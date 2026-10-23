<?php
namespace App\Controllers\Api;

use App\Services\CustomerService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم العملاء والصيدليات (Customer Controller)
 */
class CustomerController extends Controller
{
    /** @var CustomerService */
    protected $customerService;

    public function __construct(CustomerService $customerService = null)
    {
        $this->customerService = $customerService ?: new CustomerService();
    }

    /**
     * قائمة العملاء والصيدليات
     * GET /api/customers
     */
    public function index(Request $request)
    {
        list($page, $perPage) = $this->getPaginationParams($request, 25, 100);
        $filters = [
            'search'    => $request->query('search'),
            'is_active' => $request->query('is_active'),
        ];

        $customers = $this->customerService->getCustomers($filters, $page, $perPage);
        return $this->success('تم استرجاع قائمة العملاء بنجاح', $customers);
    }

    /**
     * تفاصيل عميل / صيدلية
     * GET /api/customers/{id}
     */
    public function show(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $customer = $this->customerService->getCustomerById($id);
            return $this->success('تم استرجاع بيانات العميل بنجاح', $customer);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    /**
     * إضافة عميل / صيدلية جديدة
     * POST /api/customers
     */
    public function store(Request $request)
    {
        $this->validate($request->all(), [
            'name'  => 'required|min:2|max:150',
            'phone' => 'required',
        ]);

        try {
            $currentUser = $request->getUser();
            $newCustomer = $this->customerService->createCustomer($request->all(), $currentUser['id'] ?? null);
            return $this->success('تم إضافة العميل بنجاح', $newCustomer, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تعديل بيانات عميل
     * PUT /api/customers/{id}
     */
    public function update(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $currentUser = $request->getUser();
            $updated = $this->customerService->updateCustomer($id, $request->all(), $currentUser['id'] ?? null);
            return $this->success('تم تحديث بيانات العميل بنجاح', $updated);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * كشف حساب العميل التفصيلي
     * GET /api/customers/{id}/ledger
     */
    public function ledger(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 50);
        $filters = [
            'date_from' => $request->query('date_from'),
            'date_to'   => $request->query('date_to'),
        ];

        try {
            $ledger = $this->customerService->getCustomerLedger($id, $filters, $page, $perPage);
            return $this->success('تم استرجاع كشف حساب العميل بنجاح', $ledger);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تسوية يدوية لرصيد العميل
     * POST /api/customers/{id}/adjust-balance
     */
    public function adjustBalance(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $this->validate($request->all(), [
            'amount' => 'required|numeric',
            'type'   => 'required|in:adjustment,invoice,payment,return',
        ]);

        try {
            $currentUser = $request->getUser();
            $amount = (float)$request->input('amount');
            $type = $request->input('type');
            $notes = $request->input('notes', 'تسوية رصيد يدوية');

            $newBalance = $this->customerService->adjustBalance($id, $amount, $type, 0, $notes, $currentUser['id'] ?? null);
            return $this->success('تم تعديل رصيد العميل بنجاح', ['new_balance' => $newBalance]);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }
}
