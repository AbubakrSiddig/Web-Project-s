<?php
namespace App\Repositories\Interfaces;

/**
 * واجهة مستودع الحسابات النقدية والبنكية وسندات القبض والصرف
 */
interface AccountingRepositoryInterface
{
    // الخزائن والبنوك
    public function getCashAccounts(array $filters = []);
    public function findCashAccountById($id);
    public function createCashAccount(array $data);
    public function updateCashAccount($id, array $data);
    public function updateCashAccountBalance($id, $amountChange);

    // بنود المصروفات
    public function getExpenseCategories();
    public function findExpenseCategoryById($id);
    public function createExpenseCategory(array $data);

    // السندات المالية
    public function createVoucher(array $data);
    public function findVoucherById($id);
    public function getVouchers(array $filters = [], $page = 1, $perPage = 25);
    public function generateVoucherNumber($type);

    // القيود اليومية المزدوجة (Journal Entries)
    public function createJournalEntry(array $data, array $lines);
    public function findJournalEntryById($id);
    public function getJournalEntries(array $filters = [], $page = 1, $perPage = 25);
    public function generateJournalEntryNumber();
}
