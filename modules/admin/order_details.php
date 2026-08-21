<?php
require_once __DIR__ . '/bootstrap.php'; // Centralized autoloader
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/qr_helper.php';

$token = getJWTFromCookie();
$payload = verifyJWT($token);

if (!$payload || !in_array($payload['role'], ['admin', 'staff'])) {
    clearJWTCookie();
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$role = strtolower(trim($payload['role'] ?? ''));
$order_id = (int)($_GET['id'] ?? 0);

if ($order_id <= 0) {
    header('Location: orders.php');
    exit();
}

$order = get_order_by_id($order_id);
if (!$order) {
    die("Order not found.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'approve_payment') {
        $conn->begin_transaction();
        try {
            // 1. Deduct stock for each item in the order
            $order_items_to_deduct = get_order_items($order_id);
            foreach ($order_items_to_deduct as $item) {
                $variant_id = isset($item['variant_id']) && !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                if (!deduct_product_stock((int)$item['product_id'], $variant_id, (int)$item['quantity'])) {
                    throw new Exception('Insufficient stock for product: ' . htmlspecialchars($item['product_name']));
                }
            }

            // 2. Update order status to 'confirmed'
            $stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'confirmed' WHERE id = ?");
            $stmt->bind_param('i', $order_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            header("Location: order_details.php?id=$order_id&status_updated=1");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error approving order: " . $e->getMessage();
            header("Location: order_details.php?id=$order_id");
            exit();
        }
    } elseif ($action === 'reject_payment') {
        // Stock is not deducted for pending orders, so just cancel the order.
        $stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'cancelled' WHERE id = ?");
        $stmt->bind_param('i', $order_id);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: order_details.php?id=$order_id&status_updated=1");
            exit();
        }
        $stmt->close();
    }
}

$order_items = get_order_items($order_id);
$customer = get_customer_by_id($order['customer_id']);
$delivery_details = get_delivery_details_by_order_id($order_id);

$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$user_id = (int)$payload['user_id'];
$unread_count = get_unread_count_staff($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Details - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
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

        .nav-links {
            flex: 1;
            overflow-y: auto;
            list-style: none;
            padding: 0 10px !important;
            margin: 0;
        }

        .nav-links::-webkit-scrollbar {
            width: 5px;
        }
        .nav-links::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

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

        .order-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; margin-top: 20px; }
        .info-card { background: #fff; padding: 25px; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid #f1f5f9; margin-bottom: 25px; }
        .info-card h4 { margin-top: 0; margin-bottom: 20px; color: #1e293b; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; }
        .info-item { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 0.95rem; }
        .info-label { color: #64748b; font-weight: 500; }
        .info-value { color: #1e293b; font-weight: 700; }
        .item-img { width: 60px; height: 60px; border-radius: 10px; object-fit: cover; background: #f8fafc; border: 1px solid #eee; }
        .order-summary-box { background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px dashed #cbd5e1; }
        
        .button-link {
            background: #eef2ff; color: #4338ca; padding: 6px 12px; border-radius: 8px;
            text-decoration: none; font-weight: 600; font-size: 0.85rem; border: 1px solid #c7d2fe;
            cursor: pointer; transition: all 0.2s ease;
        }
        .button-link:hover { background: #c7d2fe; }

        /* Alert Banners */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }

        /* Proof Modal Styles */
        .modal {
            display: none; position: fixed; z-index: 1001; left: 0; top: 0;
            width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.7);
            align-items: center; justify-content: center;
        }
        .modal-content {
            margin: auto; display: block; width: 80%; max-width: 600px;
            animation: zoom 0.3s;
        }
        @keyframes zoom { from {transform:scale(0.8)} to {transform:scale(1)} }
        .close-modal {
            position: absolute; top: 25px; right: 45px; color: #f1f1f1;
            font-size: 40px; font-weight: bold; cursor: pointer;
        }

        .summary-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.95rem; color: #475569; }
        .summary-total { margin-top: 15px; padding-top: 15px; border-top: 2px solid #e2e8f0; font-size: 1.2rem; color: #1e293b; font-weight: 800; }
        @media (max-width: 900px) { .order-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
            <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        </div>
        <ul class="nav-links">
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php"><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php"><i class="fas fa-history"></i> Activity Logs</a></li>
            <?php endif; ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php"><i class="fas fa-boxes"></i> Manage Rewards</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/products.php"><i class="fas fa-store"></i> Products</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/orders.php" class="active">
                <i class="fas fa-shopping-cart"></i> Orders
                <?php if ($pending_orders_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/delivery.php"><i class="fas fa-truck"></i> Delivery</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/about.php"><i class="fas fa-info-circle"></i> About Us</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php">
                <i class="fas fa-comment-dots"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php"><i class="fas fa-money-bill-wave"></i> Remittance</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php"><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-footer">
            <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <div class="main-content">
        <div class="welcome-header" style="margin-bottom: 30px;">
            <h1>Order Details</h1>
            <p>Viewing order #<?php echo htmlspecialchars($order['order_number']); ?></p>
        </div>

        <!-- Notification Alerts -->
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['status_updated']) && $_GET['status_updated'] == 1): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span>Order status has been updated successfully.</span>
            </div>
        <?php endif; ?>

            <?php if ($delivery_details && $order['order_status'] !== 'cancelled'): ?>

                <div class="info-card" style="margin-top: 25px; text-align: center; border-left: 5px solid #10b981;">
                    <h4 style="justify-content: center;"><i class="fas fa-qrcode"></i> Delivery Confirmation QR</h4>
                    <p style="font-size: 0.9rem; color: #475569; max-width: 450px; margin: 0 auto 20px;">
                        Please present this QR code to the rider upon delivery to confirm you have received your order.
                    </p>
                    <?php
                    // Dynamic JSON payload per order
                    $qr_data = json_encode([
                        'delivery_id' => (int)$delivery_details['id'],
                        'order_id'    => (int)$order['id'],
                        'token'       => $delivery_details['qr_confirmation_token']
                    ]);

                    $qr_code_uri = null;

                    // Option 1: Try Endroid PHP Library
                    if (class_exists('Endroid\\QrCode\\QrCode') && class_exists('Endroid\\QrCode\\Writer\\PngWriter')) {
                        try {
                            $qrCode = \Endroid\QrCode\QrCode::create($qr_data);
                            $writer = new \Endroid\QrCode\Writer\PngWriter();
                            $qr_code_uri = $writer->write($qrCode)->getDataUri();
                        } catch (Throwable $t) {
                            $qr_code_uri = null;
                        }
                    }

                    // Option 2: Fallback to QuickChart API (Works reliably for any order)
                    if (!$qr_code_uri) {
                        $qr_code_uri = "https://quickchart.io/qr?size=250&text=" . urlencode($qr_data);
                    }
                    ?>
                    <img src="<?php echo $qr_code_uri; ?>" alt="Delivery Confirmation QR Code" style="width: 220px; height: 220px; border: 5px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border-radius: 8px;">
                    <p style="margin-top: 10px; font-size: 0.75rem; color: #94a3b8;">Delivery Ref ID: #<?php echo (int)$delivery_details['id']; ?></p>
                </div>
        <?php endif; ?>

        <div class="order-grid">
            <div class="order-main">
            <div style="margin-top: 10px;">
                <a href="orders.php" class="button button-secondary"><i class="fas fa-arrow-left"></i> Back to All Orders</a>
            </div>
                <div class="info-card">
                    <h4><i class="fas fa-shopping-basket"></i> Order Items</h4>
                    <table class="customer-table" style="width:100%;">
                        <thead><tr><th></th><th>Item</th><th>Qty</th><th>Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($order_items as $item): ?>
                                <tr>
                                    <td style="width: 60px;"><img src="<?php echo htmlspecialchars($item['image_url'] ?: BASE_URL . '/assets/img/placeholder.png'); ?>" class="item-img" alt=""></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['product_name']); ?></strong><br>
                                        <small style="color: #64748b;">PHP <?php echo number_format($item['unit_price'], 2); ?></small>
                                    </td>
                                    <td>x<?php echo htmlspecialchars($item['quantity']); ?></td>
                                    <td><strong>PHP <?php echo number_format($item['total_price'], 2); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="order-sidebar">
                <div class="info-card">
                    <h4><i class="fas fa-user"></i> Customer Info</h4>
                    <div class="info-item"><span class="info-label">Name</span><span class="info-value"><?php echo htmlspecialchars($customer['name']); ?></span></div>
                    <div class="info-item"><span class="info-label">Email</span><span class="info-value"><?php echo htmlspecialchars($customer['email']); ?></span></div>
                    <div class="info-item"><span class="info-label">Phone</span><span class="info-value"><?php echo htmlspecialchars($customer['phone']); ?></span></div>
                </div>
                <div class="info-card">
                    <h4><i class="fas fa-info-circle"></i> Fulfillment Info</h4>
                    <div class="info-item"><span class="info-label">Method</span><span class="info-value"><?php echo ucfirst($order['fulfillment_type']); ?></span></div>
                    <?php if ($order['fulfillment_type'] === 'pickup'): ?>
                        <div class="info-item"><span class="info-label">Date</span><span class="info-value"><?php echo htmlspecialchars($order['pickup_date']); ?></span></div>
                        <div class="info-item"><span class="info-label">Time</span><span class="info-value"><?php echo htmlspecialchars($order['pickup_time']); ?></span></div>
                    <?php else: ?>
                        <div class="info-item" style="flex-direction: column; gap: 5px;"><span class="info-label">Address</span><span class="info-value" style="font-weight: 500; font-size: 0.85rem;"><?php echo htmlspecialchars($order['delivery_address']); ?></span></div>
                        <div class="info-item"><span class="info-label">Phone</span><span class="info-value"><?php echo htmlspecialchars($order['delivery_phone']); ?></span></div>
                    <?php endif; ?>
                </div>
                <div class="info-card">
                    <h4><i class="fas fa-credit-card"></i> Payment</h4>
                    <div class="info-item"><span class="info-label">Method</span><span class="info-value"><?php echo strtoupper($order['payment_method']); ?></span></div>
                    <?php if ($order['payment_method'] === 'bank'): ?>
                        <div class="info-item"><span class="info-label">Bank Name</span><span class="info-value"><?php echo htmlspecialchars($order['bank_name']); ?></span></div>
                        <div class="info-item"><span class="info-label">Account Name</span><span class="info-value"><?php echo htmlspecialchars($order['bank_account_name']); ?></span></div>
                        <div class="info-item"><span class="info-label">Reference #</span><span class="info-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div>
                        <?php if ($order['payment_proof_path']): ?>
                            <div class="info-item">
                                <span class="info-label">Proof</span>
                                <span class="info-value">
                                    <button type="button" class="button-link" onclick="openProofModal('<?php echo BASE_URL . htmlspecialchars($order['payment_proof_path']); ?>')">View Proof</button>
                                </span>
                            </div>
                        <?php endif; ?>
                    <?php elseif ($order['payment_reference']): ?>
                        <div class="info-item"><span class="info-label">Reference #</span><span class="info-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div>
                    <?php endif; ?>

                    <?php if ($order['order_status'] === 'pending' && ($order['payment_method'] === 'gcash' || $order['payment_method'] === 'bank')): ?>
                        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #f1f5f9; display: flex; gap: 10px;">
                            <form method="POST" style="flex: 1;">
                                <input type="hidden" name="action" value="approve_payment">
                                <button type="submit" class="button" style="width: 100%; background: #10b981;"><i class="fas fa-check-circle"></i> Approve</button>
                            </form>
                            <form method="POST" style="flex: 1;" onsubmit="return confirm('Are you sure you want to reject this payment and cancel the order?');">
                                <input type="hidden" name="action" value="reject_payment">
                                <button type="submit" class="button button-secondary" style="width: 100%; background: #ef4444; color: white;"><i class="fas fa-times-circle"></i> Reject</button>
                            </form>
                        </div>
                    <?php endif; ?>

                </div>
                <div class="info-card">
                    <h4><i class="fas fa-file-invoice-dollar"></i> Summary</h4>
                    <div class="order-summary-box">
                        <div class="summary-row"><span>Subtotal</span><span>PHP <?php echo number_format($order['subtotal'], 2); ?></span></div>
                        <div class="summary-row"><span>VAT (12%)</span><span>PHP <?php echo number_format($order['vat_amount'], 2); ?></span></div>
                        <div class="summary-row"><span>Delivery Fee</span><span>PHP <?php echo number_format($order['delivery_fee'], 2); ?></span></div>
                        <?php if ((float)$order['discount_amount'] > 0): ?>
                            <div class="summary-row" style="color: #10b981; font-weight: 600;"><span>Discount</span><span>-PHP <?php echo number_format($order['discount_amount'], 2); ?></span></div>
                        <?php endif; ?>
                        <div class="summary-row" style="color: #27ae60;"><span>Loyalty Earned</span><span>+<?php echo number_format($order['loyalty_points_earned'], 2); ?> pts</span></div>
                        <div class="summary-row summary-total"><span>Total Paid</span><span>PHP <?php echo number_format($order['total'], 2); ?></span></div>
                    </div>
                </div>
                <div class="info-card" style="text-align: center;">
                    <h4><i class="fas fa-qrcode"></i> Delivery Confirmation QR</h4>
                    <?php
                    $qr_code_uri = '';
                    if ($delivery_details && !empty($delivery_details['qr_confirmation_token'])) {
                        $qr_payload = json_encode([
                            'delivery_id' => (int)$delivery_details['id'],
                            'order_id'    => (int)$order['id'],
                            'token'       => $delivery_details['qr_confirmation_token']
                        ]);
                        $qr_code_uri = generate_qr_code_uri($qr_payload);
                    }
                    ?>
                    <?php if ($qr_code_uri): ?>
                        <img src="<?php echo $qr_code_uri; ?>" alt="Order QR Code" style="width: 180px; height: 180px; border: 4px solid #f1f5f9; border-radius: 10px;">
                        <p style="font-size: 0.8rem; color: #64748b; margin-top: 10px;">
                            Rider scans this to confirm delivery.
                        </p>
                    <?php else: ?>
                        <p style="font-size: 0.8rem; color: #ef4444; margin-top: 10px;">QR Code could not be generated.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div style="margin-top: 10px;">
            <a href="orders.php" class="button button-secondary"><i class="fas fa-arrow-left"></i> Back to All Orders</a>
        </div>
    </div>

    <!-- Proof of Payment Modal -->
    <div id="proofModal" class="modal">
        <span class="close-modal" onclick="closeProofModal()">&times;</span>
        <img class="modal-content" id="proofModalImage">
    </div>

    <script>
        const modal = document.getElementById('proofModal');
        const modalImg = document.getElementById('proofModalImage');

        function openProofModal(src) {
            modal.style.display = "flex";
            modalImg.src = src;
        }

        function closeProofModal() {
            modal.style.display = "none";
        }

        window.onclick = (event) => event.target == modal ? closeProofModal() : null;
    </script>
</body>
</html>