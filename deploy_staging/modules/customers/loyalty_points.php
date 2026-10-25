<?php
session_start();
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';

$token = getJWTFromCookie();
if (!$token) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$payload = verifyJWT($token);
if (!$payload) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$user_id = (int) ($payload['user_id'] ?? 0);
$role = strtolower(trim($payload['role'] ?? 'staff'));

if (isset($_POST['mark_notifications_read'])) {
    notifications_mark_all_read($conn, $user_id);
    header("Location: " . BASE_URL . "/modules/customers/loyalty_points.php");
    exit();
}
$notification_count = notifications_get_unread_count($conn, $user_id);
$recent_notifications = notifications_get_recent($conn, $user_id, 6);

$loyaltyColumn = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'loyalty_points'");
if ($loyaltyColumn && mysqli_num_rows($loyaltyColumn) === 0) {
    mysqli_query($conn, "ALTER TABLE customers ADD COLUMN loyalty_points DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER address");
} elseif ($loyaltyColumn) {
    $loyaltyInfo = mysqli_fetch_assoc($loyaltyColumn);
    if (isset($loyaltyInfo['Type']) && stripos((string) $loyaltyInfo['Type'], 'decimal') === false) {
        mysqli_query($conn, "ALTER TABLE customers MODIFY COLUMN loyalty_points DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    }
}

mysqli_query(
    $conn,
    "CREATE TABLE IF NOT EXISTS loyalty_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        user_id INT NULL,
        product_name VARCHAR(255) NOT NULL,
        quantity_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        points_earned DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

$check_null = mysqli_query($conn, "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'loyalty_transactions' AND COLUMN_NAME = 'user_id'");
if ($check_null && mysqli_fetch_assoc($check_null)['IS_NULLABLE'] === 'NO') {
    mysqli_query($conn, "ALTER TABLE loyalty_transactions MODIFY COLUMN user_id INT NULL");
}

$check_order_id_col = mysqli_query($conn, "SHOW COLUMNS FROM loyalty_transactions LIKE 'order_id'");
if (mysqli_num_rows($check_order_id_col) == 0) {
    mysqli_query($conn, "ALTER TABLE loyalty_transactions ADD COLUMN order_id INT DEFAULT NULL AFTER points_earned");
}

$success_message = '';
$error_message = '';
$selected_customer = (int) ($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);

// --- START POINT ADJUSTMENT LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_points'])) {
    $adj_customer_id = (int)$_POST['customer_id'];
    $points_value = (float)($_POST['points_value'] ?? 0);
    $manual_reason = trim($_POST['manual_reason'] ?? 'Manual Adjustment');
    $transaction_source_value = 0.00; // No kg/amount for direct point adjustment

    if ($adj_customer_id <= 0) {
        $error_message = 'Please select a customer.';
    } elseif ($points_value == 0) {
        $error_message = 'Please enter a non-zero points value.';
    } else {
        // Use the signed value directly: positive = add, negative = deduct
        $points_to_process = $points_value;

        if ($points_value > 0) {
            $transaction_description = "Addition: " . $manual_reason;
        } else {
            $transaction_description = "Deduction: " . $manual_reason;
        }

        $trans_stmt = $conn->prepare("INSERT INTO loyalty_transactions (customer_id, user_id, product_name, quantity_kg, points_earned) VALUES (?, ?, ?, ?, ?)");
        $trans_stmt->bind_param("iisdd", $adj_customer_id, $user_id, $transaction_description, $transaction_source_value, $points_to_process);

        if ($trans_stmt->execute()) {
            // --- BUG FIX: The previous logic was overwriting points. ---
            // This new logic correctly adds or subtracts from the current balance.
            $conn->query("UPDATE customers SET loyalty_points = loyalty_points + $points_to_process WHERE id = $adj_customer_id");

            $new_total = notifications_sync_customer_loyalty_points($conn, $adj_customer_id);

            $name_res = mysqli_query($conn, "SELECT name, email FROM customers WHERE id = $adj_customer_id");
            $client_row = mysqli_fetch_assoc($name_res);
            $c_name = $client_row['name'] ?? 'Unknown';
            $c_email = $client_row['email'] ?? '';

            // Determine type label for logging
            $adjustment_type = ($points_value > 0) ? 'Addition' : 'Deduction';

            // Notify the customer
            notifications_create($conn, [
                'user_id' => $user_id,
                'customer_id' => $adj_customer_id,
                'type' => 'point_adjustment',
                'channel' => 'both',
                'title' => 'Points Balance Updated',
                'message' => "Hello $c_name, your loyalty points have been updated by " . number_format($points_to_process, 2) . " points. Reason: $transaction_description. Your new balance is " . number_format($new_total, 2) . " points.",
                'email_to' => $c_email,
                'points_value' => $points_to_process
            ]);

            $log_details = "Processed points for $c_name (Type: $adjustment_type, Value: $points_to_process). Reason: $transaction_description";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'POINT_ADJUSTMENT', ?)");
            $log_stmt->bind_param("is", $user_id, $log_details);
            $log_stmt->execute();

            $_SESSION['success_message'] = "Successfully processed " . number_format($points_to_process, 2) . " points for " . htmlspecialchars($c_name) . ". New balance: " . number_format($new_total, 2) . " pts.";
            header("Location: loyalty_points.php");
            exit();
        } else {
            $error_message = "Failed to create transaction record: " . $conn->error;
        }
    }
}

// --- END NG ADJUSTMENT LOGIC ---

// Check if there is a success message from a previous redirect
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Fetch summary stats
$total_customers = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM customers"))['total'] ?? 0);
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;

$total_loyalty_points_query = mysqli_query($conn, "SELECT SUM(loyalty_points) AS total FROM customers");
$total_loyalty_points = (float) (mysqli_fetch_assoc($total_loyalty_points_query)['total'] ?? 0);

$total_gross_points_query = mysqli_query($conn, "SELECT SUM(points_earned) AS total_gross FROM loyalty_transactions");
$total_gross_points = (float) (mysqli_fetch_assoc($total_gross_points_query)['total_gross'] ?? 0);
$unread_count = get_unread_count_staff($user_id);

// If a customer is selected, ensure their loyalty_points are synced on page load
if ($selected_customer > 0) {
    notifications_sync_customer_loyalty_points($conn, $selected_customer);
}

// Fetch customers for dropdown and summary table
$customers = [];
$customers_result = mysqli_query($conn, "
    SELECT 
        c.id, 
        c.name, 
        c.email, 
        c.loyalty_points,
        (SELECT COALESCE(SUM(points_earned), 0) FROM loyalty_transactions WHERE customer_id = c.id) as total_earned_points,
        (SELECT COALESCE(SUM(points_used), 0) FROM reward_redemptions WHERE customer_id = c.id) as total_redeemed_points
    FROM customers c 
    ORDER BY id ASC");

if ($customers_result) {
    while ($row = mysqli_fetch_assoc($customers_result)) {
        $customers[] = $row;
    }
}

/* --------------------------------------------------------------------
   Customer Loyalty Summary — server-side pagination (+ optional search).
   This ONLY controls how many rows the summary table renders at once.
   The $customers list above (the "Select Customer" dropdown and the
   printable report) is deliberately left whole — every customer stays
   selectable and the report still covers everyone. The loyalty
   calculations (gross earned / redeemed / usable balance) are the exact
   same sub-selects, just fetched a page at a time via LIMIT/OFFSET.
   -------------------------------------------------------------------- */
$lp_search = trim($_GET['search'] ?? '');

$lp_where = '';
$lp_params = [];
$lp_types = '';
if ($lp_search !== '') {
    $lp_where = " WHERE (c.name LIKE ? OR c.email LIKE ?)";
    $lp_like = "%$lp_search%";
    array_push($lp_params, $lp_like, $lp_like);
    $lp_types = 'ss';
}

$lp_per_page = 15;

// 1) COUNT of matching customers (real number straight from the table).
$lp_count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM customers c" . $lp_where);
if ($lp_params) {
    $lp_count_stmt->bind_param($lp_types, ...$lp_params);
}
$lp_count_stmt->execute();
$lp_filtered_count = (int) ($lp_count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$lp_count_stmt->close();

// 2) Clamp the requested page into the valid 1..total_pages range, so a
//    hand-typed ?page=999999 safely lands on the last real page.
$lp_total_pages = max(1, (int) ceil($lp_filtered_count / $lp_per_page));
$lp_page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($lp_page < 1) {
    $lp_page = 1;
}
if ($lp_page > $lp_total_pages) {
    $lp_page = $lp_total_pages;
}
$lp_offset = ($lp_page - 1) * $lp_per_page;

/**
 * Build a loyalty_points.php URL for a page number, preserving the current
 * query string (search, and any other param) so paging never drops the
 * active search or filters.
 */
function loyalty_points_page_url(int $target_page): string
{
    $params = $_GET;
    $params['page'] = $target_page;
    $qs = http_build_query($params);
    return 'loyalty_points.php' . ($qs !== '' ? '?' . $qs : '') . '#customer-summary-section';
}

// Compact windowed page list with ellipsis — same shape the Customer
// Management and Orders pagination already use.
$lp_page_window = 2;
$lp_page_numbers = [];
for ($i = 1; $i <= $lp_total_pages; $i++) {
    if ($i === 1 || $i === $lp_total_pages || ($i >= $lp_page - $lp_page_window && $i <= $lp_page + $lp_page_window)) {
        $lp_page_numbers[] = $i;
    } elseif (end($lp_page_numbers) !== '...') {
        $lp_page_numbers[] = '...';
    }
}

// 3) Paginated SELECT — only the rows for the current page. Deterministic
//    ORDER BY c.id ASC (id is the unique PK) keeps ordering stable between
//    page loads.
$summary_customers = [];
$lp_sql = "
    SELECT
        c.id,
        c.name,
        c.email,
        c.loyalty_points,
        (SELECT COALESCE(SUM(points_earned), 0) FROM loyalty_transactions WHERE customer_id = c.id) AS total_earned_points,
        (SELECT COALESCE(SUM(points_used), 0) FROM reward_redemptions WHERE customer_id = c.id) AS total_redeemed_points
    FROM customers c" . $lp_where . "
    ORDER BY c.id ASC
    LIMIT ? OFFSET ?";
$lp_stmt = $conn->prepare($lp_sql);
$lp_stmt->bind_param($lp_types . 'ii', ...array_merge($lp_params, [$lp_per_page, $lp_offset]));
$lp_stmt->execute();
$lp_result = $lp_stmt->get_result();
while ($row = mysqli_fetch_assoc($lp_result)) {
    $summary_customers[] = $row;
}
$lp_stmt->close();

$lp_showing_from = $lp_filtered_count > 0 ? $lp_offset + 1 : 0;
$lp_showing_to = min($lp_offset + $lp_per_page, $lp_filtered_count);

// Fetch recent transactions
$transactions = [];
$transactions_result = mysqli_query(
    $conn,
    "SELECT lt.customer_id, lt.product_name, lt.quantity_kg, lt.points_earned, lt.created_at, c.name AS customer_name
     FROM loyalty_transactions lt
     LEFT JOIN customers c ON c.id = lt.customer_id
     ORDER BY lt.created_at DESC
     LIMIT 10"
);
if ($transactions_result) {
    while ($row = mysqli_fetch_assoc($transactions_result)) {
        $transactions[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loyalty Points - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        /* Simple, print-only business report. Everything else on the page
           (sidebar, forms, buttons, notification panel, the fancy stat
           cards) is hidden via `body * { visibility: hidden }` and only
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

        /* Customer Loyalty Summary — search + pagination. Reuses the same
           --dm-* theme variables admin_style.css already defines on
           :root / body.dark-mode, so it follows Light/Dark Mode with no
           second dark-mode system (identical approach to the Customer
           Management list pagination). */
        .lp-summary-toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .lp-summary-toolbar input[type="text"] {
            padding: 9px 12px; border-radius: 6px; border: 1px solid var(--dm-border, #ddd);
            background: var(--dm-surface, #fff); color: var(--dm-text, #1e293b);
            font-size: 0.9rem; min-width: 200px; max-width: 100%; box-sizing: border-box;
        }
        .lp-summary-toolbar .lp-btn-search {
            padding: 9px 16px; border-radius: 6px; border: 1px solid #4a3e94;
            background: #4a3e94; color: #fff; font-weight: 600; cursor: pointer; font-size: 0.9rem; white-space: nowrap;
        }
        .lp-summary-toolbar .lp-btn-reset {
            padding: 9px 14px; border-radius: 6px; border: 1px solid var(--dm-border, #ddd);
            background: var(--dm-surface-2, #f8fafc); color: var(--dm-text-muted, #475569);
            text-decoration: none; font-weight: 600; font-size: 0.85rem; white-space: nowrap;
        }
        .lp-result-count { color: var(--dm-text-muted, #64748b); font-size: 0.85rem; margin: 14px 0 4px; }
        .lp-pagination {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: center;
            gap: 12px; margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--dm-border, #eee);
        }
        .lp-pagination-nav { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; max-width: 100%; }
        .lp-page-numbers {
            display: flex; align-items: center; gap: 4px; flex-wrap: nowrap;
            overflow-x: auto; max-width: 100%; padding-bottom: 2px;
        }
        .lp-page-btn, .lp-page-num {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            min-width: 34px; height: 34px; padding: 0 10px; border-radius: 8px;
            border: 1px solid var(--dm-border, #e2e8f0); background: var(--dm-surface, #fff);
            color: var(--dm-text, #1e293b); text-decoration: none; font-size: 0.82rem;
            font-weight: 600; flex-shrink: 0; transition: background .15s, color .15s, border-color .15s;
        }
        a.lp-page-btn:hover, a.lp-page-num:hover { background: var(--dm-surface-2, #f1f5f9); border-color: #4a3e94; }
        .lp-page-num.active { background: #4a3e94; border-color: #4a3e94; color: #fff; }
        .lp-page-btn.disabled { opacity: 0.4; cursor: not-allowed; }
        .lp-page-ellipsis {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 20px; color: var(--dm-text-muted, #94a3b8); font-size: 0.82rem; flex-shrink: 0;
        }
        @media (max-width: 560px) {
            .lp-pagination-nav { width: 100%; justify-content: center; }
            .lp-page-btn-label { display: none; }
        }
        .page-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid var(--border); overflow-x: auto; }
        .page-tab {
            background: none; border: none; border-bottom: 3px solid transparent; margin-bottom: -2px;
            padding: 12px 20px; font: inherit; font-size: 0.95rem; font-weight: 600; color: var(--muted);
            cursor: pointer; white-space: nowrap;
        }
        .page-tab:hover { color: var(--link); }
        .page-tab.active { color: var(--link); border-bottom-color: #4a3e94; }
        .tab-panel[hidden] { display: none; }
        body.dark-mode .page-tabs { border-bottom-color: #334155; }
        body.dark-mode .page-tab { color: #94a3b8; }
        body.dark-mode .page-tab:hover, body.dark-mode .page-tab.active { color: #a5b4fc; }
        body.dark-mode .page-tab.active { border-bottom-color: #a5b4fc; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="print-report-only">
            <h1><?php echo htmlspecialchars(SYSTEM_NAME); ?></h1>
            <h2>LOYALTY POINTS REPORT</h2>
            <p class="print-meta">Generated: <?php echo date('F j, Y g:i A'); ?> by <?php echo htmlspecialchars($username); ?></p>
            <hr>
            <div class="print-section-title">SUMMARY</div>
            <table class="print-summary-table">
                <tr><td>Total Customers</td><td><?php echo (int) $total_customers; ?></td></tr>
                <tr><td>Total Usable Balance</td><td><?php echo number_format($total_loyalty_points, 2); ?> pts</td></tr>
                <tr><td>Total Points Issued (Gross)</td><td><?php echo number_format($total_gross_points, 2); ?> pts</td></tr>
            </table>
            <hr>
            <div class="print-section-title">DETAILS &mdash; Customer Loyalty Summary</div>
            <table class="print-details-table">
                <thead>
                    <tr><th>#</th><th>Customer</th><th>Email</th><th>Gross Earned</th><th>Redeemed</th><th>Usable Balance</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($customers)): ?>
                        <?php $print_no = 1; ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><?php echo $print_no++; ?></td>
                                <td><?php echo htmlspecialchars($customer['name']); ?></td>
                                <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                <td><?php echo number_format((float) $customer['total_earned_points'], 2); ?></td>
                                <td><?php echo number_format((float) $customer['total_redeemed_points'], 2); ?></td>
                                <td><?php echo number_format((float) $customer['loyalty_points'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6">No customers found yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <hr>
            <div class="print-section-title">DETAILS &mdash; Recent Loyalty Transactions</div>
            <table class="print-details-table">
                <thead>
                    <tr><th>Customer</th><th>Product / Reason</th><th>Weight (kg)</th><th>Points Earned</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($transactions)): ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($transaction['customer_name'] ?? 'Unknown Customer'); ?></td>
                                <td><?php echo htmlspecialchars($transaction['product_name']); ?></td>
                                <td><?php echo number_format((float) $transaction['quantity_kg'], 2); ?></td>
                                <td><?php echo number_format((float) $transaction['points_earned'], 2); ?></td>
                                <td><?php echo htmlspecialchars($transaction['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5">No loyalty transactions recorded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p class="print-signature">Prepared by: ____________________________</p>
        </div>

        <div class="welcome-header" style="margin-bottom: 30px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h1>Loyalty Points ⭐</h1>
                    <p>Record customer purchases and automatically convert kilograms into loyalty points.</p>
                </div>
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; position: relative;">
                    <button type="button" onclick="window.print()" style="background: #2c3e50; color: white; border: none; border-radius: 10px; padding: 10px 14px; cursor: pointer; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <div style="position: relative;">
                        <button type="button" onclick="toggleNotifications()" style="background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; cursor: pointer; font-weight: 700; color: #4a3e94;">
                            <i class="fas fa-bell"></i> Notifications
                            <?php if ($notification_count > 0): ?>
                                <span style="display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; margin-left: 8px; background: #e74c3c; color: #fff; border-radius: 999px; font-size: 0.75rem; padding: 0 6px;"><?php echo $notification_count; ?></span>
                            <?php endif; ?>
                        </button>
                        <div id="notification-panel" style="display: none; position: absolute; right: 0; top: calc(100% + 10px); width: 360px; max-width: 90vw; background: #fff; border-radius: 12px; box-shadow: 0 12px 30px rgba(0,0,0,0.12); border: 1px solid #eee; z-index: 1000;">
                            <div style="padding: 15px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                                <strong style="color: #4a3e94;">Recent Notifications</strong>
                                <form method="POST" style="margin: 0;">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" name="mark_notifications_read" style="background: none; border: none; color: #4a3e94; font-weight: 600; cursor: pointer;">Mark all as read</button>
                                </form>
                            </div>
                            <div style="max-height: 320px; overflow-y: auto;">
                                <?php if (!empty($recent_notifications)): ?>
                                    <?php foreach ($recent_notifications as $notification): ?>
                                        <div style="padding: 14px 15px; border-bottom: 1px solid #f3f3f3; background: <?php echo (int) $notification['is_read'] === 1 ? '#fff' : '#f7f4ff'; ?>;">
                                            <div style="display: flex; justify-content: space-between; align-items: start; gap: 10px;">
                                                <strong style="color: #2c3e50;"><?php echo htmlspecialchars($notification['title']); ?></strong>
                                                <?php if ((int) $notification['is_read'] === 0): ?>
                                                    <span style="width: 10px; height: 10px; background: #4a3e94; border-radius: 999px; margin-top: 5px;"></span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="margin-top: 6px; color: #555; line-height: 1.4; font-size: 0.92rem;"><?php echo htmlspecialchars($notification['message']); ?></div>
                                            <div style="margin-top: 8px; color: #888; font-size: 0.8rem;"><?php echo htmlspecialchars($notification['created_at']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="padding: 18px 15px; color: #777;">No notifications yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php if (isset($role) && $role === 'admin'): ?>
                    <a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php#charts-section" style="background: #4a3e94; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600;"><i class="fas fa-chart-line"></i> Points Analytics</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="stats-container" style="display: flex; gap: 20px; margin-bottom: 30px;">
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-user-friends" style="font-size: 2rem; color: #4a3e94;"></i>
                <h3>Total Customers</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo $total_customers; ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-bottom: 4px solid #f1c40f;">
                <i class="fas fa-star" style="font-size: 2rem; color: #f1c40f;"></i>
                <h3>Total Usable Balance</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_loyalty_points, 2); ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-bottom: 4px solid #2ecc71;">
                <i class="fas fa-coins" style="font-size: 2rem; color: #2ecc71;"></i>
                <h3>Total Points Issued</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_gross_points, 2); ?></p>
            </div>
        </div>

        <?php if ($success_message !== ''): ?>
            <div style="margin-bottom: 20px; padding: 12px 15px; background: #eafaf1; color: #27ae60; border-left: 4px solid #27ae60; border-radius: 8px; font-weight: 600;">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message !== ''): ?>
            <div style="margin-bottom: 20px; padding: 12px 15px; background: #fff5f5; color: #e74c3c; border-left: 4px solid #e74c3c; border-radius: 8px; font-weight: 600;">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div id="adjust-points-box" class="table-box" style="margin-bottom: 25px; border-left: 5px solid #4a3e94; background: #fffdfd;">
            <h2 style="margin-bottom: 15px;"><i class="fas fa-tools"></i> Manage Customer Points</h2>
            
            <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="process_points" value="1">
                <select name="customer_id" id="adj_customer_id" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <option value="">Select Customer (Current Points)</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" <?php echo $selected_customer == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?> (Balance: <?php echo number_format($customer['loyalty_points'], 2); ?> pts)
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Manual Adjustment Fields -->
                <input type="number" step="0.01" name="points_value" id="points_value" placeholder="Points Value (e.g., 10 to add, -5 to deduct)" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;" required>

                <select name="manual_reason" id="manual_reason" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <!-- Walk-in & In-Store Specific Reasons -->
                    <option value="Walk-in Purchase Points">Walk-in Purchase Points</option>
                    <option value="In-Store Redemption">In-Store Redemption (Deduct)</option>
                    <option value="On-Site Promo Bonus">On-Site Promo Bonus</option>
                    
                    <!-- Standard System Reasons -->
                    <option value="Special Bonus">Special Bonus</option>
                    <option value="Refund">Refund</option>
                    <option value="System Correction">System Correction</option>
                    <option value="Error Correction">Error Correction</option>
                    <option value="Other">Other</option>
                </select>

                <div style="grid-column: 1 / -1;">
                    <button type="submit" name="process_points" style="background: #4a3e94; color: white; border: none; padding: 12px 25px; border-radius: 5px; cursor: pointer; font-weight: 700;">
                        Process Points
                    </button>
                </div>
            </form>            <p style="font-size: 0.85rem; color: #777; margin-top: 10px;">*Note: Enter a positive number (e.g., 10) to <strong>add</strong> points. Enter a negative number (e.g., -5) to <strong>deduct</strong> points. The customer's total balance will be recalculated.</p>
        </div>

        <div class="page-tabs" id="page-tabs" role="tablist">
            <button type="button" class="page-tab active" role="tab" id="tab-transactions" data-tab="transactions" aria-selected="true" aria-controls="panel-transactions">Recent Loyalty Transactions</button>
            <button type="button" class="page-tab" role="tab" id="tab-summary" data-tab="summary" aria-selected="false" aria-controls="panel-summary">Customer Loyalty Summary</button>
        </div>

        <div class="tab-panel" id="panel-transactions" role="tabpanel" aria-labelledby="tab-transactions">
        <div class="table-box">
            <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">Recent Loyalty Transactions</h2>
            </div>

            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 2px solid #eee;">
                        <th style="padding: 15px; text-align: left;">Customer</th>
                        <th style="padding: 15px; text-align: left;">Product</th>
                        <th style="padding: 15px; text-align: left;">Weight (kg)</th>
                        <th style="padding: 15px; text-align: left;">Points Earned</th>
                        <th style="padding: 15px; text-align: left;">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($transactions)): ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 15px;"><?php echo htmlspecialchars($transaction['customer_name'] ?? 'Unknown Customer'); ?></td>
                                <td style="padding: 15px;"><?php echo htmlspecialchars($transaction['product_name']); ?></td>
                                <td style="padding: 15px;"><?php echo htmlspecialchars(number_format((float) $transaction['quantity_kg'], 2)); ?></td>
                                <td style="padding: 15px;"><?php echo htmlspecialchars(number_format((float) $transaction['points_earned'], 2)); ?></td>
                                <td style="padding: 15px;"><?php echo htmlspecialchars($transaction['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 20px;">No loyalty transactions recorded yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div>

        <div class="tab-panel" id="panel-summary" role="tabpanel" aria-labelledby="tab-summary" hidden>
        <div id="customer-summary-section" class="table-box" style="margin-bottom: 25px;">
            <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
                <h2 style="margin: 0;">Customer Loyalty Summary</h2>
                <form method="GET" action="loyalty_points.php#customer-summary-section" class="lp-summary-toolbar">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($lp_search); ?>" placeholder="Search name or email...">
                    <button type="submit" class="lp-btn-search"><i class="fas fa-search"></i> Search</button>
                    <?php if ($lp_search !== ''): ?>
                        <a href="loyalty_points.php#customer-summary-section" class="lp-btn-reset">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <p class="lp-result-count">
                Showing <?php echo number_format($lp_showing_from); ?>&ndash;<?php echo number_format($lp_showing_to); ?>
                of <?php echo number_format($lp_filtered_count); ?> customer<?php echo $lp_filtered_count === 1 ? '' : 's'; ?>
            </p>

            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 2px solid #eee;">
                        <th style="padding: 15px; text-align: left;">No.</th>
                        <th style="padding: 15px; text-align: left;">Customer Name</th>
                        <th style="padding: 15px; text-align: left;">Email</th>
                        <th style="padding: 15px; text-align: left;">Gross Earned</th>
                        <th style="padding: 15px; text-align: left;">Redeemed</th>
                        <th style="padding: 15px; text-align: left;">Usable Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($summary_customers)): ?>
                        <?php $display_id = $lp_offset + 1; ?>
                        <?php foreach ($summary_customers as $customer): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 15px;"><?php echo htmlspecialchars($display_id++); ?></td>
                                <td style="padding: 15px;"><a href="customer_details.php?id=<?php echo $customer['id']; ?>" style="text-decoration:none; color:#4a3e94; font-weight:bold;"><?php echo htmlspecialchars($customer['name']); ?></a></td>
                                <td style="padding: 15px;"><?php echo htmlspecialchars($customer['email']); ?></td>
                                <td style="padding: 15px; color: #27ae60;"><?php echo number_format((float) $customer['total_earned_points'], 2); ?></td>
                                <td style="padding: 15px; color: #e74c3c;"><?php echo number_format((float) $customer['total_redeemed_points'], 2); ?></td>
                                <td style="padding: 15px;"><strong><?php echo number_format((float) $customer['loyalty_points'], 2); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 20px;">
                                <?php echo $lp_search !== '' ? 'No customers match your search.' : 'No customers found yet.'; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($lp_total_pages > 1): ?>
            <div class="lp-pagination">
                <nav class="lp-pagination-nav" aria-label="Customer loyalty summary pagination">
                    <?php if ($lp_page > 1): ?>
                        <a class="lp-page-btn" href="<?php echo htmlspecialchars(loyalty_points_page_url($lp_page - 1)); ?>">
                            <i class="fas fa-chevron-left"></i> <span class="lp-page-btn-label">Previous</span>
                        </a>
                    <?php else: ?>
                        <span class="lp-page-btn disabled" aria-disabled="true">
                            <i class="fas fa-chevron-left"></i> <span class="lp-page-btn-label">Previous</span>
                        </span>
                    <?php endif; ?>

                    <div class="lp-page-numbers">
                        <?php foreach ($lp_page_numbers as $pn): ?>
                            <?php if ($pn === '...'): ?>
                                <span class="lp-page-ellipsis">&hellip;</span>
                            <?php elseif ((int) $pn === $lp_page): ?>
                                <span class="lp-page-num active" aria-current="page"><?php echo (int) $pn; ?></span>
                            <?php else: ?>
                                <a class="lp-page-num" href="<?php echo htmlspecialchars(loyalty_points_page_url((int) $pn)); ?>"><?php echo (int) $pn; ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($lp_page < $lp_total_pages): ?>
                        <a class="lp-page-btn" href="<?php echo htmlspecialchars(loyalty_points_page_url($lp_page + 1)); ?>">
                            <span class="lp-page-btn-label">Next</span> <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="lp-page-btn disabled" aria-disabled="true">
                            <span class="lp-page-btn-label">Next</span> <i class="fas fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </nav>
            </div>
            <?php endif; ?>
        </div>
        </div>
    </div>

    <script>
        function toggleNotifications() {
            const panel = document.getElementById('notification-panel');
            panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
        }
        document.addEventListener('click', function (event) {
            const panel = document.getElementById('notification-panel');
            if (!panel) {
                return;
            }

            if (!panel.contains(event.target) && !event.target.closest('button[onclick="toggleNotifications()"]')) {
                panel.style.display = 'none';
            }
        });
    </script>
    <script>
        // Tabs: Recent Loyalty Transactions (default) | Customer Loyalty Summary.
        // Search / paging / the #customer-summary-section link reload the page, so they reopen the summary tab.
        (function () {
            var tabs = document.querySelectorAll('.page-tab');
            function show(name) {
                tabs.forEach(function (t) {
                    var on = t.dataset.tab === name;
                    t.classList.toggle('active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                    document.getElementById('panel-' + t.dataset.tab).hidden = !on;
                });
            }
            tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.tab); }); });
            var q = new URLSearchParams(location.search);
            var summary = location.hash === '#customer-summary-section' || q.has('search') || q.has('page');
            show(summary ? 'summary' : 'transactions');
            if (summary) { var s = document.getElementById('page-tabs'); if (s) s.scrollIntoView(); }
        })();
    </script>
</body>
</html>
