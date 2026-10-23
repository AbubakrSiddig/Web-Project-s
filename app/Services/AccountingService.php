<?php
namespace App\Services;

use App\Repositories\Interfaces\AccountingRepositoryInterface;
use App\Repositories\Interfaces\CustomerRepositoryInterface;
use App\Repositories\Interfaces\PurchaseRepositoryInterface;
use App\Repositories\Interfaces\SalesRepositoryInterface;
use App\Repositories\Interfaces\SupplierRepositoryInterface;
use App\Repositories\MySQL\MySQLAccountingRepository;
use App\Repositories\MySQL\MySQLCustomerRepository;
use App\Repositories\MySQL\MySQLPurchaseRepository;
use App\Repositories\MySQL\MySQLSalesRepository;
use App\Repositories\MySQL\MySQLSupplierRepository;
use Core\Database;
use Core\Service;
use Exception;

/**
 * خدمة الحسابات المالية وسندات القبض والصرف (Accounting Service)
 */
class AccountingService extends Service
{
    /** @var AccountingRepositoryInterface */
    protected $accountingRepo;

    /** @var CustomerRepositoryInterface */
    protected $customerRepo;

    /** @var SupplierRepositoryInterface */
    protected $supplierRepo;

    /** @var SalesRepositoryInterface */
    protected $salesRepo;

    /** @var PurchaseRepositoryInterface */
    protected $purchaseRepo;

    public function __construct(
        AccountingRepositoryInterface $accountingRepo = null,
        CustomerRepositoryInterface $customerRepo = null,
        SupplierRepositoryInterface $supplierRepo = null,
        SalesRepositoryInterface $salesRepo = null,
        PurchaseRepositoryInterface $purchaseRepo = null
    ) {
        $this->accountingRepo = $accountingRepo ?: new MySQLAccountingRepository();
        $this->customerRepo = $customerRepo ?: new MySQLCustomerRepository();
        $this->supplierRepo = $supplierRepo ?: new MySQLSupplierRepository();
        $this->salesRepo = $salesRepo ?: new MySQLSalesRepository();
        $this->purchaseRepo = $purchaseRepo ?: new MySQLPurchaseRepository();
    }

    public function getCashAccounts(array $filters = [])
    {
        return $this->accountingRepo->getCashAccounts($filters);
    }

    public function getCashAccountById($id)
    {
        $account = $this->accountingRepo->findCashAccountById($id);
        if (!$account) {
            throw new Exception("الحساب المالي أو الخزينة غير موجودة.");
        }
        return $account;
    }

    public function createCashAccount(array $data, $userId = null)
    {
        if (empty($data['name'])) {
            throw new Exception("اسم الخزينة أو الحساب البنكي مطلوب.");
        }

        $id = $this->accountingRepo->createCashAccount([
            'name'            => trim($data['name']),
            'account_type'    => in_array($data['account_type'] ?? '', ['cash', 'bank']) ? $data['account_type'] : 'cash',
            'account_number'  => !empty($data['account_number']) ? trim($data['account_number']) : null,
            'currency'        => !empty($data['currency']) ? trim($data['currency']) : 'SDG',
            'current_balance' => isset($data['current_balance']) ? (float)$data['current_balance'] : 0.00,
            'is_active'       => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ]);

        $this->logAudit($userId, 'create', 'cash_accounts', $id, null, ['name' => $data['name']]);
        return $this->getCashAccountById($id);
    }

    public function updateCashAccount($id, array $data, $userId = null)
    {
        $account = $this->getCashAccountById($id);
        $this->accountingRepo->updateCashAccount($id, $data);
        $this->logAudit($userId, 'update', 'cash_accounts', $id, ['name' => $account['name']], $data);
        return $this->getCashAccountById($id);
    }

    public function getExpenseCategories()
    {
        return $this->accountingRepo->getExpenseCategories();
    }

    public function createExpenseCategory(array $data, $userId = null)
    {
        if (empty($data['name'])) {
            throw new Exception("اسم بند المصروف مطلوب.");
        }

        $id = $this->accountingRepo->createExpenseCategory([
            'name'        => trim($data['name']),
            'description' => !empty($data['description']) ? trim($data['description']) : null,
            'is_active'   => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ]);

        $this->logAudit($userId, 'create', 'expense_categories', $id, null, ['name' => $data['name']]);
        return $this->accountingRepo->findExpenseCategoryById($id);
    }

    public function getVouchers(array $filters = [], $page = 1, $perPage = 25)
    {
        return $this->accountingRepo->getVouchers($filters, $page, $perPage);
    }

    public function getVoucherById($id)
    {
        $voucher = $this->accountingRepo->findVoucherById($id);
        if (!$voucher) {
            throw new Exception("السند المالي غير موجود.");
        }
        return $voucher;
    }

