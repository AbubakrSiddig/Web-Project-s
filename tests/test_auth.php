<?php
/**
 * سكربت اختبار المصادقة والأدوار والصلاحيات (Phase 5 Unit & Integration Test)
 */

require_once dirname(__DIR__) . '/core/Autoloader.php';
\Core\Autoloader::register();

echo "=====================================================\n";
echo "   فحص واختبار المصادقة والأدوار — بوكر فارما         \n";
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

$authService = new \App\Services\AuthService();
$userService = new \App\Services\UserService();
$roleService = new \App\Services\RoleService();

// 1. اختبار تسجيل الدخول الصحيح
try {
    $loginResult = $authService->login('admin', 'Admin@123456');
    assertTest(
        "تسجيل الدخول ببيانات صحيحة للمدير",
        !empty($loginResult['token']) && $loginResult['user']['username'] === 'admin',
        "Role: " . $loginResult['user']['role_display_name'] . ", Perms: " . count($loginResult['permissions'])
    );
    $adminToken = $loginResult['token'];
} catch (\Exception $e) {
    assertTest("تسجيل الدخول ببيانات صحيحة للمدير", false, $e->getMessage());
    $adminToken = null;
}

// 2. اختبار تسجيل الدخول بكلمة مرور خاطئة
try {
    $authService->login('admin', 'WrongPassword123');
    assertTest("حظر كلمة المرور الخاطئة", false, "لم يتم إلقاء استثناء!");
} catch (\Exception $e) {
    assertTest("حظر كلمة المرور الخاطئة", true, $e->getMessage());
}

// 3. اختبار تسجيل الدخول باسم مستخدم غير موجود
try {
    $authService->login('non_existent_user', 'AnyPassword123');
    assertTest("حظر مستخدم غير مسجل", false, "لم يتم إلقاء استثناء!");
} catch (\Exception $e) {
    assertTest("حظر مستخدم غير مسجل", true, $e->getMessage());
}

// 4. اختبار فك وتدقيق الرمز JWT
$decoded = \Core\Helpers\Jwt::decode($adminToken);
assertTest(
    "التحقق من صحة توقيع الرمز (Jwt::decode)",
    $decoded !== null && $decoded['username'] === 'admin' && isset($decoded['exp']),
    "Expires in: " . ($decoded['exp'] - time()) . "s"
);

// 5. اختبار استرجاع بيانات المستخدم الحالي
$currentUser = $authService->getCurrentUser($decoded['user_id']);
assertTest(
    "استرجاع الملف الشخصي وصلاحيات المستخدم (getCurrentUser)",
    $currentUser !== null && count($currentUser['permissions']) >= 38
);

// 6. اختبار إنشاء مستخدم جديد
$testUsername = 'pharmacist_' . time();
try {
    $newUser = $userService->createUser([
        'role_id'   => 3, // مسؤول مبيعات
        'username'  => $testUsername,
        'full_name' => 'د. الصيدلي التجريبي',
        'email'     => "{$testUsername}@pokerpharma.sd",
        'phone'     => '+249912999888',
        'password'  => 'Pharma@1234',
        'is_active' => 1
    ], $decoded['user_id']);

    assertTest("إنشاء مستخدم جديد وتشفير كلمة مروره", $newUser !== null && $newUser['username'] === $testUsername);

    // تسجيل دخول المستخدم الجديد
    $newUserLogin = $authService->login($testUsername, 'Pharma@1234');
    assertTest("تسجيل الدخول بالحساب الجديد بنجاح", !empty($newUserLogin['token']) && $newUserLogin['user']['role_name'] === 'sales');

    // تبديل حالة المستخدم (تعطيل ثم تفعيل)
    $deactivated = $userService->toggleStatus($newUser['id'], $decoded['user_id']);
    assertTest("تعطيل حساب المستخدم (toggleStatus -> 0)", $deactivated === 0);

    // التحقق من منع تسجيل الدخول للمستخدم المعطل
    try {
        $authService->login($testUsername, 'Pharma@1234');
        assertTest("منع تسجيل دخول المستخدم المعطل", false);
    } catch (\Exception $e) {
        assertTest("منع تسجيل دخول المستخدم المعطل", true, $e->getMessage());
    }

    // إعادة التفعيل
    $reactivated = $userService->toggleStatus($newUser['id'], $decoded['user_id']);
    assertTest("إعادة تفعيل الحساب (toggleStatus -> 1)", $reactivated === 1);

} catch (\Exception $e) {
    assertTest("إنشاء وإدارة المستخدم الجديد", false, $e->getMessage());
}

// 7. اختبار الأدوار والصلاحيات
$roles = $roleService->getAllRoles();
assertTest("استرجاع قائمة الأدوار", count($roles) >= 6, "Total: " . count($roles));

$permissions = $roleService->getAllPermissionsGrouped();
assertTest("استرجاع الصلاحيات مجمعة حسب الوحدة", count($permissions) >= 6, "Modules: " . implode(', ', array_keys($permissions)));

// 8. فحص تسجيل العمليات في سجل التدقيق (Audit Logs)
$auditStmt = \Core\Database::pdo()->query("SELECT COUNT(*) FROM `audit_logs` WHERE `action` IN ('login', 'create', 'toggle_status')");
$auditCount = (int)$auditStmt->fetchColumn();
assertTest("توثيق العمليات الحساسة في سجل التدقيق (audit_logs)", $auditCount >= 3, "Total audit records: {$auditCount}");

echo "-----------------------------------------------------\n";
echo "النتيجة النهائية: {$passCount} نجاح | {$failCount} فشل\n";
echo "=====================================================\n";

if ($failCount > 0) {
    exit(1);
}
