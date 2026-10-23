<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع فواتير المشتريات
 */
interface PurchaseRepositoryInterface
{
    public function createInvoice(array $invoiceData, array $items);
    public function getInvoices(array $filters = [], $page = 1, $perPage = 25);
    public function getInvoiceDetails($invoiceId);
    public function updateInvoiceStatus($invoiceId, $status);
}
