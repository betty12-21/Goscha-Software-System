-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: beauty_php_ai
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
-- Table structure for table `appointment_services`
--

DROP TABLE IF EXISTS `appointment_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointment_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `duration_minutes` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_aps_appt` (`appointment_id`),
  KEY `idx_aps_service` (`service_id`),
  CONSTRAINT `fk_aps_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aps_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=160 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_services`
--

LOCK TABLES `appointment_services` WRITE;
/*!40000 ALTER TABLE `appointment_services` DISABLE KEYS */;
INSERT INTO `appointment_services` VALUES (1,1,5,1200.00,45),(2,1,1,250.00,30),(3,2,2,600.00,60),(4,3,10,1000.00,60),(5,4,6,500.00,45),(6,5,8,1500.00,60),(7,6,3,1800.00,120),(8,7,5,1200.00,45),(9,8,2,600.00,60),(10,8,1,250.00,30),(11,9,9,8000.00,120),(12,10,4,800.00,45),(13,11,7,550.00,45),(14,12,10,1000.00,60),(15,13,5,1200.00,45),(16,14,2,600.00,60),(17,15,6,500.00,45);
/*!40000 ALTER TABLE `appointment_services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  CONSTRAINT `fk_appt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appt_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=227 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
INSERT INTO `appointments` VALUES (1,1,3,'2026-09-27','09:00:00','09:45:00','confirmed','Regular facial appointment.',1450.00,0.00,217.50,1667.50,'paid',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(2,2,2,'2026-09-27','10:00:00','11:00:00','confirmed','',600.00,0.00,90.00,690.00,'paid',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(3,3,5,'2026-09-27','11:30:00','12:30:00','completed','',1000.00,0.00,150.00,1150.00,'paid',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(4,4,4,'2026-09-27','13:00:00','13:45:00','in_progress','',500.00,0.00,75.00,575.00,'pending',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(5,5,4,'2026-09-27','14:00:00','15:00:00','pending','Makeup for evening event.',1500.00,0.00,225.00,1725.00,'pending',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(6,6,1,'2026-09-27','15:30:00','16:30:00','confirmed','Hair coloring retouch.',1800.00,0.00,270.00,2070.00,'pending',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(7,7,3,'2026-09-28','10:00:00','10:45:00','confirmed','',1200.00,0.00,180.00,1380.00,'pending',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(8,8,1,'2026-09-28','11:00:00','12:00:00','confirmed','Wash and styling.',850.00,0.00,127.50,977.50,'pending',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(9,9,4,'2026-09-28','13:00:00','15:00:00','pending','Bridal makeup appointment.',8000.00,0.00,1200.00,9200.00,'pending',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(10,10,2,'2026-09-29','09:30:00','10:15:00','confirmed','Keratin treatment.',800.00,0.00,120.00,920.00,'pending',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(11,11,3,'2026-09-29','11:00:00','11:45:00','confirmed','',550.00,0.00,82.50,632.50,'pending',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(12,12,1,'2026-09-26','10:00:00','11:00:00','completed','',1000.00,0.00,150.00,1150.00,'paid',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(13,1,3,'2026-09-26','14:00:00','14:45:00','completed','',1200.00,0.00,180.00,1380.00,'paid',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(14,2,1,'2026-09-24','09:00:00','10:00:00','completed','',600.00,0.00,90.00,690.00,'paid',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(15,3,2,'2026-09-24','11:00:00','11:45:00','completed','',500.00,0.00,75.00,575.00,'paid',2,'2026-09-27 21:26:10','2026-09-27 21:26:10');
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `module` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_module` (`module`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=261 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,1,'login','auth','Administrator logged in','127.0.0.1','2026-09-26 00:00:00'),(2,2,'create','customers','Created customer Mahlet Berhanu','127.0.0.1','2026-09-25 00:00:00'),(3,2,'create','appointments','Created appointment #5 for Daniel Alemu','127.0.0.1','2026-09-26 00:00:00'),(4,2,'payment','payments','Recorded cash payment for appointment #3','127.0.0.1','2026-09-27 00:00:00'),(5,1,'settings','settings','Updated business hours settings','127.0.0.1','2026-09-21 00:00:00'),(6,1,'create','staff','Administrator added staff member: Jane Doe.','127.0.0.1','2026-09-23 00:00:00'),(7,1,'create','staff','Administrator added staff member: Sarah Smith.','127.0.0.1','2026-09-23 00:00:00'),(8,1,'update','staff','Administrator updated staff member: Hana Bekele.','127.0.0.1','2026-09-25 00:00:00'),(9,1,'login','auth','User Goscha Administrator logged in','::1','2026-09-28 01:17:09'),(10,1,'settings','settings','Updated system settings','::1','2026-09-28 01:36:39'),(11,1,'settings','settings','Updated system settings','::1','2026-09-28 01:36:53'),(12,1,'logout','auth','User logged out','::1','2026-09-28 01:36:59'),(13,1,'login','auth','User Goscha Administrator logged in','::1','2026-09-28 01:37:18'),(14,1,'logout','auth','User logged out','::1','2026-09-28 01:37:40'),(15,1,'login','auth','User Goscha Administrator logged in','::1','2026-09-28 01:38:04'),(16,1,'logout','auth','User logged out','::1','2026-09-28 01:40:20'),(17,1,'login','auth','User Goscha Administrator logged in','::1','2026-09-28 01:40:32'),(18,1,'login','auth','User Goscha Administrator logged in','::1','2026-09-28 02:13:09'),(19,1,'update','staff','Administrator updated working hours for staff member: Jane Doe.','::1','2026-09-28 02:16:14'),(20,1,'login','auth','User Goscha Administrator logged in','::1','2026-09-28 02:16:57'),(21,1,'update','staff','Administrator updated working hours for staff member: Jane Doe.','::1','2026-09-28 02:16:57'),(22,1,'update','staff','Administrator updated working hours for staff member: Jane Doe.','::1','2026-09-28 02:17:09'),(23,1,'update','staff','Administrator updated the services performed by Jane Doe.','::1','2026-09-28 02:17:18'),(24,1,'update','staff','Administrator updated the services performed by Jane Doe.','::1','2026-09-28 02:17:18'),(25,3,'login','auth','User Ruth Reception logged in','::1','2026-09-28 02:18:09'),(26,3,'forbidden','auth','Access denied to /Beauty%20salon/admin/staff.php','::1','2026-09-28 02:18:09'),(27,1,'login','auth','User Goscha Administrator logged in','::1','2026-09-28 02:25:55'),(28,1,'create','appointments','Saved appointment #28 for customer #12 on 2026-09-30 ┬╖ staff: Sarah Smith','::1','2026-09-28 02:32:53'),(29,1,'delete','appointments','Deleted appointment #28','::1','2026-09-28 02:33:21'),(30,1,'create','walkins','Walk-in appointment #30 booked and paid (INV-2026-0009)','::1','2026-09-28 02:50:48'),(31,1,'delete','appointments','Deleted appointment #30','::1','2026-09-28 02:51:50'),(32,1,'login','auth','User Goscha Administrator logged in','::1','2026-09-28 03:06:53'),(33,1,'update','appointments','Changed appointment #39 status to confirmed','::1','2026-09-28 03:08:26'),(36,3,'login','auth','User Ruth Reception logged in','::1','2026-09-28 03:14:22'),(37,3,'forbidden','auth','Access denied to /Beauty%20salon/admin/settings.php','::1','2026-09-28 03:14:22'),(38,3,'forbidden','auth','Access denied to /Beauty%20salon/admin/staff.php','::1','2026-09-28 03:14:23'),(39,3,'forbidden','auth','Access denied to /Beauty%20salon/admin/users.php','::1','2026-09-28 03:14:24'),(40,3,'forbidden','auth','Access denied to /Beauty%20salon/admin/settings.php','::1','2026-09-28 03:14:46'),(41,3,'forbidden','auth','Access denied to /Beauty%20salon/admin/staff.php','::1','2026-09-28 03:15:06'),(85,1,'login','auth','User Goscha Administrator logged in','::1','2026-10-03 00:18:45'),(172,NULL,'create','users','Created admin account probe-2d7c2e60@example.test','0.0.0.0','2026-10-03 00:58:10'),(190,1,'login','auth','User Goscha Administrator logged in','::1','2026-10-03 01:02:30'),(191,1,'update','staff','Administrator updated staff member: Hana Bekele.','::1','2026-10-03 01:03:33'),(192,1,'logout','auth','User logged out','::1','2026-10-03 01:05:04'),(193,NULL,'customer_register','auth','New customer registered: Signup Probe','::1','2026-10-03 01:11:15'),(228,1,'login','auth','User Goscha Administrator logged in','::1','2026-10-03 01:19:58'),(229,1,'update','staff','Administrator updated staff member: Hana Bekele.','::1','2026-10-03 01:20:11'),(230,1,'logout','auth','User logged out','::1','2026-10-03 01:21:43'),(247,1,'login','auth','User Goscha Administrator logged in','::1','2026-10-03 02:00:00'),(248,3,'login','auth','User Ruth Reception logged in','::1','2026-10-03 02:00:00'),(249,3,'forbidden','auth','Access denied to /Beauty%20salon/admin/settings.php','::1','2026-10-03 02:00:01'),(250,3,'forbidden','auth','Access denied to /Beauty%20salon/admin/staff.php','::1','2026-10-03 02:00:01'),(251,3,'forbidden','auth','Access denied to /Beauty%20salon/admin/settings.php','::1','2026-10-03 02:00:01'),(252,3,'forbidden','auth','Access denied to /Beauty%20salon/admin/staff.php','::1','2026-10-03 02:00:01'),(253,1,'create','appointments','Saved appointment #223 for customer #1 on 2026-10-05 ┬╖ staff: Jane Doe','::1','2026-10-03 02:00:01'),(254,1,'create','appointments','Saved appointment #224 for customer #2 on 2026-10-05 ┬╖ staff: Jane Doe','::1','2026-10-03 02:00:03'),(255,1,'create','appointments','Saved appointment #225 for customer #2 on 2026-10-05 ┬╖ staff: Jane Doe','::1','2026-10-03 02:00:04'),(256,1,'create','appointments','Saved appointment #226 for customer #2 on 2026-10-05 ┬╖ staff: Jane Doe','::1','2026-10-03 02:00:04'),(257,NULL,'customer_register','auth','New customer registered: Betty Kassa','::1','2026-10-03 02:13:14'),(258,NULL,'customer_register','auth','New customer registered: Danit solomn','::1','2026-10-03 02:27:48'),(259,1,'login','auth','User Goscha Administrator logged in','::1','2026-10-03 02:28:51'),(260,1,'update','customers','Updated customer Danit solomn','::1','2026-10-03 02:29:16');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `business_breaks`
--

DROP TABLE IF EXISTS `business_breaks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `business_breaks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `day_of_week` tinyint(3) unsigned NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_business_breaks_day` (`day_of_week`),
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
  `day_of_week` tinyint(3) unsigned NOT NULL,
  `is_open` tinyint(1) NOT NULL DEFAULT 1,
  `opening_time` time DEFAULT NULL,
  `closing_time` time DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_business_hours_day` (`day_of_week`),
  CONSTRAINT `ck_business_hours_day` CHECK (`day_of_week` between 1 and 7)
) ENGINE=InnoDB AUTO_INCREMENT=596 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_hours`
--

LOCK TABLES `business_hours` WRITE;
/*!40000 ALTER TABLE `business_hours` DISABLE KEYS */;
INSERT INTO `business_hours` VALUES (1,1,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 02:57:38'),(2,2,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(3,3,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(4,4,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(5,5,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(6,6,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-10-03 02:57:38'),(7,7,0,NULL,NULL,'2026-09-27 21:21:39','2026-09-27 21:21:39');
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
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (1,'Liya','Hailu','0911 234 567','liya.hailu@example.com',NULL,'1992-04-12','female','Bole, Addis Ababa','Normal','Curly','None','VIP customer, prefers morning appointments.',320,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(2,'Meron','Tesfaye','0912 345 678','meron.t@example.com',NULL,'1995-08-03','female','CMC, Addis Ababa','Oily','Straight','Nail polish allergy','Prefers gel-free manicure.',150,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(3,'Abel','Tadesse','0913 456 789',NULL,NULL,'1988-11-21','male','Megenagna, Addis Ababa','Sensitive','Short','Coconut oil','',90,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(4,'Hanna','Girma','0914 567 890','hanna.g@gmail.com',NULL,NULL,'female',NULL,NULL,'Wavy',NULL,'First-time client, referred by Liya.',410,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(5,'Daniel','Alemu','0915 678 901',NULL,NULL,'1990-02-15',NULL,'Gerji, Addis Ababa',NULL,NULL,NULL,'Prefers bank transfer payments.',75,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(6,'Ruth','Mekonnen','0916 789 012','ruth.mek@example.com',NULL,'1998-12-30','female','Piassa, Addis Ababa','Dry','Straight','None','',260,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(7,'Samrawit','Yohannes','0917 890 123',NULL,NULL,'1993-06-08','female',NULL,'Combination','Curly','Latex','',180,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(8,'Eden','Assefa','0918 901 234','eden.assefa@example.com',NULL,'1985-09-19','female','Kazanchis, Addis Ababa','Normal','Straight','None','Corporate client.',520,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(9,'Yonatan','Bekele','0919 012 345','yonatan.b@example.com',NULL,'1991-01-25','male',NULL,NULL,'Short',NULL,'',45,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(10,'Bethlehem','Kebede','0920 123 456',NULL,NULL,'1996-07-14','female','Sar Bet, Addis Ababa','Oily','Curly','None','',130,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(11,'Selam','Wondimu','0921 234 567','selam.w@example.com',NULL,'1987-03-02','female','Nifas Silk, Addis Ababa','Sensitive','Wavy','Alcohol (in products)','',210,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(12,'Mahlet','Berhanu','0922 345 678',NULL,NULL,'1994-10-11',NULL,'Bambis, Addis Ababa',NULL,NULL,NULL,'',60,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(63,'Betty','Kassa','0923674555','bettyety@gmail.com','$2y$10$xuM05l29oimAzluoNZAdvO7JscCxUz4dORC8yKuvvVFHG89ka1JXi',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'2026-10-03 02:13:14','2026-10-03 02:13:14'),(64,'Danit','solomn','0923674555','Dan34@gmail.com','$2y$10$h2bZ3HVrjuIoV1pKk/iBpubeNBWXT6Jh6ulWS4mbXTK/uJrltVq1O',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'2026-10-03 02:27:48','2026-10-03 02:27:48');
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `holidays`
--

DROP TABLE IF EXISTS `holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `holiday_date` date NOT NULL,
  `title` varchar(150) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_holiday_date` (`holiday_date`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `holidays`
--

LOCK TABLES `holidays` WRITE;
/*!40000 ALTER TABLE `holidays` DISABLE KEYS */;
INSERT INTO `holidays` VALUES (1,'2026-10-11','Staff Training Day'),(2,'2026-11-11','Salon Annual Break');
/*!40000 ALTER TABLE `holidays` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_products`
--

DROP TABLE IF EXISTS `inventory_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  UNIQUE KEY `uq_prod_sku` (`sku`),
  KEY `idx_prod_supplier` (`supplier_id`),
  CONSTRAINT `fk_prod_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_products`
--

LOCK TABLES `inventory_products` WRITE;
/*!40000 ALTER TABLE `inventory_products` DISABLE KEYS */;
INSERT INTO `inventory_products` VALUES (1,'Argan Oil Shampoo 500ml','GLM-SHP-001','6291041500213','Hair Care',1,24,10,350.00,550.00,'active','2026-09-27 21:26:10'),(2,'Hair Conditioner 500ml','GLM-CND-002','6291041500220','Hair Care',1,8,10,320.00,500.00,'active','2026-09-27 21:26:10'),(3,'Hair Color Kit ΓÇô Brown','GLM-HC-003','6291041500237','Hair Care',3,15,5,650.00,950.00,'active','2026-09-27 21:26:10'),(4,'Facial Cleanser 250ml','GLM-FC-004','6291041500244','Skin Care',4,20,8,280.00,480.00,'active','2026-09-27 21:26:10'),(5,'Hydrating Face Mask','GLM-FM-005','6291041500251','Skin Care',4,6,10,150.00,320.00,'active','2026-09-27 21:26:10'),(6,'Nail Polish Set','GLM-NP-006','6291041500268','Nails',2,30,12,220.00,420.00,'active','2026-09-27 21:26:10'),(7,'Nail Files (pack of 10)','GLM-NF-007','6291041500275','Nails',2,40,15,60.00,120.00,'active','2026-09-27 21:26:10'),(8,'Massage Oil 500ml','GLM-MO-008','6291041500282','Spa',1,12,6,400.00,700.00,'active','2026-09-27 21:26:10'),(9,'Makeup Brush Set','GLM-MB-009','6291041500299','Makeup',2,5,5,800.00,1400.00,'active','2026-09-27 21:26:10'),(10,'Keratin Treatment Kit','GLM-KT-010','6291041500305','Hair Care',3,9,4,1200.00,1800.00,'active','2026-09-27 21:26:10');
/*!40000 ALTER TABLE `inventory_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  UNIQUE KEY `uq_inv_number` (`invoice_number`),
  KEY `idx_inv_appt` (`appointment_id`),
  KEY `idx_inv_customer` (`customer_id`),
  CONSTRAINT `fk_inv_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inv_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=130 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoices`
--

LOCK TABLES `invoices` WRITE;
/*!40000 ALTER TABLE `invoices` DISABLE KEYS */;
INSERT INTO `invoices` VALUES (1,'INV-2026-0001',1,1,1450.00,0.00,217.50,1667.50,'paid','2026-09-26 00:00:00'),(2,'INV-2026-0002',2,2,600.00,0.00,90.00,690.00,'paid','2026-09-26 00:00:00'),(3,'INV-2026-0003',3,3,1000.00,0.00,150.00,1150.00,'paid','2026-09-27 00:00:00'),(4,'INV-2026-0004',12,12,1000.00,0.00,150.00,1150.00,'paid','2026-09-26 00:00:00'),(5,'INV-2026-0005',13,1,1200.00,0.00,180.00,1380.00,'paid','2026-09-26 00:00:00'),(6,'INV-2026-0006',14,2,600.00,0.00,90.00,690.00,'paid','2026-09-24 00:00:00'),(7,'INV-2026-0007',15,3,500.00,0.00,75.00,575.00,'paid','2026-09-24 00:00:00'),(126,'INV-2026-0008',NULL,1,250.00,0.00,0.00,250.00,'unpaid','2026-10-03 02:00:01'),(127,'INV-2026-0009',NULL,2,250.00,0.00,0.00,250.00,'unpaid','2026-10-03 02:00:03'),(128,'INV-2026-0010',NULL,2,1800.00,0.00,0.00,1800.00,'unpaid','2026-10-03 02:00:04'),(129,'INV-2026-0011',NULL,2,250.00,0.00,0.00,250.00,'unpaid','2026-10-03 02:00:04');
/*!40000 ALTER TABLE `invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loyalty_ledger`
--

DROP TABLE IF EXISTS `loyalty_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loyalty_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `points_change` int(11) NOT NULL DEFAULT 0,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ll_customer` (`customer_id`),
  CONSTRAINT `fk_ll_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loyalty_ledger`
--

LOCK TABLES `loyalty_ledger` WRITE;
/*!40000 ALTER TABLE `loyalty_ledger` DISABLE KEYS */;
INSERT INTO `loyalty_ledger` VALUES (1,1,50,'New customer welcome bonus','2026-07-29 00:00:00'),(2,1,120,'Appointment spending reward','2026-09-17 00:00:00'),(3,1,150,'Appointment spending reward','2026-09-26 00:00:00'),(4,2,80,'Appointment spending reward','2026-09-22 00:00:00'),(5,4,200,'Bridal makeup reward','2026-09-22 00:00:00'),(6,8,220,'Appointment spending reward','2026-09-15 00:00:00');
/*!40000 ALTER TABLE `loyalty_ledger` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `message` text DEFAULT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'info',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`),
  KEY `idx_notif_read` (`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=389 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,1,'New waitlist request','A new booking request was received from the website.','info',1,'2026-09-27 21:26:10'),(2,1,'Low stock alert','Hair Conditioner is below the minimum stock level.','warning',1,'2026-09-27 21:26:10'),(3,1,'Low stock alert','Hydrating Face Mask is below the minimum stock level.','warning',1,'2026-09-27 21:26:10'),(4,2,'New appointment','Appointment for Ruth Mekonnen was confirmed.','success',0,'2026-09-27 21:26:10'),(5,2,'Payment received','Cash payment of ETB 1,150.00 recorded for today.','success',0,'2026-09-27 21:26:10'),(6,1,'Appointment saved','Appointment #28 was saved on Sep 30, 2026 at 4:00 PM','success',0,'2026-09-28 02:32:53'),(7,2,'Appointment saved','Appointment #28 was saved on Sep 30, 2026 at 4:00 PM','success',0,'2026-09-28 02:32:53'),(8,3,'Appointment saved','Appointment #28 was saved on Sep 30, 2026 at 4:00 PM','success',0,'2026-09-28 02:32:53'),(9,1,'Walk-in completed','Walk-in appointment #30 booked and payment of 500.00 ETB received.','success',0,'2026-09-28 02:50:48'),(10,2,'Walk-in completed','Walk-in appointment #30 booked and payment of 500.00 ETB received.','success',0,'2026-09-28 02:50:48'),(11,3,'Walk-in completed','Walk-in appointment #30 booked and payment of 500.00 ETB received.','success',0,'2026-09-28 02:50:48'),(12,1,'Appointment status changed','Appointment #39 is now Confirmed.','info',0,'2026-09-28 03:08:26'),(13,2,'Appointment status changed','Appointment #39 is now Confirmed.','info',0,'2026-09-28 03:08:26'),(14,3,'Appointment status changed','Appointment #39 is now Confirmed.','info',0,'2026-09-28 03:08:26'),(377,1,'Appointment saved','Appointment #223 was saved on Oct 5, 2026 at 10:00 AM','success',0,'2026-10-03 02:00:01'),(378,2,'Appointment saved','Appointment #223 was saved on Oct 5, 2026 at 10:00 AM','success',0,'2026-10-03 02:00:01'),(379,3,'Appointment saved','Appointment #223 was saved on Oct 5, 2026 at 10:00 AM','success',0,'2026-10-03 02:00:01'),(380,1,'Appointment saved','Appointment #224 was saved on Oct 5, 2026 at 11:00 AM','success',0,'2026-10-03 02:00:03'),(381,2,'Appointment saved','Appointment #224 was saved on Oct 5, 2026 at 11:00 AM','success',0,'2026-10-03 02:00:03'),(382,3,'Appointment saved','Appointment #224 was saved on Oct 5, 2026 at 11:00 AM','success',0,'2026-10-03 02:00:03'),(383,1,'Appointment saved','Appointment #225 was saved on Oct 5, 2026 at 2:00 PM','success',0,'2026-10-03 02:00:04'),(384,2,'Appointment saved','Appointment #225 was saved on Oct 5, 2026 at 2:00 PM','success',0,'2026-10-03 02:00:04'),(385,3,'Appointment saved','Appointment #225 was saved on Oct 5, 2026 at 2:00 PM','success',0,'2026-10-03 02:00:04'),(386,1,'Appointment saved','Appointment #226 was saved on Oct 5, 2026 at 1:30 PM','success',0,'2026-10-03 02:00:04'),(387,2,'Appointment saved','Appointment #226 was saved on Oct 5, 2026 at 1:30 PM','success',0,'2026-10-03 02:00:04'),(388,3,'Appointment saved','Appointment #226 was saved on Oct 5, 2026 at 1:30 PM','success',0,'2026-10-03 02:00:04');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
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
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  CONSTRAINT `fk_pay_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pay_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (1,1,1,1667.50,'cash','GLM-PAY-0001','2026-09-26 00:00:00','completed'),(2,2,2,690.00,'bank_transfer','TRF-8842-113','2026-09-26 00:00:00','completed'),(3,3,3,1150.00,'cash','GLM-PAY-0003','2026-09-27 00:00:00','completed'),(4,12,12,1150.00,'bank_transfer','TRF-7712-009','2026-09-26 00:00:00','completed'),(5,13,1,1380.00,'cash','GLM-PAY-0005','2026-09-26 00:00:00','completed'),(6,14,2,690.00,'cash','GLM-PAY-0006','2026-09-24 00:00:00','completed'),(7,15,3,575.00,'bank_transfer','TRF-5501-221','2026-09-24 00:00:00','completed');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll`
--

DROP TABLE IF EXISTS `payroll`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  CONSTRAINT `fk_payroll_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll`
--

LOCK TABLES `payroll` WRITE;
/*!40000 ALTER TABLE `payroll` DISABLE KEYS */;
INSERT INTO `payroll` VALUES (1,1,'Jane Doe',9500.00,1800.00,1200.00,800.00,450.00,12850.00,'2026-07','paid','2026-09-27 21:26:10'),(2,2,'Sarah Smith',8200.00,1200.00,900.00,500.00,300.00,10500.00,'2026-07','paid','2026-09-27 21:26:10'),(3,3,'Hana Bekele',8800.00,1500.00,1000.00,600.00,350.00,11550.00,'2026-07','paid','2026-09-27 21:26:10'),(4,4,'Meron Tesfaye',7800.00,950.00,700.00,400.00,250.00,9600.00,'2026-07','paid','2026-09-27 21:26:10'),(5,5,'Lily Adams',7000.00,600.00,450.00,0.00,200.00,7850.00,'2026-06','paid','2026-09-27 21:26:10'),(6,1,'Jane Doe',9500.00,950.00,800.00,0.00,300.00,10950.00,'2026-08','pending','2026-09-27 21:26:10');
/*!40000 ALTER TABLE `payroll` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_sales`
--

DROP TABLE IF EXISTS `product_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sale_date` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ps_product` (`product_id`),
  KEY `idx_ps_customer` (`customer_id`),
  CONSTRAINT `fk_ps_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ps_product` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_sales`
--

LOCK TABLES `product_sales` WRITE;
/*!40000 ALTER TABLE `product_sales` DISABLE KEYS */;
INSERT INTO `product_sales` VALUES (1,1,1,1,550.00,550.00,'2026-09-25 00:00:00'),(2,3,6,1,950.00,950.00,'2026-09-26 00:00:00'),(3,6,4,2,420.00,840.00,'2026-09-24 00:00:00'),(4,8,12,1,700.00,700.00,'2026-09-26 00:00:00');
/*!40000 ALTER TABLE `product_sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchases`
--

DROP TABLE IF EXISTS `purchases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  CONSTRAINT `fk_pur_product` FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pur_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchases`
--

LOCK TABLES `purchases` WRITE;
/*!40000 ALTER TABLE `purchases` DISABLE KEYS */;
INSERT INTO `purchases` VALUES (1,1,1,50,320.00,16000.00,'2026-09-17','2026-09-27 21:26:10'),(2,1,2,40,290.00,11600.00,'2026-09-17','2026-09-27 21:26:10'),(3,3,3,30,600.00,18000.00,'2026-09-19','2026-09-27 21:26:10'),(4,4,4,40,250.00,10000.00,'2026-09-19','2026-09-27 21:26:10'),(5,4,5,30,130.00,3900.00,'2026-09-20','2026-09-27 21:26:10'),(6,2,6,25,200.00,5000.00,'2026-09-21','2026-09-27 21:26:10'),(7,2,7,60,50.00,3000.00,'2026-09-21','2026-09-27 21:26:10'),(8,1,8,20,360.00,7200.00,'2026-09-22','2026-09-27 21:26:10'),(9,2,9,10,750.00,7500.00,'2026-09-22','2026-09-27 21:26:10'),(10,3,10,15,1100.00,16500.00,'2026-09-23','2026-09-27 21:26:10');
/*!40000 ALTER TABLE `purchases` ENABLE KEYS */;
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
  `salon_name` varchar(150) NOT NULL,
  `owner_first_name` varchar(100) NOT NULL,
  `owner_last_name` varchar(100) NOT NULL,
  `setup_completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_salon_settings_single` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `salon_settings`
--

LOCK TABLES `salon_settings` WRITE;
/*!40000 ALTER TABLE `salon_settings` DISABLE KEYS */;
INSERT INTO `salon_settings` VALUES (1,'Goscha Software','Goscha','Administrator','2026-09-27 21:25:51','2026-09-27 21:21:39','2026-10-03 00:58:10');
/*!40000 ALTER TABLE `salon_settings` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_categories`
--

LOCK TABLES `service_categories` WRITE;
/*!40000 ALTER TABLE `service_categories` DISABLE KEYS */;
INSERT INTO `service_categories` VALUES (1,'Hair','Hair washing, styling, coloring and treatment services.','active',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(2,'Skin & Facials','Facial treatments for every skin type.','active',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(3,'Nails','Manicure and pedicure services.','active',3,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(4,'Makeup','Daily and bridal makeup services.','active',4,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(5,'Massage & Spa','Relaxing massage and spa treatments.','active',5,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(6,'Eyelashes','Lash extensions, fills and lash care.','active',6,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(7,'Eyebrows','Brow shaping, tinting and threading.','active',7,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(8,'Waxing','Body and facial waxing.','active',8,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(9,'Other','Any service that does not fit a dedicated category.','active',9,'2026-09-27 21:26:10','2026-09-27 21:26:10');
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
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_subcategories`
--

LOCK TABLES `service_subcategories` WRITE;
/*!40000 ALTER TABLE `service_subcategories` DISABLE KEYS */;
INSERT INTO `service_subcategories` VALUES (1,1,'Hair Styling','Blow-dry, blow-out, curls and event styling.','active',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(2,1,'Hair Coloring','Full color, highlights, balayage and retouch.','active',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(3,1,'Hair Treatment','Keratin, deep conditioning and repair.','active',3,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(4,1,'Hair Extensions','Weave, wig install and extension fitting.','active',4,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(5,2,'Facials','Classic, deep-cleansing and hydrating facials.','active',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(6,3,'Manicure','Classic, gel and spa manicure.','active',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(7,3,'Pedicure','Classic, gel and spa pedicure.','active',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(8,3,'Nail Art','Decoration, French tips and nail art.','active',3,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(9,4,'Makeup','Everyday, party and photographic makeup.','active',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(10,5,'Massage','Swedish, deep tissue and aromatherapy massage.','active',1,'2026-09-27 21:26:10','2026-09-27 21:26:10');
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
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `services`
--

LOCK TABLES `services` WRITE;
/*!40000 ALTER TABLE `services` DISABLE KEYS */;
INSERT INTO `services` VALUES (1,1,NULL,'Hair Wash','Gentle hair wash with premium nourishing shampoo and conditioning rinse.',30,250.00,'active',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(2,1,1,'Hair Styling','Professional blow-dry and styling for any occasion.',60,600.00,'active',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(3,1,2,'Hair Coloring','Full hair coloring with high-quality ammonia-free color.',120,1800.00,'active',3,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(4,1,3,'Hair Treatment','Deep conditioning keratin and repair treatment.',45,800.00,'active',4,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(5,2,5,'Facial','Cleansing, exfoliating and hydrating facial with facial massage.',45,1200.00,'active',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(6,3,6,'Manicure','Classic manicure with cuticle care, shaping and polish.',45,500.00,'active',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(7,3,7,'Pedicure','Classic pedicure with soak, scrub, massage and polish.',45,550.00,'active',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(8,4,9,'Makeup','Professional makeup application for events and occasions.',60,1500.00,'active',1,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(9,4,NULL,'Bridal Makeup','Complete bridal look including trial and on-the-day makeup.',120,8000.00,'active',2,'2026-09-27 21:26:10','2026-09-27 21:26:10'),(10,5,10,'Massage','Relaxing full-body massage with aromatic oils.',60,1000.00,'active',1,'2026-09-27 21:26:10','2026-09-27 21:26:10');
/*!40000 ALTER TABLE `services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=239 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,'salon_name','Goscha Software'),(2,'salon_tagline','Smart Software for Beautiful Businesses.'),(3,'salon_logo',''),(4,'salon_phone','+251 911 000 111'),(5,'salon_email','hello@goschasoftware.com'),(6,'salon_address','Bole Road, Addis Ababa, Ethiopia'),(7,'business_open','10:00'),(8,'business_close','17:00'),(9,'business_hours_display','Mon ΓÇô Sat: 9:00 AM ΓÇô 6:00 PM ┬╖ Sun: Closed'),(10,'currency',''),(11,'tax_rate','0'),(12,'loyalty_rate','0'),(13,'appointment_advance_days','30'),(14,'max_advance_days','30'),(15,'session_timeout_minutes','60'),(16,'notify_email','0'),(17,'notify_sms','0'),(18,'notification_sms_enabled','0'),(19,'notification_email_enabled','0'),(20,'facebook_url',''),(21,'instagram_url',''),(22,'tiktok_url',''),(80,'owner_first_name','Goscha'),(81,'owner_last_name','Administrator');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff`
--

LOCK TABLES `staff` WRITE;
/*!40000 ALTER TABLE `staff` DISABLE KEYS */;
INSERT INTO `staff` VALUES (1,'Jane','Doe','0911 111 001','jane.doe@example.com','1990-03-18','female','Bole, Addis Ababa','Michael Doe','0911 111 901',9500.00,'Senior hairstylist. Certified in keratin treatments.','active','2026-09-27 21:26:10','2026-09-27 21:26:10'),(2,'Sarah','Smith','0911 111 002',NULL,'1994-07-25','female','Sar Bet, Addis Ababa','Anna Smith','0911 111 902',8200.00,'Nail technician. Prefers weekend shifts.','active','2026-09-27 21:26:10','2026-09-27 21:26:10'),(3,'Hana','Bekele','0911 111 003','hana.bekele@example.com','1992-11-02','female','CMC, Addis Ababa','Tewodros Bekele','0911 111 903',8800.00,'Skincare specialist (facials and peels).','active','2026-09-27 21:26:10','2026-09-27 21:26:10'),(4,'Meron','Tesfaye','0911 111 004','meron.tesfaye@example.com','1996-01-30','female','Megenagna, Addis Ababa','Abebe Tesfaye','0911 111 904',7800.00,'Makeup artist. Bridal packages specialist.','active','2026-09-27 21:26:10','2026-09-27 21:26:10'),(5,'Lily','Adams','0911 111 005',NULL,'1991-05-14','female','Kazanchis, Addis Ababa','Peter Adams','0911 111 905',7000.00,'Massage therapist. Currently on study leave.','inactive','2026-09-27 21:26:10','2026-09-27 21:26:10');
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
  `staff_id` int(11) NOT NULL,
  `day_of_week` tinyint(3) unsigned NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_staff_breaks_staff_day` (`staff_id`,`day_of_week`),
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
  `staff_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_service` (`staff_id`,`service_id`),
  KEY `idx_staff_services_service` (`service_id`),
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
  CONSTRAINT `fk_staff_hours_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ck_staff_hours_day` CHECK (`day_of_week` between 1 and 7)
) ENGINE=InnoDB AUTO_INCREMENT=1226 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_working_hours`
--

LOCK TABLES `staff_working_hours` WRITE;
/*!40000 ALTER TABLE `staff_working_hours` DISABLE KEYS */;
INSERT INTO `staff_working_hours` VALUES (1,1,1,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(2,2,1,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(3,3,1,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(4,4,1,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(5,5,1,0,NULL,NULL,'2026-09-27 21:21:39','2026-09-27 21:21:39'),(6,1,2,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(7,2,2,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(8,3,2,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(9,4,2,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(10,5,2,0,NULL,NULL,'2026-09-27 21:21:39','2026-09-27 21:21:39'),(11,1,3,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(12,2,3,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(13,3,3,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(14,4,3,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-28 02:49:47'),(15,5,3,0,NULL,NULL,'2026-09-27 21:21:39','2026-09-27 21:21:39'),(16,1,4,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(17,2,4,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(18,3,4,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(19,4,4,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(20,5,4,0,NULL,NULL,'2026-09-27 21:21:39','2026-09-27 21:21:39'),(21,1,5,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(22,2,5,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(23,3,5,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(24,4,5,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(25,5,5,0,NULL,NULL,'2026-09-27 21:21:39','2026-09-27 21:21:39'),(26,1,6,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(27,2,6,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(28,3,6,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(29,4,6,1,'09:00:00','18:00:00','2026-09-27 21:21:39','2026-09-27 21:21:39'),(30,5,6,0,NULL,NULL,'2026-09-27 21:21:39','2026-09-27 21:21:39'),(31,1,7,0,NULL,NULL,'2026-09-27 21:21:39','2026-09-27 21:21:39'),(32,2,7,0,NULL,NULL,'2026-09-27 21:21:39','2026-09-27 21:21:39'),(33,3,7,0,NULL,NULL,'2026-09-27 21:21:39','2026-09-27 21:21:39'),(34,4,7,0,NULL,NULL,'2026-09-27 21:21:39','2026-09-27 21:21:39'),(35,5,7,0,NULL,NULL,'2026-09-27 21:21:39','2026-09-27 21:21:39');
/*!40000 ALTER TABLE `staff_working_hours` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'Addis Beauty Supplies','Abel Tadesse','0911 123 456','sales@addisbeauty.com','Bole Medhanealem, Addis Ababa','active','2026-09-27 21:26:10'),(2,'Grace Cosmetics Import','Sara Mekonnen','0912 987 654','orders@gracecosmetics.com','Kazanchis, Addis Ababa','active','2026-09-27 21:26:10'),(3,'Nairobi Hair Products Ltd','John Omondi','0913 555 789','nairobi.hair@example.com','Industrial Area, Nairobi','active','2026-09-27 21:26:10'),(4,'SunCare Essentials','Liya Habte','0914 222 333','hello@suncareet.com','Megenagna, Addis Ababa','active','2026-09-27 21:26:10'),(5,'Prime Packaging Co.','Daniel Alemu','0915 444 555','packaging@primeet.com','Kality, Addis Ababa','inactive','2026-09-27 21:26:10');
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Goscha Administrator','Goscha','Administrator','admin@beautyphpai.com','0911 000 111','$2y$10$c7OmCtx5P66xx5LMgsORxe4Hjmezb3XwmTNkUFvea3Y89teOmCHVC','admin','active','2026-09-27 21:26:10','2026-09-28 02:07:31'),(2,'Sara Admin','Sara','Admin','admin2@beautyphpai.com','0911 000 222','$2y$10$STyl3CLT1tkMdGjBGarGOeiYcE/MQLGYOUspkTArZmur1hnSbRkjW','admin','active','2026-09-27 21:26:10','2026-09-28 02:07:31'),(3,'Ruth Reception','Ruth','Reception','receptionist@beautyphpai.com','0911 000 333','$2y$10$Fb4kCwcuRdHTSHUDE2NjB.r3TiOE6KBN.S.uY6HK3IUnkijL7bNt2','receptionist','active','2026-09-27 21:26:10','2026-09-28 02:18:09');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `waitlist`
--

DROP TABLE IF EXISTS `waitlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `waitlist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  CONSTRAINT `fk_wait_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wait_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `waitlist`
--

LOCK TABLES `waitlist` WRITE;
/*!40000 ALTER TABLE `waitlist` DISABLE KEYS */;
INSERT INTO `waitlist` VALUES (1,6,NULL,NULL,4,'2026-09-30','10:00:00','Requested via website.','waiting','2026-09-27 21:26:10'),(2,NULL,'Nardos Fikru','0933 456 789',8,'2026-09-29','14:00:00','Online booking request.','waiting','2026-09-27 21:26:10'),(3,9,NULL,NULL,9,'2026-10-04','09:00:00','','converted','2026-09-27 21:26:10');
/*!40000 ALTER TABLE `waitlist` ENABLE KEYS */;
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

-- Dump completed on 2026-10-03  2:58:08
