-- ============================================================
--  Beauty-php-ai — Scheduling Foundation migration
--  Salon information, weekly business hours, breaks,
--  staff working hours and staff <-> service skills.
--
--  Safe to run on an EXISTING, POPULATED database:
--    * every table is created only when missing
--    * every column is added only when missing
--    * existing business_open / business_close settings are MIGRATED
--      into business_hours so the live schedule is preserved
--    * existing staff receive a default weekly schedule derived from
--      the salon hours (existing appointments are NEVER touched)
--
--  No appointment, customer, staff, service, payment or invoice
--  row is deleted, modified or moved by this script.
--
--  Run:  mysql -u root beauty_php_ai < database/migration_scheduling.sql
-- ============================================================

USE `beauty_php_ai`;

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. salon_settings — single row (id = 1) holding the salon's
--    identity and the owner's name as two separate fields.
--    The key/value `settings` table keeps mirroring salon_name so
--    the existing get_setting('salon_name') callers keep working.
-- ------------------------------------------------------------
SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salon_settings'
);

SET @sql = IF(@tbl_exists = 0,
  'CREATE TABLE `salon_settings` (
     `id` INT NOT NULL AUTO_INCREMENT,
     `salon_name` VARCHAR(150) NOT NULL,
     `owner_first_name` VARCHAR(100) NOT NULL,
     `owner_last_name` VARCHAR(100) NOT NULL,
     `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (`id`),
     UNIQUE KEY `uq_salon_settings_single` (`id`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2. business_hours — one row per weekday.
--    day_of_week: 1 = Monday ... 7 = Sunday  (ISO-8601, matches
--    PHP date('N')).  Times are always TIME 'HH:MM:SS'.
-- ------------------------------------------------------------
SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_hours'
);

SET @sql = IF(@tbl_exists = 0,
  'CREATE TABLE `business_hours` (
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
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3. business_breaks — salon-wide blackout periods per weekday.
--    Appointments may never overlap one of these.
-- ------------------------------------------------------------
SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_breaks'
);

SET @sql = IF(@tbl_exists = 0,
  'CREATE TABLE `business_breaks` (
     `id` INT NOT NULL AUTO_INCREMENT,
     `day_of_week` TINYINT UNSIGNED NOT NULL,
     `start_time` TIME NOT NULL,
     `end_time` TIME NOT NULL,
     `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (`id`),
     KEY `idx_business_breaks_day` (`day_of_week`),
     CONSTRAINT `ck_business_breaks_day` CHECK (`day_of_week` BETWEEN 1 AND 7)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 4. staff_working_hours — per-staff weekly schedule.
--    A staff row must always sit INSIDE the salon hours for the
--    same day; that rule is enforced in includes/scheduling.php.
-- ------------------------------------------------------------
SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_working_hours'
);

SET @sql = IF(@tbl_exists = 0,
  'CREATE TABLE `staff_working_hours` (
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
     CONSTRAINT `fk_staff_hours_staff` FOREIGN KEY (`staff_id`)
       REFERENCES `staff` (`id`) ON DELETE CASCADE,
     CONSTRAINT `ck_staff_hours_day` CHECK (`day_of_week` BETWEEN 1 AND 7)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 5. staff_breaks — per-staff blackout periods per weekday.
-- ------------------------------------------------------------
SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_breaks'
);

