<?php
/**
 * المشغل الشامل لكافة اختبارات منظومة بوكر فارما (Master Test Suite)
 */

echo "=================================================================\n";
echo "   منظومة بوكر فارما لإدارة الأعمال الدوائية - التشغيل الشامل   \n";
echo "=================================================================\n\n";

$testFiles = [
    'test_core.php'                    => '1. فحص النواة والموجه والتوجيه ومحرك الاستجابة',
    'test_auth.php'                    => '2. فحص المصادقة وتوليد JWT والأدوار والصلاحيات RBAC',
    'test_products.php'                => '3. فحص الأصناف الدوائية والفئات والاستيراد والتصدير',
    'test_batches.php'                 => '4. فحص التشغيلات وتواريخ الصلاحية ومحرك FEFO/FIFO',
    'test_warehouses_and_stock.php'    => '5. فحص المخازن والتحويلات المخزنية والتسويات والجرد',
    'test_purchases_and_suppliers.php' => '6. فحص الموردين وفواتير الشراء والتوريد',
    'test_sales_and_customers.php'     => '7. فحص العملاء وفواتير المبيعات ونقاط البيع والصلاحية',
    'test_returns_and_accounting.php'  => '8. فحص المرتجعات والحسابات وسندات القبض والصرف والتحويل',
    'test_reports_and_dashboard.php'   => '9. فحص التقارير الدوائية ولوحة المؤشرات وسجل التدقيق',
    'test_security_and_hardening.php'  => '10. فحص التحصينات الأمنية والقيود المزدوجة ومحدد المعدل وسقوف المرتجعات',
];

$totalPassedSuites = 0;
$totalFailedSuites = 0;

// Resolve portable PHP binary
$phpBinary = 'php';
if (defined('PHP_BINARY') && file_exists(PHP_BINARY)) {
    $phpBinary = PHP_BINARY;
} elseif (file_exists('C:\\xampp\\php\\php.exe')) {
    $phpBinary = 'C:\\xampp\\php\\php.exe';
}

foreach ($testFiles as $file => $description) {
    echo ">> تشغيل: {$description} [{$file}]...\n";
    $output = [];
    $returnCode = 0;
    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . $file);
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0) {
        $totalPassedSuites++;
        echo "   [✓] اكتمل بنجاح تام.\n";
    } else {
        $totalFailedSuites++;
        echo "   [✗] فشل الاختبار:\n";
        echo implode("\n", array_slice($output, -10)) . "\n";
    }
    echo "-----------------------------------------------------------------\n";
}

echo "\n=================================================================\n";
echo "الملخص الشامل: [{$totalPassedSuites} من أصل " . count($testFiles) . "] مجموعات اختبارية اجتازت بنجاح تام!\n";
if ($totalFailedSuites === 0) {
    echo "تهانينا! كافة وظائف النظام تعمل بدقة متناهية وبنسبة نجاح 100%.\n";
} else {
    echo "يوجد مجموعات اختبار أخفقت [{$totalFailedSuites}].\n";
    exit(1);
}
echo "=================================================================\n";
