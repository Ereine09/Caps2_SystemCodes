<?php
/**
 * Staff — Loyalty points management (same tables as the admin view).
 */

require_once __DIR__ . '/../shared/staff_auth.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$payload  = require_staff();
$user_id  = (int) $payload['user_id'];
$username = $payload['username'] ?? 'Staff';
$role     = 'staff';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
}

if (isset($_POST['mark_notifications_read'])) {
    notifications_mark_all_read($conn, $user_id);
    header('Location: loyalty_points.php');
    exit();
}
$notification_count   = notifications_get_unread_count($conn, $user_id);
$recent_notifications = notifications_get_recent($conn, $user_id, 6);

$success_message = '';
$error_message   = '';
$selected_customer = (int) ($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);

// --- Point adjustment ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_points'])) {
    $adj_customer_id = (int) ($_POST['customer_id'] ?? 0);
    $points_value    = (float) ($_POST['points_value'] ?? 0);
    $manual_reason   = trim($_POST['manual_reason'] ?? 'Manual Adjustment');

    if ($adj_customer_id <= 0) {
        $error_message = 'Please select a customer.';
    } elseif ($points_value == 0.0) {
        $error_message = 'Please enter a non-zero points value.';
    } else {
        $points_to_process = $points_value;
        $transaction_description = ($points_value > 0 ? 'Addition: ' : 'Deduction: ') . $manual_reason;
        $zero_kg = 0.00;

        $trans_stmt = $conn->prepare("INSERT INTO loyalty_transactions (customer_id, user_id, product_name, quantity_kg, points_earned) VALUES (?, ?, ?, ?, ?)");
        $trans_stmt->bind_param('iisdd', $adj_customer_id, $user_id, $transaction_description, $zero_kg, $points_to_process);

        if ($trans_stmt->execute()) {
            $trans_stmt->close();

            $upd = $conn->prepare("UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?");
            $upd->bind_param('di', $points_to_process, $adj_customer_id);
            $upd->execute();
            $upd->close();

            $new_total = notifications_sync_customer_loyalty_points($conn, $adj_customer_id);

            $nm = $conn->prepare("SELECT name, email FROM customers WHERE id = ?");
            $nm->bind_param('i', $adj_customer_id);
            $nm->execute();
            $client_row = $nm->get_result()->fetch_assoc();
            $nm->close();
            $c_name  = $client_row['name'] ?? 'Unknown';
            $c_email = $client_row['email'] ?? '';

            $adjustment_type = $points_value > 0 ? 'Addition' : 'Deduction';

            notifications_create($conn, [
                'user_id'      => $user_id,
                'customer_id'  => $adj_customer_id,
                'type'         => 'point_adjustment',
                'channel'      => 'both',
                'title'        => 'Points Balance Updated',
                'message'      => "Hello $c_name, your loyalty points have been updated by " . number_format($points_to_process, 2) . " points. Reason: $transaction_description. Your new balance is " . number_format($new_total, 2) . " points.",
                'email_to'     => $c_email,
                'points_value' => $points_to_process,
            ]);

            $log_details = "Processed points for $c_name (Type: $adjustment_type, Value: $points_to_process). Reason: $transaction_description";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'POINT_ADJUSTMENT', ?)");
            $log_stmt->bind_param('is', $user_id, $log_details);
            $log_stmt->execute();
            $log_stmt->close();

            $_SESSION['success_message'] = 'Successfully processed ' . number_format($points_to_process, 2) . ' points for ' . htmlspecialchars($c_name) . '. New balance: ' . number_format($new_total, 2) . ' pts.';
            header('Location: loyalty_points.php');
            exit();
        } else {
            $error_message = 'Failed to create transaction record.';
        }
    }
}

if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

$total_customers      = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM customers"))['total'] ?? 0);
$pending_orders_count = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0);
$total_loyalty_points = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(loyalty_points) AS total FROM customers"))['total'] ?? 0);
$total_gross_points   = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points_earned) AS total_gross FROM loyalty_transactions"))['total_gross'] ?? 0);
$unread_messages_count = get_unread_count_staff($user_id);

