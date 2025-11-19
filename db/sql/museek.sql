-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               10.4.32-MariaDB - mariadb.org binary distribution
-- Server OS:                    Win64
-- HeidiSQL Version:             12.11.0.7065
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Dumping structure for table museek.admin_users
CREATE TABLE IF NOT EXISTS `admin_users` (
  `admin_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('super_admin','admin','moderator') DEFAULT 'admin',
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Admin user authentication and role management';

-- Dumping data for table museek.admin_users: ~3 rows (approximately)
INSERT INTO `admin_users` (`admin_id`, `username`, `email`, `password_hash`, `full_name`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
	(1, 'admin', 'admin@museek.com', 'password', 'System Administrator', 'super_admin', 1, '2025-11-17 21:04:18', '2025-10-25 00:54:04', '2025-11-17 13:04:18'),
	(2, 'moderator1', 'moderator@museek.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria Santos', 'moderator', 1, NULL, '2025-10-25 00:54:04', '2025-10-25 00:54:04'),
	(3, 'support', 'support@museek.com', 'password', 'Juan Dela Cruz', 'admin', 1, NULL, '2025-10-25 00:54:04', '2025-10-25 01:04:27');

-- Dumping structure for table museek.audit_logs
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `admin_id` (`admin_id`),
  KEY `entity_type` (`entity_type`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Admin action audit trail';

-- Dumping data for table museek.audit_logs: ~23 rows (approximately)
INSERT INTO `audit_logs` (`log_id`, `admin_id`, `action`, `entity_type`, `entity_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
	(1, 1, 'APPROVE_OWNER', 'studio_owner', 1, 'Approved studio owner: Mark Anthony Villanueva', '192.168.1.100', NULL, '2024-01-15 02:00:00'),
	(2, 1, 'APPROVE_STUDIO', 'studio', 1, 'Approved studio: Sound Haven Studio', '192.168.1.100', NULL, '2024-01-15 02:30:00'),
	(3, 1, 'APPROVE_STUDIO', 'studio', 2, 'Approved studio: Rhythm Records', '192.168.1.100', NULL, '2024-01-15 02:30:00'),
	(4, 1, 'APPROVE_INSTRUCTOR', 'instructor', 9, 'Approved instructor: Jason Alvarez', '192.168.1.100', NULL, '2024-01-20 03:00:00'),
	(5, 1, 'APPROVE_OWNER', 'studio_owner', 2, 'Approved studio owner: Christina Gomez', '192.168.1.101', NULL, '2024-02-01 06:30:00'),
	(6, 1, 'APPROVE_STUDIO', 'studio', 3, 'Approved studio: Melody Makers Studio', '192.168.1.101', NULL, '2024-02-01 07:00:00'),
	(7, 2, 'APPROVE_OWNER', 'studio_owner', 3, 'Approved studio owner: Daniel Ramos', '192.168.1.102', NULL, '2024-03-10 01:15:00'),
	(8, 2, 'APPROVE_STUDIO', 'studio', 4, 'Approved studio: Beat Box Productions', '192.168.1.102', NULL, '2024-03-10 02:00:00'),
	(9, 1, 'studio_location', '7', 0, '', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-25 04:02:25'),
	(11, 1, 'EXPORTED_BOOKINGS', 'Booking', 0, '[Admin] ', 'Exported 50 bookings to CSV', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-27 22:25:07'),
	(12, 1, 'EXPORTED_BOOKINGS', 'Booking', 0, '[Admin] ', 'Exported 50 bookings to CSV', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-27 22:25:11'),
	(13, 1, 'REQUESTED_DOCUMENTS', 'Registration', 5, '[Admin] 5', 'Document upload link generated for zenon.draf', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-28 06:20:04'),
	(14, 1, 'studio_location', '10', 0, '', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-28 07:48:50'),
	(15, 1, 'EXPORTED_BOOKINGS', 'Booking', 0, '[Admin] ', 'Exported 50 bookings to CSV', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-28 07:49:35'),
	(16, 1, 'APPROVED', 'Studio', 0, '[Admin] ', 'Registration #1 approved. Note: ', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-29 06:33:53'),
	(17, 1, 'APPROVED', 'Studio', 0, '[Admin] ', 'Registration #1 approved. Note: ', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-29 06:34:04'),
	(18, 1, 'APPROVED', 'Studio', 0, '[Admin] ', 'Registration #1 approved. Note: ', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-29 06:37:29'),
	(19, 1, 'APPROVED', 'Studio', 0, '[Admin] ', 'Registration #1 approved. Note: ', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-29 06:37:43'),
	(20, 1, 'APPROVED', 'Studio', 0, '[Admin] ', 'Registration #1 approved. Note: ', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-29 06:37:47'),
	(21, 1, 'APPROVED', 'Studio', 0, '[Admin] ', 'Registration #1 approved. Note: ', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-29 06:37:49'),
	(22, 1, 'APPROVED', 'Studio', 0, '[Admin] ', 'Registration #1 approved. Note: ', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-29 06:37:54'),
	(23, 1, 'APPROVED', 'Studio', 0, '[Admin] ', 'Registration #1 approved. Note: ', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 OPR/122.0.0.0', '2025-10-29 06:38:08'),
	(24, 1, 'APPROVED', 'Studio', 3, '[Admin] ', 'Registration #11 approved. Note: ', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36 OPR/123.0.0.0', '2025-11-13 06:44:01'),
	(25, 1, 'APPROVED', 'Studio', 3, '[Admin] ', 'Registration #11 approved. Note: ', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36 OPR/123.0.0.0', '2025-11-13 06:44:47'),
	(26, 1, 'APPROVED', 'Studio', 0, '[Admin] ', 'Registration #13 approved. Note: ', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-13 10:27:35');

-- Dumping structure for table museek.avail_stats
CREATE TABLE IF NOT EXISTS `avail_stats` (
  `Avail_StatsID` int(11) NOT NULL AUTO_INCREMENT,
  `Avail_Name` varchar(255) NOT NULL,
  PRIMARY KEY (`Avail_StatsID`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.avail_stats: ~5 rows (approximately)
INSERT INTO `avail_stats` (`Avail_StatsID`, `Avail_Name`) VALUES
	(1, 'Available'),
	(2, 'Booked'),
	(3, 'Passed'),
	(4, 'Maintenance'),
	(5, 'Holiday');

-- Dumping structure for table museek.bookings
CREATE TABLE IF NOT EXISTS `bookings` (
  `BookingID` int(11) NOT NULL AUTO_INCREMENT,
  `ClientID` int(11) NOT NULL,
  `StudioID` int(11) NOT NULL,
  `ServiceID` int(11) NOT NULL,
  `ScheduleID` int(11) NOT NULL,
  `InstructorID` int(11) NOT NULL,
  `Book_StatsID` int(11) NOT NULL DEFAULT 1,
  `CancellationReason` text DEFAULT NULL,
  `booking_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`BookingID`),
  KEY `ClientID` (`ClientID`),
  KEY `StudioID` (`StudioID`),
  KEY `ScheduleID` (`ScheduleID`),
  KEY `Book_StatsID` (`Book_StatsID`),
  KEY `FK_bookings_services` (`ServiceID`),
  KEY `FK_bookings_instructors` (`InstructorID`),
  CONSTRAINT `FK_bookings_book_stats` FOREIGN KEY (`Book_StatsID`) REFERENCES `book_stats` (`Book_StatsID`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `FK_bookings_instructors` FOREIGN KEY (`InstructorID`) REFERENCES `instructors` (`InstructorID`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `FK_bookings_services` FOREIGN KEY (`ServiceID`) REFERENCES `services` (`ServiceID`) ON UPDATE NO ACTION,
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`ClientID`) REFERENCES `clients` (`ClientID`),
  CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`StudioID`) REFERENCES `studios` (`StudioID`),
  CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`ScheduleID`) REFERENCES `schedules` (`ScheduleID`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.bookings: ~58 rows (approximately)
INSERT INTO `bookings` (`BookingID`, `ClientID`, `StudioID`, `ServiceID`, `ScheduleID`, `InstructorID`, `Book_StatsID`, `CancellationReason`, `booking_date`) VALUES
	(2, 2, 2, 5, 4, 1, 2, NULL, '2025-05-19 22:44:30'),
	(3, 3, 1, 3, 1, 1, 1, NULL, '2025-05-19 22:44:31'),
	(4, 4, 3, 4, 3, 1, 4, NULL, '2025-05-19 22:44:32'),
	(5, 5, 1, 5, 6, 1, 1, NULL, '2025-05-19 22:44:33'),
	(6, 1, 1, 2, 2, 1, 1, NULL, '2025-05-19 22:44:34'),
	(7, 1, 1, 1, 2, 1, 4, NULL, '2025-05-20 03:17:02'),
	(8, 1, 1, 1, 12, 1, 5, NULL, '2025-05-20 01:55:17'),
	(9, 1, 3, 2, 13, 1, 5, NULL, '2025-05-20 01:53:29'),
	(10, 1, 3, 2, 14, 1, 3, NULL, '2025-05-20 00:16:49'),
	(15, 1, 1, 2, 21, 1, 5, NULL, '2025-05-20 03:28:05'),
	(16, 1, 1, 1, 22, 1, 5, NULL, '2025-10-05 13:28:21'),
	(17, 1, 1, 1, 33, 0, 4, NULL, '2025-09-28 11:03:25'),
	(18, 1, 1, 1, 24, 0, 4, NULL, '2025-09-28 11:03:25'),
	(19, 1, 1, 1, 25, 0, 4, NULL, '2025-09-28 11:03:25'),
	(20, 1, 1, 2, 26, 0, 4, NULL, '2025-09-28 11:03:25'),
	(21, 1, 1, 2, 27, 1, 4, NULL, '2025-09-28 11:03:25'),
	(22, 1, 1, 1, 28, 2, 4, NULL, '2025-09-28 11:03:25'),
	(23, 1, 1, 1, 32, 2, 4, NULL, '2025-09-28 11:03:25'),
	(24, 1, 1, 2, 34, 1, 4, NULL, '2025-09-28 11:03:25'),
	(25, 1, 1, 1, 43, 1, 5, NULL, '2025-09-28 11:08:03'),
	(26, 1, 1, 2, 36, 1, 3, NULL, '2025-09-27 17:34:30'),
	(29, 1, 1, 2, 47, 1, 4, NULL, '2025-11-08 14:13:58'),
	(30, 1, 1, 2, 36, 2, 3, 'Emergency', '2025-09-28 06:48:49'),
	(31, 1, 1, 1, 45, 1, 3, 'Personal reasons', '2025-10-05 13:55:06'),
	(32, 1, 1, 2, 46, 1, 3, 'Emergency', '2025-10-05 13:55:18'),
	(33, 1, 1, 1, 48, 2, 4, NULL, '2025-11-08 14:13:58'),
	(34, 1, 1, 2, 49, 1, 5, NULL, '2025-10-05 14:03:11'),
	(35, 1, 1, 1, 58, 1, 4, NULL, '2025-11-08 14:13:58'),
	(36, 1, 3, 6, 63, 3, 5, NULL, '2025-10-20 23:28:51'),
	(37, 1, 3, 6, 64, 3, 5, NULL, '2025-10-20 23:26:12'),
	(38, 1, 3, 6, 65, 3, 5, NULL, '2025-10-21 00:00:36'),
	(39, 1, 3, 6, 66, 3, 5, NULL, '2025-10-22 02:58:40'),
	(40, 1, 3, 6, 69, 3, 5, NULL, '2025-10-24 18:15:37'),
	(41, 1, 3, 3, 68, 4, 1, NULL, '2025-10-24 18:27:20'),
	(42, 1, 1, 1, 70, 1, 4, NULL, '2025-11-08 14:13:58'),
	(43, 1, 1, 1, 71, 1, 3, NULL, '2025-10-24 17:40:53'),
	(44, 1, 3, 6, 72, 3, 4, NULL, '2025-11-08 16:17:04'),
	(45, 1, 3, 6, 73, 3, 5, NULL, '2025-10-25 02:08:49'),
	(46, 1, 1, 1, 74, 2, 4, NULL, '2025-11-08 14:13:58'),
	(47, 1, 3, 6, 75, 3, 4, NULL, '2025-11-08 16:17:04'),
	(48, 1, 3, 3, 76, 4, 4, NULL, '2025-11-08 16:17:04'),
	(49, 1, 1, 1, 77, 1, 4, NULL, '2025-11-08 14:13:58'),
	(50, 14, 1, 1, 78, 1, 4, NULL, '2025-11-08 14:13:58'),
	(51, 14, 1, 1, 79, 1, 4, NULL, '2025-11-08 14:13:58'),
	(52, 14, 1, 1, 80, 1, 4, NULL, '2025-11-08 14:13:58'),
	(53, 14, 1, 1, 81, 1, 4, NULL, '2025-11-08 14:13:58'),
	(54, 14, 1, 1, 82, 1, 4, NULL, '2025-11-08 14:13:58'),
	(55, 14, 1, 1, 83, 1, 4, NULL, '2025-11-08 14:13:58'),
	(56, 14, 1, 1, 84, 1, 4, NULL, '2025-11-08 14:13:58'),
	(57, 14, 1, 1, 85, 1, 1, NULL, '2025-10-27 03:49:24'),
	(58, 14, 17, 7, 88, 10, 5, NULL, '2025-11-13 03:46:12'),
	(59, 14, 17, 7, 89, 10, 4, NULL, '2025-11-02 13:44:22'),
	(60, 14, 3, 3, 90, 3, 5, NULL, '2025-11-13 03:28:38'),
	(61, 14, 3, 6, 92, 11, 1, NULL, '2025-11-13 05:38:51'),
	(62, 14, 3, 6, 93, 11, 4, NULL, '2025-11-13 06:33:10'),
	(63, 14, 3, 6, 94, 11, 4, NULL, '2025-11-13 06:33:10'),
	(64, 14, 3, 6, 95, 11, 2, NULL, '2025-11-13 06:34:11'),
	(65, 16, 3, 5, 96, 3, 2, NULL, '2025-11-13 06:49:47'),
	(66, 14, 3, 6, 97, 11, 5, NULL, '2025-11-13 10:12:56'),
	(67, 14, 3, 6, 98, 11, 2, NULL, '2025-11-13 10:09:46');

-- Dumping structure for table museek.booking_addons
CREATE TABLE IF NOT EXISTS `booking_addons` (
  `AddonID` int(11) NOT NULL AUTO_INCREMENT,
  `BookingID` int(11) NOT NULL,
  `InstrumentID` int(11) NOT NULL,
  `Quantity` int(11) NOT NULL DEFAULT 1,
  `Price` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`AddonID`),
  KEY `FK_booking_addons_bookings` (`BookingID`),
  KEY `FK_booking_addons_instruments` (`InstrumentID`),
  CONSTRAINT `FK_booking_addons_bookings` FOREIGN KEY (`BookingID`) REFERENCES `bookings` (`BookingID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_booking_addons_instruments` FOREIGN KEY (`InstrumentID`) REFERENCES `instruments` (`InstrumentID`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.booking_addons: ~9 rows (approximately)
INSERT INTO `booking_addons` (`AddonID`, `BookingID`, `InstrumentID`, `Quantity`, `Price`) VALUES
	(1, 42, 2, 1, 120.00),
	(2, 42, 1, 2, 300.00),
	(3, 43, 2, 1, 120.00),
	(4, 43, 1, 2, 300.00),
	(5, 46, 2, 1, 120.00),
	(6, 49, 2, 1, 120.00),
	(7, 49, 1, 2, 300.00),
	(8, 50, 2, 1, 120.00),
	(9, 50, 1, 1, 150.00),
	(10, 57, 2, 1, 120.00),
	(11, 57, 1, 1, 150.00);

-- Dumping structure for table museek.book_stats
CREATE TABLE IF NOT EXISTS `book_stats` (
  `Book_StatsID` int(11) NOT NULL AUTO_INCREMENT,
  `Book_Stats` varchar(255) NOT NULL,
  PRIMARY KEY (`Book_StatsID`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.book_stats: ~5 rows (approximately)
INSERT INTO `book_stats` (`Book_StatsID`, `Book_Stats`) VALUES
	(1, 'Confirmed'),
	(2, 'Pending'),
	(3, 'Cancelled'),
	(4, 'Archived'),
	(5, 'Finished');

-- Dumping structure for table museek.cash
CREATE TABLE IF NOT EXISTS `cash` (
  `CashID` int(11) NOT NULL,
  `Cash_Amt` decimal(10,2) NOT NULL,
  `Change_Amt` decimal(10,2) NOT NULL,
  PRIMARY KEY (`CashID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.cash: ~2 rows (approximately)
INSERT INTO `cash` (`CashID`, `Cash_Amt`, `Change_Amt`) VALUES
	(1, 1000.00, 0.00),
	(2, 500.00, 0.00);

-- Dumping structure for table museek.chatlog
CREATE TABLE IF NOT EXISTS `chatlog` (
  `ChatID` int(11) NOT NULL AUTO_INCREMENT,
  `OwnerID` int(11) DEFAULT NULL,
  `ClientID` int(11) DEFAULT NULL,
  `Timestamp` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `Content` varchar(255) NOT NULL,
  `Sender_Type` enum('Client','Owner','System') NOT NULL,
  PRIMARY KEY (`ChatID`),
  KEY `OwnerID` (`OwnerID`),
  KEY `ClientID` (`ClientID`),
  CONSTRAINT `chatlog_ibfk_1` FOREIGN KEY (`OwnerID`) REFERENCES `studio_owners` (`OwnerID`),
  CONSTRAINT `chatlog_ibfk_2` FOREIGN KEY (`ClientID`) REFERENCES `clients` (`ClientID`)
) ENGINE=InnoDB AUTO_INCREMENT=163 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.chatlog: ~62 rows (approximately)
INSERT INTO `chatlog` (`ChatID`, `OwnerID`, `ClientID`, `Timestamp`, `Content`, `Sender_Type`) VALUES
	(39, 3, 1, '2025-10-21 23:40:17', 'Hi', 'Client'),
	(40, 3, 1, '2025-10-22 00:03:47', 'Thanks for reaching out! Here are some quick FAQs:\n1. Where is your studio located?\n2. What genres are you allowing for practice in your studio?\n\nYou can pick any from Quick Questions or continue chatting.', 'System'),
	(41, 3, 1, '2025-10-21 23:40:33', 'Where is your studio located?', 'Client'),
	(42, 3, 1, '2025-10-21 23:46:35', 'What genres are you allowing for practice in your studio?', 'Client'),
	(43, 3, 1, '2025-10-22 00:03:49', 'Jazz, Pop, Rock, etc. except Heavy Metal', 'System'),
	(44, 3, 1, '2025-10-21 23:54:32', 'Where is your studio located?', 'Client'),
	(45, 3, 1, '2025-10-22 00:03:50', 'Bacolod City', 'System'),
	(46, 3, 1, '2025-10-21 23:54:52', 'Ok are you available?', 'Client'),
	(47, 3, 1, '2025-10-21 23:55:38', 'What genres are you allowing for practice in your studio?', 'Client'),
	(48, 3, 1, '2025-10-22 00:03:52', 'Jazz, Pop, Rock, etc. except Heavy Metal', 'System'),
	(49, 3, 1, '2025-10-21 23:58:51', 'Where is your studio located?', 'Client'),
	(50, 3, 1, '2025-10-22 00:03:54', 'Bacolod City', 'System'),
	(51, 3, 1, '2025-10-22 00:04:10', 'Where is your studio located?', 'Client'),
	(52, 3, 1, '2025-10-22 00:04:10', 'Bacolod City', 'System'),
	(53, 3, 1, '2025-10-22 00:04:10', 'We\'ve reached 3 automated replies. We’ll redirect this conversation and notify the studio owner to chat with you personally.', 'System'),
	(55, 3, 1, '2025-10-22 00:16:58', 'Welcome back! Here are some quick FAQs:\n1. Where is your studio located?\n2. What genres are you allowing for practice in your studio?\n\nYou can pick any from Quick Questions or continue chatting.', 'System'),
	(56, 3, 1, '2025-10-22 00:17:06', 'Where is your studio located?', 'Client'),
	(57, 3, 1, '2025-10-22 00:17:06', 'Bacolod City', 'System'),
	(58, 3, 1, '2025-10-22 00:17:09', 'What genres are you allowing for practice in your studio?', 'Client'),
	(59, 3, 1, '2025-10-22 00:17:09', 'Jazz, Pop, Rock, etc. except Heavy Metal', 'System'),
	(60, 3, 1, '2025-10-22 00:17:10', 'Where is your studio located?', 'Client'),
	(61, 3, 1, '2025-10-22 00:17:10', 'Bacolod City', 'System'),
	(62, 3, 1, '2025-10-22 00:17:16', 'What genres are you allowing for practice in your studio?', 'Client'),
	(63, 3, 1, '2025-10-22 00:17:16', 'Jazz, Pop, Rock, etc. except Heavy Metal', 'System'),
	(114, 3, 1, '2025-10-24 14:31:05', 'Hi', 'Client'),
	(115, 3, 1, '2025-10-24 14:31:05', 'Good Day! Welcome to Mike Tambasen MusicLab and Productions How may I be of service today?', 'System'),
	(116, 3, 1, '2025-10-24 14:31:15', 'How much is per hour practice?', 'Client'),
	(117, 3, 1, '2025-10-24 14:31:15', 'Please wait for a while, the studio owner will message you shortly.', 'System'),
	(118, 3, 1, '2025-10-25 03:21:53', 'Hi', 'Client'),
	(119, 3, 1, '2025-10-25 03:21:53', 'Good Day! How may I be of service today?', 'System'),
	(120, 3, 1, '2025-10-25 03:22:07', 'Is your studio open?', 'Client'),
	(121, 3, 1, '2025-10-25 03:22:07', 'Please wait for a while, the studio owner will message you shortly.', 'System'),
	(132, 9, 14, '2025-10-29 10:18:03', 'Hi', 'Client'),
	(133, 9, 14, '2025-10-29 10:18:03', 'Good Day! How may I be of service today?', 'System'),
	(134, 9, 14, '2025-10-29 10:18:14', 'How to pay rent?', 'Client'),
	(135, 9, 14, '2025-10-29 10:18:14', 'Please wait for a while, the studio owner will message you shortly.', 'System'),
	(136, 4, 14, '2025-11-02 14:53:00', 'Hi', 'Client'),
	(137, 4, 14, '2025-11-02 14:53:00', 'Good Day! How may I be of service today?', 'System'),
	(138, 4, 14, '2025-11-02 14:53:07', 'How much for  the rent?', 'Client'),
	(139, 4, 14, '2025-11-02 14:53:07', 'Please wait for a while, the studio owner will message you shortly.', 'System'),
	(140, 4, 14, '2025-11-02 14:53:10', 'Ok', 'Client'),
	(141, 4, 14, '2025-11-02 14:53:17', 'Ok', 'Client'),
	(142, 4, 14, '2025-11-08 13:58:03', 'Hi', 'Client'),
	(143, 4, 14, '2025-11-08 13:58:03', 'Good Day! How may I be of service today?', 'System'),
	(144, 4, 14, '2025-11-08 13:58:37', 'How much is per hour practice?', 'Client'),
	(145, 4, 14, '2025-11-08 13:58:37', 'Please wait for a while, the studio owner will message you shortly.', 'System'),
	(146, 4, 14, '2025-11-08 14:13:21', 'Hi how may I help you?', 'Owner'),
	(147, 4, 14, '2025-11-13 01:11:43', 'Hi', 'Client'),
	(148, 4, 14, '2025-11-13 01:11:43', 'Good Day! How may I be of service today?', 'System'),
	(149, 4, 14, '2025-11-13 01:45:19', 'Hi', 'Client'),
	(150, 4, 14, '2025-11-13 01:45:19', 'Please wait for a while, the studio owner will message you shortly.', 'System'),
	(151, 3, 14, '2025-11-13 01:53:51', 'Hi', 'Client'),
	(152, 3, 14, '2025-11-13 01:53:51', 'Good Day! How may I be of service today?', 'System'),
	(153, 4, 14, '2025-11-13 01:56:36', 'Hi', 'Client'),
	(154, 3, 14, '2025-11-13 02:28:14', 'hi', 'Client'),
	(155, 3, 14, '2025-11-13 02:28:14', 'Please wait for a while, the studio owner will message you shortly.', 'System'),
	(156, 3, 14, '2025-11-13 02:28:16', 'hi', 'Client'),
	(157, 3, 14, '2025-11-13 02:28:17', 'hi', 'Client'),
	(158, 3, 14, '2025-11-13 02:28:18', 'hhhhhhi', 'Client'),
	(159, 3, 14, '2025-11-13 02:28:19', 'hi', 'Client'),
	(160, 3, 14, '2025-11-13 02:28:56', 'asdasdasd', 'Client'),
	(161, 3, 14, '2025-11-13 05:35:33', 'What?', 'Owner'),
	(162, 3, 14, '2025-11-13 10:11:15', 'Hi', 'Client');

-- Dumping structure for table museek.clients
CREATE TABLE IF NOT EXISTS `clients` (
  `ClientID` int(11) NOT NULL AUTO_INCREMENT,
  `Name` varchar(255) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `Phone` varchar(255) NOT NULL,
  `Password` varchar(255) NOT NULL,
  `V_StatsID` int(11) DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `ProfileImg` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`ClientID`),
  KEY `V_StatsID` (`V_StatsID`),
  CONSTRAINT `clients_ibfk_1` FOREIGN KEY (`V_StatsID`) REFERENCES `verify_stats` (`V_StatsID`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.clients: ~10 rows (approximately)
INSERT INTO `clients` (`ClientID`, `Name`, `Email`, `Phone`, `Password`, `V_StatsID`, `last_login`, `ProfileImg`) VALUES
	(1, 'Mike Tambasen', 'mike@gmail.com', '09223456678', 'Kyllanz79!', 1, '2025-11-17 10:21:13', NULL),
	(2, 'Jane Doe', 'jane@example.com', '09123456789', 'password123', 1, NULL, NULL),
	(3, 'John Smith', 'john@example.com', '09123456790', 'password456', 1, NULL, NULL),
	(4, 'Alice Johnson', 'alice@example.com', '09123456780', 'password789', 1, NULL, NULL),
	(5, 'Bob Brown', 'bob@example.com', '09123456781', 'password101', 1, NULL, NULL),
	(6, 'Jane Doe', 'jane@example.com', '09123456789', 'password123', NULL, NULL, NULL),
	(7, 'John Smith', 'john@example.com', '09123456790', 'password456', NULL, NULL, NULL),
	(14, 'Kyzzer Lanz R. Jallorina', 'kljallorina.chmsu@gmail.com', '09508199489', '12345678', 2, '2025-11-17 10:25:48', 'avatars/clients/client_14_1761537080.png'),
	(16, 'Kyzzer Lanz Rabanal Jallorina', 'jkl;', '09508199489', '', NULL, NULL, NULL),
	(17, 'Kirk Lanz De La Cruz', 'kyzzer.jallorina@gmail.com', '+63 950 819 9489', '12345678', 3, NULL, NULL);

-- Dumping structure for table museek.documents
CREATE TABLE IF NOT EXISTS `documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `registration_id` int(11) DEFAULT NULL,
  `studio_id` int(11) NOT NULL,
  `document_type` enum('Business Permit','DTI Registration','BIR Certificate','Mayors Permit','ID Proof','Other') NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `verification_status` enum('pending','verified','rejected') DEFAULT 'pending',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verification_notes` text DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`document_id`),
  KEY `registration_id` (`registration_id`),
  KEY `verification_status` (`verification_status`),
  KEY `fk_verified_by` (`verified_by`),
  KEY `idx_documents_studio_id` (`studio_id`),
  CONSTRAINT `fk_documents_registration` FOREIGN KEY (`registration_id`) REFERENCES `studio_registrations` (`registration_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `admin_users` (`admin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Registration documents and verification';

-- Dumping data for table museek.documents: ~23 rows (approximately)
INSERT INTO `documents` (`document_id`, `registration_id`, `studio_id`, `document_type`, `file_name`, `file_path`, `file_size`, `mime_type`, `verification_status`, `verified_by`, `verified_at`, `verification_notes`, `uploaded_at`) VALUES
	(1, 2, 0, 'Business Permit', 'yellow-white-minimalist-web-designer-resume-20251028-030231.png', 'documents/2/yellow-white-minimalist-web-designer-resume-20251028-030231.png', 0, '', 'pending', NULL, NULL, NULL, '2025-10-28 02:02:31'),
	(2, 2, 0, 'Business Permit', 'jallorina-4c-profe7_act-3---high-fidelity-wireframe---landscape-20251028-030357.pdf', 'documents/2/jallorina-4c-profe7_act-3---high-fidelity-wireframe---landscape-20251028-030357.pdf', 0, '', 'pending', NULL, NULL, NULL, '2025-10-28 02:03:57'),
	(3, 5, 0, 'DTI Registration', '_ched-rpag-proposal-form-2024-nsop-docx-20251028-072634.pdf', 'documents/5/_ched-rpag-proposal-form-2024-nsop-docx-20251028-072634.pdf', 0, '', 'pending', NULL, NULL, NULL, '2025-10-28 06:26:34'),
	(4, 5, 0, 'Mayors Permit', 'governing-board-resolution-on-the-approval-and-endorsement-of-the-proposal-20251028-072647.pdf', 'documents/5/governing-board-resolution-on-the-approval-and-endorsement-of-the-proposal-20251028-072647.pdf', 0, '', 'pending', NULL, NULL, NULL, '2025-10-28 06:26:47'),
	(5, 5, 0, 'ID Proof', 'gemini_generated_image_fpmrr6fpmrr6fpmr-20251028-072702.png', 'documents/5/gemini_generated_image_fpmrr6fpmrr6fpmr-20251028-072702.png', 0, '', 'pending', NULL, NULL, NULL, '2025-10-28 06:27:02'),
	(6, 6, 0, 'DTI Registration', 'yellow-white-minimalist-web-designer-resume-20251028-030231.png', 'uploads/documents/6/id_proof_1761641395_7cbfd5e01116431c.png', 761522, 'image/png', 'pending', NULL, NULL, NULL, '2025-10-28 08:49:55'),
	(7, 6, 0, 'BIR Certificate', '_CHED-RPAG-Proposal-Form-2024-NSOP.docx.pdf', 'uploads/documents/6/id_proof_1761641423_273e4341d2a44e3b.pdf', 1683688, 'application/pdf', 'pending', NULL, NULL, NULL, '2025-10-28 08:50:23'),
	(8, 6, 0, 'Mayors Permit', 'yellow-white-minimalist-web-designer-resume-20251028-030231.png', 'uploads/documents/6/id_proof_1761641440_5917663b25e3a963.png', 761522, 'image/png', 'pending', NULL, NULL, NULL, '2025-10-28 08:50:40'),
	(9, 6, 0, 'Other', '223d8422-a6f1-4764-a4f4-d9d87fb5826e.jpg', 'uploads/documents/6/bir_certificate_1762616892_7852a91614aa.jpg', 1166686, 'image/jpeg', 'pending', NULL, NULL, NULL, '2025-11-08 15:48:12'),
	(11, 11, 3, 'Mayors Permit', 'yellow-white-minimalist-web-designer-resume-20251028-030231.png', 'uploads/documents/3/Mayors Permit_1762881178_ca8d4ea39a65.png', 761522, 'image/png', 'pending', NULL, NULL, NULL, '2025-11-11 17:12:58'),
	(22, 0, 3, 'BIR Certificate', 'Screenshot_2.png', 'uploads/documents/3/dti_registration_1762790712_46dc0709ece6.png', 1410130, 'image/png', 'pending', NULL, NULL, NULL, '2025-11-10 16:05:12'),
	(23, 0, 3, 'Business Permit', 'yellow-white-minimalist-web-designer-resume-20251028-030231.png', 'uploads/documents/3/business_permit_1762792399_bf69a611e495.png', 761522, 'image/png', 'pending', NULL, NULL, NULL, '2025-11-10 16:33:19'),
	(24, 0, 3, 'Business Permit', 'IZYL DLF.png', 'uploads/documents/3/dti_registration_1762878871_4fa3aada3146.png', 129032, 'image/png', 'pending', NULL, NULL, NULL, '2025-11-11 16:34:31'),
	(25, 12, 0, 'BIR Certificate', '223d8422-a6f1-4764-a4f4-d9d87fb5826e.jpg', 'uploads/documents/12/business_permit_1762792804_a15a61b7860ef4cb.jpg', 1166686, 'image/jpeg', 'pending', NULL, NULL, NULL, '2025-11-10 16:40:04'),
	(26, 12, 0, 'BIR Certificate', '223d8422-a6f1-4764-a4f4-d9d87fb5826e.jpg', 'uploads/documents/12/id_proof_1762792804_102c4bfee285f5e1.jpg', 1166686, 'image/jpeg', 'pending', NULL, NULL, NULL, '2025-11-10 16:40:04'),
	(27, 12, 0, 'Other', 'Screenshot_2.png', 'uploads/documents/12/other_1762792804_44029053db8a6804.png', 1410130, 'image/png', 'pending', NULL, NULL, NULL, '2025-11-10 16:40:04'),
	(28, 12, 0, 'Other', 'Screenshot_1.png', 'uploads/documents/12/other_1762792804_7e7f5b480d0ac028.png', 179099, 'image/png', 'pending', NULL, NULL, NULL, '2025-11-10 16:40:04'),
	(33, 11, 3, 'DTI Registration', 'IZYL DLF.png', 'uploads/documents/3/DTI Registration_1762882415_5cf69bf581ba.png', 129032, 'image/png', 'pending', NULL, NULL, NULL, '2025-11-11 17:33:35'),
	(34, 11, 3, 'BIR Certificate', 'Copy of PROFE7_ACT 3 - High Fidelity Wireframe - Landscape.docx', 'uploads/documents/3/BIR Certificate_1762882416_c8dbd44b259a.docx', 1114720, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'pending', NULL, NULL, NULL, '2025-11-11 17:33:36'),
	(35, 13, 0, '', '223d8422-a6f1-4764-a4f4-d9d87fb5826e.jpg', 'uploads/documents/13/business_permit_1763029412_ab6c7734a9c36450.jpg', 1166686, 'image/jpeg', 'pending', NULL, NULL, NULL, '2025-11-13 10:23:32'),
	(36, 13, 0, '', 'JALLORINA_KYZZER LANZ_CSE_PRO_APP.pdf', 'uploads/documents/13/dti_registration_1763029412_9b8bfa80dfb73b14.pdf', 1199207, 'application/pdf', 'pending', NULL, NULL, NULL, '2025-11-13 10:23:32'),
	(37, 13, 0, '', 'Screenshot_2.png', 'uploads/documents/13/bir_certificate_1763029412_f0db09ad6141efb7.png', 1410130, 'image/png', 'pending', NULL, NULL, NULL, '2025-11-13 10:23:32'),
	(38, 13, 0, '', 'yellow-white-minimalist-web-designer-resume-20251028-030231.png', 'uploads/documents/13/mayors_permit_1763029412_635c342851d1c2f3.png', 761522, 'image/png', 'pending', NULL, NULL, NULL, '2025-11-13 10:23:32');

-- Dumping structure for table museek.document_upload_tokens
CREATE TABLE IF NOT EXISTS `document_upload_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `registration_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `token` (`token`),
  KEY `registration_id` (`registration_id`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `fk_token_registration` FOREIGN KEY (`registration_id`) REFERENCES `studio_registrations` (`registration_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Secure document upload tokens';

-- Dumping data for table museek.document_upload_tokens: ~8 rows (approximately)
INSERT INTO `document_upload_tokens` (`token_id`, `registration_id`, `token`, `expires_at`, `is_used`, `created_at`) VALUES
	(1, 2, '4ad15f1dc4771998a988c1401c009c46c8bb1b9c973dc1e6ad80541366e12446', '2025-11-04 10:01:41', 1, '2025-10-28 02:01:41'),
	(2, 3, '1820e5b7796479422da20cba1fb2b42b431a686b31be40f46aac778ff34df60f', '2025-11-04 10:37:17', 0, '2025-10-28 02:37:17'),
	(4, 5, '94866487191dec39358aaa8b8dfcccbc7d092f57f6cdee7d8d74691dd7841df4', '2025-11-04 07:25:59', 1, '2025-10-28 06:25:59'),
	(5, 6, 'e50a75cea34e5ea3b51fe2c646be0ffb4b19ade1226a5636ec38d456635e1344', '2025-11-04 16:14:06', 0, '2025-10-28 08:14:06'),
	(6, 6, 'b48ad79af9fa4dcf20e8bef5ac11e3e2620f55fe4fbc86e5b2373eb9e1e31dbc', '2025-11-04 23:04:58', 0, '2025-10-28 15:04:58'),
	(10, 12, '8c2109d339d76685c5626657caec3ce4d815dd3c7845fca5c9cb4a24374e0e2a', '2025-11-18 00:36:35', 0, '2025-11-10 16:36:35'),
	(11, 13, 'fc278865230aeea64fb830b5cbb00b1dbda0b85b060c47725c379b7b5679d041', '2025-11-20 18:22:13', 0, '2025-11-13 10:22:13'),
	(13, 15, 'cbc437e4748aa72c78e958c6f30418e27bf622af3017ecc3a714f8b33228f9f7', '2025-11-24 20:52:59', 0, '2025-11-17 12:52:59');

-- Dumping structure for table museek.feedback
CREATE TABLE IF NOT EXISTS `feedback` (
  `FeedbackID` int(11) NOT NULL AUTO_INCREMENT,
  `OwnerID` int(11) DEFAULT NULL,
  `ClientID` int(11) DEFAULT NULL,
  `BookingID` int(11) DEFAULT NULL,
  `Rating` int(11) NOT NULL,
  `Comment` varchar(255) NOT NULL,
  `Date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`FeedbackID`),
  KEY `FK_feedback_studio_owners` (`OwnerID`),
  KEY `FK_feedback_clients` (`ClientID`),
  KEY `FK_feedback_bookings` (`BookingID`),
  CONSTRAINT `FK_feedback_bookings` FOREIGN KEY (`BookingID`) REFERENCES `bookings` (`BookingID`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `FK_feedback_clients` FOREIGN KEY (`ClientID`) REFERENCES `clients` (`ClientID`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `FK_feedback_studio_owners` FOREIGN KEY (`OwnerID`) REFERENCES `studio_owners` (`OwnerID`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.feedback: ~7 rows (approximately)
INSERT INTO `feedback` (`FeedbackID`, `OwnerID`, `ClientID`, `BookingID`, `Rating`, `Comment`, `Date`) VALUES
	(1, 3, 1, 9, 5, 'Very Good', '2025-05-20 01:45:22'),
	(2, 4, 1, 8, 5, 'The room was good!!!', '2025-05-20 01:55:33'),
	(3, 4, 1, 15, 5, 'Okie', '2025-05-20 03:28:11'),
	(4, 3, 1, 45, 3, 'It\'s so so.', '2025-10-25 02:09:08'),
	(5, 3, 14, 60, 5, 'Nice Studio', '2025-11-13 03:56:26'),
	(6, 9, 14, 58, 5, 'Ok', '2025-11-13 03:57:00'),
	(7, 3, 14, 66, 5, 'Good Environment, Nice instrument', '2025-11-13 10:13:58');

-- Dumping structure for table museek.g_cash
CREATE TABLE IF NOT EXISTS `g_cash` (
  `GCashID` int(11) NOT NULL,
  `GCash_Num` varchar(255) NOT NULL,
  `Ref_Num` varchar(255) NOT NULL,
  PRIMARY KEY (`GCashID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.g_cash: ~0 rows (approximately)
INSERT INTO `g_cash` (`GCashID`, `GCash_Num`, `Ref_Num`) VALUES
	(1, '0950 819 9489', 'src_PzPBLgRVhbvxJmuPZqjyvNHd');

-- Dumping structure for table museek.instructors
CREATE TABLE IF NOT EXISTS `instructors` (
  `InstructorID` int(11) NOT NULL AUTO_INCREMENT,
  `OwnerID` int(11) NOT NULL,
  `Name` varchar(255) NOT NULL,
  `Profession` varchar(255) NOT NULL,
  `Phone` varchar(50) NOT NULL,
  `Email` varchar(50) NOT NULL,
  `Bio` varchar(255) NOT NULL,
  `Availability` enum('Avail','Occupied','Break','Day-Off') NOT NULL DEFAULT 'Avail',
  `StudioID` int(11) DEFAULT NULL,
  PRIMARY KEY (`InstructorID`),
  KEY `OwnerID` (`OwnerID`),
  KEY `fk_instructor_studio` (`StudioID`),
  CONSTRAINT `fk_instructor_studio` FOREIGN KEY (`StudioID`) REFERENCES `studios` (`StudioID`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `instructors_ibfk_1` FOREIGN KEY (`OwnerID`) REFERENCES `studio_owners` (`OwnerID`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.instructors: ~6 rows (approximately)
INSERT INTO `instructors` (`InstructorID`, `OwnerID`, `Name`, `Profession`, `Phone`, `Email`, `Bio`, `Availability`, `StudioID`) VALUES
	(1, 4, 'Kyzzer Lanz Jallorina', 'Drums', '09508199489', 'kyzzer.jallorina@gmail.com', 'Have a Masters Degree in Music Theory', 'Avail', NULL),
	(2, 4, 'Rian Earl Cerbo', 'Lead Guitar', '0982102845', 'earlsola@mail.com', '', 'Avail', NULL),
	(3, 3, 'Kirk Lanz De La Cruz', 'Bass', '097777544655', 'kirk@gmail.com', 'I have a bachelor\'s degree of Bass', 'Avail', NULL),
	(4, 3, 'John Gabriel Traje', 'Voice Coaching', '09854977531', 'totodandan@gmail.com', 'I have won at least 5 singing contests', 'Avail', NULL),
	(10, 9, 'Kirk Lanz De La Cruz', 'Bass', '09602133458', 'kirk@gmail.com', '', 'Avail', NULL),
	(11, 3, 'Kyzzer Lanz R. Jallorina', 'Drums', '09508199489', 'kljallorina.chmsu@gmail.com', '', 'Avail', NULL);

-- Dumping structure for table museek.instructor_services
CREATE TABLE IF NOT EXISTS `instructor_services` (
  `InstructorID` int(11) NOT NULL DEFAULT 0,
  `ServiceID` int(11) NOT NULL DEFAULT 0,
  KEY `FK_instructor_services_instructors` (`InstructorID`),
  KEY `FK_instructor_services_services` (`ServiceID`),
  CONSTRAINT `FK_instructor_services_instructors` FOREIGN KEY (`InstructorID`) REFERENCES `instructors` (`InstructorID`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `FK_instructor_services_services` FOREIGN KEY (`ServiceID`) REFERENCES `services` (`ServiceID`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.instructor_services: ~11 rows (approximately)
INSERT INTO `instructor_services` (`InstructorID`, `ServiceID`) VALUES
	(1, 1),
	(1, 1),
	(1, 1),
	(2, 1),
	(2, 1),
	(10, 6),
	(10, 7),
	(4, 3),
	(3, 3),
	(3, 5),
	(11, 6);

-- Dumping structure for table museek.instruments
CREATE TABLE IF NOT EXISTS `instruments` (
  `InstrumentID` int(11) NOT NULL AUTO_INCREMENT,
  `StudioID` int(11) NOT NULL,
  `Name` varchar(100) NOT NULL,
  `Type` varchar(50) DEFAULT NULL,
  `Brand` varchar(50) DEFAULT NULL,
  `HourlyRate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `Quantity` int(11) NOT NULL DEFAULT 1,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`InstrumentID`),
  KEY `StudioID` (`StudioID`),
  CONSTRAINT `fk_instruments_studio` FOREIGN KEY (`StudioID`) REFERENCES `studios` (`StudioID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.instruments: ~3 rows (approximately)
INSERT INTO `instruments` (`InstrumentID`, `StudioID`, `Name`, `Type`, `Brand`, `HourlyRate`, `Quantity`, `IsActive`) VALUES
	(1, 1, 'Electric Guitar', 'Guitar', 'Fender', 150.00, 2, 1),
	(2, 1, 'Bass Guitar', 'Bass', 'Ibanez', 120.00, 1, 1);

-- Dumping structure for table museek.notifications
CREATE TABLE IF NOT EXISTS `notifications` (
  `NotificationID` int(11) NOT NULL AUTO_INCREMENT,
  `OwnerID` int(11) DEFAULT NULL,
  `ClientID` int(11) DEFAULT NULL,
  `Type` varchar(50) NOT NULL,
  `Message` text NOT NULL,
  `RelatedID` int(11) DEFAULT NULL,
  `IsRead` tinyint(1) DEFAULT 0,
  `Created_At` datetime DEFAULT current_timestamp(),
  `For_User` enum('Client','Owner','Admin') NOT NULL,
  PRIMARY KEY (`NotificationID`),
  KEY `OwnerID` (`OwnerID`),
  KEY `ClientID` (`ClientID`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`OwnerID`) REFERENCES `studio_owners` (`OwnerID`),
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`ClientID`) REFERENCES `clients` (`ClientID`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.notifications: ~59 rows (approximately)
INSERT INTO `notifications` (`NotificationID`, `OwnerID`, `ClientID`, `Type`, `Message`, `RelatedID`, `IsRead`, `Created_At`, `For_User`) VALUES
	(1, 3, 1, 'Booking', 'New booking request for Mixing on 2025-05-20 from 09:00 to 11:00.', 10, 1, '2025-05-19 19:50:10', 'Client'),
	(2, 3, 1, 'booking_confirmation', 'Your booking #10 has been confirmed', NULL, 1, '2025-05-19 20:32:09', 'Client'),
	(3, 3, 1, 'payment_confirmation', 'Payment for your booking #9 has been confirmed', NULL, 1, '2025-05-19 21:21:12', 'Client'),
	(4, 3, 1, 'booking_finished', 'Your booking #9 has been marked as finished', NULL, 1, '2025-05-19 21:21:20', 'Client'),
	(5, 3, 1, 'payment_confirmation', 'Payment for your booking #9 has been confirmed', NULL, 1, '2025-05-19 22:16:25', 'Client'),
	(6, 3, 1, 'booking_cancellation', 'Your booking #9 has been cancelled', 9, 1, '2025-05-19 22:39:02', 'Client'),
	(7, 3, 1, 'booking_confirmation', 'Your booking for Mike Tambasen MusicLab and Productions on May 20, 2025 from 8:00 AM to 9:00 AM has been confirmed', NULL, 1, '2025-05-20 00:03:17', 'Client'),
	(8, 3, 1, 'payment_confirmation', 'Payment for your booking #9 has been confirmed', NULL, 1, '2025-05-20 08:52:55', 'Client'),
	(9, 3, 1, 'payment_confirmation', 'Payment for your booking #9 has been confirmed', NULL, 1, '2025-05-20 08:57:08', 'Client'),
	(11, 4, 1, 'Booking', 'New booking request for Mixing on 2025-05-20 from 12:00 to 13:00.', 15, 1, '2025-05-20 11:08:28', 'Client'),
	(12, 4, 1, 'booking_confirmation', 'Your booking for SkyTrack Band Rehearsals and Recording Studio on May 20, 2025 from 12:00 PM to 1:00 PM has been confirmed', NULL, 1, '2025-05-20 11:18:26', 'Client'),
	(13, 4, 1, 'payment_confirmation', 'Payment for your booking #15 has been confirmed', NULL, 1, '2025-05-20 11:18:39', 'Client'),
	(14, 4, 1, 'booking_confirmation', 'Your booking for SkyTrack Band Rehearsals and Recording Studio on May 20, 2025 from 12:00 PM to 1:00 PM has been confirmed', NULL, 1, '2025-05-20 11:26:52', 'Client'),
	(15, 4, 1, 'payment_confirmation', 'Payment for your booking #15 has been confirmed', NULL, 1, '2025-05-20 11:27:04', 'Client'),
	(16, 4, 1, 'Booking', 'New booking request for Recording on 2025-05-21 from 09:00 to 10:00.', 16, 1, '2025-05-20 13:47:45', 'Client'),
	(17, 4, 1, 'booking_confirmation', 'Your booking for SkyTrack Band Rehearsals and Recording Studio on May 21, 2025 from 9:00 AM to 10:00 AM has been confirmed', NULL, 1, '2025-05-20 13:52:07', 'Client'),
	(18, 4, 1, 'payment_confirmation', 'Payment for your booking #16 has been confirmed', NULL, 1, '2025-05-20 13:53:10', 'Client'),
	(19, 4, 1, 'booking_confirmation', 'Your booking for SkyTrack Band Rehearsals and Recording Studio on September 28, 2025 from 11:00 AM to 12:00 PM has been confirmed', NULL, 1, '2025-09-28 19:05:47', 'Client'),
	(20, 4, 1, 'payment_confirmation', 'Payment for your booking #25 has been confirmed', NULL, 1, '2025-09-28 19:06:17', 'Client'),
	(21, 4, 1, 'booking_finished', 'Booking has been marked as finished.', NULL, 1, '2025-10-05 21:28:21', 'Client'),
	(22, 4, 1, 'booking_finished', 'Booking has been marked as finished.', NULL, 1, '2025-10-05 22:03:11', 'Client'),
	(23, NULL, 1, 'booking', 'Your booking status has been updated to Confirmed', 37, 1, '2025-10-21 06:44:05', 'Client'),
	(24, 3, 1, 'payment_confirmation', 'Payment for your booking #37 has been confirmed', NULL, 1, '2025-10-21 06:46:43', 'Client'),
	(25, 3, 1, 'booking_finished', 'Your booking #37 has been marked as finished', NULL, 1, '2025-10-21 06:48:39', 'Client'),
	(26, NULL, 1, 'booking', 'Your booking has been marked as completed. Thank you for using our service!', 37, 1, '2025-10-21 06:54:31', 'Client'),
	(27, NULL, 1, 'booking', 'Your booking has been marked as completed. Thank you for using our service!', 37, 1, '2025-10-21 06:59:45', 'Client'),
	(28, NULL, 1, 'booking', 'Your booking has been marked as completed. Thank you for using our service!', 37, 1, '2025-10-21 07:03:54', 'Client'),
	(29, NULL, 1, 'booking', 'Your booking status has been updated to Confirmed', 36, 1, '2025-10-21 07:08:57', 'Client'),
	(30, NULL, 1, 'booking', 'Your booking has been marked as completed. Thank you for using our service!', 36, 1, '2025-10-21 07:11:50', 'Client'),
	(31, NULL, 1, 'booking', 'Your booking has been marked as completed. Thank you for using our service!', 36, 1, '2025-10-21 07:14:27', 'Client'),
	(32, NULL, 1, 'booking', 'Your booking has been marked as completed. Thank you for using our service!', 37, 1, '2025-10-21 07:20:04', 'Client'),
	(33, NULL, 1, 'booking', 'Your booking has been marked as completed. Thank you for using our service!', 37, 1, '2025-10-21 07:21:02', 'Client'),
	(34, NULL, 1, 'booking', 'Your booking has been marked as completed. Thank you for using our service!', 36, 1, '2025-10-21 07:23:43', 'Client'),
	(35, NULL, 1, 'booking', 'Your booking has been marked as completed. Thank you for using our service!', 37, 1, '2025-10-21 07:26:12', 'Client'),
	(36, NULL, 1, 'booking', 'Your booking has been marked as completed. Thank you for using our service!', 36, 1, '2025-10-21 07:28:51', 'Client'),
	(37, NULL, 1, 'booking', 'Your booking status has been updated to Confirmed', 39, 1, '2025-10-21 07:53:31', 'Client'),
	(38, NULL, 1, 'booking', 'Your booking status has been updated to Confirmed', 38, 1, '2025-10-21 07:59:07', 'Client'),
	(39, NULL, 1, 'booking', 'Your booking has been marked as completed. Thank you for using our service!', 38, 1, '2025-10-21 08:00:36', 'Client'),
	(40, NULL, 1, 'booking', 'Your booking has been marked as completed. Thank you for using our service!', 39, 1, '2025-10-21 08:02:54', 'Client'),
	(41, NULL, 1, 'booking', 'Your booking status has been updated to Confirmed', 39, 1, '2025-10-21 08:56:59', 'Client'),
	(42, 3, 1, 'booking_cancellation', 'Your booking #39 has been cancelled', 39, 1, '2025-10-22 10:24:08', 'Client'),
	(43, 3, 1, 'payment_confirmation', 'Payment for your booking #39 has been confirmed', NULL, 0, '2025-10-22 10:32:42', 'Client'),
	(44, 3, 1, 'booking_finished', 'Your booking #39 has been marked as finished', 39, 1, '2025-10-22 10:58:40', 'Client'),
	(45, 3, 1, 'booking_confirmation', 'Your booking for Mike Tambasen MusicLab and Productions on October 25, 2025 from 10:00 AM to 11:00 AM has been confirmed', NULL, 1, '2025-10-25 02:06:43', 'Client'),
	(46, 3, 1, 'payment_confirmation', 'Payment for your booking #40 has been confirmed', NULL, 0, '2025-10-25 02:12:46', 'Client'),
	(47, 3, 1, 'booking_finished', 'Your booking #40 has been marked as finished', 40, 1, '2025-10-25 02:15:37', 'Client'),
	(48, 3, 1, 'booking_confirmation', 'Your booking for Mike Tambasen MusicLab and Productions on October 25, 2025 from 9:00 AM to 10:00 AM has been confirmed', NULL, 1, '2025-10-25 02:27:20', 'Client'),
	(49, 3, 1, 'booking_confirmation', 'Your booking for Mike Tambasen MusicLab and Productions on October 25, 2025 from 2:00 PM to 3:00 PM has been confirmed', NULL, 1, '2025-10-25 09:42:54', 'Client'),
	(50, 3, 1, 'payment_confirmation', 'Payment for your booking #45 has been confirmed', NULL, 0, '2025-10-25 09:43:58', 'Client'),
	(51, 3, 1, 'booking_finished', 'Booking has been marked as finished.', NULL, 1, '2025-10-25 10:08:49', 'Client'),
	(52, NULL, NULL, 'document_request', 'Please upload studio documents: http://localhost:8000/auth/php/upload-documents.php?token=e50a75cea34e5ea3b51fe2c646be0ffb4b19ade1226a5636ec38d456635e1344', 6, 0, '2025-10-28 16:14:07', 'Owner'),
	(53, NULL, NULL, 'document_request', 'Please upload studio documents: http://localhost:8000/auth/php/subscription-payment.php?token=b48ad79af9fa4dcf20e8bef5ac11e3e2620f55fe4fbc86e5b2373eb9e1e31dbc', 6, 0, '2025-10-28 23:04:58', 'Owner'),
	(54, NULL, NULL, 'document_request', 'Please upload studio documents: http://localhost:8000/auth/php/submit-subscription.php?token=48fbd1cc7b1e74598a001d34c976a46f7439dae266ef8057731e20c188ef027c', 7, 0, '2025-10-29 01:01:37', 'Owner'),
	(55, NULL, NULL, 'document_request', 'Please upload studio documents: http://localhost:8000/auth/php/upload-documents.php?token=adace5c171a541e2c054206bc3959a8879eaf827d6e889e7ffbfe18d890bed55', 7, 0, '2025-10-29 01:14:04', 'Owner'),
	(56, 9, 14, 'booking_confirmation', 'Your booking for JertPercz Audio on October 29, 2025 from 8:00 PM to 9:00 PM has been confirmed', NULL, 0, '2025-10-29 15:02:58', 'Client'),
	(57, 9, 14, 'booking_confirmation', 'Your booking for JertPercz Audio on October 29, 2025 from 8:00 PM to 9:00 PM has been confirmed', NULL, 0, '2025-10-29 15:03:01', 'Client'),
	(58, 9, 14, 'booking_confirmation', 'Your booking for JertPercz Audio on October 29, 2025 from 8:00 PM to 9:00 PM has been confirmed', NULL, 0, '2025-10-29 15:03:05', 'Client'),
	(59, 9, 14, 'booking_confirmation', 'Your booking for JertPercz Audio on October 29, 2025 from 8:00 PM to 9:00 PM has been confirmed', NULL, 1, '2025-10-29 15:03:09', 'Client'),
	(60, 9, 14, 'payment_confirmation', 'Payment for your booking #58 has been confirmed', NULL, 1, '2025-10-29 15:07:39', 'Client'),
	(61, 9, 14, 'booking_finished', 'Booking has been marked as finished.', NULL, 1, '2025-10-29 17:26:15', 'Client'),
	(62, 3, 14, 'booking_confirmation', 'Your booking for Mike Tambasen MusicLab and Productions on November 13, 2025 from 9:00 AM to 10:00 AM has been confirmed', NULL, 0, '2025-11-13 11:15:50', 'Client'),
	(63, 3, 14, 'payment_confirmation', 'Payment for your booking #60 has been confirmed', NULL, 0, '2025-11-13 11:28:22', 'Client'),
	(64, 3, 14, 'booking_finished', 'Your booking #60 has been marked as finished', 60, 0, '2025-11-13 11:28:38', 'Client'),
	(65, 9, 14, 'booking_finished', 'Booking has been marked as finished.', NULL, 0, '2025-11-13 11:46:12', 'Client'),
	(66, 3, 14, 'booking_confirmation', 'Your booking for Mike Tambasen MusicLab and Productions on November 13, 2025 from 6:00 AM to 7:00 AM has been confirmed', NULL, 0, '2025-11-13 13:38:51', 'Client'),
	(67, 3, 14, 'booking_confirmation', 'Your booking for Mike Tambasen MusicLab and Productions on November 14, 2025 from 2:00 PM to 5:00 PM has been confirmed', NULL, 0, '2025-11-13 17:57:28', 'Client'),
	(68, 3, 14, 'payment_confirmation', 'Payment for your booking #66 has been confirmed', NULL, 0, '2025-11-13 18:12:30', 'Client'),
	(69, 3, 14, 'booking_finished', 'Your booking #66 has been marked as finished', 66, 0, '2025-11-13 18:12:56', 'Client'),
	(70, NULL, NULL, 'document_request', 'Please upload studio documents: http://localhost:8000/auth/php/upload-documents.php?token=fc278865230aeea64fb830b5cbb00b1dbda0b85b060c47725c379b7b5679d041', 13, 0, '2025-11-13 18:22:13', 'Owner'),
	(71, NULL, NULL, 'document_request', 'Please upload studio documents: http://localhost:8000/auth/php/upload-documents.php?token=cbc437e4748aa72c78e958c6f30418e27bf622af3017ecc3a714f8b33228f9f7', 15, 0, '2025-11-17 20:52:59', 'Owner');

-- Dumping structure for table museek.owners
CREATE TABLE IF NOT EXISTS `owners` (
  `OwnerID` int(11) NOT NULL AUTO_INCREMENT,
  `Name` varchar(150) NOT NULL,
  `Email` varchar(150) NOT NULL,
  `Phone` varchar(50) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`OwnerID`),
  UNIQUE KEY `Email` (`Email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.owners: ~0 rows (approximately)

-- Dumping structure for table museek.payment
CREATE TABLE IF NOT EXISTS `payment` (
  `PaymentID` int(11) NOT NULL AUTO_INCREMENT,
  `PaymentGroupID` varchar(50) DEFAULT NULL,
  `BookingID` int(11) DEFAULT NULL,
  `OwnerID` int(11) DEFAULT NULL,
  `Init_Amount` decimal(10,2) NOT NULL,
  `Amount` decimal(10,2) NOT NULL,
  `Pay_Date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `GCashID` int(11) DEFAULT NULL,
  `CashID` int(11) DEFAULT NULL,
  `Pay_Stats` enum('Pending','Completed','Failed') NOT NULL,
  PRIMARY KEY (`PaymentID`),
  KEY `BookingID` (`BookingID`),
  KEY `OwnerID` (`OwnerID`),
  KEY `GCashID` (`GCashID`),
  KEY `CashID` (`CashID`),
  CONSTRAINT `payment_ibfk_1` FOREIGN KEY (`BookingID`) REFERENCES `bookings` (`BookingID`),
  CONSTRAINT `payment_ibfk_2` FOREIGN KEY (`OwnerID`) REFERENCES `studio_owners` (`OwnerID`),
  CONSTRAINT `payment_ibfk_3` FOREIGN KEY (`GCashID`) REFERENCES `g_cash` (`GCashID`),
  CONSTRAINT `payment_ibfk_4` FOREIGN KEY (`CashID`) REFERENCES `cash` (`CashID`)
) ENGINE=InnoDB AUTO_INCREMENT=667010 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.payment: ~47 rows (approximately)
INSERT INTO `payment` (`PaymentID`, `PaymentGroupID`, `BookingID`, `OwnerID`, `Init_Amount`, `Amount`, `Pay_Date`, `GCashID`, `CashID`, `Pay_Stats`) VALUES
	(1, NULL, 10, 3, 0.00, 600.00, '2025-05-19 11:53:15', NULL, NULL, 'Pending'),
	(2, NULL, 9, 3, 0.00, 500.00, '2025-05-20 00:58:19', NULL, NULL, 'Completed'),
	(3, NULL, 8, 4, 0.00, 700.00, '2025-05-20 01:55:14', NULL, NULL, 'Completed'),
	(666953, NULL, 15, 4, 75.00, 300.00, '2025-05-20 03:28:00', NULL, NULL, 'Completed'),
	(666954, NULL, 16, 4, 125.00, 500.00, '2025-05-20 05:53:10', NULL, NULL, 'Completed'),
	(666955, NULL, 17, 4, 250.00, 1000.00, '2025-09-23 16:13:59', NULL, NULL, 'Pending'),
	(666956, NULL, 19, 4, 200.00, 800.00, '2025-09-23 16:15:21', NULL, NULL, 'Pending'),
	(666957, NULL, 21, 4, 200.00, 800.00, '2025-09-24 02:18:48', NULL, NULL, 'Pending'),
	(666958, NULL, 23, 4, 200.00, 800.00, '2025-09-25 23:38:39', NULL, NULL, 'Pending'),
	(666959, NULL, 25, 4, 200.00, 800.00, '2025-09-28 11:06:17', NULL, NULL, 'Completed'),
	(666960, 'PG_20250927_201757_68d82a55b5a5f7.33558711', 29, 4, 150.00, 600.00, '2025-09-27 18:17:57', NULL, NULL, 'Pending'),
	(666961, 'PG_20250927_201757_68d82a55b5a5f7.33558711', 30, 4, 150.00, 600.00, '2025-09-27 18:17:57', NULL, NULL, 'Pending'),
	(666962, 'PG_20250929_015854_68d9cbbe368030.61427153', 31, 4, 275.00, 1100.00, '2025-09-28 23:58:54', NULL, NULL, 'Pending'),
	(666963, 'PG_20250929_015854_68d9cbbe368030.61427153', 32, 4, 275.00, 1100.00, '2025-09-28 23:58:54', NULL, NULL, 'Pending'),
	(666964, 'PG_20250929_054444_68da00ac8279f6.11372552', 33, 4, 450.00, 1800.00, '2025-09-29 03:44:44', NULL, NULL, 'Pending'),
	(666965, 'PG_20250929_054444_68da00ac8279f6.11372552', 34, 4, 450.00, 1800.00, '2025-10-05 14:03:04', NULL, NULL, 'Completed'),
	(666966, NULL, 35, 4, 125.00, 500.00, '2025-10-05 13:48:54', NULL, NULL, 'Pending'),
	(666967, 'PG_20251020_184610_68f66752d75554.96579087', 36, 3, 250.00, 1000.00, '2025-10-20 23:23:43', NULL, NULL, 'Completed'),
	(666968, 'PG_20251020_184610_68f66752d75554.96579087', 37, 3, 250.00, 1000.00, '2025-10-20 23:21:02', NULL, NULL, 'Completed'),
	(666969, 'PG_20251021_014044_68f6c87cad6205.73386219', 38, 3, 125.00, 500.00, '2025-10-21 00:00:36', NULL, NULL, 'Completed'),
	(666970, 'PG_20251021_014044_68f6c87cad6205.73386219', 39, 3, 125.00, 500.00, '2025-10-22 02:32:42', NULL, NULL, 'Completed'),
	(666971, 'PG_20251024_152450_68fb7e227763e4.42086664', 40, 3, 125.00, 500.00, '2025-10-24 18:12:46', NULL, NULL, 'Completed'),
	(666972, 'PG_20251024_152450_68fb7e227763e4.42086664', 41, 3, 100.00, 400.00, '2025-10-24 13:24:50', NULL, NULL, 'Pending'),
	(666973, 'PG_20251024_191141_68fbb34dac3e18.73886296', 42, 4, 230.00, 920.00, '2025-10-24 17:11:41', NULL, NULL, 'Pending'),
	(666974, 'PG_20251024_191141_68fbb34dac3e18.73886296', 43, 4, 230.00, 920.00, '2025-10-24 17:40:53', NULL, NULL, 'Failed'),
	(666975, 'PG_20251025_034037_68fc2a95b57dc2.30532648', 44, 3, 125.00, 500.00, '2025-10-25 01:40:38', NULL, NULL, 'Pending'),
	(666976, 'PG_20251025_034037_68fc2a95b57dc2.30532648', 45, 3, 125.00, 500.00, '2025-10-25 01:43:58', NULL, NULL, 'Completed'),
	(666977, 'PG_20251025_045749_68fc3cad361307.97431009', 46, 4, 155.00, 620.00, '2025-10-25 02:57:49', NULL, NULL, 'Pending'),
	(666978, 'PG_20251025_051703_68fc412f205af3.04161691', 47, 3, 125.00, 500.00, '2025-10-25 03:17:03', NULL, NULL, 'Pending'),
	(666979, 'PG_20251025_051703_68fc412f205af3.04161691', 48, 3, 200.00, 800.00, '2025-10-25 03:17:03', NULL, NULL, 'Pending'),
	(666980, 'PG_20251025_052825_68fc43d94d9c89.33276517', 49, 4, 230.00, 920.00, '2025-10-25 03:28:25', NULL, NULL, 'Pending'),
	(666981, 'PG_20251025_092319_68fc7ae76824d1.63293428', 50, 4, 192.50, 770.00, '2025-10-25 07:23:19', NULL, NULL, 'Pending'),
	(666982, 'PG_20251025_092319_68fc7ae76824d1.63293428', 51, 4, 125.00, 500.00, '2025-10-25 07:23:19', NULL, NULL, 'Pending'),
	(666983, 'PG_20251025_093057_68fc7cb18a2b44.51269666', 52, 4, 125.00, 500.00, '2025-10-25 07:30:57', NULL, NULL, 'Pending'),
	(666984, 'PG_20251025_093931_68fc7eb34eb9a0.99799690', 53, 4, 125.00, 500.00, '2025-10-25 07:39:31', NULL, NULL, 'Pending'),
	(666985, 'PG_20251025_094506_68fc8002e75ac5.13433942', 54, 4, 125.00, 500.00, '2025-10-25 07:45:06', NULL, NULL, 'Pending'),
	(666986, 'PG_20251025_094732_68fc80945e1981.97821199', 55, 4, 125.00, 500.00, '2025-10-25 07:47:32', NULL, NULL, 'Pending'),
	(666987, 'PG_20251025_094853_68fc80e582f452.52239471', 56, 4, 125.00, 500.00, '2025-10-25 07:48:53', NULL, NULL, 'Pending'),
	(666988, 'PG_20251025_094853_68fc80e582f452.52239471', 57, 4, 192.50, 770.00, '2025-10-25 07:48:53', NULL, NULL, 'Pending'),
	(666989, 'SUB_6', NULL, NULL, 0.00, 500.00, '2025-10-28 08:14:15', NULL, NULL, 'Pending'),
	(666990, 'SUB_6', NULL, NULL, 0.00, 500.00, '2025-10-28 14:07:34', NULL, NULL, 'Pending'),
	(666991, 'SUB_6', NULL, NULL, 0.00, 500.00, '2025-10-28 14:51:17', NULL, NULL, 'Pending'),
	(666992, 'SUB_6', NULL, NULL, 0.00, 500.00, '2025-10-28 15:05:06', NULL, NULL, 'Pending'),
	(666993, 'SUB_6', NULL, NULL, 0.00, 500.00, '2025-10-28 15:09:22', NULL, NULL, 'Pending'),
	(666994, 'SUB_7', NULL, NULL, 0.00, 500.00, '2025-10-28 17:14:33', NULL, NULL, 'Pending'),
	(666995, 'PG_20251028_211551_6901247738aa80.14225830', 58, 9, 125.00, 500.00, '2025-10-29 07:07:38', NULL, NULL, 'Completed'),
	(666996, 'PG_20251028_211551_6901247738aa80.14225830', 59, 9, 125.00, 500.00, '2025-10-28 20:15:51', NULL, NULL, 'Pending'),
	(666997, 'PG_20251113_020222_69152e1e142698.60832799', 60, 3, 100.00, 400.00, '2025-11-13 03:28:22', NULL, NULL, 'Completed'),
	(666998, 'PG_20251113_020222_69152e1e142698.60832799', 61, 3, 125.00, 500.00, '2025-11-13 01:02:22', NULL, NULL, 'Pending'),
	(666999, 'PG_20251113_105141_6915aa2da05bd2.68625161', 66, 3, 375.00, 1500.00, '2025-11-13 10:12:30', NULL, NULL, 'Completed'),
	(667000, 'PG_20251113_110946_6915ae6ad0dc47.35100398', 67, 3, 500.00, 2000.00, '2025-11-13 10:09:46', NULL, NULL, 'Pending'),
	(667001, 'SUB_14', NULL, NULL, 0.00, 500.00, '2025-11-17 12:48:18', NULL, NULL, 'Pending'),
	(667002, 'SUB_15', NULL, NULL, 0.00, 500.00, '2025-11-17 12:54:04', NULL, NULL, 'Pending'),
	(667003, 'SUB_15', NULL, NULL, 0.00, 500.00, '2025-11-17 12:58:18', NULL, NULL, 'Pending'),
	(667004, 'SUB_15', NULL, NULL, 0.00, 500.00, '2025-11-17 13:03:19', NULL, NULL, 'Pending'),
	(667005, 'SUB_15', NULL, NULL, 0.00, 500.00, '2025-11-17 13:03:22', NULL, NULL, 'Pending'),
	(667006, 'SUB_15', NULL, NULL, 0.00, 500.00, '2025-11-17 13:03:28', NULL, NULL, 'Pending'),
	(667007, 'SUB_15', NULL, NULL, 0.00, 500.00, '2025-11-17 13:03:32', NULL, NULL, 'Pending'),
	(667008, 'SUB_15', NULL, NULL, 0.00, 500.00, '2025-11-17 13:04:22', NULL, NULL, 'Pending'),
	(667009, 'SUB_15', NULL, NULL, 0.00, 500.00, '2025-11-17 13:09:13', NULL, NULL, 'Pending');

-- Dumping structure for table museek.push_subscriptions
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `endpoint` varchar(512) NOT NULL,
  `p256dh` varchar(512) NOT NULL,
  `auth` varchar(512) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `owner_id` (`owner_id`,`endpoint`),
  CONSTRAINT `push_subscriptions_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `studio_owners` (`OwnerID`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.push_subscriptions: ~0 rows (approximately)
INSERT INTO `push_subscriptions` (`id`, `owner_id`, `endpoint`, `p256dh`, `auth`, `created_at`) VALUES
	(2, 3, 'https://wns2-bl2p.notify.windows.com/w/?token=BQYAAAA010GmNfiv2sHnrULUvky3iBXawVUSAE3zlaxzHLyXTw2qvl3HNedhwWN6BULX3xY8mi3ZrKilIhELehkGvBT%2flk143kXUMj4M5eKfFIiQKqcMYSgcXVAQz0Y1TDuYiajexzZNRzRfIqXT4xcMHce9VZtAxK4OK%2b6knLLiYbLPlHYvrL76SDg5I8OGuiNnJihJd0fSLvqA13Jm3kjgls1xU7SOLCzMOTcPe%2bsC2mma0u8p3bGyY3zmOTjTpr67qd1TJh5fUNhtEnz02lK4ECcK5FxpZsJwBO5yKwn9BVlJ2iyy5FOpwFOn2y9sIZDJelE%3d', 'BODMAsx1Fhdh0wIbx5FqQxjwzBBbV/tSnvRewLfrYf3oGqKo1wSZU7kT/TWSqbakFKjcuIZppSAcWW7CKkCkuFI=', 'bXWmc4C2LttNdbc/SH/3KQ==', '2025-05-17 22:07:34');

-- Dumping structure for table museek.registration_payments
CREATE TABLE IF NOT EXISTS `registration_payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `registration_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('gcash','card','bank_transfer','cash') NOT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `payment_status` enum('pending','completed','failed','refunded') DEFAULT 'pending',
  `payment_date` datetime DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`payment_id`),
  KEY `registration_id` (`registration_id`),
  KEY `payment_status` (`payment_status`),
  KEY `fk_processed_by` (`processed_by`),
  CONSTRAINT `fk_payment_registration` FOREIGN KEY (`registration_id`) REFERENCES `studio_registrations` (`registration_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `admin_users` (`admin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Registration fee payments';

-- Dumping data for table museek.registration_payments: ~3 rows (approximately)
INSERT INTO `registration_payments` (`payment_id`, `registration_id`, `amount`, `payment_method`, `payment_reference`, `payment_status`, `payment_date`, `processed_by`, `notes`, `created_at`, `updated_at`) VALUES
	(1, 1, 500.00, 'gcash', NULL, 'pending', NULL, NULL, 'Verification subscription fee', '2025-10-25 07:20:18', '2025-10-25 07:20:18'),
	(2, 6, 999.00, 'gcash', NULL, 'pending', NULL, NULL, NULL, '2025-10-28 08:43:52', '2025-10-28 08:43:52'),
	(3, 6, 999.00, 'gcash', 'src_CaqXHfqZaD2GsvbnF8kqQzWi', 'completed', NULL, NULL, 'PayMongo source created | phone: 0950 819 9489', '2025-10-28 16:37:51', '2025-10-28 16:51:40');

-- Dumping structure for table museek.reports
CREATE TABLE IF NOT EXISTS `reports` (
  `ReportID` int(11) NOT NULL,
  `PaymentID` int(11) DEFAULT NULL,
  `OwnerID` int(11) DEFAULT NULL,
  `FeedbackID` int(11) DEFAULT NULL,
  `R_Type` varchar(255) NOT NULL,
  `R_Content` varchar(255) NOT NULL,
  `Date` date NOT NULL,
  PRIMARY KEY (`ReportID`),
  KEY `PaymentID` (`PaymentID`),
  KEY `OwnerID` (`OwnerID`),
  KEY `fk_reports_feedback` (`FeedbackID`),
  CONSTRAINT `fk_reports_feedback` FOREIGN KEY (`FeedbackID`) REFERENCES `feedback` (`FeedbackID`),
  CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`PaymentID`) REFERENCES `payment` (`PaymentID`),
  CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`OwnerID`) REFERENCES `studio_owners` (`OwnerID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.reports: ~0 rows (approximately)

-- Dumping structure for table museek.schedules
CREATE TABLE IF NOT EXISTS `schedules` (
  `ScheduleID` int(11) NOT NULL AUTO_INCREMENT,
  `OwnerID` int(11) DEFAULT NULL,
  `StudioID` int(11) DEFAULT NULL,
  `Sched_Date` date NOT NULL,
  `Time_Start` time NOT NULL,
  `Time_End` time NOT NULL,
  `Avail_StatsID` int(11) DEFAULT NULL,
  PRIMARY KEY (`ScheduleID`),
  KEY `OwnerID` (`OwnerID`),
  KEY `Avail_StatsID` (`Avail_StatsID`),
  KEY `FK_schedules_studios` (`StudioID`),
  CONSTRAINT `FK_schedules_studios` FOREIGN KEY (`StudioID`) REFERENCES `studios` (`StudioID`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`OwnerID`) REFERENCES `studio_owners` (`OwnerID`),
  CONSTRAINT `schedules_ibfk_2` FOREIGN KEY (`Avail_StatsID`) REFERENCES `avail_stats` (`Avail_StatsID`)
) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.schedules: ~87 rows (approximately)
INSERT INTO `schedules` (`ScheduleID`, `OwnerID`, `StudioID`, `Sched_Date`, `Time_Start`, `Time_End`, `Avail_StatsID`) VALUES
	(1, 1, 2, '2025-05-15', '09:00:00', '12:00:00', 1),
	(2, 4, 1, '2025-05-19', '12:00:00', '15:00:00', 3),
	(3, 1, 3, '2025-05-19', '12:00:00', '15:00:00', 1),
	(4, 2, 10, '2025-05-16', '10:00:00', '14:00:00', 1),
	(5, 2, 8, '2025-05-16', '14:00:00', '18:00:00', 3),
	(6, 1, 4, '2025-05-17', '09:30:00', '13:00:00', 1),
	(9, 4, 1, '2025-05-18', '09:00:00', '10:00:00', 2),
	(10, 4, 1, '2025-05-18', '20:00:00', '22:00:00', 2),
	(11, 4, 1, '2025-05-18', '17:00:00', '18:00:00', 2),
	(12, 4, 1, '2025-05-19', '16:00:00', '18:00:00', 2),
	(13, 3, 3, '2025-05-20', '08:00:00', '09:00:00', 2),
	(14, 3, 3, '2025-05-20', '09:00:00', '11:00:00', 2),
	(16, 3, 3, '2025-05-21', '08:00:00', '10:00:00', 3),
	(21, 4, 1, '2025-05-20', '12:00:00', '13:00:00', 2),
	(22, 4, 1, '2025-05-21', '09:00:00', '10:00:00', 2),
	(23, 4, 1, '2025-09-24', '09:00:00', '10:00:00', 2),
	(24, 4, 1, '2025-09-24', '10:00:00', '11:00:00', 2),
	(25, 4, 1, '2025-09-24', '11:00:00', '12:00:00', 2),
	(26, 4, 1, '2025-09-24', '12:00:00', '13:00:00', 2),
	(27, 4, 1, '2025-09-24', '14:00:00', '15:00:00', 2),
	(28, 4, 1, '2025-09-24', '15:00:00', '16:00:00', 2),
	(29, 4, 1, '2025-09-26', '09:00:00', '10:00:00', 2),
	(30, 4, 1, '2025-09-26', '10:00:00', '11:00:00', 2),
	(31, NULL, 1, '2025-09-23', '08:00:00', '09:00:00', 1),
	(32, NULL, 1, '2025-09-24', '08:00:00', '09:00:00', 1),
	(33, NULL, 1, '2025-09-26', '08:00:00', '09:00:00', 1),
	(34, NULL, 1, '2025-09-26', '08:00:00', '10:00:00', 1),
	(35, 4, 1, '2025-09-28', '16:00:00', '17:00:00', 2),
	(36, 4, 1, '2025-09-28', '17:00:00', '18:00:00', 1),
	(39, 4, 1, '2025-09-28', '09:00:00', '10:00:00', 1),
	(40, 4, 1, '2025-09-28', '10:00:00', '11:00:00', 2),
	(41, NULL, 1, '2025-09-28', '19:00:00', '20:00:00', 1),
	(42, NULL, 1, '2025-09-28', '20:00:00', '21:00:00', 1),
	(43, NULL, 1, '2025-09-28', '11:00:00', '12:00:00', 2),
	(44, NULL, 1, '2025-09-29', '09:00:00', '10:00:00', 1),
	(45, 4, 1, '2025-09-29', '10:00:00', '11:00:00', 1),
	(46, 4, 1, '2025-09-29', '11:00:00', '13:00:00', 1),
	(47, NULL, 1, '2025-09-30', '09:00:00', '10:00:00', 2),
	(48, 4, 1, '2025-09-29', '13:00:00', '16:00:00', 2),
	(49, 4, 1, '2025-09-29', '16:00:00', '17:00:00', 2),
	(50, 4, 1, '2025-10-06', '09:00:00', '10:00:00', 2),
	(51, NULL, 1, '2025-09-30', '08:00:00', '10:00:00', 1),
	(52, NULL, 1, '2025-10-06', '08:00:00', '10:00:00', 1),
	(53, NULL, 1, '2025-10-06', '09:00:00', '11:00:00', 1),
	(54, NULL, 1, '2025-10-06', '14:00:00', '15:00:00', 1),
	(55, NULL, 1, '2025-10-06', '08:00:00', '09:00:00', 1),
	(56, NULL, 1, '2025-10-05', '20:00:00', '21:00:00', 1),
	(57, NULL, 1, '2025-10-19', '10:00:00', '11:00:00', 1),
	(58, NULL, 3, '2025-10-20', '09:00:00', '10:00:00', 2),
	(59, 3, 3, '2025-10-21', '08:00:00', '19:00:00', 3),
	(60, 3, 8, '2025-10-21', '08:00:00', '20:00:00', 3),
	(62, 3, 3, '2025-10-22', '16:30:00', '18:30:00', 3),
	(63, 3, 3, '2025-10-22', '09:00:00', '10:00:00', 2),
	(64, 3, 3, '2025-10-22', '10:00:00', '11:00:00', 2),
	(65, 3, 3, '2025-10-23', '08:00:00', '09:00:00', 2),
	(66, 3, 3, '2025-10-23', '09:00:00', '10:00:00', 1),
	(67, 3, 3, '2025-10-25', '08:00:00', '09:00:00', 1),
	(68, 3, 3, '2025-10-25', '09:00:00', '10:00:00', 2),
	(69, NULL, 3, '2025-10-25', '10:00:00', '11:00:00', 2),
	(70, 4, 1, '2025-10-25', '09:00:00', '10:00:00', 2),
	(71, 4, 1, '2025-10-25', '10:00:00', '11:00:00', 1),
	(72, 3, 3, '2025-10-25', '11:00:00', '12:00:00', 2),
	(73, 3, 3, '2025-10-25', '14:00:00', '15:00:00', 2),
	(74, 4, 1, '2025-10-26', '09:00:00', '10:00:00', 2),
	(75, 3, 3, '2025-10-25', '12:00:00', '13:00:00', 2),
	(76, 3, 3, '2025-10-25', '15:00:00', '17:00:00', 2),
	(77, 4, 1, '2025-10-29', '09:00:00', '10:00:00', 2),
	(78, 4, 1, '2025-10-26', '10:00:00', '11:00:00', 2),
	(79, 4, 1, '2025-10-26', '11:00:00', '12:00:00', 2),
	(80, 4, 1, '2025-10-26', '11:00:00', '12:00:00', 2),
	(81, 4, 1, '2025-10-26', '11:00:00', '12:00:00', 2),
	(82, 4, 1, '2025-10-26', '11:00:00', '12:00:00', 2),
	(83, 4, 1, '2025-10-26', '11:00:00', '12:00:00', 2),
	(84, 4, 1, '2025-10-27', '09:00:00', '10:00:00', 2),
	(85, 4, 1, '2025-10-27', '10:00:00', '11:00:00', 2),
	(86, 9, 17, '2025-10-31', '10:00:00', '22:00:00', 4),
	(87, 9, 17, '2025-10-30', '10:00:00', '22:00:00', 3),
	(88, 9, 17, '2025-10-29', '20:00:00', '21:00:00', 2),
	(89, 9, 17, '2025-10-29', '10:00:00', '11:00:00', 2),
	(90, 3, 3, '2025-11-13', '09:00:00', '10:00:00', 2),
	(91, 3, 3, '2025-11-13', '10:00:00', '11:00:00', 1),
	(92, NULL, 3, '2025-11-13', '06:00:00', '07:00:00', 2),
	(93, 3, 3, '2025-11-12', '06:00:00', '07:00:00', 1),
	(94, 3, 3, '2025-11-12', '07:00:00', '08:00:00', 1),
	(95, 3, 3, '2025-11-13', '08:00:00', '09:00:00', 2),
	(96, 3, 3, '2025-11-14', '06:00:00', '07:00:00', 2),
	(97, 3, 3, '2025-11-14', '14:00:00', '17:00:00', 2),
	(98, 3, 3, '2025-11-15', '08:00:00', '12:00:00', 2);

-- Dumping structure for table museek.services
CREATE TABLE IF NOT EXISTS `services` (
  `ServiceID` int(11) NOT NULL AUTO_INCREMENT,
  `OwnerID` int(11) DEFAULT NULL,
  `ServiceType` varchar(255) NOT NULL,
  `Description` varchar(255) NOT NULL,
  `Price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`ServiceID`),
  KEY `FK_services_studio_owners` (`OwnerID`),
  CONSTRAINT `FK_services_studio_owners` FOREIGN KEY (`OwnerID`) REFERENCES `studio_owners` (`OwnerID`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.services: ~7 rows (approximately)
INSERT INTO `services` (`ServiceID`, `OwnerID`, `ServiceType`, `Description`, `Price`) VALUES
	(1, 4, 'Recording', 'Professional audio recording', 500.00),
	(2, 9, 'Mixing', 'Audio mixing services', 300.00),
	(3, 3, 'Mastering', 'Audio mastering services', 400.00),
	(4, 9, 'Voice Coaching', 'Professional voice training', 250.00),
	(5, 3, 'Video Editing', 'High-quality video editing', 600.00),
	(6, 3, 'Band Practice', 'Band Practice per hour. Gears include Guitar, Bass and Keyboard Amp and Drum Set with 2 Microphones.', 500.00),
	(7, 4, 'Band Practice', 'Band Rehearsal', 500.00);

-- Dumping structure for table museek.studios
CREATE TABLE IF NOT EXISTS `studios` (
  `StudioID` int(11) NOT NULL AUTO_INCREMENT,
  `ClientID` int(11) DEFAULT NULL,
  `OwnerID` int(11) DEFAULT NULL,
  `StudioName` varchar(255) NOT NULL,
  `Latitude` varchar(255) NOT NULL,
  `Longitude` varchar(255) NOT NULL,
  `Loc_Desc` varchar(255) NOT NULL,
  `Time_IN` time NOT NULL,
  `Time_OUT` time NOT NULL,
  `StudioImg` varchar(255) DEFAULT NULL,
  `approved_by_admin` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`StudioID`),
  KEY `ClientID` (`ClientID`),
  KEY `FK_studios_studio_owners` (`OwnerID`),
  KEY `fk_studio_approved_by` (`approved_by_admin`),
  KEY `idx_featured` (`is_active`,`is_featured`),
  CONSTRAINT `FK_studios_studio_owners` FOREIGN KEY (`OwnerID`) REFERENCES `studio_owners` (`OwnerID`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_studio_approved_by` FOREIGN KEY (`approved_by_admin`) REFERENCES `admin_users` (`admin_id`) ON DELETE SET NULL ON UPDATE NO ACTION,
  CONSTRAINT `studios_ibfk_1` FOREIGN KEY (`ClientID`) REFERENCES `clients` (`ClientID`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.studios: ~9 rows (approximately)
INSERT INTO `studios` (`StudioID`, `ClientID`, `OwnerID`, `StudioName`, `Latitude`, `Longitude`, `Loc_Desc`, `Time_IN`, `Time_OUT`, `StudioImg`, `approved_by_admin`, `approved_at`, `is_active`, `is_featured`) VALUES
	(1, NULL, 4, 'SkyTrack Band Rehearsals and Recording Studio', '10.630673', '122.978641', 'Gardenia St., Brgy. Mansilingan, Bacolod City, Negros Occidental', '09:00:00', '22:00:00', '/uploads/studios/1/profile_1762950972_09207f26ea48.jpg', 1, '2025-11-02 21:53:32', 1, 0),
	(2, NULL, 1, 'Musician\'s Garage Rehearsals, Studio Rentals & Sound Services', '10.633673979746234', '122.9543716656823', '054 Blue Jay St., Olympia Village, Brgy. Alijis, Bacolod City, Negros Occidental', '09:00:00', '21:00:00', NULL, 1, '2025-10-29 01:20:07', 1, 0),
	(3, NULL, 3, 'Mike Tambasen MusicLab and Productions', '10.680779', '122.961909', 'Based on coordinates near Bacolod City, Negros Occidental', '06:00:00', '18:00:00', '/uploads/studios/3/profile_1762946982_8e26c0179619.jpg', 1, '2025-11-10 22:52:03', 1, 0),
	(4, NULL, 9, 'JAMM\'S Studio', '10.660643843074272', '122.9693372314184', '1313 Bern St., Helvetia Heights Subdivision', '08:00:00', '20:00:00', NULL, 1, '2025-11-12 20:50:59', 1, 0),
	(8, NULL, 3, 'Harmony Sound Studio', '10.650674', '122.988642', 'Main St., Bacolod City, Negros Occidental', '08:00:00', '20:00:00', NULL, 1, '2025-11-13 10:49:58', 1, 0),
	(9, NULL, 2, 'Echo Wave Recording', '10.690675', '122.998643', 'Park Ave., Talisay City, Negros Occidental', '10:00:00', '18:00:00', NULL, NULL, NULL, 1, 0),
	(10, NULL, 4, 'BeatBox Studio', '10.650353018468', '122.9366693698', 'Ocean Dr., Bacolod City, Negros Occidental', '09:00:00', '21:00:00', NULL, 1, '2025-10-28 10:10:41', 1, 0),
	(17, NULL, 9, 'JertPercz Audio', '10.679091', '122.965197', 'La Salleville, Villamonte, Bacolod-1, Bacolod, Negros Island Region, 6100, Philippines', '10:00:00', '22:00:00', NULL, 1, '2025-10-29 01:49:48', 1, 0),
	(20, NULL, 3, 'Aries Studio', '10.429704', '122.918566', 'Maribel Tyangson Gan Subdivision, La Carlota, Negros Occidental, Negros Island Region, 6130, Philippines', '07:00:00', '19:00:00', NULL, NULL, NULL, 1, 0),
	(23, NULL, 13, 'Pops Studios', '10.718413', '122.965197', 'Zone 15, Talisay, Negros Occidental, Negros Island Region, 6115, Philippines', '00:00:00', '00:00:00', NULL, NULL, NULL, 1, 0);

-- Dumping structure for table museek.studio_gallery
CREATE TABLE IF NOT EXISTS `studio_gallery` (
  `image_id` int(11) NOT NULL AUTO_INCREMENT,
  `StudioID` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`image_id`),
  KEY `idx_studio` (`StudioID`,`sort_order`),
  CONSTRAINT `fk_gallery_studio` FOREIGN KEY (`StudioID`) REFERENCES `studios` (`StudioID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.studio_gallery: ~9 rows (approximately)
INSERT INTO `studio_gallery` (`image_id`, `StudioID`, `file_path`, `caption`, `sort_order`, `uploaded_at`) VALUES
	(1, 17, 'uploads/studios/17/gallery/1761680272_0d5c2c9684af.png', NULL, 1, '2025-10-28 19:37:52'),
	(2, 17, 'uploads/studios/17/gallery/1761680468_694cfdc231ad.png', NULL, 2, '2025-10-28 19:41:08'),
	(3, 17, 'uploads/studios/17/gallery/1761680480_f853f01aaec0.png', NULL, 3, '2025-10-28 19:41:20'),
	(4, 17, 'uploads/studios/17/gallery/1761680485_f0d2a7e34975.png', NULL, 4, '2025-10-28 19:41:25'),
	(5, 1, 'uploads/studios/1/gallery/1762612353_d46b61b43474.png', 'VICTORY!!!', 1, '2025-11-08 14:32:33'),
	(11, 1, 'uploads/studios/1/gallery/1762612391_8a6054dbd3fa.png', 'Trying Something new', 2, '2025-11-08 14:33:11'),
	(12, 1, 'uploads/studios/1/gallery/1762612391_d9750c9dbe4f.png', NULL, 3, '2025-11-08 14:33:11'),
	(13, 1, 'uploads/studios/1/gallery/1762612391_c0ef2db27f63.png', NULL, 4, '2025-11-08 14:33:11'),
	(14, 1, 'uploads/studios/1/gallery/1762612391_eb84a7993f13.png', NULL, 5, '2025-11-08 14:33:11');

-- Dumping structure for table museek.studio_instructors
CREATE TABLE IF NOT EXISTS `studio_instructors` (
  `StudioID` int(11) NOT NULL,
  `InstructorID` int(11) NOT NULL,
  PRIMARY KEY (`StudioID`,`InstructorID`),
  KEY `idx_si_instructor` (`InstructorID`),
  KEY `idx_si_studio` (`StudioID`),
  CONSTRAINT `fk_si_instructor` FOREIGN KEY (`InstructorID`) REFERENCES `instructors` (`InstructorID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_si_studio` FOREIGN KEY (`StudioID`) REFERENCES `studios` (`StudioID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.studio_instructors: ~4 rows (approximately)
INSERT INTO `studio_instructors` (`StudioID`, `InstructorID`) VALUES
	(3, 3),
	(3, 11),
	(8, 3),
	(8, 11);

-- Dumping structure for table museek.studio_owners
CREATE TABLE IF NOT EXISTS `studio_owners` (
  `OwnerID` int(11) NOT NULL AUTO_INCREMENT,
  `Name` varchar(255) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `Phone` varchar(255) NOT NULL,
  `Password` varchar(255) NOT NULL,
  `V_StatsID` int(11) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `approved_by_admin` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `subscription_status` enum('active','suspended','expired','cancelled') NOT NULL DEFAULT 'active',
  `subscription_plan_id` int(11) DEFAULT NULL,
  `subscription_start` datetime DEFAULT NULL,
  `subscription_end` datetime DEFAULT NULL,
  PRIMARY KEY (`OwnerID`),
  UNIQUE KEY `uq_studio_owners_ownerid` (`OwnerID`),
  KEY `V_StatsID` (`V_StatsID`),
  KEY `fk_owner_approved_by` (`approved_by_admin`),
  KEY `fk_owner_subscription_plan` (`subscription_plan_id`),
  CONSTRAINT `fk_owner_approved_by` FOREIGN KEY (`approved_by_admin`) REFERENCES `admin_users` (`admin_id`) ON DELETE SET NULL ON UPDATE NO ACTION,
  CONSTRAINT `fk_owner_subscription_plan` FOREIGN KEY (`subscription_plan_id`) REFERENCES `subscription_plans` (`plan_id`) ON DELETE SET NULL ON UPDATE NO ACTION,
  CONSTRAINT `studio_owners_ibfk_1` FOREIGN KEY (`V_StatsID`) REFERENCES `verify_stats` (`V_StatsID`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.studio_owners: ~6 rows (approximately)
INSERT INTO `studio_owners` (`OwnerID`, `Name`, `Email`, `Phone`, `Password`, `V_StatsID`, `last_login`, `approved_by_admin`, `approved_at`, `subscription_status`, `subscription_plan_id`, `subscription_start`, `subscription_end`) VALUES
	(1, 'Alex Rivera', 'alex@studiomanager.com', '09123456791', 'ownerpass', 2, '2025-10-28 05:23:50', 1, '0000-00-00 00:00:00', 'active', 1, '2025-10-28 05:24:23', '2025-12-28 05:24:28'),
	(2, 'Maria Gonzalez', 'maria@studiomanager.com', '09123456792', 'ownerpass2', 2, '2025-10-28 05:23:51', 1, '0000-00-00 00:00:00', 'active', 1, '2025-10-28 05:24:24', '2025-12-28 05:24:30'),
	(3, 'Mike Tambasen', 'mike@gmail.com', '09123456789', 'admin3', 2, '2025-11-13 17:57:08', 1, '0000-00-00 00:00:00', 'active', 1, '2025-10-28 05:24:25', '2025-12-18 05:24:30'),
	(4, 'Jojo', 'zenon.draft@gmail.com', '0912345678910', 'admin4', 2, '2025-10-28 05:23:52', 1, '0000-00-00 00:00:00', 'active', 1, '2025-10-28 05:24:26', '2025-12-28 05:24:31'),
	(9, 'Kyzzer Lanz R. Jallorina', 'zenon.draft@gmail.com', '09508199489', 'password', 2, '2025-11-13 08:29:23', 1, '2025-10-28 17:49:48', 'active', 1, '2025-10-29 00:00:00', '2025-11-29 00:00:00'),
	(10, 'Jert Jallorina', 'kl.jallorina@gmail.com', '09105764655', '$2y$10$trWaNBasjYsyIW8lQTnwoejHa0tb.YyzTDn2eXeiclKhN70cHQW7G', 1, NULL, NULL, NULL, 'active', NULL, NULL, NULL),
	(13, 'Kirk Lanz De La Cruz', 'ibdelafuente.chmsu@gmail.com', '+63 950 819 9489', '$2y$10$5FIH8dwStSvBcjtpSnFNhOYXIMxxajrZYn/891/a49O4kd7uZX57.', 1, NULL, NULL, NULL, 'active', NULL, NULL, NULL);

-- Dumping structure for table museek.studio_registrations
CREATE TABLE IF NOT EXISTS `studio_registrations` (
  `registration_id` int(11) NOT NULL AUTO_INCREMENT,
  `studio_id` int(11) DEFAULT NULL,
  `business_name` varchar(255) NOT NULL,
  `owner_name` varchar(255) NOT NULL,
  `owner_email` varchar(255) NOT NULL,
  `owner_phone` varchar(20) NOT NULL,
  `business_address` text NOT NULL,
  `business_type` enum('recording_studio','rehearsal_space','production_house','multi_purpose') NOT NULL,
  `plan_id` int(11) NOT NULL,
  `subscription_duration` enum('monthly','yearly') NOT NULL,
  `registration_status` enum('pending','approved_pending_payment','approved','rejected','requires_info','payment_expired') DEFAULT 'pending',
  `payment_status` enum('pending','completed','failed','refunded') DEFAULT 'pending',
  `payment_token` varchar(64) DEFAULT NULL,
  `payment_token_expires` datetime DEFAULT NULL,
  `payment_method_used` varchar(50) DEFAULT NULL,
  `payment_transaction_id` varchar(255) DEFAULT NULL,
  `payment_completed_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`registration_id`),
  UNIQUE KEY `payment_token` (`payment_token`),
  KEY `owner_email` (`owner_email`),
  KEY `registration_status` (`registration_status`),
  KEY `payment_status` (`payment_status`),
  KEY `fk_approved_by` (`approved_by`),
  KEY `fk_plan_id` (`plan_id`),
  KEY `fk_studio_id` (`studio_id`),
  KEY `idx_payment_token` (`payment_token`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_payment_token_expires` (`payment_token_expires`),
  CONSTRAINT `fk_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `admin_users` (`admin_id`),
  CONSTRAINT `fk_plan_id` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`plan_id`),
  CONSTRAINT `fk_studio_id` FOREIGN KEY (`studio_id`) REFERENCES `studios` (`StudioID`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Studio registration applications';

-- Dumping data for table museek.studio_registrations: ~11 rows (approximately)
INSERT INTO `studio_registrations` (`registration_id`, `studio_id`, `business_name`, `owner_name`, `owner_email`, `owner_phone`, `business_address`, `business_type`, `plan_id`, `subscription_duration`, `registration_status`, `payment_status`, `payment_token`, `payment_token_expires`, `payment_method_used`, `payment_transaction_id`, `payment_completed_at`, `admin_notes`, `rejection_reason`, `reviewed_by`, `reviewed_at`, `approved_by`, `approved_at`, `submitted_at`, `updated_at`) VALUES
	(1, NULL, 'Test Studio (Approval)', 'Patricia Flores', 'patricia.flores@email.com', '09201115555', 'Bacolod City, Negros Occidental', 'recording_studio', 1, 'monthly', 'approved', 'pending', NULL, NULL, NULL, NULL, NULL, '', NULL, NULL, NULL, 1, '2025-10-29 14:38:08', '2025-10-25 07:20:18', '2025-10-29 06:38:08'),
	(2, NULL, 'JertPercz Audio', 'Kyzzer Lanz R. Jallorina', 'zenon.draft@gmail.com', '09508199489', 'Alijis St., Bacolod City, Negros Occidental', 'recording_studio', 1, 'monthly', 'rejected', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, 'asdasd', NULL, '2025-10-29 14:28:50', 1, '2025-10-28 10:15:38', '2025-10-28 02:01:41', '2025-10-29 06:28:51'),
	(3, NULL, 'JertPercz Audio', 'Kyzzer Lanz R. Jallorina', 'zenon.draft@gmail.com', '09508199489', 'Alijis St., Bacolod City, Negros Occidental', 'recording_studio', 1, 'monthly', 'rejected', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, 'Big weight', NULL, NULL, 1, '2025-10-28 13:49:50', '2025-10-28 02:37:17', '2025-10-28 05:49:50'),
	(4, NULL, 'JertPercz Audio', 'Kyzzer Lanz R. Jallorina', 'zenon.draft@gmail.com', '09508199489', 'Alijis St., Bacolod City, Negros Occidental', 'recording_studio', 1, 'monthly', 'rejected', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, 'Big weight', NULL, NULL, 1, '2025-10-28 13:49:50', '2025-10-28 05:41:14', '2025-10-28 05:49:50'),
	(5, NULL, 'JertPercz Audio', 'Kyzzer Lanz R. Jallorina', 'zenon.draft@gmail.com', '09508199489', 'Alijis St., Bacolod City, Negros Occidental', 'recording_studio', 1, 'monthly', 'rejected', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, 'Late', NULL, NULL, 1, '2025-10-28 16:03:30', '2025-10-28 05:49:19', '2025-10-28 08:03:30'),
	(6, NULL, 'JertPercz Audio', 'Kyzzer Lanz R. Jallorina', 'zenon.draft@gmail.com', '09508199489', 'Burgos Extension, Purok 7, Villamonte, Bacolod-1, Bacolod, Negros Island Region, 6100, Philippines', 'recording_studio', 1, 'monthly', 'approved', 'pending', NULL, NULL, NULL, NULL, NULL, 'Bulk approved', NULL, NULL, NULL, 1, '2025-10-29 01:14:11', '2025-10-28 07:45:25', '2025-10-28 17:14:11'),
	(8, 17, 'JertPercz Audio', 'Kyzzer Lanz R. Jallorina', 'zenon.draft@gmail.com', '09508199489', 'La Salleville, Villamonte, Bacolod-1, Bacolod, Negros Island Region, 6100, Philippines', 'recording_studio', 1, 'monthly', 'approved', 'completed', NULL, NULL, NULL, NULL, NULL, 'Bulk approved', NULL, NULL, '2025-10-29 14:28:52', 1, '2025-10-29 01:53:44', '2025-10-28 17:27:25', '2025-11-10 15:03:49'),
	(11, 3, 'Mike Tambasen MusicLab and Productions', 'Mike Tambasen', 'kyzzer.jallorina@gmail.com', '09123456789', 'Based on coordinates near Bacolod City, Negros Occidental', 'recording_studio', 1, 'monthly', 'approved', 'pending', NULL, NULL, NULL, NULL, NULL, '', NULL, NULL, NULL, 1, '2025-11-13 14:44:47', '2025-11-10 15:21:21', '2025-11-13 06:44:47'),
	(12, 20, 'Aries Studio', 'Mike Tambasen', 'kyzzer.jallorina@gmail.com', '09123456789', 'Maribel Tyangson Gan Subdivision, La Carlota, Negros Occidental, Negros Island Region, 6130, Philippines', 'recording_studio', 1, 'monthly', 'pending', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-11-10 16:36:35', '2025-11-10 16:36:35'),
	(13, NULL, 'Nazreen Studio', 'Nazreen', 'ibdelafuente.chmsu@gmail.com', '09666666666666', 'Barangay IV, Bagtic, Silay, Negros Occidental, Negros Island Region, 6116, Philippines', 'recording_studio', 2, 'yearly', 'approved', 'pending', NULL, NULL, NULL, NULL, NULL, '', NULL, NULL, NULL, 1, '2025-11-13 18:27:35', '2025-11-13 10:19:13', '2025-11-13 10:27:35'),
	(15, NULL, 'Pops Studios', 'Kirk Lanz De La Cruz', 'ibdelafuente.chmsu@gmail.com', '+63 950 819 9489', 'Zone 15, Talisay, Negros Occidental, Negros Island Region, 6115, Philippines', 'recording_studio', 1, 'monthly', 'pending', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-11-17 12:51:38', '2025-11-17 12:51:38');

-- Dumping structure for table museek.studio_services
CREATE TABLE IF NOT EXISTS `studio_services` (
  `StudioServiceID` int(11) NOT NULL AUTO_INCREMENT,
  `StudioID` int(11) NOT NULL,
  `ServiceID` int(11) NOT NULL,
  PRIMARY KEY (`StudioServiceID`),
  KEY `StudioID` (`StudioID`),
  KEY `ServiceID` (`ServiceID`),
  CONSTRAINT `fk_ss_service` FOREIGN KEY (`ServiceID`) REFERENCES `services` (`ServiceID`) ON DELETE CASCADE,
  CONSTRAINT `fk_ss_studio` FOREIGN KEY (`StudioID`) REFERENCES `studios` (`StudioID`) ON DELETE CASCADE,
  CONSTRAINT `fk_studio_services_service` FOREIGN KEY (`ServiceID`) REFERENCES `services` (`ServiceID`) ON DELETE CASCADE,
  CONSTRAINT `fk_studio_services_studio` FOREIGN KEY (`StudioID`) REFERENCES `studios` (`StudioID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.studio_services: ~7 rows (approximately)
INSERT INTO `studio_services` (`StudioServiceID`, `StudioID`, `ServiceID`) VALUES
	(17, 17, 7),
	(35, 3, 3),
	(36, 3, 5),
	(37, 3, 6),
	(38, 1, 1),
	(48, 8, 6),
	(49, 8, 3);

-- Dumping structure for table museek.subscription_plans
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `plan_id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `monthly_price` decimal(10,2) NOT NULL,
  `yearly_price` decimal(10,2) NOT NULL,
  `max_studios` int(11) NOT NULL DEFAULT 1,
  `max_instructors` int(11) NOT NULL DEFAULT 5,
  `max_services` int(11) DEFAULT NULL,
  `features` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`plan_id`),
  UNIQUE KEY `plan_name` (`plan_name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Subscription plan definitions';

-- Dumping data for table museek.subscription_plans: ~2 rows (approximately)
INSERT INTO `subscription_plans` (`plan_id`, `plan_name`, `description`, `monthly_price`, `yearly_price`, `max_studios`, `max_instructors`, `max_services`, `features`, `is_active`, `created_at`, `updated_at`) VALUES
	(1, 'Starter', 'Perfect for single studio owners', 499.00, 4990.00, 5, 10, 20, 'Basic booking management, Client management, Payment tracking, Monthly reports', 1, '2025-10-25 00:54:04', '2025-11-12 13:29:23'),
	(2, 'Professional', 'For growing studio businesses', 999.00, 9990.00, 500, 500, 500, 'Multiple studios, Advanced analytics, Priority support, Instructor management, Custom branding', 1, '2025-10-25 00:54:04', '2025-11-12 13:29:52');

-- Dumping structure for table museek.verify_stats
CREATE TABLE IF NOT EXISTS `verify_stats` (
  `V_StatsID` int(11) NOT NULL AUTO_INCREMENT,
  `V_Status` varchar(255) NOT NULL,
  PRIMARY KEY (`V_StatsID`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.verify_stats: ~2 rows (approximately)
INSERT INTO `verify_stats` (`V_StatsID`, `V_Status`) VALUES
	(1, 'Pending'),
	(2, 'Confirmed'),
	(3, 'Inactive');

-- Dumping structure for view museek.v_admin_workflow_stats
-- Creating temporary table to overcome VIEW dependency errors
CREATE TABLE `v_admin_workflow_stats` (
	`pending_registrations` BIGINT(21) NOT NULL,
	`requires_info` BIGINT(21) NOT NULL,
	`approved_today` BIGINT(21) NOT NULL,
	`rejected_today` BIGINT(21) NOT NULL,
	`pending_payments` BIGINT(21) NOT NULL,
	`payments_today` BIGINT(21) NOT NULL,
	`pending_document_verifications` BIGINT(21) NULL,
	`documents_verified_today` BIGINT(21) NULL,
	`avg_approval_time_hours` DECIMAL(24,4) NULL
);

-- Dumping structure for table museek.v_pending_registrations
CREATE TABLE IF NOT EXISTS `v_pending_registrations` (
  `registration_id` int(11) NOT NULL,
  `business_name` varchar(1) NOT NULL,
  `owner_name` varchar(1) NOT NULL,
  `owner_email` varchar(1) NOT NULL,
  `owner_phone` varchar(1) NOT NULL,
  `business_type` enum('recording_studio','rehearsal_space','production_house','multi_purpose') NOT NULL,
  `plan_name` varchar(1) DEFAULT NULL,
  `subscription_duration` enum('monthly','yearly') NOT NULL,
  `registration_status` enum('pending','approved','rejected','requires_info') DEFAULT NULL,
  `payment_status` enum('pending','completed','failed','refunded') DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `total_documents` bigint(21) NOT NULL,
  `verified_documents` decimal(22,0) DEFAULT NULL,
  `pending_documents` decimal(22,0) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.v_pending_registrations: 0 rows
/*!40000 ALTER TABLE `v_pending_registrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `v_pending_registrations` ENABLE KEYS */;

-- Dumping structure for table museek.v_recent_admin_activity
CREATE TABLE IF NOT EXISTS `v_recent_admin_activity` (
  `log_id` int(11) NOT NULL,
  `action` varchar(1) NOT NULL,
  `entity_type` varchar(1) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `admin_username` varchar(1) DEFAULT NULL,
  `admin_full_name` varchar(1) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.v_recent_admin_activity: 0 rows
/*!40000 ALTER TABLE `v_recent_admin_activity` DISABLE KEYS */;
/*!40000 ALTER TABLE `v_recent_admin_activity` ENABLE KEYS */;

-- Dumping structure for table museek.v_registration_workflow
CREATE TABLE IF NOT EXISTS `v_registration_workflow` (
  `registration_id` int(11) NOT NULL,
  `business_name` varchar(1) NOT NULL,
  `owner_name` varchar(1) NOT NULL,
  `owner_email` varchar(1) NOT NULL,
  `business_type` enum('recording_studio','rehearsal_space','production_house','multi_purpose') NOT NULL,
  `plan_name` varchar(1) DEFAULT NULL,
  `subscription_duration` enum('monthly','yearly') NOT NULL,
  `subscription_amount` decimal(10,2) DEFAULT NULL,
  `registration_status` enum('pending','approved','rejected','requires_info') DEFAULT NULL,
  `payment_status` enum('pending','completed','failed','refunded') DEFAULT NULL,
  `total_documents` bigint(21) NOT NULL,
  `verified_documents` decimal(22,0) DEFAULT NULL,
  `rejected_documents` decimal(22,0) DEFAULT NULL,
  `pending_documents` decimal(22,0) DEFAULT NULL,
  `workflow_status` varchar(1) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `approved_by_username` varchar(1) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.v_registration_workflow: 0 rows
/*!40000 ALTER TABLE `v_registration_workflow` DISABLE KEYS */;
/*!40000 ALTER TABLE `v_registration_workflow` ENABLE KEYS */;

-- Dumping structure for table museek.v_studio_stats
CREATE TABLE IF NOT EXISTS `v_studio_stats` (
  `OwnerID` int(11) NOT NULL,
  `owner_name` varchar(1) NOT NULL,
  `owner_email` varchar(1) NOT NULL,
  `total_studios` bigint(21) NOT NULL,
  `total_instructors` bigint(21) NOT NULL,
  `total_bookings` bigint(21) NOT NULL,
  `total_revenue` decimal(32,2) DEFAULT NULL,
  `subscription_status` enum('active','suspended','expired','cancelled') DEFAULT NULL,
  `subscription_plan` varchar(1) DEFAULT NULL,
  `subscription_end` date DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.v_studio_stats: 0 rows
/*!40000 ALTER TABLE `v_studio_stats` DISABLE KEYS */;
/*!40000 ALTER TABLE `v_studio_stats` ENABLE KEYS */;

-- Dumping structure for table museek.v_system_overview
CREATE TABLE IF NOT EXISTS `v_system_overview` (
  `active_studio_owners` bigint(21) DEFAULT NULL,
  `suspended_studio_owners` bigint(21) DEFAULT NULL,
  `active_studios` bigint(21) DEFAULT NULL,
  `total_instructors` bigint(21) DEFAULT NULL,
  `total_clients` bigint(21) DEFAULT NULL,
  `bookings_last_30_days` bigint(21) DEFAULT NULL,
  `revenue_last_30_days` decimal(32,2) DEFAULT NULL,
  `pending_registrations` bigint(21) DEFAULT NULL,
  `pending_document_verifications` bigint(21) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dumping data for table museek.v_system_overview: 0 rows
/*!40000 ALTER TABLE `v_system_overview` DISABLE KEYS */;
/*!40000 ALTER TABLE `v_system_overview` ENABLE KEYS */;

-- Removing temporary table and create final VIEW structure
DROP TABLE IF EXISTS `v_admin_workflow_stats`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_admin_workflow_stats` AS SELECT 
    COUNT(CASE WHEN registration_status = 'pending' THEN 1 END) AS pending_registrations,
    COUNT(CASE WHEN registration_status = 'requires_info' THEN 1 END) AS requires_info,
    COUNT(CASE WHEN registration_status = 'approved' AND DATE(approved_at) = CURDATE() THEN 1 END) AS approved_today,
    COUNT(CASE WHEN registration_status = 'rejected' AND DATE(updated_at) = CURDATE() THEN 1 END) AS rejected_today,
    COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) AS pending_payments,
    COUNT(CASE WHEN payment_status = 'completed' AND DATE(updated_at) = CURDATE() THEN 1 END) AS payments_today,
    (SELECT COUNT(*) FROM documents WHERE verification_status = 'pending') AS pending_document_verifications,
    (SELECT COUNT(*) FROM documents WHERE verification_status = 'verified' AND DATE(verified_at) = CURDATE()) AS documents_verified_today,
    AVG(TIMESTAMPDIFF(HOUR, submitted_at, approved_at)) AS avg_approval_time_hours
FROM studio_registrations
WHERE submitted_at >= CURDATE() - INTERVAL 30 DAY 
;

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
