<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/loyalty_helper.php';
require_once __DIR__ . '/qr_helper.php';

$token = getJWTFromCookie();
$payload = verifyJWT($token);

if (!$payload) {
    clearJWTCookie();
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$role = strtolower(trim($payload['role'] ?? ''));
if ($role !== 'admin') {
    header('Location: ' . BASE_URL . '/modules/staff/orders.php');
    exit();
}

$statuses = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'processing' => 'Processing',
    'ready_for_pickup' => 'Ready for Pickup',
    'out_for_delivery' => 'Out for Delivery',
    'to_ship' => 'To Ship',
    'to_receive' => 'To Receive',
    'reviews' => 'Reviews',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];

// --- E-BIKE RIDER AVAILABILITY BACKEND LOGIC ---
$conn->query("CREATE TABLE IF NOT EXISTS tbl_rider_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_name VARCHAR(100) DEFAULT 'E-Bike Delivery Rider',
    is_available TINYINT(1) DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Handle Rider Status Toggle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_rider_status') {
    $new_status = ($_POST['rider_status'] ?? '') === 'available' ? 1 : 0;
    
    $check_rider = mysqli_query($conn, "SELECT id FROM tbl_rider_status LIMIT 1");
    if (mysqli_num_rows($check_rider) == 0) {
        mysqli_query($conn, "INSERT INTO tbl_rider_status (rider_name, is_available) VALUES ('E-Bike Rider', $new_status)");
    } else {
        mysqli_query($conn, "UPDATE tbl_rider_status SET is_available = $new_status");
    }
    
    header("Location: orders.php?rider_updated=1");
    exit();
}

// Fetch Current Rider Status
$rider_res = mysqli_query($conn, "SELECT is_available FROM tbl_rider_status LIMIT 1");
if ($rider_res && mysqli_num_rows($rider_res) > 0) {
    $is_rider_available = (bool)mysqli_fetch_assoc($rider_res)['is_available'];
} else {
    // Check if rider_name column exists before inserting
    $check_column = $conn->query("SHOW COLUMNS FROM `tbl_rider_status` LIKE 'rider_name'");
    if ($check_column && $check_column->num_rows == 0) {
        // Column doesn't exist, so add it
        $conn->query("ALTER TABLE `tbl_rider_status` ADD `rider_name` VARCHAR(100) DEFAULT 'E-Bike Delivery Rider'");
    }

    // Now it's safe to insert
    $conn->query("INSERT INTO tbl_rider_status (rider_name, is_available) VALUES ('E-Bike Rider', 1)");
    $is_rider_available = true;
}

