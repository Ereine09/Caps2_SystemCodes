<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$token = getJWTFromCookie();
$payload = verifyJWT($token);

if (!$payload) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_role = strtolower(trim($payload['role'] ?? 'staff'));
$username = $payload['username'];
$user_id = (int)$payload['user_id'];

// Fix: Updated table name and added Total Loyalty Points calculation to match Admin logic
$total_customers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM customers"))['total'];
$total_orders = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM tbl_orders"))['total'];
$total_loyalty_points = (float) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(loyalty_points), 0) AS total FROM customers"))['total'] ?? 0);

// Fetch pending orders count for the sidebar badge
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$unread_messages_count = get_unread_count_staff($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css"> 
</head>
<body>
    <?php 
    // Use a consistent sidebar across all staff pages
    include __DIR__ . '/sidebar.php'; 
    ?>

    <div class="main-content">
        <div class="welcome-header">
            <h1>Welcome, <?php echo htmlspecialchars($username); ?>! 👋</h1>
            <p>Role: <span style="color: #4a3e94; font-weight: bold; text-transform: uppercase;"><?php echo htmlspecialchars($user_role); ?></span></p>
        </div>

        <div class="stats-container" style="display: flex; gap: 20px; margin-top: 30px;">
            <div class="card" style="background: white; padding: 25px; border-radius: 12px; flex: 1; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center;">
                <i class="fas fa-user-friends" style="font-size: 2.5rem; color: #4a3e94; margin-bottom: 15px;"></i>
                <h3>Total Customers</h3>
                <p style="font-size: 2rem; font-weight: bold;"><?php echo $total_customers; ?></p>
                <a href="../customers/customers.php" style="text-decoration: none; color: #4a3e94; font-weight: 600;">Manage Customers →</a>
            </div>
            
            <div class="card" style="background: white; padding: 25px; border-radius: 12px; flex: 1; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center;">
                <i class="fas fa-shopping-cart" style="font-size: 2.5rem; color: #27ae60; margin-bottom: 15px;"></i>
                <h3>Total Orders</h3>
                <p style="font-size: 2rem; font-weight: bold;"><?php echo $total_orders; ?></p>
                <a href="orders.php" style="text-decoration: none; color: #4a3e94; font-weight: 600;">View Orders →</a>
            </div>
            <div class="card" style="background: white; padding: 25px; border-radius: 12px; flex: 1; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center;">
                <i class="fas fa-star" style="font-size: 2.5rem; color: #f1c40f; margin-bottom: 15px;"></i>
                <h3>Total Loyalty Points</h3>
                <p style="font-size: 2rem; font-weight: bold;"><?php echo number_format($total_loyalty_points, 2); ?></p>
                <a href="../customers/loyalty_points.php" style="text-decoration: none; color: #4a3e94; font-weight: 600;">Quick Actions →</a>
            </div>
        </div>
    </div>
</body>
</html>