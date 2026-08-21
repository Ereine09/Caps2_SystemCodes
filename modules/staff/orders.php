<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/loyalty_helper.php';


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

// Handle order status update AFTER $conn is defined
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['order_status'])) {
    $order_id = (int) $_POST['order_id'];
    $new_order_status = $_POST['order_status'];
    $allowed_statuses = array_keys($statuses);

    if (in_array($new_order_status, $allowed_statuses, true) && $order_id > 0) {
        // Load previous order data (needed for “status changed?” check)
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

                // Save + send email via the existing notification helper
                notifications_create($conn, [
                    'user_id' => NULL, // customer-initiated context
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
        }

        header('Location: orders.php');
        exit();
    }
}


$token = getJWTFromCookie();
$payload = verifyJWT($token);

if (!$payload) {
    clearJWTCookie();
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$role = strtolower(trim($payload['role'] ?? ''));
if ($role === 'admin') {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

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

$order_result = mysqli_query($conn, "SELECT o.id, o.order_number, o.order_status, o.fulfillment_type, o.subtotal, o.delivery_fee, o.loyalty_points_earned, o.total, o.created_at, o.payment_method, o.payment_reference, c.name AS customer_name, c.email AS customer_email
    FROM tbl_orders o
    LEFT JOIN customers c ON o.customer_id = c.id
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
    <title>Order Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        .table-box table { width: 100%; border-collapse: collapse; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="welcome-header" style="margin-bottom: 30px;">
            <h1>Orders</h1>
            <p>Order records shared across customer, staff, and admin modules.</p>
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
                <p style="font-size: 1.5rem; font-weight: bold;"><?php echo number_format((float)$order_summary['total_sales'], 2); ?></p>
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
                            <th>Customer</th>
                            <th>Fulfillment</th>
                            <th>Status</th>
                            <th>Payment Ref</th>
                            <th>Total</th>
                            <th>Placed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($orders as $order): ?>
                            <tr>
                                <td><?php echo $i; ?></td>
                                <?php $i++; ?>
                                <td><?php echo htmlspecialchars($order['order_number']); ?></td>


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
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="7" style="text-align:center; padding: 20px;">No orders found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
