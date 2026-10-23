<?php
namespace App\Controllers\Api;

use App\Services\SupplierService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم الموردين (Supplier Controller)
 */
class SupplierController extends Controller
{
    /** @var SupplierService */
    protected $supplierService;

    public function __construct(SupplierService $supplierService = null)
    {
        $this->supplierService = $supplierService ?: new SupplierService();
    }

    /**
     * قائمة الموردين
     * GET /api/suppliers
     */
    public function index(Request $request)
    {
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 25);
        $filters = [
            'search'    => $request->query('search'),
            'is_active' => $request->query('is_active'),
        ];

        $suppliers = $this->supplierService->getSuppliers($filters, $page, $perPage);
        return $this->success('تم استرجاع قائمة الموردين بنجاح', $suppliers);
    }

    /**
     * تفاصيل مورد
     * GET /api/suppliers/{id}
     */
    public function show(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $supplier = $this->supplierService->getSupplierById($id);
            return $this->success('تم استرجاع بيانات المورد بنجاح', $supplier);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    /**
     * إضافة مورد جديد
     * POST /api/suppliers
     */
    public function store(Request $request)
    {
        $data = $this->validate($request->all(), [
            'name'  => 'required|min:2|max:150',
            'phone' => 'required',
        ]);

        try {
            $currentUser = $request->getUser();
            $newSupplier = $this->supplierService->createSupplier($request->all(), $currentUser['id'] ?? null);
            return $this->success('تم إضافة المورد بنجاح', $newSupplier, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تعديل بيانات مورد
     * PUT /api/suppliers/{id}
     */
    public function update(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $data = $this->validate($request->all(), [
            'name'  => 'min:2|max:150',
        ]);

        try {
            $currentUser = $request->getUser();
            $updated = $this->supplierService->updateSupplier($id, $request->all(), $currentUser['id'] ?? null);
            return $this->success('تم تحديث بيانات المورد بنجاح', $updated);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * كشف حساب المورد
     * GET /api/suppliers/{id}/ledger
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
            $ledger = $this->supplierService->getSupplierLedger($id, $filters, $page, $perPage);
            return $this->success('تم استرجاع كشف حساب المورد بنجاح', $ledger);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }
}
