<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع فواتير المبيعات
 */
interface SalesRepositoryInterface
{
    public function createInvoice(array $invoiceData, array $items);
    public function findInvoiceById($id);
    public function findInvoiceByNumber($invoiceNumber);
    public function getInvoices(array $filters = [], $page = 1, $perPage = 25);
    public function updateInvoiceStatus($id, $status, $paidAmount = null, $remainingAmount = null);
    public function generateInvoiceNumber();
    public function getInvoiceItems($invoiceId);
}
