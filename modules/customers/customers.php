<?php
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

$search = trim($_GET['search'] ?? '');
$gender_filter = trim($_GET['gender'] ?? '');
$age_group = trim($_GET['age_group'] ?? '');

if (isset($_GET['del'])) {
    $id = (int) $_GET['del'];

    $res = mysqli_query($conn, "SELECT name FROM customers WHERE id='$id'");
    if ($customer = mysqli_fetch_assoc($res)) {
        $customer_name = $customer['name'];
        mysqli_query($conn, "DELETE FROM customers WHERE id='$id'");

        $details = "Deleted customer: $customer_name";
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'DELETE', ?)");
        $stmt->bind_param("is", $user_id, $details);
        $stmt->execute();
    }

    header('Location: customers.php#customers-section');
    exit();
}

$update = false;
$id = 0;
$name = '';
$email = '';
$phone = '';
$gender = '';
$age = '';

if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $update = true;
    $result_edit = mysqli_query($conn, "SELECT * FROM customers WHERE id='$id'");
    if ($row_edit = mysqli_fetch_assoc($result_edit)) {
        $name = $row_edit['name'];
        $email = $row_edit['email'];
        $phone = $row_edit['phone'];
        $gender = $row_edit['gender'] ?? '';
        $age = $row_edit['age'] ?? '';
    }
}

if (isset($_POST['update_customer'])) {
    $id = (int) $_POST['id'];
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $gender = mysqli_real_escape_string($conn, $_POST['gender'] ?? '');
    $age = isset($_POST['age']) && $_POST['age'] !== '' ? (int) $_POST['age'] : 'NULL';

    $sql = "UPDATE customers SET name='$name', email='$email', phone='$phone', gender='$gender', age=$age WHERE id='$id'";
    mysqli_query($conn, $sql);

    $details = "Updated customer: $name";
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'UPDATE', ?)");
    $stmt->bind_param("is", $user_id, $details);
    $stmt->execute();

    header('Location: customers.php#customers-section');
    exit();
}

