<?php
session_start();
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

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

// CONSIDER: notifications_ensure_schema($conn); should be run once during setup/migration, not on every page load.

if (isset($_POST['mark_notifications_read'])) {
    notifications_mark_all_read($conn, $user_id);
    header("Location: " . BASE_URL . "/modules/customers/loyalty_points.php");
    exit();
}
// CONSIDER: notifications_seed_expiring_points($conn); should be run by a cron job, not on every page load.
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
        user_id INT NULL, -- Changed to NULL to allow customer-initiated transactions
        product_name VARCHAR(255) NOT NULL,
        quantity_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        points_earned DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

// Ensure user_id is nullable if the table already exists
$check_null = mysqli_query($conn, "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'loyalty_transactions' AND COLUMN_NAME = 'user_id'");
if ($check_null && mysqli_fetch_assoc($check_null)['IS_NULLABLE'] === 'NO') {
    mysqli_query($conn, "ALTER TABLE loyalty_transactions MODIFY COLUMN user_id INT NULL");
}

// Add order_id column to loyalty_transactions if it doesn't exist
$check_order_id_col = mysqli_query($conn, "SHOW COLUMNS FROM loyalty_transactions LIKE 'order_id'");
if (mysqli_num_rows($check_order_id_col) == 0) {
    mysqli_query($conn, "ALTER TABLE loyalty_transactions ADD COLUMN order_id INT DEFAULT NULL AFTER points_earned");
}

$success_message = '';
$error_message = '';
// Fix: Check both POST and GET to ensure selection isn't lost on error or initial load from customer list
$selected_customer = (int) ($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0); // Keep selected customer on error

