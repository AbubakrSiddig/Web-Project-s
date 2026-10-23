<?php
namespace App\Services;

use App\Repositories\Interfaces\StockRepositoryInterface;
use App\Repositories\Interfaces\WarehouseRepositoryInterface;
use App\Repositories\MySQL\MySQLStockRepository;
use App\Repositories\MySQL\MySQLWarehouseRepository;
use Core\Database;
use Core\Service;
use Exception;

/**
 * خدمة الحركات المخزنية والتحويلات والجرد (Stock & Inventory Service)
 */
class StockService extends Service
{
    /** @var StockRepositoryInterface */
    protected $stockRepo;

    /** @var WarehouseRepositoryInterface */
    protected $warehouseRepo;

    public function __construct(
        StockRepositoryInterface $stockRepo = null,
        WarehouseRepositoryInterface $warehouseRepo = null
    ) {
        $this->stockRepo = $stockRepo ?: new MySQLStockRepository();
        $this->warehouseRepo = $warehouseRepo ?: new MySQLWarehouseRepository();
    }

    /**
     * إنشاء إذن تحويل بين المخازن
     */
    public function createTransfer(array $data, $userId = null)
    {
        $fromWh = (int)$data['from_warehouse_id'];
        $toWh = (int)$data['to_warehouse_id'];

        if ($fromWh === $toWh) {
            throw new Exception("لا يمكن التحويل من المخزن إلى نفسه.");
        }

        $items = $data['items'] ?? [];
        if (empty($items) || !is_array($items)) {
            throw new Exception("يجب تحديد بند واحد على الأقل للتحويل.");
        }

        // التحقق المسبق من توفر الأرصدة في المخزن المحول منه
        foreach ($items as $item) {
            $stock = $this->warehouseRepo->getBatchStock($fromWh, $item['batch_id']);
            $currentQty = $stock ? (float)$stock['quantity'] : 0.00;
            if ($currentQty < (float)$item['quantity']) {
                throw new Exception("الرصيد غير كافٍ في المخزن المصدر للتشغيلة [ID: {$item['batch_id']}]. المتاح: {$currentQty}، المطلوب: {$item['quantity']}");
            }
        }

        $transferNumber = !empty($data['transfer_number']) ? trim($data['transfer_number']) : 'TRF-' . date('Ymd-His');
        $transferData = [
            'transfer_number'   => $transferNumber,
            'from_warehouse_id' => $fromWh,
            'to_warehouse_id'   => $toWh,
            'transfer_date'     => !empty($data['transfer_date']) ? date('Y-m-d', strtotime($data['transfer_date'])) : date('Y-m-d'),
            'status'            => 'pending',
            'notes'             => $data['notes'] ?? null,
            'created_by'        => $userId,
        ];

        $transferId = $this->stockRepo->createTransfer($transferData, $items);

        $this->logAudit($userId, 'create_transfer', 'inventory', $transferId, null, [
            'transfer_number' => $transferNumber,
            'from'            => $fromWh,
            'to'              => $toWh,
            'items_count'     => count($items)
        ]);

        return $this->stockRepo->getTransferDetails($transferId);
    }

    /**
     * اعتماد وإتمام التحويل المخزني (خصم من المصدر وإضافة للوجهة وتسجيل الحركات)
     */
    public function approveTransfer($transferId, $userId = null)
    {
        $transfer = $this->stockRepo->getTransferDetails($transferId);
        if (!$transfer) {
            throw new Exception("إذن التحويل غير موجود.");
        }

        if ($transfer['status'] !== 'pending') {
            throw new Exception("إذن التحويل معتمد أو ملغى مسبقاً (الحالة: {$transfer['status']}).");
        }

        return Database::transaction(function () use ($transfer, $userId) {
            $fromWh = $transfer['from_warehouse_id'];
            $toWh = $transfer['to_warehouse_id'];

            foreach ($transfer['items'] as $item) {
                $qty = (float)$item['quantity'];
                $batchId = (int)$item['batch_id'];
                $productId = (int)$item['product_id'];

                // 1. الخصم من المخزن المصدر
                $adjOut = $this->warehouseRepo->adjustBatchQuantity($fromWh, $batchId, $productId, -$qty);

                // 2. تسجيل حركة تحويل صادر (transfer_out)
                $this->stockRepo->recordTransaction([
                    'transaction_type' => 'transfer_out',
                    'reference_table'  => 'stock_transfers',
                    'reference_id'     => $transfer['id'],
                    'warehouse_id'     => $fromWh,
                    'product_id'       => $productId,
                    'batch_id'         => $batchId,
                    'qty_before'       => $adjOut['qty_before'],
                    'qty_change'       => -$qty,
                    'qty_after'        => $adjOut['qty_after'],
                    'unit_cost'        => 0.0000,
                    'notes'            => "تحويل صادر إلى [{$transfer['to_warehouse_name']}] بموجب إذن [{$transfer['transfer_number']}]",
                    'created_by'       => $userId,
                ]);

                // 3. الإضافة إلى المخزن الهدف
                $adjIn = $this->warehouseRepo->adjustBatchQuantity($toWh, $batchId, $productId, $qty);

                // 4. تسجيل حركة تحويل وارد (transfer_in)
                $this->stockRepo->recordTransaction([
                    'transaction_type' => 'transfer_in',
                    'reference_table'  => 'stock_transfers',
                    'reference_id'     => $transfer['id'],
                    'warehouse_id'     => $toWh,
                    'product_id'       => $productId,
                    'batch_id'         => $batchId,
                    'qty_before'       => $adjIn['qty_before'],
                    'qty_change'       => $qty,
                    'qty_after'        => $adjIn['qty_after'],
                    'unit_cost'        => 0.0000,
                    'notes'            => "تحويل وارد من [{$transfer['from_warehouse_name']}] بموجب إذن [{$transfer['transfer_number']}]",
                    'created_by'       => $userId,
                ]);
            }

            // تحديث حالة إذن التحويل إلى 'completed'
            $this->stockRepo->updateTransferStatus($transfer['id'], 'completed', $userId);

            $this->logAudit($userId, 'approve_transfer', 'inventory', $transfer['id'], ['status' => 'pending'], ['status' => 'completed']);

            return $this->stockRepo->getTransferDetails($transfer['id']);
        });
    }

