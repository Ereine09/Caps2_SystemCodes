<?php
/**
 * Staff — Process IN-STORE / WALK-IN reward redemptions.
 * Same page as modules/customers/reward_redemption.php (idempotency nonce, row-locking,
 * unique redemption code, customer search, print report) — only the auth gate and the
 * two self-redirect URLs differ, since staff has its own portal.
 *
 * The ONLINE channel is a completely separate, customer-facing flow —
 * customer/reward_redemption.php (self-service "Redeem" on the reward catalog) +
 * customer/checkout.php (applying the resulting voucher code as an order discount).
 * Both channels write to the SAME `reward_redemptions` table (reused, not duplicated)
 * but are kept strictly separate by the `channel` column: an IN-STORE redemption here
 * is marked USED immediately (it's consumed the moment staff confirms it) and NEVER
 * gets a row in `tbl_vouchers`, so it can never be entered as an online discount code.
 */
require_once __DIR__ . '/../shared/staff_auth.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$payload = require_staff();
$username = $payload['username'];
$user_id = (int) ($payload['user_id'] ?? 0);
$role = 'staff';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

if (isset($_POST['mark_notifications_read'])) {
    notifications_mark_all_read($conn, $user_id);
    header('Location: reward_redemption.php');
    exit();
}
$notification_count = notifications_get_unread_count($conn, $user_id);
$recent_notifications = notifications_get_recent($conn, $user_id, 6);

// --- Database Schema Checks (additive only; nothing here drops/recreates
// data — see the final report for the exact migration and why) ---
$loyaltyColumn = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'loyalty_points'");
if ($loyaltyColumn && mysqli_num_rows($loyaltyColumn) === 0) {
    mysqli_query($conn, "ALTER TABLE customers ADD COLUMN loyalty_points DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER address");
}

mysqli_query(
    $conn,
    "CREATE TABLE IF NOT EXISTS reward_redemptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        user_id INT NULL,
        reward_code VARCHAR(100) NOT NULL,
        reward_name VARCHAR(255) NOT NULL,
        points_used DECIMAL(10,2) NOT NULL,
        redeemed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        card_number VARCHAR(20) NULL,
        pin_code VARCHAR(10) NULL,
        expiry_date DATE NULL,
        status VARCHAR(20) DEFAULT 'ACTIVE',
        channel VARCHAR(20) NOT NULL DEFAULT 'IN_STORE',
        INDEX idx_customer_id (customer_id),
        INDEX idx_user_id (user_id)
    )"
);
// Same idempotent ALTERs as customer/reward_redemption.php (the online
// flow) — whichever page a fresh database sees first ends up with the same
// schema either way. `channel` distinguishes ONLINE vs IN_STORE redemptions;
// `card_number` (the redemption/voucher code) is made unique at the
// database level because a code is a security boundary, not just a label —
// an application-level "does this already exist" check alone cannot rule
// out a race between two near-simultaneous redemptions.
mysqli_query($conn, "ALTER TABLE reward_redemptions ADD COLUMN IF NOT EXISTS card_number VARCHAR(20) NULL");
mysqli_query($conn, "ALTER TABLE reward_redemptions ADD COLUMN IF NOT EXISTS pin_code VARCHAR(10) NULL");
mysqli_query($conn, "ALTER TABLE reward_redemptions ADD COLUMN IF NOT EXISTS expiry_date DATE NULL");
mysqli_query($conn, "ALTER TABLE reward_redemptions ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'ACTIVE'");
mysqli_query($conn, "ALTER TABLE reward_redemptions ADD COLUMN IF NOT EXISTS channel VARCHAR(20) NOT NULL DEFAULT 'IN_STORE'");
mysqli_query($conn, "ALTER TABLE reward_redemptions ADD UNIQUE KEY IF NOT EXISTS uniq_card_number (card_number)");
mysqli_query($conn, "ALTER TABLE reward_redemptions MODIFY COLUMN user_id INT NULL");

mysqli_query(
    $conn,
    "CREATE TABLE IF NOT EXISTS rewards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reward_code VARCHAR(100) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        points DECIMAL(10,2) NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        description TEXT
    )"
);

