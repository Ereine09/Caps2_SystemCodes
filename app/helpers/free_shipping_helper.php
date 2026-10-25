<?php
/**
 * Free Shipping rewards: every 10 completed orders a customer places in one calendar month earns a
 * Free Shipping reward (10, 20, 30 ... in the same month each earn one).
 *
 *   earned    -> shows on My Vouchers with a Claim button; must be claimed within 7 days
 *   claimed   -> a one-use voucher (tbl_vouchers.discount_type = 'free_shipping', owned by the
 *                customer through tbl_vouchers.owner_customer_id) that takes the delivery fee off
 *                one delivery order; usable for FREE_SHIP_VOUCHER_DAYS after claiming
 *   expired   -> not claimed within 7 days
 *
 * "Purchases" = online orders that reached Completed (or Reviews), counted in the month they were
 * placed, so cancelled orders never count. Only months from when this feature was switched on are
 * counted (system_settings FREE_SHIPPING_SINCE), so old order history doesn't suddenly earn rewards.
 *
 * Checked by free_shipping_sync(): on the customer's My Vouchers / checkout pages and every minute
 * by the background tasks (customer/includes/background_tasks.php) for recently completed orders.
 */

const FREE_SHIP_ORDERS_PER_REWARD = 10;
const FREE_SHIP_CLAIM_DAYS = 7;
const FREE_SHIP_VOUCHER_DAYS = 30;

if (!function_exists('free_shipping_ensure_schema')) {
    function free_shipping_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $conn->query("CREATE TABLE IF NOT EXISTS tbl_free_shipping_rewards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            period CHAR(7) NOT NULL,
            milestone INT NOT NULL,
            earned_at DATETIME NOT NULL,
            claim_by DATETIME NOT NULL,
            claimed_at DATETIME NULL,
            voucher_code VARCHAR(100) NULL,
            UNIQUE KEY uq_fs_reward (customer_id, period, milestone),
            KEY idx_fs_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $res = $conn->query("SHOW COLUMNS FROM tbl_vouchers LIKE 'owner_customer_id'");
        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE tbl_vouchers ADD COLUMN owner_customer_id INT NULL AFTER usage_limit");
        }
        $res = $conn->query("SHOW COLUMNS FROM tbl_vouchers LIKE 'discount_type'");
        $type = $res ? (string) ($res->fetch_assoc()['Type'] ?? '') : '';
        if ($type !== '' && strpos($type, 'free_shipping') === false) {
            $conn->query("ALTER TABLE tbl_vouchers MODIFY discount_type ENUM('fixed','percent','free_shipping') NOT NULL DEFAULT 'fixed'");
        }
        // The month the feature started: earlier months are never counted.
        $since = date('Y-m');
        $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('FREE_SHIPPING_SINCE', ?)");
        if ($stmt) {
            $stmt->bind_param('s', $since);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('free_shipping_since')) {
    function free_shipping_since(mysqli $conn): string
    {
        $row = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'FREE_SHIPPING_SINCE' LIMIT 1");
        $v = $row ? (string) ($row->fetch_assoc()['setting_value'] ?? '') : '';
        return preg_match('/^\d{4}-\d{2}$/', $v) ? $v : date('Y-m');
    }
}

if (!function_exists('free_shipping_sync')) {
    /** Records any newly earned rewards for this customer (and notifies them). Returns how many were added. */
    function free_shipping_sync(mysqli $conn, int $customer_id): int
    {
        if ($customer_id <= 0) {
            return 0;
        }
        free_shipping_ensure_schema($conn);
        $since = free_shipping_since($conn) . '-01';
        $stmt = $conn->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS period, COUNT(*) AS n
             FROM tbl_orders
             WHERE customer_id = ? AND order_status IN ('completed', 'reviews') AND created_at >= ?
             GROUP BY period"
        );
        $stmt->bind_param('is', $customer_id, $since);
        $stmt->execute();
        $months = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $added = 0;
        foreach ($months as $m) {
            $earned = intdiv((int) $m['n'], FREE_SHIP_ORDERS_PER_REWARD);
            for ($k = 1; $k <= $earned; $k++) {
                $milestone = $k * FREE_SHIP_ORDERS_PER_REWARD;
                $ins = $conn->prepare(
                    "INSERT IGNORE INTO tbl_free_shipping_rewards (customer_id, period, milestone, earned_at, claim_by)
                     VALUES (?, ?, ?, NOW(), NOW() + INTERVAL " . FREE_SHIP_CLAIM_DAYS . " DAY)"
                );
                $ins->bind_param('isi', $customer_id, $m['period'], $milestone);
                $ins->execute();
                $new = $ins->affected_rows === 1;
                $reward_id = (int) $conn->insert_id;
                $ins->close();
                if (!$new) {
                    continue;
                }
                $added++;
                if (function_exists('notifications_create')) {
                    try {
                        notifications_create($conn, [
                            'customer_id'     => $customer_id,
                            'type'            => 'free_shipping_earned',
                            'channel'         => 'in_app',
                            'title'           => 'You earned a Free Shipping voucher!',
                            'message'         => "Thanks for your {$milestone} orders this month. Claim your Free Shipping voucher on My Vouchers within "
                                . FREE_SHIP_CLAIM_DAYS . ' days, or it will expire.',
                            'reference_table' => 'tbl_free_shipping_rewards',
                            'reference_id'    => $reward_id,
                        ]);
                    } catch (Throwable $e) {
                        error_log('free_shipping notify failed: ' . $e->getMessage());
                    }
                }
            }
        }
        return $added;
    }
}

