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
        description TEXT,
        points DECIMAL(10,2) NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        validity_days INT(11) DEFAULT 7
    )"
);

// Add expiry_date column if it doesn't exist
$check_col = mysqli_query($conn, "SHOW COLUMNS FROM rewards LIKE 'expiry_date'");
if (mysqli_num_rows($check_col) == 0) {
    mysqli_query($conn, "ALTER TABLE rewards ADD COLUMN expiry_date DATE DEFAULT NULL AFTER stock");
}
// --- FIX: The previous version of this file was missing the validity_days column ---
$check_validity_col = mysqli_query($conn, "SHOW COLUMNS FROM rewards LIKE 'validity_days'");
if (mysqli_num_rows($check_validity_col) == 0) {
    mysqli_query($conn, "ALTER TABLE rewards ADD COLUMN validity_days INT(11) DEFAULT 7 AFTER stock");
}


$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_reward'])) {
        $code = trim($_POST['reward_code']);
        $name = trim($_POST['name']); // This is the reward name, e.g., "Gift Card of ₱50"
        $points = (float)$_POST['points'];
        $stock = (int)$_POST['stock'];
        $validity_days = (int)$_POST['validity_days'];
        $desc = trim($_POST['description']);

        try {
            $stmt = $conn->prepare("INSERT INTO rewards (reward_code, name, points, stock, validity_days, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdiis", $code, $name, $points, $stock, $validity_days, $desc);
            if ($stmt->execute()) {
                $success_message = "New reward item added successfully.";
                $details = "Added reward: $name ($code)";
                $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Manage Rewards', ?)");
                if ($log) {
                    $log->bind_param("is", $user_id, $details);
                $log->execute();
                }
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
    } elseif (isset($_POST['update_reward'])) {
        $id = (int)$_POST['reward_id'];
        $name = trim($_POST['name']);
        $points = (float)$_POST['points'];
        $stock = (int)$_POST['stock'];
        $validity_days = (int)$_POST['validity_days'];
        $desc = trim($_POST['description']);

        $stmt = $conn->prepare("UPDATE rewards SET name = ?, points = ?, stock = ?, validity_days = ?, description = ? WHERE id = ?");
        $stmt->bind_param("sdiisi", $name, $points, $stock, $validity_days, $desc, $id);
        if ($stmt->execute()) {
            $success_message = "Reward '$name' updated successfully.";
            $details = "Updated reward: $name (ID: $id)";
            $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Manage Rewards', ?)");
            if ($log) {
                $log->bind_param("is", $user_id, $details);
            $log->execute();
            }
        } else {
            $error_message = "Error updating reward: " . $conn->error;
        }
    } elseif (isset($_POST['delete_reward'])) {
        $id = (int)$_POST['reward_id'];

        // --- FIX: Check for existing redemptions before attempting to delete ---
        // First, get the reward_code from the reward ID
        $reward_code_stmt = $conn->prepare("SELECT reward_code, name FROM rewards WHERE id = ?");
        $reward_code_stmt->bind_param("i", $id);
        $reward_code_stmt->execute();
        $reward_data = $reward_code_stmt->get_result()->fetch_assoc();
        $reward_code_stmt->close();

        if ($reward_data) {
            $reward_code = $reward_data['reward_code'];
            $reward_name = $reward_data['name'];

            // Now, check if this reward_code exists in the redemptions table
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM reward_redemptions WHERE reward_code = ?");
            $check_stmt->bind_param("s", $reward_code);
            $check_stmt->execute();
            $redemption_count = (int)$check_stmt->get_result()->fetch_assoc()['count'];
            $check_stmt->close();

            if ($redemption_count > 0) {
                $error_message = "Cannot delete '$reward_name'. This reward has been redeemed $redemption_count time(s) and is part of your history.";
            } else {
                // No redemptions found, so it's safe to delete
                $stmt = $conn->prepare("DELETE FROM rewards WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $success_message = "Reward '$reward_name' has been deleted successfully.";
                    $details = "Deleted reward: $reward_name";
                    $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Manage Rewards', ?)");
                    if ($log) { $log->bind_param("is", $user_id, $details); $log->execute(); }
                }
            }
        }
    }
}

