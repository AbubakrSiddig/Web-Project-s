<?php
namespace App\Services;

use App\Repositories\Interfaces\BatchRepositoryInterface;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Repositories\MySQL\MySQLBatchRepository;
use App\Repositories\MySQL\MySQLProductRepository;
use Core\Database;
use Core\Service;
use Exception;

/**
 * خدمة إدارة التشغيلات والصلاحيات ومحرك سياسات الصرف FEFO/FIFO (Batch Service)
 */
class BatchService extends Service
{
    /** @var BatchRepositoryInterface */
    protected $batchRepo;

    /** @var ProductRepositoryInterface */
    protected $productRepo;

    public function __construct(
        BatchRepositoryInterface $batchRepo = null,
        ProductRepositoryInterface $productRepo = null
    ) {
        $this->batchRepo = $batchRepo ?: new MySQLBatchRepository();
        $this->productRepo = $productRepo ?: new MySQLProductRepository();
    }

    public function getBatchesForProduct($productId)
    {
        return $this->batchRepo->getBatchesByProduct($productId);
    }

    public function getBatchById($id, $warehouseId = null)
    {
        $batch = $this->batchRepo->getBatchWithStock($id, $warehouseId);
        if (!$batch) {
            throw new Exception("التشغيلة المطلوبة غير موجودة.");
        }
        return $batch;
    }

