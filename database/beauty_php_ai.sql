-- ============================================================
--  Beauty-php-ai — Goscha Software
--  Booking & Appointment Management System
--  Database : beauty_php_ai
--  Engine   : MySQL 8+ / MariaDB 10.4+
--  Charset  : utf8mb4
-- ============================================================
--
--  ####################################################################
--  #  DESTRUCTIVE FULL-INSTALL SCRIPT — READ BEFORE RUNNING           #
--  #                                                                  #
--  #  This file DROPS AND RECREATES every table in `beauty_php_ai`.   #
--  #  It also runs `USE beauty_php_ai`, so the target database is     #
--  #  chosen by THIS FILE, not by the command you type.               #
--  #                                                                  #
--  #  Loading it into a MariaDB/MySQL server that holds real data     #
--  #  DESTROYS that data irrecoverably: no undo, and binary logging  #
--  #  is usually off.                                                  #
--  #                                                                  #
--  #  To MIGRATE an existing database, use the additive script:      #
--  #      php database\migrate_service_hierarchy.php                  #
--  #  It inspects information_schema first, changes nothing it does    #
--  #  not have to, and is safe to run repeatedly.                     #
--  #                                                                  #
--  #  Only import this file into an EMPTY server, e.g.:               #
--  #      mysql -u root < database\beauty_php_ai.sql                  #
--  ####################################################################
--
--  TARGET DATABASE
--  ####################################################################
--  #  This file deliberately contains NO `CREATE DATABASE` and NO      #
--  #  `USE`, so the database is chosen by the COMMAND, not by this    #
--  #  file. That makes a test import impossible to misdirect:         #
--  #      mysql -u root beauty_php_ai_scratch < database\beauty_php_ai.sql
--  #  rebuilds the scratch database and can never touch the live one.  #
--  #                                                                  #
--  #  An earlier revision hardcoded `USE beauty_php_ai` here, which    #
--  #  silently overrode the target on the command line. A test that    #
--  #  meant to load a throwaway copy instead dropped and rebuilt the   #
--  #  live database. Do not reintroduce those two statements.          #
--  ####################################################################

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Drop tables (so the file can be re-imported safely)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `remember_tokens`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `loyalty_ledger`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `holidays`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `payroll`;
DROP TABLE IF EXISTS `product_sales`;
DROP TABLE IF EXISTS `purchases`;
DROP TABLE IF EXISTS `suppliers`;
DROP TABLE IF EXISTS `inventory_products`;
DROP TABLE IF EXISTS `invoices`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `waitlist`;
DROP TABLE IF EXISTS `appointment_services`;
DROP TABLE IF EXISTS `appointments`;
DROP TABLE IF EXISTS `staff`;
DROP TABLE IF EXISTS `services`;
DROP TABLE IF EXISTS `service_subcategories`;
DROP TABLE IF EXISTS `service_categories`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `users`;

-- ------------------------------------------------------------
-- users  (system logins: admin + receptionist only)
-- NOTE: salon staff are NOT system users — see the staff table.
-- ------------------------------------------------------------
CREATE TABLE `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `first_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','receptionist') NOT NULL DEFAULT 'admin',
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- password_resets
-- ------------------------------------------------------------
CREATE TABLE `password_resets` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pwd_token` (`token`),
  KEY `idx_pwd_user` (`user_id`),
  CONSTRAINT `fk_pwd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- remember_tokens  (remember me)
-- ------------------------------------------------------------
CREATE TABLE `remember_tokens` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rem_token` (`token`),
  KEY `idx_rem_user` (`user_id`),
  CONSTRAINT `fk_rem_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- customers  (email is OPTIONAL)