    /**
     * إنشاء وثيقة جرد وتسوية مخزنية
     */
    public function createAdjustment(array $data, $userId = null)
    {
        $warehouseId = (int)$data['warehouse_id'];
        $items = $data['items'] ?? [];
        if (empty($items) || !is_array($items)) {
            throw new Exception("يجب تحديد بند واحد على الأقل في الجرد والتسوية.");
        }

        $formattedItems = [];
        foreach ($items as $item) {
            $currentStock = $this->warehouseRepo->getBatchStock($warehouseId, $item['batch_id']);
            $systemQty = $currentStock ? (float)$currentStock['quantity'] : 0.00;
            $actualQty = (float)($item['actual_qty'] ?? $item['physical_quantity'] ?? 0);
            $diff = $actualQty - $systemQty;

            $formattedItems[] = [
                'product_id'     => (int)$item['product_id'],
                'batch_id'       => (int)$item['batch_id'],
                'system_qty'     => $systemQty,
                'actual_qty'     => $actualQty,
                'difference_qty' => $diff,
            ];
        }

        $adjNumber = !empty($data['adjustment_number']) ? trim($data['adjustment_number']) : 'ADJ-' . date('Ymd-His');
        $adjData = [
            'adjustment_number' => $adjNumber,
            'warehouse_id'      => $warehouseId,
            'adjustment_date'   => !empty($data['adjustment_date']) ? date('Y-m-d', strtotime($data['adjustment_date'])) : date('Y-m-d'),
            'reason'            => $data['reason'] ?? 'جرد دوري وتسوية فروقات',
            'status'            => 'draft',
            'notes'             => $data['notes'] ?? null,
            'created_by'        => $userId,
        ];

        $adjId = $this->stockRepo->createAdjustment($adjData, $formattedItems);

        $this->logAudit($userId, 'create_adjustment', 'inventory', $adjId, null, [
            'adjustment_number' => $adjNumber,
            'warehouse_id'      => $warehouseId,
            'items_count'       => count($formattedItems)
        ]);

        return $this->stockRepo->getAdjustmentDetails($adjId);
    }

    /**
     * اعتماد التسوية المخزنية وتعديل الأرصدة
     */
    public function approveAdjustment($adjustmentId, $userId = null)
    {
        $adj = $this->stockRepo->getAdjustmentDetails($adjustmentId);
        if (!$adj) {
            throw new Exception("وثيقة التسوية غير موجودة.");
        }

        if ($adj['status'] !== 'draft') {
            throw new Exception("وثيقة التسوية معتمدة مسبقاً (الحالة: {$adj['status']}).");
        }

        return Database::transaction(function () use ($adj, $userId) {
            $warehouseId = $adj['warehouse_id'];

            foreach ($adj['items'] as $item) {
                $diff = (float)$item['difference_qty'];
                if ($diff == 0) continue; // لا يوجد فارق لتعديله

                $batchId = (int)$item['batch_id'];
                $productId = (int)$item['product_id'];

                // تعديل رصيد التشغيلة
                $res = $this->warehouseRepo->adjustBatchQuantity($warehouseId, $batchId, $productId, $diff);

                // تسجيل الحركة
                $txType = $diff > 0 ? 'adjustment_in' : 'adjustment_out';
                $this->stockRepo->recordTransaction([
                    'transaction_type' => $txType,
                    'reference_table'  => 'stock_adjustments',
                    'reference_id'     => $adj['id'],
                    'warehouse_id'     => $warehouseId,
                    'product_id'       => $productId,
                    'batch_id'         => $batchId,
                    'qty_before'       => $res['qty_before'],
                    'qty_change'       => $diff,
                    'qty_after'        => $res['qty_after'],
                    'unit_cost'        => 0.0000,
                    'notes'            => "تسوية جرد بموجب وثيقة [{$adj['adjustment_number']}] - السبب: [{$adj['reason']}]",
                    'created_by'       => $userId,
                ]);
            }

            $this->stockRepo->updateAdjustmentStatus($adj['id'], 'approved', $userId);
            $this->logAudit($userId, 'approve_adjustment', 'inventory', $adj['id'], ['status' => 'draft'], ['status' => 'approved']);

            return $this->stockRepo->getAdjustmentDetails($adj['id']);
        });
    }

    public function getTransfersList(array $filters = [], $page = 1, $perPage = 25)
    {
        return $this->stockRepo->getTransfers($filters, $page, $perPage);
    }

    public function getAdjustmentsList(array $filters = [], $page = 1, $perPage = 25)
    {
        return $this->stockRepo->getAdjustments($filters, $page, $perPage);
    }

    public function getStockTransactions(array $filters = [], $page = 1, $perPage = 50)
    {
        return $this->stockRepo->getTransactionsHistory($filters, $page, $perPage);
    }
}
