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
        // Give up on a slow/unreachable mail server quickly instead of PHPMailer's default 300 s,
        // which outlasted the web server's limit (504 Gateway Time-out).
        $mail->Timeout = 15;
        $mail->getSMTPInstance()->Timelimit = 20;
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
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Email delivery failed.'
            ];
        }
    }
}

if (!function_exists('stock_alert_bands')) {
    /**
     * "Critical" is fixed at 5 units (matches the store's own example:
     * Stock <= 5 = Critical) but never set higher than the product's own
     * configurable "low" threshold, so the bands can't invert if an admin
     * sets a very low custom threshold (e.g. threshold=3).
     */
    function stock_alert_bands(int $low_threshold): array
    {
        return ['critical' => min(5, $low_threshold), 'low' => $low_threshold];
    }
}

if (!function_exists('classify_stock_level')) {
    function classify_stock_level(int $stock, int $low_threshold): string
    {
        $bands = stock_alert_bands($low_threshold);
        if ($stock <= 0) return 'out';
        if ($stock <= $bands['critical']) return 'critical';
        if ($stock <= $bands['low']) return 'low';
        return 'normal';
    }
}

if (!function_exists('ensure_stock_alert_columns')) {
    /**
     * Defensive schema guard for the Low Stock Monitoring feature (same
     * SHOW COLUMNS / ALTER TABLE pattern used elsewhere, e.g.
     * modules/rider/rider_profile_api.php). tbl_product_inventory and
     * tbl_product_variants may not have low_stock_threshold /
     * stock_alert_level yet on an older or freshly-imported database
     * (evaluate_stock_alert() and modules/admin/products.php both assume
     * they exist) - this adds them once, then does nothing on every later
     * call. Safe to call on every request; the SHOW COLUMNS check is cheap
     * and idempotent, matching the existing convention in this codebase.
     */
    function ensure_stock_alert_columns(mysqli $conn): void
    {
        foreach (['tbl_product_inventory', 'tbl_product_variants'] as $table) {
            $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'low_stock_threshold'");
            if (!$check || $check->num_rows === 0) {
                $conn->query("ALTER TABLE `$table` ADD COLUMN `low_stock_threshold` INT NOT NULL DEFAULT 10");
            }
            $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'stock_alert_level'");
            if (!$check || $check->num_rows === 0) {
                $conn->query("ALTER TABLE `$table` ADD COLUMN `stock_alert_level` ENUM('normal','low','critical','out') NOT NULL DEFAULT 'normal'");
            }
        }
    }
}

