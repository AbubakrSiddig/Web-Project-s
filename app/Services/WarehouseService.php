<?php
namespace App\Services;

use App\Repositories\Interfaces\WarehouseRepositoryInterface;
use App\Repositories\MySQL\MySQLWarehouseRepository;
use Core\Service;
use Exception;

/**
 * خدمة إدارة المخازن (Warehouse Service)
 */
class WarehouseService extends Service
{
    /** @var WarehouseRepositoryInterface */
    protected $warehouseRepo;

    public function __construct(WarehouseRepositoryInterface $warehouseRepo = null)
    {
        $this->warehouseRepo = $warehouseRepo ?: new MySQLWarehouseRepository();
    }

    public function getAllWarehouses()
    {
        return $this->warehouseRepo->getWarehousesWithKeeper();
    }

    public function getWarehouseById($id)
    {
        $wh = $this->warehouseRepo->find($id);
        if (!$wh) {
            throw new Exception("المخزن المطلوب غير موجود.");
        }
        return $wh;
    }

    public function createWarehouse(array $data, $userId = null)
    {
        $name = trim($data['name']);
        $code = !empty($data['code']) ? trim($data['code']) : 'WH-' . str_pad($this->warehouseRepo->count() + 1, 2, '0', STR_PAD_LEFT);

        $existing = $this->warehouseRepo->findBy('code', $code);
        if ($existing) {
            throw new Exception("كود المخزن [{$code}] مسجل مسبقاً.");
        }

        $whId = $this->warehouseRepo->create([
            'code'       => $code,
            'name'       => $name,
            'location'   => !empty($data['location']) ? trim($data['location']) : null,
            'keeper_id'  => !empty($data['keeper_id']) ? (int)$data['keeper_id'] : null,
            'branch_id'  => 1,
            'is_active'  => isset($data['is_active']) ? (int)$data['is_active'] : 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logAudit($userId, 'create', 'warehouses', $whId, null, ['name' => $name, 'code' => $code]);

        return $this->getWarehouseById($whId);
    }

    public function updateWarehouse($id, array $data, $userId = null)
    {
        $wh = $this->getWarehouseById($id);

        if (!empty($data['code']) && $data['code'] !== $wh['code']) {
            $existing = $this->warehouseRepo->findBy('code', trim($data['code']));
            if ($existing && (int)$existing['id'] !== (int)$id) {
                throw new Exception("كود المخزن [{$data['code']}] مسجل مسبقاً.");
            }
        }

        $updateData = [];
        if (!empty($data['name'])) $updateData['name'] = trim($data['name']);
        if (!empty($data['code'])) $updateData['code'] = trim($data['code']);
        if (array_key_exists('location', $data)) $updateData['location'] = !empty($data['location']) ? trim($data['location']) : null;
        if (array_key_exists('keeper_id', $data)) $updateData['keeper_id'] = !empty($data['keeper_id']) ? (int)$data['keeper_id'] : null;
        if (isset($data['is_active'])) $updateData['is_active'] = (int)$data['is_active'];

        if (!empty($updateData)) {
            $this->warehouseRepo->update($id, $updateData);
            $this->logAudit($userId, 'update', 'warehouses', $id, ['name' => $wh['name']], $updateData);
        }

        return $this->getWarehouseById($id);
    }

    public function getWarehouseInventory($warehouseId, array $filters = [], $page = 1, $perPage = 50)
    {
        $this->getWarehouseById($warehouseId); // للتحقق من وجود المخزن
        return $this->warehouseRepo->getWarehouseStockInventory($warehouseId, $filters, $page, $perPage);
    }
}
