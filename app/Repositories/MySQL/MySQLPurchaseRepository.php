<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\PurchaseRepositoryInterface;
use Core\Database;
use PDO;

/**
 * تنفيذ مستودع المشتريات عبر MySQL
 */
class MySQLPurchaseRepository implements PurchaseRepositoryInterface
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
            INSERT INTO `purchase_invoices`
            (`invoice_number`, `supplier_invoice_no`, `supplier_id`, `warehouse_id`, `branch_id`,
             `invoice_date`, `payment_type`, `subtotal`, `discount_amount`, `tax_amount`, `total_amount`,
             `paid_amount`, `remaining_amount`, `status`, `notes`, `created_by`, `created_at`)
            VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $invoiceData['invoice_number'],
            $invoiceData['supplier_invoice_no'] ?? null,
            $invoiceData['supplier_id'],
            $invoiceData['warehouse_id'],
            $invoiceData['invoice_date'],
            $invoiceData['payment_type'] ?? 'credit',
            $invoiceData['subtotal'] ?? 0.00,
            $invoiceData['discount_amount'] ?? 0.00,
            $invoiceData['tax_amount'] ?? 0.00,
            $invoiceData['total_amount'] ?? 0.00,
            $invoiceData['paid_amount'] ?? 0.00,
            $invoiceData['remaining_amount'] ?? 0.00,
            $invoiceData['status'] ?? 'draft',
            $invoiceData['notes'] ?? null,
            $invoiceData['created_by'],
        ]);
        $invoiceId = $this->db->lastInsertId();

        $itemSql = "
            INSERT INTO `purchase_invoice_items`
            (`purchase_invoice_id`, `product_id`, `batch_id`, `batch_number`, `production_date`, `expiry_date`, `quantity`, `unit_price`, `total_price`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $itemStmt = $this->db->prepare($itemSql);
        foreach ($items as $item) {
            $itemStmt->execute([
                $invoiceId,
                $item['product_id'],
                $item['batch_id'],
                $item['batch_number'],
                $item['production_date'] ?? null,
                $item['expiry_date'],
                $item['quantity'],
                $item['unit_price'],
                $item['total_price'],
            ]);
        }

        return $invoiceId;
    }

    public function getInvoices(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['supplier_id'])) {
            $where[] = "pi.supplier_id = ?";
            $bindings[] = $filters['supplier_id'];
        }

        if (!empty($filters['warehouse_id'])) {
            $where[] = "pi.warehouse_id = ?";
            $bindings[] = $filters['warehouse_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = "pi.status = ?";
            $bindings[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $where[] = "(pi.invoice_number LIKE ? OR pi.supplier_invoice_no LIKE ? OR s.name LIKE ?)";
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM `purchase_invoices` pi INNER JOIN `suppliers` s ON pi.supplier_id = s.id {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT pi.*, 
                   s.name as supplier_name, s.company_name as supplier_company,
                   w.name as warehouse_name,
                   u.full_name as creator_name,
                   (SELECT COUNT(*) FROM `purchase_invoice_items` pii WHERE pii.purchase_invoice_id = pi.id) as items_count
            FROM `purchase_invoices` pi
            INNER JOIN `suppliers` s ON pi.supplier_id = s.id
            INNER JOIN `warehouses` w ON pi.warehouse_id = w.id
            INNER JOIN `users` u ON pi.created_by = u.id
            {$whereClause}
            ORDER BY pi.id DESC
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

    public function getInvoiceDetails($invoiceId)
    {
        $sql = "
            SELECT pi.*, 
                   s.name as supplier_name, s.company_name as supplier_company, s.phone as supplier_phone, s.balance as supplier_balance,
                   w.name as warehouse_name, w.code as warehouse_code,
                   u.full_name as creator_name
            FROM `purchase_invoices` pi
            INNER JOIN `suppliers` s ON pi.supplier_id = s.id
            INNER JOIN `warehouses` w ON pi.warehouse_id = w.id
            INNER JOIN `users` u ON pi.created_by = u.id
            WHERE pi.id = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            return null;
        }

        $itemsSql = "
            SELECT pii.*, 
                   p.trade_name, p.generic_name, p.item_code, p.main_unit
            FROM `purchase_invoice_items` pii
            INNER JOIN `products` p ON pii.product_id = p.id
            WHERE pii.purchase_invoice_id = ?
            ORDER BY pii.id ASC
        ";
        $itemsStmt = $this->db->prepare($itemsSql);
        $itemsStmt->execute([$invoiceId]);
        $invoice['items'] = $itemsStmt->fetchAll();

        return $invoice;
    }

    public function updateInvoiceStatus($invoiceId, $status)
    {
        $stmt = $this->db->prepare("UPDATE `purchase_invoices` SET `status` = ?, `updated_at` = NOW() WHERE `id` = ?");
        return $stmt->execute([$status, $invoiceId]);
    }
}
