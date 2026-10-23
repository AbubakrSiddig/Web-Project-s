<?php
/**
 * سكربت فحص واختبار التشغيلات والصلاحيات ومحرك FEFO/FIFO (Phase 7 Test)
 */

require_once dirname(__DIR__) . '/core/Autoloader.php';
\Core\Autoloader::register();

echo "=====================================================\n";
echo "   فحص واختبار التشغيلات والصلاحيات ومحرك FEFO/FIFO  \n";
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

$batchService = new \App\Services\BatchService();
$prodService = new \App\Services\ProductService();
$batchRepo = new \App\Repositories\MySQL\MySQLBatchRepository();

// 1. إنشاء صنف دوائي لاختبار التشغيلات عليه
$testProduct = $prodService->createProduct([
    'category_id'    => 1,
    'trade_name'     => 'سفترياكسون 1 غرام حقن ' . time(),
    'generic_name'   => 'Ceftriaxone Sodium',
    'main_unit'      => 'فيال',
    'purchase_price' => 2000.00,
    'selling_price'  => 3000.00,
], 1);
$productId = $testProduct['id'];

// المخزن الرئيسي WH-01 (id = 1)
$warehouseId = 1;

// 2. إنشاء 3 تشغيلات مختلفة التواريخ لنفس الصنف:
// تشغيلة أ: قريبة الانتهاء (تنتهي بعد 60 يوماً)، تاريخ إنتاج قديم، كمية 30
$batchNear = $batchService->createBatch([
    'product_id'       => $productId,
    'batch_number'     => 'BN-NEAR-01',
    'production_date'  => date('Y-m-d', strtotime('-500 days')),
    'expiry_date'      => date('Y-m-d', strtotime('+60 days')),
    'warehouse_id'     => $warehouseId,
    'initial_quantity' => 30,
], 1);
assertTest("إنشاء تشغيلة قريبة الانتهاء ورصيدها (BN-NEAR-01)", $batchNear['batch_number'] === 'BN-NEAR-01' && $batchNear['days_to_expiry'] > 0);

// تشغيلة ب: صالحة وبعيدة الانتهاء (تنتهي بعد 600 يوم)، تاريخ إنتاج حديث، كمية 50
$batchFar = $batchService->createBatch([
    'product_id'       => $productId,
    'batch_number'     => 'BN-FAR-02',
    'production_date'  => date('Y-m-d', strtotime('-30 days')),
    'expiry_date'      => date('Y-m-d', strtotime('+600 days')),
    'warehouse_id'     => $warehouseId,
    'initial_quantity' => 50,
], 1);
assertTest("إنشاء تشغيلة صالحة وطويلة الصلاحية (BN-FAR-02)", $batchFar['batch_number'] === 'BN-FAR-02');

// تشغيلة ج: منتهية الصلاحية (انتهت قبل 15 يوماً)، كمية 20
$batchExp = $batchService->createBatch([
    'product_id'       => $productId,
    'batch_number'     => 'BN-EXPIRED-03',
    'production_date'  => date('Y-m-d', strtotime('-800 days')),
    'expiry_date'      => date('Y-m-d', strtotime('-15 days')),
    'warehouse_id'     => $warehouseId,
    'initial_quantity' => 20,
], 1);
assertTest("إنشاء تشغيلة منتهية الصلاحية والتصنيف التلقائي كـ expired", $batchExp['status'] === 'expired');

// 3. اختبار قاعدة الأمان الصيدلانية: منع التشغيلات المنتهية من استعلامات البيع
$validBatches = $batchRepo->getValidBatchesForSale($productId, $warehouseId, 'FEFO');
$hasExpiredInValid = false;
foreach ($validBatches as $vb) {
    if ($vb['batch_id'] == $batchExp['id'] || $vb['batch_number'] === 'BN-EXPIRED-03') {
        $hasExpiredInValid = true;
    }
}
assertTest(
    "حظر التشغيلات المنتهية الصلاحية تماماً من استعلامات البيع والصرف",
    $hasExpiredInValid === false && count($validBatches) === 2,
    "Valid batches returned: " . count($validBatches)
);

// 4. اختبار محرك صرف FEFO (الأقرب انتهاءً يخرج أولاً)
$batchService->setDispatchPolicy('FEFO', 1);
$currentPolicy = $batchService->getDispatchPolicy();
assertTest("تفعيل وضبط سياسة FEFO في إعدادات المنظومة", $currentPolicy === 'FEFO');

