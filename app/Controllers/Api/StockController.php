<?php
namespace App\Controllers\Api;

use App\Services\StockService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم الحركات والتحويلات والجرد المخزني (Stock & Transfer Controller)
 */
class StockController extends Controller
{
    /** @var StockService */
    protected $stockService;

    public function __construct(StockService $stockService = null)
    {
        $this->stockService = $stockService ?: new StockService();
    }

    /**
     * قائمة التحويلات بين المخازن
     * GET /api/stock/transfers
     */
    public function transfers(Request $request)
    {
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 25);
        $filters = [
            'from_warehouse_id' => $request->query('from_warehouse_id'),
            'to_warehouse_id'   => $request->query('to_warehouse_id'),
            'status'            => $request->query('status'),
        ];

        $transfers = $this->stockService->getTransfersList($filters, $page, $perPage);
        return $this->success('تم استرجاع قائمة التحويلات بنجاح', $transfers);
    }

    /**
     * تفاصيل إذن تحويل
     * GET /api/stock/transfers/{id}
     */
    public function transferDetails(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $stockRepo = new \App\Repositories\MySQL\MySQLStockRepository();
        $details = $stockRepo->getTransferDetails($id);

        if (!$details) {
            return $this->error('إذن التحويل غير موجود', null, 404);
        }

        return $this->success('تم استرجاع تفاصيل إذن التحويل', $details);
    }

    /**
     * إنشاء إذن تحويل بين المخازن
     * POST /api/stock/transfers
     */
    public function storeTransfer(Request $request)
    {
        $data = $this->validate($request->all(), [
            'from_warehouse_id' => 'required|integer|positive',
            'to_warehouse_id'   => 'required|integer|positive',
        ]);

        try {
            $currentUser = $request->getUser();
            $newTransfer = $this->stockService->createTransfer($request->all(), $currentUser['id'] ?? null);
            return $this->success('تم إنشاء إذن التحويل بنجاح', $newTransfer, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * اعتماد وتنفيذ التحويل المخزني
     * PUT /api/stock/transfers/{id}/approve
     */
    public function approveTransfer(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $currentUser = $request->getUser();
            $completed = $this->stockService->approveTransfer($id, $currentUser['id'] ?? null);
            return $this->success('تم اعتماد وتنفيذ التحويل وتحديث أرصدة المخازن بنجاح', $completed);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * قائمة وثائق الجرد والتسويات
     * GET /api/stock/adjustments
     */
    public function adjustments(Request $request)
    {
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 25);
        $filters = [
            'warehouse_id' => $request->query('warehouse_id'),
            'status'       => $request->query('status'),
        ];

        $adjustments = $this->stockService->getAdjustmentsList($filters, $page, $perPage);
        return $this->success('تم استرجاع قائمة التسويات بنجاح', $adjustments);
    }

    /**
     * تفاصيل وثيقة جرد وتسوية
     * GET /api/stock/adjustments/{id}
     */
    public function adjustmentDetails(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $stockRepo = new \App\Repositories\MySQL\MySQLStockRepository();
        $details = $stockRepo->getAdjustmentDetails($id);

        if (!$details) {
            return $this->error('وثيقة التسوية غير موجودة', null, 404);
        }

        return $this->success('تم استرجاع تفاصيل وثيقة التسوية', $details);
    }

    /**
     * إنشاء وثيقة جرد وتسوية
     * POST /api/stock/adjustments
     */
    public function storeAdjustment(Request $request)
    {
        $data = $this->validate($request->all(), [
            'warehouse_id' => 'required|integer|positive',
            'reason'       => 'required',
        ]);

        try {
            $currentUser = $request->getUser();
            $newAdj = $this->stockService->createAdjustment($request->all(), $currentUser['id'] ?? null);
            return $this->success('تم إنشاء وثيقة الجرد بنجاح', $newAdj, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * اعتماد التسوية وتعديل الأرصدة المخزنية
     * PUT /api/stock/adjustments/{id}/approve
     */
    public function approveAdjustment(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $currentUser = $request->getUser();
            $approved = $this->stockService->approveAdjustment($id, $currentUser['id'] ?? null);
            return $this->success('تم اعتماد التسوية وتعديل الأرصدة المخزنية بنجاح', $approved);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * سجل حركات المخزون الشامل والتاريخي
     * GET /api/stock/transactions
     */
    public function transactions(Request $request)
    {
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 50);
        $filters = [
            'warehouse_id'     => $request->query('warehouse_id'),
            'product_id'       => $request->query('product_id'),
            'batch_id'         => $request->query('batch_id'),
            'transaction_type' => $request->query('transaction_type'),
            'date_from'        => $request->query('date_from'),
            'date_to'          => $request->query('date_to'),
        ];

        $history = $this->stockService->getStockTransactions($filters, $page, $perPage);
        return $this->success('تم استرجاع سجل حركة المخزون بنجاح', $history);
    }
}
