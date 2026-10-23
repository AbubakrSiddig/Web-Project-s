<?php
/**
 * سكربت اختبار سلامة النواة (PHP MVC Core Test)
 */

require_once dirname(__DIR__) . '/core/Autoloader.php';
\Core\Autoloader::register();

echo "=====================================================\n";
echo "   فحص واختبار نواة PHP MVC Core — بوكر فارما         \n";
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

// 1. اختبار قاعدة البيانات
try {
    $pdo = \Core\Database::pdo();
    assertTest("الاتصال بقاعدة البيانات PDO Singleton", $pdo instanceof PDO, "Server version: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
} catch (\Exception $e) {
    assertTest("الاتصال بقاعدة البيانات PDO Singleton", false, $e->getMessage());
}

// 2. اختبار الـ Validator
$validator = new \Core\Helpers\Validator([
    'item_name' => 'بنادول إكسترا 500 ملغم',
    'price'     => 1250.50,
    'qty'       => 10,
    'email'     => 'test@pokerpharma.sd',
    'exp_date'  => '2027-12-31'
], [
    'item_name' => 'required|min:3',
    'price'     => 'required|numeric|positive',
    'qty'       => 'required|integer|positive',
    'email'     => 'required|email',
    'exp_date'  => 'required|date'
]);
assertTest("التحقق من صحة المدخلات (Validator - Valid Data)", $validator->validate() === true);

$invalidValidator = new \Core\Helpers\Validator([
    'price' => -50,
    'email' => 'invalid-email'
], [
    'price' => 'positive',
    'email' => 'email'
]);
assertTest("اكتشاف الأخطاء بالمدخلات (Validator - Errors Caught)", $invalidValidator->validate() === false && count($invalidValidator->getErrors()) === 2);

// 3. اختبار التشفير والأمان
$password = 'Admin@123456';
$hash = \Core\Helpers\Security::hashPassword($password);
$verified = \Core\Helpers\Security::verifyPassword($password, $hash);
assertTest("تشفير ومطابقة كلمات المرور (Security::hash/verify)", $verified);

$sanitized = \Core\Helpers\Security::sanitize("<script>alert('xss')</script>دواء");
assertTest("حماية وتطهير XSS (Security::sanitize)", strpos($sanitized, '<script>') === false && strpos($sanitized, '&lt;script&gt;') !== false);

// 4. اختبار Repository Pattern للمستخدمين
try {
    $userRepo = new \App\Repositories\MySQL\MySQLUserRepository();
    $adminUser = $userRepo->findByUsernameWithRole('admin');
    assertTest(
        "مستودع المستخدمين (MySQLUserRepository::findByUsernameWithRole)", 
        $adminUser !== null && $adminUser['username'] === 'admin' && $adminUser['role_name'] === 'admin',
        "Role: " . ($adminUser['role_display_name'] ?? 'None')
    );

    $usersList = $userRepo->getUsersWithRoles(1, 10);
    assertTest("استرجاع المستخدمين المرمزين (MySQLUserRepository::getUsersWithRoles)", count($usersList['items']) >= 6, "Total: " . $usersList['total']);
} catch (\Exception $e) {
    assertTest("مستودع المستخدمين Repository Pattern", false, $e->getMessage());
}

// 5. اختبار HealthCheckController
try {
    // محاكاة طلب
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/health';
    $request = new \Core\Request();
    
    // فحص Request
    assertTest("فئة الطلب (Request::getPath)", $request->getPath() === '/api/health');
    assertTest("فئة الطلب (Request::getMethod)", $request->getMethod() === 'GET');
} catch (\Exception $e) {
    assertTest("فئة الطلب Request", false, $e->getMessage());
}

echo "-----------------------------------------------------\n";
echo "النتيجة النهائية: {$passCount} نجاح | {$failCount} فشل\n";
echo "=====================================================\n";

if ($failCount > 0) {
    exit(1);
}
