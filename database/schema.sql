-- =============================================================================
-- نظام إدارة أعمال شركة بوكر فارما للتجارة والاستثمار المحدودة (MVP v0.1)
-- ملف مخطط قاعدة البيانات (Database Schema - Phase 3)
-- المتوافق مع: MySQL 8.x / MariaDB 10.x
-- محرك الجداول: InnoDB | الترميز: utf8mb4_unicode_ci
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+02:00";

-- -----------------------------------------------------------------------------
-- 1. جدول الأدوار (Roles)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(50) NOT NULL,
    `display_name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. جدول الصلاحيات (Permissions)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(100) NOT NULL,
    `display_name` VARCHAR(150) NOT NULL,
    `module` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_permissions_code` (`code`),
    KEY `idx_permissions_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. جدول ربط الأدوار بالصلاحيات (Role Permissions)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
    `role_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    KEY `idx_rp_permission` (`permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. جدول المستخدمين (Users)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(50) NOT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NULL,
    `phone` VARCHAR(30) NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_username` (`username`),
    UNIQUE KEY `uk_users_email` (`email`),
    KEY `idx_users_role` (`role_id`),
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. جدول سجل العمليات الحساسة (Audit Logs)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NULL,
    `action` VARCHAR(50) NOT NULL,
    `module` VARCHAR(50) NOT NULL,
    `record_id` BIGINT UNSIGNED NULL,
    `old_values` LONGTEXT NULL,
    `new_values` LONGTEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_user` (`user_id`),
    KEY `idx_audit_module_record` (`module`, `record_id`),
    KEY `idx_audit_created_at` (`created_at`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. جدول إعدادات النظام العامة (System Settings)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `description` VARCHAR(255) NULL,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_settings_key` (`setting_key`),
    KEY `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. جدول فئات الأدوية والمستلزمات (Categories)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. جدول الأصناف والأدوية (Products)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` INT UNSIGNED NOT NULL,
    `item_code` VARCHAR(50) NOT NULL,
    `trade_name` VARCHAR(200) NOT NULL,
    `generic_name` VARCHAR(200) NULL,
    `dosage_form` VARCHAR(50) NULL,
    `strength` VARCHAR(50) NULL,
    `manufacturer` VARCHAR(150) NULL,
    `origin_country` VARCHAR(100) NULL,
    `barcode` VARCHAR(100) NULL,
    `main_unit` VARCHAR(50) NOT NULL DEFAULT 'عبوة',
    `purchase_price` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    `selling_price` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    `wholesale_price` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    `retail_price` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    `min_selling_price` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    `min_stock_alert` INT NOT NULL DEFAULT 10,
    `image_url` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_products_item_code` (`item_code`),
    KEY `idx_products_category` (`category_id`),
    KEY `idx_products_barcode` (`barcode`),
    KEY `idx_products_names` (`trade_name`, `generic_name`),
    CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. جدول التشغيلات وتواريخ الصلاحية (Batches)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `batches`;
CREATE TABLE `batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `batch_number` VARCHAR(100) NOT NULL,
    `production_date` DATE NULL,
    `expiry_date` DATE NOT NULL,
    `purchase_price` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    `selling_price` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    `status` ENUM('active', 'quarantined', 'expired', 'depleted') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_product_batch` (`product_id`, `batch_number`),
    KEY `idx_batches_expiry` (`expiry_date`),
    KEY `idx_batches_status` (`status`),
    CONSTRAINT `fk_batches_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. جدول المخازن (Warehouses)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `warehouses`;
CREATE TABLE `warehouses` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(20) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `location` VARCHAR(200) NULL,
    `keeper_id` BIGINT UNSIGNED NULL,
    `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_warehouses_code` (`code`),
    KEY `idx_warehouses_keeper` (`keeper_id`),
    CONSTRAINT `fk_warehouses_keeper` FOREIGN KEY (`keeper_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. جدول أرصدة التشغيلات بكل مخزن (Warehouse Batches Stock)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `warehouse_batches`;
CREATE TABLE `warehouse_batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `reserved_quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_warehouse_batch` (`warehouse_id`, `batch_id`),
    KEY `idx_wb_product` (`product_id`),
    KEY `idx_wb_warehouse` (`warehouse_id`),
    CONSTRAINT `fk_wb_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_wb_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_wb_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. جدول التحويلات بين المخازن (Stock Transfers)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_transfers`;
CREATE TABLE `stock_transfers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `transfer_number` VARCHAR(50) NOT NULL,
    `from_warehouse_id` INT UNSIGNED NOT NULL,
    `to_warehouse_id` INT UNSIGNED NOT NULL,
    `transfer_date` DATE NOT NULL,
    `status` ENUM('pending', 'approved', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `notes` TEXT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `approved_by` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_transfer_number` (`transfer_number`),
    KEY `idx_transfer_from` (`from_warehouse_id`),
    KEY `idx_transfer_to` (`to_warehouse_id`),
    KEY `idx_transfer_date` (`transfer_date`),
    CONSTRAINT `fk_transfer_from` FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_transfer_to` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_transfer_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_transfer_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13. جدول بنود التحويل بين المخازن (Stock Transfer Items)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_transfer_items`;
CREATE TABLE `stock_transfer_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `transfer_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `notes` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ti_transfer` (`transfer_id`),
    KEY `idx_ti_product` (`product_id`),
    KEY `idx_ti_batch` (`batch_id`),
    CONSTRAINT `fk_ti_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ti_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ti_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 14. جدول الجرد والتسويات المخزنية (Stock Adjustments)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_adjustments`;
CREATE TABLE `stock_adjustments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `adjustment_number` VARCHAR(50) NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `adjustment_date` DATE NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `status` ENUM('draft', 'approved', 'cancelled') NOT NULL DEFAULT 'draft',
    `created_by` BIGINT UNSIGNED NOT NULL,
    `approved_by` BIGINT UNSIGNED NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_adjustment_number` (`adjustment_number`),
    KEY `idx_adj_warehouse` (`warehouse_id`),
    KEY `idx_adj_date` (`adjustment_date`),
    CONSTRAINT `fk_adj_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_adj_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_adj_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 15. جدول بنود التسوية والجرد (Stock Adjustment Items)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_adjustment_items`;
CREATE TABLE `stock_adjustment_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `adjustment_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `system_qty` DECIMAL(12,2) NOT NULL,
    `actual_qty` DECIMAL(12,2) NOT NULL,
    `difference_qty` DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ai_adjustment` (`adjustment_id`),
    KEY `idx_ai_product` (`product_id`),
    KEY `idx_ai_batch` (`batch_id`),
    CONSTRAINT `fk_ai_adjustment` FOREIGN KEY (`adjustment_id`) REFERENCES `stock_adjustments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ai_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ai_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 16. جدول سجل حركة المخزون الشامل (Stock Transactions Log)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_transactions`;
CREATE TABLE `stock_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `transaction_type` ENUM('purchase', 'purchase_return', 'sale', 'sale_return', 'transfer_in', 'transfer_out', 'adjustment_in', 'adjustment_out') NOT NULL,
    `reference_table` VARCHAR(50) NOT NULL,
    `reference_id` BIGINT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `qty_before` DECIMAL(12,2) NOT NULL,
    `qty_change` DECIMAL(12,2) NOT NULL,
    `qty_after` DECIMAL(12,2) NOT NULL,
    `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    `notes` VARCHAR(255) NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_st_reference` (`reference_table`, `reference_id`),
    KEY `idx_st_warehouse_product` (`warehouse_id`, `product_id`),
    KEY `idx_st_batch` (`batch_id`),
    KEY `idx_st_created_at` (`created_at`),
    CONSTRAINT `fk_st_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_st_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_st_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_st_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 17. جدول الموردين (Suppliers)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `company_name` VARCHAR(150) NULL,
    `phone` VARCHAR(30) NOT NULL,
    `alt_phone` VARCHAR(30) NULL,
    `email` VARCHAR(150) NULL,
    `address` VARCHAR(255) NULL,
    `tax_number` VARCHAR(50) NULL,
    `balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_suppliers_phone` (`phone`),
    KEY `idx_suppliers_balance` (`balance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 18. جدول فواتير المشتريات (Purchase Invoices)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_invoices`;
CREATE TABLE `purchase_invoices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_number` VARCHAR(50) NOT NULL,
    `supplier_invoice_no` VARCHAR(50) NULL,
    `supplier_id` BIGINT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `invoice_date` DATE NOT NULL,
    `payment_type` ENUM('cash', 'credit', 'bank', 'partial') NOT NULL DEFAULT 'credit',
    `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `remaining_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft', 'received', 'cancelled') NOT NULL DEFAULT 'draft',
    `notes` TEXT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_purchase_inv_number` (`invoice_number`),
    KEY `idx_pi_supplier` (`supplier_id`),
    KEY `idx_pi_warehouse` (`warehouse_id`),
    KEY `idx_pi_date` (`invoice_date`),
    KEY `idx_pi_status` (`status`),
    CONSTRAINT `fk_pi_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pi_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pi_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 19. جدول بنود فاتورة المشتريات (Purchase Invoice Items)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_invoice_items`;
CREATE TABLE `purchase_invoice_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `purchase_invoice_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `batch_number` VARCHAR(100) NOT NULL,
    `production_date` DATE NULL,
    `expiry_date` DATE NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `unit_price` DECIMAL(14,4) NOT NULL,
    `total_price` DECIMAL(14,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pii_invoice` (`purchase_invoice_id`),
    KEY `idx_pii_product` (`product_id`),
    KEY `idx_pii_batch` (`batch_id`),
    CONSTRAINT `fk_pii_invoice` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pii_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pii_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 20. جدول مرتجع المشتريات (Purchase Returns)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_returns`;
CREATE TABLE `purchase_returns` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `return_number` VARCHAR(50) NOT NULL,
    `purchase_invoice_id` BIGINT UNSIGNED NULL,
    `supplier_id` BIGINT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `return_date` DATE NOT NULL,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `refund_status` ENUM('deduct_from_balance', 'cash_refund') NOT NULL DEFAULT 'deduct_from_balance',
    `notes` TEXT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_purchase_return_number` (`return_number`),
    KEY `idx_pr_supplier` (`supplier_id`),
    KEY `idx_pr_warehouse` (`warehouse_id`),
    KEY `idx_pr_date` (`return_date`),
    CONSTRAINT `fk_pr_invoice` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_pr_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pr_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 21. جدول بنود مرتجع المشتريات (Purchase Return Items)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `purchase_return_items`;
CREATE TABLE `purchase_return_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `purchase_return_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `unit_price` DECIMAL(14,4) NOT NULL,
    `total_price` DECIMAL(14,2) NOT NULL,
    `return_reason` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pri_return` (`purchase_return_id`),
    KEY `idx_pri_product` (`product_id`),
    KEY `idx_pri_batch` (`batch_id`),
    CONSTRAINT `fk_pri_return` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pri_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 22. جدول العملاء - صيدليات ومراكز طبية (Customers)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `pharmacy_name` VARCHAR(150) NULL,
    `license_number` VARCHAR(100) NULL,
    `phone` VARCHAR(30) NOT NULL,
    `alt_phone` VARCHAR(30) NULL,
    `email` VARCHAR(150) NULL,
    `address` VARCHAR(255) NULL,
    `city` VARCHAR(100) NULL,
    `credit_limit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customers_phone` (`phone`),
    KEY `idx_customers_pharmacy` (`pharmacy_name`),
    KEY `idx_customers_balance` (`balance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 23. جدول فواتير المبيعات (Sales Invoices)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `sales_invoices`;
CREATE TABLE `sales_invoices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_number` VARCHAR(50) NOT NULL,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `invoice_date` DATE NOT NULL,
    `payment_type` ENUM('cash', 'credit', 'bank', 'partial') NOT NULL DEFAULT 'cash',
    `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `remaining_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft', 'confirmed', 'cancelled') NOT NULL DEFAULT 'draft',
    `notes` TEXT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sales_inv_number` (`invoice_number`),
    KEY `idx_si_customer` (`customer_id`),
    KEY `idx_si_warehouse` (`warehouse_id`),
    KEY `idx_si_date` (`invoice_date`),
    KEY `idx_si_status` (`status`),
    CONSTRAINT `fk_si_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_si_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_si_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 24. جدول بنود فاتورة المبيعات (Sales Invoice Items)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `sales_invoice_items`;
CREATE TABLE `sales_invoice_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sales_invoice_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `unit_price` DECIMAL(14,4) NOT NULL,
    `discount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total_price` DECIMAL(14,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sii_invoice` (`sales_invoice_id`),
    KEY `idx_sii_product` (`product_id`),
    KEY `idx_sii_batch` (`batch_id`),
    CONSTRAINT `fk_sii_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sii_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sii_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 25. جدول مرتجع المبيعات (Sales Returns)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `sales_returns`;
CREATE TABLE `sales_returns` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `return_number` VARCHAR(50) NOT NULL,
    `sales_invoice_id` BIGINT UNSIGNED NULL,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `return_date` DATE NOT NULL,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `refund_status` ENUM('deduct_from_balance', 'cash_refund') NOT NULL DEFAULT 'deduct_from_balance',
    `return_to_stock_status` ENUM('valid_stock', 'quarantine_damaged') NOT NULL DEFAULT 'valid_stock',
    `notes` TEXT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sales_return_number` (`return_number`),
    KEY `idx_sr_customer` (`customer_id`),
    KEY `idx_sr_warehouse` (`warehouse_id`),
    KEY `idx_sr_date` (`return_date`),
    CONSTRAINT `fk_sr_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_sr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sr_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 26. جدول بنود مرتجع المبيعات (Sales Return Items)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `sales_return_items`;
CREATE TABLE `sales_return_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sales_return_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `unit_price` DECIMAL(14,4) NOT NULL,
    `total_price` DECIMAL(14,2) NOT NULL,
    `return_reason` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sri_return` (`sales_return_id`),
    KEY `idx_sri_product` (`product_id`),
    KEY `idx_sri_batch` (`batch_id`),
    CONSTRAINT `fk_sri_return` FOREIGN KEY (`sales_return_id`) REFERENCES `sales_returns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sri_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 27. جدول الخزائن النقدية والحسابات البنكية (Cash & Bank Accounts)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `cash_accounts`;
CREATE TABLE `cash_accounts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `account_type` ENUM('cash', 'bank') NOT NULL DEFAULT 'cash',
    `account_number` VARCHAR(50) NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'SDG',
    `current_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cash_type` (`account_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 28. جدول تصنيفات المصروفات التشغيلية (Expense Categories)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_exp_category_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 29. جدول سندات القبض والصرف والتحويل (Payment & Receipt Vouchers)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `payment_vouchers`;
CREATE TABLE `payment_vouchers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `voucher_number` VARCHAR(50) NOT NULL,
    `voucher_type` ENUM('receipt', 'payment', 'transfer') NOT NULL,
    `cash_account_id` INT UNSIGNED NOT NULL,
    `to_cash_account_id` INT UNSIGNED NULL,
    `contact_type` ENUM('customer', 'supplier', 'expense', 'other') NULL,
    `customer_id` BIGINT UNSIGNED NULL,
    `supplier_id` BIGINT UNSIGNED NULL,
    `expense_category_id` INT UNSIGNED NULL,
    `reference_invoice_type` ENUM('sales_invoice', 'purchase_invoice', 'none') NOT NULL DEFAULT 'none',
    `reference_invoice_id` BIGINT UNSIGNED NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `voucher_date` DATE NOT NULL,
    `notes` TEXT NULL,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_voucher_number` (`voucher_number`),
    KEY `idx_pv_account` (`cash_account_id`),
    KEY `idx_pv_to_account` (`to_cash_account_id`),
    KEY `idx_pv_customer` (`customer_id`),
    KEY `idx_pv_supplier` (`supplier_id`),
    KEY `idx_pv_date` (`voucher_date`),
    CONSTRAINT `fk_pv_account` FOREIGN KEY (`cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pv_to_account` FOREIGN KEY (`to_cash_account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pv_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pv_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pv_expense` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_pv_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 30. جدول كشف حساب العميل التفصيلي (Customer Ledger Transactions)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `customer_transactions`;
CREATE TABLE `customer_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `transaction_type` ENUM('invoice', 'payment', 'return', 'adjustment') NOT NULL,
    `reference_id` BIGINT UNSIGNED NOT NULL,
    `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `balance_after` DECIMAL(14,2) NOT NULL,
    `transaction_date` DATE NOT NULL,
    `notes` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ct_customer` (`customer_id`),
    KEY `idx_ct_date` (`transaction_date`),
    KEY `idx_ct_reference` (`transaction_type`, `reference_id`),
    CONSTRAINT `fk_ct_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 31. جدول كشف حساب المورد التفصيلي (Supplier Ledger Transactions)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `supplier_transactions`;
CREATE TABLE `supplier_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_id` BIGINT UNSIGNED NOT NULL,
    `transaction_type` ENUM('invoice', 'payment', 'return', 'adjustment') NOT NULL,
    `reference_id` BIGINT UNSIGNED NOT NULL,
    `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `balance_after` DECIMAL(14,2) NOT NULL,
    `transaction_date` DATE NOT NULL,
    `notes` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_spt_supplier` (`supplier_id`),
    KEY `idx_spt_date` (`transaction_date`),
    KEY `idx_spt_reference` (`transaction_type`, `reference_id`),
    CONSTRAINT `fk_spt_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 32. جدول القيود اليومية (Daily Journal Entries - Double-Entry Accounting)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `journal_entries`;
CREATE TABLE `journal_entries` (
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

-- -----------------------------------------------------------------------------
-- 33. جدول بنود القيود اليومية التفصيلية (Journal Entry Lines)
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `journal_entry_lines`;
CREATE TABLE `journal_entry_lines` (
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

SET FOREIGN_KEY_CHECKS = 1;
