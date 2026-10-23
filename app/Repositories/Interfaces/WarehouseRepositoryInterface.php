<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع المخازن
 */
interface WarehouseRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * استرجاع المخازن مع ملخص الأصناف وأمين المخزن
     */
    public function getWarehousesWithKeeper();

    /**
     * جرد رصيد كافة الأصناف والتشغيلات داخل مخزن محدد
     */
    public function getWarehouseStockInventory($warehouseId, array $filters = [], $page = 1, $perPage = 50);

    /**
     * رصيد صنف وتشغيلة محددة في مخزن
     */
    public function getBatchStock($warehouseId, $batchId);

    /**
     * تحديث رصيد تشغيلة في مخزن (+ أو -)
     */
    public function adjustBatchQuantity($warehouseId, $batchId, $productId, $qtyChange);
}