// Handle Order Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['order_status'])) {
    $order_id = (int) $_POST['order_id'];
    $new_order_status = $_POST['order_status'];
    $allowed_statuses = array_keys($statuses);

    if (in_array($new_order_status, $allowed_statuses, true) && $order_id > 0) {
        // Load previous order data
        $prev_stmt = $conn->prepare(
            "SELECT o.order_status, o.order_number, o.customer_id, c.email, c.name
             FROM tbl_orders o
             LEFT JOIN customers c ON o.customer_id = c.id
             WHERE o.id = ?"
        );
        $prev_stmt->bind_param('i', $order_id);
        $prev_stmt->execute();
        $prev = $prev_stmt->get_result()->fetch_assoc();
        $prev_stmt->close();

        if ($prev) {
            $old_order_status = (string) ($prev['order_status'] ?? '');
            if ($old_order_status === 'cancelled' && $new_order_status === 'completed') {
                header('Location: orders.php?error=cancelled_order');
                exit();
            }

            $conn->begin_transaction();
            $award_result = [];
            try {
                $stmt = $conn->prepare("UPDATE tbl_orders SET order_status = ? WHERE id = ?");
                $stmt->bind_param('si', $new_order_status, $order_id);
                $stmt->execute();
                $stmt->close();
                if (in_array($new_order_status, ['processing', 'ready_for_pickup', 'to_ship', 'to_receive', 'out_for_delivery', 'completed'], true)) {
                    $award_result = loyalty_award_completed_order($conn, $order_id);
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                error_log("Error completing order $order_id: " . $e->getMessage());
                header('Location: orders.php?error=status_update');
                exit();
            }

            // Notify the customer once when the order status changes.
            try {
                if ($old_order_status !== $new_order_status && (int) ($prev['customer_id'] ?? 0) > 0) {
                    $order_number = $prev['order_number'] ?? ('#' . $order_id);
                    $customer_name = $prev['name'] ?? 'Customer';
                    $customer_email = $prev['email'];
                    $new_status_label = $statuses[$new_order_status] ?? $new_order_status;

                    $title = "Order {$order_number} status update";
                    $message = "Hi {$customer_name},\n\nYour order {$order_number} has been updated to: {$new_status_label}.\n\nIf you have any questions, please contact us.\n";
                    if ($new_order_status === 'completed' && (float) ($award_result['points'] ?? 0) > 0) {
                        $message .= "\nLoyalty Points Earned: " . notifications_format_points((float) $award_result['points'])
                            . "\nCurrent Loyalty Points Balance: " . notifications_format_points((float) ($award_result['balance'] ?? 0));
                    }

                    notifications_create($conn, [
                        'user_id' => NULL,
                        'customer_id' => (int) ($prev['customer_id'] ?? 0),
                        'type' => 'order_status_update',
                        'channel' => 'both',
                        'title' => $title,
                        'message' => $message,
                        'reference_table' => 'tbl_orders',
                        'reference_id' => $order_id,
                        'email_to' => $customer_email,
                    ]);
                }

                // If confirmed, send payment confirmation email
                if ($new_order_status === 'confirmed' && $old_order_status !== 'confirmed') {
                    send_payment_confirmation_email($order_id, $prev['email'], $prev['name']);
                }
            } catch (Exception $e) {
                error_log("Error sending order status email for order $order_id: " . $e->getMessage());
            }
        }

        header('Location: orders.php');
        exit();
    }
}

// Order Metrics
$order_summary = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total_orders, SUM(total) AS total_sales, SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) AS pending_orders, SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) AS completed_orders FROM tbl_orders"));
if (!$order_summary) {
    $order_summary = [
        'total_orders' => 0,
        'total_sales' => 0,
        'pending_orders' => 0,
        'completed_orders' => 0,
    ];
}
$order_summary = array_map(function ($value) {
    return $value === null ? 0 : $value;
}, $order_summary);

