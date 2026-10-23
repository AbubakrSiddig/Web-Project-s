<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\ReturnRepositoryInterface;
use Core\Database;
use PDO;

/**
 * تنفيذ مستودع المرتجعات عبر MySQL
 */
class MySQLReturnRepository implements ReturnRepositoryInterface
{
    /** @var PDO */
    protected $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function createSalesReturn(array $returnData, array $items)
    {
        $sql = "
            INSERT INTO `sales_returns`
            (`return_number`, `sales_invoice_id`, `customer_id`, `warehouse_id`, `return_date`,
             `total_amount`, `refund_status`, `return_to_stock_status`, `notes`, `created_by`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $returnData['return_number'],
            $returnData['sales_invoice_id'] ?? null,
            $returnData['customer_id'],
            $returnData['warehouse_id'],
            $returnData['return_date'],
            $returnData['total_amount'] ?? 0.00,
            $returnData['refund_status'] ?? 'deduct_from_balance',
            $returnData['return_to_stock_status'] ?? 'valid_stock',
            $returnData['notes'] ?? null,
            $returnData['created_by'],
        ]);
        $returnId = $this->db->lastInsertId();

        $itemSql = "
            INSERT INTO `sales_return_items`
            (`sales_return_id`, `product_id`, `batch_id`, `quantity`, `unit_price`, `total_price`, `return_reason`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";
        $itemStmt = $this->db->prepare($itemSql);
        foreach ($items as $item) {
            $itemStmt->execute([
                $returnId,
                $item['product_id'],
                $item['batch_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['total_price'],
                $item['return_reason'] ?? null,
            ]);
        }

        return $returnId;
    }

    public function findSalesReturnById($id)
    {
        $sql = "
            SELECT sr.*,
                   c.name as customer_name,
                   c.pharmacy_name,
                   w.name as warehouse_name,
                   u.full_name as created_by_name,
                   si.invoice_number as reference_invoice_number
            FROM `sales_returns` sr
            JOIN `customers` c ON c.id = sr.customer_id
            JOIN `warehouses` w ON w.id = sr.warehouse_id
            JOIN `users` u ON u.id = sr.created_by
            LEFT JOIN `sales_invoices` si ON si.id = sr.sales_invoice_id
            WHERE sr.id = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int)$id]);
        $return = $stmt->fetch();

        if ($return) {
            $itemSql = "
                SELECT sri.*,
                       p.trade_name,
                       p.generic_name,
                       p.item_code as product_code,
                       p.main_unit,
                       b.batch_number,
                       b.expiry_date
                FROM `sales_return_items` sri
                JOIN `products` p ON p.id = sri.product_id
                JOIN `batches` b ON b.id = sri.batch_id
                WHERE sri.sales_return_id = ?
                ORDER BY sri.id ASC
            ";
            $itemStmt = $this->db->prepare($itemSql);
            $itemStmt->execute([(int)$id]);
            $return['items'] = $itemStmt->fetchAll();
        }

        return $return ?: null;
    }

    public function getSalesReturns(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $where[] = "(sr.return_number LIKE ? OR c.name LIKE ? OR c.pharmacy_name LIKE ?)";
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (!empty($filters['customer_id'])) {
            $where[] = "sr.customer_id = ?";
            $bindings[] = $filters['customer_id'];
        }

        if (!empty($filters['warehouse_id'])) {
            $where[] = "sr.warehouse_id = ?";
            $bindings[] = $filters['warehouse_id'];
        }

        if (!empty($filters['refund_status'])) {
            $where[] = "sr.refund_status = ?";
            $bindings[] = $filters['refund_status'];
        }

        if (!empty($filters['return_to_stock_status'])) {
            $where[] = "sr.return_to_stock_status = ?";
            $bindings[] = $filters['return_to_stock_status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "sr.return_date >= ?";
            $bindings[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "sr.return_date <= ?";
            $bindings[] = $filters['date_to'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "
            SELECT COUNT(*)
            FROM `sales_returns` sr
            JOIN `customers` c ON c.id = sr.customer_id
            {$whereClause}
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT sr.*,
                   c.name as customer_name,
                   c.pharmacy_name,
                   w.name as warehouse_name,
                   u.full_name as created_by_name,
                   (SELECT COUNT(*) FROM `sales_return_items` sri WHERE sri.sales_return_id = sr.id) as items_count
            FROM `sales_returns` sr
            JOIN `customers` c ON c.id = sr.customer_id
            JOIN `warehouses` w ON w.id = sr.warehouse_id
            JOIN `users` u ON u.id = sr.created_by
            {$whereClause}
            ORDER BY sr.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int)ceil($total / $perPage),
        ];
    }

    public function generateSalesReturnNumber()
    {
        $prefix = 'SR-' . date('Ym') . '-';
        $sql = "SELECT `return_number` FROM `sales_returns` WHERE `return_number` LIKE ? ORDER BY `id` DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$prefix . '%']);
        $lastNumber = $stmt->fetchColumn();

        $seq = $lastNumber ? ((int)substr($lastNumber, strlen($prefix)) + 1) : 1;
        return $prefix . str_pad($seq, 5, '0', STR_PAD_LEFT);
    }

    public function createPurchaseReturn(array $returnData, array $items)
    {
        $sql = "
            INSERT INTO `purchase_returns`
            (`return_number`, `purchase_invoice_id`, `supplier_id`, `warehouse_id`, `return_date`,
             `total_amount`, `refund_status`, `notes`, `created_by`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $returnData['return_number'],
            $returnData['purchase_invoice_id'] ?? null,
            $returnData['supplier_id'],
            $returnData['warehouse_id'],
            $returnData['return_date'],
            $returnData['total_amount'] ?? 0.00,
            $returnData['refund_status'] ?? 'deduct_from_balance',
            $returnData['notes'] ?? null,
            $returnData['created_by'],
        ]);
        $returnId = $this->db->lastInsertId();

        $itemSql = "
            INSERT INTO `purchase_return_items`
            (`purchase_return_id`, `product_id`, `batch_id`, `quantity`, `unit_price`, `total_price`, `return_reason`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";
        $itemStmt = $this->db->prepare($itemSql);
        foreach ($items as $item) {
            $itemStmt->execute([
                $returnId,
                $item['product_id'],
                $item['batch_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['total_price'],
                $item['return_reason'] ?? null,
            ]);
        }

        return $returnId;
    }

    public function findPurchaseReturnById($id)
    {
        $sql = "
            SELECT pr.*,
                   s.name as supplier_name,
                   s.company_name,
                   w.name as warehouse_name,
                   u.full_name as created_by_name,
                   pi.invoice_number as reference_invoice_number
            FROM `purchase_returns` pr
            JOIN `suppliers` s ON s.id = pr.supplier_id
            JOIN `warehouses` w ON w.id = pr.warehouse_id
            JOIN `users` u ON u.id = pr.created_by
            LEFT JOIN `purchase_invoices` pi ON pi.id = pr.purchase_invoice_id
            WHERE pr.id = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int)$id]);
        $return = $stmt->fetch();

        if ($return) {
            $itemSql = "
                SELECT pri.*,
                       p.trade_name,
                       p.generic_name,
                       p.item_code as product_code,
                       p.main_unit,
                       b.batch_number,
                       b.expiry_date
                FROM `purchase_return_items` pri
                JOIN `products` p ON p.id = pri.product_id
                JOIN `batches` b ON b.id = pri.batch_id
                WHERE pri.purchase_return_id = ?
                ORDER BY pri.id ASC
            ";
            $itemStmt = $this->db->prepare($itemSql);
            $itemStmt->execute([(int)$id]);
            $return['items'] = $itemStmt->fetchAll();
        }

        return $return ?: null;
    }

    public function getPurchaseReturns(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $where[] = "(pr.return_number LIKE ? OR s.name LIKE ? OR s.company_name LIKE ?)";
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (!empty($filters['supplier_id'])) {
            $where[] = "pr.supplier_id = ?";
            $bindings[] = $filters['supplier_id'];
        }

        if (!empty($filters['warehouse_id'])) {
            $where[] = "pr.warehouse_id = ?";
            $bindings[] = $filters['warehouse_id'];
        }

        if (!empty($filters['refund_status'])) {
            $where[] = "pr.refund_status = ?";
            $bindings[] = $filters['refund_status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "pr.return_date >= ?";
            $bindings[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "pr.return_date <= ?";
            $bindings[] = $filters['date_to'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "
            SELECT COUNT(*)
            FROM `purchase_returns` pr
            JOIN `suppliers` s ON s.id = pr.supplier_id
            {$whereClause}
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT pr.*,
                   s.name as supplier_name,
                   s.company_name,
                   w.name as warehouse_name,
                   u.full_name as created_by_name,
                   (SELECT COUNT(*) FROM `purchase_return_items` pri WHERE pri.purchase_return_id = pr.id) as items_count
            FROM `purchase_returns` pr
            JOIN `suppliers` s ON s.id = pr.supplier_id
            JOIN `warehouses` w ON w.id = pr.warehouse_id
            JOIN `users` u ON u.id = pr.created_by
            {$whereClause}
            ORDER BY pr.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int)ceil($total / $perPage),
        ];
    }

    public function generatePurchaseReturnNumber()
    {
        $prefix = 'PR-' . date('Ym') . '-';
        $sql = "SELECT `return_number` FROM `purchase_returns` WHERE `return_number` LIKE ? ORDER BY `id` DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$prefix . '%']);
        $lastNumber = $stmt->fetchColumn();

        $seq = $lastNumber ? ((int)substr($lastNumber, strlen($prefix)) + 1) : 1;
        return $prefix . str_pad($seq, 5, '0', STR_PAD_LEFT);
    }
}
