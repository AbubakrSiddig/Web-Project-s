<?php
namespace App\Services;

use App\Repositories\Interfaces\CustomerRepositoryInterface;
use App\Repositories\Interfaces\ReturnRepositoryInterface;
use App\Repositories\Interfaces\StockRepositoryInterface;
use App\Repositories\Interfaces\WarehouseRepositoryInterface;
use App\Repositories\MySQL\MySQLCustomerRepository;
use App\Repositories\MySQL\MySQLReturnRepository;
use App\Repositories\MySQL\MySQLStockRepository;
use App\Repositories\MySQL\MySQLWarehouseRepository;
use Core\Database;
use Core\Service;
use Exception;

/**
 * خدمة مرتجعات المبيعات (Sales Return Service)
 */
class SalesReturnService extends Service
{
    /** @var ReturnRepositoryInterface */
    protected $returnRepo;

    /** @var CustomerRepositoryInterface */
    protected $customerRepo;

    /** @var WarehouseRepositoryInterface */
    protected $warehouseRepo;

    /** @var StockRepositoryInterface */
    protected $stockRepo;

    public function __construct(
        ReturnRepositoryInterface $returnRepo = null,
        CustomerRepositoryInterface $customerRepo = null,
        WarehouseRepositoryInterface $warehouseRepo = null,
        StockRepositoryInterface $stockRepo = null
    ) {
        $this->returnRepo = $returnRepo ?: new MySQLReturnRepository();
        $this->customerRepo = $customerRepo ?: new MySQLCustomerRepository();
        $this->warehouseRepo = $warehouseRepo ?: new MySQLWarehouseRepository();
        $this->stockRepo = $stockRepo ?: new MySQLStockRepository();
    }

    public function getReturns(array $filters = [], $page = 1, $perPage = 25)
    {
        return $this->returnRepo->getSalesReturns($filters, $page, $perPage);
    }

    public function getReturnById($id)
    {
        $return = $this->returnRepo->findSalesReturnById($id);
        if (!$return) {
            throw new Exception("مستند مرتجع المبيعات غير موجود.");
        }
        return $return;
    }

