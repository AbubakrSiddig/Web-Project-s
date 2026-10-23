<?php
/**
 * سكربت فحص واختبار الموردين والمشتريات (Phase 9 Test)
 */

require_once dirname(__DIR__) . '/core/Autoloader.php';
\Core\Autoloader::register();

echo "=====================================================\n";
echo "       فحص واختبار الموردين وفواتير المشتريات         \n";
echo "=====================================================\n";

$passCount = 0;
$failCount = 0;

function assertTest($name, $condition, $info = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [✓] نجاح: {$name}" . ($info ? " ({$info})" : "") . "\n";
    } else {
        $failCount++;
        echo " [✗] فشل: {$name}" . ($info ? " ({$info})" : "") . "\n";
    }
}

$supService = new \App\Services\SupplierService();
$purService = new \App\Services\PurchaseService();
$prodService = new \App\Services\ProductService();
$whRepo = new \App\Repositories\MySQL\MySQLWarehouseRepository();

// 1. إضافة مورد جديد
$supplier = $supService->createSupplier([
    'name'         => 'شركة النيل للأدوية ' . time(),
    'company_name' => 'Nile Pharma Ltd.',
    'phone'        => '0912345678',
    'address'      => 'الخرطوم - المنطقة الصناعية',
    'tax_number'   => 'TAX-12345',
], 1);
assertTest("إضافة مورد جديد", !empty($supplier['id']) && $supplier['name'] !== '');

// 2. تعديل بيانات المورد
$updatedSupplier = $supService->updateSupplier($supplier['id'], [
    'alt_phone' => '0123456789',
], 1);
assertTest("تحديث بيانات المورد", $updatedSupplier['alt_phone'] === '0123456789');

// 3. إنشاء صنف دوائي لاختبار الشراء
$product = $prodService->createProduct([
    'category_id'    => 2,
    'trade_name'     => 'أزيثرومايسين 500 مغ ' . time(),
    'generic_name'   => 'Azithromycin',
    'main_unit'      => 'علبة',
    'purchase_price' => 1200.00,
    'selling_price'  => 1800.00,
], 1);
$productId = $product['id'];

// 4. إنشاء فاتورة مشتريات آجلة مع تشغيلة جديدة (كمية 50)
$batchNumber = 'AZM-PUR-' . time();
$expiryDate = date('Y-m-d', strtotime('+365 days'));

$purchase = $purService->createInvoice([
    'supplier_id'        => $supplier['id'],
    'supplier_invoice_no'=> 'INV-SUP-9988',
    'warehouse_id'       => 1,
    'invoice_date'       => date('Y-m-d'),
    'payment_type'       => 'credit',
    'status'             => 'received',
    'items'              => [
        [
            'product_id'      => $productId,
            'batch_number'    => $batchNumber,
            'production_date' => date('Y-m-d', strtotime('-30 days')),
            'expiry_date'     => $expiryDate,
            'quantity'        => 50,
            'unit_price'      => 1200.00,
        ]
    ]
], 1);
assertTest("إصدار واستلام فاتورة مشتريات آجلة بنجاح", !empty($purchase['id']) && $purchase['status'] === 'received');
assertTest("احتساب إجمالي الفاتورة 50 × 1200 = 60000", (float)$purchase['total_amount'] == 60000.00);
assertTest("تسجيل كامل المبلغ كمديونية آجلة remaining_amount", (float)$purchase['remaining_amount'] == 60000.00);

// 5. فحص استلام البضاعة في المخزن 1
$batchCreated = (new \App\Repositories\MySQL\MySQLBatchRepository())->findByProductAndBatchNumber($productId, $batchNumber);
assertTest("الإنشاء التلقائي للتشغيلة الجديدة عند الشراء", $batchCreated && $batchCreated['batch_number'] === $batchNumber);

$stockInWh1 = $whRepo->getBatchStock(1, $batchCreated['id']);
assertTest("إضافة الكمية (50) تلقائياً لرصيد المخزن 1", $stockInWh1 && (float)$stockInWh1['quantity'] == 50.00);

// 6. فحص رصيد المورد وكشف حسابه (مستحقات دائنة بقيمة 60,000)
$supAfter = $supService->getSupplierById($supplier['id']);
assertTest("تحديث رصيد المورد ليصبح دائناً بـ 60000 ج.س", (float)$supAfter['balance'] == 60000.00);

$ledger = $supService->getSupplierLedger($supplier['id']);
assertTest("تسجيل قيد فاتورة المشتريات في كشف حساب المورد", $ledger['total'] >= 1);

echo "\n-----------------------------------------------------\n";
echo "النتيجة النهائية لاختبار الموردين والمشتريات: نجاح [{$passCount}] | فشل [{$failCount}]\n";
echo "=====================================================\n";
if ($failCount > 0) exit(1);
