-- Beauty-php-ai salon backup
-- Salon #1 generated 2026-10-09 02:02:23
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE `salons` (
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `salons` (`id`,`salon_name`,`owner_first_name`,`owner_last_name`,`email`,`phone`,`address`,`timezone`,`currency`,`status`,`setup_completed_at`,`created_at`,`updated_at`) VALUES ('1','GOSCHA Beauty Salon','Betty','Goscha','betty@goscha.test','0911000000','Addis','Africa/Addis_Ababa','ETB','active','2026-10-09 03:02:13','2026-10-09 03:02:13','2026-10-09 03:02:22');

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','receptionist') NOT NULL DEFAULT 'admin',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_salon` (`salon_id`),
  CONSTRAINT `fk_users_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`,`salon_id`,`name`,`first_name`,`last_name`,`email`,`phone`,`password`,`role`,`status`,`created_at`,`updated_at`) VALUES ('1','1','Betty Goscha','Betty','Goscha','betty@goscha.test','0911100000','$2y$10$oSNpl1OIw9wBtLxxWpgWReha5t.IteEzCpvOI2FexpZtb4WH0LYgC','admin','active','2026-10-09 03:02:14','2026-10-09 03:02:14');
INSERT INTO `users` (`id`,`salon_id`,`name`,`first_name`,`last_name`,`email`,`phone`,`password`,`role`,`status`,`created_at`,`updated_at`) VALUES ('3','1','Goscha Staff',NULL,NULL,'staff1@goscha.test','0911000011','$2y$10$2MEpCu62uWLN1CjVCF3jNOs93krdGTIjF8OuX5HzCl1NJsDrHNVU.','receptionist','active','2026-10-09 03:02:21','2026-10-09 03:02:21');

CREATE TABLE `salon_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `salon_name` varchar(150) NOT NULL,
  `owner_first_name` varchar(100) NOT NULL,
  `owner_last_name` varchar(100) NOT NULL,
  `setup_completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_salon_settings_single` (`id`),
  UNIQUE KEY `uq_salon_settings_salon` (`salon_id`),
  KEY `idx_salon_settings_salon` (`salon_id`),
  CONSTRAINT `fk_salon_settings_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `salon_settings` (`id`,`salon_id`,`salon_name`,`owner_first_name`,`owner_last_name`,`setup_completed_at`,`created_at`,`updated_at`) VALUES ('1','1','GOSCHA Beauty Salon','Betty','Goscha','2026-10-09 03:02:13','2026-10-09 03:02:13','2026-10-09 03:02:13');

CREATE TABLE `business_hours` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL,
  `is_open` tinyint(1) NOT NULL DEFAULT 1,
  `opening_time` time DEFAULT NULL,
  `closing_time` time DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_business_hours_day` (`salon_id`,`day_of_week`),
  KEY `idx_business_hours_salon` (`salon_id`),
  KEY `idx_bh_salon_day` (`salon_id`,`day_of_week`),
  CONSTRAINT `fk_business_hours_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `ck_business_hours_day` CHECK (`day_of_week` between 1 and 7)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `business_hours` (`id`,`salon_id`,`day_of_week`,`is_open`,`opening_time`,`closing_time`,`created_at`,`updated_at`) VALUES ('1','1','1','1','09:00:00','18:00:00','2026-10-09 03:02:14','2026-10-09 03:02:14');
INSERT INTO `business_hours` (`id`,`salon_id`,`day_of_week`,`is_open`,`opening_time`,`closing_time`,`created_at`,`updated_at`) VALUES ('2','1','2','1','09:00:00','18:00:00','2026-10-09 03:02:14','2026-10-09 03:02:14');
INSERT INTO `business_hours` (`id`,`salon_id`,`day_of_week`,`is_open`,`opening_time`,`closing_time`,`created_at`,`updated_at`) VALUES ('3','1','3','1','09:00:00','18:00:00','2026-10-09 03:02:14','2026-10-09 03:02:14');
INSERT INTO `business_hours` (`id`,`salon_id`,`day_of_week`,`is_open`,`opening_time`,`closing_time`,`created_at`,`updated_at`) VALUES ('4','1','4','1','09:00:00','18:00:00','2026-10-09 03:02:14','2026-10-09 03:02:14');
INSERT INTO `business_hours` (`id`,`salon_id`,`day_of_week`,`is_open`,`opening_time`,`closing_time`,`created_at`,`updated_at`) VALUES ('5','1','5','1','09:00:00','18:00:00','2026-10-09 03:02:14','2026-10-09 03:02:14');
INSERT INTO `business_hours` (`id`,`salon_id`,`day_of_week`,`is_open`,`opening_time`,`closing_time`,`created_at`,`updated_at`) VALUES ('6','1','6','1','09:00:00','18:00:00','2026-10-09 03:02:14','2026-10-09 03:02:14');
INSERT INTO `business_hours` (`id`,`salon_id`,`day_of_week`,`is_open`,`opening_time`,`closing_time`,`created_at`,`updated_at`) VALUES ('7','1','7','0',NULL,NULL,'2026-10-09 03:02:14','2026-10-09 03:02:14');

