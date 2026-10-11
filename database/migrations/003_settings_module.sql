-- Beauty-php-ai — migration 003: Settings module
--
-- Extends `holidays` from a single full-day closure per date into a flexible
-- holiday / temporary-hours record (date range, special opening hours, notes,
-- active flag), and adds `salon_backups` for the per-salon backup history.
--
-- Idempotent: safe to run on an install that already has the columns/table.
-- MariaDB 10.4 supports ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS,
-- so re-running never raises 1060/1050.

-- ------------------------------------------------------------------
-- holidays — keep holiday_date/title as-is; add the new columns.
-- Existing rows become full-day closures (is_closed = 1), which is
-- exactly how they behaved before this migration.
-- ------------------------------------------------------------------
ALTER TABLE `holidays` ADD COLUMN IF NOT EXISTS `end_date`   DATE         NULL     AFTER `holiday_date`;
ALTER TABLE `holidays` ADD COLUMN IF NOT EXISTS `is_closed`  TINYINT(1)   NOT NULL DEFAULT 1 AFTER `end_date`;
ALTER TABLE `holidays` ADD COLUMN IF NOT EXISTS `open_time`  TIME         NULL     AFTER `is_closed`;
ALTER TABLE `holidays` ADD COLUMN IF NOT EXISTS `close_time` TIME         NULL     AFTER `open_time`;
ALTER TABLE `holidays` ADD COLUMN IF NOT EXISTS `notes`      VARCHAR(255) NULL     AFTER `close_time`;
ALTER TABLE `holidays` ADD COLUMN IF NOT EXISTS `is_active`  TINYINT(1)   NOT NULL DEFAULT 1 AFTER `notes`;

-- ------------------------------------------------------------------
-- salon_backups — one history row per generated per-salon export.
-- The file itself lives outside the web root (storage/backups), so the
-- table only records metadata for the dashboard history list.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `salon_backups` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `salon_id`   int(11) NOT NULL,
  `filename`   varchar(255) NOT NULL,
  `file_size`  int(11) NOT NULL DEFAULT 0,
  `status`     enum('completed','failed') NOT NULL DEFAULT 'completed',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_salon_backups_salon` (`salon_id`),
  CONSTRAINT `fk_salon_backups_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `fk_salon_backups_user`  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
