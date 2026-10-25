<?php
/**
 * Staff — Customer profile / loyalty history (read-only).
 */

require_once __DIR__ . '/../shared/staff_auth.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$payload = require_staff();
$user_id = (int) $payload['user_id'];

$customer_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($customer_id <= 0) {
    header('Location: customers.php');
    exit();
}

$current_points = notifications_sync_customer_loyalty_points($conn, $customer_id);

$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $customer_id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    http_response_code(404);
    die('Customer not found.');
}

$gr = $conn->prepare("SELECT COALESCE(SUM(points_earned), 0) AS total FROM loyalty_transactions WHERE customer_id = ?");
$gr->bind_param('i', $customer_id);
$gr->execute();
$gross_points = (float) $gr->get_result()->fetch_assoc()['total'];
$gr->close();

$rd = $conn->prepare("SELECT COALESCE(SUM(points_used), 0) AS total FROM reward_redemptions WHERE customer_id = ?");
$rd->bind_param('i', $customer_id);
$rd->execute();
$total_redeemed_pts = (float) $rd->get_result()->fetch_assoc()['total'];
$rd->close();

if (!function_exists('staff_customer_tier_label')) {
    function staff_customer_tier_label(float $points): array
    {
        if ($points >= 1000) return ['label' => 'Platinum', 'color' => '#55efc4', 'icon' => 'fa-crown'];
        if ($points >= 500)  return ['label' => 'Gold', 'color' => '#ffeaa7', 'icon' => 'fa-medal'];
        if ($points >= 250)  return ['label' => 'Silver', 'color' => '#dfe6e9', 'icon' => 'fa-award'];
        return ['label' => 'Bronze', 'color' => '#fab1a0', 'icon' => 'fa-star'];
    }
}
$tier = staff_customer_tier_label((float) $current_points);

$ts = $conn->prepare("SELECT * FROM loyalty_transactions WHERE customer_id = ? ORDER BY created_at DESC");
$ts->bind_param('i', $customer_id);
$ts->execute();
$transactions = $ts->get_result();

$rs = $conn->prepare("SELECT * FROM reward_redemptions WHERE customer_id = ? ORDER BY redeemed_at DESC");
$rs->bind_param('i', $customer_id);
$rs->execute();
$redemptions = $rs->get_result();

$tl = $conn->prepare(
    "(SELECT 'earned' AS type, product_name AS detail, points_earned AS val, created_at AS ts FROM loyalty_transactions WHERE customer_id = ?)
     UNION ALL
     (SELECT 'redeemed' AS type, reward_name AS detail, points_used AS val, redeemed_at AS ts FROM reward_redemptions WHERE customer_id = ?)
     ORDER BY ts DESC LIMIT 15"
);
$tl->bind_param('ii', $customer_id, $customer_id);
$tl->execute();
$timeline = $tl->get_result();

