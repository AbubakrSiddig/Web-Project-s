<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع الحركات والتحويلات والتسويات المخزنية
 */
interface StockRepositoryInterface
{
    /**
     * تسجيل حركة مخزنية في السجل غير القابل للتعديل (stock_transactions)
     */
    public function recordTransaction(array $data);

    /**
     * استرجاع سجل حركة المخزون مع الفلترة وترقيم الصفحات
     */
    public function getTransactionsHistory(array $filters = [], $page = 1, $perPage = 50);

    /**
     * إنشاء إذن تحويل مخزني
     */
    public function createTransfer(array $transferData, array $items);

    /**
     * استرجاع قائمة التحويلات
     */
    public function getTransfers(array $filters = [], $page = 1, $perPage = 25);

    /**
     * تفاصيل التحويل وبنوده
     */
    public function getTransferDetails($transferId);

    /**
     * تحديث حالة التحويل
     */
    public function updateTransferStatus($transferId, $status, $approvedBy = null);

    /**
     * إنشاء تسوية جرد
     */
    public function createAdjustment(array $adjustmentData, array $items);

    /**
     * استرجاع وثائق الجرد والتسويات
     */
    public function getAdjustments(array $filters = [], $page = 1, $perPage = 25);

    /**
     * تفاصيل التسوية وبنودها
     */
    public function getAdjustmentDetails($adjustmentId);

    /**
     * تحديث حالة التسوية
     */
    public function updateAdjustmentStatus($adjustmentId, $status, $approvedBy = null);
}