// --- START NG ADJUSTMENT LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_points'])) {
    $adj_customer_id = (int)$_POST['customer_id'];

    // Always treat admin point updates as MANUAL point adjustments.
    // This page is used to add/deduct points directly, not via money->points conversion.
    $adjustment_type = 'manual';

    $points_to_process = 0.00;
    $transaction_description = '';
    $transaction_source_value = 0.00; // No kg/amount for direct point adjustment


    if ($adj_customer_id <= 0) {
        $error_message = 'Please select a customer.';
    } else {
        if ($adjustment_type === 'purchase') {
            $amount_spent = (float)($_POST['amount_spent'] ?? 0);
            $purchase_description = trim($_POST['purchase_description'] ?? 'Walk-in Purchase');

            if ($amount_spent <= 0) {
                $error_message = 'Please enter a valid purchase amount for points.';
            } else {
$points_to_process = round($amount_spent / 100, 2); // ₱100 = 1 point
                $transaction_description = "Purchase: " . $purchase_description;
                $transaction_source_value = $amount_spent; // Store amount spent in quantity_kg for audit
            }
        } elseif ($adjustment_type === 'manual') {
            $points_value = (float)($_POST['points_value'] ?? 0);
            $manual_reason = trim($_POST['manual_reason'] ?? 'Manual Adjustment');

            if ($points_value == 0.00) {
                $error_message = 'Please enter a non-zero points value for manual adjustment.';
            } else {
                // Allow adding or deducting points. Stored as an earned transaction.
                // Balance is synced as: SUM(points_earned) - SUM(points_used)
                $points_to_process = $points_value;
                $transaction_description = "Manual: " . $manual_reason;
                $transaction_source_value = 0.00; // No kg/amount for direct point adjustment
            }
        } else {
            $error_message = 'Invalid adjustment type selected.';
        }

        if (empty($error_message)) {
            $trans_stmt = $conn->prepare("INSERT INTO loyalty_transactions (customer_id, user_id, product_name, quantity_kg, points_earned) VALUES (?, ?, ?, ?, ?)");
            $trans_stmt->bind_param("iisdd", $adj_customer_id, $user_id, $transaction_description, $transaction_source_value, $points_to_process);

            if ($trans_stmt->execute()) {
                $new_total = notifications_sync_customer_loyalty_points($conn, $adj_customer_id);

                $name_res = mysqli_query($conn, "SELECT name, email FROM customers WHERE id = $adj_customer_id");
                $client_row = mysqli_fetch_assoc($name_res);
                $c_name = $client_row['name'] ?? 'Unknown';
                $c_email = $client_row['email'] ?? '';

                // Notify the customer
                notifications_create($conn, [
                    'user_id' => $user_id,
                    'customer_id' => $adj_customer_id,
                    'type' => 'point_adjustment',
                    'channel' => 'both', // Sends both email and in-app
                    'title' => 'Points Balance Updated',
                    'message' => "Hello $c_name, your loyalty points have been updated by " . number_format($points_to_process, 2) . " points. Reason: $transaction_description. Your new balance is " . number_format($new_total, 2) . " points.",
                    'email_to' => $c_email,
                    'points_value' => $points_to_process
                ]);

                $log_details = "Processed points for $c_name (Type: $adjustment_type, Value: $points_to_process). Reason: $transaction_description";
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'POINT_ADJUSTMENT', ?)");
                $log_stmt->bind_param("is", $user_id, $log_details);
                $log_stmt->execute();

                // Store message in session and redirect to prevent double-posting on refresh
                $_SESSION['success_message'] = "Successfully processed points for " . htmlspecialchars($c_name) . ".";
                header("Location: loyalty_points.php");
                exit();
            } else {
                $error_message = "Failed to create transaction record: " . $conn->error;
            }
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
    <title>Loyalty Points - INFOSEC <?php echo ucfirst($role); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        @media print {
            /* Hide non-report UI elements */
            .sidebar, .logout-link, button, .nav-links, .quick-add-btn, .table-header a,
            #notification-panel, .welcome-header div:last-child, #add-points-box, #adjust-points-box {
                display: none !important;
            }
            /* Expand content */
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            .table-box {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                page-break-inside: avoid;
            }
            /* Professional header */
            .print-report-header {
                display: block !important;
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #4a3e94;
                padding-bottom: 15px;
            }
            .print-report-header h2 { margin: 0; color: #4a3e94; font-size: 24px; }
            .print-report-header p { margin: 5px 0 0; color: #666; font-size: 14px; }
        }
        .print-report-header { display: none; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand" style="text-align: center; padding: 20px 15px;">
            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
            <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        </div>
        <ul class="nav-links" style="list-style: none; padding: 0;">
                        <li><a href="<?php echo BASE_URL; ?>/modules/admin/dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-home"></i> Dashboard</a></li>            
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'staff_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php" <?php echo basename($_SERVER['PHP_SELF']) === 'activity_logs.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Activity Logs</a></li>
            <?php endif; ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage_rewards.php' ? 'class="active"' : ''; ?>><i class="fas fa-boxes"></i> Manage Rewards</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/products.php" <?php echo basename($_SERVER['PHP_SELF']) === 'products.php' ? 'class="active"' : ''; ?>><i class="fas fa-store"></i> Products</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/orders.php" <?php echo basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-shopping-cart"></i> Orders
                <?php if ($pending_orders_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
                <?php endif; ?>
            </a></li>            
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" <?php echo basename($_SERVER['PHP_SELF']) === 'messages.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-comment-dots"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
                <?php endif; ?>
            </a></li>            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link" style="position: absolute; bottom: 20px; left: 20px; text-decoration: none;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="main-content">
        <div class="print-report-header">
            <h2>INFOSEC Loyalty Management System</h2>
            <h3>Customer Points & Transaction Report</h3>
            <p>Generated by: <?php echo htmlspecialchars($username); ?> | Date: <?php echo date('F j, Y, g:i a'); ?></p>
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
                <input type="hidden" name="process_points" value="1">
                <select name="customer_id" id="adj_customer_id" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <option value="">Select Customer</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" <?php echo $selected_customer == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?> (Current: <?php echo number_format($customer['loyalty_points'], 2); ?> pts)
                        </option>
                    <?php endforeach; ?>
                </select>

                <div style="grid-column: 1 / -1; display: flex; gap: 15px; margin-top: 5px; margin-bottom: 10px;">
                    <label style="display: flex; align-items: center; gap: 5px; font-weight: 600; color: #555;">
                        <input type="radio" name="adjustment_type" value="manual" checked onchange="toggleAdjustmentType()"> Adjust Points (Manual)
                    </label>
                </div>


                <!-- Purchase Fields -->
                <div id="purchase-fields" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); grid-column: 1 / -1; gap: 12px;">
                    <input type="text" name="purchase_description" id="purchase_description" placeholder="Purchase Description (e.g. Mixed Feeds)" value="Walk-in Purchase" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <input id="amount_spent" type="number" step="0.01" min="1" name="amount_spent" placeholder="Amount Spent (PHP)" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <div style="display: flex; align-items: center; color: #4a3e94; font-weight: 600; grid-column: span 2;">
                        <span id="points-preview">₱0.00 = 0.00 points (Rate: ₱100 = 1 point)</span>
                    </div>
                </div>

                <!-- Manual Adjustment Fields -->
                <div id="manual-fields" style="display: none; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); grid-column: 1 / -1; gap: 12px;">
                    <input type="number" step="0.01" name="points_value" id="points_value" placeholder="Points Value (+/- e.g., -5 or 10)" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <select name="manual_reason" id="manual_reason" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                        <option value="System Correction">System Correction</option>
                        <option value="Refund">Refund</option>
                        <option value="Special Bonus">Special Bonus</option>
                        <option value="Error Correction">Error Correction</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div style="grid-column: 1 / -1;">
                    <button type="submit" name="process_points" style="background: #4a3e94; color: white; border: none; padding: 12px 25px; border-radius: 5px; cursor: pointer; font-weight: 700;">
                        Process Points
                    </button>
                </div>
            </form>
            <p style="font-size: 0.85rem; color: #777; margin-top: 10px;">*Note: Use negative sign (e.g., -5) to deduct points.</p>
        </div>

        <div class="table-box" style="margin-bottom: 25px;">
            <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">Customer Loyalty Summary</h2>
            </div>

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
                    <?php if (!empty($customers)): ?>
                        <?php $display_id = 1; ?>
                        <?php foreach ($customers as $customer): ?>
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
                            <td colspan="6" style="text-align: center; padding: 20px;">No customers found yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

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

    <script>
        const amountInput = document.getElementById('amount_spent'); // For purchase
        const pointsPreview = document.getElementById('points-preview'); // For purchase
        const purchaseFields = document.getElementById('purchase-fields');
        const manualFields = document.getElementById('manual-fields');
        const pointsValueInput = document.getElementById('points_value'); // For manual adjustment
        const purchaseDescriptionInput = document.getElementById('purchase_description'); // For purchase
        const manualReasonSelect = document.getElementById('manual_reason'); // For manual adjustment

        function updatePointsPreview() {
            if (amountInput) {
                const val = parseFloat(amountInput.value || '0');
                const pts = (val / 100).toFixed(2);
                pointsPreview.textContent = `₱${val.toLocaleString()} = ${pts} points (Rate: ₱100 = 1 point)`;
            }
        }

        function toggleAdjustmentType() {
            const type = document.querySelector('input[name="adjustment_type"]:checked').value;
            if (type === 'purchase') {
                purchaseFields.style.display = 'grid';
                manualFields.style.display = 'none';
                amountInput.required = true;
                purchaseDescriptionInput.required = true;
                pointsValueInput.required = false;
                manualReasonSelect.required = false;
                updatePointsPreview(); // Update preview when switching
            } else {
                purchaseFields.style.display = 'none';
                manualFields.style.display = 'grid';
                amountInput.required = false;
                purchaseDescriptionInput.required = false;
                pointsValueInput.required = true;
                manualReasonSelect.required = true;
            }
        }

        if (amountInput) amountInput.addEventListener('input', updatePointsPreview);

        // Initial call to set correct fields based on default checked radio or previous POST
        document.addEventListener('DOMContentLoaded', () => {
            toggleAdjustmentType();
            updatePointsPreview(); // Ensure preview is updated on load
        });

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
</body>
</html>