$fefoSuggestion = $batchService->suggestBatchesForDispatch($productId, $warehouseId, 40);
// طلبنا 40: يجب أن يأخذ 30 من BN-NEAR-01 أولاً، ثم 10 من BN-FAR-02
$firstBatchAllocated = $fefoSuggestion['allocations'][0] ?? null;
$secondBatchAllocated = $fefoSuggestion['allocations'][1] ?? null;

assertTest(
    "محرك FEFO يختار التشغيلة الأقرب انتهاءً أولاً بدقة",
    $firstBatchAllocated && $firstBatchAllocated['batch_number'] === 'BN-NEAR-01' && (float)$firstBatchAllocated['allocated_quantity'] === 30.0,
    "Allocated 30 from BN-NEAR-01"
);
assertTest(
    "محرك FEFO يغطي باقي الكمية المطلوبة من التشغيلة التالية",
    $secondBatchAllocated && $secondBatchAllocated['batch_number'] === 'BN-FAR-02' && (float)$secondBatchAllocated['allocated_quantity'] === 10.0,
    "Allocated 10 from BN-FAR-02"
);
assertTest(
    "التحقق من اكتمال التخصيص الإجمالي (40 كاملة)",
    $fefoSuggestion['is_fully_allocated'] === true && (float)$fefoSuggestion['allocated_quantity'] === 40.0
);

// 5. اختبار محرك صرف FIFO (الأقدم تصنيعاً أولاً)
$batchService->setDispatchPolicy('FIFO', 1);
$fifoPolicy = $batchService->getDispatchPolicy();
assertTest("التبديل البرمجي السلس إلى سياسة FIFO", $fifoPolicy === 'FIFO');

// في اختبارنا، تاريخ إنتاج BN-NEAR-01 هو قبل 500 يوم، و BN-FAR-02 قبل 30 يوماً
$fifoSuggestion = $batchService->suggestBatchesForDispatch($productId, $warehouseId, 25);
assertTest(
    "محرك FIFO يختار التشغيلة الأقدم تصنيعاً / وروداً أولاً",
    $fifoSuggestion['allocations'][0]['batch_number'] === 'BN-NEAR-01' && (float)$fifoSuggestion['allocations'][0]['allocated_quantity'] === 25.0
);

// إعادة السياسة إلى FEFO (السياسة الموصى بها صيدلانياً لبوكر فارما)
$batchService->setDispatchPolicy('FEFO', 1);

// 6. اختبار تقرير وتنبيهات الأدوية قريبة الانتهاء (Near Expiry)
$nearExpiryAlerts = $batchService->getNearExpiryAlerts(180, $warehouseId);
$foundNearAlert = false;
foreach ($nearExpiryAlerts as $na) {
    if ($na['batch_number'] === 'BN-NEAR-01') {
        $foundNearAlert = true;
    }
}
assertTest("توليد تنبيه الأدوية قريبة الانتهاء (أقل من 180 يوماً)", $foundNearAlert === true, "Found in alerts: {$foundNearAlert}");

// 7. اختبار تقرير الأدوية منتهية الصلاحية (Expired Alerts)
$expiredAlerts = $batchService->getExpiredAlerts($warehouseId);
$foundExpAlert = false;
foreach ($expiredAlerts as $ea) {
    if ($ea['batch_number'] === 'BN-EXPIRED-03') {
        $foundExpAlert = true;
    }
}
assertTest("توليد تنبيه الأدوية المنتهية الصلاحية", $foundExpAlert === true);

// 8. فحص وتحديث الحالات التلقائي للمنتهي
$markedCount = $batchService->autoMarkExpiredBatches();
assertTest("أداة التحديث الدوري لحالات التشغيلات المنتهية", is_numeric($markedCount));

// 9. اختبار سجل التدقيق للتشغيلات
$auditStmt = \Core\Database::pdo()->query("SELECT COUNT(*) FROM `audit_logs` WHERE `module` = 'batches'");
$auditCount = (int)$auditStmt->fetchColumn();
assertTest("تسجيل حركات التشغيلات في سجل التدقيق (audit_logs)", $auditCount >= 3, "Total batch audit records: {$auditCount}");

echo "-----------------------------------------------------\n";
echo "النتيجة النهائية: {$passCount} نجاح | {$failCount} فشل\n";
echo "=====================================================\n";

if ($failCount > 0) {
    exit(1);
}
