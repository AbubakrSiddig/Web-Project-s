<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع مرتجعات المبيعات والمشتريات
 */
interface ReturnRepositoryInterface
{
    public function createSalesReturn(array $returnData, array $items);
    public function findSalesReturnById($id);
    public function getSalesReturns(array $filters = [], $page = 1, $perPage = 25);
    public function generateSalesReturnNumber();

    public function createPurchaseReturn(array $returnData, array $items);
    public function findPurchaseReturnById($id);
    public function getPurchaseReturns(array $filters = [], $page = 1, $perPage = 25);
    public function generatePurchaseReturnNumber();
}
