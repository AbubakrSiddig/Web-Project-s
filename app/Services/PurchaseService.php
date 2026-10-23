<?php
namespace App\Services;

use App\Repositories\Interfaces\BatchRepositoryInterface;
use App\Repositories\Interfaces\PurchaseRepositoryInterface;
use App\Repositories\Interfaces\StockRepositoryInterface;
use App\Repositories\Interfaces\SupplierRepositoryInterface;
use App\Repositories\Interfaces\WarehouseRepositoryInterface;
use App\Repositories\MySQL\MySQLBatchRepository;
use App\Repositories\MySQL\MySQLPurchaseRepository;
use App\Repositories\MySQL\MySQLStockRepository;
use App\Repositories\MySQL\MySQLSupplierRepository;
use App\Repositories\MySQL\MySQLWarehouseRepository;
use Core\Database;
use Core\Service;
use Exception;

/**
 * خدمة فواتير المشتريات والتوريدات (Purchase Service)
 */
class PurchaseService extends Service
{
    /** @var PurchaseRepositoryInterface */
    protected $purchaseRepo;

    /** @var SupplierRepositoryInterface */
    protected $supplierRepo;

    /** @var BatchRepositoryInterface */
    protected $batchRepo;

    /** @var WarehouseRepositoryInterface */
    protected $warehouseRepo;

    /** @var StockRepositoryInterface */
    protected $stockRepo;

    public function __construct(
        PurchaseRepositoryInterface $purchaseRepo = null,
        SupplierRepositoryInterface $supplierRepo = null,
        BatchRepositoryInterface $batchRepo = null,
        WarehouseRepositoryInterface $warehouseRepo = null,
        StockRepositoryInterface $stockRepo = null
    ) {
        $this->purchaseRepo = $purchaseRepo ?: new MySQLPurchaseRepository();
        $this->supplierRepo = $supplierRepo ?: new MySQLSupplierRepository();
        $this->batchRepo = $batchRepo ?: new MySQLBatchRepository();
        $this->warehouseRepo = $warehouseRepo ?: new MySQLWarehouseRepository();
        $this->stockRepo = $stockRepo ?: new MySQLStockRepository();
    }

    public function getInvoices(array $filters = [], $page = 1, $perPage = 25)
    {
        return $this->purchaseRepo->getInvoices($filters, $page, $perPage);
    }

    public function getInvoiceById($id)
    {
        $inv = $this->purchaseRepo->getInvoiceDetails($id);
        if (!$inv) {
            throw new Exception("فاتورة الشراء غير موجودة.");
        }
        return $inv;
    }

    public function createInvoice(array $data, $userId = null)
    {
        $supplierId = (int)$data['supplier_id'];
        $warehouseId = (int)$data['warehouse_id'];
        $items = $data['items'] ?? [];

        if (empty($items) || !is_array($items)) {
            throw new Exception("يجب إضافة صنف واحد على الأقل في فاتورة الشراء.");
        }

        $subtotal = 0.00;
        $preparedItems = [];

        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $batchNumber = trim($item['batch_number']);
            $expiryDate = date('Y-m-d', strtotime($item['expiry_date']));
            $productionDate = !empty($item['production_date']) ? date('Y-m-d', strtotime($item['production_date'])) : null;
            $qty = (float)$item['quantity'];
            $price = (float)$item['unit_price'];

            if ($qty <= 0 || $price < 0) {
                throw new Exception("الكمية وسعر الشراء يجب أن تكون قيماً صحيحة أكبر من الصفر.");
            }

            $total = $qty * $price;
            $subtotal += $total;

            // ضمان وجود التشغيلة في جدول التشغيلات لتحقيق التكامل المرجعي للـ Foreign Key
            $existingBatch = $this->batchRepo->findByProductAndBatchNumber($productId, $batchNumber);
            if (!$existingBatch) {
                $batchId = $this->batchRepo->create([
                    'product_id'      => $productId,
                    'batch_number'    => $batchNumber,
                    'production_date' => $productionDate,
                    'expiry_date'     => $expiryDate,
                    'purchase_price'  => $price,
                    'selling_price'   => 0.00,
                    'status'          => (strtotime($expiryDate) <= time()) ? 'expired' : 'active',
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);
            } else {
                $batchId = $existingBatch['id'];
            }

            $preparedItems[] = [
                'product_id'      => $productId,
                'batch_id'        => $batchId,
                'batch_number'    => $batchNumber,
                'production_date' => $productionDate,
                'expiry_date'     => $expiryDate,
                'quantity'        => $qty,
                'unit_price'      => $price,
                'total_price'     => $total,
            ];
        }

        $discount = isset($data['discount_amount']) ? (float)$data['discount_amount'] : 0.00;
        $tax = isset($data['tax_amount']) ? (float)$data['tax_amount'] : 0.00;
        $totalAmount = max(0.00, $subtotal - $discount + $tax);
        $paidAmount = isset($data['paid_amount']) ? (float)$data['paid_amount'] : 0.00;
        $remainingAmount = max(0.00, $totalAmount - $paidAmount);

        $invoiceNumber = !empty($data['invoice_number']) ? trim($data['invoice_number']) : 'PINV-' . date('Ymd-His');

        $invoiceData = [
            'invoice_number'     => $invoiceNumber,
            'supplier_invoice_no'=> $data['supplier_invoice_no'] ?? null,
            'supplier_id'        => $supplierId,
            'warehouse_id'       => $warehouseId,
            'invoice_date'       => !empty($data['invoice_date']) ? date('Y-m-d', strtotime($data['invoice_date'])) : date('Y-m-d'),
            'payment_type'       => $data['payment_type'] ?? 'credit',
            'subtotal'           => $subtotal,
            'discount_amount'    => $discount,
            'tax_amount'         => $tax,
            'total_amount'       => $totalAmount,
            'paid_amount'        => $paidAmount,
            'remaining_amount'   => $remainingAmount,
            'status'             => 'draft',
            'notes'              => $data['notes'] ?? null,
            'created_by'         => $userId,
        ];

        $invoiceId = $this->purchaseRepo->createInvoice($invoiceData, $preparedItems);

        // إذا طُلب اعتماد واستلام الفاتورة فوراً (Auto-Receive / Confirmed)
        if ((!empty($data['auto_receive']) && $data['auto_receive'] == 1) || (!empty($data['status']) && in_array($data['status'], ['confirmed', 'received']))) {
            return $this->receiveInvoice($invoiceId, $userId);
        }

        $this->logAudit($userId, 'create', 'purchases', $invoiceId, null, [
            'invoice_number' => $invoiceNumber,
            'total_amount'   => $totalAmount,
            'supplier_id'    => $supplierId
        ]);

        return $this->getInvoiceById($invoiceId);
    }