    /**
     * إنشاء سند قبض نقدية أو شيك بنكي (Receipt Voucher)
     */
    public function createReceiptVoucher(array $data, $userId)
    {
        $cashAccountId = (int)($data['cash_account_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $voucherDate = !empty($data['voucher_date']) ? $data['voucher_date'] : date('Y-m-d');
        $contactType = $data['contact_type'] ?? 'customer';
        $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;

        if ($cashAccountId <= 0) {
            throw new Exception("يرجى تحديد الخزينة أو الحساب البنكي المستلم.");
        }
        if ($amount <= 0) {
            throw new Exception("مبلغ سند القبض يجب أن يكون أكبر من الصفر.");
        }

        $account = $this->getCashAccountById($cashAccountId);
        if (empty($account['is_active'])) {
            throw new Exception("الخزينة المحددة موقوفة حالياً.");
        }

        Database::beginTransaction();
        try {
            $voucherNumber = $this->accountingRepo->generateVoucherNumber('receipt');

            // 1. زيادة رصيد الخزينة المستلمة
            $this->accountingRepo->updateCashAccountBalance($cashAccountId, $amount);

            // 2. إذا كان سند قبض من عميل: تخفيض مديونية العميل في كشف الحساب
            if ($contactType === 'customer' && $customerId) {
                $customer = $this->customerRepo->find($customerId);
                if ($customer) {
                    $newBalance = $this->customerRepo->updateBalance($customerId, -$amount);
                    $this->customerRepo->recordLedgerTransaction([
                        'customer_id'      => $customerId,
                        'transaction_type' => 'payment',
                        'reference_id'     => 0, // سيتم تحديثه برقم السند
                        'debit'            => 0.00,
                        'credit'           => $amount,
                        'balance_after'    => $newBalance,
                        'transaction_date' => $voucherDate,
                        'notes'            => "سند قبض برقم [{$voucherNumber}] - خزينة: {$account['name']}",
                    ]);
                }
            }

            // 3. إذا كان مرتبطاً بفاتورة مبيعات محددة
            $invoiceId = !empty($data['reference_invoice_id']) ? (int)$data['reference_invoice_id'] : null;
            if ($invoiceId && $data['reference_invoice_type'] === 'sales_invoice') {
                $inv = $this->salesRepo->findInvoiceById($invoiceId);
                if ($inv) {
                    $newPaid = min((float)$inv['total_amount'], (float)$inv['paid_amount'] + $amount);
                    $newRem = max(0, (float)$inv['total_amount'] - $newPaid);
                    $this->salesRepo->updateInvoiceStatus($invoiceId, $inv['status'], $newPaid, $newRem);
                }
            }

            // 4. إدراج السند
            $voucherId = $this->accountingRepo->createVoucher([
                'voucher_number'         => $voucherNumber,
                'voucher_type'           => 'receipt',
                'cash_account_id'        => $cashAccountId,
                'contact_type'           => $contactType,
                'customer_id'            => $customerId,
                'reference_invoice_type' => !empty($data['reference_invoice_type']) ? $data['reference_invoice_type'] : 'none',
                'reference_invoice_id'   => $invoiceId,
                'amount'                 => $amount,
                'voucher_date'           => $voucherDate,
                'notes'                  => !empty($data['notes']) ? trim($data['notes']) : null,
                'created_by'             => $userId,
            ]);

            $this->logAudit($userId, 'create', 'payment_vouchers', $voucherId, null, [
                'voucher_number' => $voucherNumber,
                'type'           => 'receipt',
                'amount'         => $amount,
                'account'        => $account['name'],
            ]);

            Database::commit();

            return $this->getVoucherById($voucherId);
        } catch (Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * إنشاء سند صرف نقدية أو شيك بنكي (Payment Voucher)
     */
    public function createPaymentVoucher(array $data, $userId)
    {
        $cashAccountId = (int)($data['cash_account_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $voucherDate = !empty($data['voucher_date']) ? $data['voucher_date'] : date('Y-m-d');
        $contactType = in_array($data['contact_type'] ?? '', ['supplier', 'expense', 'other']) ? $data['contact_type'] : 'expense';

        if ($cashAccountId <= 0) {
            throw new Exception("يرجى تحديد الخزينة أو الحساب البنكي المنصرف منه.");
        }
        if ($amount <= 0) {
            throw new Exception("مبلغ سند الصرف يجب أن يكون أكبر من الصفر.");
        }

        $account = $this->getCashAccountById($cashAccountId);
        if (empty($account['is_active'])) {
            throw new Exception("الخزينة المحددة موقوفة حالياً.");
        }

        // فحص كفاية رصيد الخزينة
        if ((float)$account['current_balance'] < $amount) {
            throw new Exception("رصيد الخزينة/الحساب [{$account['name']}] الحالي هو (" . (float)$account['current_balance'] . ") وهو غير كافٍ لصرف (" . $amount . ").");
        }

        Database::beginTransaction();
        try {
            $voucherNumber = $this->accountingRepo->generateVoucherNumber('payment');

            // 1. خصم رصيد الخزينة
            $this->accountingRepo->updateCashAccountBalance($cashAccountId, -$amount);

            // 2. إذا كان صرف لمورد: سداد مستحقات في كشف حساب المورد
            $supplierId = !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null;
            if ($contactType === 'supplier' && $supplierId) {
                $supplier = $this->supplierRepo->find($supplierId);
                if ($supplier) {
                    $newBalance = $this->supplierRepo->updateBalance($supplierId, -$amount);
                    $this->supplierRepo->recordLedgerTransaction([
                        'supplier_id'      => $supplierId,
                        'transaction_type' => 'payment',
                        'reference_id'     => 0,
                        'debit'            => $amount,
                        'credit'           => 0.00,
                        'balance_after'    => $newBalance,
                        'transaction_date' => $voucherDate,
                        'notes'            => "سداد مستحقات بسند صرف برقم [{$voucherNumber}]",
                    ]);
                }
            }

            // 3. إذا كان مرتبطاً بفاتورة مشتريات
            $invoiceId = !empty($data['reference_invoice_id']) ? (int)$data['reference_invoice_id'] : null;
            if ($invoiceId && $data['reference_invoice_type'] === 'purchase_invoice') {
                $inv = $this->purchaseRepo->getInvoiceDetails($invoiceId);
                if ($inv) {
                    $newPaid = min((float)$inv['total_amount'], (float)$inv['paid_amount'] + $amount);
                    $newRem = max(0, (float)$inv['total_amount'] - $newPaid);
                    $this->purchaseRepo->updateStatus($invoiceId, $inv['status'], $newPaid, $newRem);
                }
            }

            // 4. إدراج السند
            $voucherId = $this->accountingRepo->createVoucher([
                'voucher_number'         => $voucherNumber,
                'voucher_type'           => 'payment',
                'cash_account_id'        => $cashAccountId,
                'contact_type'           => $contactType,
                'supplier_id'            => $supplierId,
                'expense_category_id'    => !empty($data['expense_category_id']) ? (int)$data['expense_category_id'] : null,
                'reference_invoice_type' => !empty($data['reference_invoice_type']) ? $data['reference_invoice_type'] : 'none',
                'reference_invoice_id'   => $invoiceId,
                'amount'                 => $amount,
                'voucher_date'           => $voucherDate,
                'notes'                  => !empty($data['notes']) ? trim($data['notes']) : null,
                'created_by'             => $userId,
            ]);

            $this->logAudit($userId, 'create', 'payment_vouchers', $voucherId, null, [
                'voucher_number' => $voucherNumber,
                'type'           => 'payment',
                'amount'         => $amount,
                'account'        => $account['name'],
            ]);

            Database::commit();

            return $this->getVoucherById($voucherId);
        } catch (Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * تحويل مالي بين الخزائن والحسابات البنكية (Transfer Voucher)
     */
    public function createTransferVoucher(array $data, $userId)
    {
        $fromId = (int)($data['cash_account_id'] ?? 0);
        $toId = (int)($data['to_cash_account_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $voucherDate = !empty($data['voucher_date']) ? $data['voucher_date'] : date('Y-m-d');

        if ($fromId <= 0 || $toId <= 0) {
            throw new Exception("يرجى تحديد الخزينة المحول منها والمحول إليها.");
        }
        if ($fromId === $toId) {
            throw new Exception("لا يمكن التحويل لنفس الخزينة أو الحساب.");
        }
        if ($amount <= 0) {
            throw new Exception("مبلغ التحويل يجب أن يكون أكبر من الصفر.");
        }

        $fromAcc = $this->getCashAccountById($fromId);
        $toAcc = $this->getCashAccountById($toId);

        if ((float)$fromAcc['current_balance'] < $amount) {
            throw new Exception("الرصيد في خزينة التحويل [{$fromAcc['name']}] هو (" . (float)$fromAcc['current_balance'] . ") ولا يكفي لتحويل (" . $amount . ").");
        }

        Database::beginTransaction();
        try {
            $voucherNumber = $this->accountingRepo->generateVoucherNumber('transfer');

            // خصم من الحساب المصدر وإضافة للحساب الهدف
            $this->accountingRepo->updateCashAccountBalance($fromId, -$amount);
            $this->accountingRepo->updateCashAccountBalance($toId, $amount);

            $voucherId = $this->accountingRepo->createVoucher([
                'voucher_number'     => $voucherNumber,
                'voucher_type'       => 'transfer',
                'cash_account_id'    => $fromId,
                'to_cash_account_id' => $toId,
                'contact_type'       => 'other',
                'amount'             => $amount,
                'voucher_date'       => $voucherDate,
                'notes'              => !empty($data['notes']) ? trim($data['notes']) : "تحويل من [{$fromAcc['name']}] إلى [{$toAcc['name']}]",
                'created_by'         => $userId,
            ]);

            $this->logAudit($userId, 'create', 'payment_vouchers', $voucherId, null, [
                'voucher_number' => $voucherNumber,
                'type'           => 'transfer',
                'from_account'   => $fromAcc['name'],
                'to_account'     => $toAcc['name'],
                'amount'         => $amount,
            ]);

            Database::commit();

            return $this->getVoucherById($voucherId);
        } catch (Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * إنشاء قيد يومية محاسبي مزدوج ومتوازن (Double-Entry Journal Entry)
     */
    public function createJournalEntry(array $data, $userId)
    {
        $description = trim($data['description'] ?? '');
        $entryDate = !empty($data['entry_date']) ? $data['entry_date'] : date('Y-m-d');
        $rawLines = $data['lines'] ?? [];

        if (empty($description)) {
            throw new Exception("شرح وبيان القيد المحاسبي مطلوب.");
        }

        if (empty($rawLines) || !is_array($rawLines) || count($rawLines) < 2) {
            throw new Exception("يجب أن يحتوي القيد المحاسبي المزدوج على طرفين على الأقل (طرف مدين وطرف دائن).");
        }

        $totalDebit = 0.00;
        $totalCredit = 0.00;
        $preparedLines = [];

        foreach ($rawLines as $idx => $line) {
            $accountName = trim($line['account_name'] ?? '');
            $debit = round((float)($line['debit'] ?? 0), 2);
            $credit = round((float)($line['credit'] ?? 0), 2);

            if (empty($accountName)) {
                throw new Exception("اسم الحساب في السطر رقم " . ($idx + 1) . " مطلوب.");
            }

            if ($debit < 0 || $credit < 0) {
                throw new Exception("لا يمكن إدخال مبالغ سالبة في بنود القيد اليومي.");
            }

            if ($debit == 0 && $credit == 0) {
                throw new Exception("السطر رقم " . ($idx + 1) . " يجب أن يحتوي على مبلغ مدين أو دائن أكبر من الصفر.");
            }

            $totalDebit += $debit;
            $totalCredit += $credit;

            $preparedLines[] = [
                'account_name' => $accountName,
                'account_type' => $line['account_type'] ?? 'asset',
                'debit'        => $debit,
                'credit'       => $credit,
                'notes'        => !empty($line['notes']) ? trim($line['notes']) : null,
            ];
        }

        // قاعدة محاسبية دولية صارمة: توازن القيد المحاسبي المزدوج
        if (abs($totalDebit - $totalCredit) >= 0.01) {
            throw new Exception("القيد المحاسبي غير متوازن! إجمالي المدين (" . number_format($totalDebit, 2) . ") لا يساوي إجمالي الدائن (" . number_format($totalCredit, 2) . "). الفارق: " . number_format(abs($totalDebit - $totalCredit), 2));
        }

        if ($totalDebit <= 0) {
            throw new Exception("إجمالي مبالغ القيد يجب أن يكون أكبر من الصفر.");
        }

        Database::beginTransaction();
        try {
            $entryNumber = $this->accountingRepo->generateJournalEntryNumber();

            $entryData = [
                'entry_number'   => $entryNumber,
                'entry_date'     => $entryDate,
                'description'    => $description,
                'reference_type' => $data['reference_type'] ?? 'manual',
                'reference_id'   => !empty($data['reference_id']) ? (int)$data['reference_id'] : null,
                'total_debit'    => $totalDebit,
                'total_credit'   => $totalCredit,
                'is_posted'      => 1,
                'created_by'     => $userId,
            ];

            $entryId = $this->accountingRepo->createJournalEntry($entryData, $preparedLines);

            $this->logAudit($userId, 'create', 'journal_entries', $entryId, null, [
                'entry_number' => $entryNumber,
                'total_debit'  => $totalDebit,
                'total_credit' => $totalCredit,
                'description'  => $description,
            ]);

            Database::commit();

            return $this->getJournalEntryById($entryId);
        } catch (Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public function getJournalEntries(array $filters = [], $page = 1, $perPage = 25)
    {
        return $this->accountingRepo->getJournalEntries($filters, $page, $perPage);
    }

    public function getJournalEntryById($id)
    {
        $entry = $this->accountingRepo->findJournalEntryById($id);
        if (!$entry) {
            throw new Exception("القيد اليومي غير موجود.");
        }
        return $entry;
    }
}
