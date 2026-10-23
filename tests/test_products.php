<?php
/**
 * سكربت فحص واختبار الأصناف والفئات (Phase 6 Unit & Integration Test)
 */

require_once dirname(__DIR__) . '/core/Autoloader.php';
\Core\Autoloader::register();

echo "=====================================================\n";
echo "   فحص واختبار الأصناف والفئات — بوكر فارما           \n";
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

$catService = new \App\Services\CategoryService();
$prodService = new \App\Services\ProductService();

// 1. اختبار استرجاع الفئات الافتراضية
$categories = $catService->getAllCategories();
assertTest("استرجاع الفئات الدوائية الافتراضية", count($categories) >= 7, "Total: " . count($categories));

// 2. اختبار إضافة فئة جديدة
$catName = "فئة أدوية تجريبية " . time();
try {
    $newCat = $catService->createCategory(['name' => $catName, 'description' => 'فئة للاختبار']);
    assertTest("إنشاء فئة جديدة بنجاح", $newCat['name'] === $catName, "ID: " . $newCat['id']);
    $testCatId = $newCat['id'];
} catch (\Exception $e) {
    assertTest("إنشاء فئة جديدة", false, $e->getMessage());
    $testCatId = $categories[0]['id'];
}

// 3. اختبار إضافة صنف دوائي جديد
$barcode = "628100" . rand(100000, 999999);
$itemCode = "PRD-" . rand(1000, 9999);
try {
    $newProd = $prodService->createProduct([
        'category_id'       => $testCatId,
        'item_code'         => $itemCode,
        'trade_name'        => 'أمبيسيلين 500 ملغم كبسول',
        'generic_name'      => 'Ampicillin Trihydrate',
        'dosage_form'       => 'كبسولات',
        'strength'          => '500mg',
        'manufacturer'      => 'أميفارما للصناعات الدوائية (سودان)',
        'origin_country'    => 'السودان',
        'barcode'           => $barcode,
        'main_unit'         => 'باكت 20 كبسولة',
        'purchase_price'    => 1500.00,
        'selling_price'     => 2200.00,
        'min_selling_price' => 1800.00,
        'min_stock_alert'   => 15,
        'is_active'         => 1
    ], 1);

    assertTest(
        "إنشاء صنف دوائي جديد بكامل الحقول الصيدلانية",
        $newProd['trade_name'] === 'أمبيسيلين 500 ملغم كبسول' && $newProd['item_code'] === $itemCode,
        "Barcode: {$barcode}, Sell: {$newProd['selling_price']} SDG"
    );
    $testProdId = $newProd['id'];
} catch (\Exception $e) {
    assertTest("إنشاء صنف دوائي جديد", false, $e->getMessage());
    $testProdId = null;
}

// 4. اختبار حماية الحد الأدنى للبيع (منع البيع بخسارة أو أسعار غير متناسقة)
try {
    $prodService->createProduct([
        'category_id'       => $testCatId,
        'trade_name'        => 'دواء غير متناسق السعر',
        'purchase_price'    => 1000,
        'selling_price'     => 1200,
        'min_selling_price' => 1500 // أكبر من سعر البيع!
    ], 1);
    assertTest("حظر الحد الأدنى للبيع الأكبر من سعر البيع", false);
} catch (\Exception $e) {
    assertTest("حظر الحد الأدنى للبيع الأكبر من سعر البيع", true, $e->getMessage());
}

// 5. اختبار البحث بالباركود
$foundByBarcode = (new \App\Repositories\MySQL\MySQLProductRepository())->findByBarcode($barcode);
assertTest("البحث عن الصنف بالباركود", $foundByBarcode !== null && $foundByBarcode['id'] == $testProdId);

// 6. اختبار تعديل الصنف والأسعار
try {
    $updatedProd = $prodService->updateProduct($testProdId, [
        'selling_price'     => 2500.00,
        'min_selling_price' => 2000.00,
        'min_stock_alert'   => 20
    ], 1);
    assertTest("تحديث بيانات الصنف والأسعار", (float)$updatedProd['selling_price'] == 2500.00 && (int)$updatedProd['min_stock_alert'] == 20);
} catch (\Exception $e) {
    assertTest("تحديث بيانات الصنف", false, $e->getMessage());
}

