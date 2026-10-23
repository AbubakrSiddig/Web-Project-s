<?php
/**
 * سكربت فحص واختبار العملاء وفواتير المبيعات وسياسات الصرف (Phase 10 Test)
 */

require_once dirname(__DIR__) . '/core/Autoloader.php';
\Core\Autoloader::register();

echo "=====================================================\n";
echo "       فحص واختبار العملاء وفواتير المبيعات ومحرك FEFO \n";
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

$custService = new \App\Services\CustomerService();
$salesService = new \App\Services\SalesService();
$prodService = new \App\Services\ProductService();
$batchService = new \App\Services\BatchService();
$whRepo = new \App\Repositories\MySQL\MySQLWarehouseRepository();

// 1. إضافة عميل مع سقف ائتماني 50,000 ج.س
$customer = $custService->createCustomer([
    'name'           => 'د. أحمد سليمان - صيدلية الأمل ' . time(),
    'pharmacy_name'  => 'صيدلية الأمل المركزية',
    'license_number' => 'PH-KRT-4421',
    'phone'          => '0998877665',
    'credit_limit'   => 50000.00,
    'balance'        => 0.00,
], 1);
assertTest("إضافة صيدلية / عميل جديد بسقف ائتماني 50,000", !empty($customer['id']) && (float)$customer['credit_limit'] == 50000.00);

// 2. إنشاء صنف دوائي جديد
$product = $prodService->createProduct([
    'category_id'      => 1,
    'trade_name'       => 'باراسيتامول شراب أطفال ' . time(),
    'generic_name'     => 'Paracetamol Syrup 120mg/5ml',
    'main_unit'        => 'زجاجة',
    'purchase_price'   => 300.00,
    'selling_price'    => 500.00,
    'min_selling_price'=> 450.00,
], 1);
$productId = $product['id'];

// تشغيلة أ: منتهية الصلاحية (أمس) بكمية 20 في المخزن 1
$batchExpired = $batchService->createBatch([
    'product_id'       => $productId,
    'batch_number'     => 'PCM-EXP-' . time(),
    'production_date'  => date('Y-m-d', strtotime('-400 days')),
    'expiry_date'      => date('Y-m-d', strtotime('-1 days')), // منتهية!
    'warehouse_id'     => 1,
    'initial_quantity' => 20,
], 1);

// تشغيلة ب: صالحة (تنتهي بعد 300 يوم) بكمية 50 في المخزن 1
$batchValid = $batchService->createBatch([
    'product_id'       => $productId,
    'batch_number'     => 'PCM-VAL-' . time(),
    'production_date'  => date('Y-m-d', strtotime('-60 days')),
    'expiry_date'      => date('Y-m-d', strtotime('+300 days')), // صالحة
    'warehouse_id'     => 1,
    'initial_quantity' => 50,
], 1);

// 3. قاعدة دوائية حاسمة: منع بيع التشغيلة منتهية الصلاحية نهائياً (Zero Tolerance)
$expiredSaleBlocked = false;
try {
    $salesService->createInvoice([
        'customer_id'  => $customer['id'],
        'warehouse_id' => 1,
        'payment_type' => 'cash',
        'items'        => [
            [
                'product_id' => $productId,
                'batch_id'   => $batchExpired['id'],
                'quantity'   => 5,
                'unit_price' => 500.00,
            ]
        ]
    ], 1);
} catch (\Exception $e) {
    $expiredSaleBlocked = true;
    $exceptionMsg = $e->getMessage();
}
assertTest("منع بيع التشغيلة منتهية الصلاحية نهائياً وإطلاق استثناء أمان", $expiredSaleBlocked, $exceptionMsg ?? '');

// 4. اختبار التوزيع الذكي FEFO (صرف تلقائي دون تحديد تشغيلة)
$fefoInvoice = $salesService->createInvoice([
    'customer_id'  => $customer['id'],
    'warehouse_id' => 1,
    'payment_type' => 'cash',
    'items'        => [
        [
            'product_id' => $productId,
            'quantity'   => 15, // ستُصرف من التشغيلة الصالحة فقط
            'unit_price' => 500.00,
        ]
    ]
], 1);
assertTest("صرف تلقائي عبر FEFO واستبعاد التشغيلة المنتهية", !empty($fefoInvoice['id']));
$allocatedBatchId = $fefoInvoice['items'][0]['batch_id'];
assertTest("التشغيلة التي تم صرفها هي التشغيلة الصالحة PCM-VAL", $allocatedBatchId == $batchValid['id']);