// Seed initial rewards if table is empty
$check_rewards = mysqli_query($conn, "SELECT COUNT(*) as total FROM rewards");
$reward_count = mysqli_fetch_assoc($check_rewards)['total'];
if ($reward_count == 0) {
    mysqli_query($conn, "INSERT INTO rewards (reward_code, name, points, stock, description) VALUES
        ('free_item_1000', 'Free Item', 1000.00, 5, 'Example reward: redeem 1000 points for one free item.'),
        ('free_catfood_500', 'Free 0.5kg Cat Food', 500.00, 10, 'Half-kilo cat food reward for repeat buyers.'),
        ('free_treat_pack_250', 'Free Treat Pack', 250.00, 20, 'Small thank-you reward for loyal clients.')");
}

// Fetch rewards from database
$reward_catalog = [];
$res_rewards = mysqli_query($conn, "SELECT * FROM rewards ORDER BY points DESC");
while ($r = mysqli_fetch_assoc($res_rewards)) {
    $reward_catalog[$r['reward_code']] = $r;
}

/**
 * A unique in-store redemption code, e.g. SHOP-20260906-4F2A9C. The loop
 * here just avoids a user-visible failure on a (vanishingly unlikely)
 * collision — the UNIQUE KEY on card_number (added above) is the real,
 * database-level guarantee; the INSERT itself is still wrapped in a
 * retry below in case two requests generate the same code at the exact
 * same instant.
 */
function generate_instore_redemption_code(mysqli $conn): string {
    do {
        $code = 'SHOP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $conn->prepare("SELECT id FROM reward_redemptions WHERE card_number = ? LIMIT 1");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } while ($exists);
    return $code;
}

$success_message = '';
$error_message = '';
$selected_customer = (int) ($_POST['customer_id'] ?? 0);
$selected_reward_code = trim($_POST['reward_code'] ?? '');

// --- Idempotency token -----------------------------------------------------
// A fresh one-time token is minted for every page render. A POST must
// present the exact token that was current when the form was rendered, and
// it is consumed (rotated) the instant it's checked, before any database
// work happens — so a double-click, a browser "resend form data?" replay,
// or a retried request can never redeem twice. PHP's session is
// lock-serialized per-request, so two truly simultaneous submits from the
// same browser tab are also naturally serialized here, not just this one.
// This is a UX/idempotency safety net layered on top of the real
// protection below (the SELECT ... FOR UPDATE row lock) — not a
// replacement for it, and it isn't the only thing disabling the submit
// button client-side (see the JS at the bottom).
if (empty($_SESSION['redeem_nonce'])) {
    $_SESSION['redeem_nonce'] = bin2hex(random_bytes(16));
}

// --- MAIN LOGIC: REDEEM REWARD (IN-STORE / WALK-IN) ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_reward'])) {
    $submitted_nonce = (string) ($_POST['redeem_nonce'] ?? '');
    $expected_nonce = (string) ($_SESSION['redeem_nonce'] ?? '');
    $_SESSION['redeem_nonce'] = bin2hex(random_bytes(16)); // rotate regardless of outcome

    if ($expected_nonce === '' || !hash_equals($expected_nonce, $submitted_nonce)) {
        $error_message = 'This redemption request has already been processed or has expired. Please try again.';
    } elseif ($selected_customer <= 0) {
        $error_message = 'Please select a customer first.';
    } elseif ($selected_reward_code === '' || !isset($reward_catalog[$selected_reward_code])) {
        $error_message = 'Please choose a valid reward from the catalog.';
    } else {
        $conn->begin_transaction();
        try {
            // 1. Lock the customer row FIRST, then compute the authoritative
            // balance INSIDE the transaction. Any concurrent redemption
            // request for this same customer now blocks on this same lock
            // until we commit or roll back — that's what actually prevents
            // two near-simultaneous redemptions from both reading the same
            // "before" balance (see the report for the exact race this
            // closes vs. the previous version of this page).
            $stmt = $conn->prepare("SELECT name, email FROM customers WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $selected_customer);
            $stmt->execute();
            $customer_data = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$customer_data) {
                throw new Exception("Customer not found.");
            }
            $customer_name = $customer_data['name'];
            $customer_email = $customer_data['email'] ?? '';

            // Authoritative balance = SUM(loyalty_transactions.points_earned)
            // - SUM(reward_redemptions.points_used), recomputed now that the
            // customer row is locked — never the value submitted by the
            // browser, and never a value computed before this lock.
            $current_points = (float) notifications_sync_customer_loyalty_points($conn, $selected_customer);

            // 2. Lock the reward row and verify it's still redeemable.
            $reward_stmt = $conn->prepare("SELECT id, name, points, stock FROM rewards WHERE reward_code = ? FOR UPDATE");
            $reward_stmt->bind_param("s", $selected_reward_code);
            $reward_stmt->execute();
            $reward_data = $reward_stmt->get_result()->fetch_assoc();
            $reward_stmt->close();

            if (!$reward_data) {
                throw new Exception("Reward not found.");
            }
            if ($reward_data['stock'] <= 0) {
                throw new Exception("Sorry, " . $reward_data['name'] . " is out of stock.");
            }

            $points_required = (float) $reward_data['points'];
            $reward_name = $reward_data['name'];

            // 3. The balance actually retrieved from MySQL decides this —
            // never a points value submitted by the browser.
            if ($current_points < $points_required) {
                throw new Exception("$customer_name does not have enough points (Need: " . number_format($points_required, 2) . ", Has: " . number_format($current_points, 2) . ").");
            }

            // 4. Decrement stock — the WHERE stock > 0 makes this an atomic
            // conditional decrement, on top of the FOR UPDATE lock above.
            $update_stock = $conn->prepare("UPDATE rewards SET stock = stock - 1 WHERE id = ? AND stock > 0");
            $update_stock->bind_param("i", $reward_data['id']);
            $update_stock->execute();
            if ($update_stock->affected_rows !== 1) {
                throw new Exception("Sorry, " . $reward_name . " just went out of stock.");
            }

            // 5. Record the redemption. channel=IN_STORE, status=USED
            // immediately: unlike an online voucher (which stays ACTIVE
            // until it's applied at checkout), an in-store redemption is
            // already fully consumed the moment staff confirms it here —
            // there is no later "use" step. Retried a few times purely to
            // absorb the (extremely unlikely) chance that the code
            // generated above collided with one inserted by another
            // request between the check and this INSERT — the UNIQUE KEY
            // on card_number is what actually catches that.
            $redemption_id = null;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $redemption_code = generate_instore_redemption_code($conn);
                try {
                    $redeem_stmt = $conn->prepare(
                        "INSERT INTO reward_redemptions (customer_id, user_id, reward_code, reward_name, points_used, card_number, status, channel)
                         VALUES (?, ?, ?, ?, ?, ?, 'USED', 'IN_STORE')"
                    );
                    $redeem_stmt->bind_param("iissds", $selected_customer, $user_id, $selected_reward_code, $reward_name, $points_required, $redemption_code);
                    $redeem_stmt->execute();
                    $redemption_id = (int) $conn->insert_id;
                    $redeem_stmt->close();
                    break;
                } catch (mysqli_sql_exception $dup) {
                    if ($dup->getCode() !== 1062 || $attempt === 2) {
                        throw $dup;
                    }
                    // Duplicate code — extremely unlikely, but loop and try
                    // a freshly generated one.
                }
            }

            $remaining_points = notifications_sync_customer_loyalty_points($conn, $selected_customer);

            notifications_create($conn, [
                'user_id' => $user_id,
                'customer_id' => $selected_customer,
                'type' => 'reward_redeemed',
                'channel' => 'both',
                'title' => 'Reward redeemed successfully',
                'message' => $customer_name . ' redeemed ' . $reward_name . ' in-store using ' . notifications_format_points($points_required) . ' points (code ' . $redemption_code . '). Remaining usable balance: ' . notifications_format_points($remaining_points) . ' points.',
                'reference_table' => 'reward_redemptions',
                'reference_id' => $redemption_id,
                'points_value' => $points_required,
                'email_to' => $customer_email
            ]);

            $log_details = "$customer_name redeemed " . number_format($points_required, 2) . " points for $reward_name (IN-STORE, code $redemption_code)";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, customer_id, action, details) VALUES (?, ?, 'REWARD_REDEMPTION', ?)");
            $log_stmt->bind_param("iis", $user_id, $selected_customer, $log_details);
            $log_stmt->execute();
            $log_stmt->close();

            $conn->commit();

            // Post/Redirect/Get: reloading the result page just re-fetches
            // this same redemption by id — it can never resubmit the form
            // and redeem a second time.
            header('Location: reward_redemption.php?redeemed=' . $redemption_id);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            // Only ever show the customer/staff our own deliberate
            // throw new Exception(...) messages above. A raw DB error
            // (mysqli_sql_exception, thanks to mysqli_report() in
            // app/config/config.php) is logged, never displayed.
            if ($e instanceof mysqli_sql_exception) {
                error_log('[reward_redemption] ' . $e->getMessage());
                $error_message = 'The redemption could not be completed. Please try again.';
            } else {
                $error_message = $e->getMessage();
            }
        }
    }
}