// 7. اختبار تبديل حالة الصنف (تعطيل ثم تفعيل)
$deactivated = $prodService->toggleProductStatus($testProdId, 1);
assertTest("تعطيل الصنف (toggleStatus -> 0)", $deactivated === 0);
$reactivated = $prodService->toggleProductStatus($testProdId, 1);
assertTest("إعادة تفعيل الصنف (toggleStatus -> 1)", $reactivated === 1);

// 8. اختبار تصدير الأصناف CSV (UTF-8 BOM)
$csvData = $prodService->exportProductsCsv();
$hasBom = (substr($csvData, 0, 3) === "\xEF\xBB\xBF");
$hasHeader = (strpos($csvData, 'الاسم التجاري') !== false && strpos($csvData, 'كود الصنف') !== false);
assertTest("تصدير الأصناف كملف CSV مهيأ بالكامل لإكسل العربي (BOM UTF-8)", $hasBom && $hasHeader, "Size: " . strlen($csvData) . " bytes");

// 9. اختبار استيراد الأصناف من ملف CSV
$t = time();
$code1 = "IMP-{$t}-1";
$code2 = "IMP-{$t}-2";
$bar1 = "628" . substr(strval($t), -6) . "1";
$bar2 = "628" . substr(strval($t), -6) . "2";
$sampleCsv = "\xEF\xBB\xBF" .
"كود الصنف,الاسم التجاري,الاسم العلمي,الفئة الدوائية,الشكل الدوائي,التركيز,الشركة المصنعة,بلد المنشأ,الباركود,الوحدة,سعر الشراء الافتراضي,سعر البيع,الحد الأدنى لسعر البيع,حد إعادة الطلب\n" .
"{$code1},أوجمنتين 1 غرام أقراص,Amoxicillin + Clavulanic acid,مضادات حيوية ومضادات للجراثيم,أقراص مغلفة,1000mg,GSK,بريطانيا,{$bar1},باكت 14 قرص,4500.00,6000.00,5500.00,10\n" .
"{$code2},كتافلام 50 ملغم,Diclofenac Potassium,مسكنات وخافضات حرارة ومضادات التهاب,أقراص,50mg,نوفارتس,سويسرا,{$bar2},باكت 20 قرص,1800.00,2600.00,2300.00,15\n";

try {
    $importResult = $prodService->importProductsCsv($sampleCsv, 1);
    assertTest(
        "استيراد الأصناف الدوائية من ملف CSV",
        $importResult['imported'] >= 2 && empty($importResult['errors']),
        "Imported: {$importResult['imported']}, Updated: {$importResult['updated']}"
    );

    // التحقق من وجود الصنف المستورد في قاعدة البيانات
    $importedAugmentin = (new \App\Repositories\MySQL\MySQLProductRepository())->findByItemCode($code1);
    assertTest("التحقق من حفظ الصنف المستورد بدقة في قاعدة البيانات", $importedAugmentin !== null && $importedAugmentin['trade_name'] === 'أوجمنتين 1 غرام أقراص');
} catch (\Exception $e) {
    assertTest("استيراد الأصناف من ملف CSV", false, $e->getMessage());
}

// 10. فحص تسجيل العمليات في سجل التدقيق (Audit Logs)
$auditStmt = \Core\Database::pdo()->query("SELECT COUNT(*) FROM `audit_logs` WHERE `module` IN ('products', 'categories')");
$auditCount = (int)$auditStmt->fetchColumn();
assertTest("تسجيل عمليات الأصناف في سجل التدقيق (audit_logs)", $auditCount >= 3, "Total product audit records: {$auditCount}");

echo "-----------------------------------------------------\n";
echo "النتيجة النهائية: {$passCount} نجاح | {$failCount} فشل\n";
echo "=====================================================\n";

if ($failCount > 0) {
    exit(1);
}
