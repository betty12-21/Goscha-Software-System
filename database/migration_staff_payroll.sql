-- ============================================================
--  Beauty-php-ai — Staff ↔ Payroll relationship migration
--  Links each `payroll` record to a real `staff` member.
--
--  Design (common-sense payroll):
--    * `payroll.staff_id`  → FK to `staff.id`  (the real link)
--    * `payroll.staff_name` → snapshot of the full name at pay time,
--      so history survives staff renames and staff deletion
--      (FK is ON DELETE SET NULL — the payroll record is kept).
--    * All existing business rules are unchanged: net salary is still
--      salary + commission + tips + bonus − deductions, per pay period,
--      with the same paid/pending workflow.
--
--  Safe to run multiple times (idempotent). Existing data is never
--  modified other than back-filling the new staff_id link:
--    1. Matches existing payroll rows to staff BY FULL NAME.
--    2. For payroll rows whose staff member does not exist yet,
--       creates the missing staff record from the payroll snapshot,
--       then links them. (So the data is always real and relational.)
--
--  Run: mysql -u root beauty_php_ai < database/migration_staff_payroll.sql
-- ============================================================

USE `beauty_php_ai`;

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. Add `payroll.staff_id` column when missing
-- ------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'payroll'
    AND COLUMN_NAME = 'staff_id'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `payroll` ADD COLUMN `staff_id` INT NULL DEFAULT NULL AFTER `id`',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2. Index when missing
-- ------------------------------------------------------------
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'payroll'
    AND INDEX_NAME = 'idx_payroll_staff'
);

SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE `payroll` ADD INDEX `idx_payroll_staff` (`staff_id`)',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3. Foreign key when missing (history preserved on deletion)
-- ------------------------------------------------------------
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'payroll'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME = 'fk_payroll_staff'
);

SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE `payroll`
     ADD CONSTRAINT `fk_payroll_staff`
     FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL',
  'SELECT 1');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 4. Back-fill staff_id by matching the stored full name exactly
--    (works regardless of auto-increment ids; idempotent)
-- ------------------------------------------------------------
UPDATE `payroll` p
LEFT JOIN `staff` s
  ON CONCAT(s.first_name, ' ', s.last_name) = TRIM(p.staff_name)
SET p.staff_id = s.id
WHERE p.staff_id IS NULL
  AND s.id IS NOT NULL;

-- ------------------------------------------------------------
-- 5. Create missing staff records from payroll snapshots so that
--    every payroll row points at a real staff member.
--    Only multi-word names are materialised; names we already
--    matched above are skipped.
-- ------------------------------------------------------------
INSERT INTO `staff` (`first_name`,`last_name`,`phone`,`salary`,`notes`,`status`)
SELECT DISTINCT
  SUBSTRING_INDEX(TRIM(p.staff_name), ' ', 1),
  TRIM(SUBSTRING(TRIM(p.staff_name) FROM LOCATE(' ', TRIM(p.staff_name)) + 1)),
  '000 000 0000',
  p.salary,
  'Automatically created from an existing payroll record.',
  'active'
FROM `payroll` p
WHERE p.staff_id IS NULL
  AND LOCATE(' ', TRIM(p.staff_name)) > 0
  AND NOT EXISTS (
    SELECT 1 FROM `staff` s
    WHERE CONCAT(s.first_name, ' ', s.last_name) = TRIM(p.staff_name)
  );

-- ------------------------------------------------------------
-- 6. Link the freshly created staff members to their payroll rows
-- ------------------------------------------------------------
UPDATE `payroll` p
LEFT JOIN `staff` s
  ON CONCAT(s.first_name, ' ', s.last_name) = TRIM(p.staff_name)
SET p.staff_id = s.id
WHERE p.staff_id IS NULL
  AND s.id IS NOT NULL;

-- ============================================================
--  END OF MIGRATION
-- ============================================================