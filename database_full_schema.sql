-- ============================================================
-- DARIUS POULTRY SUPPLY & GEN. MERCHANDISE
-- Loyalty Management System - COMPLETE DATABASE SCHEMA
-- ============================================================
-- This file creates ALL tables used by the application.
-- It is safe to run on an empty database (capstone_db).
-- It uses CREATE TABLE IF NOT EXISTS so it can also be
-- re-run safely on an existing database.
--
-- Database: capstone_db
-- Charset:   utf8mb4
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- CORE AUTHENTICATION & USERS
-- --------------------------------------------------------

-- Staff & Admin accounts (also used as rider user accounts)
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(255) NOT NULL,
  `last_name` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','customer','rider','staff') NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `reset_token` VARCHAR(255) DEFAULT NULL,
  `reset_expiry` DATETIME DEFAULT NULL,
  `password_reset_at` DATETIME DEFAULT NULL,
  `login_attempts` INT(11) DEFAULT 0,
  `lock_until` DATETIME DEFAULT NULL,
  `otp_code` VARCHAR(6) DEFAULT NULL,
  `otp_expiry` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Loyalty customers
CREATE TABLE IF NOT EXISTS `customers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `gender` VARCHAR(20) DEFAULT NULL,
  `age` INT(11) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `loyalty_points` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `admin_typing_at` TIMESTAMP NULL DEFAULT NULL,
  `last_typing_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Customer login credential (password hash tied to customers)
CREATE TABLE IF NOT EXISTS `customer_login_credentials` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `last_login` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_id` (`customer_id`),
  CONSTRAINT `customer_login_credentials_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Customer saved delivery addresses
