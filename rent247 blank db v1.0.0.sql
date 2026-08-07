-- phpMyAdmin SQL Dump
-- version 5.1.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 07, 2026 at 06:57 AM
-- Server version: 10.4.19-MariaDB
-- PHP Version: 8.1.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `rent247`
--

-- --------------------------------------------------------

--
-- Table structure for table `allocation_rules`
--

CREATE TABLE `allocation_rules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `charge_type_id` bigint(20) UNSIGNED NOT NULL,
  `strategy` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `params` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`params`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `allocation_rules`
--

INSERT INTO `allocation_rules` (`id`, `property_id`, `charge_type_id`, `strategy`, `params`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 5, 'equal_units', '{\"unit_types\":[\"residential\",\"commercial\",\"owner_occupied\"]}', 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(2, 1, 3, 'water_residential_commercial', '{\"residential_rate\":16.7,\"residential_unit_types\":[\"residential\",\"owner_occupied\"],\"commercial_unit_types\":[\"commercial\"],\"residential_count_override\":2,\"divisor_unit_types\":[\"residential\",\"commercial\",\"owner_occupied\"]}', 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(3, 1, 7, 'fee_tier', '{\"tiers\":{\"full\":1500,\"half\":1000,\"none\":0},\"include_garage\":false}', 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(4, 1, 2, 'per_lease', '[]', 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(5, 1, 6, 'per_lease', '[]', 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(6, 1, 8, 'per_lease', '[]', 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00');

-- --------------------------------------------------------

--
-- Table structure for table `billing_periods`
--

CREATE TABLE `billing_periods` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `year` smallint(5) UNSIGNED NOT NULL,
  `month` tinyint(3) UNSIGNED NOT NULL,
  `bill_date` date DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `billing_periods`
--

INSERT INTO `billing_periods` (`id`, `property_id`, `year`, `month`, `bill_date`, `status`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 2026, 8, '2026-08-05', 'draft', NULL, '2026-08-05 07:12:46', '2026-08-05 07:12:46');

-- --------------------------------------------------------

--
-- Table structure for table `billing_period_documents`
--

CREATE TABLE `billing_period_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `billing_period_id` bigint(20) UNSIGNED NOT NULL,
  `kind` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `meter_id` bigint(20) UNSIGNED DEFAULT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `charge_types`
--

CREATE TABLE `charge_types` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed',
  `default_amount` decimal(14,2) DEFAULT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 1,
  `on_invoice` tinyint(1) NOT NULL DEFAULT 1,
  `period_offset_months` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `charge_types`
--

