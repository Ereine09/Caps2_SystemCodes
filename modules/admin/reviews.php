<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
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

if ($role !== 'admin' && $role !== 'staff') {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $log_action = "Manage Reviews";
    $log_details = '';

    if (isset($_POST['approve_review'])) {
        $review_id = (int)$_POST['review_id'];
        if (approve_review($review_id)) {
            $success_message = "Review approved and is now public.";
            $log_details = "User approved review ID: $review_id.";
        } else {
            $error_message = "Failed to approve review.";
        }
    } elseif (isset($_POST['delete_review'])) {
        $review_id = (int)$_POST['review_id'];
        if (delete_review($review_id)) {
            $success_message = "Review has been deleted.";
            $log_details = "User deleted review ID: $review_id.";
        } else {
            $error_message = "Failed to delete review.";
        }
    }

    if (!empty($log_details)) {
        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
        $log_stmt->bind_param('iss', $user_id, $log_action, $log_details);
        $log_stmt->execute();
        $log_stmt->close();
    }

    // Redirect to avoid form resubmission
    $_SESSION['message'] = $success_message ?: $error_message;
    $_SESSION['message_type'] = $success_message ? 'success' : 'error';
    header("Location: reviews.php");
    exit();
}

$all_reviews = get_all_reviews();
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Reviews - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        .review-card { background: white; border-radius: 8px; padding: 15px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .review-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 10px; }
        .star-rating { color: #f59e0b; font-size: 1.1rem; }
        .status-badge { padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: bold; color: white; }
        .status-approved { background: #10b981; }
        .status-pending { background: #f59e0b; }

                /* 1. Prevent double scrollbar on body */
        body {
            overflow-x: hidden;
        }

        /* 2. Hide scrollbar for Chrome, Safari and Opera */
        .sidebar::-webkit-scrollbar {
            display: none;
        }

        /* 3. Hide scrollbar for IE, Edge and Firefox */
        .sidebar {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
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
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php"><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php"><i class="fas fa-history"></i> Activity Logs</a></li>
            <?php endif; ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php"><i class="fas fa-boxes"></i> Manage Rewards</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/products.php"><i class="fas fa-store"></i> Products</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/orders.php" <?php echo basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-shopping-cart"></i> Orders 
                <?php if ($pending_orders_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php"><i class="fas fa-comment-dots"></i> Messages <?php if ($unread_count > 0) echo "<span class='notif-badge'>$unread_count</span>"; ?></a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php" class="active"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance</a></li>            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php"><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="welcome-header">
            <h1>Manage Customer Reviews</h1>
            <p>Approve or delete feedback submitted by customers.</p>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert-message alert-<?php echo $_SESSION['message_type']; ?>" style="margin-bottom: 20px;"><?php echo $_SESSION['message']; ?></div>
            <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
        <?php endif; ?>

        <div class="reviews-list">
            <?php if (empty($all_reviews)): ?>
                <p>No reviews have been submitted yet.</p>
            <?php else: ?>
                <?php foreach ($all_reviews as $review): ?>
                    <div class="review-card">
                        <div class="review-header">
                            <div>
                                <strong><?php echo htmlspecialchars($review['customer_name']); ?></strong> on 
                                <em><?php echo htmlspecialchars($review['product_name']); ?></em>
                                <div class="star-rating"><?php echo str_repeat('★', $review['rating']) . str_repeat('☆', 5 - $review['rating']); ?></div>
                            </div>
                            <div>
                                <?php if ($review['is_approved']): ?>
                                    <span class="status-badge status-approved">Approved</span>
                                <?php else: ?>
                                    <span class="status-badge status-pending">Pending</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p style="color: #333;"><?php echo nl2br(htmlspecialchars($review['review_text'])); ?></p>
                        <div style="margin-top: 10px; display: flex; gap: 10px;">
                            <?php if (!$review['is_approved']): ?>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                    <button type="submit" name="approve_review" class="button" style="background: #10b981;">Approve</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure you want to delete this review?');">
                                <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                <button type="submit" name="delete_review" class="button" style="background: #ef4444;">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>