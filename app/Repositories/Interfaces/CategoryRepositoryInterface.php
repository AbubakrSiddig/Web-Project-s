<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع فئات الأدوية والمستلزمات
 */
interface CategoryRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * استرجاع الفئات مع عدد الأصناف في كل فئة
     */
    public function getCategoriesWithProductCount();
}
