<?php
namespace App\Repositories\MySQL;

use App\Repositories\Interfaces\RoleRepositoryInterface;

/**
 * تنفيذ مستودع الأدوار الوظيفية عبر MySQL
 */
class MySQLRoleRepository extends BaseMySQLRepository implements RoleRepositoryInterface
{
    protected $table = 'roles';

    public function getRolesWithPermissionsCount()
    {
        $sql = "
            SELECT r.*, COUNT(rp.permission_id) as permissions_count,
                   (SELECT COUNT(*) FROM `users` u WHERE u.role_id = r.id) as users_count
            FROM `roles` r
            LEFT JOIN `role_permissions` rp ON r.id = rp.role_id
            GROUP BY r.id
            ORDER BY r.id ASC
        ";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    public function getRoleWithPermissions($roleId)
    {
        $role = $this->find($roleId);
        if (!$role) {
            return null;
        }

        $sql = "
            SELECT p.*
            FROM `permissions` p
            INNER JOIN `role_permissions` rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?
            ORDER BY p.module ASC, p.id ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$roleId]);
        $role['permissions'] = $stmt->fetchAll();

        return $role;
    }

    public function syncPermissions($roleId, array $permissionIds)
    {
        $this->db->beginTransaction();
        try {
            // حذف الصلاحيات الحالية للدور
            $deleteStmt = $this->db->prepare("DELETE FROM `role_permissions` WHERE `role_id` = ?");
            $deleteStmt->execute([$roleId]);

            // إدراج الصلاحيات الجديدة
            if (!empty($permissionIds)) {
                $insertSql = "INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)";
                $insertStmt = $this->db->prepare($insertSql);
                foreach ($permissionIds as $permId) {
                    if (is_numeric($permId)) {
                        $insertStmt->execute([$roleId, (int)$permId]);
                    }
                }
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
