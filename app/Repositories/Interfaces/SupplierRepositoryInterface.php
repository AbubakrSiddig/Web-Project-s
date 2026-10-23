<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع الموردين
 */
interface SupplierRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * استرجاع قائمة الموردين مع الفلترة وإجمالي الفواتير
     */
    public function getSuppliersWithStats(array $filters = [], $page = 1, $perPage = 25);

    /**
     * تحديث رصيد المورد
     */
    public function updateBalance($supplierId, $amountChange);

    /**
     * استرجاع كشف حساب المورد (Transactions Ledger)
     */
    public function getLedger($supplierId, array $filters = [], $page = 1, $perPage = 50);

    /**
     * تسجيل حركة في كشف حساب المورد
     */
    public function recordLedgerTransaction(array $data);
}
