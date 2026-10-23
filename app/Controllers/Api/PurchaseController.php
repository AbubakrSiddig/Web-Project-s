<?php
namespace App\Controllers\Api;

use App\Services\PurchaseService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم فواتير المشتريات (Purchase Controller)
 */
class PurchaseController extends Controller
{
    /** @var PurchaseService */
    protected $purchaseService;

    public function __construct(PurchaseService $purchaseService = null)
    {
        $this->purchaseService = $purchaseService ?: new PurchaseService();
    }

    /**
     * قائمة فواتير المشتريات
     * GET /api/purchases
     */
    public function index(Request $request)
    {
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 25);
        $filters = [
            'supplier_id'  => $request->query('supplier_id'),
            'warehouse_id' => $request->query('warehouse_id'),
            'status'       => $request->query('status'),
            'search'       => $request->query('search'),
        ];

        $invoices = $this->purchaseService->getInvoices($filters, $page, $perPage);
        return $this->success('تم استرجاع قائمة فواتير المشتريات بنجاح', $invoices);
    }

    /**
     * تفاصيل فاتورة مشتريات
     * GET /api/purchases/{id}
     */
    public function show(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $invoice = $this->purchaseService->getInvoiceById($id);
            return $this->success('تم استرجاع تفاصيل فاتورة الشراء بنجاح', $invoice);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    /**
     * إنشاء فاتورة مشتريات
     * POST /api/purchases
     */
    public function store(Request $request)
    {
        $data = $this->validate($request->all(), [
            'supplier_id'  => 'required|integer|positive',
            'warehouse_id' => 'required|integer|positive',
        ]);

        try {
            $currentUser = $request->getUser();
            $invoice = $this->purchaseService->createInvoice($request->all(), $currentUser['id'] ?? null);
            return $this->success('تم إنشاء فاتورة المشتريات بنجاح', $invoice, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * استلام واعتماد فاتورة مشتريات
     * PUT /api/purchases/{id}/receive
     */
    public function receive(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $currentUser = $request->getUser();
            $received = $this->purchaseService->receiveInvoice($id, $currentUser['id'] ?? null);
            return $this->success('تم استلام فاتورة المشتريات وتحديث المخزون بنجاح', $received);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }
}
