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

    $_SESSION['message'] = $success_message ?: $error_message;
    $_SESSION['message_type'] = $success_message ? 'success' : 'error';
    header("Location: reviews.php");
    exit();
}

$all_reviews = get_all_reviews();
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);

// Statistics Calculation
$total_reviews = count($all_reviews);
$pending_reviews_count = 0;
$total_rating = 0;

foreach ($all_reviews as $r) {
    if (!$r['is_approved']) $pending_reviews_count++;
    $total_rating += (int)$r['rating'];
}
$avg_rating = $total_reviews > 0 ? number_format($total_rating / $total_reviews, 1) : '0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reviews - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        body {
            overflow-x: hidden;
            background-color: #f8fafc;
            color: #1e293b;
        }

        .sidebar::-webkit-scrollbar { display: none; }
        .sidebar { -ms-overflow-style: none; scrollbar-width: none; }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: #ffffff;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }
        .icon-blue { background: #eff6ff; color: #2563eb; }
        .icon-yellow { background: #fefce8; color: #ca8a04; }
        .icon-green { background: #f0fdf4; color: #16a34a; }

        .stat-info h3 { margin: 0; font-size: 1.5rem; font-weight: 700; color: #0f172a; }
        .stat-info p { margin: 2px 0 0; font-size: 0.85rem; color: #64748b; font-weight: 500; }

        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 10px;
        }
        .tab-btn {
            background: transparent;
            border: none;
            padding: 8px 16px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        .tab-btn.active, .tab-btn:hover {
            background: #3b82f6;
            color: #ffffff;
        }

        /* Review Cards */
        .review-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .review-card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            transform: translateY(-2px);
        }
        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .avatar-circle {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #e0f2fe;
            color: #0284c7;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
        }
        .user-details h4 {
            margin: 0;
            font-size: 0.95rem;
            color: #0f172a;
        }
        .product-tag {
            font-size: 0.8rem;
            color: #475569;
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 6px;
            display: inline-block;
            margin-top: 3px;
        }
        .star-rating {
            color: #f59e0b;
            font-size: 1rem;
            margin-top: 3px;
            letter-spacing: 1px;
        }
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-approved { background: #dcfce7; color: #15803d; }
        .status-pending { background: #fef3c7; color: #b45309; }

        .review-content {
            color: #334155;
            font-size: 0.95rem;
            line-height: 1.6;
            background: #f8fafc;
            padding: 12px 16px;
            border-radius: 8px;
            border-left: 4px solid #cbd5e1;
            margin: 12px 0 15px;
        }

        .action-group {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn-action {
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }
        .btn-approve { background: #10b981; color: white; }
        .btn-approve:hover { background: #059669; }
        .btn-delete { background: #ef4444; color: white; }
        .btn-delete:hover { background: #dc2626; }

        .empty-card {
            background: white;
            padding: 40px;
            text-align: center;
            border-radius: 12px;
            border: 1px dashed #cbd5e1;
            color: #64748b;
        }
        .empty-card i {
            font-size: 2.5rem;
            margin-bottom: 10px;
            color: #94a3b8;
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
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/delivery.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'delivery.php' ? 'active' : ''; ?>"><i class="fas fa-truck"></i> Delivery</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/about.php" <?php echo basename($_SERVER['PHP_SELF']) === 'about.php' ? 'class="active"' : ''; ?>><i class="fas fa-info-circle"></i> About Us</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php">
                <i class="fas fa-comment-dots"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php" class="active"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php"><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="welcome-header" style="margin-bottom: 25px;">
            <h1>Customer Reviews</h1>
            <p>Monitor, approve, or delete customer feedback for products.</p>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert-message alert-<?php echo $_SESSION['message_type']; ?>" style="margin-bottom: 20px;"><?php echo $_SESSION['message']; ?></div>
            <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
        <?php endif; ?>

        <!-- Statistics Section -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="fas fa-comments"></i></div>
                <div class="stat-info">
                    <h3><?php echo $total_reviews; ?></h3>
                    <p>Total Feedback</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-yellow"><i class="fas fa-clock"></i></div>
                <div class="stat-info">
                    <h3><?php echo $pending_reviews_count; ?></h3>
                    <p>Pending Moderation</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green"><i class="fas fa-star"></i></div>
                <div class="stat-info">
                    <h3><?php echo $avg_rating; ?> <small style="font-size: 1rem; color: #94a3b8;">/ 5.0</small></h3>
                    <p>Average Rating</p>
                </div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <button class="tab-btn active" onclick="filterReviews('all')">All Reviews</button>
            <button class="tab-btn" onclick="filterReviews('pending')">Pending (<?php echo $pending_reviews_count; ?>)</button>
            <button class="tab-btn" onclick="filterReviews('approved')">Approved</button>
        </div>

        <!-- Reviews List -->
        <div class="reviews-list">
            <?php if (empty($all_reviews)): ?>
                <div class="empty-card">
                    <i class="fas fa-comment-slash"></i>
                    <h3>No Reviews Found</h3>
                    <p>There are no customer reviews submitted yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($all_reviews as $review): 
                    $initial = strtoupper(substr($review['customer_name'] ?? 'C', 0, 1));
                    $status_class = $review['is_approved'] ? 'approved' : 'pending';
                ?>
                    <div class="review-card item-review" data-status="<?php echo $status_class; ?>">
                        <div class="review-header">
                            <div class="user-profile">
                                <div class="avatar-circle"><?php echo $initial; ?></div>
                                <div class="user-details">
                                    <h4><?php echo htmlspecialchars($review['customer_name']); ?></h4>
                                    <span class="product-tag"><i class="fas fa-box"></i> <?php echo htmlspecialchars($review['product_name']); ?></span>
                                    <div class="star-rating">
                                        <?php echo str_repeat('★', $review['rating']) . str_repeat('☆', 5 - $review['rating']); ?>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <?php if ($review['is_approved']): ?>
                                    <span class="status-badge status-approved"><i class="fas fa-check-circle"></i> Approved</span>
                                <?php else: ?>
                                    <span class="status-badge status-pending"><i class="fas fa-hourglass-half"></i> Pending</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="review-content">
                            "<?php echo nl2br(htmlspecialchars($review['review_text'])); ?>"
                        </div>

                        <div class="action-group">
                            <?php if (!$review['is_approved']): ?>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                    <button type="submit" name="approve_review" class="btn-action btn-approve"><i class="fas fa-check"></i> Approve</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure you want to delete this review?');">
                                <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                <button type="submit" name="delete_review" class="btn-action btn-delete"><i class="fas fa-trash-alt"></i> Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function filterReviews(status) {
            const cards = document.querySelectorAll('.item-review');
            const buttons = document.querySelectorAll('.tab-btn');

            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');

            cards.forEach(card => {
                if (status === 'all') {
                    card.style.display = 'block';
                } else {
                    if (card.getAttribute('data-status') === status) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                }
            });
        }
    </script>
</body>
</html>