    public function createBatch(array $data, $userId = null)
    {
        $productId = (int)$data['product_id'];
        $product = $this->productRepo->find($productId);
        if (!$product) {
            throw new Exception("الصنف المرتبط بالتشغيلة غير موجود.");
        }

        $batchNumber = trim($data['batch_number']);
        if (empty($batchNumber)) {
            throw new Exception("رقم التشغيلة مطلوب.");
        }

        // التحقق من عدم تكرار نفس رقم التشغيلة لنفس الصنف
        $existing = $this->batchRepo->findByProductAndBatchNumber($productId, $batchNumber);
        if ($existing) {
            throw new Exception("رقم التشغيلة [{$batchNumber}] مسجل مسبقاً لهذا الصنف.");
        }

        if (empty($data['expiry_date']) || !strtotime($data['expiry_date'])) {
            throw new Exception("تاريخ الانتهاء مطلوب ويجب أن يكون تاريخاً صالحاً (YYYY-MM-DD).");
        }

        $expiryDate = date('Y-m-d', strtotime($data['expiry_date']));
        $productionDate = !empty($data['production_date']) ? date('Y-m-d', strtotime($data['production_date'])) : null;

        if ($productionDate && $productionDate > $expiryDate) {
            throw new Exception("تاريخ الإنتاج لا يمكن أن يكون لاحقاً لتاريخ الانتهاء.");
        }

        // تحديد الحالة تلقائياً
        $isExpired = ($expiryDate <= date('Y-m-d'));
        $status = $isExpired ? 'expired' : ($data['status'] ?? 'active');

        $purchasePrice = isset($data['purchase_price']) && (float)$data['purchase_price'] > 0 
            ? (float)$data['purchase_price'] 
            : (float)$product['purchase_price'];

        $sellingPrice = isset($data['selling_price']) && (float)$data['selling_price'] > 0 
            ? (float)$data['selling_price'] 
            : (float)$product['selling_price'];

        $batchData = [
            'product_id'      => $productId,
            'batch_number'    => $batchNumber,
            'production_date' => $productionDate,
            'expiry_date'     => $expiryDate,
            'purchase_price'  => $purchasePrice,
            'selling_price'   => $sellingPrice,
            'status'          => $status,
            'created_at'      => date('Y-m-d H:i:s'),
        ];

        $batchId = $this->batchRepo->create($batchData);

        // إذا تم تمرير رصيد أولي ومخزن (مثل استلام بضاعة أو رصيد افتتاحي)
        if (!empty($data['initial_quantity']) && (float)$data['initial_quantity'] > 0 && !empty($data['warehouse_id'])) {
            $warehouseId = (int)$data['warehouse_id'];
            $initialQty = (float)$data['initial_quantity'];

            $pdo = Database::pdo();
            $stmt = $pdo->prepare("
                INSERT INTO `warehouse_batches` (`warehouse_id`, `batch_id`, `product_id`, `quantity`, `reserved_quantity`, `updated_at`)
                VALUES (?, ?, ?, ?, 0.00, NOW())
                ON DUPLICATE KEY UPDATE `quantity` = `quantity` + VALUES(`quantity`), `updated_at` = NOW()
            ");
            $stmt->execute([$warehouseId, $batchId, $productId, $initialQty]);

            // تسجيل حركة المخزون الافتتاحية
            $txStmt = $pdo->prepare("
                INSERT INTO `stock_transactions` 
                (`transaction_type`, `reference_table`, `reference_id`, `warehouse_id`, `product_id`, `batch_id`, `qty_before`, `qty_change`, `qty_after`, `unit_cost`, `notes`, `created_by`, `created_at`)
                VALUES ('adjustment_in', 'batches', ?, ?, ?, ?, 0.00, ?, ?, ?, 'رصيد افتتاحي للتشغيلة', ?, NOW())
            ");
            $txStmt->execute([$batchId, $warehouseId, $productId, $batchId, $initialQty, $initialQty, $purchasePrice, $userId]);
        }

        $this->logAudit(
            $userId,
            'create',
            'batches',
            $batchId,
            null,
            ['batch_number' => $batchNumber, 'expiry_date' => $expiryDate, 'product' => $product['trade_name']]
        );

        return $this->getBatchById($batchId);
    }

    public function updateBatch($id, array $data, $userId = null)
    {
        $batch = $this->getBatchById($id);

        if (!empty($data['batch_number']) && $data['batch_number'] !== $batch['batch_number']) {
            $existing = $this->batchRepo->findByProductAndBatchNumber($batch['product_id'], trim($data['batch_number']));
            if ($existing && (int)$existing['id'] !== (int)$id) {
                throw new Exception("رقم التشغيلة [{$data['batch_number']}] مسجل مسبقاً لهذا الصنف.");
            }
        }

        $updateData = [];
        if (!empty($data['batch_number'])) $updateData['batch_number'] = trim($data['batch_number']);
        if (array_key_exists('production_date', $data)) $updateData['production_date'] = !empty($data['production_date']) ? date('Y-m-d', strtotime($data['production_date'])) : null;
        if (!empty($data['expiry_date'])) {
            $exp = date('Y-m-d', strtotime($data['expiry_date']));
            $updateData['expiry_date'] = $exp;
            if ($exp <= date('Y-m-d')) {
                $updateData['status'] = 'expired';
            }
        }
        if (isset($data['purchase_price'])) $updateData['purchase_price'] = (float)$data['purchase_price'];
        if (isset($data['selling_price'])) $updateData['selling_price'] = (float)$data['selling_price'];
        if (!empty($data['status'])) $updateData['status'] = $data['status'];

        if (!empty($updateData)) {
            $this->batchRepo->update($id, $updateData);
            $this->logAudit(
                $userId,
                'update',
                'batches',
                $id,
                ['batch_number' => $batch['batch_number'], 'expiry_date' => $batch['expiry_date']],
                $updateData
            );
        }

        return $this->getBatchById($id);
    }

    /**
     * الحصول على إعداد سياسة الصرف الحالية (FEFO أو FIFO)
     */
    public function getDispatchPolicy()
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT `setting_value` FROM `system_settings` WHERE `setting_key` = 'dispatch_policy' LIMIT 1");
        $stmt->execute();
        $policy = $stmt->fetchColumn();
        return strtoupper($policy) === 'FIFO' ? 'FIFO' : 'FEFO';
    }

    /**
     * تحديث سياسة الصرف (FEFO / FIFO)
     */
    public function setDispatchPolicy($policy, $userId = null)
    {
        $policy = strtoupper($policy) === 'FIFO' ? 'FIFO' : 'FEFO';
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("UPDATE `system_settings` SET `setting_value` = ?, `updated_at` = NOW() WHERE `setting_key` = 'dispatch_policy'");
        $stmt->execute([$policy]);

        $this->logAudit($userId, 'update_setting', 'settings', null, null, ['dispatch_policy' => $policy]);
        return $policy;
    }

    /**
     * تقرير التشغيلات القريبة من الانتهاء
     */
    public function getNearExpiryAlerts($days = null, $warehouseId = null)
    {
        if ($days === null) {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare("SELECT `setting_value` FROM `system_settings` WHERE `setting_key` = 'near_expiry_days' LIMIT 1");
            $stmt->execute();
            $daysVal = $stmt->fetchColumn();
            $days = $daysVal ? (int)$daysVal : 180;
        }

        return $this->batchRepo->getNearExpiryBatches($days, $warehouseId);
    }

    /**
     * تقرير التشغيلات المنتهية الصلاحية
     */
    public function getExpiredAlerts($warehouseId = null)
    {
        return $this->batchRepo->getExpiredBatches($warehouseId);
    }

    /**
     * تحديث تلقائي لحالات التشغيلات المنتهية
     */
    public function autoMarkExpiredBatches()
    {
        return $this->batchRepo->updateExpiredBatchesStatus();
    }

    /**
     * محرك اقتراح التشغيلات للصرف في فواتير المبيعات وفق سياسة FEFO / FIFO
     *
     * @param int $productId
     * @param int $warehouseId
     * @param float $requestedQuantity
     * @return array
     */
    public function suggestBatchesForDispatch($productId, $warehouseId, $requestedQuantity)
    {
        $requestedQuantity = (float)$requestedQuantity;
        if ($requestedQuantity <= 0) {
            throw new Exception("الكمية المطلوبة يجب أن تكون أكبر من الصفر.");
        }

        // قراءة السياسة الحالية
        $policy = $this->getDispatchPolicy();

        // جلب التشغيلات الصالحة للبيع في المخزن مرتبة حسب السياسة
        $availableBatches = $this->batchRepo->getValidBatchesForSale($productId, $warehouseId, $policy);

        $allocations = [];
        $remainingNeeded = $requestedQuantity;
        $totalAllocated = 0.00;

        foreach ($availableBatches as $batch) {
            $availableQty = (float)$batch['available_quantity'];
            if ($availableQty <= 0) continue;

            $allocate = min($availableQty, $remainingNeeded);
            $allocations[] = [
                'batch_id'           => (int)$batch['batch_id'],
                'batch_number'       => $batch['batch_number'],
                'expiry_date'        => $batch['expiry_date'],
                'production_date'    => $batch['production_date'],
                'days_to_expiry'     => (int)$batch['days_to_expiry'],
                'available_quantity' => $availableQty,
                'allocated_quantity' => $allocate,
                'unit_price'         => (float)($batch['batch_selling_price'] > 0 ? $batch['batch_selling_price'] : $batch['default_selling_price']),
            ];

            $totalAllocated += $allocate;
            $remainingNeeded -= $allocate;

            if ($remainingNeeded <= 0) {
                break;
            }
        }

        return [
            'policy'             => $policy,
            'product_id'         => (int)$productId,
            'warehouse_id'       => (int)$warehouseId,
            'requested_quantity' => $requestedQuantity,
            'allocated_quantity' => $totalAllocated,
            'is_fully_allocated' => ($totalAllocated >= $requestedQuantity),
            'shortage_quantity'  => max(0.00, $requestedQuantity - $totalAllocated),
            'allocations'        => $allocations,
        ];
    }
}