-- ------------------------------------------------------------
CREATE TABLE `customers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(255) NULL DEFAULT NULL,
  `password` VARCHAR(255) NULL DEFAULT NULL,
  `date_of_birth` DATE NULL DEFAULT NULL,
  `gender` ENUM('female','male','other') NULL DEFAULT NULL,
  `address` VARCHAR(255) NULL DEFAULT NULL,
  `skin_type` VARCHAR(100) NULL DEFAULT NULL,
  `hair_type` VARCHAR(100) NULL DEFAULT NULL,
  `allergies` TEXT NULL DEFAULT NULL,
  `notes` TEXT NULL DEFAULT NULL,
  `loyalty_points` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customers_phone` (`phone`),
  KEY `idx_customers_name` (`last_name`,`first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- staff  (salon employees — NOT system users, email is OPTIONAL)
-- ------------------------------------------------------------
CREATE TABLE `staff` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(255) NULL DEFAULT NULL,
  `date_of_birth` DATE NULL DEFAULT NULL,
  `gender` ENUM('female','male','other') NULL DEFAULT NULL,
  `address` VARCHAR(255) NULL DEFAULT NULL,
  `emergency_contact_name` VARCHAR(150) NULL DEFAULT NULL,
  `emergency_contact_phone` VARCHAR(20) NULL DEFAULT NULL,
  `salary` DECIMAL(10,2) NULL DEFAULT NULL,
  `notes` TEXT NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff_name` (`last_name`,`first_name`),
  KEY `idx_staff_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- service_categories
-- ------------------------------------------------------------
CREATE TABLE `service_categories` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_name` (`name`),
  KEY `idx_cat_status_order` (`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- service_subcategories
-- ------------------------------------------------------------
CREATE TABLE `service_subcategories` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `category_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subcat_cat_name` (`category_id`, `name`),
  KEY `idx_subcat_cat` (`category_id`),
  KEY `idx_subcat_order` (`status`,`sort_order`),
  CONSTRAINT `fk_subcat_cat` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- services
-- ------------------------------------------------------------
CREATE TABLE `services` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `category_id` INT NOT NULL,
  `subcategory_id` INT NULL DEFAULT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL DEFAULT NULL,
  `duration_minutes` INT NOT NULL DEFAULT 30,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_services_cat` (`category_id`),
  KEY `idx_services_subcat` (`subcategory_id`),
  KEY `idx_services_listing` (`status`,`category_id`,`subcategory_id`,`sort_order`),
  CONSTRAINT `fk_services_cat` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_services_subcat` FOREIGN KEY (`subcategory_id`) REFERENCES `service_subcategories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- salon_settings  (single row, id = 1: salon identity + owner name)
--   `setup_completed_at` NULL means the first-time Setup Wizard has
--   not been finished yet, so a fresh install is sent to setup.php.
--   Left EMPTY here on purpose: the wizard fills it in.
-- ------------------------------------------------------------
CREATE TABLE `salon_settings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `salon_name` VARCHAR(150) NOT NULL,
  `owner_first_name` VARCHAR(100) NOT NULL,
  `owner_last_name` VARCHAR(100) NOT NULL,
  `setup_completed_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_salon_settings_single` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- business_hours  (one row per weekday; 1 = Monday ... 7 = Sunday)
--   The salon's main weekly schedule. Seeded by the Setup Wizard,
--   edited later under Settings -> Business Hours.
-- ------------------------------------------------------------
CREATE TABLE `business_hours` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `day_of_week` TINYINT UNSIGNED NOT NULL,
  `is_open` TINYINT(1) NOT NULL DEFAULT 1,
  `opening_time` TIME NULL DEFAULT NULL,
  `closing_time` TIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_business_hours_day` (`day_of_week`),
  CONSTRAINT `ck_business_hours_day` CHECK (`day_of_week` BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- business_breaks  (salon-wide blackout periods per weekday)
-- ------------------------------------------------------------
CREATE TABLE `business_breaks` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `day_of_week` TINYINT UNSIGNED NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_business_breaks_day` (`day_of_week`),
  CONSTRAINT `ck_business_breaks_day` CHECK (`day_of_week` BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- staff_working_hours  (per-staff weekly schedule)
--   A shift must always sit INSIDE the salon hours for the same
--   day; includes/scheduling.php enforces that on save.
-- ------------------------------------------------------------
CREATE TABLE `staff_working_hours` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `staff_id` INT NOT NULL,
  `day_of_week` TINYINT UNSIGNED NOT NULL,
  `is_working` TINYINT(1) NOT NULL DEFAULT 0,
  `start_time` TIME NULL DEFAULT NULL,
  `end_time` TIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_hours_day` (`staff_id`,`day_of_week`),
  KEY `idx_staff_hours_day` (`day_of_week`),
  CONSTRAINT `fk_staff_hours_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_staff_hours_day` CHECK (`day_of_week` BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- staff_breaks  (per-staff blackout periods per weekday)
-- ------------------------------------------------------------
CREATE TABLE `staff_breaks` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `staff_id` INT NOT NULL,
  `day_of_week` TINYINT UNSIGNED NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff_breaks_staff_day` (`staff_id`,`day_of_week`),
  CONSTRAINT `fk_staff_breaks_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_staff_breaks_day` CHECK (`day_of_week` BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- staff_services  (which services a staff member performs)
--   NO rows = unrestricted. As soon as one row exists the table
--   becomes that staff member's allow-list.
-- ------------------------------------------------------------
CREATE TABLE `staff_services` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `staff_id` INT NOT NULL,
  `service_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_service` (`staff_id`,`service_id`),
  KEY `idx_staff_services_service` (`service_id`),
  CONSTRAINT `fk_staff_services_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_staff_services_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- appointments  (staff_id optionally links the appointment to a staff member)
-- ------------------------------------------------------------
CREATE TABLE `appointments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `customer_id` INT NOT NULL,
  `staff_id` INT NULL DEFAULT NULL,
  `appointment_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `status` ENUM('pending','confirmed','in_progress','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
  `notes` TEXT NULL DEFAULT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('pending','partial','paid','refunded') NOT NULL DEFAULT 'pending',
  `created_by` INT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_appt_date` (`appointment_date`),
  KEY `idx_appt_status` (`status`),
  KEY `idx_appt_customer` (`customer_id`),
  KEY `idx_appt_staff` (`staff_id`),
  KEY `idx_appt_created_by` (`created_by`),
  CONSTRAINT `fk_appt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appt_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- appointment_services  (multiple services per appointment)
-- ------------------------------------------------------------
CREATE TABLE `appointment_services` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `appointment_id` INT NOT NULL,
  `service_id` INT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `duration_minutes` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_aps_appt` (`appointment_id`),
  KEY `idx_aps_service` (`service_id`),
  CONSTRAINT `fk_aps_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aps_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- waitlist  (public booking requests + internal waitlist)
-- ------------------------------------------------------------
CREATE TABLE `waitlist` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `customer_id` INT NULL DEFAULT NULL,
  `name` VARCHAR(150) NULL DEFAULT NULL,
  `phone` VARCHAR(20) NULL DEFAULT NULL,
  `service_id` INT NULL DEFAULT NULL,
  `preferred_date` DATE NULL DEFAULT NULL,
  `preferred_time` TIME NULL DEFAULT NULL,
  `notes` TEXT NULL DEFAULT NULL,
  `status` ENUM('waiting','converted','cancelled') NOT NULL DEFAULT 'waiting',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wait_status` (`status`),
  KEY `idx_wait_customer` (`customer_id`),
  KEY `idx_wait_service` (`service_id`),
  CONSTRAINT `fk_wait_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wait_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- payments
-- ------------------------------------------------------------
CREATE TABLE `payments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `appointment_id` INT NOT NULL,
  `customer_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','bank_transfer') NOT NULL DEFAULT 'cash',
  `transaction_reference` VARCHAR(100) NULL DEFAULT NULL,
  `payment_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('completed','refunded') NOT NULL DEFAULT 'completed',
  PRIMARY KEY (`id`),
  KEY `idx_pay_appt` (`appointment_id`),
  KEY `idx_pay_customer` (`customer_id`),
  CONSTRAINT `fk_pay_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pay_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- invoices
-- ------------------------------------------------------------
CREATE TABLE `invoices` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(30) NOT NULL,
  `appointment_id` INT NULL DEFAULT NULL,
  `customer_id` INT NOT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid',
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inv_number` (`invoice_number`),
  KEY `idx_inv_appt` (`appointment_id`),
  KEY `idx_inv_customer` (`customer_id`),
  CONSTRAINT `fk_inv_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- suppliers
-- ------------------------------------------------------------
CREATE TABLE `suppliers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `contact_person` VARCHAR(100) NULL DEFAULT NULL,
  `phone` VARCHAR(20) NULL DEFAULT NULL,
  `email` VARCHAR(255) NULL DEFAULT NULL,
  `address` VARCHAR(255) NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- inventory_products
-- ------------------------------------------------------------
CREATE TABLE `inventory_products` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `sku` VARCHAR(50) NOT NULL,
  `barcode` VARCHAR(100) NULL DEFAULT NULL,
  `category` VARCHAR(100) NULL DEFAULT NULL,
  `supplier_id` INT NULL DEFAULT NULL,
  `quantity` INT NOT NULL DEFAULT 0,
  `minimum_stock` INT NOT NULL DEFAULT 5,
  `cost_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `selling_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prod_sku` (`sku`),
  KEY `idx_prod_supplier` (`supplier_id`),
  CONSTRAINT `fk_prod_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- purchases
-- ------------------------------------------------------------
CREATE TABLE `purchases` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `supplier_id` INT NULL DEFAULT NULL,
  `product_id` INT NOT NULL,
  `quantity` INT NOT NULL DEFAULT 0,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `purchase_date` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pur_supplier` (`supplier_id`),
  KEY `idx_pur_product` (`product_id`),
  CONSTRAINT `fk_pur_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pur_product` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- product_sales
-- ------------------------------------------------------------
CREATE TABLE `product_sales` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `product_id` INT NOT NULL,
  `customer_id` INT NULL DEFAULT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sale_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ps_product` (`product_id`),
  KEY `idx_ps_customer` (`customer_id`),
  CONSTRAINT `fk_ps_product` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ps_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- payroll  (admin only — one record per staff member per pay period)
-- staff_id   → real link to the `staff` table (FK, kept on staff deletion)
-- staff_name → snapshot of the full name at pay time so history survives
--              staff renames and staff deletion without losing context
-- ------------------------------------------------------------
CREATE TABLE `payroll` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `staff_id` INT NULL DEFAULT NULL,
  `staff_name` VARCHAR(100) NOT NULL,
  `salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `commission` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tips` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `bonus` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `deductions` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `net_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pay_period` VARCHAR(10) NOT NULL,
  `payment_status` ENUM('paid','pending') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_period` (`pay_period`),
  KEY `idx_payroll_staff` (`staff_id`),
  CONSTRAINT `fk_payroll_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- notifications
-- ------------------------------------------------------------
CREATE TABLE `notifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL DEFAULT NULL,
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT NULL DEFAULT NULL,
  `type` VARCHAR(30) NOT NULL DEFAULT 'info',
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`),
  KEY `idx_notif_read` (`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- settings
-- ------------------------------------------------------------
CREATE TABLE `settings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- holidays
-- ------------------------------------------------------------
CREATE TABLE `holidays` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `holiday_date` DATE NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_holiday_date` (`holiday_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- loyalty_ledger
-- ------------------------------------------------------------
CREATE TABLE `loyalty_ledger` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `customer_id` INT NOT NULL,
  `points_change` INT NOT NULL DEFAULT 0,
  `reason` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ll_customer` (`customer_id`),
  CONSTRAINT `fk_ll_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- audit_logs
-- ------------------------------------------------------------
CREATE TABLE `audit_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL DEFAULT NULL,
  `action` VARCHAR(50) NOT NULL,
  `module` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_module` (`module`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  DEMO DATA  —  Goscha Software  (all fictional)
-- ============================================================

-- ------------------------------------------------------------
-- Users (admin + receptionist)
-- Passwords (bcrypt):
--   admin@beautyphpai.com        -> Admin@123
--   admin2@beautyphpai.com       -> Admin@123
--   receptionist@beautyphpai.com -> Recep@123
-- ------------------------------------------------------------
INSERT INTO `users` (`id`,`name`,`email`,`phone`,`password`,`role`,`status`) VALUES
(1,'Goscha Administrator','admin@beautyphpai.com','0911 000 111','$2y$10$c7OmCtx5P66xx5LMgsORxe4Hjmezb3XwmTNkUFvea3Y89teOmCHVC','admin','active'),
(2,'Sara Admin','admin2@beautyphpai.com','0911 000 222','$2y$10$STyl3CLT1tkMdGjBGarGOeiYcE/MQLGYOUspkTArZmur1hnSbRkjW','admin','active'),
(3,'Ruth Reception','receptionist@beautyphpai.com','0911 000 333','$2y$10$Fb4kCwcuRdHTSHUDE2NjB.r3TiOE6KBN.S.uY6HK3IUnkijL7bNt2','receptionist','active');

-- ------------------------------------------------------------
-- Settings
-- ------------------------------------------------------------
INSERT INTO `settings` (`setting_key`,`setting_value`) VALUES
('salon_name','Goscha Software'),
('salon_tagline','Smart Software for Beautiful Businesses.'),
('salon_logo',''),
('salon_phone','+251 911 000 111'),
('salon_email','hello@goschasoftware.com'),
('salon_address','Bole Road, Addis Ababa, Ethiopia'),
('business_open','09:00'),
('business_close','18:00'),
('business_hours_display','Mon – Sat: 9:00 AM – 6:00 PM · Sun: Closed'),
('currency','ETB'),
('tax_rate','15'),
('loyalty_rate','100'),
('appointment_advance_days','30'),
('max_advance_days','30'),
('session_timeout_minutes','60'),
('notify_email','0'),
('notify_sms','0'),
('notification_sms_enabled','0'),
('notification_email_enabled','0'),
('facebook_url','https://facebook.com/goschasoftware'),
('instagram_url','https://instagram.com/goschasoftware'),
('tiktok_url','https://tiktok.com/@goschasoftware');

-- ------------------------------------------------------------
-- Customers  (12 — note some have NO email to prove email is optional)
-- ------------------------------------------------------------
INSERT INTO `customers` (`id`,`first_name`,`last_name`,`phone`,`email`,`date_of_birth`,`gender`,`address`,`skin_type`,`hair_type`,`allergies`,`notes`,`loyalty_points`) VALUES
(1,'Liya','Hailu','0911 234 567','liya.hailu@example.com','1992-04-12','female','Bole, Addis Ababa','Normal','Curly','None','VIP customer, prefers morning appointments.',320),
(2,'Meron','Tesfaye','0912 345 678','meron.t@example.com','1995-08-03','female','CMC, Addis Ababa','Oily','Straight','Nail polish allergy','Prefers gel-free manicure.',150),
(3,'Abel','Tadesse','0913 456 789',NULL,'1988-11-21','male','Megenagna, Addis Ababa','Sensitive','Short','Coconut oil','',90),
(4,'Hanna','Girma','0914 567 890','hanna.g@gmail.com',NULL,'female',NULL,NULL,'Wavy',NULL,'First-time client, referred by Liya.',410),
(5,'Daniel','Alemu','0915 678 901',NULL,'1990-02-15',NULL,'Gerji, Addis Ababa',NULL,NULL,NULL,'Prefers bank transfer payments.',75),
(6,'Ruth','Mekonnen','0916 789 012','ruth.mek@example.com','1998-12-30','female','Piassa, Addis Ababa','Dry','Straight','None','',260),
(7,'Samrawit','Yohannes','0917 890 123',NULL,'1993-06-08','female',NULL,'Combination','Curly','Latex','',180),
(8,'Eden','Assefa','0918 901 234','eden.assefa@example.com','1985-09-19','female','Kazanchis, Addis Ababa','Normal','Straight','None','Corporate client.',520),
(9,'Yonatan','Bekele','0919 012 345','yonatan.b@example.com','1991-01-25','male',NULL,NULL,'Short',NULL,'',45),
(10,'Bethlehem','Kebede','0920 123 456',NULL,'1996-07-14','female','Sar Bet, Addis Ababa','Oily','Curly','None','',130),
(11,'Selam','Wondimu','0921 234 567','selam.w@example.com','1987-03-02','female','Nifas Silk, Addis Ababa','Sensitive','Wavy','Alcohol (in products)','',210),
(12,'Mahlet','Berhanu','0922 345 678',NULL,'1994-10-11',NULL,'Bambis, Addis Ababa',NULL,NULL,NULL,'',60);

-- ------------------------------------------------------------
-- Service Categories  (LEVEL 1)
-- ------------------------------------------------------------
INSERT INTO `service_categories` (`id`,`name`,`description`,`status`,`sort_order`) VALUES
(1,'Hair','Hair washing, styling, coloring and treatment services.','active',1),
(2,'Skin & Facials','Facial treatments for every skin type.','active',2),
(3,'Nails','Manicure and pedicure services.','active',3),
(4,'Makeup','Daily and bridal makeup services.','active',4),
(5,'Massage & Spa','Relaxing massage and spa treatments.','active',5),
(6,'Eyelashes','Lash extensions, fills and lash care.','active',6),
(7,'Eyebrows','Brow shaping, tinting and threading.','active',7),
(8,'Waxing','Body and facial waxing.','active',8),
(9,'Other','Any service that does not fit a dedicated category.','active',9);

-- ------------------------------------------------------------
-- Service Subcategories  (LEVEL 2 — optional, one parent category)
-- ------------------------------------------------------------
INSERT INTO `service_subcategories` (`id`,`category_id`,`name`,`description`,`status`,`sort_order`) VALUES
(1,1,'Hair Styling','Blow-dry, blow-out, curls and event styling.','active',1),
(2,1,'Hair Coloring','Full color, highlights, balayage and retouch.','active',2),
(3,1,'Hair Treatment','Keratin, deep conditioning and repair.','active',3),
(4,1,'Hair Extensions','Weave, wig install and extension fitting.','active',4),
(5,2,'Facials','Classic, deep-cleansing and hydrating facials.','active',1),
(6,3,'Manicure','Classic, gel and spa manicure.','active',1),
(7,3,'Pedicure','Classic, gel and spa pedicure.','active',2),
(8,3,'Nail Art','Decoration, French tips and nail art.','active',3),
(9,4,'Makeup','Everyday, party and photographic makeup.','active',1),
(10,5,'Massage','Swedish, deep tissue and aromatherapy massage.','active',1);

-- ------------------------------------------------------------
-- Services  (LEVEL 3 — category REQUIRED, subcategory OPTIONAL)
-- Services 1 and 9 intentionally have subcategory_id = NULL to
-- demonstrate the "Category -> Service" (no subcategory) path.
-- ------------------------------------------------------------
INSERT INTO `services` (`id`,`category_id`,`subcategory_id`,`name`,`description`,`duration_minutes`,`price`,`status`,`sort_order`) VALUES
(1,1,NULL,'Hair Wash','Gentle hair wash with premium nourishing shampoo and conditioning rinse.',30,250.00,'active',1),
(2,1,1,'Hair Styling','Professional blow-dry and styling for any occasion.',60,600.00,'active',2),
(3,1,2,'Hair Coloring','Full hair coloring with high-quality ammonia-free color.',120,1800.00,'active',3),
(4,1,3,'Hair Treatment','Deep conditioning keratin and repair treatment.',45,800.00,'active',4),
(5,2,5,'Facial','Cleansing, exfoliating and hydrating facial with facial massage.',45,1200.00,'active',1),
(6,3,6,'Manicure','Classic manicure with cuticle care, shaping and polish.',45,500.00,'active',1),
(7,3,7,'Pedicure','Classic pedicure with soak, scrub, massage and polish.',45,550.00,'active',2),
(8,4,9,'Makeup','Professional makeup application for events and occasions.',60,1500.00,'active',1),
(9,4,NULL,'Bridal Makeup','Complete bridal look including trial and on-the-day makeup.',120,8000.00,'active',2),
(10,5,10,'Massage','Relaxing full-body massage with aromatic oils.',60,1000.00,'active',1);

-- ------------------------------------------------------------
-- Staff (5 demo members — all fictional. Sarah Smith and Lily Adams
-- deliberately have NO email to prove staff email is optional)
-- ------------------------------------------------------------
INSERT INTO `staff` (`id`,`first_name`,`last_name`,`phone`,`email`,`date_of_birth`,`gender`,`address`,`emergency_contact_name`,`emergency_contact_phone`,`salary`,`notes`,`status`) VALUES
(1,'Jane','Doe','0911 111 001','jane.doe@example.com','1990-03-18','female','Bole, Addis Ababa','Michael Doe','0911 111 901',9500.00,'Senior hairstylist. Certified in keratin treatments.','active'),
(2,'Sarah','Smith','0911 111 002',NULL,'1994-07-25','female','Sar Bet, Addis Ababa','Anna Smith','0911 111 902',8200.00,'Nail technician. Prefers weekend shifts.','active'),
(3,'Hana','Bekele','0911 111 003','hana.bekele@example.com','1992-11-02','female','CMC, Addis Ababa','Tewodros Bekele','0911 111 903',8800.00,'Skincare specialist (facials and peels).','active'),
(4,'Meron','Tesfaye','0911 111 004','meron.tesfaye@example.com','1996-01-30','female','Megenagna, Addis Ababa','Abebe Tesfaye','0911 111 904',7800.00,'Makeup artist. Bridal packages specialist.','active'),
(5,'Lily','Adams','0911 111 005',NULL,'1991-05-14','female','Kazanchis, Addis Ababa','Peter Adams','0911 111 905',7000.00,'Massage therapist. Currently on study leave.','inactive');

-- ------------------------------------------------------------
-- Appointments (dates relative to today so the demo always looks alive)
-- ------------------------------------------------------------
INSERT INTO `appointments`
(`id`,`customer_id`,`staff_id`,`appointment_date`,`start_time`,`end_time`,`status`,`notes`,`subtotal`,`discount`,`tax`,`total_amount`,`payment_status`,`created_by`) VALUES
(1,1, 3, CURDATE(), '09:00:00','09:45:00','confirmed','Regular facial appointment.',1450.00,0.00,217.50,1667.50,'paid',2),
(2,2, 2, CURDATE(), '10:00:00','11:00:00','confirmed','',600.00,0.00,90.00,690.00,'paid',2),
(3,3, 5, CURDATE(), '11:30:00','12:30:00','completed','',1000.00,0.00,150.00,1150.00,'paid',1),
(4,4, 4, CURDATE(), '13:00:00','13:45:00','in_progress','',500.00,0.00,75.00,575.00,'pending',2),
(5,5, 4, CURDATE(), '14:00:00','15:00:00','pending','Makeup for evening event.',1500.00,0.00,225.00,1725.00,'pending',2),
(6,6, 1, CURDATE(), '15:30:00','16:30:00','confirmed','Hair coloring retouch.',1800.00,0.00,270.00,2070.00,'pending',1),
(7,7, 3, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '10:00:00','10:45:00','confirmed','',1200.00,0.00,180.00,1380.00,'pending',2),
(8,8, 1, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '11:00:00','12:00:00','confirmed','Wash and styling.',850.00,0.00,127.50,977.50,'pending',2),
(9,9, 4, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '13:00:00','15:00:00','pending','Bridal makeup appointment.',8000.00,0.00,1200.00,9200.00,'pending',1),
(10,10,2, DATE_ADD(CURDATE(), INTERVAL 2 DAY), '09:30:00','10:15:00','confirmed','Keratin treatment.',800.00,0.00,120.00,920.00,'pending',2),
(11,11,3, DATE_ADD(CURDATE(), INTERVAL 2 DAY), '11:00:00','11:45:00','confirmed','',550.00,0.00,82.50,632.50,'pending',2),
(12,12,1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), '10:00:00','11:00:00','completed','',1000.00,0.00,150.00,1150.00,'paid',1),
(13,1, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), '14:00:00','14:45:00','completed','',1200.00,0.00,180.00,1380.00,'paid',2),
(14,2, 1, DATE_SUB(CURDATE(), INTERVAL 3 DAY), '09:00:00','10:00:00','completed','',600.00,0.00,90.00,690.00,'paid',1),
(15,3, 2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), '11:00:00','11:45:00','completed','',500.00,0.00,75.00,575.00,'paid',2),
(16,4, 4, DATE_SUB(CURDATE(), INTERVAL 5 DAY), '13:00:00','15:00:00','completed','Bridal makeup.',8000.00,0.00,1200.00,9200.00,'paid',1);

-- ------------------------------------------------------------
-- Appointment Services
-- ------------------------------------------------------------
INSERT INTO `appointment_services` (`appointment_id`,`service_id`,`price`,`duration_minutes`) VALUES
(1,5,1200.00,45),
(1,1,250.00,30),
(2,2,600.00,60),
(3,10,1000.00,60),
(4,6,500.00,45),
(5,8,1500.00,60),
(6,3,1800.00,120),
(7,5,1200.00,45),
(8,2,600.00,60),
(8,1,250.00,30),
(9,9,8000.00,120),
(10,4,800.00,45),
(11,7,550.00,45),
(12,10,1000.00,60),
(13,5,1200.00,45),
(14,2,600.00,60),
(15,6,500.00,45),
(16,9,8000.00,120);

-- ------------------------------------------------------------
-- Waitlist
-- ------------------------------------------------------------
INSERT INTO `waitlist` (`customer_id`,`name`,`phone`,`service_id`,`preferred_date`,`preferred_time`,`notes`,`status`) VALUES
(6,NULL,NULL,4,DATE_ADD(CURDATE(), INTERVAL 3 DAY),'10:00:00','Requested via website.','waiting'),
(NULL,'Nardos Fikru','0933 456 789',8,DATE_ADD(CURDATE(), INTERVAL 2 DAY),'14:00:00','Online booking request.','waiting'),
(9,NULL,NULL,9,DATE_ADD(CURDATE(), INTERVAL 7 DAY),'09:00:00','','converted');

-- ------------------------------------------------------------
-- Payments
-- ------------------------------------------------------------
INSERT INTO `payments` (`appointment_id`,`customer_id`,`amount`,`payment_method`,`transaction_reference`,`payment_date`,`status`) VALUES
(1,1,1667.50,'cash','GLM-PAY-0001',DATE_SUB(CURDATE(), INTERVAL 1 DAY),'completed'),
(2,2,690.00,'bank_transfer','TRF-8842-113',DATE_SUB(CURDATE(), INTERVAL 1 DAY),'completed'),
(3,3,1150.00,'cash','GLM-PAY-0003',CURDATE(),'completed'),
(12,12,1150.00,'bank_transfer','TRF-7712-009',DATE_SUB(CURDATE(), INTERVAL 1 DAY),'completed'),
(13,1,1380.00,'cash','GLM-PAY-0005',DATE_SUB(CURDATE(), INTERVAL 1 DAY),'completed'),
(14,2,690.00,'cash','GLM-PAY-0006',DATE_SUB(CURDATE(), INTERVAL 3 DAY),'completed'),
(15,3,575.00,'bank_transfer','TRF-5501-221',DATE_SUB(CURDATE(), INTERVAL 3 DAY),'completed'),
(16,4,9200.00,'bank_transfer','TRF-9099-777',DATE_SUB(CURDATE(), INTERVAL 5 DAY),'completed');

-- ------------------------------------------------------------
-- Invoices
-- ------------------------------------------------------------
INSERT INTO `invoices` (`invoice_number`,`appointment_id`,`customer_id`,`subtotal`,`discount`,`tax`,`total`,`status`,`issued_at`) VALUES
('INV-2026-0001',1,1,1450.00,0.00,217.50,1667.50,'paid',DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
('INV-2026-0002',2,2,600.00,0.00,90.00,690.00,'paid',DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
('INV-2026-0003',3,3,1000.00,0.00,150.00,1150.00,'paid',CURDATE()),
('INV-2026-0004',12,12,1000.00,0.00,150.00,1150.00,'paid',DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
('INV-2026-0005',13,1,1200.00,0.00,180.00,1380.00,'paid',DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
('INV-2026-0006',14,2,600.00,0.00,90.00,690.00,'paid',DATE_SUB(CURDATE(), INTERVAL 3 DAY)),
('INV-2026-0007',15,3,500.00,0.00,75.00,575.00,'paid',DATE_SUB(CURDATE(), INTERVAL 3 DAY)),
('INV-2026-0008',16,4,8000.00,0.00,1200.00,9200.00,'paid',DATE_SUB(CURDATE(), INTERVAL 5 DAY));

-- ------------------------------------------------------------
-- Suppliers
-- ------------------------------------------------------------
INSERT INTO `suppliers` (`id`,`name`,`contact_person`,`phone`,`email`,`address`,`status`) VALUES
(1,'Addis Beauty Supplies','Abel Tadesse','0911 123 456','sales@addisbeauty.com','Bole Medhanealem, Addis Ababa','active'),
(2,'Grace Cosmetics Import','Sara Mekonnen','0912 987 654','orders@gracecosmetics.com','Kazanchis, Addis Ababa','active'),
(3,'Nairobi Hair Products Ltd','John Omondi','0913 555 789','nairobi.hair@example.com','Industrial Area, Nairobi','active'),
(4,'SunCare Essentials','Liya Habte','0914 222 333','hello@suncareet.com','Megenagna, Addis Ababa','active'),
(5,'Prime Packaging Co.','Daniel Alemu','0915 444 555','packaging@primeet.com','Kality, Addis Ababa','inactive');

-- ------------------------------------------------------------
-- Inventory Products (some deliberately low stock to trigger alerts)
-- ------------------------------------------------------------
INSERT INTO `inventory_products` (`id`,`name`,`sku`,`barcode`,`category`,`supplier_id`,`quantity`,`minimum_stock`,`cost_price`,`selling_price`,`status`) VALUES
(1,'Argan Oil Shampoo 500ml','GLM-SHP-001','6291041500213','Hair Care',1,24,10,350.00,550.00,'active'),
(2,'Hair Conditioner 500ml','GLM-CND-002','6291041500220','Hair Care',1,8,10,320.00,500.00,'active'),
(3,'Hair Color Kit – Brown','GLM-HC-003','6291041500237','Hair Care',3,15,5,650.00,950.00,'active'),
(4,'Facial Cleanser 250ml','GLM-FC-004','6291041500244','Skin Care',4,20,8,280.00,480.00,'active'),
(5,'Hydrating Face Mask','GLM-FM-005','6291041500251','Skin Care',4,6,10,150.00,320.00,'active'),
(6,'Nail Polish Set','GLM-NP-006','6291041500268','Nails',2,30,12,220.00,420.00,'active'),
(7,'Nail Files (pack of 10)','GLM-NF-007','6291041500275','Nails',2,40,15,60.00,120.00,'active'),
(8,'Massage Oil 500ml','GLM-MO-008','6291041500282','Spa',1,12,6,400.00,700.00,'active'),
(9,'Makeup Brush Set','GLM-MB-009','6291041500299','Makeup',2,5,5,800.00,1400.00,'active'),
(10,'Keratin Treatment Kit','GLM-KT-010','6291041500305','Hair Care',3,9,4,1200.00,1800.00,'active');

-- ------------------------------------------------------------
-- Purchases
-- ------------------------------------------------------------
INSERT INTO `purchases` (`supplier_id`,`product_id`,`quantity`,`unit_price`,`total`,`purchase_date`) VALUES
(1,1,50,320.00,16000.00,DATE_SUB(CURDATE(), INTERVAL 10 DAY)),
(1,2,40,290.00,11600.00,DATE_SUB(CURDATE(), INTERVAL 10 DAY)),
(3,3,30,600.00,18000.00,DATE_SUB(CURDATE(), INTERVAL 8 DAY)),
(4,4,40,250.00,10000.00,DATE_SUB(CURDATE(), INTERVAL 8 DAY)),
(4,5,30,130.00,3900.00,DATE_SUB(CURDATE(), INTERVAL 7 DAY)),
(2,6,25,200.00,5000.00,DATE_SUB(CURDATE(), INTERVAL 6 DAY)),
(2,7,60,50.00,3000.00,DATE_SUB(CURDATE(), INTERVAL 6 DAY)),
(1,8,20,360.00,7200.00,DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
(2,9,10,750.00,7500.00,DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
(3,10,15,1100.00,16500.00,DATE_SUB(CURDATE(), INTERVAL 4 DAY));

-- ------------------------------------------------------------
-- Product Sales
-- ------------------------------------------------------------
INSERT INTO `product_sales` (`product_id`,`customer_id`,`quantity`,`unit_price`,`total`,`sale_date`) VALUES
(1,1,1,550.00,550.00,DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
(3,6,1,950.00,950.00,DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(6,4,2,420.00,840.00,DATE_SUB(CURDATE(), INTERVAL 3 DAY)),
(8,12,1,700.00,700.00,DATE_SUB(CURDATE(), INTERVAL 1 DAY));

-- ------------------------------------------------------------
-- Payroll (real staff links — staff_id ↔ staff table, salaries
-- match the staff records, snapshots stored for history)
-- ------------------------------------------------------------
INSERT INTO `payroll` (`staff_id`,`staff_name`,`salary`,`commission`,`tips`,`bonus`,`deductions`,`net_salary`,`pay_period`,`payment_status`) VALUES
(1,'Jane Doe',9500.00,1800.00,1200.00,800.00,450.00,12850.00,'2026-07','paid'),
(2,'Sarah Smith',8200.00,1200.00,900.00,500.00,300.00,10500.00,'2026-07','paid'),
(3,'Hana Bekele',8800.00,1500.00,1000.00,600.00,350.00,11550.00,'2026-07','paid'),
(4,'Meron Tesfaye',7800.00,950.00,700.00,400.00,250.00,9600.00,'2026-07','paid'),
(5,'Lily Adams',7000.00,600.00,450.00,0.00,200.00,7850.00,'2026-06','paid'),
(1,'Jane Doe',9500.00,950.00,800.00,0.00,300.00,10950.00,'2026-08','pending');

-- ------------------------------------------------------------
-- Notifications
-- ------------------------------------------------------------
INSERT INTO `notifications` (`user_id`,`title`,`message`,`type`,`is_read`) VALUES
(1,'New waitlist request','A new booking request was received from the website.','info',0),
(1,'Low stock alert','Hair Conditioner is below the minimum stock level.','warning',0),
(1,'Low stock alert','Hydrating Face Mask is below the minimum stock level.','warning',0),
(2,'New appointment','Appointment for Ruth Mekonnen was confirmed.','success',0),
(2,'Payment received','Cash payment of ETB 1,150.00 recorded for today.','success',0);

-- ------------------------------------------------------------
-- Loyalty ledger
-- ------------------------------------------------------------
INSERT INTO `loyalty_ledger` (`customer_id`,`points_change`,`reason`,`created_at`) VALUES
(1,50,'New customer welcome bonus',DATE_SUB(CURDATE(), INTERVAL 60 DAY)),
(1,120,'Appointment spending reward',DATE_SUB(CURDATE(), INTERVAL 10 DAY)),
(1,150,'Appointment spending reward',DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(2,80,'Appointment spending reward',DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
(4,200,'Bridal makeup reward',DATE_SUB(CURDATE(), INTERVAL 5 DAY)),
(8,220,'Appointment spending reward',DATE_SUB(CURDATE(), INTERVAL 12 DAY));

-- ------------------------------------------------------------
-- Holidays
-- ------------------------------------------------------------
INSERT INTO `holidays` (`holiday_date`,`title`) VALUES
(DATE_ADD(CURDATE(), INTERVAL 14 DAY),'Staff Training Day'),
(DATE_ADD(CURDATE(), INTERVAL 45 DAY),'Salon Annual Break');

-- ------------------------------------------------------------
-- Audit logs
-- ------------------------------------------------------------
INSERT INTO `audit_logs` (`user_id`,`action`,`module`,`description`,`ip_address`,`created_at`) VALUES
(1,'login','auth','Administrator logged in','127.0.0.1',DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(2,'create','customers','Created customer Mahlet Berhanu','127.0.0.1',DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
(2,'create','appointments','Created appointment #5 for Daniel Alemu','127.0.0.1',DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
(2,'payment','payments','Recorded cash payment for appointment #3','127.0.0.1',CURDATE()),
(1,'settings','settings','Updated business hours settings','127.0.0.1',DATE_SUB(CURDATE(), INTERVAL 6 DAY)),
(1,'create','staff','Administrator added staff member: Jane Doe.','127.0.0.1',DATE_SUB(CURDATE(), INTERVAL 4 DAY)),
(1,'create','staff','Administrator added staff member: Sarah Smith.','127.0.0.1',DATE_SUB(CURDATE(), INTERVAL 4 DAY)),
(1,'update','staff','Administrator updated staff member: Hana Bekele.','127.0.0.1',DATE_SUB(CURDATE(), INTERVAL 2 DAY));

-- ============================================================
--  END OF SCRIPT  —  beauty_php_ai
-- ============================================================