// Fetch Recent Orders
$order_result = mysqli_query($conn, "SELECT o.id, o.order_number, o.order_status, o.fulfillment_type, o.subtotal, o.delivery_fee, o.loyalty_points_earned, o.total, o.created_at, o.payment_method, o.payment_reference, c.name AS customer_name, c.email AS customer_email,
    GROUP_CONCAT(CONCAT(oi.product_name, ' (x', oi.quantity, ')') SEPARATOR '<br>') as items_summary
    FROM tbl_orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN tbl_order_items oi ON o.id = oi.order_id
    GROUP BY o.id
    ORDER BY o.created_at DESC LIMIT 200");

$orders = [];
if ($order_result) {
    while ($row = mysqli_fetch_assoc($order_result)) {
        $orders[] = $row;
    }
}

$username = htmlspecialchars($payload['username']);
$user_id = (int)$payload['user_id'];
$unread_count = get_unread_count_staff($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Orders - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        body {
            overflow-x: hidden;
            margin: 0;
            background-color: #f8fafc;
        }

        .table-box table { width: 100%; border-collapse: collapse; }
        .table-box th, .table-box td { border: 1px solid #eee; padding: 12px 10px; text-align: left; }
        .table-box th { background: #f8f9fa; }
        
        .status-pill { display: inline-flex; align-items: center; justify-content: center; padding: 6px 10px; border-radius: 999px; font-size: 0.8rem; color: white; font-weight: 700; }
        .status-pending { background: #f39c12; }
        .status-confirmed, .status-processing, .status-ready_for_pickup { background: #3498db; }
        .status-to_ship, .status-to_receive { background: #16a085; }
        .status-reviews { background: #f1c40f; color: #333; }
        .status-out_for_delivery { background: #8e44ad; }
        .status-completed { background: #27ae60; }
        .status-cancelled { background: #c0392b; }

        /* Sidebar Container Flexbox Fix */
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 260px !important;
            height: 100vh !important;
            display: flex !important;
            flex-direction: column !important;
            background-color: #1e293b !important;
            z-index: 1000 !important;
            box-sizing: border-box !important;
        }

        .sidebar-brand {
            flex-shrink: 0 !important;
            padding: 20px 15px !important;
            text-align: center !important;
        }

        .nav-links {
            flex: 1 1 auto !important;
            overflow-y: auto !important;
            list-style: none !important;
            padding: 0 10px !important;
            margin: 0 !important;
        }

        .nav-links::-webkit-scrollbar {
            width: 5px;
        }
        .nav-links::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        .sidebar-footer {
            flex-shrink: 0 !important;
            padding: 16px 20px !important;
            border-top: 1px solid rgba(255, 255, 255, 0.1) !important;
            background-color: #1e293b !important;
            margin-top: auto !important;
        }

        .logout-link {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            color: #ef4444 !important;
            font-weight: 600 !important;
            text-decoration: none !important;
            transition: opacity 0.2s ease;
        }

        .logout-link:hover {
            opacity: 0.8;
        }

        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: 100vh;
            box-sizing: border-box;
        }

        /* Status Badge Styling */
        .badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .badge-delivered { background: #dcfce7; color: #16a34a; }
        .badge-cancelled { background: #fee2e2; color: #dc2626; }

        /* iOS-Style Switch Toggle */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        input:checked + .slider {
            background-color: #10b981;
        }

        input:checked + .slider:before {
            transform: translateX(24px);
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
            <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        </div>
        <ul class="nav-links">
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-home"></i> Dashboard</a></li>            
            
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'staff_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php" <?php echo basename($_SERVER['PHP_SELF']) === 'activity_logs.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Activity Logs</a></li>
            <?php endif; ?>
            
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage_rewards.php' ? 'class="active"' : ''; ?>><i class="fas fa-boxes"></i> Manage Rewards</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/products.php" <?php echo basename($_SERVER['PHP_SELF']) === 'products.php' ? 'class="active"' : ''; ?>><i class="fas fa-store"></i> Products</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/orders.php" <?php echo basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-shopping-cart"></i> Orders
                <?php if ($order_summary['pending_orders'] > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $order_summary['pending_orders']; ?></span>
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
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance</a></li>
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
        <!-- Header with E-Bike Rider Availability Toggle -->
        <div class="welcome-header" style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <h1>Orders 🛒</h1>
                <p>Order records shared across customer, staff, and admin modules.</p>
            </div>

            <!-- E-Bike Rider Status Toggle Widget -->
            <div style="background: white; padding: 12px 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px rgba(0,0,0,0.03); display: flex; align-items: center; gap: 15px;">
                <div>
                    <span style="font-weight: 700; font-size: 0.9rem; display: block; color: #1e293b;">
                        <i class="fas fa-bicycle" style="color: #2563eb;"></i> E-Bike Rider
                    </span>
                    <span class="badge <?php echo $is_rider_available ? 'badge-delivered' : 'badge-cancelled'; ?>" style="margin-top: 3px;">
                        <i class="fas <?php echo $is_rider_available ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                        <?php echo $is_rider_available ? 'Available' : 'Unavailable'; ?>
                    </span>
                </div>
                <form method="POST" id="riderToggleFormHeader" style="margin: 0;">
                    <input type="hidden" name="action" value="toggle_rider_status">
                    <input type="hidden" name="rider_status" value="<?php echo $is_rider_available ? 'unavailable' : 'available'; ?>">
                    <label class="switch">
                        <input type="checkbox" <?php echo $is_rider_available ? 'checked' : ''; ?> onchange="document.getElementById('riderToggleFormHeader').submit();">
                        <span class="slider"></span>
                    </label>
                </form>
            </div>
        </div>

        <div class="stats-container" style="display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap;">
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 240px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-shopping-cart" style="font-size: 2rem; color: #27ae60;"></i>
                <h3>Total Orders</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format((int)$order_summary['total_orders']); ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 240px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-dollar-sign" style="font-size: 2rem; color: #3498db;"></i>
                <h3>Total Sales</h3>
                <p style="font-size: 1.5rem; font-weight: bold;">PHP <?php echo number_format((float)$order_summary['total_sales'], 2); ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 240px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-clock" style="font-size: 2rem; color: #f39c12;"></i>
                <h3>Pending Orders</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format((int)$order_summary['pending_orders']); ?></p>
            </div>
            <div class="card" style="background: white; padding: 20px; border-radius: 12px; flex: 1; min-width: 240px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                <i class="fas fa-check-circle" style="font-size: 2rem; color: #27ae60;"></i>
                <h3>Completed Orders</h3>
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format((int)$order_summary['completed_orders']); ?></p>
            </div>
        </div>

        <div class="table-box" style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
            <h3>Recent Orders</h3>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Order Number</th>
                            <th>Items Purchased</th>
                            <th>Customer</th>
                            <th>Fulfillment</th>
                            <th>Status</th>
                            <th>Payment Ref</th>
                            <th>Total</th>
                            <th>Placed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $row_number = 1;
                        foreach ($orders as $order): 
                        ?>
                            <tr>
                                <td><?php echo $row_number++; ?></td>
                                <td>
                                    <a href="order_details.php?id=<?php echo (int)$order['id']; ?>" style="font-weight: bold; text-decoration: none;">
                                        <?php echo htmlspecialchars($order['order_number']); ?>
                                    </a>
                                </td>
                                <td style="font-size: 0.85rem; color: #475569;"><?php echo $order['items_summary'] ?: 'N/A'; ?></td>
                                <td><?php echo htmlspecialchars($order['customer_name'] ?: 'Unknown'); ?> <br><small><?php echo htmlspecialchars($order['customer_email'] ?: ''); ?></small></td>
                                <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $order['fulfillment_type']))); ?></td>
                                <td>
                                    <form method="post" action="" style="margin:0; display:inline;">
                                        <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                        <select name="order_status" onchange="this.form.submit()" style="padding:4px 8px; border-radius:5px;">
                                            <?php
                                            foreach ($statuses as $key => $label):
                                                $selected = ($order['order_status'] === $key) ? 'selected' : '';
                                                echo "<option value='$key' $selected>$label</option>";
                                            endforeach;
                                            ?>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <?php if ($order['payment_method'] === 'gcash'): ?>
                                        <span style="color: #2980b9; font-weight: bold;">GCash:</span><br>
                                        <small><?php echo htmlspecialchars($order['payment_reference'] ?: 'Pending Ref'); ?></small>
                                    <?php else: ?>
                                        <span style="color: #7f8c8d;">COD</span>
                                    <?php endif; ?>
                                </td>
                                <td>PHP <?php echo number_format((float)$order['total'], 2); ?></td>
                                <td><?php echo htmlspecialchars($order['created_at']); ?></td>
                                <td>
                                    <?php $qr_code_uri = generate_qr_code_uri(json_encode(['order_number' => $order['order_number']])); ?>
                                    <?php if ($qr_code_uri): ?>
                                        <img src="<?php echo $qr_code_uri; ?>" alt="Order QR Code" style="width: 50px; height: 50px; border-radius: 4px;">
                                    <?php else: ?>
                                        <span style="font-size: 0.7rem; color: #ef4444;">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="9" style="text-align:center; padding: 20px;">No orders found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>