<?php
/**
 * Achievements & Gamification Helper
 *
 * Manages the logic for awarding badges to customers based on their activity.
 */

/**
 * Ensures the necessary database tables for achievements exist.
 * Seeds the initial set of achievements if the table is empty.
 */
function achievements_ensure_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `achievements` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `achievement_code` VARCHAR(50) UNIQUE NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `description` TEXT NOT NULL,
        `icon_class` VARCHAR(50) NOT NULL,
        `criteria_type` VARCHAR(20) NOT NULL COMMENT 'e.g., purchases, points_earned, redemptions',
        `criteria_value` INT NOT NULL,
        `is_active` TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $conn->query("CREATE TABLE IF NOT EXISTS `customer_achievements` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `customer_id` INT NOT NULL,
        `achievement_id` INT NOT NULL,
        `unlocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_customer_achievement` (`customer_id`, `achievement_id`),
        FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`achievement_id`) REFERENCES `achievements`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Seed initial achievements if the table is empty
    $check = $conn->query("SELECT COUNT(*) as total FROM `achievements`");
    if ($check && $check->fetch_assoc()['total'] == 0) {
        $achievements = [
            ['PURCHASE_1', 'First Purchase', 'Awarded for making your first purchase.', 'fa-shopping-cart', 'purchases', 1],
            ['PURCHASE_5', 'Serial Shopper', 'Awarded for making 5 purchases.', 'fa-shopping-bag', 'purchases', 5],
            ['PURCHASE_10', 'Loyal Customer', 'Awarded for making 10 successful purchases.', 'fa-heart', 'purchases', 10],
            ['POINTS_500', 'Point Collector', 'Awarded for earning a total of 500 points.', 'fa-star', 'points_earned', 500],
            ['POINTS_1000', 'Point Master', 'Awarded for earning a total of 1,000 points.', 'fa-crown', 'points_earned', 1000],
            ['REDEEM_1', 'First Reward', 'Awarded for redeeming your first reward.', 'fa-gift', 'redemptions', 1],
        ];
        $stmt = $conn->prepare("INSERT INTO `achievements` (achievement_code, name, description, icon_class, criteria_type, criteria_value) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($achievements as $ach) {
            $stmt->bind_param('sssssi', $ach[0], $ach[1], $ach[2], $ach[3], $ach[4], $ach[5]);
            $stmt->execute();
        }
        $stmt->close();
    }
}

/**
 * Checks all achievement criteria for a customer and awards any new ones.
 * Creates a notification for each new achievement unlocked.
 */
function achievements_check_and_award(mysqli $conn, int $customer_id): void {
    if ($customer_id <= 0) return;

    // Get all active achievements definitions
    $all_achievements = $conn->query("SELECT * FROM achievements WHERE is_active = 1")->fetch_all(MYSQLI_ASSOC);
    if (empty($all_achievements)) return;

    // Get customer's unlocked achievements
    $unlocked_res = $conn->query("SELECT achievement_id FROM customer_achievements WHERE customer_id = $customer_id");
    $unlocked_ids = array_column($unlocked_res->fetch_all(MYSQLI_ASSOC), 'achievement_id');

    // Get customer stats
    $stats_query = "
        SELECT
            (SELECT COUNT(*) FROM tbl_orders WHERE customer_id = $customer_id AND order_status = 'completed') as total_purchases,
            (SELECT COALESCE(SUM(points_earned), 0) FROM loyalty_transactions WHERE customer_id = $customer_id AND points_earned > 0) as total_points_earned,
            (SELECT COUNT(*) FROM reward_redemptions WHERE customer_id = $customer_id) as total_redemptions
    ";
    $stats = $conn->query($stats_query)->fetch_assoc();

    $insert_stmt = $conn->prepare("INSERT INTO customer_achievements (customer_id, achievement_id) VALUES (?, ?)");

    foreach ($all_achievements as $ach) {
        if (in_array($ach['id'], $unlocked_ids)) {
            continue; // Already unlocked
        }

        $criteria_met = false;
        switch ($ach['criteria_type']) {
            case 'purchases':
                if ($stats['total_purchases'] >= $ach['criteria_value']) $criteria_met = true;
                break;
            case 'points_earned':
                if ($stats['total_points_earned'] >= $ach['criteria_value']) $criteria_met = true;
                break;
            case 'redemptions':
                if ($stats['total_redemptions'] >= $ach['criteria_value']) $criteria_met = true;
                break;
        }

        if ($criteria_met) {
            // Award the achievement
            $insert_stmt->bind_param('ii', $customer_id, $ach['id']);
            $insert_stmt->execute();
            $new_id = $conn->insert_id;

            // Send a notification to the customer
            notifications_create($conn, [
                'customer_id' => $customer_id,
                'type' => 'achievement_unlocked',
                'channel' => 'in_app',
                'title' => 'Achievement Unlocked: ' . $ach['name'],
                'message' => 'Congratulations! You have earned the "' . $ach['name'] . '" badge. View all your achievements in your profile.',
                'reference_table' => 'customer_achievements',
                'reference_id' => $new_id,
            ]);
        }
    }
    $insert_stmt->close();
}

?>