<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\CustomerRepositoryInterface;

/**
 * تنفيذ مستودع العملاء عبر MySQL
 */
class MySQLCustomerRepository extends BaseMySQLRepository implements CustomerRepositoryInterface
{
    protected $table = 'customers';

    public function getCustomersWithStats(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $where[] = "(c.name LIKE ? OR c.pharmacy_name LIKE ? OR c.phone LIKE ? OR c.license_number LIKE ?)";
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = "c.is_active = ?";
            $bindings[] = $filters['is_active'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM `customers` c {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT c.*,
                   (SELECT COUNT(*) FROM `sales_invoices` si WHERE si.customer_id = c.id) as invoices_count,
                   (SELECT COALESCE(SUM(si.total_amount), 0) FROM `sales_invoices` si WHERE si.customer_id = c.id AND si.status = 'confirmed') as total_sales
            FROM `customers` c
            {$whereClause}
            ORDER BY c.id DESC
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

    public function updateBalance($customerId, $amountChange)
    {
        $stmt = $this->db->prepare("UPDATE `customers` SET `balance` = `balance` + ?, `updated_at` = NOW() WHERE `id` = ?");
        $stmt->execute([(float)$amountChange, $customerId]);

        $balStmt = $this->db->prepare("SELECT `balance` FROM `customers` WHERE `id` = ?");
        $balStmt->execute([$customerId]);
        return (float)$balStmt->fetchColumn();
    }

    public function getLedger($customerId, array $filters = [], $page = 1, $perPage = 50)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = ["ct.customer_id = ?"];
        $bindings = [$customerId];

        if (!empty($filters['date_from'])) {
            $where[] = "ct.transaction_date >= ?";
            $bindings[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "ct.transaction_date <= ?";
            $bindings[] = $filters['date_to'];
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM `customer_transactions` ct {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT ct.*
            FROM `customer_transactions` ct
            {$whereClause}
            ORDER BY ct.transaction_date ASC, ct.id ASC
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
            INSERT INTO `customer_transactions`
            (`customer_id`, `transaction_type`, `reference_id`, `debit`, `credit`, `balance_after`, `transaction_date`, `notes`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['customer_id'],
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
