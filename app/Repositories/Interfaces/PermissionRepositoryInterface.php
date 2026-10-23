<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع الصلاحيات
 */
interface PermissionRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * استرجاع الصلاحيات مجمعة حسب الوحدة (Module)
     */
    public function getAllGroupedByModule();

    /**
     * استرجاع صلاحيات دور معين
     */
    public function getPermissionsByRoleId($roleId);

    /**
     * استرجاع أكواد صلاحيات مستخدم معين
     */
    public function getUserPermissionCodes($userId);
}
