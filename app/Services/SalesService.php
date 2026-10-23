<?php
namespace App\Services;

use App\Repositories\Interfaces\BatchRepositoryInterface;
use App\Repositories\Interfaces\CustomerRepositoryInterface;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Repositories\Interfaces\SalesRepositoryInterface;
use App\Repositories\Interfaces\StockRepositoryInterface;
use App\Repositories\Interfaces\WarehouseRepositoryInterface;
use App\Repositories\MySQL\MySQLBatchRepository;
use App\Repositories\MySQL\MySQLCustomerRepository;
use App\Repositories\MySQL\MySQLProductRepository;
use App\Repositories\MySQL\MySQLSalesRepository;
use App\Repositories\MySQL\MySQLStockRepository;
use App\Repositories\MySQL\MySQLWarehouseRepository;
use Core\Database;
use Core\Service;
use Exception;

/**
 * خدمة فواتير المبيعات ونقاط البيع الدوائية (Sales Service)
 */
class SalesService extends Service
{
    /** @var SalesRepositoryInterface */
    protected $salesRepo;

    /** @var CustomerRepositoryInterface */
    protected $customerRepo;

    /** @var ProductRepositoryInterface */
    protected $productRepo;

    /** @var BatchRepositoryInterface */
    protected $batchRepo;

    /** @var WarehouseRepositoryInterface */
    protected $warehouseRepo;

    /** @var StockRepositoryInterface */
    protected $stockRepo;

    /** @var BatchService */
    protected $batchService;

    public function __construct(
        SalesRepositoryInterface $salesRepo = null,
        CustomerRepositoryInterface $customerRepo = null,
        ProductRepositoryInterface $productRepo = null,
        BatchRepositoryInterface $batchRepo = null,
        WarehouseRepositoryInterface $warehouseRepo = null,
        StockRepositoryInterface $stockRepo = null,
        BatchService $batchService = null
    ) {
        $this->salesRepo = $salesRepo ?: new MySQLSalesRepository();
        $this->customerRepo = $customerRepo ?: new MySQLCustomerRepository();
        $this->productRepo = $productRepo ?: new MySQLProductRepository();
        $this->batchRepo = $batchRepo ?: new MySQLBatchRepository();
        $this->warehouseRepo = $warehouseRepo ?: new MySQLWarehouseRepository();
        $this->stockRepo = $stockRepo ?: new MySQLStockRepository();
        $this->batchService = $batchService ?: new BatchService();
    }

    public function getInvoices(array $filters = [], $page = 1, $perPage = 25)
    {
        return $this->salesRepo->getInvoices($filters, $page, $perPage);
    }

    public function getInvoiceById($id)
    {
        $invoice = $this->salesRepo->findInvoiceById($id);
        if (!$invoice) {
            throw new Exception("فاتورة المبيعات غير موجودة.");
        }
        return $invoice;
    }

    public function getInvoiceByNumber($invoiceNumber)
    {
        $invoice = $this->salesRepo->findInvoiceByNumber($invoiceNumber);
        if (!$invoice) {
            throw new Exception("فاتورة المبيعات برقم [{$invoiceNumber}] غير موجودة.");
        }
        return $invoice;
    }

