<?php
namespace App\Services;

use App\Repositories\Interfaces\PermissionRepositoryInterface;
use App\Repositories\Interfaces\RoleRepositoryInterface;
use App\Repositories\MySQL\MySQLPermissionRepository;
use App\Repositories\MySQL\MySQLRoleRepository;
use Core\Service;
use Exception;

/**
 * خدمة إدارة الأدوار والصلاحيات (Role & Permission Service)
 */
class RoleService extends Service
{
    /** @var RoleRepositoryInterface */
    protected $roleRepo;

    /** @var PermissionRepositoryInterface */
    protected $permissionRepo;

    public function __construct(
        RoleRepositoryInterface $roleRepo = null,
        PermissionRepositoryInterface $permissionRepo = null
    ) {
        $this->roleRepo = $roleRepo ?: new MySQLRoleRepository();
        $this->permissionRepo = $permissionRepo ?: new MySQLPermissionRepository();
    }

    public function getAllRoles()
    {
        return $this->roleRepo->getRolesWithPermissionsCount();
    }

    public function getRoleDetails($roleId)
    {
        $role = $this->roleRepo->getRoleWithPermissions($roleId);
        if (!$role) {
            throw new Exception("الدور المطلوب غير موجود.");
        }
        return $role;
    }

    public function getAllPermissionsGrouped()
    {
        return $this->permissionRepo->getAllGroupedByModule();
    }

    public function syncPermissions($roleId, array $permissionIds, $updaterId = null)
    {
        $role = $this->roleRepo->find($roleId);
        if (!$role) {
            throw new Exception("الدور المطلوب غير موجود.");
        }

        // منع تعديل صلاحيات مدير النظام بالكامل لمنع حظر النظام
        if ((int)$roleId === 1) {
            throw new Exception("لا يمكن تقييد أو تعديل صلاحيات دور مدير النظام الرئيسي.");
        }

        $this->roleRepo->syncPermissions($roleId, $permissionIds);

        $this->logAudit(
            $updaterId,
            'sync_permissions',
            'roles',
            $roleId,
            null,
            ['role_name' => $role['name'], 'permissions_count' => count($permissionIds)]
        );

        return $this->getRoleDetails($roleId);
    }
}
