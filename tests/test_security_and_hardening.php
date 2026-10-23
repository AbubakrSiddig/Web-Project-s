<?php
/**
 * سكربت اختبار التحصينات الأمنية والقيود المزدوجة والمعدل وسقوف المرتجعات
 * Security Hardening, Double-Entry Accounting, Rate Limiting & Return Constraints Test
 */

require_once dirname(__DIR__) . '/core/Autoloader.php';
\Core\Autoloader::register();

// Load .env
\Core\Helpers\Env::load(dirname(__DIR__) . '/.env');

echo "=================================================================\n";
echo "   فحص التحصينات الأمنية وسقوف المعاملات — بوكر فارما           \n";
echo "=================================================================\n";

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

// -------------------------------------------------------------
// 1. فحص ملف البيئة ومفتاح التطبيق APP_KEY
// -------------------------------------------------------------
$appKey = \Core\Helpers\Env::get('APP_KEY');
assertTest("وجود مفتاح التشفير APP_KEY بطول آمن >= 32 حرفاً", !empty($appKey) && strlen($appKey) >= 32, "Length: " . strlen($appKey));

// -------------------------------------------------------------
// 2. فحص متانة JWT ومطالبات exp و nbf و iat وحظر التوكن المنتهي أو المستقبلي
// -------------------------------------------------------------
$token = \Core\Helpers\Jwt::encode(['sub' => 1, 'username' => 'admin'], 3600);
$decoded = \Core\Helpers\Jwt::decode($token);
assertTest("توليد وفك تشفير JWT بنجاح", $decoded !== null && $decoded['sub'] === 1);
assertTest("اشتمال التوكن تلقائياً على المطالبات exp و iat و nbf", isset($decoded['exp']) && isset($decoded['iat']) && isset($decoded['nbf']));

// اختبار توكن منتهي الصلاحية (Expired Token)
$expiredToken = \Core\Helpers\Jwt::encode(['sub' => 1, 'username' => 'admin'], -60);
$decodedExpired = \Core\Helpers\Jwt::decode($expiredToken);
assertTest("رفض فك تشفير التوكن المنتهي الصلاحية حتمياً (Expired Token)", $decodedExpired === null);

// اختبار توكن قادم من المستقبل (Not Before in the future)
$futureHeader = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'])), '+/', '-_'), '=');
$futurePayload = rtrim(strtr(base64_encode(json_encode([
    'sub' => 1,
    'iat' => time() + 3600,
    'nbf' => time() + 3600,
    'exp' => time() + 7200
])), '+/', '-_'), '=');
$signature = rtrim(strtr(base64_encode(hash_hmac('sha256', "$futureHeader.$futurePayload", $appKey, true)), '+/', '-_'), '=');
$futureToken = "$futureHeader.$futurePayload.$signature";
$decodedFuture = \Core\Helpers\Jwt::decode($futureToken);
assertTest("رفض فك تشفير التوكن المستقبلي nbf > now (Future Token)", $decodedFuture === null);

// اختبار توكن بتوقيع مزور
$tamperedToken = $token . "tamper";
assertTest("رفض فك تشفير التوكن ذي التوقيع المزور", \Core\Helpers\Jwt::decode($tamperedToken) === null);

// -------------------------------------------------------------
// 3. فحص محدد محاولات الدخول (Brute-Force Rate Limiting)
// -------------------------------------------------------------
$testIp = '192.168.100.' . rand(10, 99);
$testAction = 'test_login';
\Core\Helpers\RateLimiter::clear($testIp, $testAction);

assertTest("محدد المعدل: السماح بالمحاولة الأولى", \Core\Helpers\RateLimiter::isLimited($testIp, 5, 900, $testAction) === false);
for ($i = 0; $i < 4; $i++) {
    \Core\Helpers\RateLimiter::hit($testIp, 900, $testAction);
}
assertTest("محدد المعدل: البقاء متاحاً بعد 4 محاولات فاشلة", \Core\Helpers\RateLimiter::isLimited($testIp, 5, 900, $testAction) === false);
\Core\Helpers\RateLimiter::hit($testIp, 900, $testAction); // 5th hit
assertTest("محدد المعدل: إغلاق وحظر المحاولات بعد بلوغ الحد الأقصى (5 محاولات)", \Core\Helpers\RateLimiter::isLimited($testIp, 5, 900, $testAction) === true);

\Core\Helpers\RateLimiter::clear($testIp, $testAction);
assertTest("محدد المعدل: تصفير العداد بعد نجاح المصادقة", \Core\Helpers\RateLimiter::isLimited($testIp, 5, 900, $testAction) === false);

// -------------------------------------------------------------
// 4. فحص المحاسبة المزدوجة (Double-Entry Journal Balancing)
// -------------------------------------------------------------
$accountingService = new \App\Services\AccountingService();

// اختبار رفض القيد غير المتوازن (Unbalanced: Debit != Credit)
$unbalancedCaught = false;
try {
    $accountingService->createJournalEntry([
        'entry_date'  => date('Y-m-d'),
        'description' => 'قيد غير متوازن للاختبار الأمني',
        'lines' => [
            ['account_name' => 'الصندوق', 'account_type' => 'asset', 'debit' => 1000.00, 'credit' => 0.00],
            ['account_name' => 'المبيعات', 'account_type' => 'revenue', 'debit' => 0.00, 'credit' => 850.00]
        ]
    ], 1);
} catch (\InvalidArgumentException $e) {
    $unbalancedCaught = true;
} catch (\Exception $e) {
    $unbalancedCaught = true;
}
assertTest("رفض اعتماد القيد المحاسبي غير المتوازن (Debit != Credit)", $unbalancedCaught);