$total_customers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM customers"))['total'];
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customers - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        .status-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .status-active { background-color: #27ae60; box-shadow: 0 0 5px #27ae60; }
        .status-inactive { background-color: #bdc3c7; }
        .progress-container { width: 100%; background-color: #eee; border-radius: 10px; margin-top: 5px; height: 8px; overflow: hidden; }
        .progress-bar { height: 100%; background: linear-gradient(90deg, #f1c40f, #f39c12); border-radius: 10px; transition: width 0.3s; }
        .quick-add-btn { background: #f1c40f; color: #2c3e50; padding: 5px 8px; border-radius: 4px; text-decoration: none; font-size: 0.8rem; font-weight: bold; margin-right: 5px; }
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
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
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
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h1>Customer Management 👥</h1>
                    <p>Total Customers: <span style="color: #4a3e94; font-weight: bold;"><?php echo $total_customers; ?></span></p>
                </div>
                <?php if (isset($role) && $role === 'admin'): ?>
                    <a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php#loyal-customers-section" style="background: #4a3e94; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600;"><i class="fas fa-chart-bar"></i> View Customer Reports</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($update == true): ?>
        <div class="table-box" style="margin-bottom: 25px; border-left: 5px solid #3498db; background: #fff; padding: 20px; border-radius: 12px;">
            <h3><i class="fas fa-edit"></i> Edit Customer Info</h3>
            <form action="customers.php" method="POST" style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="text" name="name" value="<?php echo htmlspecialchars($name); ?>" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd; flex: 1; min-width: 180px;">
                <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd; flex: 1; min-width: 180px;">
                <input type="text" name="phone" value="<?php echo htmlspecialchars($phone); ?>" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd; flex: 1; min-width: 160px;">
                <select name="gender" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd; flex: 1; min-width: 150px;">
                    <option value="">Select Gender</option>
                    <option value="Male" <?php echo $gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?php echo $gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                </select>
                <input type="number" name="age" value="<?php echo htmlspecialchars((string) $age); ?>" min="0" max="120" required placeholder="Age" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd; flex: 1; min-width: 120px;">
                <button type="submit" name="update_customer" style="background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">Update</button>
                <a href="customers.php#customers-section" style="background: #bdc3c7; color: black; padding: 10px 20px; border-radius: 5px; text-decoration: none;">Cancel</a>
            </form>
        </div>
        <?php endif; ?>

        <div id="customers-section" class="table-box" style="background: white; padding: 25px; border-radius: 15px; box-shadow: 0 10px 20px rgba(0,0,0,0.05);">
            <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h2 style="margin: 0;">Customer List</h2>
                    <p style="margin: 6px 0 0; color: #666;">Search by customer name and filter by gender or age group.</p>
                </div>
                <a href="add_customer.php" class="btn-add" style="background: #27ae60; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600;">
                    <i class="fas fa-plus"></i> New Customer
                </a>
            </div>

            <form method="GET" action="customers.php#customers-section" style="display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap;">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search customer name..." style="padding: 10px; border-radius: 8px; border: 1px solid #ddd; flex: 1; min-width: 220px;">
                <select name="gender" style="padding: 10px; border-radius: 8px; border: 1px solid #ddd; min-width: 150px;">
                    <option value="">All Genders</option>
                    <option value="Male" <?php echo $gender_filter === 'Male' ? 'selected' : ''; ?>>Men</option>
                    <option value="Female" <?php echo $gender_filter === 'Female' ? 'selected' : ''; ?>>Women</option>
                </select>
                <select name="age_group" style="padding: 10px; border-radius: 8px; border: 1px solid #ddd; min-width: 170px;">
                    <option value="">All Ages</option>
                    <option value="18below" <?php echo $age_group === '18below' ? 'selected' : ''; ?>>18 below</option>
                    <option value="19to35" <?php echo $age_group === '19to35' ? 'selected' : ''; ?>>19 - 35</option>
                    <option value="36to59" <?php echo $age_group === '36to59' ? 'selected' : ''; ?>>36 - 59</option>
                    <option value="60above" <?php echo $age_group === '60above' ? 'selected' : ''; ?>>60 above</option>
                </select>
                <button type="submit" style="background: #4a3e94; color: white; border: none; padding: 10px 18px; border-radius: 8px; cursor: pointer;">Search</button>
                <a href="customers.php#customers-section" style="background: #ecf0f1; color: #333; padding: 10px 18px; border-radius: 8px; text-decoration: none;">Reset</a>
            </form>

            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa; border-bottom: 2px solid #eee;">
                        <th style="padding: 10px 8px; text-align: left; font-size: 0.85rem;">ID</th>
                        <th style="padding: 10px 8px; text-align: left; font-size: 0.85rem;">Status</th>
                        <th style="padding: 10px 8px; text-align: left; font-size: 0.85rem;">Name</th>
                        <th style="padding: 10px 8px; text-align: left; font-size: 0.85rem;">Loyalty Progress</th>
                        <th style="padding: 10px 8px; text-align: left; font-size: 0.85rem;">Contact</th>
                        <th style="padding: 10px 8px; text-align: left; font-size: 0.85rem;">Bio</th>
                        <th style="padding: 10px 8px; text-align: left; font-size: 0.85rem;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT c.*, MAX(lt.created_at) as last_activity 
                            FROM customers c 
                            LEFT JOIN loyalty_transactions lt ON c.id = lt.customer_id 
                            WHERE 1=1";
                    $params = [];
                    $types = "";

                    if ($search !== '') {
                        $sql .= " AND name LIKE ?";
                        $params[] = "%$search%";
                        $types .= "s";
                    }

                    if ($gender_filter !== '') {
                        $sql .= " AND gender = ?";
                        $params[] = $gender_filter;
                        $types .= "s";
                    }

                    switch ($age_group) {
                        case '18below':
                            $sql .= " AND age IS NOT NULL AND age <= 18";
                            break;
                        case '19to35':
                            $sql .= " AND age BETWEEN 19 AND 35"; // No need for params here as values are fixed
                            break;
                        case '36to59':
                            $sql .= " AND age BETWEEN 36 AND 59"; // No need for params here as values are fixed
                            break;
                        case '60above':
                            $sql .= " AND age IS NOT NULL AND age >= 60";
                            break;
                    }
                    $sql .= " GROUP BY c.id ORDER BY c.id ASC";

                    $stmt = $conn->prepare($sql);
                    if (!empty($params)) {
                        $stmt->bind_param($types, ...$params);
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $display_id = 1;


                    if (mysqli_num_rows($result) > 0) {
                        $thresholds = notifications_reward_thresholds();
                        while ($row = mysqli_fetch_assoc($result)) {
                            $display_gender = !empty($row['gender']) ? htmlspecialchars($row['gender']) : 'Not set';
                            $display_age = isset($row['age']) && $row['age'] !== null ? (int) $row['age'] : 'N/A';
                            $pts = (float)$row['loyalty_points'];
                            
                            // Status Logic: Active if transaction within 30 days
                            $isActive = (isset($row['last_activity']) && strtotime($row['last_activity']) > strtotime('-30 days'));
                            $statusClass = $isActive ? 'status-active' : 'status-inactive';

                            // Progress Logic
                            $next_target = $thresholds[0];
                            foreach($thresholds as $t) {
                                if ($pts < $t) { $next_target = $t; break; }
                                $next_target = $t;
                            }
                            $percent = min(100, ($pts / $next_target) * 100);

                            echo "<tr style='border-bottom: 1px solid #eee;'>";
                            echo "<td style='padding: 10px 8px; font-size: 0.85rem;'>" . htmlspecialchars($display_id) . "</td>";
                            echo "<td style='padding: 10px 8px;'><span class='status-dot $statusClass' title='" . ($isActive ? 'Active' : 'Inactive') . "'></span></td>";
                            echo "<td style='padding: 10px 8px; font-size: 0.85rem;'><a href='customer_details.php?id={$row['id']}' style='text-decoration:none; color:#4a3e94; font-weight:bold;'><i class='fas fa-user-circle'></i> " . htmlspecialchars($row['name']) . "</a></td>";
                            echo "<td style='padding: 10px 8px; font-size: 0.75rem; width: 180px;'>
                                    <strong>" . number_format($pts, 2) . " / $next_target</strong>
                                    <div class='progress-container'><div class='progress-bar' style='width: $percent%'></div></div>
                                  </td>";
                            echo "<td style='padding: 10px 8px; font-size: 0.85rem;'>" . htmlspecialchars($row['email']) . "<br><small>" . htmlspecialchars($row['phone']) . "</small></td>";
                            echo "<td style='padding: 10px 8px; font-size: 0.85rem;'>" . htmlspecialchars($display_gender) . ", " . htmlspecialchars($display_age) . "</td>";
                            echo "<td style='padding: 10px 8px; font-size: 0.85rem;'>
                                    <a href='loyalty_points.php?customer_id={$row['id']}' class='quick-add-btn' title='Add Points'><i class='fas fa-plus'></i> Pts</a>
                                    <a href='customer_details.php?id={$row['id']}' style='color: #27ae60; text-decoration: none; margin-right: 10px;' title='View'><i class='fas fa-eye'></i></a>
                                    <a href='customers.php?edit={$row['id']}#customers-section' style='color: #3498db; text-decoration: none; margin-right: 10px;' title='Edit'><i class='fas fa-edit'></i></a>
                                    <a href='customers.php?del={$row['id']}' style='color: #e74c3c; text-decoration: none;' title='Delete' onclick='return confirm(\"Are you sure you want to delete this customer?\")'><i class='fas fa-trash'></i></a>
                                  </td>";
                            echo "</tr>";
                            $display_id++;
                        }
                    } else {
                        echo "<tr><td colspan='7' style='text-align:center; padding:20px;'>No customers matched your search/filter.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
