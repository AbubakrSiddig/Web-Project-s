<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\SalesRepositoryInterface;
use Core\Database;
use PDO;

/**
 * تنفيذ مستودع المبيعات عبر MySQL
 */
class MySQLSalesRepository implements SalesRepositoryInterface
{
    /** @var PDO */
    protected $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function createInvoice(array $invoiceData, array $items)
    {
        $sql = "
            INSERT INTO `sales_invoices`
            (`invoice_number`, `customer_id`, `warehouse_id`, `branch_id`,
             `invoice_date`, `payment_type`, `subtotal`, `discount_amount`, `tax_amount`, `total_amount`,
             `paid_amount`, `remaining_amount`, `status`, `notes`, `created_by`, `created_at`)
            VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $invoiceData['invoice_number'],
            $invoiceData['customer_id'],
            $invoiceData['warehouse_id'],
            $invoiceData['invoice_date'],
            $invoiceData['payment_type'] ?? 'cash',
            $invoiceData['subtotal'] ?? 0.00,
            $invoiceData['discount_amount'] ?? 0.00,
            $invoiceData['tax_amount'] ?? 0.00,
            $invoiceData['total_amount'] ?? 0.00,
            $invoiceData['paid_amount'] ?? 0.00,
            $invoiceData['remaining_amount'] ?? 0.00,
            $invoiceData['status'] ?? 'confirmed',
            $invoiceData['notes'] ?? null,
            $invoiceData['created_by'],
        ]);
        $invoiceId = $this->db->lastInsertId();

        $itemSql = "
            INSERT INTO `sales_invoice_items`
            (`sales_invoice_id`, `product_id`, `batch_id`, `quantity`, `unit_price`, `discount`, `total_price`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";
        $itemStmt = $this->db->prepare($itemSql);
        foreach ($items as $item) {
            $itemStmt->execute([
                $invoiceId,
                $item['product_id'],
                $item['batch_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['discount'] ?? 0.00,
                $item['total_price'],
            ]);
        }

        return $invoiceId;
    }

    public function findInvoiceById($id)
    {
        $sql = "
            SELECT si.*,
                   c.name as customer_name,
                   c.pharmacy_name,
                   c.phone as customer_phone,
                   c.address as customer_address,
                   w.name as warehouse_name,
                   u.full_name as created_by_name
            FROM `sales_invoices` si
            JOIN `customers` c ON c.id = si.customer_id
            JOIN `warehouses` w ON w.id = si.warehouse_id
            JOIN `users` u ON u.id = si.created_by
            WHERE si.id = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int)$id]);
        $invoice = $stmt->fetch();

        if ($invoice) {
            $invoice['items'] = $this->getInvoiceItems($id);
        }

        return $invoice ?: null;
    }

    public function findInvoiceByNumber($invoiceNumber)
    {
        $sql = "
            SELECT si.*,
                   c.name as customer_name,
                   c.pharmacy_name,
                   c.phone as customer_phone,
                   c.address as customer_address,
                   w.name as warehouse_name,
                   u.full_name as created_by_name
            FROM `sales_invoices` si
            JOIN `customers` c ON c.id = si.customer_id
            JOIN `warehouses` w ON w.id = si.warehouse_id
            JOIN `users` u ON u.id = si.created_by
            WHERE si.invoice_number = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$invoiceNumber]);
        $invoice = $stmt->fetch();

        if ($invoice) {
            $invoice['items'] = $this->getInvoiceItems($invoice['id']);
        }

        return $invoice ?: null;
    }

    public function getInvoiceItems($invoiceId)
    {
        $sql = "
            SELECT sii.*,
                   p.trade_name,
                   p.generic_name,
                   p.item_code as product_code,
                   p.main_unit,
                   b.batch_number,
                   b.expiry_date
            FROM `sales_invoice_items` sii
            JOIN `products` p ON p.id = sii.product_id
            JOIN `batches` b ON b.id = sii.batch_id
            WHERE sii.sales_invoice_id = ?
            ORDER BY sii.id ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int)$invoiceId]);
        return $stmt->fetchAll();
    }

    public function getInvoices(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $where[] = "(si.invoice_number LIKE ? OR c.name LIKE ? OR c.pharmacy_name LIKE ?)";
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (!empty($filters['customer_id'])) {
            $where[] = "si.customer_id = ?";
            $bindings[] = $filters['customer_id'];
        }

        if (!empty($filters['warehouse_id'])) {
            $where[] = "si.warehouse_id = ?";
            $bindings[] = $filters['warehouse_id'];
        }

        if (!empty($filters['payment_type'])) {
            $where[] = "si.payment_type = ?";
            $bindings[] = $filters['payment_type'];
        }

        if (!empty($filters['status'])) {
            $where[] = "si.status = ?";
            $bindings[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "si.invoice_date >= ?";
            $bindings[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "si.invoice_date <= ?";
            $bindings[] = $filters['date_to'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "
            SELECT COUNT(*)
            FROM `sales_invoices` si
            JOIN `customers` c ON c.id = si.customer_id
            {$whereClause}
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT si.*,
                   c.name as customer_name,
                   c.pharmacy_name,
                   w.name as warehouse_name,
                   u.full_name as created_by_name,
                   (SELECT COUNT(*) FROM `sales_invoice_items` sii WHERE sii.sales_invoice_id = si.id) as items_count
            FROM `sales_invoices` si
            JOIN `customers` c ON c.id = si.customer_id
            JOIN `warehouses` w ON w.id = si.warehouse_id
            JOIN `users` u ON u.id = si.created_by
            {$whereClause}
            ORDER BY si.id DESC
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

    public function updateInvoiceStatus($id, $status, $paidAmount = null, $remainingAmount = null)
    {
        $fields = ["`status` = ?", "`updated_at` = NOW()"];
        $params = [$status];

        if ($paidAmount !== null) {
            $fields[] = "`paid_amount` = ?";
            $params[] = (float)$paidAmount;
        }

        if ($remainingAmount !== null) {
            $fields[] = "`remaining_amount` = ?";
            $params[] = (float)$remainingAmount;
        }

        $params[] = (int)$id;

        $sql = "UPDATE `sales_invoices` SET " . implode(', ', $fields) . " WHERE `id` = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function generateInvoiceNumber()
    {
        $prefix = 'INV-' . date('Ym') . '-';
        $sql = "SELECT `invoice_number` FROM `sales_invoices` WHERE `invoice_number` LIKE ? ORDER BY `id` DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$prefix . '%']);
        $lastNumber = $stmt->fetchColumn();

        if ($lastNumber) {
            $seq = (int)substr($lastNumber, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 5, '0', STR_PAD_LEFT);
    }
}
