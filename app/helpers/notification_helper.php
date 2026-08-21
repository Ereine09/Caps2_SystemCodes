<?php
date_default_timezone_set('Asia/Manila');

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../PHPMailer/Exception.php';
require_once __DIR__ . '/../../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../../PHPMailer/SMTP.php';
require_once __DIR__ . '/ws_push_helper.php';

if (!function_exists('notifications_sync_customer_loyalty_points')) {
    function notifications_sync_customer_loyalty_points($conn, $customer_id) {
        // 1. Calculate total points earned from loyalty_transactions
        $earned_query = mysqli_query(
            $conn,
            "SELECT COALESCE(SUM(points_earned), 0) AS total_earned
             FROM loyalty_transactions
             WHERE customer_id = " . (int)$customer_id
        );
        $total_earned = (float) (mysqli_fetch_assoc($earned_query)['total_earned'] ?? 0.00);

        // 2. Calculate total points redeemed from reward_redemptions
        $redeemed_query = mysqli_query(
            $conn,
            "SELECT COALESCE(SUM(points_used), 0) AS total_redeemed
             FROM reward_redemptions
             WHERE customer_id = " . (int)$customer_id
        );
        $total_redeemed = (float) ((mysqli_fetch_assoc($redeemed_query)['total_redeemed'] ?? 0.00));

        // Points = earned - redeemed (single source of truth = transaction tables)
        $new_total_points = (float) ($total_earned - $total_redeemed);
        if ($new_total_points < 0) {
            $new_total_points = 0.00;
        }

        // 3. Update the customer's loyalty_points in the customers table to sync the data
        mysqli_query($conn, "UPDATE customers SET loyalty_points = $new_total_points WHERE id = " . (int)$customer_id);

        return $new_total_points;
    }
}

if (!function_exists('notifications_ensure_schema')) {
    function notifications_ensure_schema(mysqli $conn): void
    {
        mysqli_query(
            $conn,
            "CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT DEFAULT NULL,
                customer_id INT DEFAULT NULL,
                type VARCHAR(50) NOT NULL,
                channel ENUM('in_app','email','both') NOT NULL DEFAULT 'in_app',
                title VARCHAR(150) NOT NULL,
                message TEXT NOT NULL,
                reference_table VARCHAR(50) DEFAULT NULL,
                reference_id INT DEFAULT NULL,
                points_value DECIMAL(10,2) DEFAULT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_at DATETIME DEFAULT NULL,
                email_to VARCHAR(255) DEFAULT NULL,
                delivery_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
                delivery_error TEXT DEFAULT NULL,
                sent_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_notifications_user_read_created (user_id, is_read, created_at),
                INDEX idx_notifications_customer_read_created (customer_id, is_read, created_at),
                INDEX idx_notifications_status_channel (delivery_status, channel),
                INDEX idx_notifications_type_created (type, created_at),
                INDEX idx_notifications_reference (reference_table, reference_id)
            )"
        );

        // Fix column updates
        $user_id_col = mysqli_query($conn, "SHOW COLUMNS FROM notifications LIKE 'user_id'");
        if ($user_id_col && mysqli_num_rows($user_id_col) === 0) {
            mysqli_query($conn, "ALTER TABLE notifications ADD COLUMN user_id INT DEFAULT NULL AFTER id");
        }

        $customer_id_col = mysqli_query($conn, "SHOW COLUMNS FROM notifications LIKE 'customer_id'");
        if ($customer_id_col && mysqli_num_rows($customer_id_col) === 0) {
            mysqli_query($conn, "ALTER TABLE notifications ADD COLUMN customer_id INT DEFAULT NULL AFTER user_id");
        }

        $delivery_error_column = mysqli_query($conn, "SHOW COLUMNS FROM notifications LIKE 'delivery_error'");
        if ($delivery_error_column && mysqli_num_rows($delivery_error_column) === 0) {
            mysqli_query($conn, "ALTER TABLE notifications ADD COLUMN delivery_error TEXT DEFAULT NULL AFTER delivery_status");
        }
    }
}

if (!function_exists('notifications_send_login_otp')) {
    /**
     * Sends a dynamic 6-digit authentication code to the Admin's Gmail.
     */
    function notifications_send_login_otp(string $email_to, string $otp_code): array
    {
        $title = "Your Security Verification Code";
        $message = "A login attempt was made for the Admin Panel.\n\nYour verification code is: " . $otp_code . "\n\nThis code will expire in 5 minutes. If this wasn't you, please secure your account.";
        
        return notifications_send_email($email_to, $title, $message, true);
    }
}

