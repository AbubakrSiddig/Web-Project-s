<?php
namespace App\Services;

use App\Repositories\Interfaces\ReturnRepositoryInterface;
use App\Repositories\Interfaces\StockRepositoryInterface;
use App\Repositories\Interfaces\SupplierRepositoryInterface;
use App\Repositories\Interfaces\WarehouseRepositoryInterface;
use App\Repositories\MySQL\MySQLReturnRepository;
use App\Repositories\MySQL\MySQLStockRepository;
use App\Repositories\MySQL\MySQLSupplierRepository;
use App\Repositories\MySQL\MySQLWarehouseRepository;
use Core\Database;
use Core\Service;
use Exception;

/**
 * خدمة مرتجعات المشتريات إلى الموردين (Purchase Return Service)
 */
class PurchaseReturnService extends Service
{
    /** @var ReturnRepositoryInterface */
    protected $returnRepo;

    /** @var SupplierRepositoryInterface */
    protected $supplierRepo;

    /** @var WarehouseRepositoryInterface */
    protected $warehouseRepo;

    /** @var StockRepositoryInterface */
    protected $stockRepo;

    public function __construct(
        ReturnRepositoryInterface $returnRepo = null,
        SupplierRepositoryInterface $supplierRepo = null,
        WarehouseRepositoryInterface $warehouseRepo = null,
        StockRepositoryInterface $stockRepo = null
    ) {
        $this->returnRepo = $returnRepo ?: new MySQLReturnRepository();
        $this->supplierRepo = $supplierRepo ?: new MySQLSupplierRepository();
        $this->warehouseRepo = $warehouseRepo ?: new MySQLWarehouseRepository();
        $this->stockRepo = $stockRepo ?: new MySQLStockRepository();
    }

    public function getReturns(array $filters = [], $page = 1, $perPage = 25)
    {
        return $this->returnRepo->getPurchaseReturns($filters, $page, $perPage);
    }

    public function getReturnById($id)
    {
        $return = $this->returnRepo->findPurchaseReturnById($id);
        if (!$return) {
            throw new Exception("مستند مرتجع المشتريات غير موجود.");
        }
        return $return;
    }