if (!function_exists('evaluate_stock_alert')) {
    /**
     * Event-based Low Stock Monitoring + Gmail Alert.
     *
     * Call this right after a stock UPDATE succeeds (deduct_product_stock()
     * / restore_product_stock() in customer/includes/functions.php - the
     * one real chokepoint every order path already goes through). It only
     * acts when the stock LEVEL actually transitions (normal -> low ->
     * critical -> out, or back to normal on restock) - never on every
     * single unit sold - which is what stops a repeat email for e.g.
     * 9 -> 8 -> 7 while still inside the same "low" band, and is exactly
     * what resets the state once restocked past the threshold.
     *
     * @param string $table 'tbl_product_inventory' or 'tbl_product_variants'
     * @param int    $row_id The product id or variant id being changed.
     */
    function evaluate_stock_alert(mysqli $conn, string $table, int $row_id): void
    {
        if (!in_array($table, ['tbl_product_inventory', 'tbl_product_variants'], true)) {
            return;
        }

        ensure_stock_alert_columns($conn);

        if ($table === 'tbl_product_variants') {
            $stmt = $conn->prepare(
                "SELECT v.stock, v.low_stock_threshold, v.stock_alert_level, v.size, p.name AS product_name
                 FROM tbl_product_variants v
                 JOIN tbl_product_inventory p ON p.id = v.product_id
                 WHERE v.id = ?"
            );
        } else {
            $stmt = $conn->prepare(
                "SELECT stock, low_stock_threshold, stock_alert_level, name AS product_name, NULL AS size
                 FROM tbl_product_inventory WHERE id = ?"
            );
        }
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $row_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return;
        }

        $stock = (int) $row['stock'];
        $threshold = (int) $row['low_stock_threshold'];
        $old_level = (string) $row['stock_alert_level'];
        $new_level = classify_stock_level($stock, $threshold);

        if ($new_level === $old_level) {
            return; // No band change - nothing to do or send.
        }

        $update_stmt = $conn->prepare("UPDATE `$table` SET stock_alert_level = ? WHERE id = ?");
        if ($update_stmt) {
            $update_stmt->bind_param('si', $new_level, $row_id);
            $update_stmt->execute();
            $update_stmt->close();
        }

        // Escalating into low/critical/out sends one email for that new band.
        // Falling back to 'normal' (restocked) just quietly resets the state -
        // no email requested for that case in the spec.
        if (!in_array($new_level, ['low', 'critical', 'out'], true)) {
            return;
        }

        $display_name = $row['product_name'] . (!empty($row['size']) ? ' (' . $row['size'] . ')' : '');
        $level_labels = ['low' => 'Low Stock', 'critical' => 'Critical Stock', 'out' => 'Out of Stock'];
        $level_icons = ['low' => '🟠', 'critical' => '🔴', 'out' => '❌'];

        $title = $level_icons[$new_level] . ' ' . $level_labels[$new_level] . ' Alert - ' . SYSTEM_NAME;
        $message = "Product: " . $display_name . "\nCurrent Stock: " . $stock . " units\nLow Stock Threshold: " . $threshold . " units\n\nPlease consider restocking this product.";

        // Real recipients only - every admin account that has an email on file.
        // Reuses the existing PHPMailer infra (notifications_send_email());
        // no second email system, no SMS invented (none is configured).
        $admins_res = $conn->query("SELECT email FROM users WHERE role = 'admin' AND email IS NOT NULL AND email != ''");
        if ($admins_res) {
            while ($admin = $admins_res->fetch_assoc()) {
                notifications_send_email($admin['email'], $title, $message, true);
            }
        }
    }
}

