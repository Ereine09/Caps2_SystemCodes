<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$user_id = (int)$payload['user_id'];
$role = strtolower(trim($payload['role'] ?? 'staff'));

// Allow both admins and staff to manage rewards and stock
if ($role !== 'admin' && $role !== 'staff') {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

// --- Database Schema Check ---
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

// Add expiry_date column if it doesn't exist
$check_col = mysqli_query($conn, "SHOW COLUMNS FROM rewards LIKE 'expiry_date'");
if (mysqli_num_rows($check_col) == 0) {
    mysqli_query($conn, "ALTER TABLE rewards ADD COLUMN expiry_date DATE DEFAULT NULL AFTER stock");
}

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_reward'])) {
        $code = trim($_POST['reward_code']);
        $name = trim($_POST['name']);
        $points = (float)$_POST['points'];
        $stock = (int)$_POST['stock'];
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $desc = trim($_POST['description']);

        try {
            $stmt = $conn->prepare("INSERT INTO rewards (reward_code, name, points, stock, expiry_date, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdiss", $code, $name, $points, $stock, $expiry_date, $desc);
            if ($stmt->execute()) {
                $success_message = "New reward item added successfully.";
                $details = "Added reward: $name ($code)";
                $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Manage Rewards', ?)");
                $log->bind_param("is", $user_id, $details);
                $log->execute();
            } else {
                $error_message = "Error adding reward: " . $conn->error;
            }
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) {
                $error_message = "The Reward Code '$code' is already in use. Please provide a unique code.";
            } else {
                $error_message = "Database Error: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_stock'])) {
        $id = (int)$_POST['reward_id'];
        $new_stock = (int)$_POST['stock'];
        $new_expiry = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        
        $res = mysqli_query($conn, "SELECT name FROM rewards WHERE id = $id");
        $reward = mysqli_fetch_assoc($res);
        
        $stmt = $conn->prepare("UPDATE rewards SET stock = ?, expiry_date = ? WHERE id = ?");
        $stmt->bind_param("isi", $new_stock, $new_expiry, $id);
        if ($stmt->execute()) {
            $success_message = "Restocked " . $reward['name'] . " successfully.";
            $details = "Updated stock for " . $reward['name'] . " to $new_stock";
            
            // Log point for audit trail
            $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Manage Rewards', ?)");
            $log->bind_param("is", $user_id, $details);
            $log->execute();
        }
    }
}

$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);

$rewards = mysqli_query($conn, "SELECT * FROM rewards ORDER BY points DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Rewards - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
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
        <div class="welcome-header">
            <h1>Manage Reward Inventory 📦</h1>
            <p>Add new physical rewards or update stock levels for existing items.</p>
        </div>

        <?php if ($success_message): ?>
            <div style="padding: 15px; background: #eafaf1; color: #27ae60; border-left: 4px solid #27ae60; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div style="padding: 15px; background: #fff5f5; color: #e74c3c; border-left: 4px solid #e74c3c; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="table-box" style="margin-bottom: 30px; border-left: 5px solid #3498db;">
            <h2><i class="fas fa-plus-circle"></i> Add New Reward</h2>
            <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                <input type="text" name="reward_code" placeholder="Code (e.g. DPS-RW-01)" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                <div>
                    <input type="text" name="name" placeholder="Reward Name (e.g., Gift Card of ₱50)" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 5px; display: block;">For gift cards, include the monetary value in the name (e.g., "Gift Card of ₱100").</small>
                </div>
                <input type="number" step="0.01" name="points" placeholder="Points Required" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                <input type="number" name="stock" placeholder="Initial Stock" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-size: 0.8rem; color: #666;">Expiration Date (Optional)</label>
                    <input type="date" name="expiry_date" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                </div>
                <textarea name="description" placeholder="Short description..." style="grid-column: 1 / -1; padding: 10px; border-radius: 5px; border: 1px solid #ddd;"></textarea>
                <button type="submit" name="add_reward" style="background: #3498db; color: white; border: none; padding: 12px; border-radius: 5px; cursor: pointer; font-weight: bold;">
                    Save Reward Item
                </button>
            </form>
        </div>

        <div class="table-box">
            <h2><i class="fas fa-inventory"></i> Current Inventory</h2>
            <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                <thead>
                    <tr style="background: #f8f9fa; text-align: left;">
                        <th style="padding: 12px;">Reward Item</th>
                        <th style="padding: 12px;">Points</th>
                        <th style="padding: 12px;">Current Stock</th>
                        <th style="padding: 12px;">Expiry Date</th>
                        <th style="padding: 12px;">Restock / Update</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($r = mysqli_fetch_assoc($rewards)): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px;">
                                <strong><?php echo htmlspecialchars($r['name']); ?></strong><br>
                                <small style="color: #888;"><?php echo htmlspecialchars($r['reward_code']); ?></small>
                            </td>
                            <td style="padding: 12px;"><?php echo number_format($r['points'], 2); ?></td>
                            <td style="padding: 12px;">
                                <?php if ($r['stock'] <= 0): ?>
                                    <span style="color: #e74c3c; font-weight: bold;"><i class="fas fa-exclamation-triangle"></i> OUT OF STOCK</span>
                                <?php elseif ($r['stock'] <= 5): ?>
                                    <span style="color: #f39c12; font-weight: bold;"><?php echo $r['stock']; ?> left (Low)</span>
                                <?php else: ?>
                                    <span style="color: #27ae60; font-weight: bold;"><?php echo $r['stock']; ?> items</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px;">
                                <?php if ($r['expiry_date']): ?>
                                    <?php 
                                        $is_expired = strtotime($r['expiry_date']) < strtotime(date('Y-m-d'));
                                        $color = $is_expired ? '#e74c3c' : '#2c3e50';
                                    ?>
                                    <span style="color: <?php echo $color; ?>; <?php echo $is_expired ? 'font-weight:bold;' : ''; ?>">
                                        <?php echo date('M d, Y', strtotime($r['expiry_date'])); ?>
                                        <?php if ($is_expired) echo '<br><small>(EXPIRED)</small>'; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #999;">No Expiry</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px;">
                                <form method="POST" style="display: flex; gap: 8px; align-items: flex-end;">
                                    <input type="hidden" name="reward_id" value="<?php echo $r['id']; ?>">
                                    <div style="display:flex; flex-direction:column;">
                                        <small style="font-size: 0.7rem; color: #888;">Stock</small>
                                        <input type="number" name="stock" value="<?php echo $r['stock']; ?>" style="width: 60px; padding: 5px; border-radius: 4px; border: 1px solid #ddd;">
                                    </div>
                                    <div style="display:flex; flex-direction:column;">
                                        <small style="font-size: 0.7rem; color: #888;">Expiry</small>
                                        <input type="date" name="expiry_date" value="<?php echo $r['expiry_date']; ?>" style="padding: 4px; border-radius: 4px; border: 1px solid #ddd; font-size: 0.8rem;">
                                    </div>
                                    <button type="submit" name="update_stock" style="background: #27ae60; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer;">
                                        Update
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>