CREATE TABLE `business_breaks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_business_breaks_day` (`day_of_week`),
  KEY `idx_business_breaks_salon` (`salon_id`),
  CONSTRAINT `fk_business_breaks_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `ck_business_breaks_day` CHECK (`day_of_week` between 1 and 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setting_key` (`salon_id`,`setting_key`),
  KEY `idx_settings_salon` (`salon_id`),
  CONSTRAINT `fk_settings_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('1','1','salon_name','GOSCHA Beauty Salon');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('2','1','currency','ETB');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('3','1','slot_interval_minutes','15');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('8','1','salon_tagline','Test tagline');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('9','1','salon_phone','0911000000');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('10','1','salon_email','betty@goscha.test');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('11','1','salon_address','Addis');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('12','1','owner_first_name','Betty');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('13','1','owner_last_name','Goscha');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('14','1','salon_description','Test salon');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('15','1','salon_website','https://goscha.test');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('17','1','tax_rate','15');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('18','1','loyalty_rate','100');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('19','1','appointment_advance_days','30');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('20','1','max_advance_days','30');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('21','1','session_timeout_minutes','60');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('23','1','buffer_minutes','5');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('24','1','cancellation_notice_hours','0');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('25','1','reschedule_notice_hours','0');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('26','1','allow_same_day','1');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('27','1','allow_walkins','1');
INSERT INTO `settings` (`id`,`salon_id`,`setting_key`,`setting_value`) VALUES ('28','1','allow_after_hours','0');

CREATE TABLE `holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `holiday_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_closed` tinyint(1) NOT NULL DEFAULT 1,
  `open_time` time DEFAULT NULL,
  `close_time` time DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `title` varchar(150) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_holiday_date` (`holiday_date`),
  KEY `idx_holidays_salon` (`salon_id`),
  CONSTRAINT `fk_holidays_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `holidays` (`id`,`salon_id`,`holiday_date`,`end_date`,`is_closed`,`open_time`,`close_time`,`notes`,`is_active`,`title`) VALUES ('1','1','2026-10-23',NULL,'1',NULL,NULL,NULL,'1','Staff Training Day');
INSERT INTO `holidays` (`id`,`salon_id`,`holiday_date`,`end_date`,`is_closed`,`open_time`,`close_time`,`notes`,`is_active`,`title`) VALUES ('2','1','2026-11-23',NULL,'1',NULL,NULL,NULL,'1','Salon Annual Break');
INSERT INTO `holidays` (`id`,`salon_id`,`holiday_date`,`end_date`,`is_closed`,`open_time`,`close_time`,`notes`,`is_active`,`title`) VALUES ('3','1','2026-10-10','2026-10-10','0','10:00:00','14:00:00','Open half day','1','Special Test Hours');
INSERT INTO `holidays` (`id`,`salon_id`,`holiday_date`,`end_date`,`is_closed`,`open_time`,`close_time`,`notes`,`is_active`,`title`) VALUES ('4','1','2026-10-10','2026-10-10','0','10:00:00','14:00:00','Open half day','1','Special Test Hours');

