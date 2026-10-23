<?php
/**
 * أداة تهيئة وبناء قاعدة البيانات التلقائية — بوكر فارما (Phase 3 Migration Script)
 * تدعم التشغيل عبر سطر الأوامر (CLI) أو عبر المتصفح (Web).
 */

header('Content-Type: text/plain; charset=UTF-8');

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_DATABASE') ?: 'poker_pharma';
$dbUser = getenv('DB_USERNAME') ?: 'root';
$dbPass = getenv('DB_PASSWORD') ?: '';

echo "=====================================================\n";
echo "  نظام إدارة أعمال بوكر فارما — تهيئة قاعدة البيانات  \n";
echo "=====================================================\n";
echo "المخدم: $dbHost:$dbPort\n";
echo "قاعدة البيانات: $dbName\n";
echo "المستخدم: $dbUser\n";
echo "الوقت: " . date('Y-m-d H:i:s') . "\n";
echo "-----------------------------------------------------\n";

try {
    // 1. الاتصال المبدئي بدون اختيار قاعدة البيانات لإنشائها إن لم تكن موجودة
    $pdoRoot = new PDO(
        "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "[1/4] جاري إنشاء قاعدة البيانات `{$dbName}` إذا لم تكن موجودة...\n";
    $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    echo "  -> تم إنشاء / التأكد من وجود قاعدة البيانات بنجاح.\n\n";

    // 2. الاتصال بقاعدة البيانات المحددة
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );

    // 3. قراءة وتنفيذ ملف schema.sql
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("ملف المخطط غير موجود: {$schemaFile}");
    }
    echo "[2/4] جاري تنفيذ ملف المخطط الهيكلي (schema.sql)...\n";
    $schemaSql = file_get_contents($schemaFile);
    $pdo->exec($schemaSql);
    echo "  -> تم إنشاء كافة الجداول والقيود بنجاح.\n\n";

    // 4. قراءة وتنفيذ ملف seeds.sql
    $seedsFile = __DIR__ . '/seeds.sql';
    if (!file_exists($seedsFile)) {
        throw new RuntimeException("ملف البذور غير موجود: {$seedsFile}");
    }
    echo "[3/4] جاري إدراج البيانات الافتراضية الأولية (seeds.sql)...\n";
    $seedsSql = file_get_contents($seedsFile);
    $pdo->exec($seedsSql);
    echo "  -> تم إدراج الأدوار، الصلاحيات، المستخدمين الافتراضيين، المخازن والإعدادات بنجاح.\n\n";

    // 5. فحص والتحقق من الجداول المُنشأة
    echo "[4/4] فحص حالة الجداول وتأكيد التثبيت:\n";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "  إجمالي الجداول المنشأة: " . count($tables) . " جدولاً.\n";
    echo "  ---------------------------------------------------\n";
    foreach ($tables as $index => $table) {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
        $rowCount = $countStmt->fetchColumn();
        printf("  [%02d] %-30s | السجلات: %d\n", $index + 1, $table, $rowCount);
    }

    echo "-----------------------------------------------------\n";
    echo "تهانينا! تمت تهيئة قاعدة بيانات بوكر فارما بنجاح 100%.\n";
    echo "المستخدم الافتراضي: admin\n";
    echo "كلمة المرور الافتراضية: Admin@123456\n";
    echo "=====================================================\n";

} catch (PDOException $e) {
    echo "\n[خطأ في قاعدة البيانات PDO]: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "\n[خطأ عام]: " . $e->getMessage() . "\n";
    exit(1);
}
