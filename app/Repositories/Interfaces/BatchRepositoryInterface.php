<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع التشغيلات والصلاحيات (Batch Repository Interface)
 */
interface BatchRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * استرجاع كافة تشغيلات صنف معين
     */
    public function getBatchesByProduct($productId);

    /**
     * البحث برقم التشغيلة وصنف الدواء
     */
    public function findByProductAndBatchNumber($productId, $batchNumber);

    /**
     * استرجاع التشغيلات الصالحة للبيع المتاحة في مخزن محدد مرتبة حسب السياسة (FEFO / FIFO)
     * تستبعد المنتهية الصلاحية تلقائياً
     */
    public function getValidBatchesForSale($productId, $warehouseId, $policy = 'FEFO');

    /**
     * استرجاع التشغيلات القريبة من الانتهاء (Near Expiry)
     */
    public function getNearExpiryBatches($days = 180, $warehouseId = null);

    /**
     * استرجاع التشغيلات المنتهية الصلاحية (Expired)
     */
    public function getExpiredBatches($warehouseId = null);

    /**
     * تحديث حالة التشغيلات التي تجاوزت تاريخ اليوم لتصبح 'expired'
     */
    public function updateExpiredBatchesStatus();

    /**
     * استرجاع تفاصيل التشغيلة مع رصيدها في المخازن
     */
    public function getBatchWithStock($batchId, $warehouseId = null);
}
