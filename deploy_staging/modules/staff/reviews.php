<?php
/**
 * Staff — Customer review moderation.
 * Uses the shared review helpers in customer/includes/functions.php.
 */

require_once __DIR__ . '/../shared/staff_auth.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/review_ui_helper.php';

$payload  = require_staff();
$user_id  = (int) $payload['user_id'];

// Read-only page: reviews are the customer's own feedback, so staff/admin
// can view them but cannot approve, decline or delete them.
$all_reviews           = get_all_reviews();
$pending_orders_count  = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0);
$unread_messages_count = get_unread_count_staff($user_id);

$total_reviews = count($all_reviews);
$total_rating = 0;
foreach ($all_reviews as $r) {
    $total_rating += (int) $r['rating'];
}
$avg_rating = $total_reviews > 0 ? number_format($total_rating / $total_reviews, 1) : '0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Reviews - Staff</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/product_ratings.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/product_ratings.css') ?: time(); ?>">
    <script src="<?php echo BASE_URL; ?>/assets/js/product-ratings.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/product-ratings.js') ?: time(); ?>"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/reviews-admin.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/reviews-admin.js') ?: time(); ?>"></script>
    <style>
        body { background-color: var(--surface-2); color: var(--text); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: var(--surface); padding: 20px; border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
        .icon-blue { background: var(--info-soft); color: var(--info); } .icon-yellow { background: #fefce8; color: var(--warning); } .icon-green { background: var(--success-soft); color: var(--success); }
        .stat-info h3 { margin: 0; font-size: 1.5rem; font-weight: 700; } .stat-info p { margin: 2px 0 0; font-size: 0.85rem; color: var(--muted); }
        .filter-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 10px; }
        .tab-btn { background: transparent; border: none; padding: 8px 16px; font-size: 0.9rem; font-weight: 600; color: var(--muted); cursor: pointer; border-radius: 8px; }
        .tab-btn.active, .tab-btn:hover { background: #3b82f6; color: #fff; }
        .review-card { background: var(--surface); border-radius: 12px; padding: 20px; margin-bottom: 16px; border: 1px solid var(--border); }
        .review-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .avatar-circle { width: 42px; height: 42px; border-radius: 50%; background: var(--info-soft); color: var(--info); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; }
        .user-details h4 { margin: 0; font-size: 0.95rem; }
        .product-tag { font-size: 0.8rem; color: var(--text-2); background: var(--surface-2); padding: 2px 8px; border-radius: 6px; display: inline-block; margin-top: 3px; }
        .star-rating { color: #f59e0b; font-size: 1rem; margin-top: 3px; letter-spacing: 1px; }
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .status-approved { background: var(--success-soft); color: var(--success); } .status-pending { background: var(--warning-soft); color: var(--warning); }
        .review-content { color: var(--text-2); font-size: 0.95rem; line-height: 1.6; background: var(--surface-2); padding: 12px 16px; border-radius: 8px; border-left: 4px solid var(--border); margin: 12px 0 15px; }
        .action-group { display: flex; gap: 10px; justify-content: flex-end; }
        .btn-action { border: none; padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .btn-approve { background: #10b981; color: #fff; } .btn-delete { background: #ef4444; color: #fff; }
        .empty-card { background: var(--surface); padding: 40px; text-align: center; border-radius: 12px; border: 1px dashed var(--border); color: var(--muted); }
        .empty-card i { font-size: 2.5rem; margin-bottom: 10px; color: var(--muted); }
        .alert-message { padding: 12px 16px; border-radius: 8px; }
        .alert-success { background: var(--success-soft); color: var(--success); } .alert-error { background: var(--danger-soft); color: var(--danger); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="welcome-header" style="margin-bottom: 25px;">
            <h1>Customer Reviews</h1>
            <p>Customer feedback on products (view only).</p>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert-message alert-<?php echo htmlspecialchars($_SESSION['message_type']); ?>" style="margin-bottom: 20px;"><?php echo htmlspecialchars($_SESSION['message']); ?></div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <?php echo render_reviews_admin($all_reviews); ?>
        <script>
            var reviewsRoot = document.getElementById('reviewsRatings');
            PRRatings.init(reviewsRoot); // photo lightbox
            RVAdmin.init(reviewsRoot);   // search / filters / sort / paging
        </script>
        </div>
</body>
</html>
