<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$user_id = (int) ($payload['user_id'] ?? 0);
$role = strtolower(trim($payload['role'] ?? 'staff'));

// Only admin can access this page
if ($role !== 'admin') {
    header("Location: " . BASE_URL . "/modules/staff/dashboard.php");
    exit();
}

// Ensure supplier deliveries table exists
$conn->query("CREATE TABLE IF NOT EXISTS tbl_supplier_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_email VARCHAR(255) DEFAULT NULL,
    supplier_name VARCHAR(255) NOT NULL,
    items_summary TEXT NOT NULL,
    tracking_number VARCHAR(100) DEFAULT NULL,
    status ENUM('pending', 'in_transit', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
    expected_date DATE DEFAULT NULL,
    received_date DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// --- FIX: Ensure the supplier_email column exists ---
$check_col = $conn->query("SHOW COLUMNS FROM `tbl_supplier_deliveries` LIKE 'supplier_email'");
if ($check_col && $check_col->num_rows === 0) {
    $conn->query("ALTER TABLE `tbl_supplier_deliveries` ADD COLUMN `supplier_email` VARCHAR(255) DEFAULT NULL AFTER `id`");
}
// --- End of fix ---

// Handle POST actions (Adding or updating deliveries)
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_delivery') {
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $supplier_email = trim($_POST['supplier_email'] ?? '');
        $items_summary = trim($_POST['items_summary'] ?? '');
        $tracking_number = trim($_POST['tracking_number'] ?? '');
        $expected_date = !empty($_POST['expected_date']) ? $_POST['expected_date'] : null;
        $notes = trim($_POST['notes'] ?? '');

        if (!empty($supplier_name) && !empty($items_summary)) {
            $stmt = $conn->prepare("INSERT INTO tbl_supplier_deliveries (supplier_name, supplier_email, items_summary, tracking_number, expected_date, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $supplier_name, $supplier_email, $items_summary, $tracking_number, $expected_date, $notes);
            if ($stmt->execute()) {
                $message = "Supplier delivery recorded successfully!";
                // If an email was provided, send the purchase order
                if (!empty($supplier_email) && filter_var($supplier_email, FILTER_VALIDATE_EMAIL)) {
                    if (send_supplier_order_email($supplier_email, $supplier_name, $items_summary, $notes)) {
                        $message .= " Purchase order email sent to supplier.";
                    } else {
                        $error = "Delivery was recorded, but the purchase order email could not be sent.";
                    }
                }
            } else {
                $error = "Failed to record delivery: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error = "Supplier Name and Items Summary are required.";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        $delivery_id = (int)($_POST['delivery_id'] ?? 0);
        $new_status = $_POST['status'] ?? '';

        if ($delivery_id > 0 && in_array($new_status, ['delivered', 'cancelled'])) {
            if ($new_status === 'delivered') {
                // Mark as delivered and set the received date
                $stmt = $conn->prepare("UPDATE tbl_supplier_deliveries SET status = 'delivered', received_date = NOW() WHERE id = ?");
                $stmt->bind_param("i", $delivery_id);
                $message = "Delivery marked as received!";
            } else { // cancelled
                $stmt = $conn->prepare("UPDATE tbl_supplier_deliveries SET status = 'cancelled' WHERE id = ?");
                $stmt->bind_param("i", $delivery_id);
                $message = "Delivery has been cancelled.";
            }

            if ($stmt->execute()) {
                // Success
            } else {
                $error = "Failed to update status: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error = "Invalid action or delivery ID.";
        }
    }
}

// Fetch order metrics for sidebar badge & headers
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

$unread_count = get_unread_count_staff($user_id);

// Fetch Supplier Deliveries
$deliveries_query = mysqli_query($conn, "SELECT * FROM tbl_supplier_deliveries ORDER BY created_at DESC");
$deliveries = [];
if ($deliveries_query) {
    while ($row = mysqli_fetch_assoc($deliveries_query)) {
        $deliveries[] = $row;
    }
}

/**
 * Sends a purchase order email to a supplier.
 */
function send_supplier_order_email(string $supplier_email, string $supplier_name, string $items_summary, string $notes): bool {
    $mail = new PHPMailer(true);
    try {
        //Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = SMTP_AUTH;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;

        //Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, htmlspecialchars(SYSTEM_NAME));
        $mail->addAddress($supplier_email, htmlspecialchars($supplier_name));
        $mail->addReplyTo(SMTP_FROM_EMAIL, htmlspecialchars(SYSTEM_NAME));

        //Content
        $mail->isHTML(true);
        $mail->Subject = 'New Purchase Order from ' . htmlspecialchars(SYSTEM_NAME);
        $mail->Body    = "
            <p>Hello " . htmlspecialchars($supplier_name) . ",</p>
            <p>We would like to place a new order for the following items:</p>
            <pre>" . nl2br(htmlspecialchars($items_summary)) . "</pre>
            <p><strong>Notes:</strong> " . nl2br(htmlspecialchars($notes)) . "</p>
            <p>Please confirm this order and provide an estimated delivery date.</p>
            <p>Thank you,<br>" . htmlspecialchars(SYSTEM_NAME) . "</p>
        ";
        $mail->AltBody = "Hello " . htmlspecialchars($supplier_name) . ",\n\nWe would like to place a new order for the following items:\n\n" . htmlspecialchars($items_summary) . "\n\nNotes: " . htmlspecialchars($notes) . "\n\nPlease confirm this order and provide an estimated delivery date.\n\nThank you,\n" . htmlspecialchars(SYSTEM_NAME);

        return $mail->send();
    } catch (Exception $e) {
        error_log("Supplier email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// Deliveries status breakdown
$delivery_counts = [
    'total' => count($deliveries),
    'pending' => 0,
    'in_transit' => 0,
    'delivered' => 0
];
foreach ($deliveries as $d) {
    if (isset($delivery_counts[$d['status']])) {
        $delivery_counts[$d['status']]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Deliveries - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        .delivery-container {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        }
        .header-action {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .header-action h2 {
            margin: 0;
            color: #1e293b;
        }
        .btn-add {
            background: #2563eb;
            color: white;
            padding: 10px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-add:hover {
            background: #1d4ed8;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 15px 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .stat-card i {
            font-size: 1.8rem;
            color: #3b82f6;
        }
        .stat-info h4 {
            margin: 0;
            font-size: 0.85rem;
            color: #64748b;
        }
        .stat-info p {
            margin: 5px 0 0 0;
            font-size: 1.3rem;
            font-weight: bold;
            color: #1e293b;
        }
        .delivery-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .delivery-table th, .delivery-table td {
            padding: 14px 15px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.95rem;
            vertical-align: middle;
        }
        .delivery-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 600;
        }
        .badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
            display: inline-block;
        }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-in_transit { background: #dbeafe; color: #2563eb; }
        .badge-delivered { background: #dcfce7; color: #16a34a; }
        .badge-cancelled { background: #fee2e2; color: #dc2626; }
        
        .alert {
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        /* Modern Action Buttons Styling */
        .action-buttons-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
        }

        .btn-action {
            padding: 7px 13px;
            border-radius: 7px;
            font-size: 0.82rem;
            font-weight: 600;
            border: 1px solid transparent;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            outline: none;
            white-space: nowrap;
        }

        .btn-approve {
            background-color: #ecfdf5;
            color: #047857;
            border-color: #a7f3d0;
        }

        .btn-approve:hover {
            background-color: #059669;
            color: #ffffff;
            border-color: #059669;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.2);
            transform: translateY(-1px);
        }

        .btn-cancel {
            background-color: #fef2f2;
            color: #b91c1c;
            border-color: #fecaca;
        }

        .btn-cancel:hover {
            background-color: #dc2626;
            color: #ffffff;
            border-color: #dc2626;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2);
            transform: translateY(-1px);
        }

        .text-action-disabled {
            color: #94a3b8;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-style: italic;
        }

        /* Modal styling */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center; justify-content: center;
            z-index: 1000;
        }
        .modal-content {
            background: white;
            padding: 25px;
            border-radius: 12px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #334155;
            font-size: 0.9rem;
        }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-sizing: border-box;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
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
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance</a></li>            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
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
        <div class="welcome-header" style="margin-bottom: 30px;">
            <h1>Supplier Deliveries 🚚</h1>
            <p>Manage incoming stock shipments from suppliers.</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-boxes-packing"></i>
                <div class="stat-info">
                    <h4>Total Deliveries</h4>
                    <p><?php echo $delivery_counts['total']; ?></p>
                </div>
            </div>
            <div class="stat-card">
                <i class="fas fa-clock" style="color: #d97706;"></i>
                <div class="stat-info">
                    <h4>Pending</h4>
                    <p><?php echo $delivery_counts['pending']; ?></p>
                </div>
            </div>
            <div class="stat-card">
                <i class="fas fa-truck-fast" style="color: #2563eb;"></i>
                <div class="stat-info">
                    <h4>In Transit</h4>
                    <p><?php echo $delivery_counts['in_transit']; ?></p>
                </div>
            </div>
            <div class="stat-card">
                <i class="fas fa-circle-check" style="color: #16a34a;"></i>
                <div class="stat-info">
                    <h4>Delivered</h4>
                    <p><?php echo $delivery_counts['delivered']; ?></p>
                </div>
            </div>
        </div>

        <div class="delivery-container">
            <div class="header-action">
                <h2>Incoming Deliveries</h2>
                <button class="btn-add" onclick="openModal()"><i class="fas fa-plus"></i> Record Delivery</button>
            </div>

            <?php if (empty($deliveries)): ?>
                <div class="empty-state">
                    <i class="fas fa-truck-ramp-box" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 10px;"></i>
                    <p>No supplier deliveries logged yet. Click "Record Delivery" to track incoming stock.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="delivery-table">
                        <thead>
                            <tr>
                                <th>Supplier</th>
                                <th>Items / Description</th>
                                <th>Tracking No.</th>
                                <th>Expected Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deliveries as $item): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['supplier_name']); ?></strong></td>
                                    <td><?php echo nl2br(htmlspecialchars($item['items_summary'])); ?></td>
                                    <td><code><?php echo htmlspecialchars($item['tracking_number'] ?: 'N/A'); ?></code></td>
                                    <td><?php echo $item['expected_date'] ? date('M d, Y', strtotime($item['expected_date'])) : '—'; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo htmlspecialchars($item['status']); ?>">
                                            <?php echo str_replace('_', ' ', $item['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($item['status'] === 'pending' || $item['status'] === 'in_transit'): ?>
                                            <div class="action-buttons-group">
                                                <form method="POST" onsubmit="return confirm('Did you receive this delivery?');" style="margin: 0;">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="delivery_id" value="<?php echo $item['id']; ?>">
                                                    <input type="hidden" name="status" value="delivered">
                                                    <button type="submit" class="btn-action btn-approve">
                                                        <i class="fas fa-check"></i> Mark Delivered
                                                    </button>
                                                </form>
                                                <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this order?');" style="margin: 0;">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="delivery_id" value="<?php echo $item['id']; ?>">
                                                    <input type="hidden" name="status" value="cancelled">
                                                    <button type="submit" class="btn-action btn-cancel">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-action-disabled"><i class="fas fa-lock"></i> No actions</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Delivery Modal -->
    <div id="deliveryModal" class="modal">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0;">Record Supplier Delivery</h3>
                <span onclick="closeModal()" style="cursor: pointer; font-weight: bold; font-size: 1.2rem;">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_delivery">
                <div class="form-group">
                    <label>Supplier Email</label>
                    <input type="email" name="supplier_email" placeholder="supplier@example.com (Optional)">
                </div>
                <div class="form-group">
                    <label>Supplier Name *</label>
                    <input type="text" name="supplier_name" required placeholder="e.g. San Miguel Foods">
                </div>
                <div class="form-group">
                    <label>Items Summary *</label>
                    <textarea name="items_summary" rows="3" required placeholder="e.g. 50x Poultry Feed 25kg, 20x Vitamin Supplements"></textarea>
                </div>
                <div class="form-group">
                    <label>Tracking / Invoice Number</label>
                    <input type="text" name="tracking_number" placeholder="Optional tracking code">
                </div>
                <div class="form-group">
                    <label>Expected Delivery Date</label>
                    <input type="date" name="expected_date">
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="2" placeholder="Additional details or instructions"></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" onclick="closeModal()" style="padding: 8px 16px; border-radius: 6px; border: 1px solid #cbd5e1; background: #f8fafc; cursor: pointer;">Cancel</button>
                    <button type="submit" class="btn-add">Save Delivery</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('deliveryModal').style.display = 'flex';
        }
        function closeModal() {
            document.getElementById('deliveryModal').style.display = 'none';
        }
        window.onclick = function(event) {
            const modal = document.getElementById('deliveryModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>