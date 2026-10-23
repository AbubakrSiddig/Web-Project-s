<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\BatchRepositoryInterface;

/**
 * تنفيذ مستودع التشغيلات والصلاحيات عبر MySQL
 */
class MySQLBatchRepository extends BaseMySQLRepository implements BatchRepositoryInterface
{
    protected $table = 'batches';

    public function getBatchesByProduct($productId)
    {
        $sql = "
            SELECT b.*, p.trade_name, p.generic_name, p.main_unit,
                   COALESCE((SELECT SUM(wb.quantity) FROM `warehouse_batches` wb WHERE wb.batch_id = b.id), 0) as total_quantity,
                   DATEDIFF(b.expiry_date, CURRENT_DATE()) as days_to_expiry
            FROM `batches` b
            INNER JOIN `products` p ON b.product_id = p.id
            WHERE b.product_id = ?
            ORDER BY b.expiry_date ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    public function findByProductAndBatchNumber($productId, $batchNumber)
    {
        $sql = "
            SELECT b.*
            FROM `batches` b
            WHERE b.product_id = ? AND b.batch_number = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$productId, trim($batchNumber)]);
        $res = $stmt->fetch();
        return $res ?: null;
    }

    public function getValidBatchesForSale($productId, $warehouseId, $policy = 'FEFO')
    {
        // قاعدة أمان صيدلانية صارمة: لا يسمح ببيع أي تشغيلة منتهية الصلاحية
        $orderBy = (strtoupper($policy) === 'FIFO')
            ? "b.production_date ASC, b.id ASC" // FIFO: الأقدم تصنيعاً/وروداً أولاً
            : "b.expiry_date ASC, b.id ASC";    // FEFO: الأقرب انتهاءً أولاً

        $sql = "
            SELECT b.id as batch_id, b.batch_number, b.expiry_date, b.production_date,
                   b.selling_price as batch_selling_price, p.selling_price as default_selling_price,
                   wb.quantity as available_quantity, wb.reserved_quantity,
                   DATEDIFF(b.expiry_date, CURRENT_DATE()) as days_to_expiry,
                   p.trade_name, p.generic_name, p.main_unit
            FROM `batches` b
            INNER JOIN `products` p ON b.product_id = p.id
            INNER JOIN `warehouse_batches` wb ON wb.batch_id = b.id
            WHERE b.product_id = ?
              AND wb.warehouse_id = ?
              AND wb.quantity > 0
              AND b.status = 'active'
              AND b.expiry_date > CURRENT_DATE()
            ORDER BY {$orderBy}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$productId, $warehouseId]);
        return $stmt->fetchAll();
    }

    public function getNearExpiryBatches($days = 180, $warehouseId = null)
    {
        $days = (int)$days;
        $params = [$days];

        $warehouseFilter = "";
        if (!empty($warehouseId)) {
            $warehouseFilter = " AND wb.warehouse_id = ? ";
            $params[] = $warehouseId;
        }

        $sql = "
            SELECT b.id, b.batch_number, b.production_date, b.expiry_date, b.status,
                   DATEDIFF(b.expiry_date, CURRENT_DATE()) as days_to_expiry,
                   p.id as product_id, p.trade_name, p.generic_name, p.main_unit, c.name as category_name,
                   COALESCE(SUM(wb.quantity), 0) as current_stock,
                   w.name as warehouse_name
            FROM `batches` b
            INNER JOIN `products` p ON b.product_id = p.id
            INNER JOIN `categories` c ON p.category_id = c.id
            LEFT JOIN `warehouse_batches` wb ON wb.batch_id = b.id
            LEFT JOIN `warehouses` w ON wb.warehouse_id = w.id
            WHERE b.status = 'active'
              AND b.expiry_date > CURRENT_DATE()
              AND b.expiry_date <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)
              {$warehouseFilter}
            GROUP BY b.id, wb.warehouse_id
            HAVING current_stock > 0
            ORDER BY b.expiry_date ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getExpiredBatches($warehouseId = null)
    {
        $params = [];
        $warehouseFilter = "";
        if (!empty($warehouseId)) {
            $warehouseFilter = " AND wb.warehouse_id = ? ";
            $params[] = $warehouseId;
        }

        $sql = "
            SELECT b.id, b.batch_number, b.production_date, b.expiry_date, b.status,
                   DATEDIFF(CURRENT_DATE(), b.expiry_date) as days_expired_ago,
                   p.id as product_id, p.trade_name, p.generic_name, p.main_unit, c.name as category_name,
                   COALESCE(SUM(wb.quantity), 0) as current_stock,
                   w.name as warehouse_name
            FROM `batches` b
            INNER JOIN `products` p ON b.product_id = p.id
            INNER JOIN `categories` c ON p.category_id = c.id
            LEFT JOIN `warehouse_batches` wb ON wb.batch_id = b.id
            LEFT JOIN `warehouses` w ON wb.warehouse_id = w.id
            WHERE (b.expiry_date <= CURRENT_DATE() OR b.status = 'expired')
              {$warehouseFilter}
            GROUP BY b.id, wb.warehouse_id
            ORDER BY b.expiry_date DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function updateExpiredBatchesStatus()
    {
        $sql = "
            UPDATE `batches` 
            SET `status` = 'expired' 
            WHERE `expiry_date` <= CURRENT_DATE() 
              AND `status` NOT IN ('expired', 'depleted')
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function getBatchWithStock($batchId, $warehouseId = null)
    {
        $sql = "
            SELECT b.*, p.trade_name, p.generic_name, p.main_unit, c.name as category_name,
                   COALESCE((SELECT SUM(wb.quantity) FROM `warehouse_batches` wb WHERE wb.batch_id = b.id), 0) as total_stock,
                   DATEDIFF(b.expiry_date, CURRENT_DATE()) as days_to_expiry
            FROM `batches` b
            INNER JOIN `products` p ON b.product_id = p.id
            INNER JOIN `categories` c ON p.category_id = c.id
            WHERE b.id = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch();
        if (!$batch) {
            return null;
        }

        // جلب تفاصيل الرصيد في كل مخزن
        $stockSql = "
            SELECT wb.warehouse_id, wb.quantity, wb.reserved_quantity, w.name as warehouse_name, w.code as warehouse_code
            FROM `warehouse_batches` wb
            INNER JOIN `warehouses` w ON wb.warehouse_id = w.id
            WHERE wb.batch_id = ?
        ";
        $stockStmt = $this->db->prepare($stockSql);
        $stockStmt->execute([$batchId]);
        $batch['warehouses_stock'] = $stockStmt->fetchAll();

        return $batch;
    }
}
