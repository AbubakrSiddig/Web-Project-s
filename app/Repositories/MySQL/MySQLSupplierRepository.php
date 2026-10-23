<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\SupplierRepositoryInterface;

/**
 * تنفيذ مستودع الموردين عبر MySQL
 */
class MySQLSupplierRepository extends BaseMySQLRepository implements SupplierRepositoryInterface
{
    protected $table = 'suppliers';

    public function getSuppliersWithStats(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $where[] = "(s.name LIKE ? OR s.company_name LIKE ? OR s.phone LIKE ?)";
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = "s.is_active = ?";
            $bindings[] = $filters['is_active'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM `suppliers` s {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT s.*,
                   (SELECT COUNT(*) FROM `purchase_invoices` pi WHERE pi.supplier_id = s.id) as invoices_count,
                   (SELECT COALESCE(SUM(pi.total_amount), 0) FROM `purchase_invoices` pi WHERE pi.supplier_id = s.id AND pi.status = 'received') as total_purchases
            FROM `suppliers` s
            {$whereClause}
            ORDER BY s.id DESC
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

    public function updateBalance($supplierId, $amountChange)
    {
        $stmt = $this->db->prepare("UPDATE `suppliers` SET `balance` = `balance` + ?, `updated_at` = NOW() WHERE `id` = ?");
        $stmt->execute([(float)$amountChange, $supplierId]);

        $balStmt = $this->db->prepare("SELECT `balance` FROM `suppliers` WHERE `id` = ?");
        $balStmt->execute([$supplierId]);
        return (float)$balStmt->fetchColumn();
    }

    public function getLedger($supplierId, array $filters = [], $page = 1, $perPage = 50)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = ["st.supplier_id = ?"];
        $bindings = [$supplierId];

        if (!empty($filters['date_from'])) {
            $where[] = "st.transaction_date >= ?";
            $bindings[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "st.transaction_date <= ?";
            $bindings[] = $filters['date_to'];
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM `supplier_transactions` st {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT st.*
            FROM `supplier_transactions` st
            {$whereClause}
            ORDER BY st.transaction_date ASC, st.id ASC
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

    public function recordLedgerTransaction(array $data)
    {
        $sql = "
            INSERT INTO `supplier_transactions`
            (`supplier_id`, `transaction_type`, `reference_id`, `debit`, `credit`, `balance_after`, `transaction_date`, `notes`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['supplier_id'],
            $data['transaction_type'],
            $data['reference_id'],
            $data['debit'] ?? 0.00,
            $data['credit'] ?? 0.00,
            $data['balance_after'],
            $data['transaction_date'] ?? date('Y-m-d'),
            $data['notes'] ?? null,
        ]);
        return $this->db->lastInsertId();
    }
}