CREATE TABLE IF NOT EXISTS `customer_addresses` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `label` VARCHAR(50) NOT NULL,
  `full_address` TEXT NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `is_default` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  CONSTRAINT `customer_addresses_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Rider profiles (linked to users.id via user_id)
CREATE TABLE IF NOT EXISTS `riders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `vehicle_type` VARCHAR(50) COMMENT 'e.g., Motorcycle, Bicycle',
  `plate_number` VARCHAR(20) NULL,
  `is_on_duty` TINYINT(1) DEFAULT 0,
  `last_seen` TIMESTAMP NULL,
  UNIQUE KEY (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- SYSTEM SETTINGS & AUDIT
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(50) PRIMARY KEY,
  `setting_value` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) DEFAULT NULL,
  `customer_id` INT(11) DEFAULT NULL,
  `action` VARCHAR(255) DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- NOTIFICATIONS
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) DEFAULT NULL,
  `customer_id` INT(11) DEFAULT NULL,
  `type` VARCHAR(50) NOT NULL,
  `channel` ENUM('in_app','email','both') NOT NULL DEFAULT 'in_app',
  `title` VARCHAR(150) NOT NULL,
  `message` TEXT NOT NULL,
  `reference_table` VARCHAR(50) DEFAULT NULL,
  `reference_id` INT(11) DEFAULT NULL,
  `points_value` DECIMAL(10,2) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `read_at` DATETIME DEFAULT NULL,
  `email_to` VARCHAR(255) DEFAULT NULL,
  `delivery_status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `delivery_error` TEXT DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_read_created` (`user_id`,`is_read`,`created_at`),
  KEY `idx_notifications_customer_read_created` (`customer_id`,`is_read`,`created_at`),
  KEY `idx_notifications_status_channel` (`delivery_status`,`channel`),
  KEY `idx_notifications_type_created` (`type`,`created_at`),
  KEY `idx_notifications_reference` (`reference_table`,`reference_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- MESSAGING
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tbl_messages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `sender_type` VARCHAR(20) NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- PRODUCTS, VARIANTS & CATEGORIES
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `products` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `category` VARCHAR(255) DEFAULT NULL,
  `image_url` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_product_inventory` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `sku` VARCHAR(100) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stock` INT(11) NOT NULL DEFAULT 0,
  `category` VARCHAR(100) DEFAULT 'General',
  `lifestage` VARCHAR(100) DEFAULT NULL,
  `brand` VARCHAR(100) DEFAULT NULL,
  `food_type` VARCHAR(100) DEFAULT NULL,
  `health_needs` VARCHAR(100) DEFAULT NULL,
  `image_url` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_product_variants` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `size` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10, 2) NOT NULL,
  `stock` INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES tbl_product_inventory(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_product_size (product_id, size)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_custom_categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `group_name` VARCHAR(100) NOT NULL,
  `category_value` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cat` (`group_name`,`category_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- CART & ORDERS
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tbl_cart` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `quantity` INT(11) NOT NULL DEFAULT 1,
  `variant_id` INT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_product_variant` (`customer_id`,`product_id`,`variant_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `tbl_cart_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tbl_cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `tbl_product_inventory` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tbl_cart_ibfk_3` FOREIGN KEY (`variant_id`) REFERENCES `tbl_product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_orders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `order_number` VARCHAR(100) NOT NULL,
  `order_status` ENUM('pending','confirmed','processing','ready_for_pickup','out_for_delivery','to_ship','to_receive','reviews','completed','cancelled') NOT NULL DEFAULT 'pending',
  `fulfillment_type` ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',
  `pickup_date` DATE DEFAULT NULL,
  `pickup_time` VARCHAR(50) DEFAULT NULL,
  `delivery_address` TEXT DEFAULT NULL,
  `order_notes` TEXT DEFAULT NULL,
  `delivery_phone` VARCHAR(50) DEFAULT NULL,
  `delivery_instructions` TEXT DEFAULT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `delivery_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `bulk_order` TINYINT(1) NOT NULL DEFAULT 0,
  `free_delivery` TINYINT(1) NOT NULL DEFAULT 0,
  `loyalty_points_earned` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `voucher_code` VARCHAR(100) DEFAULT NULL,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  `payment_method` ENUM('cod','gcash','pay_at_shop','bank') NOT NULL DEFAULT 'cod',
  `payment_reference` VARCHAR(100) DEFAULT NULL,
  `bank_name` VARCHAR(100) DEFAULT NULL,
  `bank_account_name` VARCHAR(255) DEFAULT NULL,
  `payment_proof_path` VARCHAR(255) DEFAULT NULL,
  `qr_code_path` VARCHAR(255) DEFAULT NULL,
  `payment_confirmation_token` VARCHAR(255) DEFAULT NULL,
  `rider_id` INT NULL DEFAULT NULL,
  `payment_settled` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `customer_id` (`customer_id`),
  CONSTRAINT `tbl_orders_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_order_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `variant_size` VARCHAR(100) DEFAULT NULL,
  `quantity` INT(11) NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`),
  KEY `variant_size` (`variant_size`),
  CONSTRAINT `tbl_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `tbl_orders` (`id`) ON DELETE CASCADE,
CONSTRAINT `tbl_order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `tbl_product_inventory` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_order_messages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_id` INT(11) NOT NULL,
  `sender_role` ENUM('customer','admin','staff') NOT NULL,
  `message` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `tbl_order_messages_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `tbl_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Legacy/alternative order tables (used by some older modules)
CREATE TABLE IF NOT EXISTS `customer_orders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `order_number` VARCHAR(100) NOT NULL,
  `order_status` ENUM('pending','confirmed','processing','ready_for_pickup','out_for_delivery','completed','cancelled') NOT NULL DEFAULT 'pending',
  `fulfillment_type` ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',
  `pickup_date` DATE DEFAULT NULL,
  `pickup_time` VARCHAR(50) DEFAULT NULL,
  `delivery_address` TEXT DEFAULT NULL,
  `delivery_phone` VARCHAR(50) DEFAULT NULL,
  `delivery_instructions` TEXT DEFAULT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `delivery_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `bulk_order` TINYINT(1) NOT NULL DEFAULT 0,
  `free_delivery` TINYINT(1) NOT NULL DEFAULT 0,
  `loyalty_points_earned` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `customer_id` (`customer_id`),
  CONSTRAINT `customer_orders_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `customer_order_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `quantity` INT(11) NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `customer_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `customer_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- DELIVERY & TRACKING
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tbl_delivery` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_id` INT(11) NOT NULL,
  `delivery_type` ENUM('pickup','delivery') NOT NULL DEFAULT 'delivery',
  `address` TEXT DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `instructions` TEXT DEFAULT NULL,
  `status` ENUM('pending','in_transit','delivered','failed') NOT NULL DEFAULT 'pending',
  `scheduled_at` DATETIME DEFAULT NULL,
  `delivered_at` DATETIME DEFAULT NULL,
  `qr_confirmation_token` VARCHAR(255) DEFAULT NULL,
  `rider_id` INT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `tbl_delivery_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `tbl_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tbl_delivery_ibfk_rider` FOREIGN KEY (`rider_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `delivery_tracking` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `rider_id` INT NOT NULL,
  `status` VARCHAR(50) NOT NULL COMMENT 'e.g., accepted, picked_up, delivered, failed',
  `notes` TEXT NULL,
  `proof_image_url` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`) REFERENCES `tbl_orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`rider_id`) REFERENCES `riders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- VOUCHERS
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tbl_vouchers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `discount_type` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  `discount_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `min_order_amount` DECIMAL(10,2) DEFAULT NULL,
  `usage_limit` INT(11) DEFAULT NULL,
  `used_count` INT(11) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `expires_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- LOYALTY & REWARDS
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `loyalty_transactions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `quantity_kg` DECIMAL(10,2) NOT NULL,
  `points_earned` DECIMAL(10,2) NOT NULL,
  `order_id` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `loyalty_transactions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `loyalty_transactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rewards` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `reward_code` VARCHAR(100) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `points` DECIMAL(10,2) NOT NULL,
  `stock` INT(11) NOT NULL DEFAULT 0,
  `expiry_date` DATE DEFAULT NULL,
  `validity_days` INT(11) DEFAULT 7,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reward_code` (`reward_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `reward_redemptions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `reward_code` VARCHAR(100) NOT NULL,
  `reward_name` VARCHAR(255) NOT NULL,
  `points_used` DECIMAL(10,2) NOT NULL,
  `redeemed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `card_number` VARCHAR(20) DEFAULT NULL,
  `pin_code` VARCHAR(10) DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'Active',
  PRIMARY KEY (`id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `reward_code` (`reward_code`),
  CONSTRAINT `reward_redemptions_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `reward_redemptions_ibfk_3` FOREIGN KEY (`reward_code`) REFERENCES `rewards` (`reward_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- PRODUCT REVIEWS
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tbl_product_reviews` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `customer_id` INT NOT NULL,
  `rating` INT NOT NULL,
  `review_text` TEXT,
  `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`) REFERENCES `tbl_orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `tbl_product_inventory`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- RIDER REMITTANCE
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tbl_rider_remittances` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `rider_id` INT NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `notes` TEXT NULL COMMENT 'Reason for rejection, etc.',
  `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `processed_by_user_id` INT NULL,
  `processed_at` DATETIME NULL,
  FOREIGN KEY (`rider_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`processed_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_rider_remittance_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `remittance_id` INT NOT NULL,
  `order_id` INT NOT NULL,
  FOREIGN KEY (`remittance_id`) REFERENCES `tbl_rider_remittances`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `tbl_orders`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uniq_remittance_order` (`remittance_id`, `order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- VIEWS (created after base tables)
-- --------------------------------------------------------

DROP VIEW IF EXISTS `tbl_customer_records`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `tbl_customer_records` AS
SELECT `customers`.`id` AS `id`,
       `customers`.`name` AS `name`,
       `customers`.`email` AS `email`,
       `customers`.`phone` AS `phone`,
       `customers`.`gender` AS `gender`,
       `customers`.`age` AS `age`,
       `customers`.`address` AS `address`,
       `customers`.`loyalty_points` AS `loyalty_points`,
       `customers`.`created_at` AS `created_at`
FROM `customers`;

DROP VIEW IF EXISTS `tbl_user_accounts`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `tbl_user_accounts` AS
SELECT `users`.`id` AS `id`,
       `users`.`username` AS `username`,
       `users`.`first_name` AS `first_name`,
       `users`.`last_name` AS `last_name`,
       `users`.`role` AS `role`,
       `users`.`email` AS `email`,
       `users`.`password` AS `password`,
       `users`.`reset_token` AS `reset_token`,
       `users`.`reset_expiry` AS `reset_expiry`,
       `users`.`password_reset_at` AS `password_reset_at`,
       `users`.`login_attempts` AS `login_attempts`,
       `users`.`lock_until` AS `lock_until`
FROM `users`;

-- ============================================================
-- DEFAULT ADMIN ACCOUNT (email: elaisareinebelandres09@gmail.com)
-- Password: Admin@12345  (change after first login!)
-- ============================================================
INSERT INTO `users` (`username`, `first_name`, `last_name`, `role`, `email`, `password`)
SELECT 'ereine', 'elaisa', 'belandres', 'admin', 'elaisareinebelandres09@gmail.com', '$2y$10$mgRuQdxTj4QSvXGMC.hl1uOBNgwrL0hfqbYNvoHwDVrE.rrT.6dKq'
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `email` = 'elaisareinebelandres09@gmail.com');

-- ============================================================
-- END OF SCHEMA
-- ============================================================