$pending_orders_count  = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0);
$unread_messages_count = get_unread_count_staff($user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Profile - <?php echo htmlspecialchars($customer['name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        .main-content { margin-left: 260px; padding: 30px; }
        .profile-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        .stat-card { background: var(--surface); padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .timeline-item { padding: 10px; border-left: 3px solid #4a3e94; margin-bottom: 10px; background: #f9f9ff; margin-left: 10px; }
        .tier-badge { padding: 5px 15px; border-radius: 20px; font-weight: bold; font-size: 0.9rem; display: inline-block; }
        @media (max-width: 900px) { .profile-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="welcome-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1>Customer Profile: <?php echo htmlspecialchars($customer['name']); ?></h1>
                <p>Email: <?php echo htmlspecialchars($customer['email']); ?> | Joined: <?php echo date('M d, Y', strtotime($customer['created_at'] ?? 'now')); ?></p>
            </div>
            <a href="customers.php" class="btn-add" style="background: #bdc3c7; color: #2c3e50;"><i class="fas fa-arrow-left"></i> Back to List</a>
        </div>

        <div class="profile-grid">
            <div class="left-col">
                <div class="stat-card" style="text-align: center;">
                    <i class="fas <?php echo $tier['icon']; ?>" style="font-size: 3rem; color: #f1c40f; margin-bottom: 10px;"></i>
                    <h3>Current Status</h3>
                    <div class="tier-badge" style="background: <?php echo $tier['color']; ?>; color: #2c3e50;"><?php echo $tier['label']; ?> Tier</div>
                    <h1 style="font-size: 2.5rem; margin-top: 15px;"><?php echo number_format($current_points, 2); ?></h1>
                    <p style="color: #7f8c8d;">Total Usable Points</p>
                </div>

                <div class="stat-card" style="display: flex; flex-direction: column; gap: 10px;">
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                        <span style="color: #666; font-weight: 600;">Gross Earned:</span>
                        <span style="color: #27ae60; font-weight: bold;">+<?php echo number_format($gross_points, 2); ?> pts</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                        <span style="color: #666; font-weight: 600;">Total Redeemed:</span>
                        <span style="color: #e74c3c; font-weight: bold;">-<?php echo number_format($total_redeemed_pts, 2); ?> pts</span>
                    </div>
                    <div style="font-size: 0.75rem; color: #999; margin-top: 5px; font-style: italic;">* Usable balance accounts for redeemed and expired points.</div>
                </div>

                <div class="stat-card">
                    <h3><i class="fas fa-stream"></i> Activity Timeline</h3>
                    <div style="margin-top: 15px;">
                        <?php while ($item = $timeline->fetch_assoc()): ?>
                            <div class="timeline-item">
                                <small style="color: #95a5a6;"><?php echo date('M d, H:i', strtotime($item['ts'])); ?></small><br>
                                <strong><?php echo $item['type'] === 'earned' ? '<span style="color:#27ae60;">+ Earned</span>' : '<span style="color:#e74c3c;">- Redeemed</span>'; ?></strong>
                                <span style="font-size: 0.9rem;"><?php echo htmlspecialchars($item['detail']); ?></span>
                                <div style="float: right; font-weight: bold;"><?php echo number_format($item['val'], 2); ?></div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>

            <div class="right-col">
                <div class="table-box" style="margin-bottom: 20px;">
                    <h2><i class="fas fa-receipt"></i> Transaction History</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                        <thead><tr style="background: #f8f9fa; text-align: left;"><th style="padding: 10px;">Product</th><th style="padding: 10px;">Weight (kg)</th><th style="padding: 10px;">Points</th><th style="padding: 10px;">Date</th></tr></thead>
                        <tbody>
                            <?php if ($transactions->num_rows > 0): ?>
                                <?php while ($row = $transactions->fetch_assoc()): ?>
                                    <tr style="border-bottom: 1px solid #eee;">
                                        <td style="padding: 10px;"><?php echo htmlspecialchars($row['product_name']); ?></td>
                                        <td style="padding: 10px;"><?php echo number_format($row['quantity_kg'], 2); ?></td>
                                        <td style="padding: 10px; font-weight: bold; color: #27ae60;">+<?php echo number_format($row['points_earned'], 2); ?></td>
                                        <td style="padding: 10px; font-size: 0.85rem;"><?php echo htmlspecialchars($row['created_at']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="padding: 20px; text-align: center;">No transactions found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-box">
                    <h2><i class="fas fa-gift"></i> Redemption History</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                        <thead><tr style="background: #f8f9fa; text-align: left;"><th style="padding: 10px;">Reward</th><th style="padding: 10px;">Points Used</th><th style="padding: 10px;">Date</th></tr></thead>
                        <tbody>
                            <?php if ($redemptions->num_rows > 0): ?>
                                <?php while ($row = $redemptions->fetch_assoc()): ?>
                                    <tr style="border-bottom: 1px solid #eee;">
                                        <td style="padding: 10px;"><?php echo htmlspecialchars($row['reward_name']); ?></td>
                                        <td style="padding: 10px; font-weight: bold; color: #e74c3c;">-<?php echo number_format($row['points_used'], 2); ?></td>
                                        <td style="padding: 10px; font-size: 0.85rem;"><?php echo htmlspecialchars($row['redeemed_at']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="padding: 20px; text-align: center;">No redemptions found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
