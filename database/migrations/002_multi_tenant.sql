-- ============================================================
-- 002_multi_tenant.sql
-- Phase 2: introduce `salons` and tenant-scope every business table.
--
-- Safe to re-run: each block checks information_schema first.
-- MariaDB auto-commits DDL, so do not wrap this in a transaction.
-- ============================================================

SET @old_fk_checks = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. Tenant root
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `salons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_name` varchar(150) NOT NULL,
  `owner_first_name` varchar(100) DEFAULT NULL,
  `owner_last_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'Africa/Addis_Ababa',
  `currency` char(3) NOT NULL DEFAULT 'ETB',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `setup_completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_salons_name` (`salon_name`),
  KEY `idx_salons_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Seed the default salon from the legacy global settings row
--    (id 1 keeps existing references valid)
-- ------------------------------------------------------------
INSERT INTO `salons` (`id`, `salon_name`, `owner_first_name`, `owner_last_name`, `setup_completed_at`)
SELECT 1, `salon_name`, `owner_first_name`, `owner_last_name`, `setup_completed_at`
  FROM `salon_settings`
 WHERE `id` = 1
   AND NOT EXISTS (SELECT 1 FROM `salons` WHERE `id` = 1)
LIMIT 1;

-- ------------------------------------------------------------
-- 3. Add + backfill salon_id on every tenant-scoped table
-- ------------------------------------------------------------
-- users (users)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `users` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- salon_settings (salon_settings)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salon_settings' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `salon_settings` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- business_hours (business_hours)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_hours' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `business_hours` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- business_breaks (business_breaks)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_breaks' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `business_breaks` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- settings (settings)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `settings` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- holidays (holidays)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'holidays' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `holidays` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- service_categories (service_categories)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_categories' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `service_categories` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- service_subcategories (service_subcategories)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_subcategories' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `service_subcategories` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- services (services)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `services` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- staff (staff)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- staff_working_hours (staff_working_hours)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_working_hours' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff_working_hours` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- staff_breaks (staff_breaks)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_breaks' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff_breaks` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- staff_services (staff_services)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_services' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff_services` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- customers (customers)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `customers` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- appointments (appointments)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `appointments` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- appointment_services (appointment_services)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointment_services' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `appointment_services` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- waitlist (waitlist)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'waitlist' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `waitlist` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- invoices (invoices)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `invoices` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- payments (payments)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `payments` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- suppliers (suppliers)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `suppliers` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- inventory_products (inventory_products)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_products' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `inventory_products` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- purchases (purchases)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `purchases` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- product_sales (product_sales)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_sales' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `product_sales` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- payroll (payroll)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `payroll` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- loyalty_ledger (loyalty_ledger)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loyalty_ledger' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `loyalty_ledger` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- notifications (notifications)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `notifications` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- audit_logs (audit_logs)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND COLUMN_NAME = 'salon_id');
SET @sql := IF(@has = 0,
  'ALTER TABLE `audit_logs` ADD COLUMN `salon_id` int(11) NULL DEFAULT NULL AFTER `id`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill: every pre-existing row belongs to the default salon.
UPDATE `users` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `salon_settings` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `business_hours` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `business_breaks` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `settings` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `holidays` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `service_categories` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `service_subcategories` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `services` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `staff` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `staff_working_hours` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `staff_breaks` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `staff_services` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `customers` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `appointments` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `appointment_services` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `waitlist` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `invoices` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `payments` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `suppliers` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `inventory_products` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `purchases` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `product_sales` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `payroll` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `loyalty_ledger` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `notifications` SET `salon_id` = 1 WHERE `salon_id` IS NULL;
UPDATE `audit_logs` SET `salon_id` = 1 WHERE `salon_id` IS NULL;

-- ------------------------------------------------------------
-- 4. Tighten to NOT NULL, index, and add the foreign key
-- ------------------------------------------------------------
-- users (users)
SET @nulls := (SELECT COUNT(*) FROM `users` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `users` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `users` ADD KEY `idx_users_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `users` ADD CONSTRAINT `fk_users_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- salon_settings (salon_settings)
SET @nulls := (SELECT COUNT(*) FROM `salon_settings` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `salon_settings` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salon_settings' AND INDEX_NAME = 'idx_salon_settings_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `salon_settings` ADD KEY `idx_salon_settings_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'salon_settings' AND CONSTRAINT_NAME = 'fk_salon_settings_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `salon_settings` ADD CONSTRAINT `fk_salon_settings_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- One legacy mirror row per salon. Without a UNIQUE key here the profile
-- upsert in salon_profile_save() silently appended a duplicate row on every
-- wizard save instead of updating the existing one.
SET @dups := (SELECT COUNT(*) FROM (
                  SELECT `salon_id` FROM `salon_settings`
                   GROUP BY `salon_id` HAVING COUNT(*) > 1
                 ) d);
SET @sql := IF(@dups > 0,
  'DELETE s1 FROM `salon_settings` s1
     JOIN `salon_settings` s2
       ON s1.salon_id = s2.salon_id AND s1.id > s2.id', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salon_settings'
                 AND INDEX_NAME = 'uq_salon_settings_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `salon_settings` ADD UNIQUE KEY `uq_salon_settings_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- business_hours (business_hours)
SET @nulls := (SELECT COUNT(*) FROM `business_hours` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `business_hours` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_hours' AND INDEX_NAME = 'idx_business_hours_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `business_hours` ADD KEY `idx_business_hours_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'business_hours' AND CONSTRAINT_NAME = 'fk_business_hours_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `business_hours` ADD CONSTRAINT `fk_business_hours_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- business_breaks (business_breaks)
SET @nulls := (SELECT COUNT(*) FROM `business_breaks` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `business_breaks` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_breaks' AND INDEX_NAME = 'idx_business_breaks_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `business_breaks` ADD KEY `idx_business_breaks_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'business_breaks' AND CONSTRAINT_NAME = 'fk_business_breaks_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `business_breaks` ADD CONSTRAINT `fk_business_breaks_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- settings (settings)
SET @nulls := (SELECT COUNT(*) FROM `settings` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `settings` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND INDEX_NAME = 'idx_settings_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `settings` ADD KEY `idx_settings_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND CONSTRAINT_NAME = 'fk_settings_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `settings` ADD CONSTRAINT `fk_settings_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- holidays (holidays)
SET @nulls := (SELECT COUNT(*) FROM `holidays` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `holidays` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'holidays' AND INDEX_NAME = 'idx_holidays_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `holidays` ADD KEY `idx_holidays_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'holidays' AND CONSTRAINT_NAME = 'fk_holidays_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `holidays` ADD CONSTRAINT `fk_holidays_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- service_categories (service_categories)
SET @nulls := (SELECT COUNT(*) FROM `service_categories` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `service_categories` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_categories' AND INDEX_NAME = 'idx_service_categories_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `service_categories` ADD KEY `idx_service_categories_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'service_categories' AND CONSTRAINT_NAME = 'fk_service_categories_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `service_categories` ADD CONSTRAINT `fk_service_categories_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- service_subcategories (service_subcategories)
SET @nulls := (SELECT COUNT(*) FROM `service_subcategories` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `service_subcategories` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_subcategories' AND INDEX_NAME = 'idx_service_subcategories_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `service_subcategories` ADD KEY `idx_service_subcategories_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'service_subcategories' AND CONSTRAINT_NAME = 'fk_service_subcategories_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `service_subcategories` ADD CONSTRAINT `fk_service_subcategories_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- services (services)
SET @nulls := (SELECT COUNT(*) FROM `services` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `services` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND INDEX_NAME = 'idx_services_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `services` ADD KEY `idx_services_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND CONSTRAINT_NAME = 'fk_services_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `services` ADD CONSTRAINT `fk_services_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- staff (staff)
SET @nulls := (SELECT COUNT(*) FROM `staff` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `staff` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND INDEX_NAME = 'idx_staff_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff` ADD KEY `idx_staff_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND CONSTRAINT_NAME = 'fk_staff_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff` ADD CONSTRAINT `fk_staff_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- staff_working_hours (staff_working_hours)
SET @nulls := (SELECT COUNT(*) FROM `staff_working_hours` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `staff_working_hours` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_working_hours' AND INDEX_NAME = 'idx_staff_working_hours_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff_working_hours` ADD KEY `idx_staff_working_hours_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_working_hours' AND CONSTRAINT_NAME = 'fk_staff_working_hours_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff_working_hours` ADD CONSTRAINT `fk_staff_working_hours_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- staff_breaks (staff_breaks)
SET @nulls := (SELECT COUNT(*) FROM `staff_breaks` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `staff_breaks` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_breaks' AND INDEX_NAME = 'idx_staff_breaks_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff_breaks` ADD KEY `idx_staff_breaks_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_breaks' AND CONSTRAINT_NAME = 'fk_staff_breaks_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff_breaks` ADD CONSTRAINT `fk_staff_breaks_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- staff_services (staff_services)
SET @nulls := (SELECT COUNT(*) FROM `staff_services` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `staff_services` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_services' AND INDEX_NAME = 'idx_staff_services_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff_services` ADD KEY `idx_staff_services_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_services' AND CONSTRAINT_NAME = 'fk_staff_services_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff_services` ADD CONSTRAINT `fk_staff_services_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- customers (customers)
SET @nulls := (SELECT COUNT(*) FROM `customers` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `customers` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND INDEX_NAME = 'idx_customers_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `customers` ADD KEY `idx_customers_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND CONSTRAINT_NAME = 'fk_customers_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `customers` ADD CONSTRAINT `fk_customers_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- appointments (appointments)
SET @nulls := (SELECT COUNT(*) FROM `appointments` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `appointments` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_appointments_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `appointments` ADD KEY `idx_appointments_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND CONSTRAINT_NAME = 'fk_appointments_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `appointments` ADD CONSTRAINT `fk_appointments_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- appointment_services (appointment_services)
SET @nulls := (SELECT COUNT(*) FROM `appointment_services` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `appointment_services` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointment_services' AND INDEX_NAME = 'idx_appointment_services_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `appointment_services` ADD KEY `idx_appointment_services_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'appointment_services' AND CONSTRAINT_NAME = 'fk_appointment_services_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `appointment_services` ADD CONSTRAINT `fk_appointment_services_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- waitlist (waitlist)
SET @nulls := (SELECT COUNT(*) FROM `waitlist` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `waitlist` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'waitlist' AND INDEX_NAME = 'idx_waitlist_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `waitlist` ADD KEY `idx_waitlist_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'waitlist' AND CONSTRAINT_NAME = 'fk_waitlist_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `waitlist` ADD CONSTRAINT `fk_waitlist_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- invoices (invoices)
SET @nulls := (SELECT COUNT(*) FROM `invoices` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `invoices` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND INDEX_NAME = 'idx_invoices_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `invoices` ADD KEY `idx_invoices_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND CONSTRAINT_NAME = 'fk_invoices_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `invoices` ADD CONSTRAINT `fk_invoices_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- payments (payments)
SET @nulls := (SELECT COUNT(*) FROM `payments` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `payments` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND INDEX_NAME = 'idx_payments_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `payments` ADD KEY `idx_payments_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND CONSTRAINT_NAME = 'fk_payments_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `payments` ADD CONSTRAINT `fk_payments_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- suppliers (suppliers)
SET @nulls := (SELECT COUNT(*) FROM `suppliers` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `suppliers` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND INDEX_NAME = 'idx_suppliers_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `suppliers` ADD KEY `idx_suppliers_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND CONSTRAINT_NAME = 'fk_suppliers_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `suppliers` ADD CONSTRAINT `fk_suppliers_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- inventory_products (inventory_products)
SET @nulls := (SELECT COUNT(*) FROM `inventory_products` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `inventory_products` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_products' AND INDEX_NAME = 'idx_inventory_products_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `inventory_products` ADD KEY `idx_inventory_products_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_products' AND CONSTRAINT_NAME = 'fk_inventory_products_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `inventory_products` ADD CONSTRAINT `fk_inventory_products_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- purchases (purchases)
SET @nulls := (SELECT COUNT(*) FROM `purchases` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `purchases` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND INDEX_NAME = 'idx_purchases_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `purchases` ADD KEY `idx_purchases_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND CONSTRAINT_NAME = 'fk_purchases_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `purchases` ADD CONSTRAINT `fk_purchases_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- product_sales (product_sales)
SET @nulls := (SELECT COUNT(*) FROM `product_sales` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `product_sales` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_sales' AND INDEX_NAME = 'idx_product_sales_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `product_sales` ADD KEY `idx_product_sales_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'product_sales' AND CONSTRAINT_NAME = 'fk_product_sales_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `product_sales` ADD CONSTRAINT `fk_product_sales_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- payroll (payroll)
SET @nulls := (SELECT COUNT(*) FROM `payroll` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `payroll` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND INDEX_NAME = 'idx_payroll_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `payroll` ADD KEY `idx_payroll_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll' AND CONSTRAINT_NAME = 'fk_payroll_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `payroll` ADD CONSTRAINT `fk_payroll_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- loyalty_ledger (loyalty_ledger)
SET @nulls := (SELECT COUNT(*) FROM `loyalty_ledger` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `loyalty_ledger` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loyalty_ledger' AND INDEX_NAME = 'idx_loyalty_ledger_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `loyalty_ledger` ADD KEY `idx_loyalty_ledger_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'loyalty_ledger' AND CONSTRAINT_NAME = 'fk_loyalty_ledger_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `loyalty_ledger` ADD CONSTRAINT `fk_loyalty_ledger_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- notifications (notifications)
SET @nulls := (SELECT COUNT(*) FROM `notifications` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `notifications` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notifications_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `notifications` ADD KEY `idx_notifications_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND CONSTRAINT_NAME = 'fk_notifications_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `notifications` ADD CONSTRAINT `fk_notifications_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- audit_logs (audit_logs)
SET @nulls := (SELECT COUNT(*) FROM `audit_logs` WHERE `salon_id` IS NULL);
SET @sql := IF(@nulls = 0,
  'ALTER TABLE `audit_logs` MODIFY COLUMN `salon_id` int(11) NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND INDEX_NAME = 'idx_audit_logs_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `audit_logs` ADD KEY `idx_audit_logs_salon` (`salon_id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND CONSTRAINT_NAME = 'fk_audit_logs_salon');
SET @sql := IF(@has = 0,
  'ALTER TABLE `audit_logs` ADD CONSTRAINT `fk_audit_logs_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 5. Convert global UNIQUE keys to per-salon UNIQUE keys
--    Without this a second salon cannot own its own Monday hours
--    or its own category named "Hair".
-- ------------------------------------------------------------
-- business_hours: uq_business_hours_day -> (salon_id,day_of_week)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_hours' AND INDEX_NAME = 'uq_business_hours_day');
SET @sql := IF(@has > 0, 'ALTER TABLE `business_hours` DROP INDEX `uq_business_hours_day`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_hours' AND INDEX_NAME = 'uq_business_hours_day');
SET @sql := IF(@has = 0,
  'ALTER TABLE `business_hours` ADD UNIQUE KEY `uq_business_hours_day` (salon_id,day_of_week)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- service_categories: uq_cat_name -> (salon_id,name)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_categories' AND INDEX_NAME = 'uq_cat_name');
SET @sql := IF(@has > 0, 'ALTER TABLE `service_categories` DROP INDEX `uq_cat_name`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_categories' AND INDEX_NAME = 'uq_cat_name');
SET @sql := IF(@has = 0,
  'ALTER TABLE `service_categories` ADD UNIQUE KEY `uq_cat_name` (salon_id,name)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- service_subcategories: uq_subcat_cat_name -> (salon_id,category_id,name)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_subcategories' AND INDEX_NAME = 'uq_subcat_cat_name');
SET @sql := IF(@has > 0, 'ALTER TABLE `service_subcategories` DROP INDEX `uq_subcat_cat_name`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_subcategories' AND INDEX_NAME = 'uq_subcat_cat_name');
SET @sql := IF(@has = 0,
  'ALTER TABLE `service_subcategories` ADD UNIQUE KEY `uq_subcat_cat_name` (salon_id,category_id,name)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- settings: uq_setting_key -> (salon_id,setting_key)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND INDEX_NAME = 'uq_setting_key');
SET @sql := IF(@has > 0, 'ALTER TABLE `settings` DROP INDEX `uq_setting_key`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND INDEX_NAME = 'uq_setting_key');
SET @sql := IF(@has = 0,
  'ALTER TABLE `settings` ADD UNIQUE KEY `uq_setting_key` (salon_id,setting_key)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- inventory_products: uq_prod_sku -> (salon_id,sku)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_products' AND INDEX_NAME = 'uq_prod_sku');
SET @sql := IF(@has > 0, 'ALTER TABLE `inventory_products` DROP INDEX `uq_prod_sku`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_products' AND INDEX_NAME = 'uq_prod_sku');
SET @sql := IF(@has = 0,
  'ALTER TABLE `inventory_products` ADD UNIQUE KEY `uq_prod_sku` (salon_id,sku)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- invoices: uq_inv_number -> (salon_id,invoice_number)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND INDEX_NAME = 'uq_inv_number');
SET @sql := IF(@has > 0, 'ALTER TABLE `invoices` DROP INDEX `uq_inv_number`', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND INDEX_NAME = 'uq_inv_number');
SET @sql := IF(@has = 0,
  'ALTER TABLE `invoices` ADD UNIQUE KEY `uq_inv_number` (salon_id,invoice_number)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 6. Composite indexes for tenant-scoped hot paths
-- ------------------------------------------------------------
-- business_hours (idx_bh_salon_day)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_hours' AND INDEX_NAME = 'idx_bh_salon_day');
SET @sql := IF(@has = 0,
  'ALTER TABLE `business_hours` ADD KEY `idx_bh_salon_day` (salon_id,day_of_week)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- appointments (idx_appt_salon_date)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_appt_salon_date');
SET @sql := IF(@has = 0,
  'ALTER TABLE `appointments` ADD KEY `idx_appt_salon_date` (salon_id,appointment_date)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- appointments (idx_appt_salon_staff)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_appt_salon_staff');
SET @sql := IF(@has = 0,
  'ALTER TABLE `appointments` ADD KEY `idx_appt_salon_staff` (salon_id,staff_id,appointment_date)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- appointments (idx_appt_salon_cust)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_appt_salon_cust');
SET @sql := IF(@has = 0,
  'ALTER TABLE `appointments` ADD KEY `idx_appt_salon_cust` (salon_id,customer_id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- customers (idx_cust_salon_name)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND INDEX_NAME = 'idx_cust_salon_name');
SET @sql := IF(@has = 0,
  'ALTER TABLE `customers` ADD KEY `idx_cust_salon_name` (salon_id,last_name,first_name)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- staff (idx_staff_salon_list)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND INDEX_NAME = 'idx_staff_salon_list');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff` ADD KEY `idx_staff_salon_list` (salon_id,status)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- services (idx_svc_salon_list)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND INDEX_NAME = 'idx_svc_salon_list');
SET @sql := IF(@has = 0,
  'ALTER TABLE `services` ADD KEY `idx_svc_salon_list` (salon_id,status,category_id,sort_order)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- invoices (idx_inv_salon_cust)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND INDEX_NAME = 'idx_inv_salon_cust');
SET @sql := IF(@has = 0,
  'ALTER TABLE `invoices` ADD KEY `idx_inv_salon_cust` (salon_id,customer_id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- staff_working_hours (idx_swh_salon_staff)
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_working_hours' AND INDEX_NAME = 'idx_swh_salon_staff');
SET @sql := IF(@has = 0,
  'ALTER TABLE `staff_working_hours` ADD KEY `idx_swh_salon_staff` (salon_id,staff_id)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET FOREIGN_KEY_CHECKS = @old_fk_checks;

-- End of 002_multi_tenant.sql
