<?php
/**
 * سكريبت ترقية قاعدة البيانات وإضافة الميزات المطلوبة وفق المقترح (Phase 1 & 2 Hardening)
 */

require_once __DIR__ . '/../core/Database.php';

use Core\Database;

try {
    $db = Database::getInstance()->getConnection();
    echo ">> بدء تطبيق ترقية قاعدة البيانات...\n";

    // 1. إضافة صلاحية sales.cancel إن لم تكن موجودة
    $stmt = $db->query("SELECT id FROM permissions WHERE code = 'sales.cancel'");
    $perm = $stmt->fetch();
    if (!$perm) {
        $db->exec("INSERT INTO `permissions` (`code`, `display_name`, `module`, `description`) 
                   VALUES ('sales.cancel', 'إلغاء فاتورة بيع', 'sales', 'إلغاء فاتورة مبيعات مؤكدة واسترجاع كميات المخزون وتعديل الحساب')");
        $permId = $db->lastInsertId();
        echo "   [✓] تمت إضافة صلاحية [sales.cancel] بمعرف: {$permId}\n";

        // إسناد الصلاحية لمدير النظام (1) والمدير العام (2)
        $db->exec("INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) VALUES (1, {$permId}), (2, {$permId})");
        echo "   [✓] تم ربط صلاحية الإلغاء بدور مدير النظام والمدير العام.\n";
    } else {
        echo "   [-] صلاحية [sales.cancel] موجودة مسبقاً.\n";
    }

    // 2. إضافة أعمدة wholesale_price و retail_price في جدول products
    $columns = $db->query("SHOW COLUMNS FROM `products` LIKE 'wholesale_price'")->fetchAll();
    if (empty($columns)) {
        $db->exec("ALTER TABLE `products` 
                   ADD COLUMN `wholesale_price` DECIMAL(14,4) NOT NULL DEFAULT 0.0000 AFTER `selling_price`,
                   ADD COLUMN `retail_price` DECIMAL(14,4) NOT NULL DEFAULT 0.0000 AFTER `wholesale_price`");
        $db->exec("UPDATE `products` SET `wholesale_price` = `selling_price` * 0.9, `retail_price` = `selling_price` WHERE `wholesale_price` = 0");
        echo "   [✓] تمت إضافة عمودي [wholesale_price] و [retail_price] بنجاح.\n";
    } else {
        echo "   [-] أعمدة أسعار الجملة والقطاعي موجودة مسبقاً.\n";
    }

    // 3. إنشاء جدول القيود اليومية (journal_entries)
    $db->exec("
        CREATE TABLE IF NOT EXISTS `journal_entries` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `entry_number` VARCHAR(50) NOT NULL,
            `entry_date` DATE NOT NULL,
            `description` VARCHAR(255) NOT NULL,
            `reference_type` VARCHAR(50) NULL,
            `reference_id` BIGINT UNSIGNED NULL,
            `total_debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `total_credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `is_posted` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_je_number` (`entry_number`),
            KEY `idx_je_date` (`entry_date`),
            KEY `idx_je_reference` (`reference_type`, `reference_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "   [✓] تم فحص/إنشاء جدول القيود اليومية [journal_entries].\n";

    // 4. إنشاء جدول بنود القيد اليومي (journal_entry_lines)
    $db->exec("
        CREATE TABLE IF NOT EXISTS `journal_entry_lines` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `journal_entry_id` BIGINT UNSIGNED NOT NULL,
            `account_name` VARCHAR(150) NOT NULL,
            `account_type` VARCHAR(50) NOT NULL DEFAULT 'asset',
            `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `notes` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_jel_entry` (`journal_entry_id`),
            KEY `idx_jel_account` (`account_name`),
            CONSTRAINT `fk_jel_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "   [✓] تم فحص/إنشاء جدول بنود القيود [journal_entry_lines].\n";

    echo ">> اكتملت ترقية قاعدة البيانات بنجاح تام.\n";
} catch (Exception $e) {
    echo ">> [خطأ]: " . $e->getMessage() . "\n";
    exit(1);
}
