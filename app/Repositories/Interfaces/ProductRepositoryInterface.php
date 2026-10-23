<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع الأصناف والأدوية
 */
interface ProductRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * استرجاع الأصناف مع بيانات الفئة وترقيم الصفحات والبحث المتقدم
     *
     * @param array $filters
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function getProductsWithCategory(array $filters = [], $page = 1, $perPage = 25);

    /**
     * البحث بالباركود
     *
     * @param string $barcode
     * @return array|null
     */
    public function findByBarcode($barcode);

    /**
     * البحث بكود الصنف
     *
     * @param string $itemCode
     * @return array|null
     */
    public function findByItemCode($itemCode);

    /**
     * استرجاع الأصناف التي وصل مخزونها لحد إعادة الطلب
     */
    public function getLowStockProducts();

    /**
     * استرجاع كافة الأصناف للتصدير
     */
    public function getAllForExport(array $filters = []);
}
