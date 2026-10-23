<?php
namespace App\Controllers\Api;

use App\Services\BatchService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم التشغيلات والصلاحيات (Batch Controller)
 */
class BatchController extends Controller
{
    /** @var BatchService */
    protected $batchService;

    public function __construct(BatchService $batchService = null)
    {
        $this->batchService = $batchService ?: new BatchService();
    }

    /**
     * قائمة التشغيلات
     * GET /api/batches
     */
    public function index(Request $request)
    {
        $productId = $request->query('product_id');
        if ($productId) {
            $batches = $this->batchService->getBatchesForProduct((int)$productId);
            return $this->success('تم استرجاع تشغيلات الصنف بنجاح', $batches);
        }

        // استرجاع أحدث التشغيلات
        $batchRepo = new \App\Repositories\MySQL\MySQLBatchRepository();
        $batches = $batchRepo->all(['*']);
        return $this->success('تم استرجاع قائمة التشغيلات بنجاح', $batches);
    }

    /**
     * تفاصيل تشغيلة
     * GET /api/batches/{id}
     */
    public function show(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $warehouseId = $request->query('warehouse_id');
        try {
            $batch = $this->batchService->getBatchById($id, $warehouseId);
            return $this->success('تم استرجاع تفاصيل التشغيلة بنجاح', $batch);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    /**
     * إضافة تشغيلة جديدة
     * POST /api/batches
     */
    public function store(Request $request)
    {
        $data = $this->validate($request->all(), [
            'product_id'   => 'required|integer|positive',
            'batch_number' => 'required',
            'expiry_date'  => 'required|date',
        ]);

        try {
            $currentUser = $request->getUser();
            $newBatch = $this->batchService->createBatch($request->all(), $currentUser['id'] ?? null);
            return $this->success('تم إنشاء التشغيلة بنجاح', $newBatch, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تعديل بيانات تشغيلة
     * PUT /api/batches/{id}
     */
    public function update(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $currentUser = $request->getUser();
            $updated = $this->batchService->updateBatch($id, $request->all(), $currentUser['id'] ?? null);
            return $this->success('تم تحديث بيانات التشغيلة بنجاح', $updated);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تنبيهات الأصناف قريبة الانتهاء
     * GET /api/batches/near-expiry
     */
    public function nearExpiry(Request $request)
    {
        $days = $request->query('days');
        $warehouseId = $request->query('warehouse_id');
        $alerts = $this->batchService->getNearExpiryAlerts($days !== null ? (int)$days : null, $warehouseId);
        return $this->success('تم استرجاع تنبيهات الأدوية قريبة الانتهاء', $alerts);
    }

    /**
     * تنبيهات وقائمة الأصناف المنتهية الصلاحية
     * GET /api/batches/expired
     */
    public function expired(Request $request)
    {
        $warehouseId = $request->query('warehouse_id');
        $alerts = $this->batchService->getExpiredAlerts($warehouseId);
        return $this->success('تم استرجاع قائمة الأدوية منتهية الصلاحية', $alerts);
    }

    /**
     * سياسة الصرف الحالية
     * GET /api/batches/policy
     */
    public function getPolicy(Request $request)
    {
        $policy = $this->batchService->getDispatchPolicy();
        return $this->success('تم استرجاع سياسة الصرف المعتمدة', [
            'policy'      => $policy,
            'description' => $policy === 'FEFO' ? 'الأقرب انتهاءً يخرج أولاً (First Expire, First Out)' : 'الأقدم دخولاً يخرج أولاً (First In, First Out)'
        ]);
    }

    /**
     * تبديل سياسة الصرف (FEFO / FIFO)
     * PUT /api/batches/policy
     */
    public function setPolicy(Request $request)
    {
        $data = $this->validate($request->all(), [
            'policy' => 'required|in:FEFO,FIFO',
        ]);

        try {
            $currentUser = $request->getUser();
            $newPolicy = $this->batchService->setDispatchPolicy($data['policy'], $currentUser['id'] ?? null);
            return $this->success('تم تحديث سياسة صرف المخزون بنجاح', ['policy' => $newPolicy]);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * محرك اقتراح التشغيلات للصرف في المبيعات
     * POST /api/batches/suggest-dispatch
     */
    public function suggestDispatch(Request $request)
    {
        $data = $this->validate($request->all(), [
            'product_id'   => 'required|integer|positive',
            'warehouse_id' => 'required|integer|positive',
            'quantity'     => 'required|numeric|greater_than_zero',
        ]);

        try {
            $suggestion = $this->batchService->suggestBatchesForDispatch(
                (int)$data['product_id'],
                (int)$data['warehouse_id'],
                (float)$data['quantity']
            );
            return $this->success('تم حساب وتوزيع التشغيلات المقترحة للصرف', $suggestion);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تحديث حالات التشغيلات المنتهية
     * POST /api/batches/mark-expired
     */
    public function markExpired(Request $request)
    {
        $count = $this->batchService->autoMarkExpiredBatches();
        return $this->success("تم تحديث حالة ({$count}) تشغيلة منتهية الصلاحية", ['updated_count' => $count]);
    }
}