    public function createReturn(array $data, $userId)
    {
        $supplierId = (int)($data['supplier_id'] ?? 0);
        $warehouseId = (int)($data['warehouse_id'] ?? 0);
        $rawItems = $data['items'] ?? [];

        if ($supplierId <= 0) {
            throw new Exception("يرجى تحديد المورد.");
        }
        if ($warehouseId <= 0) {
            throw new Exception("يرجى تحديد المخزن المرتجع منه.");
        }
        if (empty($rawItems) || !is_array($rawItems)) {
            throw new Exception("يجب أن يحتوي المرتجع على صنف واحد على الأقل.");
        }

        $supplier = $this->supplierRepo->find($supplierId);
        if (!$supplier) {
            throw new Exception("المورد المحدد غير موجود.");
        }

        $warehouse = $this->warehouseRepo->find($warehouseId);
        if (!$warehouse) {
            throw new Exception("المخزن المحدد غير موجود.");
        }

        $refundStatus = in_array($data['refund_status'] ?? '', ['deduct_from_balance', 'cash_refund'])
            ? $data['refund_status']
            : 'deduct_from_balance';

        $totalAmount = 0.00;
        $items = [];

        foreach ($rawItems as $idx => $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $batchId = (int)($item['batch_id'] ?? 0);
            $qty = (float)($item['quantity'] ?? 0);
            $unitPrice = (float)($item['unit_price'] ?? 0);

            if ($productId <= 0 || $batchId <= 0 || $qty <= 0 || $unitPrice <= 0) {
                throw new Exception("البند رقم " . ($idx + 1) . " يحتوي على بيانات غير صالحة.");
            }

            // التأكد من توفر الرصيد في المخزن للإرجاع
            $batchStock = $this->warehouseRepo->getBatchStock($warehouseId, $batchId);
            $avail = $batchStock ? (float)$batchStock['quantity'] : 0.00;
            if ($avail < $qty) {
                throw new Exception("الرصيد المتوفر في المخزن للتشغيلة رقم [{$batchId}] هو ({$avail}) فقط، ولا يكفي لإرجاع ({$qty}).");
            }

            $lineTotal = round($qty * $unitPrice, 2);
            $totalAmount += $lineTotal;

            $items[] = [
                'product_id'    => $productId,
                'batch_id'      => $batchId,
                'quantity'      => $qty,
                'unit_price'    => $unitPrice,
                'total_price'   => $lineTotal,
                'return_reason' => !empty($item['return_reason']) ? trim($item['return_reason']) : null,
            ];
        }

        // التحقق من صحة فاتورة الشراء الأصلية وعدم تجاوز الكمية المشتراة
        if (!empty($data['purchase_invoice_id'])) {
            $purchaseInvoiceId = (int)$data['purchase_invoice_id'];
            $purchaseRepo = new \App\Repositories\MySQL\MySQLPurchaseRepository();
            $purchaseInvoice = $purchaseRepo->getInvoiceDetails($purchaseInvoiceId);
            if (!$purchaseInvoice) {
                throw new Exception("فاتورة الشراء الأصلية [ID: {$purchaseInvoiceId}] غير موجودة.");
            }

            $invoiceItemsMap = [];
            foreach ($purchaseInvoice['items'] as $invItem) {
                $k = $invItem['product_id'] . '_' . $invItem['batch_id'];
                $invoiceItemsMap[$k] = (float)$invItem['quantity'];
            }

            $alreadyReturnedStmt = Database::pdo()->prepare("
                SELECT pri.product_id, pri.batch_id, SUM(pri.quantity) as total_returned
                FROM purchase_return_items pri
                JOIN purchase_returns pr ON pr.id = pri.purchase_return_id
                WHERE pr.purchase_invoice_id = ?
                GROUP BY pri.product_id, pri.batch_id
            ");
            $alreadyReturnedStmt->execute([$purchaseInvoiceId]);
            $alreadyReturnedMap = [];
            while ($row = $alreadyReturnedStmt->fetch()) {
                $k = $row['product_id'] . '_' . $row['batch_id'];
                $alreadyReturnedMap[$k] = (float)$row['total_returned'];
            }

            foreach ($items as $item) {
                $k = $item['product_id'] . '_' . $item['batch_id'];
                if (!isset($invoiceItemsMap[$k])) {
                    throw new Exception("الصنف رقم [{$item['product_id']}] بالتشغيلة [{$item['batch_id']}] ليس جزءاً من فاتورة الشراء الأصلية [{$purchaseInvoice['invoice_number']}].");
                }

                $purchasedQty = $invoiceItemsMap[$k];
                $prevReturned = $alreadyReturnedMap[$k] ?? 0.00;
                $maxReturnable = $purchasedQty - $prevReturned;

                if ($item['quantity'] > $maxReturnable) {
                    throw new Exception("الكمية المطلوب إرجاعها ({$item['quantity']}) تتجاوز الكمية المتبقية القابلة للإرجاع للمورد ({$maxReturnable}) من فاتورة الشراء [{$purchaseInvoice['invoice_number']}].");
                }
            }
        }

        Database::beginTransaction();
        try {
            $returnNumber = $this->returnRepo->generatePurchaseReturnNumber();

            $returnData = [
                'return_number'       => $returnNumber,
                'purchase_invoice_id' => !empty($data['purchase_invoice_id']) ? (int)$data['purchase_invoice_id'] : null,
                'supplier_id'         => $supplierId,
                'warehouse_id'        => $warehouseId,
                'return_date'         => !empty($data['return_date']) ? $data['return_date'] : date('Y-m-d'),
                'total_amount'        => $totalAmount,
                'refund_status'       => $refundStatus,
                'notes'               => !empty($data['notes']) ? trim($data['notes']) : null,
                'created_by'          => $userId,
            ];

            $returnId = $this->returnRepo->createPurchaseReturn($returnData, $items);

            // خصم الكميات من المخزن
            foreach ($items as $item) {
                $adj = $this->warehouseRepo->adjustBatchQuantity($warehouseId, $item['batch_id'], $item['product_id'], -$item['quantity']);

                $this->stockRepo->recordTransaction([
                    'transaction_type' => 'return',
                    'reference_table'  => 'purchase_returns',
                    'reference_id'     => $returnId,
                    'warehouse_id'     => $warehouseId,
                    'product_id'       => $item['product_id'],
                    'batch_id'         => $item['batch_id'],
                    'qty_before'       => $adj['qty_before'],
                    'qty_change'       => -$item['quantity'],
                    'qty_after'        => $adj['qty_after'],
                    'unit_cost'        => $item['unit_price'],
                    'notes'            => "مرتجع مشتريات إلى المورد [{$supplier['name']}] برقم [{$returnNumber}]",
                    'created_by'       => $userId,
                ]);
            }

            // تسوية مستحقات المورد
            if ($refundStatus === 'deduct_from_balance' && $totalAmount > 0) {
                $newBal = $this->supplierRepo->updateBalance($supplierId, -$totalAmount);
                $this->supplierRepo->recordLedgerTransaction([
                    'supplier_id'      => $supplierId,
                    'transaction_type' => 'return',
                    'reference_id'     => $returnId,
                    'debit'            => $totalAmount,
                    'credit'           => 0.00,
                    'balance_after'    => $newBal,
                    'transaction_date' => $returnData['return_date'],
                    'notes'            => "مرتجع مشتريات برقم [{$returnNumber}]",
                ]);
            }

            $this->logAudit($userId, 'create', 'purchase_returns', $returnId, null, [
                'return_number' => $returnNumber,
                'supplier'      => $supplier['name'],
                'total_amount'  => $totalAmount,
            ]);

            Database::commit();

            return $this->getReturnById($returnId);
        } catch (Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }
}