// فحص رصيد المخزن للتشغيلة الصالحة بعد صرف 15 (50 - 15 = 35)
$stockValidAfter = $whRepo->getBatchStock(1, $batchValid['id']);
assertTest("خصم 15 من رصيد التشغيلة الصالحة في المخزن (المتبقي: 35)", (float)$stockValidAfter['quantity'] == 35.00);

// 5. اختبار تجاوز الحد الائتماني (Credit Limit Check)
// سقف الائتمان 50,000. سنحاول شراء كمية بقيمة 60,000 بالآجل
$creditBlocked = false;
try {
    $salesService->createInvoice([
        'customer_id'  => $customer['id'],
        'warehouse_id' => 1,
        'payment_type' => 'credit',
        'items'        => [
            [
                'product_id' => $productId,
                'quantity'   => 120, // 120 * 500 = 60000 > 50000
                'unit_price' => 500.00,
            ]
        ]
    ], 1, false); // hasCreditOverride = false
} catch (\Exception $e) {
    $creditBlocked = true;
}
assertTest("منع البيع الآجل عند تجاوز الحد الائتماني للصيدلية بدون إذن المدير", $creditBlocked);

// 6. إصدار فاتورة بيع آجل نظامية ضمن الحد الائتماني (10 وحدات × 500 = 5,000 ج.س)
$creditInvoice = $salesService->createInvoice([
    'customer_id'  => $customer['id'],
    'warehouse_id' => 1,
    'payment_type' => 'credit',
    'items'        => [
        [
            'product_id' => $productId,
            'batch_id'   => $batchValid['id'],
            'quantity'   => 10,
            'unit_price' => 500.00,
        ]
    ]
], 1);
assertTest("إصدار فاتورة مبيعات آجلة نظامية بقيمة 5,000 ج.س", (float)$creditInvoice['total_amount'] == 5000.00);

// فحص رصيد الصيدلية وكشف الحساب
$customerAfter = $custService->getCustomerById($customer['id']);
assertTest("تسجيل المديونية على الصيدلية (الرصيد: 5,000)", (float)$customerAfter['balance'] == 5000.00);

$custLedger = $custService->getCustomerLedger($customer['id']);
assertTest("تسجيل قيد الفاتورة في كشف حساب الصيدلية", $custLedger['total'] >= 1);

// 7. اختبار توليد بيانات الطباعة (A4 والحراري 80mm)
$printData = $salesService->getPrintableInvoice($creditInvoice['id'], 'thermal_80');
assertTest("توليد بيانات الطباعة متضمنة ترويسة بوكر فارما", !empty($printData['company']['name_ar']) && $printData['format'] === 'thermal_80');

// 8. اختبار إلغاء الفاتورة واسترجاع البضاعة والمديونية
$cancelled = $salesService->cancelInvoice($creditInvoice['id'], 1);
assertTest("إلغاء فاتورة المبيعات وتحديث حالتها إلى cancelled", $cancelled['status'] === 'cancelled');

$stockRestored = $whRepo->getBatchStock(1, $batchValid['id']);
assertTest("استرجاع الكمية الملغاة (10) إلى رصيد المخزن (الرصيد عاد إلى 35)", (float)$stockRestored['quantity'] == 35.00);

$customerRestored = $custService->getCustomerById($customer['id']);
assertTest("إلغاء مديونية الصيدلية وعودة رصيدها إلى 0", (float)$customerRestored['balance'] == 0.00);

echo "\n-----------------------------------------------------\n";
echo "النتيجة النهائية لاختبار العملاء والمبيعات: نجاح [{$passCount}] | فشل [{$failCount}]\n";
echo "=====================================================\n";
if ($failCount > 0) exit(1);