    /**
     * اعتماد واستلام فاتورة الشراء وتحديث المخزون والتشغيلات وحساب المورد
     */
    public function receiveInvoice($invoiceId, $userId = null)
    {
        $invoice = $this->getInvoiceById($invoiceId);
        if ($invoice['status'] === 'received') {
            throw new Exception("فاتورة الشراء مستلمة ومعتمدة مسبقاً.");
        }

        return Database::transaction(function () use ($invoice, $userId) {
            $warehouseId = $invoice['warehouse_id'];
            $supplierId = $invoice['supplier_id'];

            foreach ($invoice['items'] as $item) {
                $productId = $item['product_id'];
                $batchNumber = $item['batch_number'];
                $qty = (float)$item['quantity'];
                $unitPrice = (float)$item['unit_price'];

                // 1. فحص هل التشغيلة مسجلة مسبقاً أو إنشاؤها
                $batch = $this->batchRepo->findByProductAndBatchNumber($productId, $batchNumber);
                if (!$batch) {
                    $batchId = $this->batchRepo->create([
                        'product_id'      => $productId,
                        'batch_number'    => $batchNumber,
                        'production_date' => $item['production_date'],
                        'expiry_date'     => $item['expiry_date'],
                        'purchase_price'  => $unitPrice,
                        'selling_price'   => 0.00, // سيعتمد السعر الافتراضي للصنف
                        'status'          => (strtotime($item['expiry_date']) <= time()) ? 'expired' : 'active',
                        'created_at'      => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    $batchId = $batch['id'];
                }

                // تحديث الـ batch_id في بند الفاتورة
                $updateItemStmt = Database::pdo()->prepare("UPDATE `purchase_invoice_items` SET `batch_id` = ? WHERE `id` = ?");
                $updateItemStmt->execute([$batchId, $item['id']]);

                // 2. زيادة رصيد المخزن للتشغيلة
                $adj = $this->warehouseRepo->adjustBatchQuantity($warehouseId, $batchId, $productId, $qty);

                // 3. تسجيل حركة المخزون الواردة (purchase)
                $this->stockRepo->recordTransaction([
                    'transaction_type' => 'purchase',
                    'reference_table'  => 'purchase_invoices',
                    'reference_id'     => $invoice['id'],
                    'warehouse_id'     => $warehouseId,
                    'product_id'       => $productId,
                    'batch_id'         => $batchId,
                    'qty_before'       => $adj['qty_before'],
                    'qty_change'       => $qty,
                    'qty_after'        => $adj['qty_after'],
                    'unit_cost'        => $unitPrice,
                    'notes'            => "وارد مشتريات فاتورة [{$invoice['invoice_number']}] من المورد [{$invoice['supplier_name']}]",
                    'created_by'       => $userId,
                ]);
            }

            // 4. تحديث رصيد المورد (مستحقات دائنة للمورد)
            // إذا كانت الفاتورة آجلة أو مدفوعة جزئياً، يزيد رصيد المورد بالمبلغ المتبقي
            $creditAmount = (float)$invoice['remaining_amount'];
            if ($creditAmount > 0) {
                $newSupplierBalance = $this->supplierRepo->updateBalance($supplierId, $creditAmount);
                $this->supplierRepo->recordLedgerTransaction([
                    'supplier_id'      => $supplierId,
                    'transaction_type' => 'invoice',
                    'reference_id'     => $invoice['id'],
                    'debit'            => 0.00,
                    'credit'           => $creditAmount,
                    'balance_after'    => $newSupplierBalance,
                    'transaction_date' => $invoice['invoice_date'],
                    'notes'            => "استحقاق فاتورة مشتريات آجلة رقم [{$invoice['invoice_number']}]",
                ]);
            }

            // 5. تحديث حالة الفاتورة
            $this->purchaseRepo->updateInvoiceStatus($invoice['id'], 'received');

            $this->logAudit($userId, 'receive_purchase', 'purchases', $invoice['id'], ['status' => 'draft'], ['status' => 'received']);

            return $this->getInvoiceById($invoice['id']);
        });
    }
}