if (!function_exists('notifications_send_admin_login_alert')) {
    /**
     * Sends a confirmation alert after a successful login.
     */
    function notifications_send_admin_login_alert(string $admin_email, string $username): array
    {
        $time = date('F j, Y, g:i a');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $title = "Security Alert: Successful Login";
        $message = "A successful login was detected for account: " . $username . "\nTime: " . $time . "\nIP Address: " . $ip . "\n\nIf you recognize this activity, no action is needed.";
        
        return notifications_send_email($admin_email, $title, $message, true);
    }
}

if (!function_exists('notifications_format_points')) {
    function notifications_format_points(float $value): string
    {
        return number_format($value, 2);
    }
}

if (!function_exists('notifications_expiry_months')) {
    function notifications_expiry_months(): int
    {
        return (int) LOYALTY_POINTS_EXPIRY_MONTHS;
    }
}

if (!function_exists('notifications_email_subject_prefix')) {
    function notifications_email_subject_prefix(bool $omit = false): string
    {
        return $omit ? '' : SYSTEM_NAME;
    }
}

if (!function_exists('notifications_mailer')) {
    function notifications_mailer(): PHPMailer
    {
        $mail = new PHPMailer(true); // Enable exceptions for better error handling
        $mail->SMTPDebug = 0; // Set to 0 to disable output and allow redirects
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = SMTP_AUTH;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = (SMTP_PORT === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->isHTML(true);

        return $mail;
    }
}

if (!function_exists('notifications_send_email')) {
    function notifications_send_email(string $email_to, string $title, string $message, bool $omit_prefix = false): array
    {
        if ($email_to === '' || !filter_var($email_to, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'Invalid recipient email address.'
            ];
        }

        try {
            $mail = notifications_mailer();
            $mail->addAddress($email_to);
            $prefix = notifications_email_subject_prefix($omit_prefix);
            $mail->Subject = $prefix ? ($prefix . ' - ' . $title) : $title;

            // Use CID embedding for the logo so it works on localhost/Gmail
            $logo_physical_path = realpath(__DIR__ . '/../../assets/img/loyalty_logo.png');
            if ($logo_physical_path && file_exists($logo_physical_path)) {
                $mail->addEmbeddedImage($logo_physical_path, 'company_logo');
                $logo_src = 'cid:company_logo';
            } else {
                $logo_src = SYSTEM_LOGO_URL;
            }

            $mail->Body = "
                <div style='font-family: Arial, sans-serif; border: 1px solid #e0e0e0; padding: 30px; border-radius: 8px; max-width: 600px; margin: 20px auto; color: #333;'>
                    <div style='text-align: center; margin-bottom: 25px;'>
                        <img src='" . $logo_src . "' alt='" . SYSTEM_NAME . " Logo' style='max-height: 80px; width: auto;'>
                    </div>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #4a3e94; margin: 0;'>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</h2>
                    </div>
                    <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                    <p>Hello valued user,</p>
                    <p style='line-height: 1.6;'>" . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . "</p>
                    <div style='background-color: #f9f9f9; padding: 15px; border-left: 4px solid #4a3e94; margin-top: 25px;'>
                        <p style='margin: 0; font-size: 13px; color: #666;'>
                            <strong>Security Notice:</strong> This is an automated message from the " . SYSTEM_NAME . " Loyalty Management System.
                            " . ($omit_prefix ? "This verification code is time-sensitive." : "Note: Loyalty points expire after " . notifications_expiry_months() . " months.") . "
                        </p>
                    </div>
                    <p style='font-size: 12px; color: #999; text-align: center; margin-top: 20px;'>
                        © " . date('Y') . " " . SYSTEM_NAME . " Administration.
                    </p>
                </div>";

            $mail->send();

            return [
                'success' => true,
                'error' => null
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

if (!function_exists('notifications_update_delivery_status')) {
    function notifications_update_delivery_status(mysqli $conn, int $notification_id, string $status, ?string $error = null): void
    {
        $stmt = $conn->prepare(
            "UPDATE notifications
             SET delivery_status = ?, delivery_error = ?, sent_at = CASE WHEN ? = 'sent' THEN NOW() ELSE sent_at END
             WHERE id = ?"
        );

        if (!$stmt) {
            return;
        }

        $stmt->bind_param("sssi", $status, $error, $status, $notification_id);
        $stmt->execute();
    }
}

if (!function_exists('notifications_create')) {
    function notifications_create(mysqli $conn, array $data, ?array &$delivery_result = null): bool
    {
        $delivery_result = [
            'saved' => false,
            'email_sent' => false,
        ];
        $user_id = isset($data['user_id']) ? (int) $data['user_id'] : 0;
        $customer_id = isset($data['customer_id']) ? (int) $data['customer_id'] : 0;
        $type = trim((string) ($data['type'] ?? 'general'));
        $channel = trim((string) ($data['channel'] ?? 'in_app'));
        $title = trim((string) ($data['title'] ?? 'Notification'));
        $message = trim((string) ($data['message'] ?? ''));
        $reference_table = isset($data['reference_table']) ? trim((string) $data['reference_table']) : '';
        $reference_id = isset($data['reference_id']) ? (int) $data['reference_id'] : 0;
        $points_value = isset($data['points_value']) ? (float) $data['points_value'] : 0.00;
        $email_to = isset($data['email_to']) ? trim((string) $data['email_to']) : '';

        if ($message === '') {
            return false;
        }

        if (!in_array($channel, ['in_app', 'email', 'both'], true)) {
            $channel = 'in_app';
        }

        $delivery_status = $channel === 'in_app' ? 'sent' : 'pending';
        $stmt = $conn->prepare(
            "INSERT INTO notifications (
                user_id,
                customer_id,
                type,
                channel,
                title,
                message,
                reference_table,
                reference_id,
                points_value,
                email_to,
                delivery_status,
                sent_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            return false;
        }

        $db_user_id = $user_id > 0 ? $user_id : null;
        $db_customer_id = $customer_id > 0 ? $customer_id : null;
        $db_reference_table = $reference_table !== '' ? $reference_table : null;
        $db_reference_id = $reference_id > 0 ? $reference_id : null;
        $db_points_value = $points_value > 0 ? $points_value : null;
        $db_email_to = $email_to !== '' ? $email_to : null;
        $sent_at = $delivery_status === 'sent' ? date('Y-m-d H:i:s') : null;

        $stmt->bind_param(
            "iisssssiddss",
            $db_user_id,
            $db_customer_id,
            $type,
            $channel,
            $title,
            $message,
            $db_reference_table,
            $db_reference_id,
            $db_points_value,
            $db_email_to,
            $delivery_status,
            $sent_at
        );

        $saved = $stmt->execute();
        if (!$saved) {
            return false;
        }

        $delivery_result['saved'] = true;

        $notification_id = (int) $conn->insert_id;

        if (in_array($channel, ['email', 'both'], true)) {
            $omit_prefix = in_array($type, ['security_sim', 'security_alert'], true);
            $email_result = notifications_send_email($email_to, $title, $message, $omit_prefix);

            $delivery_result['email_sent'] = (bool) $email_result['success'];
            if ($email_result['success']) {
                notifications_update_delivery_status($conn, $notification_id, 'sent');
            } else {
                notifications_update_delivery_status($conn, $notification_id, 'failed', $email_result['error']);
            }
        }

        // Push the notification in real-time to the target user (e.g. a rider)
        // if they have an active WebSocket connection. Non-blocking / silent on failure.
        if ($user_id > 0) {
            ws_push_notification(
                $conn,
                $user_id,
                $notification_id,
                $title,
                $message,
                $reference_table,
                $reference_id,
                date('Y-m-d H:i:s')
            );
        }

        return true;
    }
}

if (!function_exists('notifications_get_unread_count')) {
    function notifications_get_unread_count(mysqli $conn, int $user_id): int
    {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0");
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return (int) ($result['total'] ?? 0);
    }
}

if (!function_exists('notifications_get_recent')) {
    function notifications_get_recent(mysqli $conn, int $user_id, int $limit = 6): array
    {
        $limit = max(1, min(20, $limit));
        $stmt = $conn->prepare(
            "SELECT id, title, message, type, is_read, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?"
        );

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }

        return $notifications;
    }
}

if (!function_exists('notifications_mark_all_read')) {
    function notifications_mark_all_read(mysqli $conn, int $user_id): bool
    {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("i", $user_id);

        return $stmt->execute();
    }
}

if (!function_exists('notifications_reward_thresholds')) {
    function notifications_reward_thresholds(): array
    {
        return [250.00, 500.00, 1000.00];
    }
}

if (!function_exists('notifications_crossed_thresholds')) {
    function notifications_crossed_thresholds(float $before_points, float $after_points): array
    {
        $crossed = [];

        foreach (notifications_reward_thresholds() as $threshold) {
            if ($before_points < $threshold && $after_points >= $threshold) {
                $crossed[] = $threshold;
            }
        }

        return $crossed;
    }
}

if (!function_exists('notifications_expired_points_for_customer')) {
    function notifications_expired_points_for_customer(mysqli $conn, int $customer_id): float
    {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(points_earned), 0) AS expired_points
             FROM loyalty_transactions
             WHERE customer_id = ?
               AND created_at < DATE_SUB(NOW(), INTERVAL ? MONTH)"
        );

        if (!$stmt) {
            return 0.00;
        }

        $months = notifications_expiry_months();
        $stmt->bind_param("ii", $customer_id, $months);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return (float) ($result['expired_points'] ?? 0.00);
    }
}

if (!function_exists('notifications_available_points_for_customer')) {
    function notifications_available_points_for_customer(mysqli $conn, int $customer_id): float
    {
        $earned_stmt = $conn->prepare(
            "SELECT COALESCE(SUM(points_earned), 0) AS active_points
             FROM loyalty_transactions
             WHERE customer_id = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)"
        );

        if (!$earned_stmt) {
            return 0.00;
        }

        $months = notifications_expiry_months();
        $earned_stmt->bind_param("ii", $customer_id, $months);
        $earned_stmt->execute();
        $earned_result = $earned_stmt->get_result()->fetch_assoc();
        $active_points = (float) ($earned_result['active_points'] ?? 0.00);

        $redeemed_stmt = $conn->prepare(
            "SELECT COALESCE(SUM(points_used), 0) AS redeemed_points
             FROM reward_redemptions
             WHERE customer_id = ?"
        );

        if (!$redeemed_stmt) {
            return max(0, $active_points);
        }

        $redeemed_stmt->bind_param("i", $customer_id);
        $redeemed_stmt->execute();
        $redeemed_result = $redeemed_stmt->get_result()->fetch_assoc();
        $redeemed_points = (float) ($redeemed_result['redeemed_points'] ?? 0.00);

        return max(0, $active_points - $redeemed_points);
    }
}

if (!function_exists('notifications_seed_expiring_points')) {
    function notifications_seed_expiring_points(mysqli $conn): void
    {
        notifications_ensure_schema($conn);

        $existing_stmt = $conn->prepare(
            "SELECT id
             FROM notifications
             WHERE type = 'points_expiring'
               AND DATE(created_at) = CURDATE()
             LIMIT 1"
        );

        if (!$existing_stmt) {
            return;
        }

        $existing_stmt->execute();
        $existing_result = $existing_stmt->get_result();
        if ($existing_result && $existing_result->num_rows > 0) {
            return;
        }

        $users_result = mysqli_query($conn, "SELECT id, email FROM users ORDER BY id ASC LIMIT 1");
        $sample_user = $users_result ? mysqli_fetch_assoc($users_result) : null;

        $customers_result = mysqli_query(
            $conn,
            "SELECT id, name, email
             FROM customers
             ORDER BY id ASC"
        );

        if (!$sample_user || !$customers_result) {
            return;
        }

        while ($sample_customer = mysqli_fetch_assoc($customers_result)) {
            $customer_id = (int) ($sample_customer['id'] ?? 0);
            if ($customer_id <= 0) {
                continue;
            }

            $expired_points = notifications_expired_points_for_customer($conn, $customer_id);
            if ($expired_points <= 0) {
                continue;
            }

            $available_points = notifications_sync_customer_loyalty_points($conn, $customer_id);
            $message = $sample_customer['name'] . " has " . notifications_format_points($expired_points) . " expired points after " . notifications_expiry_months() . " months. Current usable balance: " . notifications_format_points($available_points) . " points.";

            notifications_create($conn, [
                'user_id' => (int) $sample_user['id'],
                'customer_id' => $customer_id,
                'type' => 'points_expiring',
                'channel' => 'in_app',
                'title' => 'Points expiring soon',
                'message' => $message,
                'points_value' => $expired_points,
                'email_to' => $sample_user['email'] ?? null
            ]);

            break;
        }
    }
}
?>