SET @sql = IF(@tbl_exists = 0,
  'CREATE TABLE `staff_breaks` (
     `id` INT NOT NULL AUTO_INCREMENT,
     `staff_id` INT NOT NULL,
     `day_of_week` TINYINT UNSIGNED NOT NULL,
     `start_time` TIME NOT NULL,
     `end_time` TIME NOT NULL,
     `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (`id`),
     KEY `idx_staff_breaks_staff_day` (`staff_id`,`day_of_week`),
     CONSTRAINT `fk_staff_breaks_staff` FOREIGN KEY (`staff_id`)
       REFERENCES `staff` (`id`) ON DELETE CASCADE,
     CONSTRAINT `ck_staff_breaks_day` CHECK (`day_of_week` BETWEEN 1 AND 7)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 6. staff_services — which services a staff member performs.
--    A staff member with NO rows in this table is unrestricted
--    (may perform any active service).  As soon as one row exists
--    the list becomes the staff member's allow-list.
-- ------------------------------------------------------------
SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_services'
);

SET @sql = IF(@tbl_exists = 0,
  'CREATE TABLE `staff_services` (
     `id` INT NOT NULL AUTO_INCREMENT,
     `staff_id` INT NOT NULL,
     `service_id` INT NOT NULL,
     `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     PRIMARY KEY (`id`),
     UNIQUE KEY `uq_staff_service` (`staff_id`,`service_id`),
     KEY `idx_staff_services_service` (`service_id`),
     CONSTRAINT `fk_staff_services_staff` FOREIGN KEY (`staff_id`)
       REFERENCES `staff` (`id`) ON DELETE CASCADE,
     CONSTRAINT `fk_staff_services_service` FOREIGN KEY (`service_id`)
       REFERENCES `services` (`id`) ON DELETE CASCADE
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 7. users — split the single `name` column into first / last.
--    `name` is KEPT (it is the composed display name) so every
--    existing reader — navbar, audit log, user list — is
--    unaffected.  Existing rows are back-filled from `name`.
-- ------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users' AND COLUMN_NAME = 'first_name'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `users`
     ADD COLUMN `first_name` VARCHAR(100) NULL DEFAULT NULL AFTER `name`',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_name'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `users`
     ADD COLUMN `last_name` VARCHAR(100) NULL DEFAULT NULL AFTER `first_name`',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Back-fill first/last from the existing composed name. Idempotent:
-- it only touches rows where the split has not been done yet.
UPDATE `users`
SET `first_name` = TRIM(SUBSTRING_INDEX(`name`, ' ', 1)),
    `last_name`  = NULLIF(TRIM(SUBSTRING_INDEX(`name`, ' ', -1)), '')
WHERE `first_name` IS NULL
  AND `name` IS NOT NULL
  AND TRIM(`name`) <> '';

UPDATE `users`
SET `last_name` = ''
WHERE `first_name` IS NOT NULL AND `last_name` IS NULL;

-- ------------------------------------------------------------
-- 8. salon_settings — setup marker + upgrade seed.
--
--    8a. `setup_completed_at` is the ONLY reliable "this install has
--        been set up" flag. The row itself is not a valid marker,
--        because an upgraded database already has one.
--        NULL  => first-time setup has not been finished yet
--        NOT NULL => salon identity + weekly hours are configured
--
--    8b. The seed runs ONLY when the table is empty AND at least one
--        Administrator already exists, i.e. this is an UPGRADE of a
--        live install. On a genuinely fresh (empty) database nothing
--        is inserted, so setup.php always runs and no placeholder
--        owner name is invented. Re-running is a no-op, so later
--        Administrator edits are never overwritten.
--    Both owner names are COALESCEd so the NOT NULL columns can
--    never be violated by an Administrator with no last name (or by
--    an empty users table).
-- ------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'salon_settings' AND COLUMN_NAME = 'setup_completed_at'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `salon_settings`
     ADD COLUMN `setup_completed_at` DATETIME NULL DEFAULT NULL AFTER `owner_last_name`',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @profile_empty = (SELECT COUNT(*) FROM `salon_settings`);
SET @admin_count   = (SELECT COUNT(*) FROM `users` WHERE `role` = 'admin');

SET @sql = IF(@profile_empty = 0 AND @admin_count > 0,
  'INSERT INTO `salon_settings` (`id`, `salon_name`, `owner_first_name`, `owner_last_name`, `setup_completed_at`)
   VALUES (1,
           COALESCE(NULLIF((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''salon_name''), ''''), ''Beauty Salon''),
           COALESCE((SELECT `first_name` FROM `users` WHERE `role` = ''admin'' ORDER BY `id` LIMIT 1), ''Owner''),
           COALESCE((SELECT `last_name`  FROM `users` WHERE `role` = ''admin'' ORDER BY `id` LIMIT 1), ''''),
           NOW())',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- An install that already had a profile row AND an Administrator is
-- an upgrade: it is already configured, so stamp the marker now.
SET @sql = IF(@admin_count > 0 AND @profile_empty > 0,
  'UPDATE `salon_settings`
      SET `setup_completed_at` = NOW()
    WHERE `setup_completed_at` IS NULL',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 9. Seed business_hours — 7 rows, Monday..Sunday.
--    The opening / closing time is inherited from the existing
--    `business_open` / `business_close` settings so the schedule
--    the salon is already running on is preserved exactly.
--    Sunday defaults to CLOSED, matching the pre-existing
--    `business_hours_display` text.
--    Runs ONLY when the table is empty AND an Administrator exists
--    (an upgrade). A fresh install gets its week from setup.php, so
--    the owner is never shown hours they did not choose.
-- ------------------------------------------------------------
SET @hours_empty = (SELECT COUNT(*) FROM `business_hours`);

SET @sql = IF(@hours_empty = 0 AND @admin_count > 0,
  'INSERT INTO `business_hours` (`day_of_week`, `is_open`, `opening_time`, `closing_time`) VALUES
     (1, 1, COALESCE((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''business_open''), ''09:00:00''),
            COALESCE((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''business_close''), ''18:00:00'')),
     (2, 1, COALESCE((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''business_open''), ''09:00:00''),
            COALESCE((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''business_close''), ''18:00:00'')),
     (3, 1, COALESCE((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''business_open''), ''09:00:00''),
            COALESCE((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''business_close''), ''18:00:00'')),
     (4, 1, COALESCE((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''business_open''), ''09:00:00''),
            COALESCE((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''business_close''), ''18:00:00'')),
     (5, 1, COALESCE((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''business_open''), ''09:00:00''),
            COALESCE((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''business_close''), ''18:00:00'')),
     (6, 1, COALESCE((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''business_open''), ''09:00:00''),
            COALESCE((SELECT `setting_value` FROM `settings` WHERE `setting_key` = ''business_close''), ''18:00:00'')),
     (7, 0, NULL, NULL)',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 10. Seed staff_working_hours for staff that has no schedule.
--     Default: every day the salon is OPEN is a working day, with
--     the staff shift equal to the salon hours. That is the most
--     permissive (and therefore safest) default: it never hides
--     an existing staff member from an already-booked appointment.
--     Runs ONLY for staff without rows, so it is safe to re-run.
-- ------------------------------------------------------------
INSERT INTO `staff_working_hours` (`staff_id`, `day_of_week`, `is_working`, `start_time`, `end_time`)
SELECT s.`id`, bh.`day_of_week`, bh.`is_open`, bh.`opening_time`, bh.`closing_time`
  FROM `staff` s
  CROSS JOIN `business_hours` bh
 WHERE NOT EXISTS (
         SELECT 1 FROM `staff_working_hours` w
          WHERE w.`staff_id` = s.`id` AND w.`day_of_week` = bh.`day_of_week`
       );

-- Inactive staff are not rostered, but their row is kept so
-- re-activating a staff member does not silently drop the schedule.
UPDATE `staff_working_hours` w
  JOIN `staff` s ON s.`id` = w.`staff_id`
   SET w.`is_working` = 0,
       w.`start_time` = NULL,
       w.`end_time`   = NULL
 WHERE s.`status` = 'inactive'
   AND w.`is_working` = 1;

-- ------------------------------------------------------------
-- 11. Mirror the salon name into the key/value `settings` table so
--     every existing get_setting('salon_name') call site keeps
--     working unchanged. salon_settings is the source of truth.
-- ------------------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`)
SELECT 'salon_name', `salon_name` FROM `salon_settings` WHERE `id` = 1
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- Same for the owner's two names, so header / receipt / report
-- views can read them without a second query.
INSERT INTO `settings` (`setting_key`, `setting_value`)
SELECT 'owner_first_name', `owner_first_name` FROM `salon_settings` WHERE `id` = 1
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

INSERT INTO `settings` (`setting_key`, `setting_value`)
SELECT 'owner_last_name', `owner_last_name` FROM `salon_settings` WHERE `id` = 1
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- ------------------------------------------------------------
-- 12. Result summary. The `SELECT 1` lines printed by the blocks
--     above are intentional no-op branches, not errors.
-- ------------------------------------------------------------
SELECT 'salon_settings'  AS `object`, COUNT(*) AS `rows_in_place` FROM `salon_settings`
UNION ALL SELECT 'setup_completed_at', IFNULL(CAST(`setup_completed_at` AS CHAR), '(NULL = setup pending)') FROM `salon_settings` WHERE `id` = 1
UNION ALL SELECT 'business_hours', COUNT(*) FROM `business_hours`
UNION ALL SELECT 'business_breaks', COUNT(*) FROM `business_breaks`
UNION ALL SELECT 'staff_working_hours', COUNT(*) FROM `staff_working_hours`
UNION ALL SELECT 'staff_breaks', COUNT(*) FROM `staff_breaks`
UNION ALL SELECT 'staff_services', COUNT(*) FROM `staff_services`
UNION ALL SELECT 'users (admins)', COUNT(*) FROM `users` WHERE `role` = 'admin'
UNION ALL SELECT 'appointments (untouched)', COUNT(*) FROM `appointments`;

-- ============================================================
--  END OF MIGRATION
-- ============================================================
