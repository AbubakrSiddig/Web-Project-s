<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع الأدوار الوظيفية
 */
interface RoleRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * استرجاع قائمة الأدوار مع عدد الصلاحيات لكل دور
     */
    public function getRolesWithPermissionsCount();

    /**
     * استرجاع تفاصيل الدور مع صلاحياته
     */
    public function getRoleWithPermissions($roleId);

    /**
     * تحديث صلاحيات الدور
     */
    public function syncPermissions($roleId, array $permissionIds);
}