INSERT INTO `charge_types` (`id`, `property_id`, `code`, `label`, `category`, `default_amount`, `is_recurring`, `on_invoice`, `period_offset_months`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, 'office_rent', 'Office Rent', 'rent', NULL, 1, 1, 0, 1, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(2, 1, 'gas', 'Gas Bill', 'fixed', '1080.00', 1, 1, 0, 1, 2, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(3, 1, 'water', 'Water Bill', 'utility', NULL, 1, 1, -1, 1, 3, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(4, 1, 'electricity', 'Electricity Bill', 'utility', NULL, 1, 1, -1, 1, 4, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(5, 1, 'electricity_common', 'Electricity Bill (common)', 'utility', NULL, 1, 1, -1, 1, 5, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(6, 1, 'caretaker', 'Care Taker / Security Guard', 'fixed', '6000.00', 1, 1, 0, 1, 6, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(7, 1, 'dohs', 'DOHS Porishod', 'fixed', '1500.00', 1, 1, 0, 1, 7, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(8, 1, 'lift', 'Lift Maintenance', 'fixed', '1000.00', 1, 1, 0, 1, 8, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(9, 1, 'other', 'Other Charges', 'other', '0.00', 0, 1, 0, 1, 9, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(10, 1, 'arrears', 'Arrears', 'arrears', '0.00', 0, 1, 0, 1, 10, '2026-08-05 02:11:00', '2026-08-05 02:11:00');

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `billing_period_id` bigint(20) UNSIGNED NOT NULL,
  `lease_id` bigint(20) UNSIGNED NOT NULL,
  `number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `issued_at` timestamp NULL DEFAULT NULL,
  `pdf_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_lines`
--

CREATE TABLE `invoice_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `charge_type_id` bigint(20) UNSIGNED DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leases`
--

CREATE TABLE `leases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `unit_id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` bigint(20) UNSIGNED NOT NULL,
  `rent_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `rent_label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Office Rent',
  `invoice_mode` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'combined',
  `attach_water_bill` tinyint(1) NOT NULL DEFAULT 0,
  `attach_electricity_bill` tinyint(1) NOT NULL DEFAULT 0,
  `fee_tier` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leases`
--

INSERT INTO `leases` (`id`, `property_id`, `unit_id`, `tenant_id`, `rent_amount`, `rent_label`, `invoice_mode`, `attach_water_bill`, `attach_electricity_bill`, `fee_tier`, `starts_on`, `ends_on`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, '100000.00', 'Office Rent', 'combined', 1, 1, 'full', NULL, NULL, 1, '2026-08-05 02:11:00', '2026-08-05 07:12:01'),
(2, 1, 3, 2, '80000.00', 'Office Rent', 'split', 1, 1, 'full', NULL, NULL, 1, '2026-08-05 02:11:00', '2026-08-05 07:12:09'),
(3, 1, 4, 2, '80000.00', 'Office Rent', 'split', 1, 1, 'full', NULL, NULL, 1, '2026-08-05 02:11:00', '2026-08-05 07:12:15'),
(4, 1, 5, 3, '105556.00', 'Office Rent', 'combined', 1, 1, 'full', NULL, NULL, 1, '2026-08-05 02:11:00', '2026-08-05 07:12:21'),
(5, 1, 6, 4, '0.00', 'Garage Rent', 'combined', 0, 0, 'none', NULL, NULL, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(6, 1, 7, 5, '4000.00', 'Garage Rent', 'combined', 0, 0, 'none', NULL, NULL, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(7, 1, 8, 6, '0.00', 'Garage Rent', 'combined', 0, 0, 'none', NULL, NULL, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(8, 1, 9, 7, '4000.00', 'Garage Rent', 'combined', 0, 0, 'none', NULL, NULL, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00');

-- --------------------------------------------------------

--
-- Table structure for table `lease_charge_assignments`
--

CREATE TABLE `lease_charge_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lease_id` bigint(20) UNSIGNED NOT NULL,
  `charge_type_id` bigint(20) UNSIGNED NOT NULL,
  `amount_override` decimal(14,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `lease_charge_assignments`
--

INSERT INTO `lease_charge_assignments` (`id`, `lease_id`, `charge_type_id`, `amount_override`, `is_active`, `created_at`, `updated_at`) VALUES
(39, 1, 2, NULL, 1, '2026-08-05 07:12:01', '2026-08-05 07:12:01'),
(40, 1, 6, NULL, 1, '2026-08-05 07:12:01', '2026-08-05 07:12:01'),
(41, 1, 8, NULL, 1, '2026-08-05 07:12:01', '2026-08-05 07:12:01'),
(42, 1, 7, NULL, 1, '2026-08-05 07:12:01', '2026-08-05 07:12:01'),
(43, 2, 2, NULL, 1, '2026-08-05 07:12:09', '2026-08-05 07:12:09'),
(44, 2, 6, NULL, 1, '2026-08-05 07:12:09', '2026-08-05 07:12:09'),
(45, 2, 8, NULL, 1, '2026-08-05 07:12:09', '2026-08-05 07:12:09'),
(46, 2, 7, NULL, 1, '2026-08-05 07:12:09', '2026-08-05 07:12:09'),
(47, 3, 2, NULL, 1, '2026-08-05 07:12:15', '2026-08-05 07:12:15'),
(48, 3, 6, NULL, 1, '2026-08-05 07:12:15', '2026-08-05 07:12:15'),
(49, 3, 8, NULL, 1, '2026-08-05 07:12:15', '2026-08-05 07:12:15'),
(50, 3, 7, NULL, 1, '2026-08-05 07:12:15', '2026-08-05 07:12:15'),
(51, 4, 2, NULL, 1, '2026-08-05 07:12:21', '2026-08-05 07:12:21'),
(52, 4, 6, NULL, 1, '2026-08-05 07:12:21', '2026-08-05 07:12:21'),
(53, 4, 8, NULL, 1, '2026-08-05 07:12:21', '2026-08-05 07:12:21'),
(54, 4, 7, NULL, 1, '2026-08-05 07:12:21', '2026-08-05 07:12:21');

-- --------------------------------------------------------

--
-- Table structure for table `mail_logs`
--

CREATE TABLE `mail_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `to_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `error` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meters`
--

CREATE TABLE `meters` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `meters`
--

INSERT INTO `meters` (`id`, `property_id`, `code`, `name`, `kind`, `unit_id`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, '581', 'Stair', 'common', NULL, 1, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(2, 1, '317', 'Water', 'common', NULL, 1, 2, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(3, 1, '318', 'Ground', 'common', NULL, 1, 3, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(4, 1, '676', 'Main', 'common', NULL, 1, 4, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(5, 1, '059', 'New', 'common', NULL, 1, 5, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(6, 1, '563', '1st Floor', 'unit', 1, 1, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(7, 1, '223', '2nd Floor A', 'unit', 2, 1, 2, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(8, 1, '327', '2nd Floor B', 'unit', 2, 1, 3, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(9, 1, '670', '2nd Floor C', 'unit', 2, 1, 4, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(10, 1, '237', '3rd Floor', 'unit', 3, 1, 5, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(11, 1, '545', '4th Floor', 'unit', 4, 1, 6, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(12, 1, '565', '5th Floor', 'unit', 5, 1, 7, '2026-08-05 02:11:00', '2026-08-05 02:11:00');

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '2014_10_12_000000_create_users_table', 1),
(2, '2014_10_12_100000_create_password_reset_tokens_table', 1),
(3, '2019_08_19_000000_create_failed_jobs_table', 1),
(4, '2019_12_14_000001_create_personal_access_tokens_table', 1),
(5, '2026_08_05_000001_create_rent247_tables', 1),
(6, '2026_08_05_000002_add_invoice_split_preferences', 1),
(7, '2026_08_05_000003_use_single_invoice_with_pdf_layout', 2),
(8, '2026_08_05_000004_add_tenant_portal_token', 3),
(9, '2026_08_05_000005_utility_bill_attachments', 4),
(10, '2026_08_05_000006_add_meter_to_billing_period_documents', 5);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `paid_on` date NOT NULL,
  `method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `period_charge_inputs`
--

CREATE TABLE `period_charge_inputs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `billing_period_id` bigint(20) UNSIGNED NOT NULL,
  `charge_type_id` bigint(20) UNSIGNED NOT NULL,
  `lease_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `units` decimal(14,4) DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `period_meter_inputs`
--

CREATE TABLE `period_meter_inputs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `billing_period_id` bigint(20) UNSIGNED NOT NULL,
  `meter_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `service_period` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `properties`
--

CREATE TABLE `properties` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_display_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BDT',
  `timezone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Asia/Dhaka',
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `properties`
--

INSERT INTO `properties` (`id`, `user_id`, `name`, `address`, `owner_display_name`, `currency`, `timezone`, `settings`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 20, 'House-247', 'House -247, Road-3, Baridhara DOHS, Dhaka Cantt., Dhaka-1206', 'Brig General M Mofizur Rahman (Retd)', 'BDT', 'Asia/Dhaka', '{\"pdf_dual_copy\":true,\"invoice_title\":\"House Rent and Utility Bill\",\"auto_carry_arrears\":true,\"signature_path\":\"signatures\\/1\\/BQRIM3emq9pzwpRpeoAcSg4ovEY5ACA7UPFnzbii.png\"}', 1, '2026-08-05 02:11:00', '2026-08-05 05:32:21');

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `portal_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `portal_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tenants`
--

INSERT INTO `tenants` (`id`, `user_id`, `name`, `email`, `phone`, `notes`, `portal_token`, `portal_enabled`, `created_at`, `updated_at`) VALUES
(1, 20, 'Biswa Shera Travel Agency', NULL, NULL, NULL, '7940a720cb3ea31ab0c454ffe02b47c8947420f53dfad59f732451b54cdb9ccb', 1, '2026-08-05 02:11:00', '2026-08-06 22:23:49'),
(2, 20, 'Pentex', NULL, NULL, NULL, 'a0b91226d84b913f6747ea7292fa29c72a01209c1d94b41152c2aad4816c3f3e', 1, '2026-08-05 02:11:00', '2026-08-05 06:40:51'),
(3, 20, 'Nishat Fabrics', NULL, NULL, NULL, 'e752f98c7cf80dc6eccccf4c7ce2c9b426be46c599a8922fca7e801001dc6231', 1, '2026-08-05 02:11:00', '2026-08-05 06:40:50'),
(4, 20, 'Maj Ismail Bhuiyan', NULL, NULL, NULL, 'dcb5b7f01ee7b7dbac63ee2693ea4d7c1fcc0674e35d01cc12bb05d3eef01d8f', 1, '2026-08-05 02:11:00', '2026-08-05 06:40:48'),
(5, 20, 'Shati', NULL, NULL, NULL, 'ea423d6de4794ede61f4ee10003933cb889882333a205b350e45493f7ce84b60', 1, '2026-08-05 02:11:00', '2026-08-05 06:40:55'),
(6, 20, 'Lt. Col Sakhawat', NULL, NULL, NULL, 'd439837ad1e88eae4c1adafc4670a31e90462ebcdc3390360ff9ca521da2205c', 1, '2026-08-05 02:11:00', '2026-08-05 06:40:47'),
(7, 20, 'Sareen Kabir', NULL, NULL, NULL, '2b417dce03decd297ee24c3e56ca47d4e700124f9536c79a31b95f16f431d300', 1, '2026-08-05 02:11:00', '2026-08-05 06:40:53');

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `property_id` bigint(20) UNSIGNED NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `units`
--

INSERT INTO `units` (`id`, `property_id`, `label`, `type`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, '1st', 'commercial', 1, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(2, 1, '2nd', 'owner_occupied', 2, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(3, 1, '3rd', 'commercial', 3, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(4, 1, '4th', 'commercial', 4, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(5, 1, '5th', 'commercial', 5, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(6, 1, 'Garage 1', 'garage', 11, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(7, 1, 'Garage 2', 'garage', 12, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(8, 1, 'Garage 3', 'garage', 13, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00'),
(9, 1, 'Garage 4', 'garage', 14, 1, '2026-08-05 02:11:00', '2026-08-05 02:11:00');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_property_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `email_verified_at`, `password`, `remember_token`, `current_property_id`, `created_at`, `updated_at`) VALUES
(20, 'Brig General M Mofizur Rahman', 'mofiz374@gmail.com', NULL, '$2y$10$j83M5AwdkNNS0DFF0n6PjOo7.sRFJJFD4Hr1m1RVWv2ig8CQln4iW', 'IyyMbkeTDZVYUeNFa0GFK2E4vih3pvijeyXukkIHPLV9dW2K53KIasdpqnZs', 1, '2026-08-05 02:11:00', '2026-08-06 22:46:16'),
(21, 'Swad Ahmed Mahfuz', 'swad.mahfuz@gmail.com', NULL, '$2y$10$u.gv.Ik1BukGgP3T8vYX/uUhKc9ggCDc4aY74oZ0262H5tyUhjWMm', NULL, NULL, '2026-08-05 07:27:42', '2026-08-05 07:27:42');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `allocation_rules`
--
ALTER TABLE `allocation_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `allocation_rules_property_id_foreign` (`property_id`),
  ADD KEY `allocation_rules_charge_type_id_foreign` (`charge_type_id`);

--
-- Indexes for table `billing_periods`
--
ALTER TABLE `billing_periods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `billing_periods_property_id_year_month_unique` (`property_id`,`year`,`month`);

--
-- Indexes for table `billing_period_documents`
--
ALTER TABLE `billing_period_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `period_docs_meter_scope` (`billing_period_id`,`kind`,`unit_id`,`meter_id`),
  ADD KEY `billing_period_documents_unit_id_foreign` (`unit_id`),
  ADD KEY `billing_period_documents_meter_id_foreign` (`meter_id`);

--
-- Indexes for table `charge_types`
--
ALTER TABLE `charge_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `charge_types_property_id_code_unique` (`property_id`,`code`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoices_billing_period_id_lease_id_unique` (`billing_period_id`,`lease_id`),
  ADD KEY `invoices_property_id_foreign` (`property_id`),
  ADD KEY `invoices_lease_id_foreign` (`lease_id`),
  ADD KEY `invoices_billing_period_index` (`billing_period_id`);

--
-- Indexes for table `invoice_lines`
--
ALTER TABLE `invoice_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_lines_invoice_id_foreign` (`invoice_id`),
  ADD KEY `invoice_lines_charge_type_id_foreign` (`charge_type_id`);

--
-- Indexes for table `leases`
--
ALTER TABLE `leases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `leases_property_id_foreign` (`property_id`),
  ADD KEY `leases_unit_id_foreign` (`unit_id`),
  ADD KEY `leases_tenant_id_foreign` (`tenant_id`);

--
-- Indexes for table `lease_charge_assignments`
--
ALTER TABLE `lease_charge_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `lease_charge_assignments_lease_id_charge_type_id_unique` (`lease_id`,`charge_type_id`),
  ADD KEY `lease_charge_assignments_charge_type_id_foreign` (`charge_type_id`);

--
-- Indexes for table `mail_logs`
--
ALTER TABLE `mail_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `mail_logs_invoice_id_foreign` (`invoice_id`);

--
-- Indexes for table `meters`
--
ALTER TABLE `meters`
  ADD PRIMARY KEY (`id`),
  ADD KEY `meters_property_id_foreign` (`property_id`),
  ADD KEY `meters_unit_id_foreign` (`unit_id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `payments_invoice_id_foreign` (`invoice_id`);

--
-- Indexes for table `period_charge_inputs`
--
ALTER TABLE `period_charge_inputs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `period_charge_inputs_billing_period_id_foreign` (`billing_period_id`),
  ADD KEY `period_charge_inputs_charge_type_id_foreign` (`charge_type_id`),
  ADD KEY `period_charge_inputs_lease_id_foreign` (`lease_id`);

--
-- Indexes for table `period_meter_inputs`
--
ALTER TABLE `period_meter_inputs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `period_meter_inputs_billing_period_id_meter_id_unique` (`billing_period_id`,`meter_id`),
  ADD KEY `period_meter_inputs_meter_id_foreign` (`meter_id`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `properties`
--
ALTER TABLE `properties`
  ADD PRIMARY KEY (`id`),
  ADD KEY `properties_user_id_foreign` (`user_id`);

--
-- Indexes for table `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tenants_portal_token_unique` (`portal_token`),
  ADD KEY `tenants_user_id_foreign` (`user_id`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`id`),
  ADD KEY `units_property_id_foreign` (`property_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD KEY `users_current_property_id_foreign` (`current_property_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `allocation_rules`
--
ALTER TABLE `allocation_rules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `billing_periods`
--
ALTER TABLE `billing_periods`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `billing_period_documents`
--
ALTER TABLE `billing_period_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `charge_types`
--
ALTER TABLE `charge_types`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_lines`
--
ALTER TABLE `invoice_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leases`
--
ALTER TABLE `leases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `lease_charge_assignments`
--
ALTER TABLE `lease_charge_assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `mail_logs`
--
ALTER TABLE `mail_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `meters`
--
ALTER TABLE `meters`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `period_charge_inputs`
--
ALTER TABLE `period_charge_inputs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `period_meter_inputs`
--
ALTER TABLE `period_meter_inputs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `properties`
--
ALTER TABLE `properties`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `allocation_rules`
--
ALTER TABLE `allocation_rules`
  ADD CONSTRAINT `allocation_rules_charge_type_id_foreign` FOREIGN KEY (`charge_type_id`) REFERENCES `charge_types` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `allocation_rules_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `billing_periods`
--
ALTER TABLE `billing_periods`
  ADD CONSTRAINT `billing_periods_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `billing_period_documents`
--
ALTER TABLE `billing_period_documents`
  ADD CONSTRAINT `billing_period_documents_billing_period_id_foreign` FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `billing_period_documents_meter_id_foreign` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `billing_period_documents_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `charge_types`
--
ALTER TABLE `charge_types`
  ADD CONSTRAINT `charge_types_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_billing_period_id_foreign` FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_lease_id_foreign` FOREIGN KEY (`lease_id`) REFERENCES `leases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `invoice_lines`
--
ALTER TABLE `invoice_lines`
  ADD CONSTRAINT `invoice_lines_charge_type_id_foreign` FOREIGN KEY (`charge_type_id`) REFERENCES `charge_types` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `invoice_lines_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leases`
--
ALTER TABLE `leases`
  ADD CONSTRAINT `leases_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leases_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leases_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `lease_charge_assignments`
--
ALTER TABLE `lease_charge_assignments`
  ADD CONSTRAINT `lease_charge_assignments_charge_type_id_foreign` FOREIGN KEY (`charge_type_id`) REFERENCES `charge_types` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lease_charge_assignments_lease_id_foreign` FOREIGN KEY (`lease_id`) REFERENCES `leases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mail_logs`
--
ALTER TABLE `mail_logs`
  ADD CONSTRAINT `mail_logs_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `meters`
--
ALTER TABLE `meters`
  ADD CONSTRAINT `meters_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `meters_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `period_charge_inputs`
--
ALTER TABLE `period_charge_inputs`
  ADD CONSTRAINT `period_charge_inputs_billing_period_id_foreign` FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `period_charge_inputs_charge_type_id_foreign` FOREIGN KEY (`charge_type_id`) REFERENCES `charge_types` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `period_charge_inputs_lease_id_foreign` FOREIGN KEY (`lease_id`) REFERENCES `leases` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `period_meter_inputs`
--
ALTER TABLE `period_meter_inputs`
  ADD CONSTRAINT `period_meter_inputs_billing_period_id_foreign` FOREIGN KEY (`billing_period_id`) REFERENCES `billing_periods` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `period_meter_inputs_meter_id_foreign` FOREIGN KEY (`meter_id`) REFERENCES `meters` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `properties`
--
ALTER TABLE `properties`
  ADD CONSTRAINT `properties_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tenants`
--
ALTER TABLE `tenants`
  ADD CONSTRAINT `tenants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `units`
--
ALTER TABLE `units`
  ADD CONSTRAINT `units_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_current_property_id_foreign` FOREIGN KEY (`current_property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
