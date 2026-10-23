<?php
namespace App\Services;

use Core\Database;
use Core\Service;
use Exception;
use PDO;

/**
 * خدمة سجل التدقيق الأمني (Audit Log Service)
 */
class AuditLogService extends Service
{
    /** @var PDO */
    protected $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    public function getLogs(array $filters = [], $page = 1, $perPage = 50)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(500, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['user_id'])) {
            $where[] = "al.user_id = ?";
            $bindings[] = (int)$filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $where[] = "al.action = ?";
            $bindings[] = $filters['action'];
        }

        if (!empty($filters['table_name'])) {
            $where[] = "al.table_name = ?";
            $bindings[] = $filters['table_name'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "DATE(al.created_at) >= ?";
            $bindings[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "DATE(al.created_at) <= ?";
            $bindings[] = $filters['date_to'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM `audit_logs` al {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT al.*, u.full_name as user_name, u.username
            FROM `audit_logs` al
            LEFT JOIN `users` u ON u.id = al.user_id
            {$whereClause}
            ORDER BY al.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        $logs = $stmt->fetchAll();

        // فك تشفير JSON للحقول
        foreach ($logs as &$log) {
            $log['old_values'] = !empty($log['old_values']) ? json_decode($log['old_values'], true) : null;
            $log['new_values'] = !empty($log['new_values']) ? json_decode($log['new_values'], true) : null;
        }

        return [
            'items'     => $logs,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int)ceil($total / $perPage),
        ];
    }

    public function getLogById($id)
    {
        $sql = "
            SELECT al.*, u.full_name as user_name, u.username
            FROM `audit_logs` al
            LEFT JOIN `users` u ON u.id = al.user_id
            WHERE al.id = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int)$id]);
        $log = $stmt->fetch();

        if (!$log) {
            throw new Exception("سجل التدقيق غير موجود.");
        }

        $log['old_values'] = !empty($log['old_values']) ? json_decode($log['old_values'], true) : null;
        $log['new_values'] = !empty($log['new_values']) ? json_decode($log['new_values'], true) : null;

        return $log;
    }
}
