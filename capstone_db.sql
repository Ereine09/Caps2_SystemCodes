-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 11, 2026 at 04:18 PM
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
-- Database: `capstone_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(1, 'Elaisa Reine Belandres', 'ereinebelandres09@gmail.com', '09634998174', 'Female', 21, '269 Stotsenburg St. Caloocan City', 28.00, '2026-04-28 17:25:04', '2026-06-07 15:38:14', NULL),
(2, 'Marichelle D. Gono', 'gonomarichelle@gmail.com', '09385381016', 'Female', 22, 'kawal phase 1 road, kawal phase 1 rd, blk 100 lot 6 purok 4 kawal st. dagat-dagatan caloocan city', 10.00, '2026-05-20 12:52:48', NULL, NULL);

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
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(1, 2, 1, 'Purchase: Walk-in Purchase', 1000.00, 10.00, NULL, '2026-05-24 02:42:21'),
(2, 1, 1, 'Manual: Other', 0.00, 10.00, NULL, '2026-05-24 02:54:10'),
(3, 1, 1, 'Manual: System Correction', 0.00, 50.00, NULL, '2026-05-24 02:54:23'),
(4, 1, 1, 'Manual: System Correction', 0.00, 50.00, NULL, '2026-05-24 02:54:39'),
(5, 1, 1, 'Manual: System Correction', 0.00, 50.00, NULL, '2026-05-24 02:54:52'),
(6, 1, 1, 'Manual: Other', 0.00, 10.00, NULL, '2026-05-24 03:03:10');

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
  `expiry_date` date DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rewards`
--

INSERT INTO `rewards` (`id`, `reward_code`, `name`, `points`, `stock`, `expiry_date`, `description`) VALUES
(1, 'free_item_1000', 'Free Item', 1000.00, 5, NULL, 'Example reward: redeem 1000 points for one free item.'),
(2, 'free_catfood_500', 'Free 0.5kg Cat Food', 500.00, 10, NULL, 'Half-kilo cat food reward for repeat buyers.'),
(3, 'free_treat_pack_250', 'Free Treat Pack', 250.00, 19, NULL, 'Small thank-you reward for loyal clients.'),
(4, 'DPS-GF-001', 'Gift Card of ₱50', 5000.00, 10, NULL, 'Thank you for being a loyal member of Darius Poultry Supplies! Here\'s a Php 50 gift card as a reward for your continuous support in our shop. Let\'s go!');

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
  `redeemed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reward_redemptions`
--

INSERT INTO `reward_redemptions` (`id`, `customer_id`, `user_id`, `reward_code`, `reward_name`, `points_used`, `redeemed_at`) VALUES
(5, 1, NULL, 'free_treat_pack_250', 'Free Treat Pack', 250.00, '2026-05-07 11:02:34');

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
-- Table structure for table `tbl_delivery`
--

CREATE TABLE `tbl_delivery` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `delivery_type` enum('pickup','delivery') NOT NULL DEFAULT 'delivery',
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `status` enum('pending','in_transit','delivered','failed') NOT NULL DEFAULT 'pending',
  `scheduled_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_delivery`
--

