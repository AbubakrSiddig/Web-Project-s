<?php
namespace App\Controllers\Api;

use App\Services\AccountingService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم الإدارة المالية والخزائن والسندات (Accounting Controller)
 */
class AccountingController extends Controller
{
    /** @var AccountingService */
    protected $accountingService;

    public function __construct(AccountingService $accountingService = null)
    {
        $this->accountingService = $accountingService ?: new AccountingService();
    }

    // ==========================================
    // 1. الخزائن والحسابات البنكية (Cash Accounts)
    // ==========================================

    public function accountsIndex(Request $request)
    {
        $filters = [
            'is_active'    => $request->query('is_active'),
            'account_type' => $request->query('account_type'),
        ];
        $accounts = $this->accountingService->getCashAccounts($filters);
        return $this->success('تم استرجاع قائمة الخزائن والحسابات بنجاح', $accounts);
    }

    public function accountsStore(Request $request)
    {
        $this->validate($request->all(), [
            'name'         => 'required|min:2|max:100',
            'account_type' => 'required|in:cash,bank',
        ]);

        try {
            $currentUser = $request->getUser();
            $account = $this->accountingService->createCashAccount($request->all(), $currentUser['id'] ?? null);
            return $this->success('تم إنشاء الحساب / الخزينة بنجاح', $account, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    public function accountsUpdate(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $currentUser = $request->getUser();
            $account = $this->accountingService->updateCashAccount($id, $request->all(), $currentUser['id'] ?? null);
            return $this->success('تم تحديث بيانات الحساب بنجاح', $account);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    // ==========================================
    // 2. تصنيفات المصروفات (Expense Categories)
    // ==========================================

    public function expenseCategoriesIndex()
    {
        $categories = $this->accountingService->getExpenseCategories();
        return $this->success('تم استرجاع بنود المصروفات بنجاح', $categories);
    }

    public function expenseCategoriesStore(Request $request)
    {
        $this->validate($request->all(), [
            'name' => 'required|min:2|max:100',
        ]);

        try {
            $currentUser = $request->getUser();
            $cat = $this->accountingService->createExpenseCategory($request->all(), $currentUser['id'] ?? null);
            return $this->success('تم إنشاء بند المصروف بنجاح', $cat, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    // ==========================================
    // 3. السندات المالية (Vouchers)
    // ==========================================

    public function vouchersIndex(Request $request)
    {
        list($page, $perPage) = $this->getPaginationParams($request, 25, 100);
        $filters = [
            'search'          => $request->query('search'),
            'voucher_type'    => $request->query('voucher_type'),
            'cash_account_id' => $request->query('cash_account_id'),
            'contact_type'    => $request->query('contact_type'),
            'customer_id'     => $request->query('customer_id'),
            'supplier_id'     => $request->query('supplier_id'),
            'date_from'       => $request->query('date_from'),
            'date_to'         => $request->query('date_to'),
        ];

        $vouchers = $this->accountingService->getVouchers($filters, $page, $perPage);
        return $this->success('تم استرجاع قائمة السندات بنجاح', $vouchers);
    }

    public function vouchersShow(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $voucher = $this->accountingService->getVoucherById($id);
            return $this->success('تم استرجاع تفاصيل السند بنجاح', $voucher);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    public function createReceipt(Request $request)
    {
        $this->validate($request->all(), [
            'cash_account_id' => 'required|numeric',
            'amount'          => 'required|numeric',
        ]);

        try {
            $currentUser = $this->getAuthUser($request);
            $userId = (int)$currentUser['id'];

            $voucher = $this->accountingService->createReceiptVoucher($request->all(), $userId);
            return $this->success('تم إصدار سند القبض بنجاح', $voucher, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    public function createPayment(Request $request)
    {
        $this->validate($request->all(), [
            'cash_account_id' => 'required|numeric',
            'amount'          => 'required|numeric',
        ]);

        try {
            $currentUser = $this->getAuthUser($request);
            $userId = (int)$currentUser['id'];

            $voucher = $this->accountingService->createPaymentVoucher($request->all(), $userId);
            return $this->success('تم إصدار سند الصرف بنجاح', $voucher, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    public function createTransfer(Request $request)
    {
        $this->validate($request->all(), [
            'cash_account_id'    => 'required|numeric',
            'to_cash_account_id' => 'required|numeric',
            'amount'             => 'required|numeric',
        ]);

        try {
            $currentUser = $this->getAuthUser($request);
            $userId = (int)$currentUser['id'];

            $voucher = $this->accountingService->createTransferVoucher($request->all(), $userId);
            return $this->success('تم تحويل الأموال وإصدار سند التحويل بنجاح', $voucher, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    // ==========================================
    // 3. القيود اليومية المحاسبية (Journal Entries)
    // ==========================================

    public function journalEntriesIndex(Request $request)
    {
        list($page, $perPage) = $this->getPaginationParams($request, 25, 100);
        $filters = [
            'search'    => $request->query('search'),
            'date_from' => $request->query('date_from'),
            'date_to'   => $request->query('date_to'),
        ];

        $entries = $this->accountingService->getJournalEntries($filters, $page, $perPage);
        return $this->success('تم استرجاع قائمة القيود اليومية بنجاح', $entries);
    }

    public function journalEntriesShow(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $entry = $this->accountingService->getJournalEntryById($id);
            return $this->success('تم استرجاع تفاصيل القيد اليومي بنجاح', $entry);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    public function journalEntriesStore(Request $request)
    {
        $this->validate($request->all(), [
            'description' => 'required|min:3',
            'lines'       => 'required',
        ]);

        try {
            $currentUser = $this->getAuthUser($request);
            $userId = (int)$currentUser['id'];

            $entry = $this->accountingService->createJournalEntry($request->all(), $userId);
            return $this->success('تم تسجيل القيد اليومي المزدوج بنجاح', $entry, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }
}
