<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع العملاء والصيدليات
 */
interface CustomerRepositoryInterface extends BaseRepositoryInterface
{
    public function getCustomersWithStats(array $filters = [], $page = 1, $perPage = 25);
    public function updateBalance($customerId, $amountChange);
    public function getLedger($customerId, array $filters = [], $page = 1, $perPage = 50);
    public function recordLedgerTransaction(array $data);
}