CREATE TABLE `service_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_name` (`salon_id`,`name`),
  KEY `idx_cat_status_order` (`status`,`sort_order`),
  KEY `idx_service_categories_salon` (`salon_id`),
  CONSTRAINT `fk_service_categories_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `service_categories` (`id`,`salon_id`,`name`,`description`,`status`,`sort_order`,`created_at`,`updated_at`) VALUES ('1','1','GOSCHA Hair',NULL,'active','1','2026-10-09 03:02:14','2026-10-09 03:02:14');

CREATE TABLE `service_subcategories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subcat_cat_name` (`salon_id`,`category_id`,`name`),
  KEY `idx_subcat_cat` (`category_id`),
  KEY `idx_subcat_order` (`status`,`sort_order`),
  KEY `idx_service_subcategories_salon` (`salon_id`),
  CONSTRAINT `fk_service_subcategories_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `fk_subcat_cat` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `subcategory_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 30,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_services_cat` (`category_id`),
  KEY `idx_services_subcat` (`subcategory_id`),
  KEY `idx_services_listing` (`status`,`category_id`,`subcategory_id`,`sort_order`),
  KEY `idx_services_salon` (`salon_id`),
  KEY `idx_svc_salon_list` (`salon_id`,`status`,`category_id`,`sort_order`),
  CONSTRAINT `fk_services_cat` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`),
  CONSTRAINT `fk_services_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `fk_services_subcat` FOREIGN KEY (`subcategory_id`) REFERENCES `service_subcategories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `services` (`id`,`salon_id`,`category_id`,`subcategory_id`,`name`,`description`,`duration_minutes`,`price`,`status`,`sort_order`,`created_at`,`updated_at`) VALUES ('1','1','1',NULL,'GOSCHA Cut',NULL,'60','100.00','active','1','2026-10-09 03:02:14','2026-10-09 03:02:14');

CREATE TABLE `staff` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('female','male','other') DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `emergency_contact_name` varchar(150) DEFAULT NULL,
  `emergency_contact_phone` varchar(20) DEFAULT NULL,
  `salary` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_staff_name` (`last_name`,`first_name`),
  KEY `idx_staff_status` (`status`),
  KEY `idx_staff_salon` (`salon_id`),
  KEY `idx_staff_salon_list` (`salon_id`,`status`),
  CONSTRAINT `fk_staff_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `staff` (`id`,`salon_id`,`first_name`,`last_name`,`phone`,`email`,`date_of_birth`,`gender`,`address`,`emergency_contact_name`,`emergency_contact_phone`,`salary`,`notes`,`status`,`created_at`,`updated_at`) VALUES ('1','1','Goscha','Stylist','0922100000',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-10-09 03:02:14','2026-10-09 03:02:14');

CREATE TABLE `staff_working_hours` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL,
  `is_working` tinyint(1) NOT NULL DEFAULT 0,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_hours_day` (`staff_id`,`day_of_week`),
  KEY `idx_staff_hours_day` (`day_of_week`),
  KEY `idx_staff_working_hours_salon` (`salon_id`),
  KEY `idx_swh_salon_staff` (`salon_id`,`staff_id`),
  CONSTRAINT `fk_staff_hours_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_staff_working_hours_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `ck_staff_hours_day` CHECK (`day_of_week` between 1 and 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `staff_breaks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_staff_breaks_staff_day` (`staff_id`,`day_of_week`),
  KEY `idx_staff_breaks_salon` (`salon_id`),
  CONSTRAINT `fk_staff_breaks_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `fk_staff_breaks_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_staff_breaks_day` CHECK (`day_of_week` between 1 and 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `staff_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_service` (`staff_id`,`service_id`),
  KEY `idx_staff_services_service` (`service_id`),
  KEY `idx_staff_services_salon` (`salon_id`),
  CONSTRAINT `fk_staff_services_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `fk_staff_services_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_staff_services_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('female','male','other') DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `skin_type` varchar(100) DEFAULT NULL,
  `hair_type` varchar(100) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `loyalty_points` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_customers_phone` (`phone`),
  KEY `idx_customers_name` (`last_name`,`first_name`),
  KEY `idx_customers_salon` (`salon_id`),
  KEY `idx_cust_salon_name` (`salon_id`,`last_name`,`first_name`),
  CONSTRAINT `fk_customers_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `customers` (`id`,`salon_id`,`first_name`,`last_name`,`phone`,`email`,`password`,`date_of_birth`,`gender`,`address`,`skin_type`,`hair_type`,`allergies`,`notes`,`loyalty_points`,`created_at`,`updated_at`) VALUES ('1','1','Goscha','Customer','0933100000',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','2026-10-09 03:02:14','2026-10-09 03:02:14');
INSERT INTO `customers` (`id`,`salon_id`,`first_name`,`last_name`,`phone`,`email`,`password`,`date_of_birth`,`gender`,`address`,`skin_type`,`hair_type`,`allergies`,`notes`,`loyalty_points`,`created_at`,`updated_at`) VALUES ('3','1','Zeta','Alpha','0911999001',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'0','2026-10-09 03:02:17','2026-10-09 03:02:17');

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `appointment_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('pending','confirmed','in_progress','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('pending','partial','paid','refunded') NOT NULL DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_appt_date` (`appointment_date`),
  KEY `idx_appt_status` (`status`),
  KEY `idx_appt_customer` (`customer_id`),
  KEY `idx_appt_staff` (`staff_id`),
  KEY `idx_appt_created_by` (`created_by`),
  KEY `idx_appointments_salon` (`salon_id`),
  KEY `idx_appt_salon_date` (`salon_id`,`appointment_date`),
  KEY `idx_appt_salon_staff` (`salon_id`,`staff_id`,`appointment_date`),
  KEY `idx_appt_salon_cust` (`salon_id`,`customer_id`),
  CONSTRAINT `fk_appointments_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `fk_appt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appt_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `appointments` (`id`,`salon_id`,`customer_id`,`staff_id`,`appointment_date`,`start_time`,`end_time`,`status`,`notes`,`subtotal`,`discount`,`tax`,`total_amount`,`payment_status`,`created_by`,`created_at`,`updated_at`) VALUES ('1','1','1','1','2026-10-09','10:00:00','11:00:00','completed',NULL,'100.00','0.00','0.00','100.00','paid',NULL,'2026-10-09 03:02:14','2026-10-09 03:02:14');

CREATE TABLE `appointment_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `duration_minutes` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_aps_appt` (`appointment_id`),
  KEY `idx_aps_service` (`service_id`),
  KEY `idx_appointment_services_salon` (`salon_id`),
  CONSTRAINT `fk_appointment_services_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `fk_aps_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aps_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `appointment_services` (`id`,`salon_id`,`appointment_id`,`service_id`,`price`,`duration_minutes`) VALUES ('1','1','1','1','100.00','60');

CREATE TABLE `waitlist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `name` varchar(150) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `preferred_date` date DEFAULT NULL,
  `preferred_time` time DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('waiting','converted','cancelled') NOT NULL DEFAULT 'waiting',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wait_status` (`status`),
  KEY `idx_wait_customer` (`customer_id`),
  KEY `idx_wait_service` (`service_id`),
  KEY `idx_waitlist_salon` (`salon_id`),
  CONSTRAINT `fk_wait_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wait_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_waitlist_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `invoice_number` varchar(30) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid',
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inv_number` (`salon_id`,`invoice_number`),
  KEY `idx_inv_appt` (`appointment_id`),
  KEY `idx_inv_customer` (`customer_id`),
  KEY `idx_invoices_salon` (`salon_id`),
  KEY `idx_inv_salon_cust` (`salon_id`,`customer_id`),
  CONSTRAINT `fk_inv_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoices_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `invoices` (`id`,`salon_id`,`invoice_number`,`appointment_id`,`customer_id`,`subtotal`,`discount`,`tax`,`total`,`status`,`issued_at`) VALUES ('1','1','INV-10001','1','1','100.00','0.00','0.00','100.00','paid','2026-10-09 03:02:14');

CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','bank_transfer') NOT NULL DEFAULT 'cash',
  `transaction_reference` varchar(100) DEFAULT NULL,
  `payment_date` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('completed','refunded') NOT NULL DEFAULT 'completed',
  PRIMARY KEY (`id`),
  KEY `idx_pay_appt` (`appointment_id`),
  KEY `idx_pay_customer` (`customer_id`),
  KEY `idx_payments_salon` (`salon_id`),
  CONSTRAINT `fk_pay_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pay_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `payments` (`id`,`salon_id`,`appointment_id`,`customer_id`,`amount`,`payment_method`,`transaction_reference`,`payment_date`,`status`) VALUES ('1','1','1','1','100.00','cash',NULL,'2026-10-09 03:02:14','completed');

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_suppliers_salon` (`salon_id`),
  CONSTRAINT `fk_suppliers_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `suppliers` (`id`,`salon_id`,`name`,`contact_person`,`phone`,`email`,`address`,`status`,`created_at`) VALUES ('1','1','Addis Beauty Supplies','Abel Tadesse','0911 123 456','sales@addisbeauty.com','Bole Medhanealem, Addis Ababa','active','2026-10-09 01:37:17');
INSERT INTO `suppliers` (`id`,`salon_id`,`name`,`contact_person`,`phone`,`email`,`address`,`status`,`created_at`) VALUES ('2','1','Grace Cosmetics Import','Sara Mekonnen','0912 987 654','orders@gracecosmetics.com','Kazanchis, Addis Ababa','active','2026-10-09 01:37:17');
INSERT INTO `suppliers` (`id`,`salon_id`,`name`,`contact_person`,`phone`,`email`,`address`,`status`,`created_at`) VALUES ('3','1','Nairobi Hair Products Ltd','John Omondi','0913 555 789','nairobi.hair@example.com','Industrial Area, Nairobi','active','2026-10-09 01:37:17');
INSERT INTO `suppliers` (`id`,`salon_id`,`name`,`contact_person`,`phone`,`email`,`address`,`status`,`created_at`) VALUES ('4','1','SunCare Essentials','Liya Habte','0914 222 333','hello@suncareet.com','Megenagna, Addis Ababa','active','2026-10-09 01:37:17');
INSERT INTO `suppliers` (`id`,`salon_id`,`name`,`contact_person`,`phone`,`email`,`address`,`status`,`created_at`) VALUES ('5','1','Prime Packaging Co.','Daniel Alemu','0915 444 555','packaging@primeet.com','Kality, Addis Ababa','inactive','2026-10-09 01:37:17');

CREATE TABLE `inventory_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `minimum_stock` int(11) NOT NULL DEFAULT 5,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prod_sku` (`salon_id`,`sku`),
  KEY `idx_prod_supplier` (`supplier_id`),
  KEY `idx_inventory_products_salon` (`salon_id`),
  CONSTRAINT `fk_inventory_products_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `fk_prod_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `inventory_products` (`id`,`salon_id`,`name`,`sku`,`barcode`,`category`,`supplier_id`,`quantity`,`minimum_stock`,`cost_price`,`selling_price`,`status`,`created_at`) VALUES ('1','1','Argan Oil Shampoo 500ml','GLM-SHP-001','6291041500213','Hair Care','1','24','10','350.00','550.00','active','2026-10-09 01:37:17');
INSERT INTO `inventory_products` (`id`,`salon_id`,`name`,`sku`,`barcode`,`category`,`supplier_id`,`quantity`,`minimum_stock`,`cost_price`,`selling_price`,`status`,`created_at`) VALUES ('2','1','Hair Conditioner 500ml','GLM-CND-002','6291041500220','Hair Care','1','8','10','320.00','500.00','active','2026-10-09 01:37:17');
INSERT INTO `inventory_products` (`id`,`salon_id`,`name`,`sku`,`barcode`,`category`,`supplier_id`,`quantity`,`minimum_stock`,`cost_price`,`selling_price`,`status`,`created_at`) VALUES ('3','1','Hair Color Kit – Brown','GLM-HC-003','6291041500237','Hair Care','3','15','5','650.00','950.00','active','2026-10-09 01:37:17');
INSERT INTO `inventory_products` (`id`,`salon_id`,`name`,`sku`,`barcode`,`category`,`supplier_id`,`quantity`,`minimum_stock`,`cost_price`,`selling_price`,`status`,`created_at`) VALUES ('4','1','Facial Cleanser 250ml','GLM-FC-004','6291041500244','Skin Care','4','20','8','280.00','480.00','active','2026-10-09 01:37:17');
INSERT INTO `inventory_products` (`id`,`salon_id`,`name`,`sku`,`barcode`,`category`,`supplier_id`,`quantity`,`minimum_stock`,`cost_price`,`selling_price`,`status`,`created_at`) VALUES ('5','1','Hydrating Face Mask','GLM-FM-005','6291041500251','Skin Care','4','6','10','150.00','320.00','active','2026-10-09 01:37:17');
INSERT INTO `inventory_products` (`id`,`salon_id`,`name`,`sku`,`barcode`,`category`,`supplier_id`,`quantity`,`minimum_stock`,`cost_price`,`selling_price`,`status`,`created_at`) VALUES ('6','1','Nail Polish Set','GLM-NP-006','6291041500268','Nails','2','30','12','220.00','420.00','active','2026-10-09 01:37:17');
INSERT INTO `inventory_products` (`id`,`salon_id`,`name`,`sku`,`barcode`,`category`,`supplier_id`,`quantity`,`minimum_stock`,`cost_price`,`selling_price`,`status`,`created_at`) VALUES ('7','1','Nail Files (pack of 10)','GLM-NF-007','6291041500275','Nails','2','40','15','60.00','120.00','active','2026-10-09 01:37:17');
INSERT INTO `inventory_products` (`id`,`salon_id`,`name`,`sku`,`barcode`,`category`,`supplier_id`,`quantity`,`minimum_stock`,`cost_price`,`selling_price`,`status`,`created_at`) VALUES ('8','1','Massage Oil 500ml','GLM-MO-008','6291041500282','Spa','1','12','6','400.00','700.00','active','2026-10-09 01:37:17');
INSERT INTO `inventory_products` (`id`,`salon_id`,`name`,`sku`,`barcode`,`category`,`supplier_id`,`quantity`,`minimum_stock`,`cost_price`,`selling_price`,`status`,`created_at`) VALUES ('9','1','Makeup Brush Set','GLM-MB-009','6291041500299','Makeup','2','5','5','800.00','1400.00','active','2026-10-09 01:37:17');
INSERT INTO `inventory_products` (`id`,`salon_id`,`name`,`sku`,`barcode`,`category`,`supplier_id`,`quantity`,`minimum_stock`,`cost_price`,`selling_price`,`status`,`created_at`) VALUES ('10','1','Keratin Treatment Kit','GLM-KT-010','6291041500305','Hair Care','3','9','4','1200.00','1800.00','active','2026-10-09 01:37:17');

CREATE TABLE `purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `purchase_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pur_supplier` (`supplier_id`),
  KEY `idx_pur_product` (`product_id`),
  KEY `idx_purchases_salon` (`salon_id`),
  CONSTRAINT `fk_pur_product` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pur_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchases_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `purchases` (`id`,`salon_id`,`supplier_id`,`product_id`,`quantity`,`unit_price`,`total`,`purchase_date`,`created_at`) VALUES ('1','1','1','1','50','320.00','16000.00','2026-09-29','2026-10-09 01:37:17');
INSERT INTO `purchases` (`id`,`salon_id`,`supplier_id`,`product_id`,`quantity`,`unit_price`,`total`,`purchase_date`,`created_at`) VALUES ('2','1','1','2','40','290.00','11600.00','2026-09-29','2026-10-09 01:37:17');
INSERT INTO `purchases` (`id`,`salon_id`,`supplier_id`,`product_id`,`quantity`,`unit_price`,`total`,`purchase_date`,`created_at`) VALUES ('3','1','3','3','30','600.00','18000.00','2026-10-01','2026-10-09 01:37:17');
INSERT INTO `purchases` (`id`,`salon_id`,`supplier_id`,`product_id`,`quantity`,`unit_price`,`total`,`purchase_date`,`created_at`) VALUES ('4','1','4','4','40','250.00','10000.00','2026-10-01','2026-10-09 01:37:17');
INSERT INTO `purchases` (`id`,`salon_id`,`supplier_id`,`product_id`,`quantity`,`unit_price`,`total`,`purchase_date`,`created_at`) VALUES ('5','1','4','5','30','130.00','3900.00','2026-10-02','2026-10-09 01:37:17');
INSERT INTO `purchases` (`id`,`salon_id`,`supplier_id`,`product_id`,`quantity`,`unit_price`,`total`,`purchase_date`,`created_at`) VALUES ('6','1','2','6','25','200.00','5000.00','2026-10-03','2026-10-09 01:37:17');
INSERT INTO `purchases` (`id`,`salon_id`,`supplier_id`,`product_id`,`quantity`,`unit_price`,`total`,`purchase_date`,`created_at`) VALUES ('7','1','2','7','60','50.00','3000.00','2026-10-03','2026-10-09 01:37:17');
INSERT INTO `purchases` (`id`,`salon_id`,`supplier_id`,`product_id`,`quantity`,`unit_price`,`total`,`purchase_date`,`created_at`) VALUES ('8','1','1','8','20','360.00','7200.00','2026-10-04','2026-10-09 01:37:17');
INSERT INTO `purchases` (`id`,`salon_id`,`supplier_id`,`product_id`,`quantity`,`unit_price`,`total`,`purchase_date`,`created_at`) VALUES ('9','1','2','9','10','750.00','7500.00','2026-10-04','2026-10-09 01:37:17');
INSERT INTO `purchases` (`id`,`salon_id`,`supplier_id`,`product_id`,`quantity`,`unit_price`,`total`,`purchase_date`,`created_at`) VALUES ('10','1','3','10','15','1100.00','16500.00','2026-10-05','2026-10-09 01:37:17');

CREATE TABLE `product_sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sale_date` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ps_product` (`product_id`),
  KEY `idx_ps_customer` (`customer_id`),
  KEY `idx_product_sales_salon` (`salon_id`),
  CONSTRAINT `fk_product_sales_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `fk_ps_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ps_product` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `product_sales` (`id`,`salon_id`,`product_id`,`customer_id`,`quantity`,`unit_price`,`total`,`sale_date`) VALUES ('1','1','1','1','1','550.00','550.00','2026-10-07 00:00:00');
INSERT INTO `product_sales` (`id`,`salon_id`,`product_id`,`customer_id`,`quantity`,`unit_price`,`total`,`sale_date`) VALUES ('2','1','3','6','1','950.00','950.00','2026-10-08 00:00:00');
INSERT INTO `product_sales` (`id`,`salon_id`,`product_id`,`customer_id`,`quantity`,`unit_price`,`total`,`sale_date`) VALUES ('3','1','6','4','2','420.00','840.00','2026-10-06 00:00:00');
INSERT INTO `product_sales` (`id`,`salon_id`,`product_id`,`customer_id`,`quantity`,`unit_price`,`total`,`sale_date`) VALUES ('4','1','8','12','1','700.00','700.00','2026-10-08 00:00:00');

CREATE TABLE `payroll` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `staff_name` varchar(100) NOT NULL,
  `salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `commission` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tips` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bonus` decimal(10,2) NOT NULL DEFAULT 0.00,
  `deductions` decimal(10,2) NOT NULL DEFAULT 0.00,
  `net_salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pay_period` varchar(10) NOT NULL,
  `payment_status` enum('paid','pending') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_payroll_period` (`pay_period`),
  KEY `idx_payroll_staff` (`staff_id`),
  KEY `idx_payroll_salon` (`salon_id`),
  CONSTRAINT `fk_payroll_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `fk_payroll_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `payroll` (`id`,`salon_id`,`staff_id`,`staff_name`,`salary`,`commission`,`tips`,`bonus`,`deductions`,`net_salary`,`pay_period`,`payment_status`,`created_at`) VALUES ('1','1','1','Jane Doe','9500.00','1800.00','1200.00','800.00','450.00','12850.00','2026-07','paid','2026-10-09 01:37:17');
INSERT INTO `payroll` (`id`,`salon_id`,`staff_id`,`staff_name`,`salary`,`commission`,`tips`,`bonus`,`deductions`,`net_salary`,`pay_period`,`payment_status`,`created_at`) VALUES ('2','1','2','Sarah Smith','8200.00','1200.00','900.00','500.00','300.00','10500.00','2026-07','paid','2026-10-09 01:37:17');
INSERT INTO `payroll` (`id`,`salon_id`,`staff_id`,`staff_name`,`salary`,`commission`,`tips`,`bonus`,`deductions`,`net_salary`,`pay_period`,`payment_status`,`created_at`) VALUES ('3','1','3','Hana Bekele','8800.00','1500.00','1000.00','600.00','350.00','11550.00','2026-07','paid','2026-10-09 01:37:17');
INSERT INTO `payroll` (`id`,`salon_id`,`staff_id`,`staff_name`,`salary`,`commission`,`tips`,`bonus`,`deductions`,`net_salary`,`pay_period`,`payment_status`,`created_at`) VALUES ('4','1','4','Meron Tesfaye','7800.00','950.00','700.00','400.00','250.00','9600.00','2026-07','paid','2026-10-09 01:37:17');
INSERT INTO `payroll` (`id`,`salon_id`,`staff_id`,`staff_name`,`salary`,`commission`,`tips`,`bonus`,`deductions`,`net_salary`,`pay_period`,`payment_status`,`created_at`) VALUES ('5','1','5','Lily Adams','7000.00','600.00','450.00','0.00','200.00','7850.00','2026-06','paid','2026-10-09 01:37:17');
INSERT INTO `payroll` (`id`,`salon_id`,`staff_id`,`staff_name`,`salary`,`commission`,`tips`,`bonus`,`deductions`,`net_salary`,`pay_period`,`payment_status`,`created_at`) VALUES ('6','1','1','Jane Doe','9500.00','950.00','800.00','0.00','300.00','10950.00','2026-08','pending','2026-10-09 01:37:17');

CREATE TABLE `loyalty_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `points_change` int(11) NOT NULL DEFAULT 0,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ll_customer` (`customer_id`),
  KEY `idx_loyalty_ledger_salon` (`salon_id`),
  CONSTRAINT `fk_ll_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_loyalty_ledger_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `loyalty_ledger` (`id`,`salon_id`,`customer_id`,`points_change`,`reason`,`created_at`) VALUES ('1','1','1','50','New customer welcome bonus','2026-08-10 00:00:00');
INSERT INTO `loyalty_ledger` (`id`,`salon_id`,`customer_id`,`points_change`,`reason`,`created_at`) VALUES ('2','1','1','120','Appointment spending reward','2026-09-29 00:00:00');
INSERT INTO `loyalty_ledger` (`id`,`salon_id`,`customer_id`,`points_change`,`reason`,`created_at`) VALUES ('3','1','1','150','Appointment spending reward','2026-10-08 00:00:00');
INSERT INTO `loyalty_ledger` (`id`,`salon_id`,`customer_id`,`points_change`,`reason`,`created_at`) VALUES ('4','1','2','80','Appointment spending reward','2026-10-04 00:00:00');
INSERT INTO `loyalty_ledger` (`id`,`salon_id`,`customer_id`,`points_change`,`reason`,`created_at`) VALUES ('5','1','4','200','Bridal makeup reward','2026-10-04 00:00:00');
INSERT INTO `loyalty_ledger` (`id`,`salon_id`,`customer_id`,`points_change`,`reason`,`created_at`) VALUES ('6','1','8','220','Appointment spending reward','2026-09-27 00:00:00');

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `message` text DEFAULT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'info',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`),
  KEY `idx_notif_read` (`is_read`),
  KEY `idx_notifications_salon` (`salon_id`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `notifications` (`id`,`salon_id`,`user_id`,`title`,`message`,`type`,`is_read`,`created_at`) VALUES ('1','1','1','Customer created','Zeta Alpha was added as a new customer.','info','0','2026-10-09 03:02:17');

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salon_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `module` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_module` (`module`),
  KEY `idx_audit_logs_salon` (`salon_id`),
  CONSTRAINT `fk_audit_logs_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `audit_logs` (`id`,`salon_id`,`user_id`,`action`,`module`,`description`,`ip_address`,`created_at`) VALUES ('1','1','1','login','auth','User Betty Goscha logged in','127.0.0.1','2026-10-09 03:02:15');
INSERT INTO `audit_logs` (`id`,`salon_id`,`user_id`,`action`,`module`,`description`,`ip_address`,`created_at`) VALUES ('3','1','1','create','customers','Created customer Zeta Alpha','127.0.0.1','2026-10-09 03:02:17');
INSERT INTO `audit_logs` (`id`,`salon_id`,`user_id`,`action`,`module`,`description`,`ip_address`,`created_at`) VALUES ('5','1','1','create','users','Created receptionist account staff1@goscha.test','127.0.0.1','2026-10-09 03:02:21');
INSERT INTO `audit_logs` (`id`,`salon_id`,`user_id`,`action`,`module`,`description`,`ip_address`,`created_at`) VALUES ('6','1','1','settings','settings','Updated salon information','127.0.0.1','2026-10-09 03:02:22');
INSERT INTO `audit_logs` (`id`,`salon_id`,`user_id`,`action`,`module`,`description`,`ip_address`,`created_at`) VALUES ('7','1','1','settings','settings','Updated settings (hours)','127.0.0.1','2026-10-09 03:02:22');
INSERT INTO `audit_logs` (`id`,`salon_id`,`user_id`,`action`,`module`,`description`,`ip_address`,`created_at`) VALUES ('8','1','1','create','holidays','Added holiday Special Test Hours','127.0.0.1','2026-10-09 03:02:22');

SET FOREIGN_KEY_CHECKS=1;