if ($selected_customer > 0) {
    notifications_sync_customer_loyalty_points($conn, $selected_customer);
}

$customers = [];
$customers_result = mysqli_query($conn, "
    SELECT c.id, c.name, c.email, c.loyalty_points,
        (SELECT COALESCE(SUM(points_earned), 0) FROM loyalty_transactions WHERE customer_id = c.id) AS total_earned_points,
        (SELECT COALESCE(SUM(points_used), 0) FROM reward_redemptions WHERE customer_id = c.id) AS total_redeemed_points
    FROM customers c ORDER BY id ASC");
if ($customers_result) {
    while ($row = mysqli_fetch_assoc($customers_result)) { $customers[] = $row; }
}

$transactions = [];
$transactions_result = mysqli_query($conn,
    "SELECT lt.customer_id, lt.product_name, lt.quantity_kg, lt.points_earned, lt.created_at, c.name AS customer_name
     FROM loyalty_transactions lt LEFT JOIN customers c ON c.id = lt.customer_id
     ORDER BY lt.created_at DESC LIMIT 10");
if ($transactions_result) {
    while ($row = mysqli_fetch_assoc($transactions_result)) { $transactions[] = $row; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loyalty Points - Staff</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        .main-content { margin-left: 260px; padding: 30px; }
        @media print {
            .sidebar, .logout-link, button, .nav-links, .quick-add-btn, #notification-panel, #adjust-points-box { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; width: 100% !important; }
            .table-box { box-shadow: none !important; border: 1px solid var(--border) !important; page-break-inside: avoid; }
            .print-report-header { display: block !important; text-align: center; margin-bottom: 30px; border-bottom: 2px solid #4a3e94; padding-bottom: 15px; }
        }
        .print-report-header { display: none; }
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
        <div class="print-report-header">
            <h2><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
            <h3>Customer Points &amp; Transaction Report</h3>
            <p>Generated by: <?php echo htmlspecialchars($username); ?> | Date: <?php echo date('F j, Y, g:i a'); ?></p>
        </div>

        <div class="welcome-header" style="margin-bottom: 30px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h1>Loyalty Points ⭐</h1>
                    <p>Record walk-in purchases and adjust customer loyalty balances.</p>
                </div>
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; position: relative;">
                    <button type="button" onclick="window.print()" style="background: #2c3e50; color: white; border: none; border-radius: 10px; padding: 10px 14px; cursor: pointer; font-weight: 700;">
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
                                            <strong style="color: #2c3e50;"><?php echo htmlspecialchars($notification['title']); ?></strong>
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
                </div>
            </div>
        </div>

        <div class="stats-container" style="display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap;">
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 220px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-user-friends" style="font-size: 2rem; color: #4a3e94;"></i>
                <h3>Total Customers</h3><p style="font-size: 1.5rem; font-weight: bold;"><?php echo $total_customers; ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 220px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-bottom: 4px solid #f1c40f;">
                <i class="fas fa-star" style="font-size: 2rem; color: #f1c40f;"></i>
                <h3>Total Usable Balance</h3><p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_loyalty_points, 2); ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 220px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-bottom: 4px solid #2ecc71;">
                <i class="fas fa-coins" style="font-size: 2rem; color: #2ecc71;"></i>
                <h3>Total Points Issued</h3><p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format($total_gross_points, 2); ?></p>
            </div>
        </div>

        <?php if ($success_message !== ''): ?>
            <div style="margin-bottom: 20px; padding: 12px 15px; background: #eafaf1; color: #27ae60; border-left: 4px solid #27ae60; border-radius: 8px; font-weight: 600;"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if ($error_message !== ''): ?>
            <div style="margin-bottom: 20px; padding: 12px 15px; background: #fff5f5; color: #e74c3c; border-left: 4px solid #e74c3c; border-radius: 8px; font-weight: 600;"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div id="adjust-points-box" class="table-box" style="margin-bottom: 25px; border-left: 5px solid #4a3e94; background: #fffdfd;">
            <h2 style="margin-bottom: 15px;"><i class="fas fa-tools"></i> Manage Customer Points</h2>
            <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="process_points" value="1">
                <select name="customer_id" id="adj_customer_id" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <option value="">Select Customer (Current Points)</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo (int) $customer['id']; ?>" <?php echo $selected_customer == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?> (Balance: <?php echo number_format($customer['loyalty_points'], 2); ?> pts)
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="number" step="0.01" name="points_value" id="points_value" placeholder="Points Value (e.g., 10 to add, -5 to deduct)" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;" required>
                <select name="manual_reason" id="manual_reason" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <option value="Walk-in Purchase Points">Walk-in Purchase Points</option>
                    <option value="In-Store Redemption">In-Store Redemption (Deduct)</option>
                    <option value="On-Site Promo Bonus">On-Site Promo Bonus</option>
                    <option value="Special Bonus">Special Bonus</option>
                    <option value="Refund">Refund</option>
                    <option value="System Correction">System Correction</option>
                    <option value="Error Correction">Error Correction</option>
                    <option value="Other">Other</option>
                </select>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" name="process_points" style="background: #4a3e94; color: white; border: none; padding: 12px 25px; border-radius: 5px; cursor: pointer; font-weight: 700;">Process Points</button>
                </div>
            </form>
            <p style="font-size: 0.85rem; color: #777; margin-top: 10px;">*Enter a positive number to <strong>add</strong> points, a negative number to <strong>deduct</strong>. The balance is recalculated.</p>
        </div>

        <div class="page-tabs" id="page-tabs" role="tablist">
            <button type="button" class="page-tab active" role="tab" id="tab-transactions" data-tab="transactions" aria-selected="true" aria-controls="panel-transactions">Recent Loyalty Transactions</button>
            <button type="button" class="page-tab" role="tab" id="tab-summary" data-tab="summary" aria-selected="false" aria-controls="panel-summary">Customer Loyalty Summary</button>
        </div>

        <div class="tab-panel" id="panel-transactions" role="tabpanel" aria-labelledby="tab-transactions">
        <div class="table-box">
            <div class="table-header" style="margin-bottom: 20px;"><h2 style="margin: 0;">Recent Loyalty Transactions</h2></div>
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
                                <td style="padding: 15px;"><?php echo number_format((float) $transaction['quantity_kg'], 2); ?></td>
                                <td style="padding: 15px;"><?php echo number_format((float) $transaction['points_earned'], 2); ?></td>
                                <td style="padding: 15px;"><?php echo htmlspecialchars($transaction['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align: center; padding: 20px;">No loyalty transactions recorded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div>

        <div class="tab-panel" id="panel-summary" role="tabpanel" aria-labelledby="tab-summary" hidden>
        <div class="table-box" style="margin-bottom: 25px;">
            <div class="table-header" style="margin-bottom: 20px;"><h2 style="margin: 0;">Customer Loyalty Summary</h2></div>
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
                    <?php if (!empty($customers)): $display_id = 1; ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 15px;"><?php echo $display_id++; ?></td>
                                <td style="padding: 15px;"><a href="customer_details.php?id=<?php echo (int) $customer['id']; ?>" style="text-decoration:none; color:#4a3e94; font-weight:bold;"><?php echo htmlspecialchars($customer['name']); ?></a></td>
                                <td style="padding: 15px;"><?php echo htmlspecialchars($customer['email']); ?></td>
                                <td style="padding: 15px; color: #27ae60;"><?php echo number_format((float) $customer['total_earned_points'], 2); ?></td>
                                <td style="padding: 15px; color: #e74c3c;"><?php echo number_format((float) $customer['total_redeemed_points'], 2); ?></td>
                                <td style="padding: 15px;"><strong><?php echo number_format((float) $customer['loyalty_points'], 2); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align: center; padding: 20px;">No customers found yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
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
            if (!panel) return;
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