INSERT INTO `tbl_delivery` (`id`, `order_id`, `delivery_type`, `address`, `phone`, `instructions`, `status`, `scheduled_at`, `delivered_at`, `created_at`, `updated_at`) VALUES
(1, 34, 'delivery', 'Landaska Street, Barangay 28, Zone 3, Dagat-Dagatan, District 2, Caloocan, Northern Manila District, Metropolitan Manila, 1410, Philippines', '09385381016', '', 'pending', NULL, NULL, '2026-05-24 02:41:45', NULL),
(2, 35, 'delivery', '269 stotsenburg st. caloocan city,. brgy 70 zone 6', '09634998174', '', 'pending', NULL, NULL, '2026-05-24 02:53:14', NULL),
(3, 36, 'delivery', '269 stotsenburg st. caloocan city,. brgy 70 zone 6', '09634998174', '', 'pending', NULL, NULL, '2026-05-24 03:02:45', NULL),
(4, 37, 'pickup', NULL, NULL, NULL, 'pending', '2026-06-03 22:36:00', NULL, '2026-06-03 14:36:02', NULL),
(5, 38, 'delivery', 'Esguerra Street, Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', '09634998174', '1561612', 'pending', NULL, NULL, '2026-06-03 14:37:25', NULL);

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
(1, 1, NULL, 'customer', 'hi admin', 0, NULL, '2026-06-07 15:52:14'),
(2, 1, NULL, 'customer', 'hi admin', 0, NULL, '2026-06-07 15:52:14'),
(3, 1, NULL, 'customer', 'hi admin', 0, NULL, '2026-06-07 15:55:06'),
(4, 1, NULL, 'customer', 'hi admin', 0, NULL, '2026-06-07 15:55:06'),
(5, 1, NULL, 'customer', 'hey', 0, NULL, '2026-06-07 15:55:16'),
(6, 1, NULL, 'customer', 'hey', 0, NULL, '2026-06-07 15:55:16');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_orders`
--

CREATE TABLE `tbl_orders` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `order_number` varchar(100) NOT NULL,
  `order_status` enum('pending','confirmed','processing','ready_for_pickup','out_for_delivery','to_ship','to_receive','reviews','completed','cancelled') NOT NULL DEFAULT 'pending',
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
  `updated_at` datetime DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT 'Cash',
  `payment_reference` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_orders`
--

