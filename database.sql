-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 23, 2026 at 06:15 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pcu_rfid2`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL COMMENT 'register_card, unregister_card, clear_violation, etc.',
  `table_name` varchar(50) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL COMMENT 'JSON of old values',
  `new_values` text DEFAULT NULL COMMENT 'JSON of new values',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `admin_name` varchar(255) NOT NULL,
  `action_type` varchar(100) NOT NULL,
  `target_type` varchar(50) NOT NULL,
  `target_id` int(11) DEFAULT NULL,
  `target_name` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `admin_id`, `admin_name`, `action_type`, `target_type`, `target_id`, `target_name`, `description`, `details`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 3, 'System Administrator', 'APPROVE_STUDENT', 'student', 4, 'Jason Ramos', 'Approved student account for Jason Ramos (ID: TEMP-1770390474)', '{\"student_id\":\"TEMP-1770390474\",\"email\":\"mrk.ramos118@gmail.com\",\"previous_status\":\"Pending\",\"new_status\":\"Active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-06 15:14:55'),
(2, 3, 'System Administrator', 'APPROVE_STUDENT', 'student', 5, 'Joshua Morales', 'Approved student account for Joshua Morales (ID: TEMP-1770538459)', '{\"student_id\":\"TEMP-1770538459\",\"email\":\"morales.josh133@gmail.com\",\"previous_status\":\"Pending\",\"new_status\":\"Active\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-08 08:15:28'),
(3, 3, 'System Administrator', 'MARK_LOST', 'rfid_card', 1, 'halimaw mag pa baby', 'Marked RFID card 0014973874 as lost for halimaw mag pa baby', '{\"rfid_uid\":\"0014973874\",\"card_id\":1,\"student_id\":2,\"email_sent\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-21 05:42:11'),
(4, 3, 'System Administrator', 'MARK_FOUND', 'rfid_card', 1, 'halimaw mag pa baby', 'Re-enabled RFID card 0014973874 for halimaw mag pa baby ()', '{\"rfid_uid\":\"0014973874\",\"card_id\":1,\"student_id\":\"\",\"email_sent\":true}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-21 05:51:59'),
(5, 3, 'System Administrator', 'UPDATE_STUDENT', 'student', 2, 'Mark Jason Briones Ramos', 'Updated student info for Mark Jason Briones Ramos (202232903)', '{\"changes\":{\"name\":{\"from\":\"halimaw mag pa baby\",\"to\":\"Mark Jason Briones Ramos\"}},\"student_id\":\"202232903\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-21 05:53:50');

-- --------------------------------------------------------

--
-- Table structure for table `auth_audit_log`
--

CREATE TABLE `auth_audit_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `provider_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `action` enum('login_success','login_failed','logout','signup','link_account') NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `auth_providers`
--

CREATE TABLE `auth_providers` (
  `id` int(11) NOT NULL,
  `provider_name` varchar(50) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `auth_providers`
--

INSERT INTO `auth_providers` (`id`, `provider_name`, `is_enabled`, `is_primary`, `created_at`, `updated_at`) VALUES
(1, 'google', 1, 1, '2026-02-18 02:57:05', '2026-02-18 02:57:05'),
(2, 'manual', 0, 0, '2026-02-18 02:57:05', '2026-02-18 02:57:05');

-- --------------------------------------------------------

--
-- Table structure for table `face_descriptors`
--

CREATE TABLE `face_descriptors` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `descriptor_data` text NOT NULL,
  `descriptor_iv` varchar(48) NOT NULL,
  `descriptor_tag` varchar(48) NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `quality_score` float DEFAULT NULL,
  `registered_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `face_descriptors`
--

INSERT INTO `face_descriptors` (`id`, `user_id`, `descriptor_data`, `descriptor_iv`, `descriptor_tag`, `label`, `quality_score`, `registered_by`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 2, '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', '10ernbZfb79+TYsz', 'K0Y580sziSGTIAxKyzfeaw==', 'front', 0.999718, 3, 1, '2026-02-07 00:21:29', '2026-02-07 00:21:29'),
(2, 2, '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', 'NJQIflh321IYaA7x', 'MKzYq1oY1spB8Zd1Wgn9rw==', 'left', 0.972595, 3, 1, '2026-02-07 00:21:39', '2026-02-07 00:21:39'),
(3, 2, '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', '5ITmS0tV1oC0TnQC', 'VcFlVTvuCYBFgrtEqnmMcA==', 'right', 0.979081, 3, 1, '2026-02-07 00:21:46', '2026-02-07 00:21:46'),
(4, 2, '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', 'nwxf4PLMQsbWA3S6', 'QvTOt3FxIDfdXd2s++KF1A==', 'down', 0.999163, 3, 1, '2026-02-07 00:21:56', '2026-02-07 00:21:56'),
(5, 5, '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', 'G7kGWDC6ruQKHqF3', 'D5Jhe81sajLBdesSWVKXAg==', 'front', 0.997056, 3, 1, '2026-02-08 08:17:25', '2026-02-08 08:17:25');

