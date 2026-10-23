<?php
namespace App\Controllers\Api;

use App\Services\PurchaseReturnService;
use App\Services\SalesReturnService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم المرتجعات للمبيعات والمشتريات (Return Controller)
 */
class ReturnController extends Controller
{
    /** @var SalesReturnService */
    protected $salesReturnService;

    /** @var PurchaseReturnService */
    protected $purchaseReturnService;

    public function __construct(
        SalesReturnService $salesReturnService = null,
        PurchaseReturnService $purchaseReturnService = null
    ) {
        $this->salesReturnService = $salesReturnService ?: new SalesReturnService();
        $this->purchaseReturnService = $purchaseReturnService ?: new PurchaseReturnService();
    }

    // ==========================================
    // 1. مرتجعات المبيعات (Sales Returns)
    // ==========================================

    public function salesIndex(Request $request)
    {
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 25);
        $filters = [
            'search'                 => $request->query('search'),
            'customer_id'            => $request->query('customer_id'),
            'warehouse_id'           => $request->query('warehouse_id'),
            'refund_status'          => $request->query('refund_status'),
            'return_to_stock_status' => $request->query('return_to_stock_status'),
            'date_from'              => $request->query('date_from'),
            'date_to'                => $request->query('date_to'),
        ];

        $returns = $this->salesReturnService->getReturns($filters, $page, $perPage);
        return $this->success('تم استرجاع قائمة مرتجعات المبيعات بنجاح', $returns);
    }

    public function salesShow(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $return = $this->salesReturnService->getReturnById($id);
            return $this->success('تم استرجاع بيانات مرتجع المبيعات بنجاح', $return);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    public function salesStore(Request $request)
    {
        $this->validate($request->all(), [
            'customer_id'  => 'required|numeric',
            'warehouse_id' => 'required|numeric',
            'items'        => 'required',
        ]);

        try {
            $currentUser = $request->getUser();
            $userId = $currentUser['id'] ?? 1;

            $return = $this->salesReturnService->createReturn($request->all(), $userId);
            return $this->success('تم إنشاء مرتجع المبيعات بنجاح', $return, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    // ==========================================
    // 2. مرتجعات المشتريات (Purchase Returns)
    // ==========================================

    public function purchasesIndex(Request $request)
    {
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 25);
        $filters = [
            'search'        => $request->query('search'),
            'supplier_id'   => $request->query('supplier_id'),
            'warehouse_id'  => $request->query('warehouse_id'),
            'refund_status' => $request->query('refund_status'),
            'date_from'     => $request->query('date_from'),
            'date_to'       => $request->query('date_to'),
        ];

        $returns = $this->purchaseReturnService->getReturns($filters, $page, $perPage);
        return $this->success('تم استرجاع قائمة مرتجعات المشتريات بنجاح', $returns);
    }

    public function purchasesShow(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $return = $this->purchaseReturnService->getReturnById($id);
            return $this->success('تم استرجاع تفاصيل مرتجع المشتريات بنجاح', $return);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    public function purchasesStore(Request $request)
    {
        $this->validate($request->all(), [
            'supplier_id'  => 'required|numeric',
            'warehouse_id' => 'required|numeric',
            'items'        => 'required',
        ]);

        try {
            $currentUser = $request->getUser();
            $userId = $currentUser['id'] ?? 1;

            $return = $this->purchaseReturnService->createReturn($request->all(), $userId);
            return $this->success('تم إنشاء مرتجع المشتريات بنجاح', $return, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }
}
