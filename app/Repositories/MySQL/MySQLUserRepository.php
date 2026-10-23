<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\UserRepositoryInterface;

/**
 * تنفيذ مستودع المستخدمين عبر MySQL
 */
class MySQLUserRepository extends BaseMySQLRepository implements UserRepositoryInterface
{
    protected $table = 'users';

    public function findByUsernameWithRole($username)
    {
        $sql = "
            SELECT u.*, r.name as role_name, r.display_name as role_display_name
            FROM `users` u
            INNER JOIN `roles` r ON u.role_id = r.id
            WHERE u.username = ?
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function getUsersWithRoles($page = 1, $perPage = 25, array $filters = [])
    {
        $page = max(1, (int)$page);
        $perPage = max(1, min(200, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['search'])) {
            $where[] = "(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $term = '%' . $filters['search'] . '%';
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (isset($filters['role_id']) && $filters['role_id'] !== '') {
            $where[] = "u.role_id = ?";
            $bindings[] = $filters['role_id'];
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = "u.is_active = ?";
            $bindings[] = $filters['is_active'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total
        $countSql = "SELECT COUNT(*) FROM `users` u {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        // Items
        $sql = "
            SELECT u.id, u.role_id, u.username, u.full_name, u.email, u.phone, 
                   u.is_active, u.last_login_at, u.created_at,
                   r.name as role_name, r.display_name as role_display_name
            FROM `users` u
            INNER JOIN `roles` r ON u.role_id = r.id
            {$whereClause}
            ORDER BY u.id DESC
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

    public function updateLastLogin($userId)
    {
        $stmt = $this->db->prepare("UPDATE `users` SET `last_login_at` = NOW() WHERE `id` = ?");
        return $stmt->execute([$userId]);
    }
}
