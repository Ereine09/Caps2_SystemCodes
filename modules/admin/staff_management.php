9<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

// JWT authentication and role check
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
if ($role !== 'admin') {
    header("Location: dashboard.php?error=access_denied");
    exit();
}

$admin = $username;
$success_message = '';
$error_message = '';
$show_add_form = isset($_GET['add_user']);

if (isset($_GET['success']) && $_GET['success'] === 'user_added') {
    $success_message = 'New user added successfully.';
}

// --- ADD USER LOGIC ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_user_submit'])) {
    $show_add_form = true;
    $new_username = trim($_POST['username'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password_raw = $_POST['password'] ?? '';
    $new_role = strtolower(trim($_POST['role'] ?? 'staff'));

    if (!in_array($new_role, ['admin', 'staff'], true)) {
        $new_role = 'staff';
    }

    if ($new_username === '' || $first_name === '' || $last_name === '' || $email === '' || $password_raw === '') {
        $error_message = 'All user fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } elseif (strlen($password_raw) < 8) {
        $error_message = 'Password must be at least 8 characters long.';
    } else {
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $checkStmt->bind_param("ss", $new_username, $email);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            $error_message = 'Username or email already exists.';
        } else {
            $password = password_hash($password_raw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $new_username, $first_name, $last_name, $email, $password, $new_role);

            //add new user, then log the action in activity_logs
            if ($stmt->execute()) {
                $details = "Added new " . ucfirst($new_role) . " account: $new_username";
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Manage_Staff', ?)");
                $log_stmt->bind_param("is", $user_id, $details);
                $log_stmt->execute();

                header('Location: staff_management.php?success=user_added');
                exit();
            } else {
                $error_message = 'Error adding user: ' . $stmt->error;
            }
        }
    }
}

// --- DELETE STAFF LOGIC ---
if(isset($_GET['del_staff'])){
    $id = (int) $_GET['del_staff'];
    
    // Get the username for the logs
    $res = mysqli_query($conn, "SELECT username FROM users WHERE id='$id'");
    $staff = mysqli_fetch_assoc($res);
    $staff_name = $staff['username'] ?? 'Unknown User';

    mysqli_query($conn, "DELETE FROM users WHERE id='$id'");
    
    // I-log ang action sa activity_logs using the current admin user ID
    $details = "Deleted staff account: $staff_name";
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'manage_staff', ?)");
    $stmt->bind_param("is", $user_id, $details);
    $stmt->execute();
    
    header('location: staff_management.php');
    exit();
}

$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Management - DPS Admin</title>
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
            <h1>Staff Management 🧑‍💼</h1>
            <p>Control who has access to your administrative system.</p>
        </div>

        <?php if ($success_message !== ''): ?>
            <div style="margin-bottom: 20px; padding: 12px 15px; background: #eafaf1; color: #27ae60; border-left: 4px solid #27ae60; border-radius: 8px; font-weight: 600;">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message !== ''): ?>
            <div style="margin-bottom: 20px; padding: 12px 15px; background: #fff5f5; color: #e74c3c; border-left: 4px solid #e74c3c; border-radius: 8px; font-weight: 600;">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($show_add_form): ?>
        <div class="table-box" style="margin-bottom: 25px; border-left: 5px solid #3498db;">
            <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;"><i class="fas fa-user-plus"></i> Add New User</h2>
            </div>
            <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                <input type="text" name="username" placeholder="Username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                <input type="text" name="first_name" placeholder="First Name" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                <input type="text" name="last_name" placeholder="Last Name" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                <input type="email" name="email" placeholder="Email Address" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                <input type="password" name="password" placeholder="Temporary Password" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                <select name="role" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd;">
                    <option value="staff" <?php echo (($_POST['role'] ?? 'staff') === 'staff') ? 'selected' : ''; ?>>Staff</option>
                    <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin</option>
                </select>
                <div style="grid-column: 1 / -1; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="submit" name="add_user_submit" style="background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600;">
                        Save User
                    </button>
                    <a href="staff_management.php" style="background: #bdc3c7; color: black; padding: 10px 20px; border-radius: 5px; text-decoration: none;">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="table-box">
            <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">User Accounts</h2>
                <a href="staff_management.php?add_user=1" class="btn-add" style="background: #3498db; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600;">
                    <i class="fas fa-user-plus"></i> Add New User
                </a>
            </div>

            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 2px solid #eee;">
                        <th style="padding: 15px; text-align: left;">ID</th>
                        <th style="padding: 15px; text-align: left;">Username</th>
                        <th style="padding: 15px; text-align: left;">Role</th>
                        <th style="padding: 15px; text-align: left;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT * FROM users ORDER BY id ASC";
                    $result = mysqli_query($conn, $sql);
                    $display_id = 1;

                    if (mysqli_num_rows($result) > 0) {
                        while ($row = mysqli_fetch_assoc($result)) {
                            $is_current_user = ($row['username'] === $username);
                            echo "<tr style='border-bottom: 1px solid #eee;'>";
                            echo "<td style='padding: 15px;'>" . $display_id . "</td>";
                            echo "<td style='padding: 15px;'><strong>" . htmlspecialchars($row['username']) . "</strong> " . ($is_current_user ? "(You)" : "") . "</td>";
                            $rowRole = strtolower(trim($row['role'] ?? 'staff'));
                            echo "<td style='padding: 15px;'><span style='background: " . ($rowRole == 'admin' ? '#e74c3c' : '#2ecc71') . "; color: white; padding: 5px 10px; border-radius: 15px; font-size: 0.8rem;'>" . htmlspecialchars(ucfirst($rowRole)) . "</span></td>";
                            echo "<td style='padding: 15px;'>";
                            
                            // Prevent deleting your own admin account, to avoid lockout
                            if (!$is_current_user) {
                                echo "<a href='staff_management.php?del_staff=" . $row['id'] . "' style='color: #e74c3c; text-decoration: none;' onclick='return confirm(\"Are you sure you want to remove this user?\")'><i class='fas fa-trash'></i> Remove Access</a>";
                            } else {
                                echo "<span style='color: #bdc3c7;'>No Actions</span>";
                            }
                            
                            echo "</td></tr>";
                            $display_id++;
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>