    public function createReturn(array $data, $userId)
    {
        $customerId = (int)($data['customer_id'] ?? 0);
        $warehouseId = (int)($data['warehouse_id'] ?? 0);
        $rawItems = $data['items'] ?? [];

        if ($customerId <= 0) {
            throw new Exception("يرجى تحديد العميل أو الصيدلية.");
        }
        if ($warehouseId <= 0) {
            throw new Exception("يرجى تحديد المخزن المستلم للبضاعة المرتجعة.");
        }
        if (empty($rawItems) || !is_array($rawItems)) {
            throw new Exception("يجب أن يحتوي المرتجع على صنف دوائي واحد على الأقل.");
        }

        $customer = $this->customerRepo->find($customerId);
        if (!$customer) {
            throw new Exception("العميل المحدد غير موجود.");
        }

        $warehouse = $this->warehouseRepo->find($warehouseId);
        if (!$warehouse) {
            throw new Exception("المخزن المحدد غير موجود.");
        }

        $returnToStockStatus = in_array($data['return_to_stock_status'] ?? '', ['valid_stock', 'quarantine_damaged'])
            ? $data['return_to_stock_status']
            : 'valid_stock';

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

        // التحقق من صحة الفاتورة الأصلية وعدم تجاوز الكمية المباعة
        if (!empty($data['sales_invoice_id'])) {
            $invoiceId = (int)$data['sales_invoice_id'];
            $salesRepo = new \App\Repositories\MySQL\MySQLSalesRepository();
            $invoice = $salesRepo->findInvoiceById($invoiceId);
            if (!$invoice) {
                throw new Exception("فاتورة المبيعات الأصلية [ID: {$invoiceId}] غير موجودة.");
            }

            $invoiceItemsMap = [];
            foreach ($invoice['items'] as $invItem) {
                $k = $invItem['product_id'] . '_' . $invItem['batch_id'];
                $invoiceItemsMap[$k] = (float)$invItem['quantity'];
            }

            $alreadyReturnedStmt = Database::pdo()->prepare("
                SELECT sri.product_id, sri.batch_id, SUM(sri.quantity) as total_returned
                FROM sales_return_items sri
                JOIN sales_returns sr ON sr.id = sri.sales_return_id
                WHERE sr.sales_invoice_id = ?
                GROUP BY sri.product_id, sri.batch_id
            ");
            $alreadyReturnedStmt->execute([$invoiceId]);
            $alreadyReturnedMap = [];
            while ($row = $alreadyReturnedStmt->fetch()) {
                $k = $row['product_id'] . '_' . $row['batch_id'];
                $alreadyReturnedMap[$k] = (float)$row['total_returned'];
            }

            foreach ($items as $item) {
                $k = $item['product_id'] . '_' . $item['batch_id'];
                if (!isset($invoiceItemsMap[$k])) {
                    throw new Exception("الصنف رقم [{$item['product_id']}] بالتشغيلة [{$item['batch_id']}] لم يتم صرفه ضمن الفاتورة الأصلية رقم [{$invoice['invoice_number']}].");
                }

                $soldQty = $invoiceItemsMap[$k];
                $prevReturned = $alreadyReturnedMap[$k] ?? 0.00;
                $maxReturnable = $soldQty - $prevReturned;

                if ($item['quantity'] > $maxReturnable) {
                    throw new Exception("الكمية المطلوب إرجاعها ({$item['quantity']}) تتجاوز الكمية المتبقية القابلة للإرجاع ({$maxReturnable}) من الفاتورة [{$invoice['invoice_number']}].");
                }
            }
        }

        Database::beginTransaction();
        try {
            $returnNumber = $this->returnRepo->generateSalesReturnNumber();

            $returnData = [
                'return_number'          => $returnNumber,
                'sales_invoice_id'       => !empty($data['sales_invoice_id']) ? (int)$data['sales_invoice_id'] : null,
                'customer_id'            => $customerId,
                'warehouse_id'           => $warehouseId,
                'return_date'            => !empty($data['return_date']) ? $data['return_date'] : date('Y-m-d'),
                'total_amount'           => $totalAmount,
                'refund_status'          => $refundStatus,
                'return_to_stock_status' => $returnToStockStatus,
                'notes'                  => !empty($data['notes']) ? trim($data['notes']) : null,
                'created_by'             => $userId,
            ];

            $returnId = $this->returnRepo->createSalesReturn($returnData, $items);

            // استرجاع البضاعة للمخزن
            // إذا كانت بضاعة تالفة / حجر صحي، إذا كان المخزن المستلم ليس حجر صحي، يمكن التوجيه لمخزن الحجر الصحي (WH-03)
            $targetWarehouseId = $warehouseId;
            if ($returnToStockStatus === 'quarantine_damaged') {
                // فحص إذا وجد مخزن حجر صحي خاص
                $qWarehouse = Database::pdo()->query("SELECT `id` FROM `warehouses` WHERE `warehouse_type` = 'quarantine' AND `is_active` = 1 LIMIT 1")->fetch();
                if ($qWarehouse) {
                    $targetWarehouseId = (int)$qWarehouse['id'];
                }
            }

            foreach ($items as $item) {
                $adj = $this->warehouseRepo->adjustBatchQuantity($targetWarehouseId, $item['batch_id'], $item['product_id'], $item['quantity']);

                $this->stockRepo->recordTransaction([
                    'transaction_type' => 'return',
                    'reference_table'  => 'sales_returns',
                    'reference_id'     => $returnId,
                    'warehouse_id'     => $targetWarehouseId,
                    'product_id'       => $item['product_id'],
                    'batch_id'         => $item['batch_id'],
                    'qty_before'       => $adj['qty_before'],
                    'qty_change'       => $item['quantity'],
                    'qty_after'        => $adj['qty_after'],
                    'unit_cost'        => $item['unit_price'],
                    'notes'            => "مرتجع مبيعات [{$returnNumber}] - حالة البضاعة: " . ($returnToStockStatus === 'valid_stock' ? 'صالحة للصرف' : 'تالفة / حجر صحي'),
                    'created_by'       => $userId,
                ]);
            }

            // تسوية رصيد العميل إن كان الخصم من الحساب
            if ($refundStatus === 'deduct_from_balance' && $totalAmount > 0) {
                $newBal = $this->customerRepo->updateBalance($customerId, -$totalAmount);
                $this->customerRepo->recordLedgerTransaction([
                    'customer_id'      => $customerId,
                    'transaction_type' => 'return',
                    'reference_id'     => $returnId,
                    'debit'            => 0.00,
                    'credit'           => $totalAmount,
                    'balance_after'    => $newBal,
                    'transaction_date' => $returnData['return_date'],
                    'notes'            => "مرتجع مبيعات برقم [{$returnNumber}]",
                ]);
            }

            $this->logAudit($userId, 'create', 'sales_returns', $returnId, null, [
                'return_number' => $returnNumber,
                'customer'      => $customer['name'],
                'total_amount'  => $totalAmount,
                'status'        => $returnToStockStatus,
            ]);

            Database::commit();

            return $this->getReturnById($returnId);
        } catch (Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }
}
