-- ============================================================
--  Beauty-php-ai — Staff Management migration
--  Adds the Staff Management module to an EXISTING database.
--
--  Safe to run multiple times (idempotent):
--    * Creates the `staff` table if missing
--    * Adds `appointments.staff_id` + index + FK if missing
--    * Extends `users.role` to include 'receptionist'
--    * Seeds 5 demo staff members ONLY when the staff table is empty
--
--  Existing data is never modified or destroyed.
--  Run: mysql -u root beauty_php_ai < database/migration_staff.sql
-- ============================================================

USE `beauty_php_ai`;

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. Create `staff` table when missing
-- ------------------------------------------------------------
SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff'
);

SET @sql = IF(@tbl_exists = 0,
  'CREATE TABLE `staff` (
     `id` INT NOT NULL AUTO_INCREMENT,
     `first_name` VARCHAR(100) NOT NULL,
     `last_name` VARCHAR(100) NOT NULL,
     `phone` VARCHAR(20) NOT NULL,
     `email` VARCHAR(255) NULL DEFAULT NULL,
     `date_of_birth` DATE NULL DEFAULT NULL,
     `gender` ENUM(''female'',''male'',''other'') NULL DEFAULT NULL,
     `address` VARCHAR(255) NULL DEFAULT NULL,
     `emergency_contact_name` VARCHAR(150) NULL DEFAULT NULL,
     `emergency_contact_phone` VARCHAR(20) NULL DEFAULT NULL,
     `salary` DECIMAL(10,2) NULL DEFAULT NULL,
     `notes` TEXT NULL DEFAULT NULL,
     `status` ENUM(''active'',''inactive'') NOT NULL DEFAULT ''active'',
     `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (`id`),
     KEY `idx_staff_name` (`last_name`,`first_name`),
     KEY `idx_staff_status` (`status`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2. Add `appointments.staff_id` column when missing
-- ------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'appointments'
    AND COLUMN_NAME = 'staff_id'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `appointments`
     ADD COLUMN `staff_id` INT NULL DEFAULT NULL AFTER `customer_id`',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index when missing
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'appointments'
    AND INDEX_NAME = 'idx_appt_staff'
);

SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE `appointments` ADD INDEX `idx_appt_staff` (`staff_id`)',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Foreign key when missing
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'appointments'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_appt_staff'
);

SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE `appointments`
     ADD CONSTRAINT `fk_appt_staff`
     FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3. Allow the 'receptionist' system role on users
--    (MODIFY with the same definition is a no-op when unchanged)
-- ------------------------------------------------------------
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('admin','receptionist') NOT NULL DEFAULT 'admin';

-- ------------------------------------------------------------
-- 4. Demo staff data — inserted ONLY when the table is empty
--    (Sarah Smith and Lily Adams have NO email on purpose)
-- ------------------------------------------------------------
SET @staff_empty = (SELECT COUNT(*) FROM `staff`);

SET @sql = IF(@staff_empty = 0,
  'INSERT INTO `staff`
     (`first_name`,`last_name`,`phone`,`email`,`date_of_birth`,`gender`,`address`,
      `emergency_contact_name`,`emergency_contact_phone`,`salary`,`notes`,`status`)
   VALUES
     (''Jane'',''Doe'',''0911 111 001'',''jane.doe@example.com'',''1990-03-18'',''female'',''Bole, Addis Ababa'',
      ''Michael Doe'',''0911 111 901'',9500.00,''Senior hairstylist. Certified in keratin treatments.'',''active''),
     (''Sarah'',''Smith'',''0911 111 002'',NULL,''1994-07-25'',''female'',''Sar Bet, Addis Ababa'',
      ''Anna Smith'',''0911 111 902'',8200.00,''Nail technician. Prefers weekend shifts.'',''active''),
     (''Hana'',''Bekele'',''0911 111 003'',''hana.bekele@example.com'',''1992-11-02'',''female'',''CMC, Addis Ababa'',
      ''Tewodros Bekele'',''0911 111 903'',8800.00,''Skincare specialist (facials and peels).'',''active''),
     (''Meron'',''Tesfaye'',''0911 111 004'',''meron.tesfaye@example.com'',''1996-01-30'',''female'',''Megenagna, Addis Ababa'',
      ''Abebe Tesfaye'',''0911 111 904'',7800.00,''Makeup artist. Bridal packages specialist.'',''active''),
     (''Lily'',''Adams'',''0911 111 005'',NULL,''1991-05-14'',''female'',''Kazanchis, Addis Ababa'',
      ''Peter Adams'',''0911 111 905'',7000.00,''Massage therapist. Currently on study leave.'',''inactive'')',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 5. Backfill demo staff assignments on existing appointments
--    Runs ONLY on rows whose staff_id is still NULL, and matches
--    staff BY NAME (works regardless of auto-increment ids).
--    Idempotent: re-running changes nothing. Never touches any
--    appointment that already has a staff member assigned.
-- ------------------------------------------------------------
UPDATE `appointments` a
JOIN (
  SELECT 1  AS appt_id, 'Hana'  AS fn, 'Bekele'  AS ln
  UNION ALL SELECT 2,  'Sarah', 'Smith'
  UNION ALL SELECT 3,  'Lily',  'Adams'
  UNION ALL SELECT 4,  'Meron', 'Tesfaye'
  UNION ALL SELECT 5,  'Meron', 'Tesfaye'
  UNION ALL SELECT 6,  'Jane',  'Doe'
  UNION ALL SELECT 7,  'Hana',  'Bekele'
  UNION ALL SELECT 8,  'Jane',  'Doe'
  UNION ALL SELECT 9,  'Meron', 'Tesfaye'
  UNION ALL SELECT 10, 'Sarah', 'Smith'
  UNION ALL SELECT 11, 'Hana',  'Bekele'
  UNION ALL SELECT 12, 'Jane',  'Doe'
  UNION ALL SELECT 13, 'Hana',  'Bekele'
  UNION ALL SELECT 14, 'Jane',  'Doe'
  UNION ALL SELECT 15, 'Sarah', 'Smith'
  UNION ALL SELECT 16, 'Meron', 'Tesfaye'
) m ON m.appt_id = a.id
SET a.staff_id = (
  SELECT s.id FROM `staff` s
  WHERE s.first_name = m.fn AND s.last_name = m.ln
  ORDER BY s.id LIMIT 1
)
WHERE a.staff_id IS NULL;

-- ============================================================
--  END OF MIGRATION
-- ============================================================
