-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 24, 2026 at 02:58 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `capstone_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `customer_id`, `action`, `details`, `created_at`) VALUES
(1, NULL, 1, 'Customer Login', 'Customer \'Elaisa Reine Belandres\' (ID: 1) logged in.', '2026-07-29 13:48:02'),
(8, 1, NULL, 'Manage Rewards', 'Deleted reward: Gift Card of ₱50', '2026-07-29 14:34:34'),
(9, 1, NULL, 'Manage Rewards', 'Deleted reward: Free Item', '2026-07-29 14:34:37'),
(10, 1, NULL, 'Manage Rewards', 'Deleted reward: Free 0.5kg Cat Food', '2026-07-29 14:34:40'),
(11, 1, NULL, 'Manage Rewards', 'Deleted reward: ₱100', '2026-07-29 14:36:21'),
(12, 1, NULL, 'Manage Rewards', 'Added reward: ₱10 Discount Voucher (DPS-DISC-10)', '2026-07-29 14:40:56'),
(13, 1, NULL, 'Manage Rewards', 'Added reward: ₱25 Discount Voucher (DPS-DISC-25)', '2026-07-29 14:41:32'),
(14, 1, NULL, 'Manage Rewards', 'Added reward: ₱50 Discount Voucher (DPS-DISC-50)', '2026-07-29 14:42:13'),
(15, 1, NULL, 'Manage Rewards', 'Added reward: ₱100 Discount Voucher (DPS-DISC-100)', '2026-07-29 14:42:54'),
(16, 1, NULL, 'Manage Rewards', 'Updated reward: ₱50 Discount Voucher (ID: 10)', '2026-07-29 14:43:09'),
(17, NULL, 1, 'Customer Login', 'Customer \'Elaisa Reine Belandres\' (ID: 1) logged in.', '2026-07-29 14:44:01'),
(18, 1, NULL, 'POINT_ADJUSTMENT', 'Processed points for Elaisa Reine Belandres (Type: Addition, Value: 10). Reason: Addition: Special Bonus', '2026-07-29 14:55:02'),
(19, 1, NULL, 'POINT_ADJUSTMENT', 'Processed points for Elaisa Reine Belandres (Type: Addition, Value: 10). Reason: Addition: System Correction', '2026-07-29 14:56:29'),
(20, 1, NULL, 'POINT_ADJUSTMENT', 'Processed points for Elaisa Reine Belandres (Type: Addition, Value: 10). Reason: Addition: Walk-in Purchase Points', '2026-07-29 15:36:06'),
(21, 1, NULL, 'Manage Rewards', 'Added reward: ₱25 Discount Voucher (DPS-DISC-25)', '2026-07-29 15:59:22'),
(22, 1, NULL, 'Manage Rewards', 'Added reward: ₱50 Discount Voucher (DPS-DISC-50)', '2026-07-29 16:01:13'),
(23, 1, NULL, 'Manage Rewards', 'Added reward: ₱100 Discount Voucher (DPS-DISC-100)', '2026-07-29 16:02:43'),
(24, 1, NULL, 'Manage Rewards', 'Added reward: ₱300 Discount Voucher (DPS-DISC-300)', '2026-07-29 16:03:54'),
(25, NULL, 1, 'Customer Logout', 'Customer \'Elaisa Reine Belandres\' (ID: 1) logged out.', '2026-07-29 16:09:58'),
(26, NULL, 1, 'Customer Login', 'Customer \'Elaisa Reine Belandres\' (ID: 1) logged in.', '2026-07-29 16:24:28'),
(27, 1, NULL, 'POINT_ADJUSTMENT', 'Processed points for Elaisa Reine Belandres (Type: Addition, Value: 5). Reason: Addition: Walk-in Purchase Points', '2026-07-29 16:36:06'),
(28, 1, NULL, 'POINT_ADJUSTMENT', 'Processed points for Elaisa Reine Belandres (Type: Addition, Value: 10). Reason: Addition: Walk-in Purchase Points', '2026-07-29 16:38:07'),
(29, 1, NULL, 'POINT_ADJUSTMENT', 'Processed points for Elaisa Reine Belandres (Type: Addition, Value: 40). Reason: Addition: Walk-in Purchase Points', '2026-07-29 16:49:56'),
(30, 1, NULL, 'POINT_ADJUSTMENT', 'Processed points for Elaisa Reine Belandres (Type: Addition, Value: 50). Reason: Addition: Walk-in Purchase Points', '2026-07-30 16:21:12'),
(31, 1, NULL, 'Updated Product', 'User updated product: FELIX As Good As It Looks Adult Chicken in Jelly Wet Cat Food. Changes: Price from 100.00 to 185.00, Stock from 85 to 50.', '2026-07-31 16:03:07'),
(32, 1, NULL, 'Updated Product', 'User updated product: FELIX As Good As It Looks Adult Chicken in Jelly Wet Cat Food. No changes detected.', '2026-07-31 16:54:14'),
(33, 1, NULL, 'Manage Reviews', 'User approved review ID: 1.', '2026-07-31 17:17:52'),
(34, 1, NULL, 'POINT_ADJUSTMENT', 'Processed points for Elaisa Reine Belandres (Type: Addition, Value: 10). Reason: Addition: Walk-in Purchase Points', '2026-07-31 17:27:49'),
(35, 2, NULL, 'POINT_ADJUSTMENT', 'Processed points for Elaisa Reine Belandres (Type: Addition, Value: 5). Reason: Addition: Walk-in Purchase Points', '2026-08-02 12:22:04'),
(36, NULL, 1, 'Customer Login', 'Customer \'Elaisa Reine Belandres\' (ID: 1) logged in.', '2026-08-04 03:01:36'),
(37, 1, NULL, 'POINT_ADJUSTMENT', 'Processed points for Elaisa Reine Belandres (Type: Addition, Value: 5). Reason: Addition: Walk-in Purchase Points', '2026-08-04 08:25:39'),
(38, NULL, 1, 'Customer Login', 'Customer \'Elaisa Reine Belandres\' (ID: 1) logged in.', '2026-08-22 09:01:13'),
(39, 1, NULL, 'POINT_ADJUSTMENT', 'Processed points for Marichelle D. Gono (Type: Addition, Value: 100). Reason: Addition: Walk-in Purchase Points', '2026-08-23 05:16:47');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `loyalty_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin_typing_at` timestamp NULL DEFAULT NULL,
  `last_typing_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `name`, `email`, `phone`, `gender`, `age`, `address`, `loyalty_points`, `created_at`, `admin_typing_at`, `last_typing_at`) VALUES