INSERT INTO `tbl_orders` (`id`, `customer_id`, `order_number`, `order_status`, `fulfillment_type`, `pickup_date`, `pickup_time`, `delivery_address`, `delivery_phone`, `delivery_instructions`, `subtotal`, `delivery_fee`, `bulk_order`, `free_delivery`, `loyalty_points_earned`, `total`, `created_at`, `updated_at`, `payment_method`, `payment_reference`) VALUES
(34, 2, 'DPS-F75B6D1B-1779590505', 'to_receive', 'delivery', '0000-00-00', '', 'Landaska Street, Barangay 28, Zone 3, Dagat-Dagatan, District 2, Caloocan, Northern Manila District, Metropolitan Manila, 1410, Philippines', '09385381016', '', 1000.00, 50.00, 1, 0, 10.00, 1050.00, '2026-05-24 02:41:45', NULL, 'cod', NULL),
(35, 1, 'DPS-5AC05739-1779591194', 'to_receive', 'delivery', '0000-00-00', '', '269 stotsenburg st. caloocan city,. brgy 70 zone 6', '09634998174', '', 1000.00, 50.00, 1, 0, 10.00, 1050.00, '2026-05-24 02:53:14', NULL, 'cod', NULL),
(36, 1, 'DPS-08490D31-1779591765', 'to_receive', 'delivery', '0000-00-00', '', '269 stotsenburg st. caloocan city,. brgy 70 zone 6', '09634998174', '', 1000.00, 50.00, 1, 0, 10.00, 1050.00, '2026-05-24 03:02:45', NULL, 'cod', NULL),
(37, 1, 'DPS-9F5E227E-1780497362', 'pending', 'pickup', '2026-06-03', '22:36', '', '', '', 1800.00, 0.00, 1, 1, 18.00, 1800.00, '2026-06-03 14:36:02', NULL, 'pay_at_shop', NULL),
(38, 1, 'DPS-85CF5447-1780497445', 'pending', 'delivery', '0000-00-00', '', 'Esguerra Street, Barangay 70, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines', '09634998174', '1561612', 1000.00, 0.00, 1, 1, 10.00, 1000.00, '2026-06-03 14:37:25', NULL, 'cod', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_order_items`
--

CREATE TABLE `tbl_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_order_items`
--

INSERT INTO `tbl_order_items` (`id`, `order_id`, `product_id`, `product_name`, `quantity`, `unit_price`, `total_price`, `created_at`) VALUES
(1, 34, 33, 'Fancy Feast Grilled Tuna Feast Adult Wet Cat Food', 10, 100.00, 1000.00, '2026-05-24 02:41:45'),
(2, 35, 33, 'Fancy Feast Grilled Tuna Feast Adult Wet Cat Food', 10, 100.00, 1000.00, '2026-05-24 02:53:14'),
(3, 36, 33, 'Fancy Feast Grilled Tuna Feast Adult Wet Cat Food', 10, 100.00, 1000.00, '2026-05-24 03:02:45'),
(4, 37, 31, 'FELIX As Good As It Looks Adult Chicken in Jelly Wet Cat Food', 18, 100.00, 1800.00, '2026-06-03 14:36:02'),
(5, 38, 7, 'Friskies Kitten Discoveries Kitten Dry Cat Food', 10, 100.00, 1000.00, '2026-06-03 14:37:25');

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
(4, 'Purina ONE®-001', 'PURINA ONE® Active Kitten with Chicken Dry Cat Food', 'PURINA ONE® Dry Cat Food is expertly combined with high quality ingredients to deliver a balanced nutrition &; a delicious taste that cats love.', 100.00, 9, 'Cat Food, Kitten, Dry Food, Purina ONE®', 'Puppy/Kitten', 'Purina ONE®', 'Dry Food', '', 'https://www.purina.ph/sites/default/files/2025-06/8850125085015_C1N1_0.webp', '2026-04-30 15:14:51', NULL),
(7, 'Friskies-001', 'Friskies Kitten Discoveries Kitten Dry Cat Food', 'FRISKIES® Kitten DiscoveriesTM! With its delicious flavours of chicken, tuna, milk, vegetables and whole grains, it contains complete and balance nutrition to support development of strong bones and teeth and lean muscles.', 100.00, 0, 'Cat Food, Dry Food, Friskies', 'Puppy/Kitten', 'Friskies', 'Dry Food', '', 'https://www.purina.ph/sites/default/files/2025-06/8850125071360_C1N1_0.webp', '2026-04-30 16:22:58', NULL),
(9, 'FELIX-002', 'FELIX As Good As It Looks Kitten Tuna in Jelly Wet Cat Food', 'Mouth-watering tastes and textures, it really does taste as good as it looks\r\nPacked with essential vitamins and minerals\r\nA great way to keep your cat nourished through complete and balanced nutrition\r\nWe ensure our kitten food contains all the necessary proteins and minerals that are essential for the early development of your kitten', 100.00, 10, 'Cat Food, Kitten, Wet Food, FELIX', 'Puppy/Kitten', 'Felix', 'Wet Food', '', 'https://www.purina.ph/sites/default/files/2025-06/AGAIL_KITTEN%2520TUNA_FRONT_0.jpg', '2026-04-30 16:44:44', NULL),
(10, 'Friskies-002', 'Friskies Indoor Delights Adult Dry Cat Food', 'Salmon, tuna, chicken, beef and vegetables flavors\r\nReduces litter box odors\r\nComplete and balanced nutrition. Packed with protein, essential fatty acids, antioxidants and Vitamin A\r\nContains natural fiber to aid digestion and specially formulated formula to help control hairball formation', 100.00, 8, 'Cat Food, Adult (1 - 7), Dry Food, Indoor Cats, Friskies', 'Adult', 'Friskies', 'Dry Food', 'Indoor Cat', 'https://www.purina.ph/sites/default/files/2025-06/8850125073012_C1N1_0.webp', '2026-04-30 16:47:45', NULL),
(11, 'Purina ONE®-002', 'PURINA ONE® Indoor Advantage with Chicken Dry Cat Food', 'Natural With Added Vitamins, Minerals & Nutrients\r\nReal Chicken is #1 Ingredient\r\nHelps Maintain a Healthy Weight\r\nNatural Fiber Blend Minimizes Hairballs', 100.00, 8, 'Cat Food, Kitten, Dry Food, Purina ONE®', 'Adult', 'Purina ONE®', 'Dry Food', 'Indoor Cat', 'https://www.purina.ph/sites/default/files/2025-06/8850125078222_C1N1_0.jpg', '2026-04-30 16:49:02', NULL),
(16, 'FELIX-001', 'FELIX® Kitten Wet with Chicken in Jelly', '100% of your Kitten\'s daily needs\r\nTasty, meaty chunks with chicken in an irresistible jelly\r\nEnriched with Vitamins D & E\r\nA source of essential Omega 6 fatty acids\r\nThe right combination of balanced minerals', 100.00, 10, 'Cat Food, Kitten, Wet Food, FELIX', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-10/PH_en_PU_FELIX_New%20Website_ProdImg_1080px.png', '2026-05-05 08:41:11', NULL),
(17, 'Purina ONE®-003', 'PURINA ONE® Healthy Adult with Salmon & Tuna Dry Cat Food', 'PURINA ONE® Dry Cat Food is expertly combined with high quality ingredients to deliver a balanced nutrition &; a delicious taste that cats love. With PURINA ONE®, witness visible health differences in your cat in just 3 weeks. Formulated for house cats and made with real salmon and tuna as it\'s first ingredient, PURINA ONE® Healthy Adult with Salmon &; Tuna has a high fibre content to help limit hairball formation, and contains prebiotic fibres that minimise litter box odour. Give your cat a nutritious diet with reduced calories that helps maintain a healthy weight. Take the 3-Week Challenge today to see the visible health diffferences in your cat.\r\n\r\n \r\n\r\nNatural With Added Vitamins, Minerals & Nutrients\r\nReal Salmon & Tuna is #1 Ingredient\r\nHigh Protein Supports Kittens’ Growing Muscles\r\nSupports Vision & Brain Development', 100.00, 9, 'Cat Food, Adult (1 - 7), Dry Food, Purina ONE®', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/8850125001695_C1N1_0.jpg', '2026-05-05 08:43:38', NULL),
(21, 'Friskies-003', 'Friskies Meaty Grill Adult Dry Cat Food', 'Excite your meat-loving cats even more with FRISKIES® Meaty GrillsTM! Made with beef, chicken, lamb, turkey and vegetables, it contains protein, essential fatty acids, Omega 3 & 6, antioxidants and Vitamin A to maintain lean muscles and support a healthy skin and coat. It is also specially formulated to develop clear vision and a healthy immune so that she can always go on with her days happily!\r\n\r\n \r\n\r\nIn flavors of beef, chicken, lamb, turkey and vegetables\r\n100% complete and balanced nutrition for cats of all life stages\r\nStrong, lean muscles supported by high-quality protein\r\nPacked with protein, essential fatty acids, Omega 3 & 6, antioxidants and Vitamin A', 100.00, 8, 'Cat Food, Adult (1 - 7), Dry Food, Friskies', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/08850125072978_C1N1_0.webp', '2026-05-05 09:06:01', NULL),
(22, 'Friskies-004', 'Friskies Seafood Sensations Adult Dry Cat Food', 'Excite your seafood-loving cats even more with FRISKIES® Seafood Sensation! With its delicious flavours of tuna, salmon, whitefish, crab and shrimp, it contains protein, essential fatty acids, Omega 3 & 6, antioxidants and Vitamin A to maintain lean muscles and support a healthy skin and coat. It is also specially formulated to develop clear vision and a healthy immune so that she can always go on with her days happily!\r\n\r\n \r\n\r\nTuna, salmon, whitefish, crab, shrimp flavors\r\n100% complete and balanced nutrition for cats of all life stages\r\nStrong, lean muscles supported by high-quality protein\r\nPacked with protein, essential fatty acids, Omega 3 & 6, antioxidants and Vitamin A', 100.00, 8, 'Cat Food, Adult (1 - 7), Dry Food, Friskies', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/8850125072893_C1N1_0.webp', '2026-05-05 09:06:51', NULL),
(23, 'Friskies-005', 'Friskies Surfin\' Favorites\' Adult Dry Cat Food', 'Give your cats the best of land and sea together with FRISKIES® Surfin\' FavouritesTM! With flavours of mackerel, tuna, salmon and sardine, it contains protein, essential fatty acids, Omega 3 & 6, antioxidants and Vitamin A to maintain lean muscles and support a healthy skin and coat. It is also specially formulated to develop clear vision and a healthy immune so that she can always go on with her days happily!\r\n\r\n \r\n\r\nWith flavours of mackerel, tuna, salmon and sardine\r\n100% complete and balanced nutrition for cats of all life stages\r\nStrong, lean muscles supported by high-quality protein\r\nComplete and balanced nutrition. Packed with protein, essential fatty acids, Omega 3 & 6, antioxidants and Vitamin A', 100.00, 10, 'Cat Food, Adult (1 - 7), Dry Food, Friskies', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/8850125072930_C1N1_0.webp', '2026-05-05 09:07:46', NULL),
(24, 'Friskies-006', 'Friskies Party Mix Crunch Mixed Grill Adult Cat Treats', 'Cats are fired up! And they should be. Made with real chicken as the #1 ingredient and flavors of beef & salmon, this grillicious cat treat adds sizzle to every day – no spatula, oven mitt or apron required!\r\n\r\n \r\n\r\nReal chicken is the #1 Ingredient\r\nMade with flavors of beef & salmon\r\nLess than 2 calories per cat treat\r\nWith a crunchy texture that helps clean teeth\r\nComplete & balanced treats for adult cats', 100.00, 8, 'Cat Food, Adult (1 - 7), Treats, Friskies', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/MIXEDGRILL-1_1.png', '2026-05-05 09:08:27', NULL),
(25, 'Friskies-007', 'Friskies Party Mix Crunch Classic Adult Cat Treats', 'Me-WOW! Made with real chicken as the #1 ingredient and flavors of tuna & bonito, it’s the deliciously crunchy cat treat that started it all. Every lip-licking crunchy bite lets your cat know loud and clear - it\'s time to party!\r\n\r\n \r\n\r\nReal chicken is the #1 Ingredient\r\nMade with flavors of tuna & bonito\r\nLess than 2 calories per cat treat\r\nWith a crunchy texture that helps clean teeth', 100.00, 10, 'Cat Food, Adult (1 - 7), Treats, Friskies', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/styles/search_result_large/public/2025-06/1_8.jpg.webp?itok=um5G9lRm', '2026-05-05 09:09:14', NULL),
(26, 'Friskies-008', 'Friskies Party Mix Crunch Beachside Adult Cat Treats', 'Surf\'s up! And the waves of deliciousness are rolling in with flavors as good as the ocean is deep. We’re talking real ocean whitefish as the #1 ingredient with flavors of shrimp, crab & tuna. Woah - that\'s one scrumptious cat treat.\r\n\r\n \r\n\r\nReal ocean whitefish is the #1 Ingredient\r\nMade with flavors of shrimp, crab & tuna\r\nLess than 2 calories per cat treat with a crunchy texture that helps clean teeth\r\nComplete & balanced treats for adult cats', 100.00, 7, 'Cat Food, Adult (1 - 7), Treats, Friskies', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/partymix_0.jpg', '2026-05-05 09:09:48', NULL),
(27, 'FELIX-003', 'FELIX As Good As It Looks Adult Sardine in Jelly Wet Cat Food', 'We know cats love fish, and with our FELIX As Good As It Looks Adult Cat Sardine in Jelly that\'s packed with delicious fishy flavours, it really does taste as good as it looks!\r\n\r\n \r\n\r\nWe use high quality ingredients to provide your cat with all they need to maintain a healthy lifestyle, and our added vitamins and minerals ensure their natural defences are supported too!\r\n\r\n \r\n\r\nWe\'re sure your cat will love the tasty flavours of sardine in this easy to serve pouch, especially as the sardine is combined with a smooth, silky jelly that your cat can enjoy.\r\n\r\n \r\n\r\nMouth-watering tastes and textures, it really does taste as good as it looks\r\nPacked with essential vitamins and minerals\r\nA great way to keep your cat nourished through complete and balanced nutrition\r\nDelicious flavour including sardine', 100.00, 10, 'Cat Food, Adult (1 - 7), Wet Food, FELIX', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/styles/search_result_large/public/2025-06/AGAIL_ADULT%2520SARDINE_FRONT_0.jpg.webp?itok=TsANnXjM', '2026-05-05 09:13:18', NULL),
(28, 'FELIX-004', 'FELIX As Good As It Looks Adult Salmon in Jelly Wet Cat Food', 'We know cats love fish, and with our FELIX As Good As It Looks Adult Cat Salmon in Jelly that\'s packed with delicious fishy flavours, it really does taste as good as it looks!\r\n\r\n \r\n\r\nWe use high quality ingredients to provide your cat with all they need to maintain a healthy lifestyle, and our added vitamins and minerals ensure their natural defences are supported too!\r\n\r\n \r\n\r\nWe\'re sure your cat will love the tasty flavours of salmon in this easy to serve pouch, especially as the salmon is combined with a smooth, silky jelly that your cat can enjoy.\r\n\r\n \r\n\r\nMouth-watering tastes and textures, it really does taste as good as it looks\r\nPacked with essential vitamins and minerals\r\nA great way to keep your cat nourished through complete and balanced nutrition\r\nDelicious flavour including salmon', 100.00, 10, 'Cat Food, Adult (1 - 7), Wet Food, FELIX', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/styles/search_result_large/public/2025-06/AGAIL_SALMON_FRONT_0.jpg.webp?itok=K8crDDRv', '2026-05-05 09:14:00', NULL),
(29, 'FELIX-005', 'FELIX As Good As It Looks Adult Mackerel in Jelly Wet Cat Food', 'We know cats love fish, and with our FELIX As Good As It Looks Adult Cat Mackerel in Jelly that\'s packed with delicious fishy flavours, it really does taste as good as it looks!\r\n\r\n \r\n\r\nWe use high quality ingredients to provide your cat with all they need to maintain a healthy lifestyle, and our added vitamins and minerals ensure their natural defences are supported too!\r\n\r\n \r\n\r\nWe\'re sure your cat will love the tasty flavours of mackerel in this easy to serve pouch, especially as the mackerel is combined with a smooth, silky jelly that your cat can enjoy.\r\n\r\n \r\n\r\nMouth-watering tastes and textures, it really does taste as good as it looks\r\nPacked with essential vitamins and minerals\r\nA great way to keep your cat nourished through complete and balanced nutrition\r\nDelicious flavour including mackerel', 100.00, 10, 'Cat Food, Adult (1 - 7), Wet Food, FELIX', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/AGAIL_ADULT%2520MACHEREL_FRONT_2.jpg', '2026-05-05 09:14:59', NULL),
(30, 'FELIX-006', 'FELIX As Good As It Looks Adult Tuna in Jelly Wet Cat Food', 'We know cats love fish, and with our FELIX As Good As It Looks Adult Cat Tuna in Jelly that\'s packed with delicious fishy flavours, it really does taste as good as it looks!\r\n\r\n \r\n\r\nWe use high quality ingredients to provide your cat with all they need to maintain a healthy lifestyle, and our added vitamins and minerals ensure their natural defences are supported too!\r\n\r\n \r\n\r\nWe\'re sure your cat will love the tasty flavours of tuna in this easy to serve pouch, especially as the tuna is combined with a smooth, silky jelly that your cat can enjoy.\r\n\r\n \r\n\r\nMouth-watering tastes and textures, it really does taste as good as it looks\r\nPacked with essential vitamins and minerals\r\nA great way to keep your cat nourished through complete and balanced nutrition\r\nDelicious flavour including tuna', 100.00, 9, 'Cat Food, Adult (1 - 7), Wet Food, FELIX', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/AGAIL_ADULT%2520TUNA_FRONT_0.jpg', '2026-05-05 09:15:45', NULL),
(31, 'FELIX-007', 'FELIX As Good As It Looks Adult Chicken in Jelly Wet Cat Food', 'With tender pieces of chicken making our FELIX wet cat food utterly irresistible, we\'re sure your cat will love this delicious dish! FELIX As Good As It Looks Adult Cat Chicken in Jelly Wet Food is a single pouch packed with essential nutrients and key ingredients to help your adult cat maintain a healthy and active lifestyle. Our vets and nutritionists have specially formulated this mouth-watering meal to ensure your cat gets all he needs from his daily diet.\r\n\r\n \r\n\r\nMouth-watering tastes and textures, it really does taste as good as it looks\r\nPacked with essential vitamins and minerals\r\nA great way to keep your cat nourished through complete and balanced nutrition\r\nDelicious flavour including chicken', 100.00, 82, 'Cat Food, Adult (1 - 7), Wet Food, FELIX', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/AGAIL_ADULT%2520CHICKEN_FRONT_0.jpg', '2026-05-05 09:16:42', NULL),
(32, 'Fancy Feast-001', 'Fancy Feast Savory Salmon Adult Wet Cat Food', 'Just like dry cat food, wet cat food offers balanced nutrition. Wet cat food also helps provide additional moisture to balanced nutrition, because of the delicious juices and savory gravy in each entrée. Fancy Feast wet cat food entrées are complete and balanced, and rich in protein - made with high-quality ingredients, from real beef, poultry and seafood to cheddar cheese, garden veggies and egg (depending on the recipe).\r\n\r\n \r\n\r\nMade in the USA\r\nComplete & balanced nutrition\r\nSmooth paté made with salmon\r\nConvenient flip top can makes feeding quick & easy', 100.00, 100, 'Cat Food, Adult (1 - 7), Wet Food, Fancy Feast', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/2025-06/29%20FANCY%20FEAST%20SAVORY%20SALMON%2085G_0.jpg', '2026-05-05 13:13:32', NULL),
(33, 'Fancy Feast-002', 'Fancy Feast Grilled Tuna Feast Adult Wet Cat Food', 'Every Fancy Feast selection is made with high-quality protein sources and ingredients. This Grilled Feast is a luscious, slow-cooked feast of tuna and chicken with a sumptuous basting of gravy.\r\n\r\n \r\n\r\nMade in the USA\r\nComplete & balanced nutrition\r\nDelicious entree of succulent pieces\r\nA great way to add moisture to your cat’s diet', 100.00, 70, 'Cat Food, Adult (1 - 7), Wet Food, Fancy Feast', NULL, NULL, NULL, NULL, 'https://www.purina.ph/sites/default/files/styles/search_result_narrow/public/2025-06/3520FEAST20TUNA2085G_0.jpg.webp?itok=ZjMPFgQA', '2026-05-05 13:14:23', NULL);

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
,`role` enum('admin','staff')
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

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `role` enum('admin','staff') DEFAULT 'staff',
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
(2, 'dom', 'dominic', 'vega', 'staff', 'vega@gmail.com', '$2y$10$vNh5gfJB9HNtp27GM0b3QemkLdMuJOSEBrQmkw1bTuDTgOn9pOt0q', NULL, NULL, NULL, 0, NULL, NULL, NULL);

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
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `tbl_delivery`
--
ALTER TABLE `tbl_delivery`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

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
  ADD KEY `customer_id` (`customer_id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customer_addresses`
--
ALTER TABLE `customer_addresses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `loyalty_transactions`
--
ALTER TABLE `loyalty_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rewards`
--
ALTER TABLE `rewards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `reward_redemptions`
--
ALTER TABLE `reward_redemptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tbl_cart`
--
ALTER TABLE `tbl_cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

--
-- AUTO_INCREMENT for table `tbl_delivery`
--
ALTER TABLE `tbl_delivery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tbl_messages`
--
ALTER TABLE `tbl_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tbl_orders`
--
ALTER TABLE `tbl_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `tbl_order_items`
--
ALTER TABLE `tbl_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tbl_order_messages`
--
ALTER TABLE `tbl_order_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `tbl_product_inventory`
--
ALTER TABLE `tbl_product_inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `tbl_vouchers`
--
ALTER TABLE `tbl_vouchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
-- Constraints for table `tbl_cart`
--
ALTER TABLE `tbl_cart`
  ADD CONSTRAINT `tbl_cart_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `tbl_product_inventory` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_delivery`
--
ALTER TABLE `tbl_delivery`
  ADD CONSTRAINT `tbl_delivery_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `tbl_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_orders`
--
ALTER TABLE `tbl_orders`
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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
