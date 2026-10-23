<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\PermissionRepositoryInterface;

/**
 * تنفيذ مستودع الصلاحيات عبر MySQL
 */
class MySQLPermissionRepository extends BaseMySQLRepository implements PermissionRepositoryInterface
{
    protected $table = 'permissions';

    public function getAllGroupedByModule()
    {
        $sql = "SELECT * FROM `permissions` ORDER BY `module` ASC, `id` ASC";
        $stmt = $this->db->query($sql);
        $permissions = $stmt->fetchAll();

        $grouped = [];
        foreach ($permissions as $p) {
            $module = $p['module'];
            if (!isset($grouped[$module])) {
                $grouped[$module] = [];
            }
            $grouped[$module][] = $p;
        }

        return $grouped;
    }

    public function getPermissionsByRoleId($roleId)
    {
        $sql = "
            SELECT p.*
            FROM `permissions` p
            INNER JOIN `role_permissions` rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?
            ORDER BY p.id ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$roleId]);
        return $stmt->fetchAll();
    }

    public function getUserPermissionCodes($userId)
    {
        $sql = "
            SELECT DISTINCT p.code
            FROM `permissions` p
            INNER JOIN `role_permissions` rp ON p.id = rp.permission_id
            INNER JOIN `users` u ON u.role_id = rp.role_id
            WHERE u.id = ? AND u.is_active = 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
