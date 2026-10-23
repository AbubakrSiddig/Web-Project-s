<?php
namespace App\Controllers\Api;

use App\Services\WarehouseService;
use Core\Controller;
use Core\Request;
use Exception;

/**
 * متحكم المخازن (Warehouse Controller)
 */
class WarehouseController extends Controller
{
    /** @var WarehouseService */
    protected $warehouseService;

    public function __construct(WarehouseService $warehouseService = null)
    {
        $this->warehouseService = $warehouseService ?: new WarehouseService();
    }

    /**
     * قائمة المخازن
     * GET /api/warehouses
     */
    public function index(Request $request)
    {
        $warehouses = $this->warehouseService->getAllWarehouses();
        return $this->success('تم استرجاع قائمة المخازن بنجاح', $warehouses);
    }

    /**
     * تفاصيل مخزن
     * GET /api/warehouses/{id}
     */
    public function show(Request $request)
    {
        $id = (int)$request->routeParam('id');
        try {
            $wh = $this->warehouseService->getWarehouseById($id);
            return $this->success('تم استرجاع بيانات المخزن بنجاح', $wh);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 404);
        }
    }

    /**
     * رصيد وجرد المخزن
     * GET /api/warehouses/{id}/inventory
     */
    public function inventory(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 50);
        $filters = [
            'search'      => $request->query('search'),
            'category_id' => $request->query('category_id'),
            'has_stock'   => $request->query('has_stock'),
        ];

        try {
            $inventory = $this->warehouseService->getWarehouseInventory($id, $filters, $page, $perPage);
            return $this->success('تم استرجاع جرد ورصيد المخزن بنجاح', $inventory);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * إضافة مخزن جديد
     * POST /api/warehouses
     */
    public function store(Request $request)
    {
        $data = $this->validate($request->all(), [
            'name' => 'required|min:3|max:100',
        ]);

        try {
            $currentUser = $request->getUser();
            $newWh = $this->warehouseService->createWarehouse($request->all(), $currentUser['id'] ?? null);
            return $this->success('تم إنشاء المخزن بنجاح', $newWh, 201);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }

    /**
     * تعديل بيانات مخزن
     * PUT /api/warehouses/{id}
     */
    public function update(Request $request)
    {
        $id = (int)$request->routeParam('id');
        $data = $this->validate($request->all(), [
            'name' => 'min:3|max:100',
        ]);

        try {
            $currentUser = $request->getUser();
            $updated = $this->warehouseService->updateWarehouse($id, $request->all(), $currentUser['id'] ?? null);
            return $this->success('تم تحديث بيانات المخزن بنجاح', $updated);
        } catch (Exception $e) {
            return $this->error($e->getMessage(), null, 400);
        }
    }
}
