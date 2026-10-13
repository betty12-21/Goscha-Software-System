-- ============================================================
-- 004_super_admin.sql
-- Phase 4: Super Admin Control Panel.
--
-- Adds:
--   * super_admins               — the platform-level operators (separate
--                                  from any salon's `users` rows)
--   * salon lifecycle status     — pending/active/inactive/suspended/
--                                  rejected/cancelled + approval metadata
--   * salon_modules              — per-salon module permissions
--   * super_admin_audit_logs     — platform-level audit trail
--   * super_admin_login_attempts — brute-force rate limiting
--
-- Idempotent: safe to re-run. MariaDB 10.4 supports
-- ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS, and the FK /
-- index additions are guarded by information_schema checks.
-- MariaDB auto-commits DDL, so do not wrap this in a transaction.
-- ============================================================

SET @old_fk_checks = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. super_admins — platform operators.
--    Deliberately NOT tenant-scoped: a super admin governs the whole
--    install, so this table has no salon_id.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `super_admins` (
  `id`            int(11) NOT NULL AUTO_INCREMENT,
  `name`          varchar(150) NOT NULL,
  `email`         varchar(255) NOT NULL,
  `password`      varchar(255) NOT NULL,
  `status`        enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_super_admins_email` (`email`),
  KEY `idx_super_admins_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. salons — widen the lifecycle status and add approval metadata.
--    `active` stays the only status that grants access; every other
--    value is blocked by require_salon_context().
-- ------------------------------------------------------------
ALTER TABLE `salons`
  MODIFY COLUMN `status`
    enum('pending','active','inactive','suspended','rejected','cancelled')
    NOT NULL DEFAULT 'active';

ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `approved_at`     datetime      NULL AFTER `setup_completed_at`;
ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `approved_by`     int(11)       NULL AFTER `approved_at`;
ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `rejected_at`     datetime      NULL AFTER `approved_by`;
ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `rejected_by`     int(11)       NULL AFTER `rejected_at`;
ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `suspended_at`    datetime      NULL AFTER `rejected_by`;
ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `suspended_by`    int(11)       NULL AFTER `suspended_at`;
ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `cancelled_at`    datetime      NULL AFTER `suspended_by`;
ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `cancelled_by`    int(11)       NULL AFTER `cancelled_at`;
ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `restored_at`     datetime      NULL AFTER `cancelled_by`;
ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `status_reason`   varchar(500)  NULL AFTER `restored_at`;
ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `admin_notes`     text          NULL AFTER `status_reason`;
ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `access_profile`  varchar(40)   NOT NULL DEFAULT 'full' AFTER `admin_notes`;
ALTER TABLE `salons` ADD COLUMN IF NOT EXISTS `last_activity_at` datetime     NULL AFTER `access_profile`;

-- Index the new status column for the accounts list / dashboard counters.
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'salons' AND INDEX_NAME = 'idx_salons_status');
SET @sql := IF(@has = 0,
  'ALTER TABLE `salons` ADD KEY `idx_salons_status` (`status`)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Foreign keys from the *_by audit columns to super_admins.
SET @has := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'salons' AND CONSTRAINT_NAME = 'fk_salons_approved_by');
SET @sql := IF(@has = 0,
  'ALTER TABLE `salons` ADD CONSTRAINT `fk_salons_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `super_admins` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 3. salon_modules — one row per (salon, module) permission.
--    A missing row means "not granted"; the app defaults to denied so
--    a module added in code is not silently exposed to every tenant.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `salon_modules` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `salon_id`   int(11) NOT NULL,
  `module_key` varchar(50) NOT NULL,
  `enabled`    tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_salon_module` (`salon_id`,`module_key`),
  KEY `idx_salon_modules_salon` (`salon_id`),
  CONSTRAINT `fk_salon_modules_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_salon_modules_super` FOREIGN KEY (`updated_by`) REFERENCES `super_admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed every existing salon with the full module set enabled. Profile
-- gating is applied later from the panel; this only guarantees a salon
-- that predates the panel keeps every module it already had.
INSERT INTO `salon_modules` (`salon_id`, `module_key`, `enabled`, `updated_at`)
SELECT s.`id`, m.`module_key`, 1, NOW()
  FROM `salons` s
  CROSS JOIN (
    SELECT 'dashboard'     AS module_key UNION ALL
    SELECT 'customers'                UNION ALL
    SELECT 'appointments'             UNION ALL
    SELECT 'calendar'                 UNION ALL
    SELECT 'walkins'                  UNION ALL
    SELECT 'services'                 UNION ALL
    SELECT 'staff'                    UNION ALL
    SELECT 'payments'                 UNION ALL
    SELECT 'invoices'                 UNION ALL
    SELECT 'inventory'                UNION ALL
    SELECT 'suppliers'                UNION ALL
    SELECT 'payroll'                  UNION ALL
    SELECT 'reports'                  UNION ALL
    SELECT 'notifications'            UNION ALL
    SELECT 'settings'
  ) m
  LEFT JOIN `salon_modules` sm
    ON sm.`salon_id` = s.`id` AND sm.`module_key` = m.`module_key`
 WHERE sm.`id` IS NULL;

-- ------------------------------------------------------------
-- 4. super_admin_audit_logs — platform-level audit trail.
--    Kept separate from the salon-scoped `audit_logs` so a tenant's
--    own audit screen never sees, and never leaks, platform actions.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `super_admin_audit_logs` (
  `id`                bigint(20) NOT NULL AUTO_INCREMENT,
  `super_admin_id`    int(11) DEFAULT NULL,
  `super_admin_name`  varchar(150) DEFAULT NULL,
  `action`            varchar(50) NOT NULL,
  `target_salon_id`   int(11) DEFAULT NULL,
  `target_salon_name` varchar(150) DEFAULT NULL,
  `description`       varchar(500) DEFAULT NULL,
  `ip_address`        varchar(45) DEFAULT NULL,
  `created_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sa_audit_admin` (`super_admin_id`),
  KEY `idx_sa_audit_salon` (`target_salon_id`),
  KEY `idx_sa_audit_created` (`created_at`),
  CONSTRAINT `fk_sa_audit_super` FOREIGN KEY (`super_admin_id`) REFERENCES `super_admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. super_admin_login_attempts — rate limiting.
--    The login controller counts recent failures per (email, ip) and
--    refuses further attempts once the window threshold is hit.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `super_admin_login_attempts` (
  `id`           bigint(20) NOT NULL AUTO_INCREMENT,
  `email`        varchar(255) NOT NULL,
  `ip_address`   varchar(45) NOT NULL,
  `successful`   tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sa_attempts_lookup` (`email`,`ip_address`,`attempted_at`),
  KEY `idx_sa_attempts_created` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = @old_fk_checks;
