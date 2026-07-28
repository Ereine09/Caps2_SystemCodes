<?php

/**
 * Ensures that rider-specific database tables and columns exist.
 * This function should be called at application startup or when rider-related modules are initialized.
 *
 * @param mysqli $conn The database connection object.
 */
function ensureRiderSchema(mysqli $conn): void
{
    // Table to store rider-specific information
    $conn->query("
        CREATE TABLE IF NOT EXISTS `riders` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `vehicle_type` VARCHAR(50) COMMENT 'e.g., Motorcycle, Bicycle',
            `plate_number` VARCHAR(20) NULL,
            `is_on_duty` TINYINT(1) DEFAULT 0,
            `last_seen` TIMESTAMP NULL,
            UNIQUE KEY (`user_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        )
    ");

    // Table to log the history of each delivery status change
    $conn->query("
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
        )
    ");

    // Add rider_id to orders table to assign deliveries
    $check_col = $conn->query("SHOW COLUMNS FROM `tbl_orders` LIKE 'rider_id'");
    if ($check_col && $check_col->num_rows === 0) {
        $conn->query("ALTER TABLE `tbl_orders` ADD COLUMN `rider_id` INT NULL DEFAULT NULL AFTER `customer_id`");
        $conn->query("ALTER TABLE `tbl_orders` ADD CONSTRAINT `fk_order_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders`(`id`) ON DELETE SET NULL");
    }
}