if (!function_exists('notifications_render_order_receipt_html')) {
    /**
     * Builds the receipt HTML shared by BOTH:
     *   - notifications_send_order_receipt() below (the Gmail e-receipt), and
     *   - modules/admin/print_receipt.php (the Admin "Print E-Receipt" view).
     *
     * Everything that's identical between the two lives here exactly once
     * (customer/order info, product table, totals, Prepared-by, loyalty).
     * The two callers only differ in how the logo and QR images are embedded
     * (email needs PHPMailer cid: embedding; the print view uses plain
     * <img src> URLs), so those two pieces are built by the caller and
     * passed in as already-finished HTML/src strings rather than duplicated.
     *
     * @param array       $order    A tbl_orders row (as returned by get_order_by_id()).
     * @param array       $items    tbl_order_items rows (as returned by get_order_items()).
     * @param array       $customer A customers row (id, name, email, loyalty_points, ...).
     * @param mysqli|null $conn     Optional — used to look up the real "Prepared by"
     *        staff/admin name (tbl_orders.created_by_admin_id) and the real
     *        loyalty_transactions ledger. Safe to omit.
     * @param string $logo_src Finished <img src="..."> value for the DPS logo.
     * @param string $qr_html  Finished HTML block for the QR section (or '' to omit it).
     * @return string The full receipt HTML.
     */
    function notifications_render_order_receipt_html(array $order, array $items, array $customer, ?mysqli $conn, string $logo_src, string $qr_html): string
    {
        $order_number     = (string) ($order['order_number'] ?? ('DPS-' . ($order['id'] ?? '')));
        $customer_name    = trim((string) ($customer['name'] ?? '')) ?: 'Valued Customer';
        $fulfillment_type = strtolower((string) ($order['fulfillment_type'] ?? 'delivery'));
        $order_date       = !empty($order['created_at']) ? date('F j, Y g:i A', strtotime($order['created_at'])) : '—';
        $brand            = '#0f766e'; // Same teal used across the admin/staff dashboards.

        $money = static function ($v): string {
            return 'PHP ' . number_format((float) $v, 2);
        };
        $esc = static function ($v): string {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $dash = static function ($v) use ($esc): string {
            $v = trim((string) ($v ?? ''));
            return $v !== '' ? $esc($v) : '—';
        };

        // --- "Mode" + the one address/schedule line that goes with it ---
        if ($fulfillment_type === 'pickup') {
            $mode_label   = 'Pickup';
            $mode_caption = 'Pickup Schedule';
            $mode_value   = $dash(trim(($order['pickup_date'] ?? '') . ' ' . ($order['pickup_time'] ?? '')));
        } else {
            $mode_label   = 'Delivery';
            $mode_caption = 'Delivery Address';
            $mode_value   = $dash($order['delivery_address'] ?? '');
        }

        // --- Payment method label + any reference already on file (no raw DB ids exposed) ---
        $payment_labels = ['cod' => 'Cash on Delivery', 'pay_at_shop' => 'Cash (Pay at Shop)', 'gcash' => 'GCash', 'bank' => 'Bank Transfer'];
        $payment_key    = strtolower((string) ($order['payment_method'] ?? ''));
        $payment_label  = $payment_labels[$payment_key] ?? ucfirst($payment_key);

        $payment_ref_html = '';
        if ($payment_key === 'gcash' && !empty($order['payment_reference'])) {
            $payment_ref_html = "<p style='margin:4px 0 0;font-size:12px;color:#64748b;'>GCash Reference #: " . $esc($order['payment_reference']) . "</p>";
        } elseif ($payment_key === 'bank' && (!empty($order['payment_reference']) || !empty($order['bank_name']))) {
            $bank_bits = [];
            if (!empty($order['bank_name'])) $bank_bits[] = 'Bank: ' . $esc($order['bank_name']);
            if (!empty($order['bank_account_name'])) $bank_bits[] = 'Account Name: ' . $esc($order['bank_account_name']);
            if (!empty($order['payment_reference'])) $bank_bits[] = 'Reference #: ' . $esc($order['payment_reference']);
            $payment_ref_html = "<p style='margin:4px 0 0;font-size:12px;color:#64748b;'>" . implode('<br>', $bank_bits) . "</p>";
        }

        // --- Customer / Order information block (receipt-style label rows) ---
        $info_row = static function (string $label, string $value) use ($brand): string {
            return "<tr>
                <td style='padding:6px 4px;width:38%;font-size:11px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:{$brand};vertical-align:top;'>{$label}</td>
                <td style='padding:6px 4px;font-size:13.5px;color:#1f2937;'>{$value}</td>
            </tr>";
        };
        $info_html =
            $info_row('Customer', $esc($customer_name)) .
            $info_row($mode_caption, $mode_value) .
            $info_row('Mode', $esc($mode_label)) .
            $info_row('Order ID', '#' . $esc($order_number)) .
            $info_row('Date', $esc($order_date)) .
            $info_row('Payment', $esc($payment_label) . $payment_ref_html) .
            $info_row('Remarks', $dash($order['order_notes'] ?? ''));

        // --- Product table: Product Description | Variant | Unit | Qty | Price | Amount ---
        $total_items = count($items);
        $total_qty   = 0;
        $rows_html   = '';
        foreach ($items as $item) {
            $name       = $esc($item['product_name'] ?? 'Item');
            $variant    = $dash($item['variant_size'] ?? '');
            $qty        = (int) ($item['quantity'] ?? 0);
            $unit_price = (float) ($item['unit_price'] ?? 0);
            $line_total = (float) ($item['total_price'] ?? 0);
            $weight_kg  = (float) ($item['weight_kg'] ?? 0);
            // "Unit" = the real per-line weight already stored for this item
            // (same weight_kg tbl_order_items column the loyalty "Online
            // Purchase" transaction already reports), or "pc" for items that
            // aren't sold by weight. Not a new/invented field.
            $unit_label = $weight_kg > 0 ? rtrim(rtrim(number_format($weight_kg, 3), '0'), '.') . ' kg' : 'pc';
            $total_qty += $qty;
            $rows_html .= "<tr>
                <td style='padding:9px 6px;border-bottom:1px solid #f0f0f0;word-break:break-word;'>{$name}</td>
                <td style='padding:9px 6px;border-bottom:1px solid #f0f0f0;color:#64748b;'>{$variant}</td>
                <td style='padding:9px 6px;border-bottom:1px solid #f0f0f0;color:#64748b;white-space:nowrap;'>{$unit_label}</td>
                <td style='padding:9px 6px;border-bottom:1px solid #f0f0f0;text-align:center;'>{$qty}</td>
                <td style='padding:9px 6px;border-bottom:1px solid #f0f0f0;text-align:right;white-space:nowrap;'>" . $money($unit_price) . "</td>
                <td style='padding:9px 6px;border-bottom:1px solid #f0f0f0;text-align:right;white-space:nowrap;'>" . $money($line_total) . "</td>
            </tr>";
        }

        // --- Totals (only real, applicable fields — same figures tbl_orders already stores) ---
        $totals_html  = "<tr><td colspan='2' style='padding:3px 8px;text-align:right;color:#64748b;'>Total Items</td><td style='padding:3px 8px;text-align:right;color:#64748b;'>{$total_items}</td></tr>";
        $totals_html .= "<tr><td colspan='2' style='padding:3px 8px;text-align:right;color:#64748b;'>Total Quantity</td><td style='padding:3px 8px;text-align:right;color:#64748b;'>{$total_qty}</td></tr>";
        $totals_html .= "<tr><td colspan='2' style='padding:3px 8px;text-align:right;'>Subtotal</td><td style='padding:3px 8px;text-align:right;'>" . $money($order['subtotal'] ?? 0) . "</td></tr>";
        if ((float) ($order['vat_amount'] ?? 0) > 0) {
            $totals_html .= "<tr><td colspan='2' style='padding:3px 8px;text-align:right;'>VAT</td><td style='padding:3px 8px;text-align:right;'>" . $money($order['vat_amount']) . "</td></tr>";
        }
        if ((float) ($order['delivery_fee'] ?? 0) > 0) {
            $totals_html .= "<tr><td colspan='2' style='padding:3px 8px;text-align:right;'>Delivery Fee</td><td style='padding:3px 8px;text-align:right;'>" . $money($order['delivery_fee']) . "</td></tr>";
        }
        if ((float) ($order['discount_amount'] ?? 0) > 0) {
            $totals_html .= "<tr><td colspan='2' style='padding:3px 8px;text-align:right;'>Discount</td><td style='padding:3px 8px;text-align:right;color:#16a34a;'>- " . $money($order['discount_amount']) . "</td></tr>";
        }
        $totals_html .= "<tr><td colspan='2' style='padding:10px 8px;text-align:right;font-weight:700;font-size:16px;border-top:2px solid #1f2937;'>Grand Total</td><td style='padding:10px 8px;text-align:right;font-weight:700;font-size:16px;border-top:2px solid #1f2937;'>" . $money($order['total'] ?? 0) . "</td></tr>";

        // --- "Prepared by" — ONLY when this order genuinely has one on file
        // (Admin-Assisted Ordering sets tbl_orders.created_by_admin_id to the
        // real staff/admin who built it). No "Checked by / Received by /
        // Approved by" lines are shown — the system has no such data for any
        // order, and the brief is explicit: do not invent names.
        $prepared_by_html = '';
        if ($conn instanceof mysqli && !empty($order['created_by_admin_id'])) {
            $preparer_id = (int) $order['created_by_admin_id'];
            $stmt = $conn->prepare("SELECT first_name, last_name, role FROM users WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $preparer_id);
                $stmt->execute();
                $preparer = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($preparer) {
                    $preparer_name = trim($preparer['first_name'] . ' ' . $preparer['last_name']);
                    $prepared_by_html = "<table role='presentation' width='100%' style='margin-top:18px;border-top:1px dashed #cbd5e1;padding-top:12px;'>
                        <tr>
                            <td style='font-size:11px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:#94a3b8;'>Prepared by</td>
                            <td style='font-size:13px;color:#1f2937;text-align:right;'>" . $esc($preparer_name) . " (" . $esc(ucfirst($preparer['role'])) . ")</td>
                        </tr>
                    </table>";
                }
            }
        }

        // Order follows the DPS Header -> QR -> Receipt -> Order Details
        // layout of the physical delivery receipt this digital version is
        // based on. Table-based layout throughout (no flex/grid, no <style>
        // block, only inline styles) so it renders reliably both as an email
        // (Gmail desktop + mobile) and as a plain browser page (Admin print
        // view); the outer table is fluid (width 100% up to 620px) so it
        // scales down on narrow screens without horizontal scrolling.
        return "
                <table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background:#f1f5f9;padding:20px 0;'>
                <tr><td align='center'>
                <table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='max-width:620px;width:100%;background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;font-family:Arial,Helvetica,sans-serif;color:#1f2937;'>
                <tr><td style='padding:28px 24px 24px;'>

                    <div style='text-align:center;margin-bottom:22px;'>
                        <img src='{$logo_src}' alt='" . SYSTEM_NAME . " Logo' style='max-height:70px;width:auto;'>
                        <p style='margin:10px 0 0;font-size:19px;font-weight:800;letter-spacing:.04em;color:{$brand};'>DARIUS POULTRY SUPPLIES</p>
                    </div>

                    {$qr_html}

                    <table role='presentation' width='100%' style='border-top:2px dashed #cbd5e1;border-bottom:2px dashed #cbd5e1;'>
                        <tr><td style='padding:14px 0;text-align:center;'>
                            <span style='font-size:17px;font-weight:800;letter-spacing:.08em;color:#1f2937;'>DELIVERY RECEIPT</span><br>
                            <span style='font-size:12px;color:#94a3b8;'>Order #{$order_number} &bull; {$order_date}</span>
                        </td></tr>
                    </table>

                    <table role='presentation' width='100%' style='margin-top:16px;border-collapse:collapse;'>{$info_html}</table>

                    <table role='presentation' width='100%' style='width:100%;border-collapse:collapse;margin-top:18px;'>
                        <thead>
                            <tr style='background:{$brand};'>
                                <th style='padding:8px 6px;text-align:left;font-size:11px;color:#ffffff;'>Product Description</th>
                                <th style='padding:8px 6px;text-align:left;font-size:11px;color:#ffffff;'>Variant</th>
                                <th style='padding:8px 6px;text-align:left;font-size:11px;color:#ffffff;'>Unit</th>
                                <th style='padding:8px 6px;text-align:center;font-size:11px;color:#ffffff;'>Qty</th>
                                <th style='padding:8px 6px;text-align:right;font-size:11px;color:#ffffff;'>Price</th>
                                <th style='padding:8px 6px;text-align:right;font-size:11px;color:#ffffff;'>Amount</th>
                            </tr>
                        </thead>
                        <tbody style='font-size:13px;'>{$rows_html}</tbody>
                    </table>

                    <table role='presentation' width='100%' style='margin-top:6px;font-size:13.5px;'>{$totals_html}</table>

                    {$prepared_by_html}

                </td></tr>
                </table>
                </td></tr>
                </table>";
    }
}

if (!function_exists('notifications_send_order_receipt')) {
    /**
     * Emails the customer a full order e-receipt that embeds the SAME
     * Delivery Confirmation QR shown on customer/orders.php.
     *
     * The QR payload/logic is NOT reinvented here — it is the exact JSON
     * {delivery_id, order_id, token} built from tbl_delivery.qr_confirmation_token
     * (see customer/orders.php and ensure_order_delivery_token() in
     * customer/includes/functions.php, the single source of truth both share).
     * That is the same token the rider app / rider_update_status_api.php and
     * rider_qr_confirm_api.php validate, so scanning the emailed QR resolves
     * to this exact order — no second QR format, no invented reference.
     *
     * The actual receipt markup is built by notifications_render_order_receipt_html()
     * above (shared with the Admin "Print E-Receipt" view) — this function's
     * own job is just: generate the QR image bytes, embed the QR + logo via
     * PHPMailer's cid: mechanism (so Gmail never shows them as attachments),
     * and send.
     *
     * @param array $order    A tbl_orders row (as returned by get_order_by_id()).
     * @param array $items    tbl_order_items rows (as returned by get_order_items()).
     * @param array $delivery ['id' => int, 'qr_confirmation_token' => string] (ensure_order_delivery_token()).
     * @param array $customer A tbl_customer_records row (id, name, email, ...).
     * @param mysqli|null $conn Optional — only used to look up the staff/admin
     *        name for the "Prepared by" line when this order was placed via
     *        Admin-Assisted Ordering (tbl_orders.created_by_admin_id). Safe to
     *        omit; that line is simply left out when $conn isn't passed.
     * @return array ['success' => bool, 'error' => ?string]
     */
    function notifications_send_order_receipt(array $order, array $items, array $delivery, array $customer, ?mysqli $conn = null): array
    {
        $email_to = trim((string) ($customer['email'] ?? ''));
        if ($email_to === '' || !filter_var($email_to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'No valid email address on file for this account.'];
        }
        if (empty($delivery['id']) || empty($delivery['qr_confirmation_token'])) {
            return ['success' => false, 'error' => 'This order does not have an active delivery QR yet.'];
        }

        // --- The exact QR payload/logic used by customer/orders.php ---
        $qr_payload = json_encode([
            'delivery_id' => (int) $delivery['id'],
            'order_id'    => (int) $order['id'],
            'token'       => $delivery['qr_confirmation_token'],
        ]);

        $qr_bytes = null;
        if (class_exists('Endroid\\QrCode\\QrCode') && class_exists('Endroid\\QrCode\\Writer\\PngWriter')) {
            try {
                $qrCode = \Endroid\QrCode\QrCode::create($qr_payload);
                $writer = new \Endroid\QrCode\Writer\PngWriter();
                $qr_bytes = $writer->write($qrCode)->getString();
            } catch (Throwable $t) {
                $qr_bytes = null;
            }
        }
        if ($qr_bytes === null) {
            // Same QuickChart fallback customer/orders.php uses when Endroid is unavailable.
            $ctx = stream_context_create(['http' => ['timeout' => 6]]);
            $qr_bytes = @file_get_contents(
                'https://quickchart.io/qr?size=400&text=' . urlencode($qr_payload),
                false,
                $ctx
            ) ?: null;
        }

        $customer_name = trim((string) ($customer['name'] ?? '')) ?: 'Valued Customer';
        $order_number  = (string) ($order['order_number'] ?? ('DPS-' . ($order['id'] ?? '')));

        try {
            $mail = notifications_mailer();
            $mail->addAddress($email_to, $customer_name);
            $mail->Subject = SYSTEM_NAME . ' - E-Receipt - Order #' . $order_number;

            $logo_physical_path = realpath(__DIR__ . '/../../assets/img/loyalty_logo.png');
            if ($logo_physical_path && file_exists($logo_physical_path)) {
                $mail->addEmbeddedImage($logo_physical_path, 'company_logo');
                $logo_src = 'cid:company_logo';
            } else {
                $logo_src = SYSTEM_LOGO_URL;
            }

            $qr_html = '';
            if ($qr_bytes) {
                $mail->addStringEmbeddedImage($qr_bytes, 'delivery_qr', 'delivery_qr.png', PHPMailer::ENCODING_BASE64, 'image/png');
                $qr_html = "
                    <div style='text-align:center;margin:0 0 24px;padding:20px 16px;background:#f9fafb;border-radius:10px;border:1px dashed #cbd5e1;'>
                        <img src='cid:delivery_qr' alt='Delivery Confirmation QR' width='200' height='200' style='width:200px;max-width:60vw;height:auto;border:5px solid #ffffff;box-shadow:0 2px 8px rgba(0,0,0,0.12);border-radius:8px;'>
                        <p style='margin:12px 0 0;font-weight:700;color:#111827;font-size:14px;'>Delivery Confirmation QR</p>
                        <p style='margin:4px 0 0;font-size:12.5px;color:#64748b;'>Please show this QR code to the rider for delivery confirmation.</p>
                        <p style='margin:8px 0 0;font-size:11px;color:#94a3b8;'>Delivery Ref ID: #" . (int) $delivery['id'] . "</p>
                    </div>";
            }

            $mail->Body = notifications_render_order_receipt_html($order, $items, $customer, $conn, $logo_src, $qr_html);
            $mail->send();

            return ['success' => true, 'error' => null];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
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

        // NOTE: position 10 (email_to) must be 's'. It was previously 'd', which
        // silently cast every recipient address to 0 in the `email_to` column
        // (email delivery itself was unaffected – it uses the local $email_to).
        $stmt->bind_param(
            "iisssssidsss",
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
            $send_email = function () use ($conn, $notification_id, $email_to, $title, $message, $omit_prefix) {
                $email_result = notifications_send_email($email_to, $title, $message, $omit_prefix);
                if ($email_result['success']) {
                    notifications_update_delivery_status($conn, $notification_id, 'sent');
                } else {
                    notifications_update_delivery_status($conn, $notification_id, 'failed', $email_result['error']);
                }
                return (bool) $email_result['success'];
            };
            if (!empty($data['email_now'])) {
                $delivery_result['email_sent'] = $send_email();
            } else {
                // Sent after the page reaches the browser, so a slow mail server never holds up
                // (or times out) the page that created the notification.
                require_once __DIR__ . '/after_response_helper.php';
                app_after_response($send_email);
                $delivery_result['email_queued'] = true;
            }
        }

        // Push the notification in real-time to the target (rider, admin,
        // staff, or customer) if they have an active WebSocket connection.
        // Non-blocking / silent on failure either way - the row above is
        // already saved regardless, so nothing is lost if the WS server is
        // down or the recipient isn't currently connected.
        if ($user_id > 0) {
            // users.id spans admin/staff/rider - look up which one this
            // recipient actually is so ChatServer's role-scoped connection
            // map (see ws/ChatServer.php's connectionsByRoleId) can find the
            // right connection instead of a bare, collision-prone id.
            $role_stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
            $role_stmt->bind_param('i', $user_id);
            $role_stmt->execute();
            $recipient_role = strtolower(trim((string) ($role_stmt->get_result()->fetch_assoc()['role'] ?? '')));
            $role_stmt->close();

            if ($recipient_role !== '') {
                ws_push_notification(
                    $conn,
                    $user_id,
                    $notification_id,
                    $title,
                    $message,
                    $reference_table,
                    $reference_id,
                    date('Y-m-d H:i:s'),
                    $recipient_role
                );
            }
        }
        if ($db_customer_id !== null) {
            // Separate `if` (not else-if) on purpose: user_id and
            // customer_id are independent recipients, and while no existing
            // caller sets both at once, nothing stops one from wanting to in
            // the future - both would genuinely need their own push.
            ws_push_notification(
                $conn,
                (int) $db_customer_id,
                $notification_id,
                $title,
                $message,
                $reference_table,
                $reference_id,
                date('Y-m-d H:i:s'),
                'customer'
            );
        }

        return true;
    }
}

if (!function_exists('notifications_recipient_sql')) {
    /**
     * WHERE clause selecting the notifications addressed to one account.
     * Returns [sql, bind types, bind args].
     *
     * How rows are addressed in this table: when customer_id is set, the
     * notification is FOR that customer (user_id then only records the staff
     * member who acted, e.g. point_adjustment / rider_status_update) — except
     * the rider-directed types below, which carry the customer only as context
     * and are delivered TO the rider in user_id: 'customer_message' (a
     * customer's chat message) and 'rider_reminder' ("prepare the exact COD
     * amount", modules/rider/update_rider_order.php). Rows with only user_id
     * are for that user (admin, staff or rider).
     *
     * @param string $kind 'customer' (customers.id) or 'user' (users.id: admin/staff/rider)
     */
    function notifications_recipient_sql(string $kind, int $account_id): array
    {
        $rider_directed = "'customer_message', 'rider_reminder'";
        if ($kind === 'customer') {
            return ["customer_id = ? AND type NOT IN ($rider_directed) AND type <> 'rider_to_receive_email'", 'i', [$account_id]];
        }
        return ["user_id = ? AND (customer_id IS NULL OR type IN ($rider_directed))", 'i', [$account_id]];
    }
}

if (!function_exists('notifications_toast_tone')) {
    /** 'ok' | 'error' | 'info' tone for a notification toast, from its type/title. */
    function notifications_toast_tone(string $type, string $title): string
    {
        $text = strtolower($type . ' ' . $title);
        if (preg_match('/cancel|reject|fail|discrepan|declin|expired/', $text)) {
            return 'error';
        }
        if (preg_match('/complet|confirm|verified|earned|deliver|success|approved|ready/', $text)) {
            return 'ok';
        }
        return 'info';
    }
}

if (!function_exists('notifications_link_for')) {
    /**
     * Page a notification should open for the recipient's portal (null when there is no web page for it).
     * $customer_id (notifications.customer_id) lets a rider's chat notification open that customer's chat.
     */
    function notifications_link_for(string $role, string $type, string $reference_table, int $reference_id, int $customer_id = 0): ?string
    {
        $base = rtrim(BASE_URL, '/');
        if ($role === 'customer') {
            switch ($reference_table) {
                case 'tbl_orders':
                    return $reference_id > 0 ? "$base/customer/orders.php?id=$reference_id" : "$base/customer/orders.php";
                case 'tbl_messages':
                    return $type === 'rider_message' ? "$base/customer/rider_chat.php" : "$base/customer/messages.php";
            }
            if (strpos($type, 'point') !== false) {
                return "$base/customer/reward_catalog.php";
            }
            if ($reference_table === 'tbl_free_shipping_rewards') {
                return "$base/customer/my_rewards.php#free-shipping";
            }
            return "$base/customer/notifications.php";
        }
        if ($role === 'admin' || $role === 'staff') {
            $dir = $role === 'admin' ? 'admin' : 'staff';
            switch ($reference_table) {
                case 'tbl_orders':
                    return $reference_id > 0 ? "$base/modules/$dir/order_details.php?id=$reference_id" : "$base/modules/$dir/orders.php";
                case 'tbl_messages':
                    return "$base/modules/$dir/messages.php";
                case 'tbl_rider_remittances':
                    return "$base/modules/$dir/remittance_management.php";
                case 'tbl_product_reviews':
                    return "$base/modules/$dir/reviews.php";
                case 'riders': // rider availability changes (rider_dispatch_helper.php)
                    return $role === 'admin' ? "$base/modules/admin/ebike_maintenance.php#riders" : null;
            }
            return null;
        }
        if ($role === 'rider') { // web Rider Portal (/rider/)
            if ($type === 'return_pickup') {
                return "$base/rider/returns.php";
            }
            if ($type === 'delivery_reassigned') { // the order went to another rider
                return "$base/rider/deliveries.php";
            }
            switch ($reference_table) {
                case 'tbl_orders':
                    return $reference_id > 0 ? "$base/rider/order.php?id=$reference_id" : "$base/rider/deliveries.php";
                case 'tbl_messages':
                    return $customer_id > 0 ? "$base/rider/chat.php?customer_id=$customer_id" : "$base/rider/messages.php";
                case 'tbl_rider_remittances':
                    return "$base/rider/remittance.php";
            }
            return "$base/rider/notifications.php";
        }
        return null;
    }
}

if (!function_exists('notifications_get_unread_count')) {
    function notifications_get_unread_count(mysqli $conn, int $user_id): int
    {
        // Only rows addressed to this user (see notifications_recipient_sql()).
        [$scope, $types, $args] = notifications_recipient_sql('user', $user_id);
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE $scope AND is_read = 0");
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param($types, ...$args);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return (int) ($result['total'] ?? 0);
    }
}

if (!function_exists('notifications_get_recent')) {
    function notifications_get_recent(mysqli $conn, int $user_id, int $limit = 6): array
    {
        $limit = max(1, min(20, $limit));
        [$scope, $types, $args] = notifications_recipient_sql('user', $user_id);
        $stmt = $conn->prepare(
            "SELECT id, title, message, type, is_read, created_at, customer_id, reference_table, reference_id
             FROM notifications
             WHERE $scope
             ORDER BY created_at DESC
             LIMIT ?"
        );

        if (!$stmt) {
            return [];
        }

        $types .= 'i';
        $args[] = $limit;
        $stmt->bind_param($types, ...$args);
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
        // Same rows the badge counts (notifications_recipient_sql()), so marking read never flips a
        // customer-addressed row whose user_id only records the staff member / rider who acted.
        [$scope, $types, $args] = notifications_recipient_sql('user', $user_id);
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE $scope AND is_read = 0");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param($types, ...$args);

        return $stmt->execute();
    }
}

if (!function_exists('notifications_mark_one_read')) {
    /** Marks one of this user's notifications read (only rows addressed to them, see notifications_recipient_sql()). */
    function notifications_mark_one_read(mysqli $conn, int $user_id, int $notification_id): bool
    {
        [$scope, $types, $args] = notifications_recipient_sql('user', $user_id);
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND $scope AND is_read = 0");
        if (!$stmt) {
            return false;
        }
        $types = 'i' . $types;
        array_unshift($args, $notification_id);
        $stmt->bind_param($types, ...$args);
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