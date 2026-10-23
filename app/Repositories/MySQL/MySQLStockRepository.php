<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\StockRepositoryInterface;
use Core\Database;
use PDO;

/**
 * تنفيذ مستودع الحركات والتحويلات والجرد عبر MySQL
 */
class MySQLStockRepository implements StockRepositoryInterface
{
    /** @var PDO */
    protected $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function recordTransaction(array $data)
    {
        $sql = "
            INSERT INTO `stock_transactions`
            (`transaction_type`, `reference_table`, `reference_id`, `warehouse_id`, `product_id`, `batch_id`,
             `qty_before`, `qty_change`, `qty_after`, `unit_cost`, `notes`, `created_by`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['transaction_type'],
            $data['reference_table'],
            $data['reference_id'],
            $data['warehouse_id'],
            $data['product_id'],
            $data['batch_id'],
            $data['qty_before'],
            $data['qty_change'],
            $data['qty_after'],
            $data['unit_cost'] ?? 0.0000,
            $data['notes'] ?? null,
            $data['created_by'],
        ]);
        return $this->db->lastInsertId();
    }

    public function getTransactionsHistory(array $filters = [], $page = 1, $perPage = 50)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['warehouse_id'])) {
            $where[] = "st.warehouse_id = ?";
            $bindings[] = $filters['warehouse_id'];
        }

        if (!empty($filters['product_id'])) {
            $where[] = "st.product_id = ?";
            $bindings[] = $filters['product_id'];
        }

        if (!empty($filters['batch_id'])) {
            $where[] = "st.batch_id = ?";
            $bindings[] = $filters['batch_id'];
        }

        if (!empty($filters['transaction_type'])) {
            $where[] = "st.transaction_type = ?";
            $bindings[] = $filters['transaction_type'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "DATE(st.created_at) >= ?";
            $bindings[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "DATE(st.created_at) <= ?";
            $bindings[] = $filters['date_to'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM `stock_transactions` st {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT st.*, 
                   w.name as warehouse_name, w.code as warehouse_code,
                   p.trade_name, p.generic_name, p.item_code, p.main_unit,
                   b.batch_number, b.expiry_date,
                   u.full_name as user_name
            FROM `stock_transactions` st
            INNER JOIN `warehouses` w ON st.warehouse_id = w.id
            INNER JOIN `products` p ON st.product_id = p.id
            INNER JOIN `batches` b ON st.batch_id = b.id
            INNER JOIN `users` u ON st.created_by = u.id
            {$whereClause}
            ORDER BY st.id DESC
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

    public function createTransfer(array $transferData, array $items)
    {
        $sql = "
            INSERT INTO `stock_transfers`
            (`transfer_number`, `from_warehouse_id`, `to_warehouse_id`, `transfer_date`, `status`, `notes`, `created_by`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $transferData['transfer_number'],
            $transferData['from_warehouse_id'],
            $transferData['to_warehouse_id'],
            $transferData['transfer_date'],
            $transferData['status'] ?? 'pending',
            $transferData['notes'] ?? null,
            $transferData['created_by'],
        ]);
        $transferId = $this->db->lastInsertId();

        $itemSql = "
            INSERT INTO `stock_transfer_items`
            (`transfer_id`, `product_id`, `batch_id`, `quantity`, `notes`)
            VALUES (?, ?, ?, ?, ?)
        ";
        $itemStmt = $this->db->prepare($itemSql);
        foreach ($items as $item) {
            $itemStmt->execute([
                $transferId,
                $item['product_id'],
                $item['batch_id'],
                $item['quantity'],
                $item['notes'] ?? null,
            ]);
        }

        return $transferId;
    }

    public function getTransfers(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['from_warehouse_id'])) {
            $where[] = "st.from_warehouse_id = ?";
            $bindings[] = $filters['from_warehouse_id'];
        }

        if (!empty($filters['to_warehouse_id'])) {
            $where[] = "st.to_warehouse_id = ?";
            $bindings[] = $filters['to_warehouse_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = "st.status = ?";
            $bindings[] = $filters['status'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM `stock_transfers` st {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT st.*, 
                   wf.name as from_warehouse_name,
                   wt.name as to_warehouse_name,
                   uc.full_name as creator_name,
                   ua.full_name as approver_name,
                   (SELECT COUNT(*) FROM `stock_transfer_items` sti WHERE sti.transfer_id = st.id) as items_count,
                   (SELECT COALESCE(SUM(sti.quantity), 0) FROM `stock_transfer_items` sti WHERE sti.transfer_id = st.id) as total_units
            FROM `stock_transfers` st
            INNER JOIN `warehouses` wf ON st.from_warehouse_id = wf.id
            INNER JOIN `warehouses` wt ON st.to_warehouse_id = wt.id
            INNER JOIN `users` uc ON st.created_by = uc.id
            LEFT JOIN `users` ua ON st.approved_by = ua.id
            {$whereClause}
            ORDER BY st.id DESC
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

    public function getTransferDetails($transferId)
    {
        $sql = "
            SELECT st.*, 
                   wf.name as from_warehouse_name, wf.code as from_warehouse_code,
                   wt.name as to_warehouse_name, wt.code as to_warehouse_code,
                   uc.full_name as creator_name,
                   ua.full_name as approver_name
            FROM `stock_transfers` st
            INNER JOIN `warehouses` wf ON st.from_warehouse_id = wf.id
            INNER JOIN `warehouses` wt ON st.to_warehouse_id = wt.id
            INNER JOIN `users` uc ON st.created_by = uc.id
            LEFT JOIN `users` ua ON st.approved_by = ua.id
            WHERE st.id = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$transferId]);
        $transfer = $stmt->fetch();
        if (!$transfer) {
            return null;
        }

        $itemsSql = "
            SELECT sti.*, 
                   p.trade_name, p.generic_name, p.item_code, p.main_unit,
                   b.batch_number, b.expiry_date, b.production_date
            FROM `stock_transfer_items` sti
            INNER JOIN `products` p ON sti.product_id = p.id
            INNER JOIN `batches` b ON sti.batch_id = b.id
            WHERE sti.transfer_id = ?
            ORDER BY sti.id ASC
        ";
        $itemsStmt = $this->db->prepare($itemsSql);
        $itemsStmt->execute([$transferId]);
        $transfer['items'] = $itemsStmt->fetchAll();

        return $transfer;
    }

    public function updateTransferStatus($transferId, $status, $approvedBy = null)
    {
        $sql = "UPDATE `stock_transfers` SET `status` = ?, `approved_by` = ?, `updated_at` = NOW() WHERE `id` = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$status, $approvedBy, $transferId]);
    }

    public function createAdjustment(array $adjustmentData, array $items)
    {
        $sql = "
            INSERT INTO `stock_adjustments`
            (`adjustment_number`, `warehouse_id`, `adjustment_date`, `reason`, `status`, `notes`, `created_by`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $adjustmentData['adjustment_number'],
            $adjustmentData['warehouse_id'],
            $adjustmentData['adjustment_date'],
            $adjustmentData['reason'],
            $adjustmentData['status'] ?? 'draft',
            $adjustmentData['notes'] ?? null,
            $adjustmentData['created_by'],
        ]);
        $adjustmentId = $this->db->lastInsertId();

        $itemSql = "
            INSERT INTO `stock_adjustment_items`
            (`adjustment_id`, `product_id`, `batch_id`, `system_qty`, `actual_qty`, `difference_qty`)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        $itemStmt = $this->db->prepare($itemSql);
        foreach ($items as $item) {
            $itemStmt->execute([
                $adjustmentId,
                $item['product_id'],
                $item['batch_id'],
                $item['system_qty'],
                $item['actual_qty'],
                $item['difference_qty'],
            ]);
        }

        return $adjustmentId;
    }

    public function getAdjustments(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['warehouse_id'])) {
            $where[] = "sa.warehouse_id = ?";
            $bindings[] = $filters['warehouse_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = "sa.status = ?";
            $bindings[] = $filters['status'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM `stock_adjustments` sa {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT sa.*, 
                   w.name as warehouse_name, w.code as warehouse_code,
                   uc.full_name as creator_name,
                   ua.full_name as approver_name,
                   (SELECT COUNT(*) FROM `stock_adjustment_items` sai WHERE sai.adjustment_id = sa.id) as items_count,
                   (SELECT COALESCE(SUM(sai.difference_qty), 0) FROM `stock_adjustment_items` sai WHERE sai.adjustment_id = sa.id) as total_difference
            FROM `stock_adjustments` sa
            INNER JOIN `warehouses` w ON sa.warehouse_id = w.id
            INNER JOIN `users` uc ON sa.created_by = uc.id
            LEFT JOIN `users` ua ON sa.approved_by = ua.id
            {$whereClause}
            ORDER BY sa.id DESC
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

    public function getAdjustmentDetails($adjustmentId)
    {
        $sql = "
            SELECT sa.*, 
                   w.name as warehouse_name, w.code as warehouse_code,
                   uc.full_name as creator_name,
                   ua.full_name as approver_name
            FROM `stock_adjustments` sa
            INNER JOIN `warehouses` w ON sa.warehouse_id = w.id
            INNER JOIN `users` uc ON sa.created_by = uc.id
            LEFT JOIN `users` ua ON sa.approved_by = ua.id
            WHERE sa.id = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$adjustmentId]);
        $adjustment = $stmt->fetch();
        if (!$adjustment) {
            return null;
        }

        $itemsSql = "
            SELECT sai.*, 
                   p.trade_name, p.generic_name, p.item_code, p.main_unit,
                   b.batch_number, b.expiry_date
            FROM `stock_adjustment_items` sai
            INNER JOIN `products` p ON sai.product_id = p.id
            INNER JOIN `batches` b ON sai.batch_id = b.id
            WHERE sai.adjustment_id = ?
            ORDER BY sai.id ASC
        ";
        $itemsStmt = $this->db->prepare($itemsSql);
        $itemsStmt->execute([$adjustmentId]);
        $adjustment['items'] = $itemsStmt->fetchAll();

        return $adjustment;
    }

    public function updateAdjustmentStatus($adjustmentId, $status, $approvedBy = null)
    {
        $sql = "UPDATE `stock_adjustments` SET `status` = ?, `approved_by` = ?, `updated_at` = NOW() WHERE `id` = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$status, $approvedBy, $adjustmentId]);
    }
}
