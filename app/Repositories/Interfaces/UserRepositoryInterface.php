<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع المستخدمين
 */
interface UserRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * البحث عن مستخدم بواسطة اسم المستخدم مع بيانات دوره
     *
     * @param string $username
     * @return array|null
     */
    public function findByUsernameWithRole($username);

    /**
     * استرجاع قائمة المستخدمين مع الأدوار
     *
     * @param int $page
     * @param int $perPage
     * @param array $filters
     * @return array
     */
    public function getUsersWithRoles($page = 1, $perPage = 25, array $filters = []);

    /**
     * تحديث تاريخ آخر تسجيل دخول
     *
     * @param int $userId
     * @return bool
     */
    public function updateLastLogin($userId);
}