-- --------------------------------------------------------

--
-- Table structure for table `face_entry_logs`
--

CREATE TABLE `face_entry_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `confidence_score` float NOT NULL,
  `match_threshold` float NOT NULL,
  `gate_location` varchar(100) DEFAULT NULL,
  `security_guard_id` int(11) DEFAULT NULL,
  `entry_type` enum('face_match','face_violation','face_denied') NOT NULL DEFAULT 'face_match',
  `snapshot_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `face_entry_logs`
--

INSERT INTO `face_entry_logs` (`id`, `user_id`, `confidence_score`, `match_threshold`, `gate_location`, `security_guard_id`, `entry_type`, `snapshot_path`, `created_at`) VALUES
(1, 2, 0.6926, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-07 00:23:34'),
(2, 2, 0.7855, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-07 00:23:39'),
(3, 2, 0.7137, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-07 00:29:05'),
(4, 2, 0.712, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-07 00:29:10'),
(5, 2, 0.7186, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-07 00:29:15'),
(6, 2, 0.7145, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-07 00:37:05'),
(7, 2, 0.446, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-07 00:37:24'),
(8, 2, 0.6657, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-07 01:07:25'),
(9, 2, 0.6714, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-07 01:07:41'),
(10, 2, 0.7506, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-07 13:29:09'),
(11, 2, 0.6248, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-07 13:29:33'),
(12, 2, 0.6905, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-07 13:29:56'),
(13, 2, 0.6286, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-08 08:10:52'),
(14, 5, 0.6576, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-08 08:19:34'),
(15, 2, 0.5947, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-20 15:31:31'),
(16, 2, 0.7213, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:31:41'),
(17, 2, 0.6825, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:31:47'),
(18, 2, 0.6787, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:31:52'),
(19, 2, 0.5234, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:31:58'),
(20, 2, 0.6639, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:39:16'),
(21, 2, 0.5949, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:39:21'),
(22, 2, 0.6555, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:39:26'),
(23, 2, 0.6826, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:51:28'),
(24, 2, 0.6743, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:51:54'),
(25, 2, 0.7155, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:52:13'),
(26, 2, 0.6808, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:52:18'),
(27, 2, 0.6581, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:52:24'),
(28, 2, 0.6985, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:53:39'),
(29, 2, 0.6941, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:53:50'),
(30, 2, 0.6232, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:57:12'),
(31, 2, 0.6243, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:57:20'),
(32, 2, 0.6312, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:57:31'),
(33, 2, 0.6677, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 15:57:39'),
(34, 2, 0.7414, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 16:00:37'),
(35, 2, 0.6797, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 16:00:44'),
(36, 2, 0.5987, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 16:00:49'),
(37, 2, 0.6284, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 16:00:54'),
(38, 2, 0.6344, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 16:01:04'),
(39, 2, 0.6286, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 16:01:12'),
(40, 2, 0.6428, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 16:09:14'),
(41, 2, 0.5503, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-20 16:09:25'),
(42, 2, 0.519, 0.6, NULL, NULL, 'face_violation', NULL, '2026-02-21 05:57:32'),
(43, 2, 0.6587, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-21 05:57:38'),
(44, 2, 0.6462, 0.6, NULL, NULL, 'face_denied', NULL, '2026-02-21 05:57:44');

-- --------------------------------------------------------

--
-- Table structure for table `face_registration_log`
--

CREATE TABLE `face_registration_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` enum('registered','deactivated','reactivated','deleted') NOT NULL,
  `descriptor_count` int(11) DEFAULT 0,
  `performed_by` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `face_registration_log`
--

INSERT INTO `face_registration_log` (`id`, `user_id`, `action`, `descriptor_count`, `performed_by`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 2, 'registered', 1, 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-07 00:21:29'),
(2, 2, 'registered', 1, 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-07 00:21:39'),
(3, 2, 'registered', 1, 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-07 00:21:46'),
(4, 2, 'registered', 1, 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-07 00:21:56'),
(5, 5, 'registered', 1, 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', '2026-02-08 08:17:25');

-- --------------------------------------------------------

--
-- Table structure for table `guardians`
--

CREATE TABLE `guardians` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `relationship` enum('Mother','Father','Guardian','Other') NOT NULL DEFAULT 'Guardian',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_logs`
--

CREATE TABLE `notification_logs` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `guardian_id` int(11) NOT NULL,
  `notification_type` enum('entry','exit','violation','daily_summary') NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('sent','failed','queued') NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_queue`
--

CREATE TABLE `notification_queue` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `guardian_id` int(11) NOT NULL,
  `notification_type` enum('entry','exit','violation','daily_summary') NOT NULL,
  `scheduled_for` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `retry_count` int(11) NOT NULL DEFAULT 0,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_settings`
--

CREATE TABLE `notification_settings` (
  `id` int(11) NOT NULL,
  `guardian_id` int(11) NOT NULL,
  `entry_notification` tinyint(1) NOT NULL DEFAULT 1,
  `exit_notification` tinyint(1) NOT NULL DEFAULT 0,
  `violation_notification` tinyint(1) NOT NULL DEFAULT 1,
  `daily_summary` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qr_entry_logs`
--

CREATE TABLE `qr_entry_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `entry_type` enum('QR_CODE','RFID') DEFAULT 'QR_CODE',
  `scanned_at` datetime NOT NULL,
  `security_guard` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rfid_cards`
--

CREATE TABLE `rfid_cards` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rfid_uid` varchar(50) NOT NULL,
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `unregistered_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `registered_by` int(11) DEFAULT NULL COMMENT 'Admin who registered the card',
  `unregistered_by` int(11) DEFAULT NULL COMMENT 'Admin who unregistered the card',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_lost` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether RFID is marked as lost',
  `lost_at` datetime DEFAULT NULL COMMENT 'When RFID was marked as lost',
  `lost_reason` varchar(255) DEFAULT NULL COMMENT 'Reason for marking as lost',
  `lost_reported_by` int(11) DEFAULT NULL COMMENT 'Admin who marked it as lost',
  `found_at` datetime DEFAULT NULL COMMENT 'When RFID was found/unmarked',
  `found_by` int(11) DEFAULT NULL COMMENT 'Admin who unmarked it'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rfid_cards`
--

INSERT INTO `rfid_cards` (`id`, `user_id`, `rfid_uid`, `registered_at`, `unregistered_at`, `is_active`, `registered_by`, `unregistered_by`, `notes`, `created_at`, `updated_at`, `is_lost`, `lost_at`, `lost_reason`, `lost_reported_by`, `found_at`, `found_by`) VALUES
(1, 2, '0014973874', '2026-02-21 04:50:18', NULL, 1, NULL, NULL, NULL, '2026-02-21 04:56:35', '2026-02-21 05:51:56', 0, '2026-02-21 13:42:08', 'RFID card marked as lost by admin - Student notified', 3, '2026-02-21 13:51:56', 3);

--
-- Triggers `rfid_cards`
--
DELIMITER $$
CREATE TRIGGER `after_rfid_insert` AFTER INSERT ON `rfid_cards` FOR EACH ROW BEGIN
    IF NEW.is_active = 1 THEN
        UPDATE users 
        SET rfid_uid = NEW.rfid_uid, 
            rfid_registered_at = NEW.registered_at
        WHERE id = NEW.user_id;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_rfid_update` AFTER UPDATE ON `rfid_cards` FOR EACH ROW BEGIN
    IF NEW.is_active = 0 AND OLD.is_active = 1 THEN
        UPDATE users 
        SET rfid_uid = NULL, 
            rfid_registered_at = NULL
        WHERE id = NEW.user_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `rfid_status_history`
--

CREATE TABLE `rfid_status_history` (
  `id` int(11) NOT NULL,
  `rfid_card_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status_change` varchar(50) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `changed_by` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rfid_status_history`
--

INSERT INTO `rfid_status_history` (`id`, `rfid_card_id`, `user_id`, `status_change`, `changed_at`, `changed_by`, `reason`, `notes`, `ip_address`) VALUES
(1, 1, 2, 'LOST', '2026-02-21 04:56:55', 3, 'RFID card marked as lost by admin - Student notified', NULL, '::1'),
(2, 1, 2, 'FOUND', '2026-02-21 05:11:34', 3, 'RFID card marked as lost by admin - Student notified', 'Previously lost', '::1'),
(3, 1, 2, 'LOST', '2026-02-21 05:42:08', 3, 'RFID card marked as lost by admin - Student notified', NULL, '::1'),
(4, 1, 2, 'FOUND', '2026-02-21 05:51:56', 3, 'RFID card marked as lost by admin - Student notified', 'Previously lost', '::1');

-- --------------------------------------------------------

--
-- Table structure for table `student_guardians`
--

CREATE TABLE `student_guardians` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `guardian_id` int(11) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 1,
  `relationship_notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_profiles`
--

CREATE TABLE `student_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `profile_picture_uploaded_at` datetime DEFAULT NULL,
  `profile_picture_size` int(11) DEFAULT NULL COMMENT 'File size in bytes',
  `profile_picture_mime_type` varchar(50) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `student_profiles`
--
DELIMITER $$
CREATE TRIGGER `after_profile_update` AFTER UPDATE ON `student_profiles` FOR EACH ROW BEGIN
    IF NEW.profile_picture != OLD.profile_picture OR 
       NEW.profile_picture_uploaded_at != OLD.profile_picture_uploaded_at THEN
        UPDATE users 
        SET profile_picture = NEW.profile_picture,
            profile_picture_uploaded_at = NEW.profile_picture_uploaded_at
        WHERE id = NEW.user_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `value`, `description`, `updated_at`, `updated_by`) VALUES
(1, 'guardian_notifications_enabled', '1', 'Enable/disable guardian entry notifications globally', '2026-02-18 02:56:56', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `twofactor_codes`
--

CREATE TABLE `twofactor_codes` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `code` varchar(6) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `used_qr_tokens`
--

CREATE TABLE `used_qr_tokens` (
  `id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `used_at` datetime NOT NULL,
  `security_guard` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `course` varchar(255) DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Admin','Student') NOT NULL DEFAULT 'Student',
  `status` enum('Pending','Active','Locked') NOT NULL DEFAULT 'Pending',
  `failed_attempts` int(11) NOT NULL DEFAULT 0,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `profile_picture` varchar(255) DEFAULT NULL,
  `profile_picture_uploaded_at` datetime DEFAULT NULL,
  `rfid_uid` varchar(50) DEFAULT NULL,
  `rfid_registered_at` timestamp NULL DEFAULT NULL,
  `violation_count` int(11) NOT NULL DEFAULT 0,
  `face_registered` tinyint(1) NOT NULL DEFAULT 0,
  `face_registered_at` timestamp NULL DEFAULT NULL
) ;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `student_id`, `name`, `email`, `course`, `google_id`, `password`, `role`, `status`, `failed_attempts`, `last_login`, `created_at`, `updated_at`, `profile_picture`, `profile_picture_uploaded_at`, `rfid_uid`, `rfid_registered_at`, `violation_count`, `face_registered`, `face_registered_at`) VALUES
(1, 'ADMIN001', 'System Admin', 'admin@pcu.edu.ph', NULL, NULL, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 'Active', 0, NULL, '2026-02-04 10:52:03', '2026-02-04 10:52:03', NULL, NULL, NULL, NULL, 0, 0, NULL),
(2, '202232903', 'Mark Jason Briones Ramos', 'mrk.jason118@gmail.com', 'BS Information Technology', '117931670655597175684', '$2y$10$FNjaXw2zWHfTRMYeYOu1j.6SewRlL7KfwJG1xR6iDl8p8UXXWydg2', 'Student', 'Active', 0, '2026-02-23 12:07:16', '2026-02-04 11:27:36', '2026-02-23 04:07:16', 'student_2_1771400811.jpg', NULL, '0014973874', '2026-02-21 04:50:18', 0, 1, '2026-02-07 00:21:56'),
(3, 'ADMIN-001', 'System Administrator', 'jeysidelima04@gmail.com', NULL, NULL, '$2y$10$44khmJMS8msC/HKjEWPpRO5q.qcct7oefhLsccl5IGXa1EtneavLu', 'Admin', 'Active', 0, NULL, '2026-02-04 11:44:42', '2026-02-04 11:44:42', NULL, NULL, NULL, NULL, 0, 0, NULL),
(5, 'TEMP-1770538459', 'Joshua Morales', 'morales.josh133@gmail.com', NULL, '108230023574228583644', '$2y$10$7GlTtRmZWWKMw5.q49u7uutm01nLCmdYMyMndMFgdLaMfhrXFBklO', 'Student', 'Active', 0, '2026-02-08 16:18:21', '2026-02-08 08:14:19', '2026-02-08 08:19:34', NULL, NULL, NULL, NULL, 2, 1, '2026-02-08 08:17:25');

-- --------------------------------------------------------

--
-- Table structure for table `user_auth_methods`
--

CREATE TABLE `user_auth_methods` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `provider_user_id` varchar(255) DEFAULT NULL,
  `is_primary_method` tinyint(1) NOT NULL DEFAULT 0,
  `first_used_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `use_count` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_auth_methods`
--

INSERT INTO `user_auth_methods` (`id`, `user_id`, `provider_id`, `provider_user_id`, `is_primary_method`, `first_used_at`, `last_used_at`, `use_count`) VALUES
(1, 5, 1, '108230023574228583644', 1, '2026-02-08 08:14:19', '2026-02-08 08:19:34', 0),
(2, 2, 1, '117931670655597175684', 1, '2026-02-04 11:27:36', '2026-02-08 08:10:52', 0),
(4, 1, 2, NULL, 1, '2026-02-04 10:52:03', NULL, 0),
(5, 3, 2, NULL, 1, '2026-02-04 11:44:42', NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `violations`
--

CREATE TABLE `violations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rfid_uid` varchar(50) NOT NULL COMMENT 'RFID scanned at gate (may differ from registered)',
  `scanned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `violation_type` enum('forgot_card','unauthorized_access','blocked_entry') NOT NULL DEFAULT 'forgot_card',
  `gate_location` varchar(100) DEFAULT NULL COMMENT 'Which security gate',
  `security_guard_id` int(11) DEFAULT NULL COMMENT 'Guard who logged the violation',
  `email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `email_sent_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `violations`
--

INSERT INTO `violations` (`id`, `user_id`, `rfid_uid`, `scanned_at`, `violation_type`, `gate_location`, `security_guard_id`, `email_sent`, `email_sent_at`, `notes`) VALUES
(12, 5, 'FACE_RECOGNITION', '2026-02-08 08:19:34', 'forgot_card', NULL, NULL, 0, NULL, NULL);

--
-- Triggers `violations`
--
DELIMITER $$
CREATE TRIGGER `after_violation_insert` AFTER INSERT ON `violations` FOR EACH ROW BEGIN
    UPDATE users 
    SET violation_count = (
        SELECT COUNT(*) 
        FROM violations 
        WHERE user_id = NEW.user_id
    )
    WHERE id = NEW.user_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_active_rfid_cards`
-- (See below for the actual view)
--
CREATE TABLE `v_active_rfid_cards` (
`user_id` int(11)
,`student_id` varchar(20)
,`name` varchar(100)
,`email` varchar(100)
,`rfid_uid` varchar(50)
,`registered_at` timestamp
,`registered_by` int(11)
,`violation_count` int(11)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_students_complete`
-- (See below for the actual view)
--
CREATE TABLE `v_students_complete` (
`id` int(11)
,`student_id` varchar(20)
,`name` varchar(100)
,`email` varchar(100)
,`role` enum('Admin','Student')
,`status` enum('Pending','Active','Locked')
,`created_at` timestamp
,`last_login` datetime
,`profile_picture` varchar(255)
,`profile_picture_uploaded_at` datetime
,`rfid_uid` varchar(50)
,`rfid_registered_at` timestamp
,`violation_count` int(11)
,`bio` text
,`phone` varchar(20)
,`emergency_contact` varchar(100)
,`emergency_phone` varchar(20)
,`total_violations` bigint(21)
,`last_violation_date` timestamp
);

-- --------------------------------------------------------

--
-- Structure for view `v_active_rfid_cards`
--
DROP TABLE IF EXISTS `v_active_rfid_cards`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_active_rfid_cards`  AS SELECT `u`.`id` AS `user_id`, `u`.`student_id` AS `student_id`, `u`.`name` AS `name`, `u`.`email` AS `email`, `rc`.`rfid_uid` AS `rfid_uid`, `rc`.`registered_at` AS `registered_at`, `rc`.`registered_by` AS `registered_by`, `u`.`violation_count` AS `violation_count` FROM (`users` `u` join `rfid_cards` `rc` on(`u`.`id` = `rc`.`user_id`)) WHERE `rc`.`is_active` = 1 ;

-- --------------------------------------------------------

--
-- Structure for view `v_students_complete`
--
DROP TABLE IF EXISTS `v_students_complete`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_students_complete`  AS SELECT `u`.`id` AS `id`, `u`.`student_id` AS `student_id`, `u`.`name` AS `name`, `u`.`email` AS `email`, `u`.`role` AS `role`, `u`.`status` AS `status`, `u`.`created_at` AS `created_at`, `u`.`last_login` AS `last_login`, `u`.`profile_picture` AS `profile_picture`, `u`.`profile_picture_uploaded_at` AS `profile_picture_uploaded_at`, `u`.`rfid_uid` AS `rfid_uid`, `u`.`rfid_registered_at` AS `rfid_registered_at`, `u`.`violation_count` AS `violation_count`, `sp`.`bio` AS `bio`, `sp`.`phone` AS `phone`, `sp`.`emergency_contact` AS `emergency_contact`, `sp`.`emergency_phone` AS `emergency_phone`, (select count(0) from `violations` `v` where `v`.`user_id` = `u`.`id`) AS `total_violations`, (select max(`v`.`scanned_at`) from `violations` `v` where `v`.`user_id` = `u`.`id`) AS `last_violation_date` FROM (`users` `u` left join `student_profiles` `sp` on(`u`.`id` = `sp`.`user_id`)) WHERE `u`.`role` = 'Student' ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_table_name` (`table_name`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin_id` (`admin_id`),
  ADD KEY `idx_action_type` (`action_type`),
  ADD KEY `idx_target_type` (`target_type`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `auth_audit_log`
--
ALTER TABLE `auth_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `provider_id` (`provider_id`),
  ADD KEY `idx_user_action` (`user_id`,`action`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_ip_address` (`ip_address`),
  ADD KEY `idx_email` (`email`);

--
-- Indexes for table `auth_providers`
--
ALTER TABLE `auth_providers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `provider_name` (`provider_name`),
  ADD KEY `idx_enabled` (`is_enabled`),
  ADD KEY `idx_primary` (`is_primary`);

--
-- Indexes for table `face_descriptors`
--
ALTER TABLE `face_descriptors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_registered_by` (`registered_by`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `face_entry_logs`
--
ALTER TABLE `face_entry_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_security_guard_id` (`security_guard_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_entry_type` (`entry_type`);

--
-- Indexes for table `face_registration_log`
--
ALTER TABLE `face_registration_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_performed_by` (`performed_by`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `guardians`
--
ALTER TABLE `guardians`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_name` (`last_name`,`first_name`);

--
-- Indexes for table `notification_logs`
--
ALTER TABLE `notification_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_student_type_sent` (`student_id`,`notification_type`,`sent_at`),
  ADD KEY `idx_guardian_sent` (`guardian_id`,`sent_at`),
  ADD KEY `idx_sent_at` (`sent_at`);

--
-- Indexes for table `notification_queue`
--
ALTER TABLE `notification_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status_scheduled` (`status`,`scheduled_for`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_guardian` (`guardian_id`);

--
-- Indexes for table `notification_settings`
--
ALTER TABLE `notification_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `guardian_id` (`guardian_id`),
  ADD KEY `idx_entry_enabled` (`entry_notification`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_token` (`token`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_expires_at` (`expires_at`),
  ADD KEY `idx_used` (`used`);

--
-- Indexes for table `qr_entry_logs`
--
ALTER TABLE `qr_entry_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_student_id` (`student_id`),
  ADD KEY `idx_scanned_at` (`scanned_at`);

--
-- Indexes for table `rfid_cards`
--
ALTER TABLE `rfid_cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_active_rfid_per_user` (`user_id`,`is_active`),
  ADD UNIQUE KEY `uk_rfid_cards_uid` (`rfid_uid`),
  ADD KEY `registered_by` (`registered_by`),
  ADD KEY `unregistered_by` (`unregistered_by`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_is_lost` (`is_lost`),
  ADD KEY `fk_rfid_lost_reported_by` (`lost_reported_by`),
  ADD KEY `fk_rfid_found_by` (`found_by`);

--
-- Indexes for table `rfid_status_history`
--
ALTER TABLE `rfid_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rfid_card` (`rfid_card_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_changed_by` (`changed_by`);

--
-- Indexes for table `student_guardians`
--
ALTER TABLE `student_guardians`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_student_guardian` (`student_id`,`guardian_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_guardian` (`guardian_id`),
  ADD KEY `idx_primary` (`is_primary`);

--
-- Indexes for table `student_profiles`
--
ALTER TABLE `student_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_profile` (`user_id`),
  ADD KEY `idx_profile_picture` (`profile_picture`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `updated_by` (`updated_by`),
  ADD KEY `idx_setting_key` (`setting_key`);

--
-- Indexes for table `twofactor_codes`
--
ALTER TABLE `twofactor_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indexes for table `used_qr_tokens`
--
ALTER TABLE `used_qr_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `idx_token_hash` (`token_hash`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_used_at` (`used_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `unique_rfid` (`rfid_uid`),
  ADD UNIQUE KEY `google_id` (`google_id`),
  ADD KEY `idx_student_id` (`student_id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_profile_picture` (`profile_picture`),
  ADD KEY `idx_users_rfid_lookup` (`rfid_uid`,`role`,`status`),
  ADD KEY `idx_users_violations` (`role`,`violation_count`,`status`),
  ADD KEY `idx_users_profile` (`id`,`email`,`profile_picture`),
  ADD KEY `idx_users_face_lookup` (`face_registered`,`role`,`status`);

--
-- Indexes for table `user_auth_methods`
--
ALTER TABLE `user_auth_methods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_provider` (`user_id`,`provider_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_provider` (`provider_id`),
  ADD KEY `idx_provider_user_id` (`provider_user_id`);

--
-- Indexes for table `violations`
--
ALTER TABLE `violations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `security_guard_id` (`security_guard_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_scanned_at` (`scanned_at`),
  ADD KEY `idx_violation_type` (`violation_type`),
  ADD KEY `idx_rfid_uid` (`rfid_uid`);

--
-- Security and integrity checks
--
ALTER TABLE `users`
  ADD CONSTRAINT `chk_users_non_negative` CHECK (`failed_attempts` >= 0 AND `violation_count` >= 0),
  ADD CONSTRAINT `chk_users_face_registered_bool` CHECK (`face_registered` IN (0,1));

ALTER TABLE `rfid_cards`
  ADD CONSTRAINT `chk_rfid_cards_flags` CHECK (`is_active` IN (0,1) AND `is_lost` IN (0,1));

ALTER TABLE `notification_queue`
  ADD CONSTRAINT `chk_notification_queue_retry_non_negative` CHECK (`retry_count` >= 0);

ALTER TABLE `face_descriptors`
  ADD CONSTRAINT `chk_face_descriptors_quality` CHECK (`quality_score` IS NULL OR (`quality_score` >= 0 AND `quality_score` <= 1));

ALTER TABLE `face_descriptors`
  ADD CONSTRAINT `chk_face_descriptors_active` CHECK (`is_active` IN (0,1));

ALTER TABLE `face_entry_logs`
  ADD CONSTRAINT `chk_face_entry_scores` CHECK (`confidence_score` >= 0 AND `confidence_score` <= 1 AND `match_threshold` >= 0 AND `match_threshold` <= 1);

ALTER TABLE `face_registration_log`
  ADD CONSTRAINT `chk_face_registration_descriptor_count` CHECK (`descriptor_count` >= 0);

ALTER TABLE `auth_providers`
  ADD CONSTRAINT `chk_auth_providers_flags` CHECK (`is_enabled` IN (0,1) AND `is_primary` IN (0,1));

ALTER TABLE `notification_settings`
  ADD CONSTRAINT `chk_notification_settings_flags` CHECK (`entry_notification` IN (0,1) AND `exit_notification` IN (0,1) AND `violation_notification` IN (0,1) AND `daily_summary` IN (0,1));

ALTER TABLE `password_resets`
  ADD CONSTRAINT `chk_password_resets_used` CHECK (`used` IN (0,1));

ALTER TABLE `student_guardians`
  ADD CONSTRAINT `chk_student_guardians_primary` CHECK (`is_primary` IN (0,1));

ALTER TABLE `user_auth_methods`
  ADD CONSTRAINT `chk_user_auth_methods_flags` CHECK (`is_primary_method` IN (0,1) AND `use_count` >= 0);

ALTER TABLE `violations`
  ADD CONSTRAINT `chk_violations_email_sent` CHECK (`email_sent` IN (0,1));

ALTER TABLE `rfid_status_history`
  ADD CONSTRAINT `chk_rfid_status_history_status` CHECK (`status_change` IN ('LOST','FOUND','REGISTERED','UNREGISTERED'));

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `auth_audit_log`
--
ALTER TABLE `auth_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `auth_providers`
--
ALTER TABLE `auth_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `face_descriptors`
--
ALTER TABLE `face_descriptors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `face_entry_logs`
--
ALTER TABLE `face_entry_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `face_registration_log`
--
ALTER TABLE `face_registration_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `guardians`
--
ALTER TABLE `guardians`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_logs`
--
ALTER TABLE `notification_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_queue`
--
ALTER TABLE `notification_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_settings`
--
ALTER TABLE `notification_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qr_entry_logs`
--
ALTER TABLE `qr_entry_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rfid_cards`
--
ALTER TABLE `rfid_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `rfid_status_history`
--
ALTER TABLE `rfid_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `student_guardians`
--
ALTER TABLE `student_guardians`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_profiles`
--
ALTER TABLE `student_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `twofactor_codes`
--
ALTER TABLE `twofactor_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `used_qr_tokens`
--
ALTER TABLE `used_qr_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_auth_methods`
--
ALTER TABLE `user_auth_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `violations`
--
ALTER TABLE `violations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `auth_audit_log`
--
ALTER TABLE `auth_audit_log`
  ADD CONSTRAINT `auth_audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `auth_audit_log_ibfk_2` FOREIGN KEY (`provider_id`) REFERENCES `auth_providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `face_descriptors`
--
ALTER TABLE `face_descriptors`
  ADD CONSTRAINT `face_descriptors_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `face_descriptors_ibfk_2` FOREIGN KEY (`registered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `face_entry_logs`
--
ALTER TABLE `face_entry_logs`
  ADD CONSTRAINT `face_entry_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `face_entry_logs_ibfk_2` FOREIGN KEY (`security_guard_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `face_registration_log`
--
ALTER TABLE `face_registration_log`
  ADD CONSTRAINT `face_registration_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `face_registration_log_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notification_logs`
--
ALTER TABLE `notification_logs`
  ADD CONSTRAINT `notification_logs_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notification_logs_ibfk_2` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_queue`
--
ALTER TABLE `notification_queue`
  ADD CONSTRAINT `notification_queue_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notification_queue_ibfk_2` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_settings`
--
ALTER TABLE `notification_settings`
  ADD CONSTRAINT `notification_settings_ibfk_1` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `qr_entry_logs`
--
ALTER TABLE `qr_entry_logs`
  ADD CONSTRAINT `qr_entry_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rfid_cards`
--
ALTER TABLE `rfid_cards`
  ADD CONSTRAINT `fk_rfid_found_by` FOREIGN KEY (`found_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rfid_lost_reported_by` FOREIGN KEY (`lost_reported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `rfid_cards_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `rfid_cards_ibfk_2` FOREIGN KEY (`registered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `rfid_cards_ibfk_3` FOREIGN KEY (`unregistered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `rfid_status_history`
--
ALTER TABLE `rfid_status_history`
  ADD CONSTRAINT `rfid_status_history_ibfk_1` FOREIGN KEY (`rfid_card_id`) REFERENCES `rfid_cards` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `rfid_status_history_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `rfid_status_history_ibfk_3` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `student_guardians`
--
ALTER TABLE `student_guardians`
  ADD CONSTRAINT `student_guardians_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `student_guardians_ibfk_2` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_profiles`
--
ALTER TABLE `student_profiles`
  ADD CONSTRAINT `student_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `twofactor_codes`
--
ALTER TABLE `twofactor_codes`
  ADD CONSTRAINT `twofactor_codes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `used_qr_tokens`
--
ALTER TABLE `used_qr_tokens`
  ADD CONSTRAINT `used_qr_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_auth_methods`
--
ALTER TABLE `user_auth_methods`
  ADD CONSTRAINT `user_auth_methods_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_auth_methods_ibfk_2` FOREIGN KEY (`provider_id`) REFERENCES `auth_providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `violations`
--
ALTER TABLE `violations`
  ADD CONSTRAINT `violations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `violations_ibfk_2` FOREIGN KEY (`security_guard_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
