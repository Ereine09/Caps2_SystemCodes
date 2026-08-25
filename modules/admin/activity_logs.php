<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

// Check if logged in via JWT
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
$user_id = $payload['user_id'];
$role = strtolower(trim($payload['role'] ?? 'admin')); 
// Activity log viewer must use the JWT user_id for filtering.
// Do NOT rely on any other IDs.
$user_id = (int)($payload['user_id'] ?? 0);

// --- Schema Update: Add customer_id for customer-side logging ---
$check_col = mysqli_query($conn, "SHOW COLUMNS FROM activity_logs LIKE 'customer_id'");
if ($check_col && mysqli_num_rows($check_col) === 0) {
    mysqli_query($conn, "ALTER TABLE activity_logs ADD COLUMN customer_id INT NULL DEFAULT NULL AFTER user_id");
    mysqli_query($conn, "ALTER TABLE activity_logs MODIFY COLUMN user_id INT NULL DEFAULT NULL");
}

// Check if Admin - redirect if not admin
if (strtolower(trim($role)) !== 'admin') {
    header("Location: " . BASE_URL . "/modules/staff/dashboard.php?error=access_denied");
    exit();
}

$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Activity Logs - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css"> 
</head>
<body>
    <style>
        /* Sidebar Container Solution */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            display: flex;
            flex-direction: column;
            background-color: #1e293b;
            z-index: 1000;
            box-sizing: border-box;
        }

        .sidebar-brand {
            flex-shrink: 0;
            padding: 20px 15px;
            text-align: center;
        }

        /* Ginawang scrollable ang listahan kapag mahaba */
        .nav-links {
            flex: 1;
            overflow-y: auto;
            list-style: none;
            padding: 0 10px !important;
            margin: 0;
        }

        /* Subtle Scrollbar Style */
        .nav-links::-webkit-scrollbar {
            width: 5px;
        }
        .nav-links::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        /* Sidebar Footer para sa Logout (Pinned at the bottom) */
        .sidebar-footer {
            flex-shrink: 0;
            padding: 16px 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            background-color: #1e293b;
        }

        .logout-link {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ef4444 !important;
            font-weight: 600;
            text-decoration: none;
            transition: opacity 0.2s ease;
        }

        .logout-link:hover {
            opacity: 0.8;
        }
    </style>

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
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/delivery.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'delivery.php' ? 'active' : ''; ?>"><i class="fas fa-truck"></i> Delivery</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/about.php" <?php echo basename($_SERVER['PHP_SELF']) === 'about.php' ? 'class="active"' : ''; ?>><i class="fas fa-info-circle"></i> About Us</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" <?php echo basename($_SERVER['PHP_SELF']) === 'messages.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-comment-dots"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
                <?php endif; ?>
            </a></li>            
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance<?php require_once __DIR__ . '/../../app/helpers/admin_badge_helper.php'; $pending_remittances_count = get_pending_remittance_count($conn); if ($pending_remittances_count > 0): ?><span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_remittances_count; ?></span><?php endif; ?></a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-footer">
            <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <div class="main-content">
        <div class="welcome-header">
            <h1>Activity Logs 📜</h1>
            <p>Monitor system changes and user actions.</p>
        </div>

        <div class="table-box">
            <table>
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 2px solid #eee;">
                        <th style="padding: 15px; text-align: left;">Date & Time</th>
                        <th style="padding: 15px; text-align: left;">User</th>
                        <th style="padding: 15px; text-align: left;">Action</th>
                        <th style="padding: 15px; text-align: left;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // PINALITAN: al.timestamp -> al.created_at
                    // Role-based audit feed:
                    // - Admin: see ALL activity logs
                    // - Staff: only see logs they created
                    if ($role === 'admin') {
                        $log_res = mysqli_query(
                            $conn,
                            "SELECT al.*, u.username, c.name as customer_name
                             FROM activity_logs al 
                             LEFT JOIN users u ON al.user_id = u.id 
                             LEFT JOIN customers c ON al.customer_id = c.id
                             ORDER BY al.created_at DESC"
                        );
                    } else {
                        // NOTE: staff shouldn't access this page (admin-only),
                        // but keep logic safe if it happens.
                        $stmt = $conn->prepare(
                            "SELECT al.*, u.username 
                             FROM activity_logs al 
                             LEFT JOIN users u ON al.user_id = u.id 
                             WHERE al.user_id = ?
                             ORDER BY al.created_at DESC"
                        );
                        $stmt->bind_param('i', $user_id);
                        $stmt->execute();
                        $log_res = $stmt->get_result();
                    }

                    if ($log_res && mysqli_num_rows($log_res) > 0) {
                        while ($log = mysqli_fetch_assoc($log_res)) {
                            if (!empty($log['username'])) {
                                $u_display = $log['username'] . ' (Admin)';
                            } elseif (!empty($log['customer_name'])) {
                                $u_display = $log['customer_name'] . ' (Customer)';
                            } else {
                                $u_display = "System/Deleted User";
                            }
                            
                            // COLOR LOGIC PARA SA ACTION COLUMN
                            $action_color = '#3498db'; // Default Blue
                            if ($log['action'] === 'DELETE' || $log['action'] === 'delete_reward') {
                                $action_color = '#e74c3c'; // Red
                            } elseif ($log['action'] === 'POINT_ADJUSTMENT' || $log['action'] === 'REWARD_REDEMPTION') {
                                $action_color = '#f39c12'; // Gold/Orange
                            }
//Point Adjustment
                            echo "<tr>";
                            // PINALITAN: $log['timestamp'] -> $log['created_at']
                            echo "<td style='padding:15px;'>" . date("M d, Y - h:i A", strtotime($log['created_at'])) . "</td>";
                            echo "<td style='padding:15px;'><strong>" . htmlspecialchars($u_display) . "</strong></td>";
                            
                            echo "<td style='padding:15px; color: " . $action_color . "; font-weight: bold;'>";
                            
                            $display_action = str_replace('_', ' ', $log['action']);
                            $icon = ($log['action'] === 'POINT_ADJUSTMENT') ? 'fa-adjust' : 'fa-info-circle';

                            echo "<i class='fas " . $icon . "'></i> " . htmlspecialchars(ucwords(strtolower($display_action)));
                            echo "</td>";

                            echo "<td style='padding:15px;'>" . htmlspecialchars($log['details']) . "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='4' style='text-align: center; padding: 20px;'>No logs recorded yet.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>