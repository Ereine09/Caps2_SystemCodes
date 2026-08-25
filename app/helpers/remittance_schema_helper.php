<?php

function ensure_remittance_schema(mysqli $conn): void
{
    // 1. Table to store remittance requests from riders
    $conn->query("
        CREATE TABLE IF NOT EXISTS `tbl_rider_remittances` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `rider_id` INT NOT NULL,
          `reference_number` VARCHAR(100) NULL,
          `amount` DECIMAL(10, 2) NOT NULL,
          `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
          `notes` TEXT NULL COMMENT 'Reason for rejection, etc.',
          `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `processed_by_user_id` INT NULL,
          `processed_at` DATETIME NULL,
          FOREIGN KEY (`rider_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`processed_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 2. Junction table to link which orders are included in a remittance
    $conn->query("
        CREATE TABLE IF NOT EXISTS `tbl_rider_remittance_items` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `remittance_id` INT NOT NULL,
          `order_id` INT NOT NULL,
          FOREIGN KEY (`remittance_id`) REFERENCES `tbl_rider_remittances`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`order_id`) REFERENCES `tbl_orders`(`id`) ON DELETE CASCADE,
          UNIQUE KEY `uniq_remittance_order` (`remittance_id`, `order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Repair older installations that already have the table without newer columns.
    $columns = [
        'reference_number' => "ALTER TABLE `tbl_rider_remittances` ADD COLUMN `reference_number` VARCHAR(100) NULL AFTER `rider_id`",
        'remitted_at' => "ALTER TABLE `tbl_rider_remittances` ADD COLUMN `remitted_at` TIMESTAMP NULL DEFAULT NULL AFTER `requested_at`",
    ];
    foreach ($columns as $column => $alter_sql) {
        $column_check = $conn->query("SHOW COLUMNS FROM `tbl_rider_remittances` LIKE '$column'");
        if ($column_check && $column_check->num_rows === 0) {
            $conn->query($alter_sql);
        }
    }

    // Reference numbers are receipt identifiers and must be unique globally.
    // Keep NULL values allowed for legacy/admin-created records without a reference.
    $reference_index = $conn->query("SHOW INDEX FROM `tbl_rider_remittances` WHERE Key_name = 'uniq_remittance_reference'");
    if ($reference_index && $reference_index->num_rows === 0) {
        $duplicate_refs = $conn->query(
            "SELECT reference_number FROM tbl_rider_remittances
             WHERE reference_number IS NOT NULL AND reference_number <> ''
             GROUP BY reference_number HAVING COUNT(*) > 1 LIMIT 1"
        );
        if ($duplicate_refs && $duplicate_refs->num_rows > 0) {
            throw new RuntimeException('Duplicate remittance reference numbers already exist. Resolve the existing duplicates before enabling global reference validation.');
        }
        $conn->query("ALTER TABLE `tbl_rider_remittances` ADD UNIQUE KEY `uniq_remittance_reference` (`reference_number`)");
    }

    // 3. Add a column to tbl_orders to track if the COD payment has been settled by the rider
    $check_col_sql = "SHOW COLUMNS FROM `tbl_orders` LIKE 'payment_settled'";
    $res = $conn->query($check_col_sql);
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `tbl_orders` ADD COLUMN `payment_settled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `payment_method`;");
    }

    // 4. Add a rider_id to the delivery table to know who handled the delivery
    $check_rider_col_sql = "SHOW COLUMNS FROM `tbl_delivery` LIKE 'rider_id'";
    $res_rider = $conn->query($check_rider_col_sql);
    if ($res_rider && $res_rider->num_rows === 0) {
        $conn->query("ALTER TABLE `tbl_delivery` ADD COLUMN `rider_id` INT NULL AFTER `order_id`;");
        $conn->query("ALTER TABLE `tbl_delivery` ADD FOREIGN KEY (`rider_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;");
    }

    // For demonstration, let's ensure the user who confirms the QR code is assigned as the rider.
    // This should be integrated into your rider app's logic.
    // We will modify the QR confirm API to set this.
}

?>