// Handle fetching a reward for editing
$edit_reward = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $conn->prepare("SELECT * FROM rewards WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $edit_reward = $result->fetch_assoc();
    }
    $stmt->close();
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
    <style>
        /* FORCE FIX FOR SIDEBAR OVERLAPPING */
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

        /* Ginagawang scrollable ang mismong links lang */
        .sidebar .nav-links {
            flex: 1 1 auto !important;
            overflow-y: auto !important;
            list-style: none !important;
            padding: 0 10px 20px 10px !important;
            margin: 0 !important;
            max-height: calc(100vh - 180px) !important; /* Piliting mag-scroll bago tumapat sa logout */
        }

        /* Custom Scrollbar */
        .sidebar .nav-links::-webkit-scrollbar {
            width: 5px;
        }
        .sidebar .nav-links::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        /* Naka-pin sa pinakababa ang Logout */
        .sidebar-footer {
            flex-shrink: 0 !important;
            padding: 16px 20px !important;
            border-top: 1px solid rgba(255, 255, 255, 0.1) !important;
            background-color: #1e293b !important;
            margin-top: auto !important;
        }

        .sidebar-footer .logout-link {
            position: static !important; /* Tinatanggal ang absolute positioning */
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            color: #ef4444 !important;
            font-weight: 600 !important;
            text-decoration: none !important;
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
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance</a></li>            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
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
            <h2><i class="fas <?php echo $edit_reward ? 'fa-edit' : 'fa-plus-circle'; ?>"></i> <?php echo $edit_reward ? 'Edit Reward' : 'Add New Reward'; ?></h2>
            <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                <input type="hidden" name="reward_id" value="<?php echo $edit_reward['id'] ?? ''; ?>">
                <input type="text" name="reward_code" placeholder="Code (e.g. DPS-RW-01)" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;" value="<?php echo htmlspecialchars($edit_reward['reward_code'] ?? ''); ?>" <?php echo $edit_reward ? 'readonly' : ''; ?>>
                <div>
                    <input type="text" name="name" placeholder="Reward Name (e.g., Gift Card of ₱50)" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;" value="<?php echo htmlspecialchars($edit_reward['name'] ?? ''); ?>">
                    <small style="color: #666; font-size: 0.75rem; margin-top: 5px; display: block;">For gift cards, include the monetary value in the name (e.g., "Gift Card of ₱100").</small>
                </div>
                <input type="number" step="0.01" name="points" placeholder="Points Required" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;" value="<?php echo htmlspecialchars($edit_reward['points'] ?? ''); ?>">
                <input type="number" name="stock" placeholder="Initial Stock" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;" value="<?php echo htmlspecialchars($edit_reward['stock'] ?? ''); ?>">
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-size: 0.8rem; color: #666;">Voucher Validity (Days)</label>
                    <input type="number" name="validity_days" value="<?php echo htmlspecialchars($edit_reward['validity_days'] ?? '30'); ?>" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                </div>
                <textarea name="description" placeholder="Short description..." style="grid-column: 1 / -1; padding: 10px; border-radius: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($edit_reward['description'] ?? ''); ?></textarea>
                <div style="grid-column: 1 / -1; display: flex; gap: 10px;">
                    <button type="submit" name="<?php echo $edit_reward ? 'update_reward' : 'add_reward'; ?>" style="background: #3498db; color: white; border: none; padding: 12px; border-radius: 5px; cursor: pointer; font-weight: bold;"><?php echo $edit_reward ? 'Update Reward' : 'Save Reward Item'; ?></button>
                    <?php if ($edit_reward): ?><a href="manage_rewards.php" style="background: #bdc3c7; color: black; padding: 12px; border-radius: 5px; text-decoration: none; font-weight: bold;">Cancel Edit</a><?php endif; ?>
                </div>
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
                        <th style="padding: 12px;">Validity</th>
                        <th style="padding: 12px;">Actions</th>
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
                            <td style="padding: 12px;"><?php echo htmlspecialchars($r['validity_days']); ?> days</td>
                            <td style="padding: 12px; display: flex; gap: 10px;">
                                <a href="manage_rewards.php?edit_id=<?php echo $r['id']; ?>" style="background: #f39c12; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 0.8rem;">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this reward? This cannot be undone.');">
                                    <input type="hidden" name="reward_id" value="<?php echo $r['id']; ?>">
                                    <button type="submit" name="delete_reward" style="background: #e74c3c; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                                        <i class="fas fa-trash"></i> Trash
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