    /**
     * إنشاء وتأكيد فاتورة مبيعات جديدة مع فحص الصلاحية وسياسة FEFO/FIFO والحد الائتماني
     */
    public function createInvoice(array $data, $userId, $hasCreditOverride = false)
    {
        $customerId = (int)($data['customer_id'] ?? 0);
        $warehouseId = (int)($data['warehouse_id'] ?? 0);
        $rawItems = $data['items'] ?? [];

        if ($customerId <= 0) {
            throw new Exception("يرجى تحديد العميل أو الصيدلية.");
        }
        if ($warehouseId <= 0) {
            throw new Exception("يرجى تحديد المخزن المنصرف منه.");
        }
        if (empty($rawItems) || !is_array($rawItems)) {
            throw new Exception("يجب أن تحتوي فاتورة المبيعات على صنف واحد على الأقل.");
        }

        $customer = $this->customerRepo->find($customerId);
        if (!$customer) {
            throw new Exception("بيانات العميل / الصيدلية غير موجودة.");
        }
        if (empty($customer['is_active'])) {
            throw new Exception("حساب العميل موقوف حالياً ولا يمكن إصدار فواتير له.");
        }

        $warehouse = $this->warehouseRepo->find($warehouseId);
        if (!$warehouse) {
            throw new Exception("المخزن المحدد غير موجود.");
        }

        $today = date('Y-m-d');
        $resolvedItems = [];
        $subtotal = 0.00;

        // 1. معالجة البنود وتحديد التشغيلات وفق سياسة FEFO أو التشغيلة المحددة
        foreach ($rawItems as $idx => $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $qty = (float)($item['quantity'] ?? 0);
            $itemDiscount = (float)($item['discount'] ?? 0);

            if ($productId <= 0 || $qty <= 0) {
                throw new Exception("البند رقم " . ($idx + 1) . " يحتوي على صنف أو كمية غير صالحة.");
            }

            $product = $this->productRepo->find($productId);
            if (!$product) {
                throw new Exception("الصنف رقم [{$productId}] غير مسجل في المنظومة.");
            }
            if (empty($product['is_active'])) {
                throw new Exception("الصنف [{$product['trade_name']}] موقوف حالياً ولا يمكن بيعه.");
            }

            // فحص سعر الوحدة وسعر الحد الأدنى للبيع
            $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : (float)$product['selling_price'];
            if ($unitPrice <= 0) {
                throw new Exception("سعر بيع الصنف [{$product['trade_name']}] غير صالح.");
            }
            if ($product['min_selling_price'] > 0 && $unitPrice < (float)$product['min_selling_price'] && !$hasCreditOverride) {
                throw new Exception("سعر بيع الصنف [{$product['trade_name']}] ({$unitPrice}) أقل من الحد الأدنى المسموح به (" . (float)$product['min_selling_price'] . ").");
            }

            // إذا حدد المستخدم تشغيلة معينة يدويًا
            if (!empty($item['batch_id'])) {
                $batchId = (int)$item['batch_id'];
                $batch = $this->batchRepo->find($batchId);
                if (!$batch || $batch['product_id'] != $productId) {
                    throw new Exception("التشغيلة المحددة غير مطابقة للصنف [{$product['trade_name']}].");
                }

                // قاعدة دوائية صارمة: منع صرف أي دواء منتهي الصلاحية نهائياً
                if (strtotime($batch['expiry_date']) <= strtotime($today)) {
                    throw new Exception("قانونياً لا يمكن بيع الصنف [{$product['trade_name']}] - التشغيلة [{$batch['batch_number']}] منتهية الصلاحية بتاريخ {$batch['expiry_date']}.");
                }

                // فحص الرصيد المتوفر للتشغيلة في هذا المخزن
                $batchStock = $this->warehouseRepo->getBatchStock($warehouseId, $batchId);
                $availableQty = $batchStock ? (float)$batchStock['quantity'] : 0.00;
                if ($availableQty < $qty) {
                    throw new Exception("الرصيد المتاح للتشغيلة [{$batch['batch_number']}] من صنف [{$product['trade_name']}] في المخزن هو ({$availableQty}) فقط، والمطلوب بيعه ({$qty}).");
                }

                $itemTotal = ($qty * $unitPrice) - $itemDiscount;
                $resolvedItems[] = [
                    'product_id'  => $productId,
                    'batch_id'    => $batchId,
                    'quantity'    => $qty,
                    'unit_price'  => $unitPrice,
                    'discount'    => $itemDiscount,
                    'total_price' => max(0, $itemTotal),
                ];
                $subtotal += max(0, $itemTotal);
            } else {
                // صرف تلقائي ذكي وفق FEFO / FIFO
                $suggestion = $this->batchService->suggestBatchesForDispatch($productId, $warehouseId, $qty);
                if (empty($suggestion['is_fully_allocated'])) {
                    throw new Exception("الرصيد الصالح غير منتهي الصلاحية للصنف [{$product['trade_name']}] في المخزن هو (" . ($suggestion['allocated_quantity'] ?? 0) . ") فقط، ولا يكفي الكمية المطلوبة ({$qty}).");
                }

                foreach ($suggestion['allocations'] as $alloc) {
                    $allocQty = (float)($alloc['allocated_quantity'] ?? $alloc['allocate_quantity'] ?? 0);
                    $allocDiscount = round(($itemDiscount * ($allocQty / $qty)), 2);
                    $allocTotal = ($allocQty * $unitPrice) - $allocDiscount;

                    $resolvedItems[] = [
                        'product_id'  => $productId,
                        'batch_id'    => $alloc['batch_id'],
                        'quantity'    => $allocQty,
                        'unit_price'  => $unitPrice,
                        'discount'    => $allocDiscount,
                        'total_price' => max(0, $allocTotal),
                    ];
                    $subtotal += max(0, $allocTotal);
                }
            }
        }

        // 2. الحسابات الإجمالية للفاتورة
        $invoiceDiscount = isset($data['discount_amount']) ? (float)$data['discount_amount'] : 0.00;
        $taxAmount = isset($data['tax_amount']) ? (float)$data['tax_amount'] : 0.00;
        $totalAmount = max(0, $subtotal - $invoiceDiscount + $taxAmount);

        $paymentType = in_array($data['payment_type'] ?? '', ['cash', 'credit', 'bank', 'partial']) ? $data['payment_type'] : 'cash';

        if ($paymentType === 'cash' || $paymentType === 'bank') {
            $paidAmount = isset($data['paid_amount']) ? min($totalAmount, (float)$data['paid_amount']) : $totalAmount;
        } elseif ($paymentType === 'credit') {
            $paidAmount = 0.00;
        } else { // partial
            $paidAmount = isset($data['paid_amount']) ? min($totalAmount, max(0, (float)$data['paid_amount'])) : 0.00;
        }
        $remainingAmount = max(0, $totalAmount - $paidAmount);

        // 3. فحص الحد الائتماني للصيدلية
        if ($remainingAmount > 0) {
            $futureBalance = (float)$customer['balance'] + $remainingAmount;
            $creditLimit = (float)$customer['credit_limit'];
            if ($creditLimit > 0 && $futureBalance > $creditLimit && !$hasCreditOverride) {
                throw new Exception("تجاوزت الفاتورة الحد الائتماني المعتمد للصيدلية! الرصيد الحالي: ({$customer['balance']})، قيمة الآجل الجديدة: ({$remainingAmount})، المجموع: ({$futureBalance}) بينما الحد الائتماني: ({$creditLimit}).");
            }
        }

        // 4. تنفيذ العملية في حركة قاعدة بيانات آمنة (Atomic Transaction)
        Database::beginTransaction();
        try {
            $invoiceNumber = $this->salesRepo->generateInvoiceNumber();

            $invoiceData = [
                'invoice_number'  => $invoiceNumber,
                'customer_id'     => $customerId,
                'warehouse_id'    => $warehouseId,
                'invoice_date'    => !empty($data['invoice_date']) ? $data['invoice_date'] : $today,
                'payment_type'    => $paymentType,
                'subtotal'        => $subtotal,
                'discount_amount' => $invoiceDiscount,
                'tax_amount'      => $taxAmount,
                'total_amount'    => $totalAmount,
                'paid_amount'     => $paidAmount,
                'remaining_amount'=> $remainingAmount,
                'status'          => 'confirmed',
                'notes'           => !empty($data['notes']) ? trim($data['notes']) : null,
                'created_by'      => $userId,
            ];

            $invoiceId = $this->salesRepo->createInvoice($invoiceData, $resolvedItems);

            // خصم المخزون وتسجيل القيود التفصيلية
            foreach ($resolvedItems as $item) {
                $adj = $this->warehouseRepo->adjustBatchQuantity($warehouseId, $item['batch_id'], $item['product_id'], -$item['quantity']);

                $this->stockRepo->recordTransaction([
                    'transaction_type' => 'sale',
                    'reference_table'  => 'sales_invoices',
                    'reference_id'     => $invoiceId,
                    'warehouse_id'     => $warehouseId,
                    'product_id'       => $item['product_id'],
                    'batch_id'         => $item['batch_id'],
                    'qty_before'       => $adj['qty_before'],
                    'qty_change'       => -$item['quantity'],
                    'qty_after'        => $adj['qty_after'],
                    'unit_cost'        => $item['unit_price'],
                    'notes'            => "صرف مبيعات فاتورة [{$invoiceNumber}] للعميل [{$customer['name']}]",
                    'created_by'       => $userId,
                ]);
            }

            // تحديث رصيد العميل وسجل كشف الحساب إذا وجد مبلغ آجل
            if ($remainingAmount > 0) {
                $newBalance = $this->customerRepo->updateBalance($customerId, $remainingAmount);
                $this->customerRepo->recordLedgerTransaction([
                    'customer_id'      => $customerId,
                    'transaction_type' => 'invoice',
                    'reference_id'     => $invoiceId,
                    'debit'            => $remainingAmount,
                    'credit'           => 0.00,
                    'balance_after'    => $newBalance,
                    'transaction_date' => $invoiceData['invoice_date'],
                    'notes'            => "قيمة مبيعات آجلة للفاتورة [{$invoiceNumber}]",
                ]);
            }

            $this->logAudit($userId, 'create', 'sales_invoices', $invoiceId, null, [
                'invoice_number' => $invoiceNumber,
                'customer'       => $customer['name'],
                'total_amount'   => $totalAmount,
                'payment_type'   => $paymentType,
            ]);

            Database::commit();

            return $this->getInvoiceById($invoiceId);
        } catch (Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * إلغاء فاتورة مبيعات واسترجاع البضاعة للمخزن وتعديل الرصيد
     */
    public function cancelInvoice($invoiceId, $userId)
    {
        $invoice = $this->getInvoiceById($invoiceId);

        if ($invoice['status'] === 'cancelled') {
            throw new Exception("الفاتورة ملغاة بالفعل مسبقاً.");
        }

        Database::beginTransaction();
        try {
            // إعادة كميات البضاعة للمخزن
            foreach ($invoice['items'] as $item) {
                $adj = $this->warehouseRepo->adjustBatchQuantity(
                    $invoice['warehouse_id'],
                    $item['batch_id'],
                    $item['product_id'],
                    $item['quantity']
                );

                $this->stockRepo->recordTransaction([
                    'transaction_type' => 'adjustment',
                    'reference_table'  => 'sales_invoices',
                    'reference_id'     => $invoiceId,
                    'warehouse_id'     => $invoice['warehouse_id'],
                    'product_id'       => $item['product_id'],
                    'batch_id'         => $item['batch_id'],
                    'qty_before'       => $adj['qty_before'],
                    'qty_change'       => $item['quantity'],
                    'qty_after'        => $adj['qty_after'],
                    'unit_cost'        => $item['unit_price'],
                    'notes'            => "إلغاء فاتورة مبيعات [{$invoice['invoice_number']}] وإرجاع الكميات للمخزن",
                    'created_by'       => $userId,
                ]);
            }

            // إلغاء الأثر المالي على رصيد العميل
            if ($invoice['remaining_amount'] > 0) {
                $newBal = $this->customerRepo->updateBalance($invoice['customer_id'], -$invoice['remaining_amount']);
                $this->customerRepo->recordLedgerTransaction([
                    'customer_id'      => $invoice['customer_id'],
                    'transaction_type' => 'adjustment',
                    'reference_id'     => $invoiceId,
                    'debit'            => 0.00,
                    'credit'           => $invoice['remaining_amount'],
                    'balance_after'    => $newBal,
                    'transaction_date' => date('Y-m-d'),
                    'notes'            => "إلغاء مديونية فاتورة مبيعات ملغاة [{$invoice['invoice_number']}]",
                ]);
            }

            $this->salesRepo->updateInvoiceStatus($invoiceId, 'cancelled');

            $this->logAudit($userId, 'cancel', 'sales_invoices', $invoiceId, ['status' => $invoice['status']], ['status' => 'cancelled']);

            Database::commit();

            return $this->getInvoiceById($invoiceId);
        } catch (Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * تجهيز بيانات الطباعة الحرارية والمستندات A4 الرسمية
     */
    public function getPrintableInvoice($invoiceId, $format = 'a4')
    {
        $invoice = $this->getInvoiceById($invoiceId);

        return [
            'company' => [
                'name_ar'       => 'شركة بوكر فارما للتجارة والاستثمار المحدودة',
                'name_en'       => 'Poker Pharma Trading & Investment Co. Ltd.',
                'country'       => 'جمهورية السودان - الخرطوم',
                'phone'         => '+249 123 456 789',
                'email'         => 'info@pokerpharma.com',
                'tax_number'    => 'TR-987654321',
                'license'       => 'PH-SUD-2024/098',
            ],
            'invoice' => $invoice,
            'format'  => in_array($format, ['thermal_80', 'thermal_57', 'a4']) ? $format : 'a4',
            'printed_at' => date('Y-m-d H:i:s'),
        ];
    }
}
