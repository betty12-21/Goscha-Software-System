-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: beauty_php_ai
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `business_breaks`
--

DROP TABLE IF EXISTS `business_breaks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_breaks`
--

LOCK TABLES `business_breaks` WRITE;
/*!40000 ALTER TABLE `business_breaks` DISABLE KEYS */;
/*!40000 ALTER TABLE `business_breaks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `business_hours`
--

DROP TABLE IF EXISTS `business_hours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=603 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_hours`
--

LOCK TABLES `business_hours` WRITE;
/*!40000 ALTER TABLE `business_hours` DISABLE KEYS */;
INSERT INTO `business_hours` VALUES (1,1,1,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(2,1,2,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(3,1,3,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(4,1,4,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(5,1,5,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(6,1,6,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(7,1,7,0,NULL,NULL,'2026-09-27 21:21:39','2026-10-03 03:07:57');
/*!40000 ALTER TABLE `business_hours` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  KEY `idx_customers_name` (`last_name`,`first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pwd_token` (`token`),
  KEY `idx_pwd_user` (`user_id`),
  CONSTRAINT `fk_pwd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `remember_tokens`
--

DROP TABLE IF EXISTS `remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rem_token` (`token`),
  KEY `idx_rem_user` (`user_id`),
  CONSTRAINT `fk_rem_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `remember_tokens`
--

LOCK TABLES `remember_tokens` WRITE;
/*!40000 ALTER TABLE `remember_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `remember_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `salon_settings`
--

DROP TABLE IF EXISTS `salon_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  KEY `idx_salon_settings_salon` (`salon_id`),
  CONSTRAINT `fk_salon_settings_salon` FOREIGN KEY (`salon_id`) REFERENCES `salons` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `salon_settings`
--

LOCK TABLES `salon_settings` WRITE;
/*!40000 ALTER TABLE `salon_settings` DISABLE KEYS */;
INSERT INTO `salon_settings` VALUES (1,1,'Goscha Software','Goscha','Administrator','2026-09-27 21:25:51','2026-09-27 21:21:39','2026-10-03 03:07:57');
/*!40000 ALTER TABLE `salon_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `salons`
--

DROP TABLE IF EXISTS `salons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `salons`
--

LOCK TABLES `salons` WRITE;
/*!40000 ALTER TABLE `salons` DISABLE KEYS */;
INSERT INTO `salons` VALUES (1,'Goscha Software','Goscha','Administrator',NULL,NULL,NULL,'Africa/Addis_Ababa','ETB','active','2026-09-27 21:25:51','2026-10-03 03:07:56','2026-10-03 03:07:56');
/*!40000 ALTER TABLE `salons` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_categories`
--

DROP TABLE IF EXISTS `service_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_name` (`name`),
  KEY `idx_cat_status_order` (`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_categories`
--

LOCK TABLES `service_categories` WRITE;
/*!40000 ALTER TABLE `service_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_subcategories`
--

DROP TABLE IF EXISTS `service_subcategories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_subcategories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subcat_cat_name` (`category_id`,`name`),
  KEY `idx_subcat_cat` (`category_id`),
  KEY `idx_subcat_order` (`status`,`sort_order`),
  CONSTRAINT `fk_subcat_cat` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_subcategories`
--

LOCK TABLES `service_subcategories` WRITE;
/*!40000 ALTER TABLE `service_subcategories` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_subcategories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  CONSTRAINT `fk_services_cat` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`),
  CONSTRAINT `fk_services_subcat` FOREIGN KEY (`subcategory_id`) REFERENCES `service_subcategories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `services`
--

LOCK TABLES `services` WRITE;
/*!40000 ALTER TABLE `services` DISABLE KEYS */;
/*!40000 ALTER TABLE `services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff`
--

DROP TABLE IF EXISTS `staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  KEY `idx_staff_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff`
--

LOCK TABLES `staff` WRITE;
/*!40000 ALTER TABLE `staff` DISABLE KEYS */;
/*!40000 ALTER TABLE `staff` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_breaks`
--

DROP TABLE IF EXISTS `staff_breaks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_breaks`
--

LOCK TABLES `staff_breaks` WRITE;
/*!40000 ALTER TABLE `staff_breaks` DISABLE KEYS */;
/*!40000 ALTER TABLE `staff_breaks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_services`
--

DROP TABLE IF EXISTS `staff_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_services`
--

LOCK TABLES `staff_services` WRITE;
/*!40000 ALTER TABLE `staff_services` DISABLE KEYS */;
/*!40000 ALTER TABLE `staff_services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_working_hours`
--

DROP TABLE IF EXISTS `staff_working_hours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=1226 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_working_hours`
--

LOCK TABLES `staff_working_hours` WRITE;
/*!40000 ALTER TABLE `staff_working_hours` DISABLE KEYS */;
INSERT INTO `staff_working_hours` VALUES (1,1,1,1,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(2,1,2,1,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(3,1,3,1,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(4,1,4,1,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(5,1,5,1,0,NULL,NULL,'2026-09-27 21:21:39','2026-10-03 03:07:57'),(6,1,1,2,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(7,1,2,2,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(8,1,3,2,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(9,1,4,2,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(10,1,5,2,0,NULL,NULL,'2026-09-27 21:21:39','2026-10-03 03:07:57'),(11,1,1,3,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(12,1,2,3,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(13,1,3,3,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(14,1,4,3,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(15,1,5,3,0,NULL,NULL,'2026-09-27 21:21:39','2026-10-03 03:07:57'),(16,1,1,4,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(17,1,2,4,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(18,1,3,4,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(19,1,4,4,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(20,1,5,4,0,NULL,NULL,'2026-09-27 21:21:39','2026-10-03 03:07:57'),(21,1,1,5,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(22,1,2,5,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(23,1,3,5,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(24,1,4,5,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(25,1,5,5,0,NULL,NULL,'2026-09-27 21:21:39','2026-10-03 03:07:57'),(26,1,1,6,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(27,1,2,6,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(28,1,3,6,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(29,1,4,6,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 03:07:57'),(30,1,5,6,0,NULL,NULL,'2026-09-27 21:21:39','2026-10-03 03:07:57'),(31,1,1,7,0,NULL,NULL,'2026-09-27 21:21:39','2026-10-03 03:07:57'),(32,1,2,7,0,NULL,NULL,'2026-09-27 21:21:39','2026-10-03 03:07:57'),(33,1,3,7,0,NULL,NULL,'2026-09-27 21:21:39','2026-10-03 03:07:57'),(34,1,4,7,0,NULL,NULL,'2026-09-27 21:21:39','2026-10-03 03:07:57'),(35,1,5,7,0,NULL,NULL,'2026-09-27 21:21:39','2026-10-03 03:07:57');
/*!40000 ALTER TABLE `staff_working_hours` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'beauty_php_ai'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-03  3:26:31