(1, 'Elaisa Reine Belandres', 'ereinebelandres09@gmail.com', '09634998174', 'Female', 21, '269 Stotsenburg St. Caloocan City', 23.20, '2026-04-28 17:25:04', '2026-07-21 19:09:02', NULL),
(2, 'Marichelle D. Gono', 'gonomarichelle@gmail.com', '09385381016', 'Female', 22, 'kawal phase 1 road, kawal phase 1 rd, blk 100 lot 6 purok 4 kawal st. dagat-dagatan caloocan city', 100.00, '2026-05-20 12:52:48', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customer_addresses`
--

CREATE TABLE `customer_addresses` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `label` varchar(50) NOT NULL,
  `full_address` text NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customer_addresses`
--

INSERT INTO `customer_addresses` (`id`, `customer_id`, `label`, `full_address`, `phone`, `latitude`, `longitude`, `is_default`, `created_at`) VALUES
(1, 2, 'blk 100', 'kawal dagat-dagatan caloocan city', '09385381016', NULL, NULL, 0, '2026-06-24 12:45:10'),
(2, 1, 'Home', 'Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', '09634998174', NULL, NULL, 1, '2026-08-21 12:33:17');

-- --------------------------------------------------------

--
-- Table structure for table `customer_login_credentials`
--

CREATE TABLE `customer_login_credentials` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `password` varchar(255) NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customer_login_credentials`
--

INSERT INTO `customer_login_credentials` (`id`, `customer_id`, `password`, `last_login`, `created_at`) VALUES
(1, 1, '$2y$10$dJGfqkCHH34XG9I/qFqeNu/geP8RsDPPdfeXINcQExE.kGiWEmyku', NULL, '2026-04-28 17:25:04'),
(2, 2, '$2y$10$kMwm6XEiWSt9S5E55ZUzxuO884fFgjVFKTuNV8LNQU1CVCMQdX1LS', NULL, '2026-05-20 12:52:48');

-- --------------------------------------------------------

--
-- Table structure for table `customer_orders`
--

CREATE TABLE `customer_orders` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `order_number` varchar(100) NOT NULL,
  `order_status` enum('pending','confirmed','processing','ready_for_pickup','out_for_delivery','completed','cancelled') NOT NULL DEFAULT 'pending',
  `fulfillment_type` enum('pickup','delivery') NOT NULL DEFAULT 'pickup',
  `pickup_date` date DEFAULT NULL,
  `pickup_time` varchar(50) DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `delivery_phone` varchar(50) DEFAULT NULL,
  `delivery_instructions` text DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `delivery_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bulk_order` tinyint(1) NOT NULL DEFAULT 0,
  `free_delivery` tinyint(1) NOT NULL DEFAULT 0,
  `loyalty_points_earned` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_order_items`
--

CREATE TABLE `customer_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_tracking`
--

CREATE TABLE `delivery_tracking` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `rider_id` int(11) NOT NULL,
  `status` varchar(50) NOT NULL COMMENT 'e.g., accepted, picked_up, delivered, failed',
  `notes` text DEFAULT NULL,
  `proof_image_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loyalty_transactions`
--

CREATE TABLE `loyalty_transactions` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity_kg` decimal(10,2) NOT NULL,
  `points_earned` decimal(10,2) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loyalty_transactions`
--

INSERT INTO `loyalty_transactions` (`id`, `customer_id`, `user_id`, `product_name`, `quantity_kg`, `points_earned`, `order_id`, `created_at`) VALUES
(1, 1, NULL, 'Online Purchase (Order #82)', 0.00, 5.00, 82, '2026-07-29 14:01:01'),
(2, 1, 1, 'Addition: Special Bonus', 0.00, 10.00, NULL, '2026-07-29 14:54:57'),
(3, 1, NULL, 'Online Purchase (Order #83)', 0.00, 10.00, 83, '2026-07-29 14:55:45'),
(4, 1, 1, 'Addition: System Correction', 0.00, 10.00, NULL, '2026-07-29 14:56:24'),
(5, 1, 1, 'Addition: Walk-in Purchase Points', 0.00, 10.00, NULL, '2026-07-29 15:36:01'),
(6, 1, NULL, 'Online Purchase (Order #84)', 0.00, 5.00, 84, '2026-07-29 16:35:42'),
(7, 1, 1, 'Addition: Walk-in Purchase Points', 0.00, 5.00, NULL, '2026-07-29 16:36:02'),
(8, 1, NULL, 'Online Purchase (Order #85)', 0.00, 4.75, 85, '2026-07-29 16:37:22'),
(9, 1, 1, 'Addition: Walk-in Purchase Points', 0.00, 10.00, NULL, '2026-07-29 16:38:02'),
(10, 1, 1, 'Addition: Walk-in Purchase Points', 0.00, 40.00, NULL, '2026-07-29 16:49:51'),
(11, 1, NULL, 'Online Purchase (Order #86)', 0.00, 4.75, 86, '2026-07-30 16:20:31'),
(12, 1, 1, 'Addition: Walk-in Purchase Points', 0.00, 50.00, NULL, '2026-07-30 16:21:08'),
(13, 1, 1, 'Addition: Walk-in Purchase Points', 0.00, 10.00, NULL, '2026-07-31 17:27:44'),
(14, 1, 2, 'Addition: Walk-in Purchase Points', 0.00, 5.00, NULL, '2026-08-02 12:21:57'),
(15, 1, NULL, 'Online Purchase (Order #87)', 0.00, 5.00, 87, '2026-08-02 12:34:50'),
(16, 1, NULL, 'Online Purchase (Order #88)', 0.00, 22.70, 88, '2026-08-04 05:19:43'),
(17, 1, NULL, 'Online Purchase (Order #89)', 0.00, 5.00, 89, '2026-08-04 08:01:54'),
(18, 1, 1, 'Addition: Walk-in Purchase Points', 0.00, 5.00, NULL, '2026-08-04 08:25:33'),
(19, 1, NULL, 'Online Purchase (Order #90)', 0.00, 5.00, 90, '2026-08-21 13:03:53'),
(20, 1, NULL, 'Online Purchase (Order #91)', 0.00, 1.00, 91, '2026-08-22 10:29:01'),
(21, 2, 1, 'Addition: Walk-in Purchase Points', 0.00, 100.00, NULL, '2026-08-23 05:16:34');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `channel` enum('in_app','email','both') NOT NULL DEFAULT 'in_app',
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `reference_table` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `points_value` decimal(10,2) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `email_to` varchar(255) DEFAULT NULL,
  `delivery_status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `delivery_error` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `customer_id`, `type`, `channel`, `title`, `message`, `reference_table`, `reference_id`, `points_value`, `is_read`, `read_at`, `email_to`, `delivery_status`, `delivery_error`, `sent_at`, `created_at`) VALUES
(1, NULL, 1, 'ORDER', 'in_app', 'New Customer Order', 'Order #DPS-490E76B7-1785333660 for PHP 560.00 was placed as Delivery. Free delivery applied.', 'tbl_orders', 82, NULL, 1, '2026-08-21 20:55:29', NULL, 'pending', NULL, NULL, '2026-07-29 14:01:01'),
(2, NULL, 1, 'points_earned', 'both', 'You earned 5.00 points!', 'Elaisa Reine Belandres earned 5.00 points from your purchase. New usable balance: 0.00 points. Points expire after 12 months.', 'loyalty_transactions', 1, 5.00, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-07-29 22:01:07', '2026-07-29 14:01:01'),
(3, 1, 1, 'point_adjustment', 'both', 'Points Balance Updated', 'Hello Elaisa Reine Belandres, your loyalty points have been updated by 10.00 points. Reason: Addition: Special Bonus. Your new balance is 15.00 points.', NULL, NULL, 10.00, 1, '2026-07-29 23:10:40', '0', 'sent', NULL, '2026-07-29 22:55:02', '2026-07-29 14:54:57'),
(4, NULL, 1, 'ORDER', 'in_app', 'New Customer Order', 'Order #DPS-0AE575F8-1785336944 for PHP 1,120.00 was placed as Delivery. Bulk order flagged for priority handling. Free delivery applied.', 'tbl_orders', 83, NULL, 1, '2026-08-21 20:55:29', NULL, 'pending', NULL, NULL, '2026-07-29 14:55:44'),
(5, NULL, 1, 'points_earned', 'both', 'You earned 10.00 points!', 'Elaisa Reine Belandres earned 10.00 points from your purchase. New usable balance: 25.00 points. Points expire after 12 months.', 'loyalty_transactions', 3, 10.00, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-07-29 22:55:49', '2026-07-29 14:55:45'),
(6, 1, 1, 'point_adjustment', 'both', 'Points Balance Updated', 'Hello Elaisa Reine Belandres, your loyalty points have been updated by 10.00 points. Reason: Addition: System Correction. Your new balance is 35.00 points.', NULL, NULL, 10.00, 1, '2026-07-29 23:10:40', '0', 'sent', NULL, '2026-07-29 22:56:29', '2026-07-29 14:56:24'),
(7, 1, 1, 'point_adjustment', 'both', 'Points Balance Updated', 'Hello Elaisa Reine Belandres, your loyalty points have been updated by 10.00 points. Reason: Addition: Walk-in Purchase Points. Your new balance is 45.00 points.', NULL, NULL, 10.00, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-07-29 23:36:06', '2026-07-29 15:36:01'),
(8, NULL, 1, 'ORDER', 'in_app', 'New Customer Order', 'Order #DPS-FBACE43C-1785342941 for PHP 560.00 was placed as Delivery. Free delivery applied.', 'tbl_orders', 84, NULL, 1, '2026-08-21 20:55:29', NULL, 'pending', NULL, NULL, '2026-07-29 16:35:42'),
(9, NULL, 1, 'points_earned', 'both', 'You earned 5.00 points!', 'Elaisa Reine Belandres earned 5.00 points from your purchase. New usable balance: 50.00 points. Points expire after 12 months.', 'loyalty_transactions', 6, 5.00, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-07-30 00:35:46', '2026-07-29 16:35:42'),
(10, 1, 1, 'point_adjustment', 'both', 'Points Balance Updated', 'Hello Elaisa Reine Belandres, your loyalty points have been updated by 5.00 points. Reason: Addition: Walk-in Purchase Points. Your new balance is 55.00 points.', NULL, NULL, 5.00, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-07-30 00:36:06', '2026-07-29 16:36:02'),
(11, NULL, 1, 'ORDER', 'in_app', 'New Customer Order', 'Order #DPS-5B3EC9C1-1785343042 for PHP 560.00 was placed as Delivery. Free delivery applied.', 'tbl_orders', 85, NULL, 1, '2026-08-21 20:55:29', NULL, 'pending', NULL, NULL, '2026-07-29 16:37:22'),
(12, NULL, 1, 'points_earned', 'both', 'You earned 4.75 points!', 'Elaisa Reine Belandres earned 4.75 points from your purchase. New usable balance: 9.75 points. Points expire after 12 months.', 'loyalty_transactions', 8, 4.75, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-07-30 00:37:27', '2026-07-29 16:37:22'),
(13, 1, 1, 'point_adjustment', 'both', 'Points Balance Updated', 'Hello Elaisa Reine Belandres, your loyalty points have been updated by 10.00 points. Reason: Addition: Walk-in Purchase Points. Your new balance is 19.75 points.', NULL, NULL, 10.00, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-07-30 00:38:07', '2026-07-29 16:38:02'),
(14, 1, 1, 'point_adjustment', 'both', 'Points Balance Updated', 'Hello Elaisa Reine Belandres, your loyalty points have been updated by 40.00 points. Reason: Addition: Walk-in Purchase Points. Your new balance is 59.75 points.', NULL, NULL, 40.00, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-07-30 00:49:56', '2026-07-29 16:49:51'),
(15, NULL, 1, 'ORDER', 'in_app', 'New Customer Order', 'Order #DPS-500D3C37-1785428430 for PHP 560.00 was placed as Delivery. Free delivery applied.', 'tbl_orders', 86, NULL, 1, '2026-08-21 20:55:29', NULL, 'pending', NULL, NULL, '2026-07-30 16:20:31'),
(16, NULL, 1, 'points_earned', 'both', 'You earned 4.75 points!', 'Elaisa Reine Belandres earned 4.75 points from your purchase. New usable balance: 14.50 points. Points expire after 12 months.', 'loyalty_transactions', 11, 4.75, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-07-31 00:20:36', '2026-07-30 16:20:31'),
(17, 1, 1, 'point_adjustment', 'both', 'Points Balance Updated', 'Hello Elaisa Reine Belandres, your loyalty points have been updated by 50.00 points. Reason: Addition: Walk-in Purchase Points. Your new balance is 64.50 points.', NULL, NULL, 50.00, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-07-31 00:21:12', '2026-07-30 16:21:08'),
(18, NULL, 1, 'order_status_update', 'email', 'Order DPS-0AE575F8-1785336944 status update', 'Hi Elaisa Reine Belandres,\n\nYour order DPS-0AE575F8-1785336944 has been updated to: Completed.\n\nIf you have any questions, please contact us.', 'tbl_orders', 83, NULL, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-08-01 00:54:25', '2026-07-31 16:54:20'),
(19, 1, 1, 'point_adjustment', 'both', 'Points Balance Updated', 'Hello Elaisa Reine Belandres, your loyalty points have been updated by 10.00 points. Reason: Addition: Walk-in Purchase Points. Your new balance is 24.50 points.', NULL, NULL, 10.00, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-08-01 01:27:49', '2026-07-31 17:27:44'),
(20, NULL, 1, 'order_status_update', 'email', 'Order DPS-FBACE43C-1785342941 status update', 'Hi Elaisa Reine Belandres,\n\nYour order DPS-FBACE43C-1785342941 has been updated to: Completed.\n\nIf you have any questions, please contact us.', 'tbl_orders', 84, NULL, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-08-01 01:30:15', '2026-07-31 17:30:11'),
(21, 6, 1, 'rider_qr_confirm', 'in_app', 'Delivery confirmed', 'Your delivery has been confirmed by the rider.', 'tbl_orders', 84, NULL, 1, '2026-08-21 20:55:29', NULL, 'sent', NULL, '2026-08-01 16:46:39', '2026-08-01 08:46:39'),
(22, 6, 1, 'rider_qr_confirm', 'in_app', 'Delivery confirmed', 'Your delivery has been confirmed by the rider.', 'tbl_orders', 86, NULL, 1, '2026-08-21 20:55:29', NULL, 'sent', NULL, '2026-08-01 16:49:32', '2026-08-01 08:49:32'),
(23, NULL, 1, 'order_status_update', 'email', 'Order DPS-500D3C37-1785428430 status update', 'Hi Elaisa Reine Belandres,\n\nYour order DPS-500D3C37-1785428430 has been updated to: To Receive.\n\nIf you have any questions, please contact us.', 'tbl_orders', 86, NULL, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-08-01 16:53:06', '2026-08-01 08:52:58'),
(24, NULL, 1, 'order_status_update', 'email', 'Order DPS-5B3EC9C1-1785343042 status update', 'Hi Elaisa Reine Belandres,\n\nYour order DPS-5B3EC9C1-1785343042 has been updated to: To Receive.\n\nIf you have any questions, please contact us.', 'tbl_orders', 85, NULL, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-08-01 16:58:06', '2026-08-01 08:58:01'),
(25, 6, 1, 'rider_qr_confirm', 'in_app', 'Delivery confirmed', 'Your delivery has been confirmed by the rider.', 'tbl_orders', 85, NULL, 1, '2026-08-21 20:55:29', NULL, 'sent', NULL, '2026-08-01 16:58:29', '2026-08-01 08:58:29'),
(26, NULL, 1, 'order_status_update', 'email', 'Order DPS-500D3C37-1785428430 status update', 'Hi Elaisa Reine Belandres,\n\nYour order DPS-500D3C37-1785428430 has been updated to: Completed.\n\nIf you have any questions, please contact us.', 'tbl_orders', 86, NULL, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-08-01 17:00:40', '2026-08-01 09:00:35'),
(27, NULL, 1, 'order_status_update', 'email', 'Order DPS-0AE575F8-1785336944 status update', 'Hi Elaisa Reine Belandres,\n\nYour order DPS-0AE575F8-1785336944 has been updated to: Completed.\n\nIf you have any questions, please contact us.', 'tbl_orders', 83, NULL, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-08-01 17:42:48', '2026-08-01 09:42:43'),
(28, 2, 1, 'point_adjustment', 'both', 'Points Balance Updated', 'Hello Elaisa Reine Belandres, your loyalty points have been updated by 5.00 points. Reason: Addition: Walk-in Purchase Points. Your new balance is 29.50 points.', NULL, NULL, 5.00, 1, '2026-08-02 20:22:12', '0', 'sent', NULL, '2026-08-02 20:22:04', '2026-08-02 12:21:57'),
(29, NULL, 1, 'ORDER', 'in_app', 'New Customer Order', 'Order #DPS-40473E2D-1785674089 for PHP 560.00 was placed as Delivery. Free delivery applied.', 'tbl_orders', 87, NULL, 1, '2026-08-21 20:55:29', NULL, 'pending', NULL, NULL, '2026-08-02 12:34:49'),
(30, NULL, 1, 'points_earned', 'both', 'You earned 5.00 points!', 'Elaisa Reine Belandres earned 5.00 points from your purchase. New usable balance: 34.50 points. Points expire after 12 months.', 'loyalty_transactions', 15, 5.00, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-08-02 20:34:58', '2026-08-02 12:34:50'),
(31, NULL, 1, 'ORDER', 'in_app', 'New Customer Order', 'Order #DPS-7C13E433-1785820783 for PHP 2,570.40 was placed as Delivery. Bulk order flagged for priority handling. Free delivery applied.', 'tbl_orders', 88, NULL, 1, '2026-08-21 20:55:29', NULL, 'pending', NULL, NULL, '2026-08-04 05:19:43'),
(32, NULL, 1, 'points_earned', 'both', 'You earned 22.70 points!', 'Elaisa Reine Belandres earned 22.70 points from your purchase. New usable balance: 57.20 points. Points expire after 12 months.', 'loyalty_transactions', 16, 22.70, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-08-04 13:19:49', '2026-08-04 05:19:43'),
(33, NULL, 1, 'ORDER', 'in_app', 'New Customer Order', 'Order #DPS-1ACFEAF5-1785830513 for PHP 560.00 was placed as Pickup. Free delivery applied.', 'tbl_orders', 89, NULL, 1, '2026-08-21 20:55:29', NULL, 'pending', NULL, NULL, '2026-08-04 08:01:54'),
(34, NULL, 1, 'points_earned', 'both', 'You earned 5.00 points!', 'Elaisa Reine Belandres earned 5.00 points from your purchase. New usable balance: 12.20 points. Points expire after 12 months.', 'loyalty_transactions', 17, 5.00, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-08-04 16:02:01', '2026-08-04 08:01:54'),
(35, 1, 1, 'point_adjustment', 'both', 'Points Balance Updated', 'Hello Elaisa Reine Belandres, your loyalty points have been updated by 5.00 points. Reason: Addition: Walk-in Purchase Points. Your new balance is 17.20 points.', NULL, NULL, 5.00, 1, '2026-08-21 20:55:29', '0', 'sent', NULL, '2026-08-04 16:25:39', '2026-08-04 08:25:33'),
(36, 6, 1, 'rider_qr_confirm', 'in_app', 'Delivery confirmed', 'Your delivery has been confirmed by the rider.', 'tbl_orders', 88, NULL, 1, '2026-08-21 20:55:29', NULL, 'sent', NULL, '2026-08-04 20:33:05', '2026-08-04 12:33:05'),
(37, 1, 1, 'rider_message', 'in_app', 'Message from your rider', 'Your rider sent you a message.', 'tbl_messages', 1, NULL, 1, '2026-08-21 20:55:11', NULL, 'sent', NULL, '2026-08-21 17:38:36', '2026-08-21 09:38:36'),
(38, NULL, 1, 'order_status_update', 'both', 'Order #DPS-40473E2D-1785674089 Status Updated', 'Hello Elaisa Reine Belandres,\n\nYour order #DPS-40473E2D-1785674089 has been updated.\n\nCurrent Order Status:\nCompleted\n\nOrder Date: August 2, 2026\nFulfillment: Delivery\nPickup Date: 0000-00-00\n\nYou can log in to your Darius Poultry Supplies customer portal to view the latest details of your order.\n\nThank you for shopping with Darius Poultry Supplies.', 'tbl_orders', 87, NULL, 1, '2026-08-21 20:55:05', '0', 'sent', NULL, '2026-08-21 20:22:17', '2026-08-21 12:22:10'),
(39, NULL, 1, 'ORDER', 'in_app', 'New Customer Order', 'Order #DPS-15A7D141-1787315573 for PHP 560.00 was placed as Delivery.', 'tbl_orders', 90, NULL, 1, '2026-08-21 20:55:00', NULL, 'pending', NULL, NULL, '2026-08-21 12:32:54'),
(40, NULL, 1, 'order_status_update', 'both', 'Order Confirmed!', 'Your order #DPS-15A7D141-1787315573 has been confirmed and is now being processed.', 'tbl_orders', 90, NULL, 1, '2026-08-21 20:45:06', NULL, 'failed', 'Invalid recipient email address.', NULL, '2026-08-21 12:44:52'),
(41, NULL, 1, 'points_earned', 'both', 'Loyalty points earned', 'Elaisa Reine Belandres earned 5.00 points from order #90. Current balance: 22.20 points.', 'tbl_orders', 90, 5.00, 0, NULL, '0', 'sent', NULL, '2026-08-21 21:03:58', '2026-08-21 13:03:53'),
(42, NULL, 1, 'order_status_update', 'both', 'Order DPS-15A7D141-1787315573 status update', 'Hi Elaisa Reine Belandres,\n\nYour order DPS-15A7D141-1787315573 has been updated to: Completed.\n\nIf you have any questions, please contact us.\n\nLoyalty Points Earned: 5.00\nCurrent Loyalty Points Balance: 22.20', 'tbl_orders', 90, NULL, 0, NULL, '0', 'sent', NULL, '2026-08-21 21:04:03', '2026-08-21 13:03:58'),
(43, NULL, 1, 'ORDER', 'in_app', 'New Customer Order', 'Order #DPS-980E2683-1787394411 for PHP 112.00 was placed as Delivery.', 'tbl_orders', 91, NULL, 0, NULL, NULL, 'pending', NULL, NULL, '2026-08-22 10:26:52'),
(44, NULL, 1, 'points_earned', 'both', 'Loyalty points earned', 'Elaisa Reine Belandres earned 1.00 points from order #91. Current balance: 23.20 points.', 'tbl_orders', 91, 1.00, 0, NULL, '0', 'sent', NULL, '2026-08-22 18:29:07', '2026-08-22 10:29:01'),
(45, NULL, 1, 'order_status_update', 'both', 'Order #DPS-980E2683-1787394411 Status Updated', 'Hello Elaisa Reine Belandres,\n\nYour order #DPS-980E2683-1787394411 has been updated.\n\nCurrent Order Status:\nTo Ship\n\nOrder Date: August 22, 2026\nFulfillment: Delivery\nPickup Date: 0000-00-00\n\nYou can log in to your Darius Poultry Supplies customer portal to view the latest details of your order.\n\nThank you for shopping with Darius Poultry Supplies.', 'tbl_orders', 91, NULL, 0, NULL, '0', 'sent', NULL, '2026-08-22 18:29:14', '2026-08-22 10:29:08'),
(46, NULL, 1, 'order_status_update', 'both', 'Order #DPS-980E2683-1787394411 Status Updated', 'Hello Elaisa Reine Belandres,\n\nYour order #DPS-980E2683-1787394411 has been updated.\n\nCurrent Order Status:\nCompleted\n\nOrder Date: August 22, 2026\nFulfillment: Delivery\nPickup Date: 0000-00-00\n\nLoyalty Points Earned: 1.00\nCurrent Loyalty Points Balance: 23.20\n\nYou can log in to your Darius Poultry Supplies customer portal to view the latest details of your order.\n\nThank you for shopping with Darius Poultry Supplies.', 'tbl_orders', 91, NULL, 0, NULL, '0', 'sent', NULL, '2026-08-22 18:29:54', '2026-08-22 10:29:46'),
(47, NULL, 1, 'ORDER', 'in_app', 'New Customer Order', 'Order #DPS-71B2DF7B-1787447328 for PHP 207.20 was placed as Delivery.', 'tbl_orders', 92, NULL, 0, NULL, NULL, 'pending', NULL, NULL, '2026-08-23 01:08:49'),
(48, 1, 2, 'point_adjustment', 'both', 'Points Balance Updated', 'Hello Marichelle D. Gono, your loyalty points have been updated by 100.00 points. Reason: Addition: Walk-in Purchase Points. Your new balance is 100.00 points.', NULL, NULL, 100.00, 0, NULL, '0', 'sent', NULL, '2026-08-23 13:16:46', '2026-08-23 05:16:34');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `category` varchar(255) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rewards`
--

CREATE TABLE `rewards` (
  `id` int(11) NOT NULL,
  `reward_code` varchar(100) NOT NULL,
  `name` varchar(255) NOT NULL,
  `points` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `validity_days` int(11) DEFAULT 7,
  `expiry_date` date DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rewards`
--

INSERT INTO `rewards` (`id`, `reward_code`, `name`, `points`, `stock`, `validity_days`, `expiry_date`, `description`) VALUES
(12, 'DPS-DISC-25', '₱25 Discount Voucher', 50.00, 995, 30, NULL, 'Get a ₱25 discount on your next purchase. A small thank you for your loyalty!'),
(13, 'DPS-DISC-50', '₱50 Discount Voucher', 100.00, 999, 30, NULL, 'Enjoy a ₱50 discount on any order, perfect for your daily essentials.'),
(14, 'DPS-DISC-100', '₱100 Discount Voucher', 200.00, 500, 60, NULL, 'A ₱100 discount for our loyal customers. Use it on your next big purchase!'),
(15, 'DPS-DISC-300', '₱300 Discount Voucher', 500.00, 250, 60, NULL, 'The ultimate discount! Get ₱300 off as a thank you for your continued support.');

-- --------------------------------------------------------

--
-- Table structure for table `reward_redemptions`
--

CREATE TABLE `reward_redemptions` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `reward_code` varchar(100) NOT NULL,
  `reward_name` varchar(255) NOT NULL,
  `points_used` decimal(10,2) NOT NULL,
  `redeemed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `card_number` varchar(20) DEFAULT NULL,
  `pin_code` varchar(10) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reward_redemptions`
--

INSERT INTO `reward_redemptions` (`id`, `customer_id`, `user_id`, `reward_code`, `reward_name`, `points_used`, `redeemed_at`, `card_number`, `pin_code`, `expiry_date`, `status`) VALUES
(1, 1, NULL, 'DPS-DISC-25', '0', 50.00, '2026-07-29 16:36:23', 'V-BBA197E1ADE1', NULL, '2026-08-29', 'Used'),
(2, 1, NULL, 'DPS-DISC-25', '0', 50.00, '2026-07-29 16:50:03', 'V-DD818CEFFF3F', NULL, '2026-08-29', 'Used'),
(3, 1, NULL, 'DPS-DISC-25', '0', 50.00, '2026-07-30 16:21:21', 'V-6AF68E60774A', NULL, '2026-08-30', 'Used'),
(4, 1, NULL, 'DPS-DISC-25', '0', 50.00, '2026-08-04 07:19:41', 'V-018CFACF75EF', NULL, '2026-09-03', 'Used');

-- --------------------------------------------------------

--
-- Table structure for table `riders`
--

CREATE TABLE `riders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vehicle_type` varchar(50) DEFAULT NULL COMMENT 'e.g., Motorcycle, Bicycle',
  `plate_number` varchar(20) DEFAULT NULL,
  `is_on_duty` tinyint(1) DEFAULT 0,
  `availability_status` enum('available','unavailable') NOT NULL DEFAULT 'available',
  `last_seen` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `riders`
--

INSERT INTO `riders` (`id`, `user_id`, `vehicle_type`, `plate_number`, `is_on_duty`, `availability_status`, `last_seen`) VALUES
(2, 2, NULL, NULL, 0, 'available', NULL),
(3, 1, NULL, NULL, 0, 'available', NULL),
(4, 6, NULL, NULL, 1, 'available', '2026-08-21 09:33:35');

-- --------------------------------------------------------

--
-- Table structure for table `rider_remittances`
--

CREATE TABLE `rider_remittances` (
  `id` int(11) NOT NULL,
  `rider_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference_number` varchar(255) NOT NULL,
  `remitted_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_cart`
--

CREATE TABLE `tbl_cart` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `variant_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `tbl_customer_records`
-- (See below for the actual view)
--
CREATE TABLE `tbl_customer_records` (
`id` int(11)
,`name` varchar(255)
,`email` varchar(255)
,`phone` varchar(20)
,`gender` varchar(20)
,`age` int(11)
,`address` text
,`loyalty_points` decimal(10,2)
,`created_at` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_custom_categories`
--

CREATE TABLE `tbl_custom_categories` (
  `id` int(11) NOT NULL,
  `group_name` varchar(100) NOT NULL,
  `category_value` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_custom_categories`
--

INSERT INTO `tbl_custom_categories` (`id`, `group_name`, `category_value`, `created_at`) VALUES
(3, 'Brand', 'SUPERCOAT®', '2026-07-21 13:25:27'),
(4, 'Health Needs', 'Cat Food', '2026-07-21 13:26:39'),
(5, 'Lifestage', 'Puppy', '2026-07-21 14:45:05');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_deliveries`
--

CREATE TABLE `tbl_deliveries` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `delivery_status` enum('pending','accepted','picked_up','out_for_delivery','delivered','failed_delivery','cancelled') NOT NULL DEFAULT 'pending',
  `qr_confirmation_token` varchar(255) DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_delivery`
--

CREATE TABLE `tbl_delivery` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `rider_id` int(11) DEFAULT NULL,
  `delivery_type` enum('pickup','delivery') NOT NULL DEFAULT 'delivery',
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `status` enum('pending','in_transit','delivered','failed') NOT NULL DEFAULT 'pending',
  `scheduled_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `qr_confirmation_token` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_delivery`
--

INSERT INTO `tbl_delivery` (`id`, `order_id`, `rider_id`, `delivery_type`, `address`, `phone`, `instructions`, `status`, `scheduled_at`, `delivered_at`, `qr_confirmation_token`, `created_at`, `updated_at`) VALUES
(2, 83, NULL, 'delivery', 'Baltazar Bukid, Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metropolitan Manila, 1406, Philippines', '09634998174', '', 'pending', NULL, NULL, 'e0a65f06f0b1a86e56035d67c91aa193', '2026-07-29 14:55:44', NULL),
(3, 84, 6, 'delivery', 'Baltazar Bukid, Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metropolitan Manila, 1406, Philippines', '09634998174', '', 'delivered', NULL, '2026-08-01 16:46:39', '1b1a94fd7c931d6785caf6efc2c52cbf', '2026-07-29 16:35:41', '2026-08-01 16:46:39'),
(4, 85, 6, 'delivery', 'Baltazar Bukid, Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metropolitan Manila, 1406, Philippines', '09634998174', '', 'delivered', NULL, '2026-08-01 16:58:29', '13227ba7d832c8cf0d654b0185e92b42', '2026-07-29 16:37:22', '2026-08-01 16:58:29'),
(5, 86, 6, 'delivery', 'Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', '09634998174', '', 'delivered', NULL, '2026-08-01 16:49:32', 'f1c83f240f23d5337f286cbc6793f9a7', '2026-07-30 16:20:30', '2026-08-01 16:49:32'),
(6, 87, NULL, 'delivery', 'Baltazar Bukid, Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metropolitan Manila, 1406, Philippines', '09634998174', '', 'pending', NULL, NULL, '429fde8fd9695536ba72d3ac48e4cd87', '2026-08-02 12:34:49', NULL),
(7, 88, 6, 'delivery', 'Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', '09634998174', '', 'delivered', NULL, '2026-08-04 20:33:05', NULL, '2026-08-04 05:19:43', '2026-08-04 20:33:05'),
(8, 89, NULL, 'pickup', '', '', '', 'pending', '2026-08-09 09:00:00', NULL, '69648348afeae10b1fcee5e5629dac9b', '2026-08-04 08:01:53', NULL),
(9, 90, NULL, 'delivery', 'Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', '09634998174', '', 'pending', NULL, NULL, '8deeec6f604e0ae00bc2594be8777521', '2026-08-21 12:32:53', NULL),
(10, 91, NULL, 'delivery', 'Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', '09634998174', '', 'pending', NULL, NULL, '173fa04a7a18a9140043c6ef0a23f68d', '2026-08-22 10:26:51', NULL),
(11, 92, NULL, 'delivery', 'Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', '09634998174', '', 'pending', NULL, NULL, '15aa166328cc12fe19ec856134e0a554', '2026-08-23 01:08:48', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_messages`
--

CREATE TABLE `tbl_messages` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `sender_type` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_messages`
--

INSERT INTO `tbl_messages` (`id`, `customer_id`, `user_id`, `sender_type`, `message`, `is_read`, `read_at`, `created_at`) VALUES
(1, 1, 6, 'rider', 'DPS DELIVERY! ID: DPS-1ACFEAF5-1785830513, Rider: testrider1.  Your COD amount is 1000.00. You can pay by cash or conveniently online when the rider arrives.  Please note that a photo proof of delivery is required.  Thank you for choosing DPS Express Philippines! Stay safe and have a great day ahead.', 1, '2026-08-21 17:38:50', '2026-08-21 09:38:36');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_orders`
--

CREATE TABLE `tbl_orders` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `rider_id` int(11) DEFAULT NULL,
  `order_number` varchar(100) NOT NULL,
  `order_status` enum('pending','confirmed','processing','ready_for_pickup','out_for_delivery','to_ship','to_receive','reviews','completed','cancelled') NOT NULL DEFAULT 'pending',
  `cancellation_reason` text DEFAULT NULL,
  `fulfillment_type` enum('pickup','delivery') NOT NULL DEFAULT 'pickup',
  `pickup_date` date DEFAULT NULL,
  `pickup_time` varchar(50) DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `order_notes` text DEFAULT NULL,
  `delivery_phone` varchar(50) DEFAULT NULL,
  `delivery_instructions` text DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vat_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `delivery_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tip` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bulk_order` tinyint(1) NOT NULL DEFAULT 0,
  `free_delivery` tinyint(1) NOT NULL DEFAULT 0,
  `loyalty_points_earned` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `voucher_code` varchar(100) DEFAULT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `payment_method` enum('cod','gcash','pay_at_shop','bank') NOT NULL DEFAULT 'cod',
  `payment_settled` tinyint(1) NOT NULL DEFAULT 0,
  `payment_reference` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_name` varchar(255) DEFAULT NULL,
  `payment_proof_path` varchar(255) DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `payment_confirmation_token` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_orders`
--

INSERT INTO `tbl_orders` (`id`, `customer_id`, `rider_id`, `order_number`, `order_status`, `cancellation_reason`, `fulfillment_type`, `pickup_date`, `pickup_time`, `delivery_address`, `order_notes`, `delivery_phone`, `delivery_instructions`, `subtotal`, `vat_amount`, `delivery_fee`, `tip`, `bulk_order`, `free_delivery`, `loyalty_points_earned`, `discount_amount`, `voucher_code`, `total`, `created_at`, `updated_at`, `payment_method`, `payment_settled`, `payment_reference`, `bank_name`, `bank_account_name`, `payment_proof_path`, `qr_code_path`, `payment_confirmation_token`) VALUES
(83, 1, 4, 'DPS-0AE575F8-1785336944', 'out_for_delivery', NULL, 'delivery', '0000-00-00', '', 'Baltazar Bukid, Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metropolitan Manila, 1406, Philippines', NULL, '09634998174', '', 1000.00, 120.00, 0.00, 0.00, 1, 1, 10.00, 0.00, '', 1120.00, '2026-07-29 14:55:44', NULL, 'gcash', 0, '1038780232114', NULL, NULL, NULL, NULL, NULL),
(84, 1, NULL, 'DPS-FBACE43C-1785342941', 'completed', NULL, 'delivery', '0000-00-00', '', 'Baltazar Bukid, Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metropolitan Manila, 1406, Philippines', NULL, '09634998174', '', 500.00, 60.00, 0.00, 0.00, 0, 1, 5.00, 0.00, '', 560.00, '2026-07-29 16:35:41', '2026-08-01 16:46:39', 'gcash', 0, '1038780232114', NULL, NULL, NULL, NULL, NULL),
(85, 1, NULL, 'DPS-5B3EC9C1-1785343042', 'completed', NULL, 'delivery', '0000-00-00', '', 'Baltazar Bukid, Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metropolitan Manila, 1406, Philippines', NULL, '09634998174', '', 500.00, 60.00, 0.00, 0.00, 0, 1, 4.75, 25.00, 'V-BBA197E1ADE1', 535.00, '2026-07-29 16:37:22', '2026-08-01 16:58:29', 'cod', 0, '', NULL, NULL, NULL, NULL, NULL),
(86, 1, NULL, 'DPS-500D3C37-1785428430', 'completed', NULL, 'delivery', '0000-00-00', '', 'Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', NULL, '09634998174', '', 500.00, 60.00, 0.00, 0.00, 0, 1, 4.75, 25.00, 'V-DD818CEFFF3F', 535.00, '2026-07-30 16:20:30', '2026-08-01 16:49:32', 'gcash', 0, '4043017654741', NULL, NULL, NULL, NULL, NULL),
(87, 1, 4, 'DPS-40473E2D-1785674089', 'completed', NULL, 'delivery', '0000-00-00', '', 'Baltazar Bukid, Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metropolitan Manila, 1406, Philippines', '', '09634998174', '0', 500.00, 60.00, 0.00, 0.00, 0, 1, 5.00, 0.00, '', 560.00, '2026-08-02 12:34:49', '2026-08-21 20:22:10', 'cod', 0, '', NULL, NULL, NULL, NULL, NULL),
(88, 1, 4, 'DPS-7C13E433-1785820783', 'completed', NULL, 'delivery', '0000-00-00', '', 'Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', 'hahahahahahhahahahaha', '09634998174', '0', 2295.00, 275.40, 0.00, 0.00, 1, 1, 22.70, 25.00, 'V-6AF68E60774A', 2545.40, '2026-08-04 05:19:43', '2026-08-04 20:33:05', 'cod', 0, '', NULL, NULL, NULL, NULL, NULL),
(89, 1, NULL, 'DPS-1ACFEAF5-1785830513', 'completed', NULL, 'pickup', '2026-08-09', '09:00', '', '', '', '0', 500.00, 60.00, 0.00, 0.00, 0, 1, 5.00, 25.00, 'V-018CFACF75EF', 535.00, '2026-08-04 08:01:53', '2026-08-21 17:22:14', 'gcash', 0, '4043017654741', NULL, NULL, NULL, NULL, NULL),
(90, 1, NULL, 'DPS-15A7D141-1787315573', 'completed', NULL, 'delivery', '0000-00-00', '', 'Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', '', '09634998174', '0', 500.00, 60.00, 50.00, 0.00, 0, 0, 5.00, 0.00, '', 610.00, '2026-08-21 12:32:53', NULL, 'cod', 0, '', NULL, NULL, NULL, NULL, NULL),
(91, 1, NULL, 'DPS-980E2683-1787394411', 'completed', NULL, 'delivery', '0000-00-00', '', 'Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', '', '09634998174', '0', 100.00, 12.00, 50.00, 0.00, 0, 0, 1.00, 0.00, '', 162.00, '2026-08-22 10:26:51', '2026-08-22 18:29:46', 'cod', 0, '', NULL, NULL, NULL, NULL, NULL),
(92, 1, NULL, 'DPS-71B2DF7B-1787447328', 'cancelled', 'ang galing mo pokingina', 'delivery', '0000-00-00', '', 'Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', '', '09634998174', '0', 185.00, 22.20, 50.00, 0.00, 0, 0, 0.00, 0.00, '', 257.20, '2026-08-23 01:08:48', NULL, 'cod', 0, '', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_order_items`
--

CREATE TABLE `tbl_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `variant_size` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_order_items`
--

INSERT INTO `tbl_order_items` (`id`, `order_id`, `product_id`, `product_name`, `variant_size`, `quantity`, `unit_price`, `total_price`, `created_at`) VALUES
(6, 83, 4, 'PURINA ONE® Active Kitten with Chicken Dry Cat Food', NULL, 10, 100.00, 1000.00, '2026-07-29 14:55:44'),
(7, 84, 34, 'Supercoat Adult Small Sensitive Skin Ocean Fish Dry Dog Food', NULL, 5, 100.00, 500.00, '2026-07-29 16:35:41'),
(8, 85, 10, 'Friskies Indoor Delights Adult Dry Cat Food', NULL, 5, 100.00, 500.00, '2026-07-29 16:37:22'),
(9, 86, 7, 'Friskies Kitten Discoveries Kitten Dry Cat Food', NULL, 5, 100.00, 500.00, '2026-07-30 16:20:30'),
(10, 87, 34, 'Supercoat Adult Small Sensitive Skin Ocean Fish Dry Dog Food', NULL, 5, 100.00, 500.00, '2026-08-02 12:34:49'),
(11, 88, 32, 'Fancy Feast Savory Salmon Adult Wet Cat Food', NULL, 1, 100.00, 100.00, '2026-08-04 05:19:43'),
(12, 88, 33, 'Fancy Feast Grilled Tuna Feast Adult Wet Cat Food', NULL, 1, 100.00, 100.00, '2026-08-04 05:19:43'),
(13, 88, 37, 'Supercoat Chicken Adult Small Breed Dry Dog Food', NULL, 1, 100.00, 100.00, '2026-08-04 05:19:43'),
(14, 88, 31, 'FELIX As Good As It Looks Adult Chicken in Jelly Wet Cat Food (4.5KG)', NULL, 1, 1895.00, 1895.00, '2026-08-04 05:19:43'),
(15, 88, 38, 'Supercoat Chicken Adult Dry Dog Food', NULL, 1, 100.00, 100.00, '2026-08-04 05:19:43'),
(16, 89, 34, 'Supercoat Adult Small Sensitive Skin Ocean Fish Dry Dog Food', NULL, 2, 100.00, 200.00, '2026-08-04 08:01:53'),
(17, 89, 7, 'Friskies Kitten Discoveries Kitten Dry Cat Food', NULL, 1, 100.00, 100.00, '2026-08-04 08:01:53'),
(18, 89, 32, 'Fancy Feast Savory Salmon Adult Wet Cat Food', NULL, 1, 100.00, 100.00, '2026-08-04 08:01:53'),
(19, 89, 4, 'PURINA ONE® Active Kitten with Chicken Dry Cat Food', NULL, 1, 100.00, 100.00, '2026-08-04 08:01:53'),
(20, 90, 34, 'Supercoat Adult Small Sensitive Skin Ocean Fish Dry Dog Food', NULL, 5, 100.00, 500.00, '2026-08-21 12:32:53'),
(21, 91, 34, 'Supercoat Adult Small Sensitive Skin Ocean Fish Dry Dog Food', NULL, 1, 100.00, 100.00, '2026-08-22 10:26:51'),
(22, 92, 31, 'FELIX As Good As It Looks Adult Chicken in Jelly Wet Cat Food (340G)', NULL, 1, 185.00, 185.00, '2026-08-23 01:08:48');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_order_messages`
--

CREATE TABLE `tbl_order_messages` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `sender_role` enum('customer','admin','staff') NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_product_inventory`
--

CREATE TABLE `tbl_product_inventory` (
  `id` int(11) NOT NULL,
  `sku` varchar(100) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stock` int(11) NOT NULL DEFAULT 0,
  `category` varchar(100) DEFAULT 'General',
  `lifestage` varchar(100) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `food_type` varchar(100) DEFAULT NULL,
  `health_needs` varchar(100) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_product_inventory`
--

INSERT INTO `tbl_product_inventory` (`id`, `sku`, `name`, `description`, `price`, `stock`, `category`, `lifestage`, `brand`, `food_type`, `health_needs`, `image_url`, `created_at`, `updated_at`) VALUES
(4, 'Purina ONE®-001', 'PURINA ONE® Active Kitten with Chicken Dry Cat Food', 'PURINA ONE® Dry Cat Food is expertly combined with high quality ingredients to deliver a balanced nutrition &; a delicious taste that cats love.', 100.00, 64, 'Cat Food, Kitten, Dry Food, Purina ONE®', 'Puppy/Kitten', 'Purina ONE®', 'Dry Food', '', 'https://www.purina.ph/sites/default/files/2025-06/8850125085015_C1N1_0.webp', '2026-04-30 15:14:51', '2026-07-21 21:26:51'),
(7, 'Friskies-001', 'Friskies Kitten Discoveries Kitten Dry Cat Food', 'FRISKIES® Kitten DiscoveriesTM! With its delicious flavours of chicken, tuna, milk, vegetables and whole grains, it contains complete and balance nutrition to support development of strong bones and teeth and lean muscles.', 100.00, 94, 'Cat Food, Dry Food, Friskies', 'Puppy/Kitten', 'Friskies', 'Dry Food', '', 'https://www.purina.ph/sites/default/files/2025-06/8850125071360_C1N1_0.webp', '2026-04-30 16:22:58', NULL),
(9, 'FELIX-002', 'FELIX As Good As It Looks Kitten Tuna in Jelly Wet Cat Food', 'Mouth-watering tastes and textures, it really does taste as good as it looks\r\nPacked with essential vitamins and minerals\r\nA great way to keep your cat nourished through complete and balanced nutrition\r\nWe ensure our kitten food contains all the necessary proteins and minerals that are essential for the early development of your kitten', 100.00, 10, 'Cat Food, Kitten, Wet Food, FELIX', 'Puppy/Kitten', 'Felix', 'Wet Food', '', 'https://www.purina.ph/sites/default/files/2025-06/AGAIL_KITTEN%2520TUNA_FRONT_0.jpg', '2026-04-30 16:44:44', NULL),
(10, 'Friskies-002', 'Friskies Indoor Delights Adult Dry Cat Food', 'Salmon, tuna, chicken, beef and vegetables flavors\r\nReduces litter box odors\r\nComplete and balanced nutrition. Packed with protein, essential fatty acids, antioxidants and Vitamin A\r\nContains natural fiber to aid digestion and specially formulated formula to help control hairball formation', 100.00, 95, 'Cat Food, Adult (1 - 7), Dry Food, Indoor Cats, Friskies', 'Adult', 'Friskies', 'Dry Food', 'Indoor Cat', 'https://www.purina.ph/sites/default/files/2025-06/8850125073012_C1N1_0.webp', '2026-04-30 16:47:45', NULL),
(11, 'Purina ONE®-002', 'PURINA ONE® Indoor Advantage with Chicken Dry Cat Food', 'Natural With Added Vitamins, Minerals & Nutrients\r\nReal Chicken is #1 Ingredient\r\nHelps Maintain a Healthy Weight\r\nNatural Fiber Blend Minimizes Hairballs', 100.00, 8, 'Cat Food, Kitten, Dry Food, Purina ONE®', 'Adult', 'Purina ONE®', 'Dry Food', 'Indoor Cat', 'https://www.purina.ph/sites/default/files/2025-06/8850125078222_C1N1_0.jpg', '2026-04-30 16:49:02', NULL),
(16, 'FELIX-001', 'FELIX® Kitten Wet with Chicken in Jelly', '100% of your Kitten\'s daily needs\r\nTasty, meaty chunks with chicken in an irresistible jelly\r\nEnriched with Vitamins D & E\r\nA source of essential Omega 6 fatty acids\r\nThe right combination of balanced minerals', 100.00, 10, 'Cat Food, Kitten, Wet Food, FELIX', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-10/PH_en_PU_FELIX_New%20Website_ProdImg_1080px.png', '2026-05-05 08:41:11', NULL),
(17, 'Purina ONE®-003', 'PURINA ONE® Healthy Adult with Salmon & Tuna Dry Cat Food', 'PURINA ONE® Dry Cat Food is expertly combined with high quality ingredients to deliver a balanced nutrition &; a delicious taste that cats love. With PURINA ONE®, witness visible health differences in your cat in just 3 weeks. Formulated for house cats and made with real salmon and tuna as it\'s first ingredient, PURINA ONE® Healthy Adult with Salmon &; Tuna has a high fibre content to help limit hairball formation, and contains prebiotic fibres that minimise litter box odour. Give your cat a nutritious diet with reduced calories that helps maintain a healthy weight. Take the 3-Week Challenge today to see the visible health diffferences in your cat.\r\n\r\n \r\n\r\nNatural With Added Vitamins, Minerals & Nutrients\r\nReal Salmon & Tuna is #1 Ingredient\r\nHigh Protein Supports Kittens’ Growing Muscles\r\nSupports Vision & Brain Development', 100.00, 9, 'Cat Food, Adult (1 - 7), Dry Food, Purina ONE®', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/8850125001695_C1N1_0.jpg', '2026-05-05 08:43:38', NULL),
(21, 'Friskies-003', 'Friskies Meaty Grill Adult Dry Cat Food', 'Excite your meat-loving cats even more with FRISKIES® Meaty GrillsTM! Made with beef, chicken, lamb, turkey and vegetables, it contains protein, essential fatty acids, Omega 3 & 6, antioxidants and Vitamin A to maintain lean muscles and support a healthy skin and coat. It is also specially formulated to develop clear vision and a healthy immune so that she can always go on with her days happily!\r\n\r\n \r\n\r\nIn flavors of beef, chicken, lamb, turkey and vegetables\r\n100% complete and balanced nutrition for cats of all life stages\r\nStrong, lean muscles supported by high-quality protein\r\nPacked with protein, essential fatty acids, Omega 3 & 6, antioxidants and Vitamin A', 100.00, 8, 'Cat Food, Adult (1 - 7), Dry Food, Friskies', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/08850125072978_C1N1_0.webp', '2026-05-05 09:06:01', NULL),
(22, 'Friskies-004', 'Friskies Seafood Sensations Adult Dry Cat Food', 'Excite your seafood-loving cats even more with FRISKIES® Seafood Sensation! With its delicious flavours of tuna, salmon, whitefish, crab and shrimp, it contains protein, essential fatty acids, Omega 3 & 6, antioxidants and Vitamin A to maintain lean muscles and support a healthy skin and coat. It is also specially formulated to develop clear vision and a healthy immune so that she can always go on with her days happily!\r\n\r\n \r\n\r\nTuna, salmon, whitefish, crab, shrimp flavors\r\n100% complete and balanced nutrition for cats of all life stages\r\nStrong, lean muscles supported by high-quality protein\r\nPacked with protein, essential fatty acids, Omega 3 & 6, antioxidants and Vitamin A', 100.00, 8, 'Cat Food, Adult (1 - 7), Dry Food, Friskies', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/8850125072893_C1N1_0.webp', '2026-05-05 09:06:51', NULL),
(23, 'Friskies-005', 'Friskies Surfin\' Favorites\' Adult Dry Cat Food', 'Give your cats the best of land and sea together with FRISKIES® Surfin\' FavouritesTM! With flavours of mackerel, tuna, salmon and sardine, it contains protein, essential fatty acids, Omega 3 & 6, antioxidants and Vitamin A to maintain lean muscles and support a healthy skin and coat. It is also specially formulated to develop clear vision and a healthy immune so that she can always go on with her days happily!\r\n\r\n \r\n\r\nWith flavours of mackerel, tuna, salmon and sardine\r\n100% complete and balanced nutrition for cats of all life stages\r\nStrong, lean muscles supported by high-quality protein\r\nComplete and balanced nutrition. Packed with protein, essential fatty acids, Omega 3 & 6, antioxidants and Vitamin A', 100.00, 10, 'Cat Food, Adult (1 - 7), Dry Food, Friskies', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/8850125072930_C1N1_0.webp', '2026-05-05 09:07:46', NULL),
(24, 'Friskies-006', 'Friskies Party Mix Crunch Mixed Grill Adult Cat Treats', 'Cats are fired up! And they should be. Made with real chicken as the #1 ingredient and flavors of beef & salmon, this grillicious cat treat adds sizzle to every day – no spatula, oven mitt or apron required!\r\n\r\n \r\n\r\nReal chicken is the #1 Ingredient\r\nMade with flavors of beef & salmon\r\nLess than 2 calories per cat treat\r\nWith a crunchy texture that helps clean teeth\r\nComplete & balanced treats for adult cats', 100.00, 100, 'Cat Food, Adult (1 - 7), Treats, Friskies', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/MIXEDGRILL-1_1.png', '2026-05-05 09:08:27', '2026-07-21 21:26:39'),
(25, 'Friskies-007', 'Friskies Party Mix Crunch Classic Adult Cat Treats', 'Me-WOW! Made with real chicken as the #1 ingredient and flavors of tuna & bonito, it’s the deliciously crunchy cat treat that started it all. Every lip-licking crunchy bite lets your cat know loud and clear - it\'s time to party!\r\n\r\n \r\n\r\nReal chicken is the #1 Ingredient\r\nMade with flavors of tuna & bonito\r\nLess than 2 calories per cat treat\r\nWith a crunchy texture that helps clean teeth', 100.00, 5, 'Cat Food, Adult (1 - 7), Treats, Friskies', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/styles/search_result_large/public/2025-06/1_8.jpg.webp?itok=um5G9lRm', '2026-05-05 09:09:14', NULL),
(26, 'Friskies-008', 'Friskies Party Mix Crunch Beachside Adult Cat Treats', 'Surf\'s up! And the waves of deliciousness are rolling in with flavors as good as the ocean is deep. We’re talking real ocean whitefish as the #1 ingredient with flavors of shrimp, crab & tuna. Woah - that\'s one scrumptious cat treat.\r\n\r\n \r\n\r\nReal ocean whitefish is the #1 Ingredient\r\nMade with flavors of shrimp, crab & tuna\r\nLess than 2 calories per cat treat with a crunchy texture that helps clean teeth\r\nComplete & balanced treats for adult cats', 100.00, 2, 'Cat Food, Adult (1 - 7), Treats, Friskies', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/partymix_0.jpg', '2026-05-05 09:09:48', NULL),
(27, 'FELIX-003', 'FELIX As Good As It Looks Adult Sardine in Jelly Wet Cat Food', 'We know cats love fish, and with our FELIX As Good As It Looks Adult Cat Sardine in Jelly that\'s packed with delicious fishy flavours, it really does taste as good as it looks!\r\n\r\n \r\n\r\nWe use high quality ingredients to provide your cat with all they need to maintain a healthy lifestyle, and our added vitamins and minerals ensure their natural defences are supported too!\r\n\r\n \r\n\r\nWe\'re sure your cat will love the tasty flavours of sardine in this easy to serve pouch, especially as the sardine is combined with a smooth, silky jelly that your cat can enjoy.\r\n\r\n \r\n\r\nMouth-watering tastes and textures, it really does taste as good as it looks\r\nPacked with essential vitamins and minerals\r\nA great way to keep your cat nourished through complete and balanced nutrition\r\nDelicious flavour including sardine', 100.00, 100, 'Cat Food, Adult (1 - 7), Wet Food, FELIX', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/styles/search_result_large/public/2025-06/AGAIL_ADULT%2520SARDINE_FRONT_0.jpg.webp?itok=TsANnXjM', '2026-05-05 09:13:18', NULL),
(28, 'FELIX-004', 'FELIX As Good As It Looks Adult Salmon in Jelly Wet Cat Food', 'We know cats love fish, and with our FELIX As Good As It Looks Adult Cat Salmon in Jelly that\'s packed with delicious fishy flavours, it really does taste as good as it looks!\r\n\r\n \r\n\r\nWe use high quality ingredients to provide your cat with all they need to maintain a healthy lifestyle, and our added vitamins and minerals ensure their natural defences are supported too!\r\n\r\n \r\n\r\nWe\'re sure your cat will love the tasty flavours of salmon in this easy to serve pouch, especially as the salmon is combined with a smooth, silky jelly that your cat can enjoy.\r\n\r\n \r\n\r\nMouth-watering tastes and textures, it really does taste as good as it looks\r\nPacked with essential vitamins and minerals\r\nA great way to keep your cat nourished through complete and balanced nutrition\r\nDelicious flavour including salmon', 100.00, 10, 'Cat Food, Adult (1 - 7), Wet Food, FELIX', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/styles/search_result_large/public/2025-06/AGAIL_SALMON_FRONT_0.jpg.webp?itok=K8crDDRv', '2026-05-05 09:14:00', NULL),
(29, 'FELIX-005', 'FELIX As Good As It Looks Adult Mackerel in Jelly Wet Cat Food', 'We know cats love fish, and with our FELIX As Good As It Looks Adult Cat Mackerel in Jelly that\'s packed with delicious fishy flavours, it really does taste as good as it looks!\r\n\r\n \r\n\r\nWe use high quality ingredients to provide your cat with all they need to maintain a healthy lifestyle, and our added vitamins and minerals ensure their natural defences are supported too!\r\n\r\n \r\n\r\nWe\'re sure your cat will love the tasty flavours of mackerel in this easy to serve pouch, especially as the mackerel is combined with a smooth, silky jelly that your cat can enjoy.\r\n\r\n \r\n\r\nMouth-watering tastes and textures, it really does taste as good as it looks\r\nPacked with essential vitamins and minerals\r\nA great way to keep your cat nourished through complete and balanced nutrition\r\nDelicious flavour including mackerel', 100.00, 40, 'Cat Food, Adult (1 - 7), Wet Food, FELIX', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/AGAIL_ADULT%2520MACHEREL_FRONT_2.jpg', '2026-05-05 09:14:59', NULL),
(30, 'FELIX-006', 'FELIX As Good As It Looks Adult Tuna in Jelly Wet Cat Food', 'We know cats love fish, and with our FELIX As Good As It Looks Adult Cat Tuna in Jelly that\'s packed with delicious fishy flavours, it really does taste as good as it looks!\r\n\r\n \r\n\r\nWe use high quality ingredients to provide your cat with all they need to maintain a healthy lifestyle, and our added vitamins and minerals ensure their natural defences are supported too!\r\n\r\n \r\n\r\nWe\'re sure your cat will love the tasty flavours of tuna in this easy to serve pouch, especially as the tuna is combined with a smooth, silky jelly that your cat can enjoy.\r\n\r\n \r\n\r\nMouth-watering tastes and textures, it really does taste as good as it looks\r\nPacked with essential vitamins and minerals\r\nA great way to keep your cat nourished through complete and balanced nutrition\r\nDelicious flavour including tuna', 100.00, 9, 'Cat Food, Adult (1 - 7), Wet Food, FELIX', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/AGAIL_ADULT%2520TUNA_FRONT_0.jpg', '2026-05-05 09:15:45', NULL),
(31, 'FELIX-007', 'FELIX As Good As It Looks Adult Chicken in Jelly Wet Cat Food', 'With tender pieces of chicken making our FELIX wet cat food utterly irresistible, we\'re sure your cat will love this delicious dish! FELIX As Good As It Looks Adult Cat Chicken in Jelly Wet Food is a single pouch packed with essential nutrients and key ingredients to help your adult cat maintain a healthy and active lifestyle. Our vets and nutritionists have specially formulated this mouth-watering meal to ensure your cat gets all he needs from his daily diet.\r\n\r\n \r\n\r\nMouth-watering tastes and textures, it really does taste as good as it looks\r\nPacked with essential vitamins and minerals\r\nA great way to keep your cat nourished through complete and balanced nutrition\r\nDelicious flavour including chicken', 185.00, 49, 'Cat Food, Adult (1 - 7), Wet Food, FELIX', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/AGAIL_ADULT%2520CHICKEN_FRONT_0.jpg', '2026-05-05 09:16:42', '2026-08-01 00:54:14'),
(32, 'Fancy Feast-001', 'Fancy Feast Savory Salmon Adult Wet Cat Food', 'Just like dry cat food, wet cat food offers balanced nutrition. Wet cat food also helps provide additional moisture to balanced nutrition, because of the delicious juices and savory gravy in each entrée. Fancy Feast wet cat food entrées are complete and balanced, and rich in protein - made with high-quality ingredients, from real beef, poultry and seafood to cheddar cheese, garden veggies and egg (depending on the recipe).\r\n\r\n \r\n\r\nMade in the USA\r\nComplete & balanced nutrition\r\nSmooth paté made with salmon\r\nConvenient flip top can makes feeding quick & easy', 100.00, 93, 'Cat Food, Adult (1 - 7), Wet Food, Fancy Feast', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/29%20FANCY%20FEAST%20SAVORY%20SALMON%2085G_0.jpg', '2026-05-05 13:13:32', NULL),
(33, 'Fancy Feast-002', 'Fancy Feast Grilled Tuna Feast Adult Wet Cat Food', 'Every Fancy Feast selection is made with high-quality protein sources and ingredients. This Grilled Feast is a luscious, slow-cooked feast of tuna and chicken with a sumptuous basting of gravy.\r\n\r\n \r\n\r\nMade in the USA\r\nComplete & balanced nutrition\r\nDelicious entree of succulent pieces\r\nA great way to add moisture to your cat’s diet', 100.00, 99, 'Cat Food, Adult (1 - 7), Wet Food, Fancy Feast', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/styles/search_result_narrow/public/2025-06/3520FEAST20TUNA2085G_0.jpg.webp?itok=ZjMPFgQA', '2026-05-05 13:14:23', NULL),
(34, 'SUPERCOAT®-001', 'Supercoat Adult Small Sensitive Skin Ocean Fish Dry Dog Food', 'Supercoat provides tailored nutrition specially formulated for Small Breed Dogs. Developed with Smartblend® technology, a precise combination of high-quality ingredients blended with 21 essential vitamins & minerals to cater to the unique needs of small dogs. Supercoat contains higher* protein & fat levels to keep up with small dogs\' high metabolism level, formulated with calcium for stronger bones and teeth and is sized perfectly to suit their small jaws. Comes in variants to suit all lifestages with no added artificial colours or flavours.\r\n\r\n \r\n\r\n*vs Supercoat All Breed Range\r\n\r\n \r\n\r\nDeveloped with Smartblend® technology, a combination of high-quality ingredients to meet the unique needs of small dogs\r\nHigher* protein & fat levels to keep up with your Small Dog’s high metabolism level. (vs Supercoat All Breed Range)\r\nPerfectly szied kibble to suit smaller jaws while being nutrient dense to nourish small dogs with everything they need\r\nFormulated with calcium for stronger bones & teeth', 100.00, 83, 'Dog Food, Adult (1 - 7), Dry Food, Sensitive Skin, SUPERCOAT®', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/1_6.jpg', '2026-07-21 11:36:15', '2026-07-29 13:59:01'),
(35, 'SUPERCOAT®-002', 'Supercoat Adult Small Breed Tuna Dry Dog Food', 'Supercoat provides tailored nutrition specially formulated for Small Breed Dogs. Developed with Smartblend® technology, a precise combination of high-quality ingredients blended with 21 essential vitamins & minerals to cater to the unique needs of small dogs. Supercoat contains higher* protein & fat levels to keep up with small dogs\' high metabolism level, formulated with calcium for stronger bones and teeth and is sized perfectly to suit their small jaws. Comes in variants to suit all lifestages with no added artificial colours or flavours.     *vs Supercoat All Breed Range     Developed with Smartblend® technology, a combination of high-quality ingredients to meet the unique needs of small dogs Higher* protein & fat levels to keep up with your Small Dog’s high metabolism level. (vs Supercoat All Breed Range) Perfectly sized kibble to suit smaller jaws while being nutrient dense to nourish small dogs with everything they need Formulated with calcium for stronger bones & teeth', 100.00, 100, 'Dog Food, Adult (1 - 7), Dry Food, Small Breeds, SUPERCOAT®', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/1_4.jpg', '2026-07-21 11:59:38', '2026-07-21 21:25:27'),
(36, 'SUPERCOAT®-003', 'Supercoat Puppy Small Breed Chicken Dry Dog Food', 'Supercoat provides tailored nutrition specially formulated for Small Breed Dogs. Developed with Smartblend® technology, a precise combination of high-quality ingredients blended with 21 essential vitamins & minerals to cater to the unique needs of small dogs. Supercoat contains higher* protein & fat levels to keep up with small dogs\' high metabolism level, formulated with calcium for stronger bones and teeth and is sized perfectly to suit their small jaws. Comes in variants to suit all lifestages with no added artificial colours or flavours.\r\n\r\n \r\n\r\n*vs Supercoat All Breed Range\r\n\r\n \r\n\r\nDeveloped with Smartblend® technology, a combination of high-quality ingredients to meet the unique needs of small dogs\r\nHigher* protein & fat levels to keep up with your Small Dog’s high metabolism level. (vs Supercoat All Breed Range)\r\nPerfectly sized kibble to suit smaller jaws while being nutrient dense to nourish small dogs with everything they need\r\nFormulated with calcium for stronger bones & teeth', 100.00, 100, 'Dog Food, Puppy, Dry Food, Small Breeds, SUPERCOAT®', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/1_1.webp', '2026-07-21 14:45:05', NULL),
(37, 'SUPERCOAT®-004', 'Supercoat Chicken Adult Small Breed Dry Dog Food', 'Specifically formulated for small breed dogs, up to 10kg. SUPERCOAT® SMARTBLEND® Adult Small Breed with Real Chicken provides complete and balanced nutrition, with no artificial colors or flavors, to help keep your dog happy and healthy\r\n\r\n \r\n\r\nSpecifically formulated for small breed dogs, up to 10kg\r\nProvides complete and balanced nutrition, with no artificial colours or flavours, to help keep your dog happy and healthy\r\nMade with Real Chicken\r\nTailored nutrition for your dog’s overall health to bring out his active best', 100.00, 99, 'Dog Food, Adult (1 - 7), Dry Food, SUPERCOAT®', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/2.7kg_Chicken%2520Front_0.jpg', '2026-07-21 14:46:17', NULL),
(38, 'SUPERCOAT®-005', 'Supercoat Chicken Adult Dry Dog Food', 'Specifically formulated for adult dogs 1 to 7 years. SUPERCOAT® SMARTBLEND® Adult With Real Chicken provides complete and balanced nutrition, with no artificial colours or flavours, to help keep your dog happy and healthy.\r\n\r\n \r\n\r\nSpecifically formulated for adult dogs from 1 to 7 years of age\r\nProvides complete and balanced nutrition, with no artificial colours or flavours, to help keep your dog happy and healthy\r\nMade with Real Chicken\r\nProtein-rich food for the muscle health of your dog', 100.00, 99, 'Dog Food, Adult (1 - 7), Dry Food, SUPERCOAT®', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/1_0_0.webp', '2026-07-21 14:48:25', NULL),
(42, 'SUPERCOAT®-006', 'Supercoat Beef Adult Dry Dog Food', 'Specifically formulated for adult dogs 1 to 7 years. SUPERCOAT® SMARTBLEND® Adult With Real Beef provides complete and balanced nutrition, with no artificial colours or flavours, to help keep your dog happy and healthy.\r\n\r\n \r\n\r\nSpecifically formulated for adult dogs 1 to 7 years\r\nProvides complete and balanced nutrition, with no artificial colours or flavours, to help keep your dog happy and healthy\r\nMade with Real Beef\r\nContains omega 3 and 6 fatty acids, vitamins ensure that your dog has healthy skin and a shiny coat', 100.00, 100, 'Dog Food, Adult (1 - 7), Dry Food, SUPERCOAT®', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/1_0.webp', '2026-07-21 14:57:06', NULL),
(43, 'SUPERCOAT®-007', 'Supercoat Chicken Puppy All Breed Dry Dog Food', 'Specifically formulated for puppies up to 12 months. SUPERCOAT® SMARTBLEND® Puppy With Real Meat provides complete and balanced nutrition, with no artificial colors or flavors, to help give your puppy a healthy start to life.\r\n\r\n \r\n\r\nSpecifically formulated for puppies up to 12 months\r\nContains chicken that provides complete and balanced nutrition, with no artificial colours or flavours, to help give your puppy a healthy start to life\r\nMade with Chicken\r\nHelps to develop support natural defences with high levels of Vitamin C&E', 100.00, 100, 'Dog Food, Puppy, Dry Food, SUPERCOAT®', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/11_0.png', '2026-07-21 14:58:17', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_product_reviews`
--

CREATE TABLE `tbl_product_reviews` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL,
  `review_text` text DEFAULT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_product_reviews`
--

INSERT INTO `tbl_product_reviews` (`id`, `order_id`, `product_id`, `customer_id`, `rating`, `review_text`, `is_approved`, `created_at`) VALUES
(1, 83, 4, 1, 5, 'my cats like these products thankyou, seller', 1, '2026-07-31 17:11:32');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_product_variants`
--

CREATE TABLE `tbl_product_variants` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `size` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_product_variants`
--

INSERT INTO `tbl_product_variants` (`id`, `product_id`, `size`, `price`, `stock`) VALUES
(4, 31, '340G', 185.00, 50),
(5, 31, '1.5KG', 695.00, 50),
(6, 31, '4.5KG', 1895.00, 50);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_rider_remittances`
--

CREATE TABLE `tbl_rider_remittances` (
  `id` int(11) NOT NULL,
  `rider_id` int(11) NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL COMMENT 'Reason for rejection, etc.',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `remitted_at` timestamp NULL DEFAULT NULL,
  `processed_by_user_id` int(11) DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_rider_remittance_items`
--

CREATE TABLE `tbl_rider_remittance_items` (
  `id` int(11) NOT NULL,
  `remittance_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_rider_status`
--

CREATE TABLE `tbl_rider_status` (
  `id` int(11) NOT NULL,
  `rider_id` int(11) NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `last_update` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `rider_name` varchar(100) DEFAULT 'E-Bike Delivery Rider'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_rider_status`
--

INSERT INTO `tbl_rider_status` (`id`, `rider_id`, `is_available`, `last_update`, `rider_name`) VALUES
(1, 0, 1, '2026-08-12 08:40:12', 'E-Bike Rider');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_supplier_deliveries`
--

CREATE TABLE `tbl_supplier_deliveries` (
  `id` int(11) NOT NULL,
  `supplier_email` varchar(255) DEFAULT NULL,
  `supplier_name` varchar(255) NOT NULL,
  `items_summary` text NOT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `status` enum('pending','in_transit','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `expected_date` date DEFAULT NULL,
  `received_date` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_supplier_deliveries`
--

INSERT INTO `tbl_supplier_deliveries` (`id`, `supplier_email`, `supplier_name`, `items_summary`, `tracking_number`, `status`, `expected_date`, `received_date`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'elaisareinebelandres09@gmail.com', 'CatLittersLovers', '50x Cat Litter 7kg', '112143112143', 'cancelled', '2026-08-09', NULL, '', '2026-08-03 16:58:17', '2026-08-04 01:03:15'),
(2, 'elaisareinebelandres09@gmail.com', 'CatLittersLovers', '50x Cat Litter 7kg', '112143112143', 'pending', '2026-08-09', NULL, '', '2026-08-03 17:03:05', NULL);

-- --------------------------------------------------------

--
-- Stand-in structure for view `tbl_user_accounts`
-- (See below for the actual view)
--
CREATE TABLE `tbl_user_accounts` (
`id` int(11)
,`username` varchar(255)
,`first_name` varchar(255)
,`last_name` varchar(255)
,`role` enum('admin','customer','rider','staff')
,`email` varchar(255)
,`password` varchar(255)
,`reset_token` varchar(255)
,`reset_expiry` datetime
,`password_reset_at` datetime
,`login_attempts` int(11)
,`lock_until` datetime
);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_vouchers`
--

CREATE TABLE `tbl_vouchers` (
  `id` int(11) NOT NULL,
  `code` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `discount_type` enum('fixed','percent') NOT NULL DEFAULT 'fixed',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `min_order_amount` decimal(10,2) DEFAULT NULL,
  `usage_limit` int(11) DEFAULT NULL,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_vouchers`
--

INSERT INTO `tbl_vouchers` (`id`, `code`, `description`, `discount_type`, `discount_value`, `min_order_amount`, `usage_limit`, `used_count`, `active`, `expires_at`, `created_at`, `updated_at`) VALUES
(1, 'V-3DDFCB8589D5', '₱100', 'fixed', 100.00, NULL, 1, 0, 0, NULL, '2026-07-17 12:44:53', NULL),
(3, 'V-F6E477D06DC8', 'Free Treat Pack', 'fixed', 0.00, NULL, 1, 0, 0, '2026-08-05 21:57:48', '2026-07-29 13:57:48', NULL),
(4, 'V-BBA197E1ADE1', '₱25 Discount Voucher', 'fixed', 25.00, NULL, 1, 0, 0, '2026-08-29 00:36:23', '2026-07-29 16:36:23', NULL),
(5, 'V-DD818CEFFF3F', '₱25 Discount Voucher', 'fixed', 25.00, NULL, 1, 0, 0, '2026-08-29 00:50:03', '2026-07-29 16:50:03', NULL),
(6, 'V-6AF68E60774A', '₱25 Discount Voucher', 'fixed', 25.00, NULL, 1, 0, 0, '2026-08-30 00:21:21', '2026-07-30 16:21:21', NULL),
(7, 'V-018CFACF75EF', '₱25 Discount Voucher', 'fixed', 25.00, NULL, 1, 0, 0, '2026-09-03 15:19:41', '2026-08-04 07:19:41', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `role` enum('admin','customer','rider','staff') NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL,
  `password_reset_at` datetime DEFAULT NULL,
  `login_attempts` int(11) DEFAULT 0,
  `lock_until` datetime DEFAULT NULL,
  `otp_code` varchar(6) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `first_name`, `last_name`, `role`, `email`, `password`, `reset_token`, `reset_expiry`, `password_reset_at`, `login_attempts`, `lock_until`, `otp_code`, `otp_expiry`) VALUES
(1, 'ereine', 'elaisa', 'belandres', 'admin', 'elaisareinebelandres09@gmail.com', '$2y$10$mgRuQdxTj4QSvXGMC.hl1uOBNgwrL0hfqbYNvoHwDVrE.rrT.6dKq', NULL, NULL, '2026-06-03 23:01:40', 0, NULL, NULL, NULL),
(2, 'dom', 'dominic', 'vega', 'staff', 'vega@gmail.com', '$2y$10$vNh5gfJB9HNtp27GM0b3QemkLdMuJOSEBrQmkw1bTuDTgOn9pOt0q', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(3, 'regino00', 'rogin', 'mayores', 'staff', 'reginomayores09@gmail.com', '$2y$10$6zrWY7tLHYwH0oLVqfd3SeGcH3n2NWbU4LPGxgiMAHPgwL3u1UWXm', NULL, NULL, NULL, 0, NULL, NULL, NULL),
(6, 'rider1', '', '', 'rider', 'testrider1@gmail.com', '$2y$10$mUnV9fB1f6OWwMzCTPDLf.wGxYZ0hip7.24nq.RME1zVj.uzyZ4sC', NULL, NULL, NULL, 0, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Structure for view `tbl_customer_records`
--
DROP TABLE IF EXISTS `tbl_customer_records`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `tbl_customer_records`  AS SELECT `customers`.`id` AS `id`, `customers`.`name` AS `name`, `customers`.`email` AS `email`, `customers`.`phone` AS `phone`, `customers`.`gender` AS `gender`, `customers`.`age` AS `age`, `customers`.`address` AS `address`, `customers`.`loyalty_points` AS `loyalty_points`, `customers`.`created_at` AS `created_at` FROM `customers` ;

-- --------------------------------------------------------

--
-- Structure for view `tbl_user_accounts`
--
DROP TABLE IF EXISTS `tbl_user_accounts`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `tbl_user_accounts`  AS SELECT `users`.`id` AS `id`, `users`.`username` AS `username`, `users`.`first_name` AS `first_name`, `users`.`last_name` AS `last_name`, `users`.`role` AS `role`, `users`.`email` AS `email`, `users`.`password` AS `password`, `users`.`reset_token` AS `reset_token`, `users`.`reset_expiry` AS `reset_expiry`, `users`.`password_reset_at` AS `password_reset_at`, `users`.`login_attempts` AS `login_attempts`, `users`.`lock_until` AS `lock_until` FROM `users` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `customer_addresses`
--
ALTER TABLE `customer_addresses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `customer_login_credentials`
--
ALTER TABLE `customer_login_credentials`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_customer_id` (`customer_id`);

--
-- Indexes for table `customer_orders`
--
ALTER TABLE `customer_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `customer_order_items`
--
ALTER TABLE `customer_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `delivery_tracking`
--
ALTER TABLE `delivery_tracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `rider_id` (`rider_id`);

--
-- Indexes for table `loyalty_transactions`
--
ALTER TABLE `loyalty_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user_read_created` (`user_id`,`is_read`,`created_at`),
  ADD KEY `idx_notifications_customer_read_created` (`customer_id`,`is_read`,`created_at`),
  ADD KEY `idx_notifications_status_channel` (`delivery_status`,`channel`),
  ADD KEY `idx_notifications_type_created` (`type`,`created_at`),
  ADD KEY `idx_notifications_reference` (`reference_table`,`reference_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rewards`
--
ALTER TABLE `rewards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reward_code` (`reward_code`);

--
-- Indexes for table `reward_redemptions`
--
ALTER TABLE `reward_redemptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `reward_code` (`reward_code`);

--
-- Indexes for table `riders`
--
ALTER TABLE `riders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `rider_remittances`
--
ALTER TABLE `rider_remittances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rider_id` (`rider_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `tbl_cart`
--
ALTER TABLE `tbl_cart`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_customer_product` (`customer_id`,`product_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `variant_id` (`variant_id`);

--
-- Indexes for table `tbl_custom_categories`
--
ALTER TABLE `tbl_custom_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_cat` (`group_name`,`category_value`);

--
-- Indexes for table `tbl_deliveries`
--
ALTER TABLE `tbl_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `tbl_delivery`
--
ALTER TABLE `tbl_delivery`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `rider_id` (`rider_id`);

--
-- Indexes for table `tbl_messages`
--
ALTER TABLE `tbl_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `tbl_orders`
--
ALTER TABLE `tbl_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `fk_order_rider` (`rider_id`);

--
-- Indexes for table `tbl_order_items`
--
ALTER TABLE `tbl_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `tbl_order_messages`
--
ALTER TABLE `tbl_order_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `tbl_product_inventory`
--
ALTER TABLE `tbl_product_inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`);

--
-- Indexes for table `tbl_product_reviews`
--
ALTER TABLE `tbl_product_reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `tbl_product_variants`
--
ALTER TABLE `tbl_product_variants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_product_size` (`product_id`,`size`);

--
-- Indexes for table `tbl_rider_remittances`
--
ALTER TABLE `tbl_rider_remittances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rider_id` (`rider_id`),
  ADD KEY `processed_by_user_id` (`processed_by_user_id`);

--
-- Indexes for table `tbl_rider_remittance_items`
--
ALTER TABLE `tbl_rider_remittance_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_remittance_order` (`remittance_id`,`order_id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `tbl_supplier_deliveries`
--
ALTER TABLE `tbl_supplier_deliveries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tbl_vouchers`
--
ALTER TABLE `tbl_vouchers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customer_addresses`
--
ALTER TABLE `customer_addresses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customer_login_credentials`
--
ALTER TABLE `customer_login_credentials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customer_orders`
--
ALTER TABLE `customer_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customer_order_items`
--
ALTER TABLE `customer_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `delivery_tracking`
--
ALTER TABLE `delivery_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loyalty_transactions`
--
ALTER TABLE `loyalty_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rewards`
--
ALTER TABLE `rewards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `reward_redemptions`
--
ALTER TABLE `reward_redemptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `riders`
--
ALTER TABLE `riders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `rider_remittances`
--
ALTER TABLE `rider_remittances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_cart`
--
ALTER TABLE `tbl_cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=122;

--
-- AUTO_INCREMENT for table `tbl_custom_categories`
--
ALTER TABLE `tbl_custom_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tbl_deliveries`
--
ALTER TABLE `tbl_deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_delivery`
--
ALTER TABLE `tbl_delivery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `tbl_messages`
--
ALTER TABLE `tbl_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_orders`
--
ALTER TABLE `tbl_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=93;

--
-- AUTO_INCREMENT for table `tbl_order_items`
--
ALTER TABLE `tbl_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `tbl_order_messages`
--
ALTER TABLE `tbl_order_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `tbl_product_inventory`
--
ALTER TABLE `tbl_product_inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `tbl_product_reviews`
--
ALTER TABLE `tbl_product_reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_product_variants`
--
ALTER TABLE `tbl_product_variants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tbl_rider_remittances`
--
ALTER TABLE `tbl_rider_remittances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_rider_remittance_items`
--
ALTER TABLE `tbl_rider_remittance_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_supplier_deliveries`
--
ALTER TABLE `tbl_supplier_deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tbl_vouchers`
--
ALTER TABLE `tbl_vouchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `customer_addresses`
--
ALTER TABLE `customer_addresses`
  ADD CONSTRAINT `customer_addresses_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_login_credentials`
--
ALTER TABLE `customer_login_credentials`
  ADD CONSTRAINT `customer_login_credentials_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_orders`
--
ALTER TABLE `customer_orders`
  ADD CONSTRAINT `customer_orders_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_order_items`
--
ALTER TABLE `customer_order_items`
  ADD CONSTRAINT `customer_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `customer_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `delivery_tracking`
--
ALTER TABLE `delivery_tracking`
  ADD CONSTRAINT `delivery_tracking_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `tbl_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delivery_tracking_ibfk_2` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `loyalty_transactions`
--
ALTER TABLE `loyalty_transactions`
  ADD CONSTRAINT `loyalty_transactions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `loyalty_transactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);

--
-- Constraints for table `reward_redemptions`
--
ALTER TABLE `reward_redemptions`
  ADD CONSTRAINT `reward_redemptions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `reward_redemptions_ibfk_3` FOREIGN KEY (`reward_code`) REFERENCES `rewards` (`reward_code`);

--
-- Constraints for table `riders`
--
ALTER TABLE `riders`
  ADD CONSTRAINT `riders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_cart`
--
ALTER TABLE `tbl_cart`
  ADD CONSTRAINT `tbl_cart_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_10` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_100` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_101` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_102` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_103` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_104` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_105` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_106` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_107` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_108` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_109` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_11` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_110` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_111` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_112` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_113` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_114` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_115` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_116` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_117` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_118` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_119` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_12` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_120` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_121` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_122` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_123` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_124` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_125` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_126` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_127` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_128` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_129` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_13` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_130` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_131` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_132` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_133` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_134` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_135` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_136` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_137` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_138` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_139` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_14` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_140` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_141` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_142` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_143` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_144` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_145` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_146` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_147` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_148` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_149` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_15` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_150` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_151` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_152` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_153` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_154` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_155` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_156` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_157` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_158` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_159` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_16` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_160` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_161` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_162` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_163` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_164` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_165` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_166` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_167` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_168` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_169` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_17` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_170` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_171` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_172` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_173` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_174` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_175` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_176` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_177` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_178` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_179` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_18` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_180` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_181` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_182` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_183` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_184` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_185` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_186` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_187` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_188` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_189` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_19` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_190` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_191` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_192` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_193` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_194` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_195` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_196` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_197` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_198` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_199` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `tbl_product_inventory` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_20` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_200` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_201` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_202` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_203` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_204` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_205` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_206` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_207` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_208` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_209` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_21` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_210` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_211` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_212` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_213` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_214` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_215` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_216` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_217` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_218` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_219` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_22` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_220` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_221` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_222` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_223` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_224` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_225` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_226` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_227` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_228` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_229` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_23` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_230` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_231` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_232` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_233` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_234` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_235` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_236` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_237` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_238` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_239` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_24` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_240` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_241` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_242` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_243` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_244` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_245` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_246` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_247` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_248` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_249` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_25` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_250` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_251` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_252` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_253` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_254` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_255` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_256` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_257` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_258` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_259` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_26` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_260` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_261` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_262` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_263` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_264` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_265` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_266` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_267` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_268` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_269` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_27` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_270` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_271` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_272` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_273` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_274` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_275` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_276` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_277` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_278` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_279` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_28` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_280` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_281` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_282` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_283` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_284` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_285` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_286` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_287` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_288` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_289` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_29` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_290` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_291` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_292` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_293` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_294` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_295` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_296` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_297` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_298` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_299` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_3` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_30` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_300` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_301` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_302` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_303` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_304` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_305` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_306` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_307` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_308` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_309` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_31` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_310` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_311` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_312` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_313` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_314` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_315` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_316` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_317` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_318` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_319` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_32` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_320` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_321` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_322` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_323` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_324` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_325` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_326` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_327` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_328` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_329` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_33` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_330` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_331` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_332` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_333` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_334` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_335` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_336` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_337` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_338` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_339` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_34` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_340` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_341` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_342` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_343` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_344` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_345` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_346` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_347` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_348` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_349` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_35` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_350` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_351` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_352` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_353` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_354` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_355` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_356` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_357` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_358` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_359` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_36` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_360` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_361` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_362` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_363` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_364` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_365` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_366` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_367` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_368` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_369` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_37` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_370` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_371` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_372` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_373` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_374` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_375` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_376` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_377` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_378` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_379` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_38` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_380` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_381` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_382` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_383` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_384` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_385` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_386` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_387` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_388` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_389` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_39` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_390` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_391` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_392` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_393` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_394` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_395` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_396` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_397` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_398` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_399` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_4` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_40` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_400` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_401` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_402` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_403` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_404` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_405` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_406` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_407` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_408` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_409` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_41` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_410` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_411` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_412` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_413` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_414` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_415` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_416` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_417` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_418` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_419` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_42` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_420` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_421` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_422` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_423` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_424` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_425` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_426` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_427` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_428` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_429` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_43` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_430` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_431` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_432` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_433` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_434` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_435` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_436` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_437` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_438` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_439` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_44` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_440` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_441` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_442` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_443` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_444` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_445` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_446` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_447` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_448` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_449` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_45` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_450` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_451` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_452` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_453` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_454` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_455` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_456` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_457` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_458` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_459` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_46` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_460` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_461` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_462` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_463` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_464` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_465` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_466` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_467` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_468` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_469` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_47` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_470` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_471` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_472` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_473` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_474` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_475` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_476` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_477` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_478` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_479` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_48` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_480` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_481` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_482` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_483` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_484` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_485` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_486` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_487` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_488` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_489` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_49` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_490` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_491` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_492` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_493` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_494` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_495` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_496` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_497` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_498` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_499` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_5` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_50` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_500` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_501` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_502` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_503` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_504` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_505` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_506` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_507` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_508` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_509` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_51` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_510` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_511` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_512` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_513` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_514` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_515` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_516` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_517` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_518` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_519` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_52` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_520` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_521` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_522` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_523` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_524` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_525` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_526` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_527` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_528` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_529` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_53` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_530` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_531` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_532` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_533` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_534` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_535` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_536` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_537` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_538` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_539` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_54` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_540` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_541` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_542` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_543` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_544` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_545` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_546` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_547` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_548` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_549` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_55` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_550` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_551` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_552` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_553` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_554` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_555` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_556` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_557` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_558` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_559` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_56` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_560` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_561` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_562` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_563` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_564` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_565` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_566` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_567` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_568` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_569` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_57` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_570` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_571` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_572` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_573` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_574` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_575` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_576` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_577` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_578` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_579` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_58` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_580` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_581` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_582` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_583` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_584` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_585` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_586` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_587` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_588` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_589` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_59` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_590` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_591` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_592` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_593` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_594` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_595` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_596` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_597` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_598` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_599` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_6` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_60` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_600` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_601` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_602` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_603` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_604` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_605` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_606` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_607` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_608` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_609` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_61` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_610` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_611` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_612` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_613` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_614` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_615` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_616` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_617` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_618` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_619` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_62` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_620` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_621` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_622` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_623` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_624` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_625` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_626` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_627` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_628` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_629` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_63` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_630` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_631` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_632` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_633` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_634` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_635` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_636` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_637` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_638` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_639` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_64` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_640` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_641` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_642` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_643` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_644` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_645` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_646` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_647` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_648` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_649` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_65` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_650` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_651` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_652` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_653` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_654` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_655` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_656` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_657` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_658` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_659` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_66` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_660` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_661` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_662` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_663` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_664` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_665` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_666` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_667` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_668` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_669` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_67` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_670` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_671` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_672` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_673` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_674` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_675` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_676` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_677` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_678` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_679` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_68` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_680` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_681` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_682` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_683` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_684` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_685` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_686` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_687` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_688` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_689` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_69` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_690` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_691` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_692` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_693` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_694` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_695` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_696` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_697` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_698` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_699` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_7` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_70` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_700` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_701` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_702` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_703` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_704` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_705` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_706` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_707` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_708` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_709` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_71` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_710` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_711` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_712` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_713` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_714` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_715` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_716` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_717` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_718` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_719` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_72` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_720` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_721` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_722` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_723` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_724` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_725` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_726` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_727` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_728` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_729` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_73` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_730` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_731` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_732` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_733` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_734` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_735` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_736` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_737` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_738` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_739` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_74` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_740` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_741` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_742` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_743` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_744` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_745` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_746` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_747` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_748` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_749` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_75` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_750` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_751` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_752` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_753` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_754` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_755` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_756` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_757` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_758` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_759` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_76` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_760` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_761` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_762` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_763` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_764` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_765` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_766` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_767` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_768` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_769` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_77` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_770` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_771` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_772` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_773` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_774` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_775` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_776` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_777` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_78` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_79` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_8` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_80` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_81` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_82` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_83` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_84` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_85` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_86` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_87` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_88` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_89` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_9` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_90` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_91` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_92` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_93` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_94` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_95` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_96` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_97` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_98` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_99` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_deliveries`
--
ALTER TABLE `tbl_deliveries`
  ADD CONSTRAINT `tbl_deliveries_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `tbl_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_delivery`
--
ALTER TABLE `tbl_delivery`
  ADD CONSTRAINT `tbl_delivery_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `tbl_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_delivery_ibfk_2` FOREIGN KEY (`rider_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `tbl_orders`
--
ALTER TABLE `tbl_orders`
  ADD CONSTRAINT `fk_order_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tbl_orders_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_order_items`
--
ALTER TABLE `tbl_order_items`
  ADD CONSTRAINT `tbl_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `tbl_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `tbl_product_inventory` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_order_messages`
--
ALTER TABLE `tbl_order_messages`
  ADD CONSTRAINT `tbl_order_messages_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `tbl_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_product_reviews`
--
ALTER TABLE `tbl_product_reviews`
  ADD CONSTRAINT `tbl_product_reviews_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `tbl_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_product_reviews_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `tbl_product_inventory` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_product_reviews_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_product_variants`
--
ALTER TABLE `tbl_product_variants`
  ADD CONSTRAINT `tbl_product_variants_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `tbl_product_inventory` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_rider_remittances`
--
ALTER TABLE `tbl_rider_remittances`
  ADD CONSTRAINT `tbl_rider_remittances_ibfk_1` FOREIGN KEY (`rider_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_rider_remittances_ibfk_2` FOREIGN KEY (`processed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `tbl_rider_remittance_items`
--
ALTER TABLE `tbl_rider_remittance_items`
  ADD CONSTRAINT `tbl_rider_remittance_items_ibfk_1` FOREIGN KEY (`remittance_id`) REFERENCES `tbl_rider_remittances` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_rider_remittance_items_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `tbl_orders` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
