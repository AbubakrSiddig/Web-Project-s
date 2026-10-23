<?php
namespace App\Controllers\Api;

use App\Services\SalesService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم فواتير المبيعات ونقاط البيع (Sales Controller)
 */
class SalesController extends Controller
{
    /** @var SalesService */
    protected $salesService;

    public function __construct(SalesService $salesService = null)
    {
        $this->salesService = $salesService ?: new SalesService();
    }

    /**
     * قائمة فواتير المبيعات
     * GET /api/sales
     */
    public function index(Request $request)
    {
        list($page, $perPage) = $this->getPaginationParams($request, 25, 100);
        $filters = [
            'search'       => $request->query('search'),
            'customer_id'  => $request->query('customer_id'),
            'warehouse_id' => $request->query('warehouse_id'),
            'payment_type' => $request->query('payment_type'),
            'status'       => $request->query('status'),
            'date_from'    => $request->query('date_from'),
            'date_to'      => $request->query('date_to'),
        ];

        $invoices = $this->salesService->getInvoices($filters, $page, $perPage);
        return $this->success('تم استرجاع قائمة فواتير المبيعات بنجاح', $invoices);
    }

    /**
     * تفاصيل فاتورة المبيعات
     * GET /api/sales/{id}
     */
    public function show(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $invoice = $this->salesService->getInvoiceById($id);
            return $this->success('تم استرجاع تفاصيل فاتورة المبيعات بنجاح', $invoice);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    /**
     * إنشاء فاتورة مبيعات جديدة
     * POST /api/sales
     */
    public function store(Request $request)
    {
        $this->validate($request->all(), [
            'customer_id'  => 'required|numeric',
            'warehouse_id' => 'required|numeric',
            'items'        => 'required',
        ]);

        try {
            $currentUser = $this->getAuthUser($request);
            $userId = (int)$currentUser['id'];

            // التحقق من صلاحية تجاوز الحد الائتماني إذا كان المستخدم مديراً
            $userPerms = $currentUser['permissions'] ?? [];
            $hasCreditOverride = in_array('sales.credit_override', $userPerms) || ($currentUser['role_id'] ?? 0) == 1;

            $invoice = $this->salesService->createInvoice($request->all(), $userId, $hasCreditOverride);
            return $this->success('تم إصدار فاتورة المبيعات وصرف الأدوية بنجاح', $invoice, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * إلغاء فاتورة مبيعات
     * POST /api/sales/{id}/cancel
     */
    public function cancel(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $currentUser = $this->getAuthUser($request);
            $userId = (int)$currentUser['id'];

            $invoice = $this->salesService->cancelInvoice($id, $userId);
            return $this->success('تم إلغاء فاتورة المبيعات واسترجاع المخزون بنجاح', $invoice);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تجهيز بيانات الطباعة (حراري 80mm أو عادي A4)
     * GET /api/sales/{id}/print
     */
    public function printInvoice(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $format = $request->query('format', 'a4');
        try {
            $printData = $this->salesService->getPrintableInvoice($id, $format);
            return $this->success('تم تجهيز بيانات الطباعة بنجاح', $printData);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }
}