// --- Fetch a just-completed redemption for the result panel (PRG target) --
$completed_redemption = null;
$redeemed_id = (int) ($_GET['redeemed'] ?? 0);
if ($redeemed_id > 0) {
    $cr_stmt = $conn->prepare(
        "SELECT rr.*, c.name AS customer_name, c.email AS customer_email, u.username AS processed_by
         FROM reward_redemptions rr
         LEFT JOIN customers c ON c.id = rr.customer_id
         LEFT JOIN users u ON u.id = rr.user_id
         WHERE rr.id = ? AND rr.channel = 'IN_STORE'
         LIMIT 1"
    );
    $cr_stmt->bind_param("i", $redeemed_id);
    $cr_stmt->execute();
    $completed_redemption = $cr_stmt->get_result()->fetch_assoc();
    $cr_stmt->close();
    if ($completed_redemption) {
        $completed_redemption['remaining_points'] = notifications_sync_customer_loyalty_points($conn, (int) $completed_redemption['customer_id']);
    }
}

// --- Fetch data for display ---
$customers = [];
$customers_result = mysqli_query($conn, "
    SELECT
        id,
        name,
        email,
        ((SELECT COALESCE(SUM(points_earned), 0) FROM loyalty_transactions WHERE customer_id = customers.id) -
         (SELECT COALESCE(SUM(points_used), 0) FROM reward_redemptions WHERE customer_id = customers.id)) AS loyalty_points
    FROM customers
    ORDER BY name ASC");
if ($customers_result) {
    while ($row = mysqli_fetch_assoc($customers_result)) {
        $customers[] = $row;
    }
}

$total_redemptions = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM reward_redemptions"))['total'] ?? 0);
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$unread_messages_count = get_unread_count_staff($user_id);

$total_points_redeemed = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(points_used), 0) AS total FROM reward_redemptions"))['total'] ?? 0);

$redemption_history = [];
$history_result = mysqli_query(
    $conn,
    "SELECT rr.reward_name, rr.points_used, rr.redeemed_at, rr.card_number, rr.channel, rr.status,
            c.name AS customer_name, u.username AS processed_by
     FROM reward_redemptions rr
     LEFT JOIN customers c ON c.id = rr.customer_id
     LEFT JOIN users u ON u.id = rr.user_id
     ORDER BY rr.redeemed_at DESC
     LIMIT 15"
);
if ($history_result) {
    while ($row = mysqli_fetch_assoc($history_result)) { $redemption_history[] = $row; }
}