if (!function_exists('free_shipping_sync_recent')) {
    /** Background task: checks customers whose orders were completed in the last 2 days. */
    function free_shipping_sync_recent(mysqli $conn): void
    {
        free_shipping_ensure_schema($conn);
        $res = $conn->query("SELECT DISTINCT customer_id FROM tbl_orders
                             WHERE order_status IN ('completed', 'reviews') AND updated_at >= NOW() - INTERVAL 2 DAY AND customer_id IS NOT NULL");
        while ($res && ($row = $res->fetch_assoc())) {
            free_shipping_sync($conn, (int) $row['customer_id']);
        }
    }
}

if (!function_exists('free_shipping_rewards')) {
    /**
     * The customer's Free Shipping rewards, newest first, each with 'state':
     * claimable | claimed (voucher ready) | used | voucher_expired | expired (not claimed in time).
     */
    function free_shipping_rewards(mysqli $conn, int $customer_id): array
    {
        free_shipping_ensure_schema($conn);
        $stmt = $conn->prepare(
            "SELECT r.*, v.used_count, v.usage_limit, v.expires_at AS voucher_expires_at
             FROM tbl_free_shipping_rewards r
             LEFT JOIN tbl_vouchers v ON v.code = r.voucher_code
             WHERE r.customer_id = ?
             ORDER BY r.earned_at DESC, r.id DESC"
        );
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $now = time();
        foreach ($rows as &$r) {
            if ($r['claimed_at'] === null) {
                $r['state'] = strtotime($r['claim_by']) >= $now ? 'claimable' : 'expired';
            } elseif ((int) $r['usage_limit'] > 0 && (int) $r['used_count'] >= (int) $r['usage_limit']) {
                $r['state'] = 'used';
            } elseif (!empty($r['voucher_expires_at']) && strtotime($r['voucher_expires_at']) < $now) {
                $r['state'] = 'voucher_expired';
            } else {
                $r['state'] = 'claimed';
            }
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('free_shipping_progress')) {
    /** ['count' => completed orders placed this month, 'next' => orders to the next reward]. */
    function free_shipping_progress(mysqli $conn, int $customer_id): array
    {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS n FROM tbl_orders
             WHERE customer_id = ? AND order_status IN ('completed', 'reviews')
               AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        );
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return ['count' => $n, 'next' => FREE_SHIP_ORDERS_PER_REWARD - ($n % FREE_SHIP_ORDERS_PER_REWARD)];
    }
}

if (!function_exists('free_shipping_claim')) {
    /** Claims an earned reward: creates the customer's one-use Free Shipping voucher. */
    function free_shipping_claim(mysqli $conn, int $customer_id, int $reward_id): array
    {
        free_shipping_ensure_schema($conn);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT * FROM tbl_free_shipping_rewards WHERE id = ? AND customer_id = ? FOR UPDATE");
            $stmt->bind_param('ii', $reward_id, $customer_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$r) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Reward not found.'];
            }
            if ($r['claimed_at'] !== null) {
                $conn->rollback();
                return ['ok' => true, 'message' => 'Already claimed. Your voucher code is ' . $r['voucher_code'] . '.', 'code' => $r['voucher_code']];
            }
            if (strtotime($r['claim_by']) < time()) {
                $conn->rollback();
                return ['ok' => false, 'message' => 'Sorry, this reward expired. It had to be claimed within ' . FREE_SHIP_CLAIM_DAYS . ' days.'];
            }
            $code = 'FS-' . strtoupper(bin2hex(random_bytes(5)));
            $desc = 'Free Shipping Voucher';
            $days = FREE_SHIP_VOUCHER_DAYS;
            $v = $conn->prepare("INSERT INTO tbl_vouchers (code, description, discount_type, discount_value, usage_limit, owner_customer_id, active, expires_at)
                                 VALUES (?, ?, 'free_shipping', 0, 1, ?, 1, NOW() + INTERVAL $days DAY)");
            $v->bind_param('ssi', $code, $desc, $customer_id);
            $v->execute();
            $v->close();
            $u = $conn->prepare("UPDATE tbl_free_shipping_rewards SET claimed_at = NOW(), voucher_code = ? WHERE id = ?");
            $u->bind_param('si', $code, $reward_id);
            $u->execute();
            $u->close();
            $conn->commit();
            return ['ok' => true, 'message' => "Claimed! Use voucher {$code} at checkout within {$days} days for free delivery.", 'code' => $code];
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('free_shipping_claim failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not claim the reward. Please try again.'];
        }
    }
}
