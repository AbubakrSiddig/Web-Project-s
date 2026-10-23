<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\WarehouseRepositoryInterface;
use Exception;

/**
 * تنفيذ مستودع المخازن عبر MySQL
 */
class MySQLWarehouseRepository extends BaseMySQLRepository implements WarehouseRepositoryInterface
{
    protected $table = 'warehouses';

    public function getWarehousesWithKeeper()
    {
        $sql = "
            SELECT w.*, u.full_name as keeper_name, u.phone as keeper_phone,
                   (SELECT COUNT(DISTINCT wb.product_id) FROM `warehouse_batches` wb WHERE wb.warehouse_id = w.id AND wb.quantity > 0) as total_products,
                   (SELECT COALESCE(SUM(wb.quantity), 0) FROM `warehouse_batches` wb WHERE wb.warehouse_id = w.id) as total_units
            FROM `warehouses` w
            LEFT JOIN `users` u ON w.keeper_id = u.id
            ORDER BY w.id ASC
        ";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    public function getWarehouseStockInventory($warehouseId, array $filters = [], $page = 1, $perPage = 50)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = ["wb.warehouse_id = ?"];
        $bindings = [$warehouseId];

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $where[] = "(p.trade_name LIKE ? OR p.generic_name LIKE ? OR p.item_code LIKE ? OR b.batch_number LIKE ?)";
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (!empty($filters['category_id'])) {
            $where[] = "p.category_id = ?";
            $bindings[] = $filters['category_id'];
        }

        if (isset($filters['has_stock']) && $filters['has_stock'] == 1) {
            $where[] = "wb.quantity > 0";
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countSql = "
            SELECT COUNT(*)
            FROM `warehouse_batches` wb
            INNER JOIN `products` p ON wb.product_id = p.id
            INNER JOIN `batches` b ON wb.batch_id = b.id
            {$whereClause}
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT wb.id as wb_id, wb.warehouse_id, wb.quantity, wb.reserved_quantity, wb.updated_at,
                   (wb.quantity - wb.reserved_quantity) as available_quantity,
                   p.id as product_id, p.item_code, p.trade_name, p.generic_name, p.main_unit, p.min_stock_alert,
                   c.name as category_name,
                   b.id as batch_id, b.batch_number, b.production_date, b.expiry_date, b.status as batch_status,
                   b.selling_price as batch_selling_price, p.selling_price as default_selling_price,
                   DATEDIFF(b.expiry_date, CURRENT_DATE()) as days_to_expiry
            FROM `warehouse_batches` wb
            INNER JOIN `products` p ON wb.product_id = p.id
            INNER JOIN `categories` c ON p.category_id = c.id
            INNER JOIN `batches` b ON wb.batch_id = b.id
            {$whereClause}
            ORDER BY p.trade_name ASC, b.expiry_date ASC
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

    public function getBatchStock($warehouseId, $batchId)
    {
        $stmt = $this->db->prepare("SELECT * FROM `warehouse_batches` WHERE `warehouse_id` = ? AND `batch_id` = ? LIMIT 1");
        $stmt->execute([$warehouseId, $batchId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function adjustBatchQuantity($warehouseId, $batchId, $productId, $qtyChange)
    {
        $current = $this->getBatchStock($warehouseId, $batchId);
        $currentQty = $current ? (float)$current['quantity'] : 0.00;
        $newQty = $currentQty + (float)$qtyChange;

        if ($newQty < 0) {
            throw new Exception("الرصيد غير كافٍ في المخزن لإتمام العملية. الرصيد الحالي: {$currentQty}، المطلوب خصمه: " . abs($qtyChange));
        }

        $sql = "
            INSERT INTO `warehouse_batches` (`warehouse_id`, `batch_id`, `product_id`, `quantity`, `reserved_quantity`, `updated_at`)
            VALUES (?, ?, ?, ?, 0.00, NOW())
            ON DUPLICATE KEY UPDATE `quantity` = `quantity` + VALUES(`quantity`), `updated_at` = NOW()
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$warehouseId, $batchId, $productId, (float)$qtyChange]);

        return [
            'qty_before' => $currentQty,
            'qty_change' => (float)$qtyChange,
            'qty_after'  => $newQty,
        ];
    }
}