/** Small helper: pick an existing dark-mode-ready badge class per channel/status. */
function redemption_channel_badge(string $channel): string {
    return strtoupper($channel) === 'ONLINE' ? 'badge-in_transit' : 'brand-chip';
}
function redemption_status_badge(string $status): string {
    switch (strtoupper($status)) {
        case 'ACTIVE': return 'badge-approved';
        case 'USED': return 'badge-rejected';
        case 'EXPIRED': return 'badge-pending';
        default: return 'badge-inactive'; // CANCELLED / unknown
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reward Redemption - Staff</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        :root {
            --primary-purple: #4a3e94;
            --border-color: var(--border);
            --text-dark: #1e293b;
            --text-muted: #64748b;
        }

        body {
            background-color: var(--surface-2);
            margin: 0;
            font-family: inherit;
        }

        .welcome-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .welcome-header h1 {
            margin: 0;
            font-size: 1.6rem;
            color: var(--text-dark);
        }

        /* Notification Panel */
        .notification-wrapper {
            position: relative;
        }

        .notification-bell {
            background: var(--surface);
            border: 1px solid var(--border-color);
            padding: 10px 14px;
            border-radius: 10px;
            cursor: pointer;
            position: relative;
            font-size: 1.1rem;
            color: var(--text-dark);
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 999px;
        }

        .notification-dropdown {
            position: absolute;
            right: 0;
            top: 45px;
            width: 320px;
            background: var(--surface);
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            z-index: 1050;
            display: none;
            overflow: hidden;
        }

        .notification-dropdown.active {
            display: block;
        }

        .notif-header {
            padding: 12px 16px;
            background: var(--surface-2);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .notif-item {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            font-size: 0.85rem;
        }

        .notif-item:last-child {
            border-bottom: none;
        }

        .notif-title {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 2px;
        }

        .notif-time {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        /* Stats & Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--surface);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            text-align: center;
        }

        .stat-card i {
            font-size: 2rem;
            margin-bottom: 8px;
        }

        .stat-card h3 {
            margin: 0;
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .stat-card p {
            margin: 5px 0 0 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        /* Form Box & Table Box */
        .content-box {
            background: var(--surface);
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 25px;
        }

        .content-box h2 {
            margin-top: 0;
            font-size: 1.15rem;
            color: var(--text-dark);
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .channel-note {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--primary-soft);
            color: var(--link);
            border: 1px solid #c7d2fe;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-group select, .form-group input {
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            outline: none;
        }

        .btn-primary {
            background: var(--primary-purple);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary:hover {
            opacity: 0.9;
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-print {
            background: #0284c7;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Table */
        .styled-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .styled-table th, .styled-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .styled-table th {
            background: var(--surface-2);
            color: var(--text-dark);
            font-weight: 700;
        }

        code.redemption-code {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            background: var(--surface-2);
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.82rem;
        }

        /* Small pill badges — reuse the exact classes already used (and
           already dark-mode-styled in assets/css/admin_style.css) for
           status/channel chips elsewhere in the Admin system: badge-approved
           (green), badge-rejected (red), badge-pending (amber),
           badge-inactive (neutral), badge-in_transit (blue), brand-chip
           (indigo). No new visual system introduced here. */
        .status-badge, code.redemption-code { display: inline-block; }
        .badge-approved, .badge-rejected, .badge-pending, .badge-inactive, .badge-in_transit, .brand-chip {
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .alert-success { background: var(--success-soft); color: var(--success); border: 1px solid #bbf7d0; }
        .alert-error { background: var(--danger-soft); color: var(--danger); border: 1px solid #fecaca; }

        /* Result panel shown after a successful in-store redemption
           (Post/Redirect/Get target — ?redeemed=<id>) */
        .result-panel {
            border: 1px solid #bbf7d0;
            background: var(--success-soft);
        }
        .result-panel h2 { color: var(--success); }
        .result-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px 24px;
            margin: 16px 0;
        }
        .result-grid .label { font-size: 0.78rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; }
        .result-grid .value { font-size: 1.02rem; color: var(--text-dark); font-weight: 700; margin-top: 2px; }

        /* Confirmation modal shown before the real submit fires */
        .confirm-modal { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 2000; align-items: center; justify-content: center; padding: 20px; }
        .confirm-modal.active { display: flex; }
        .confirm-modal-box { background: var(--surface); border-radius: 14px; max-width: 420px; width: 100%; padding: 26px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); }
        .confirm-modal-box h3 { margin: 0 0 6px; color: var(--text-dark); }
        .confirm-modal-box .sub { color: var(--text-muted); font-size: 0.85rem; margin-bottom: 18px; }
        .confirm-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed var(--border-color); font-size: 0.92rem; }
        .confirm-row .k { color: var(--text-muted); }
        .confirm-row .v { font-weight: 700; color: var(--text-dark); }
        .confirm-actions { display: flex; gap: 10px; margin-top: 20px; }
        .confirm-actions button { flex: 1; padding: 11px; border-radius: 8px; font-weight: 700; cursor: pointer; border: 1px solid var(--border-color); }
        .confirm-actions .btn-cancel-modal { background: var(--surface-2); color: var(--text-dark); }
        .confirm-actions .btn-confirm-modal { background: var(--primary-purple); color: white; border-color: var(--primary-purple); }
        .confirm-actions .btn-confirm-modal:disabled { background: #cbd5e1; border-color: var(--border); color: var(--muted); cursor: not-allowed; opacity: 1; }
        .cf-warning { display: none; align-items: flex-start; gap: 10px; background: var(--danger-soft); color: var(--danger); border: 1px solid #fecaca; border-radius: 8px; padding: 10px 12px; font-size: 0.85rem; font-weight: 600; margin-bottom: 16px; }
        .cf-warning.active { display: flex; }

        .customer-search { margin-bottom: 6px; }

        /* Simple, print-only business report. Everything else on the page
           (sidebar, forms, buttons, notification panel, stat cards) is
           hidden via `body * { visibility: hidden }` and only
           .print-report-only is made visible again — more robust than
           hiding each screen element one by one, and guarantees nothing
           but the report ever prints. */
        @media print {
            body * { visibility: hidden; }
            .print-report-only, .print-report-only * { visibility: visible; }
            .print-report-only {
                display: block !important;
                position: absolute;
                top: 0; left: 0;
                width: 100%;
                margin: 0;
                padding: 0;
            }
            @page { margin: 1.5cm; }
        }

        .print-report-only { display: none; }
        .print-report-only h1 { font-size: 18px; margin: 0 0 2px; text-align: center; letter-spacing: .5px; }
        .print-report-only h2 { font-size: 14px; margin: 0 0 10px; text-align: center; font-weight: 700; letter-spacing: .5px; }
        .print-report-only .print-meta { text-align: center; font-size: 12px; color: var(--text); margin: 0 0 14px; line-height: 1.5; }
        .print-report-only hr { border: none; border-top: 1px solid #000; margin: 14px 0; }
        .print-report-only .print-section-title { font-weight: 700; font-size: 13px; letter-spacing: .5px; margin-bottom: 8px; }
        .print-report-only table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 6px; }
        .print-report-only .print-summary-table td { padding: 3px 0; }
        .print-report-only .print-summary-table td:first-child { width: 60%; }
        .print-report-only .print-summary-table td:last-child { text-align: right; font-weight: 600; }
        .print-report-only .print-details-table th,
        .print-report-only .print-details-table td { border-bottom: 1px solid #999; padding: 5px 6px; text-align: left; }
        .print-report-only .print-signature { margin-top: 50px; font-size: 12px; }
    </style>
</head>
<body>

    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">

        <!-- Printable Report (screen-hidden; shown only via @media print) -->
        <div class="print-report-only">
            <h1><?php echo htmlspecialchars(SYSTEM_NAME); ?></h1>
            <h2>REWARD REDEMPTION REPORT</h2>
            <p class="print-meta">Generated: <?php echo date('F j, Y g:i A'); ?> by <?php echo htmlspecialchars($username); ?></p>
            <hr>
            <div class="print-section-title">SUMMARY</div>
            <table class="print-summary-table">
                <tr><td>Total Redemptions</td><td><?php echo number_format($total_redemptions); ?></td></tr>
                <tr><td>Total Points Redeemed</td><td><?php echo number_format($total_points_redeemed, 2); ?> pts</td></tr>
            </table>
            <hr>
            <div class="print-section-title">DETAILS &mdash; Recent Redemption Transactions</div>
            <table class="print-details-table">
                <thead>
                    <tr><th>Customer</th><th>Reward</th><th>Points Used</th><th>Code</th><th>Channel</th><th>Status</th><th>Redeemed Date</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($redemption_history)): ?>
                        <?php foreach ($redemption_history as $history): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($history['customer_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars($history['reward_name']); ?></td>
                                <td><?php echo number_format($history['points_used'], 2); ?></td>
                                <td><?php echo htmlspecialchars($history['card_number'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($history['channel']); ?></td>
                                <td><?php echo htmlspecialchars($history['status']); ?></td>
                                <td><?php echo date('M j, Y g:i A', strtotime($history['redeemed_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7">No redemption history found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p style="font-size: 11px; color: #444; margin-top: 8px;">
                Note: every row above is a completed redemption &mdash; this system rolls back and never records a redemption that fails (insufficient points, out of stock, etc.), so there is no separate pending/failed status to report.
            </p>
            <p class="print-signature">Prepared by: ____________________________</p>
        </div>

        <!-- Header Bar -->
        <div class="welcome-header">
            <div>
                <h1><i class="fas fa-gift" style="color: var(--primary-purple);"></i> Reward Redemption <span class="channel-note"><i class="fas fa-store"></i> In-Store / Walk-in</span></h1>
                <p style="margin: 4px 0 0 0; color: var(--text-muted); font-size: 0.9rem;">Process a walk-in customer's reward redemption in the physical store. Online redemptions are handled separately by the customer on the website and are never usable here or vice versa.</p>
            </div>

            <div style="display: flex; gap: 12px; align-items: center;">
                <button type="button" onclick="window.print()" class="btn-print">
                    <i class="fas fa-print"></i> Print Report
                </button>

                <!-- Notifications Dropdown -->
                <div class="notification-wrapper">
                    <div class="notification-bell" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge"><?php echo $notification_count; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="notification-dropdown" id="notifDropdown">
                        <div class="notif-header">
                            <span>Notifications</span>
                            <?php if ($notification_count > 0): ?>
                                <form method="POST" style="margin: 0;">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" name="mark_notifications_read" style="background: none; border: none; color: var(--primary-purple); font-size: 0.8rem; cursor: pointer; font-weight: 600;">Mark all read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($recent_notifications)): ?>
                            <div class="notif-item" style="text-align: center; color: var(--text-muted);">No notifications</div>
                        <?php else: ?>
                            <?php foreach ($recent_notifications as $notif): ?>
                                <div class="notif-item">
                                    <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                    <div style="color: #475569;"><?php echo htmlspecialchars($notif['message']); ?></div>
                                    <div class="notif-time"><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Alerts -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Redemption Successful result panel (PRG target: ?redeemed=id) -->
        <?php if ($completed_redemption): ?>
            <div class="content-box result-panel">
                <h2><i class="fas fa-check-circle"></i> Redemption Successful</h2>
                <div class="result-grid">
                    <div>
                        <div class="label">Customer</div>
                        <div class="value"><?php echo htmlspecialchars($completed_redemption['customer_name'] ?? 'Unknown'); ?></div>
                    </div>
                    <div>
                        <div class="label">Reward</div>
                        <div class="value"><?php echo htmlspecialchars($completed_redemption['reward_name']); ?></div>
                    </div>
                    <div>
                        <div class="label">Points Used</div>
                        <div class="value"><?php echo number_format($completed_redemption['points_used'], 2); ?> points</div>
                    </div>
                    <div>
                        <div class="label">Remaining Points</div>
                        <div class="value"><?php echo number_format((float) $completed_redemption['remaining_points'], 2); ?> points</div>
                    </div>
                    <div>
                        <div class="label">Redemption Type</div>
                        <div class="value"><span class="<?php echo redemption_channel_badge($completed_redemption['channel']); ?>">In-Store / Walk-in</span></div>
                    </div>
                    <div>
                        <div class="label">Redemption Code</div>
                        <div class="value"><code class="redemption-code"><?php echo htmlspecialchars($completed_redemption['card_number'] ?? '—'); ?></code></div>
                    </div>
                    <div>
                        <div class="label">Status</div>
                        <div class="value"><span class="<?php echo redemption_status_badge($completed_redemption['status']); ?>"><?php echo htmlspecialchars($completed_redemption['status']); ?></span></div>
                    </div>
                    <div>
                        <div class="label">Date / Time</div>
                        <div class="value"><?php echo date('M d, Y h:i A', strtotime($completed_redemption['redeemed_at'])); ?></div>
                    </div>
                    <div>
                        <div class="label">Processed By</div>
                        <div class="value"><?php echo htmlspecialchars($completed_redemption['processed_by'] ?? 'Unknown Staff'); ?></div>
                    </div>
                </div>
                <p style="margin: 4px 0 0; font-size: 0.85rem; color: var(--text-muted);">
                    <i class="fas fa-lock"></i> This reward has already been consumed in-store and is recorded as <strong>USED</strong> — the code above cannot be entered at online checkout.
                </p>
            </div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-gift" style="color: #8b5cf6;"></i>
                <h3>Total Redemptions</h3>
                <p><?php echo number_format($total_redemptions); ?></p>
            </div>
            <div class="stat-card" style="border-bottom: 4px solid #f59e0b;">
                <i class="fas fa-star" style="color: #f59e0b;"></i>
                <h3>Total Points Redeemed</h3>
                <p><?php echo number_format($total_points_redeemed, 2); ?></p>
            </div>
        </div>

        <!-- Process Redemption Form -->
        <div class="content-box" id="redemption-form-box">
            <h2><i class="fas fa-hand-holding-heart" style="color: #10b981;"></i> Process In-Store Redemption</h2>
            <form method="POST" action="" id="redeemForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="redeem_nonce" value="<?php echo htmlspecialchars($_SESSION['redeem_nonce']); ?>">
                <input type="hidden" name="redeem_reward" value="1">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="customer_search">Search Customer</label>
                        <input type="text" id="customer_search" class="customer-search" placeholder="Type a name or email to search..." autocomplete="off">
                        <span id="customer_search_status" style="font-size: 0.75rem; color: var(--text-muted);"></span>
                        <label for="customer_id" style="margin-top:4px;">Select Customer</label>
                        <select name="customer_id" id="customer_id" required onchange="updateCustomerPointsDisplay()">
                            <option value="">-- Choose Customer --</option>
                            <?php foreach ($customers as $cust): ?>
                                <option value="<?php echo $cust['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($cust['name']); ?>"
                                        data-points="<?php echo (float)$cust['loyalty_points']; ?>"
                                        <?php echo ($selected_customer === (int)$cust['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cust['name']); ?> (Bal: <?php echo number_format($cust['loyalty_points'], 2); ?> pts)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="reward_code">Select Reward Item</label>
                        <select name="reward_code" id="reward_code" required onchange="updateCustomerPointsDisplay()">
                            <option value="">-- Choose Reward Item --</option>
                            <?php foreach ($reward_catalog as $code => $item): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>"
                                        data-name="<?php echo htmlspecialchars($item['name']); ?>"
                                        data-points="<?php echo (float)$item['points']; ?>"
                                        <?php echo ($selected_reward_code === $code) ? 'selected' : ''; ?>
                                        <?php echo ($item['stock'] <= 0) ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($item['name']); ?>
                                    (Cost: <?php echo number_format($item['points'], 2); ?> pts | Stock: <?php echo $item['stock']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 10px;">
                    <div id="selected-points-info" style="font-size: 0.9rem; font-weight: 600; color: var(--primary-purple);">
                        Available Customer Balance: <span id="display-points">0.00</span> points
                    </div>
                    <button type="button" id="openConfirmBtn" class="btn-primary">
                        <i class="fas fa-check-circle"></i> Confirm Redemption
                    </button>
                </div>
            </form>
        </div>

        <!-- Redemption History Table -->
        <div class="content-box">
            <h2><i class="fas fa-history" style="color: var(--primary-purple);"></i> Recent Redemption Transactions</h2>

            <?php if (empty($redemption_history)): ?>
                <p style="text-align: center; color: var(--text-muted); margin: 30px 0;">No redemption history found.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Reward</th>
                                <th>Points Used</th>
                                <th>Code</th>
                                <th>Channel</th>
                                <th>Status</th>
                                <th>Processed By</th>
                                <th>Date &amp; Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($redemption_history as $history): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($history['customer_name'] ?? 'Unknown'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($history['reward_name']); ?></td>
                                    <td><span style="color: #e11d48; font-weight: 700;">-<?php echo number_format($history['points_used'], 2); ?> pts</span></td>
                                    <td><?php echo $history['card_number'] ? '<code class="redemption-code">' . htmlspecialchars($history['card_number']) . '</code>' : '<span style="color: var(--text-muted);">—</span>'; ?></td>
                                    <td><span class="<?php echo redemption_channel_badge($history['channel']); ?>"><?php echo htmlspecialchars(str_replace('_', '-', $history['channel'])); ?></span></td>
                                    <td><span class="<?php echo redemption_status_badge($history['status']); ?>"><?php echo htmlspecialchars($history['status']); ?></span></td>
                                    <td><?php echo htmlspecialchars($history['processed_by'] ?? '— (Online, self-service)'); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($history['redeemed_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Final confirmation modal — the real gate is still 100% server-side
         (the transaction above); this is purely a UX step so staff see the
         exact numbers before anything is submitted. -->
    <div class="confirm-modal" id="confirmModal">
        <div class="confirm-modal-box">
            <h3>Confirm In-Store Redemption?</h3>
            <p class="sub">This will immediately deduct points and mark the reward as used.</p>
            <div class="cf-warning" id="cf-warning-banner">
                <i class="fas fa-triangle-exclamation" style="margin-top:1px;"></i>
                <span id="cf-warning-text">This customer does not have enough points for this reward.</span>
            </div>
            <div class="confirm-row"><span class="k">Customer</span><span class="v" id="cf-customer">—</span></div>
            <div class="confirm-row"><span class="k">Reward</span><span class="v" id="cf-reward">—</span></div>
            <div class="confirm-row"><span class="k">Points Required</span><span class="v" id="cf-required">—</span></div>
            <div class="confirm-row"><span class="k">Current Balance</span><span class="v" id="cf-current">—</span></div>
            <div class="confirm-row"><span class="k">Remaining Balance</span><span class="v" id="cf-remaining">—</span></div>
            <div class="confirm-row"><span class="k">Redemption Channel</span><span class="v">IN-STORE</span></div>
            <div class="confirm-actions">
                <button type="button" class="btn-cancel-modal" onclick="closeConfirmModal()">Cancel</button>
                <button type="button" class="btn-confirm-modal" id="finalConfirmBtn">Confirm Redemption</button>
            </div>
        </div>
    </div>

    <script>
        function toggleNotifications() {
            const dropdown = document.getElementById('notifDropdown');
            if (dropdown) {
                dropdown.classList.toggle('active');
            }
        }

        function updateCustomerPointsDisplay() {
            const select = document.getElementById('customer_id');
            const display = document.getElementById('display-points');
            if (!select || !display) return;

            const selectedOption = select.options[select.selectedIndex];
            const points = selectedOption ? selectedOption.getAttribute('data-points') : 0;
            display.textContent = points ? parseFloat(points).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00';
        }

        // --- Search Customer: server-side lookup ------------------------------
        // Queries modules/customers/search_customers_api.php (the customers
        // table directly, authoritative balance recomputed the same way as
        // everywhere else) instead of filtering the already-rendered option
        // list — scales to any customer count, not just the ones the page
        // happened to load on first render.
        const SEARCH_API_URL = "<?php echo BASE_URL; ?>/modules/customers/search_customers_api.php";
        const customerSelect = document.getElementById('customer_id');
        const searchStatus = document.getElementById('customer_search_status');
        // Snapshot of the server-rendered option list this page loaded with —
        // restored whenever the search box is cleared, so no network call is
        // needed just to get back to "showing everyone" and the page still
        // works exactly as before if JS/the API call ever fails.
        const originalCustomerOptionsHTML = customerSelect.innerHTML;

        function renderCustomerOptions(customers, query) {
            let html = '<option value="">-- Choose Customer --</option>';
            if (customers.length === 0) {
                html += '<option value="" disabled>No customers match "' + escapeHtmlAttr(query) + '"</option>';
            } else {
                for (const c of customers) {
                    const bal = parseFloat(c.loyalty_points || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    html += '<option value="' + c.id + '" data-name="' + escapeHtmlAttr(c.name) + '" data-points="' + parseFloat(c.loyalty_points || 0) + '">'
                        + escapeHtmlAttr(c.name) + ' (Bal: ' + bal + ' pts)</option>';
                }
            }
            customerSelect.innerHTML = html;
            updateCustomerPointsDisplay();
        }

        function escapeHtmlAttr(s) {
            const div = document.createElement('div');
            div.textContent = s == null ? '' : String(s);
            return div.innerHTML;
        }

        let searchDebounce = null;
        let searchRequestSeq = 0;
        document.getElementById('customer_search').addEventListener('input', function () {
            const q = this.value.trim();
            clearTimeout(searchDebounce);

            if (q === '') {
                searchStatus.textContent = '';
                customerSelect.innerHTML = originalCustomerOptionsHTML;
                updateCustomerPointsDisplay();
                return;
            }

            searchStatus.textContent = 'Searching...';
            searchDebounce = setTimeout(() => {
                const thisRequest = ++searchRequestSeq;
                fetch(SEARCH_API_URL + '?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        if (thisRequest !== searchRequestSeq) return; // a newer search already fired
                        if (!data.success) {
                            searchStatus.textContent = 'Search failed — showing the full list.';
                            customerSelect.innerHTML = originalCustomerOptionsHTML;
                            updateCustomerPointsDisplay();
                            return;
                        }
                        searchStatus.textContent = data.customers.length + ' match' + (data.customers.length === 1 ? '' : 'es');
                        renderCustomerOptions(data.customers, q);
                    })
                    .catch(() => {
                        if (thisRequest !== searchRequestSeq) return;
                        searchStatus.textContent = 'Search failed — showing the full list.';
                        customerSelect.innerHTML = originalCustomerOptionsHTML;
                        updateCustomerPointsDisplay();
                    });
            }, 300);
        });

        function fmtPts(n) {
            return parseFloat(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' points';
        }

        const confirmModal = document.getElementById('confirmModal');
        function closeConfirmModal() { confirmModal.classList.remove('active'); }

        const warningBanner = document.getElementById('cf-warning-banner');
        const warningText = document.getElementById('cf-warning-text');
        const finalConfirmBtn = document.getElementById('finalConfirmBtn');

        document.getElementById('openConfirmBtn').addEventListener('click', function () {
            const custSelect = document.getElementById('customer_id');
            const rewardSelect = document.getElementById('reward_code');
            const custOpt = custSelect.options[custSelect.selectedIndex];
            const rewardOpt = rewardSelect.options[rewardSelect.selectedIndex];

            if (!custSelect.value) { alert('Please select a customer first.'); return; }
            if (!rewardSelect.value) { alert('Please choose a reward item.'); return; }

            const current = parseFloat(custOpt.getAttribute('data-points') || 0);
            const required = parseFloat(rewardOpt.getAttribute('data-points') || 0);
            const remaining = current - required;
            const insufficient = remaining < 0;

            document.getElementById('cf-customer').textContent = custOpt.getAttribute('data-name');
            document.getElementById('cf-reward').textContent = rewardOpt.getAttribute('data-name');
            document.getElementById('cf-required').textContent = fmtPts(required);
            document.getElementById('cf-current').textContent = fmtPts(current);
            document.getElementById('cf-remaining').textContent = fmtPts(remaining);

            // This preview mirrors the real check but is NOT the actual
            // security gate — the server independently re-checks the
            // authoritative balance (from a row-locked query, not this
            // dropdown data) before anything is deducted. This just warns
            // staff up front instead of letting them submit and only find
            // out from an error page.
            const remainingEl = document.getElementById('cf-remaining');
            remainingEl.style.color = insufficient ? '#dc2626' : '';

            if (insufficient) {
                warningText.textContent = custOpt.getAttribute('data-name') + ' only has ' + fmtPts(current)
                    + ', but ' + rewardOpt.getAttribute('data-name') + ' needs ' + fmtPts(required)
                    + ' — short by ' + fmtPts(Math.abs(remaining)) + '.';
                warningBanner.classList.add('active');
                finalConfirmBtn.disabled = true;
                finalConfirmBtn.textContent = 'Insufficient Points';
            } else {
                warningBanner.classList.remove('active');
                finalConfirmBtn.disabled = false;
                finalConfirmBtn.textContent = 'Confirm Redemption';
            }

            confirmModal.classList.add('active');
        });

        finalConfirmBtn.addEventListener('click', function () {
            const btn = this;
            const openBtn = document.getElementById('openConfirmBtn');
            // Last-line client-side guard — should already be disabled by
            // the check above, but never submit an insufficient-balance
            // redemption from here regardless. The server's own check is
            // still what actually protects the data either way.
            if (btn.disabled) return;
            // Disabling the buttons is a UX nicety only — the real,
            // authoritative protection against a double submit is the
            // server-side one-time nonce + the row-locked DB transaction.
            btn.disabled = true;
            openBtn.disabled = true;
            btn.textContent = 'Processing...';
            document.getElementById('redeemForm').submit();
        });

        // Run on page load if a customer was pre-selected
        document.addEventListener('DOMContentLoaded', () => {
            updateCustomerPointsDisplay();

            // Close notification dropdown when clicking outside
            document.addEventListener('click', (e) => {
                const wrapper = document.querySelector('.notification-wrapper');
                const dropdown = document.getElementById('notifDropdown');
                if (wrapper && !wrapper.contains(e.target) && dropdown) {
                    dropdown.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>
