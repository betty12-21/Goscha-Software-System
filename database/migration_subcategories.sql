-- ============================================================
-- Migration: add service subcategories
-- Category (Hair) -> Subcategory (Boho Hair) -> Service (Hair Braiding)
--
-- SUPERSEDED by database\migrate_service_hierarchy.php, which is the
-- idempotent migration to run. This file is kept only as a record of
-- the original (2026-09) subcategory change. The delete rules below
-- match the final schema: RESTRICT so deleting a category can never
-- silently dissolve its subcategories or services.
-- ============================================================

CREATE TABLE IF NOT EXISTS `service_subcategories` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `category_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT NULL DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subcat_cat_name` (`category_id`, `name`),
  KEY `idx_subcat_cat` (`category_id`),
  CONSTRAINT `fk_subcat_cat` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `services`
  ADD COLUMN `subcategory_id` INT NULL DEFAULT NULL AFTER `category_id`;

ALTER TABLE `services`
  ADD KEY `idx_services_subcat` (`subcategory_id`),
  ADD CONSTRAINT `fk_services_subcat` FOREIGN KEY (`subcategory_id`) REFERENCES `service_subcategories` (`id`) ON DELETE SET NULL;

INSERT INTO `service_subcategories` (`category_id`, `name`, `description`) VALUES
(1, 'Boho Hair', 'Free-spirited braids, waves and bohemian styles.');