// اختبار رفض القيد الفارغ أو ذو مبالغ صفرية
$zeroCaught = false;
try {
    $accountingService->createJournalEntry([
        'entry_date'  => date('Y-m-d'),
        'description' => 'قيد صفري',
        'lines' => [
            ['account_name' => 'الصندوق', 'account_type' => 'asset', 'debit' => 0.00, 'credit' => 0.00],
            ['account_name' => 'المبيعات', 'account_type' => 'revenue', 'debit' => 0.00, 'credit' => 0.00]
        ]
    ], 1);
} catch (\InvalidArgumentException $e) {
    $zeroCaught = true;
} catch (\Exception $e) {
    $zeroCaught = true;
}
assertTest("رفض اعتماد القيد المحاسبي بمبالغ صفرية", $zeroCaught);

// اختبار نجاح قيد متوازن تماماً
$balancedSuccess = false;
try {
    $je = $accountingService->createJournalEntry([
        'entry_date'  => date('Y-m-d'),
        'description' => 'قيد متوازن معتمد للاختبار الأمني',
        'lines' => [
            ['account_name' => 'خزينة الفرع', 'account_type' => 'asset', 'debit' => 5000.00, 'credit' => 0.00],
            ['account_name' => 'إيراد الاستشارات الطبية', 'account_type' => 'revenue', 'debit' => 0.00, 'credit' => 5000.00]
        ]
    ], 1);
    $balancedSuccess = !empty($je['id']) && $je['total_debit'] == 5000.00 && $je['total_credit'] == 5000.00;
} catch (\Exception $e) {
    $balancedSuccess = false;
}
assertTest("اعتماد القيد المحاسبي المتوازن وترحيله تلقائياً", $balancedSuccess);

// -------------------------------------------------------------
// 5. فحص التحقق من رفع الملفات والصور الأمنية (File Upload Hardening)
// -------------------------------------------------------------
$fakePhpFile = [
    'name'     => 'exploit.php',
    'type'     => 'application/x-php',
    'tmp_name' => sys_get_temp_dir() . '/test_php.tmp',
    'error'    => UPLOAD_ERR_OK,
    'size'     => 1024
];
file_put_contents($fakePhpFile['tmp_name'], '<?php phpinfo(); ?>');

$fakePhpValidator = \Core\Helpers\FileUpload::validateImage($fakePhpFile);
assertTest("حظر رفع الملفات التنفيذية والبرمجية PHP كصورة صنف", $fakePhpValidator !== true);
@unlink($fakePhpFile['tmp_name']);

$fakeLargeFile = [
    'name'     => 'huge_image.jpg',
    'type'     => 'image/jpeg',
    'tmp_name' => sys_get_temp_dir() . '/test_large.tmp',
    'error'    => UPLOAD_ERR_OK,
    'size'     => 3 * 1024 * 1024 // 3MB > 2MB limit
];
file_put_contents($fakeLargeFile['tmp_name'], str_repeat('A', 100));
$fakeLargeValidator = \Core\Helpers\FileUpload::validateImage($fakeLargeFile);
assertTest("حظر رفع الصور التي تتجاوز السقف الأقصى 2MB", $fakeLargeValidator !== true);
@unlink($fakeLargeFile['tmp_name']);

// -------------------------------------------------------------
// 6. فحص سقف كميات المرتجعات الدوائية (Return Quantity Hardening)
// -------------------------------------------------------------
$salesReturnService = new \App\Services\SalesReturnService();
$excessReturnCaught = false;
try {
    // Attempting return of 999999 units on invoice 1 which only has small quantities
    $salesReturnService->createReturn([
        'sales_invoice_id' => 1,
        'return_date'      => date('Y-m-d'),
        'warehouse_id'     => 1,
        'item_condition'   => 'intact',
        'refund_type'      => 'credit_note',
        'items' => [
            [
                'product_id'   => 1,
                'batch_id'     => 1,
                'quantity'     => 999999, // Exceeds original invoice qty
                'unit_price'   => 100.00
            ]
        ]
    ], 1);
} catch (\InvalidArgumentException $e) {
    $excessReturnCaught = true;
} catch (\Exception $e) {
    $excessReturnCaught = true;
}
assertTest("حظر المرتجع الدوائي الذي يتجاوز الكمية المباعة بالفاتورة الأصلية", $excessReturnCaught);

// -------------------------------------------------------------
// ملخص نتائج فحص التحصينات
// -------------------------------------------------------------
echo "\n=================================================================\n";
echo "النتيجة: [{$passCount}] اختبارات نجحت بنجاح تام، و [{$failCount}] اختبارات فشلت.\n";
if ($failCount === 0) {
    echo "الحالة: كافة التحصينات الأمنية وسقوف المعاملات تعمل بكفاءة 100%!\n";
    exit(0);
} else {
    echo "الحالة: فشل في اجتياز بعض التحصينات الأمنية!\n";
    exit(1);
}
