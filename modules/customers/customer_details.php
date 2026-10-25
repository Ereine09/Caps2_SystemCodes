<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php'; // Add this line to include the messaging helper
require_once __DIR__ . '/../../app/helpers/profile_photo_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_id = (int)$payload['user_id'];
$role = strtolower(trim($payload['role'] ?? 'staff'));
$customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($customer_id <= 0) {
    die("Invalid Customer ID.");
}
$unread_count = get_unread_count_staff($user_id); // Define unread messages count for staff
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;

// Sync points and fetch customer basic info
$current_points = notifications_sync_customer_loyalty_points($conn, $customer_id);
$customer_query = $conn->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
$customer_query->bind_param("i", $customer_id);
$customer_query->execute();
$customer = $customer_query->get_result()->fetch_assoc();

if (!$customer) {
    die("Customer not found.");
}

// Order history summary (orders placed / completed / total spent) for the Customer Info
// card below - moved here from modules/admin/order_details.php, which was showing this
// same summary but made that page too crowded; this profile page is the right home for it.
$cd_cust = ['orders' => 0, 'done' => 0, 'spent' => 0.0];
$cd_stmt = $conn->prepare(
    "SELECT COUNT(*) AS n,
            COALESCE(SUM(order_status IN ('completed','reviews')), 0) AS done,
            COALESCE(SUM(CASE WHEN order_status <> 'cancelled' THEN total ELSE 0 END), 0) AS spent
     FROM tbl_orders WHERE customer_id = ?"
);
$cd_stmt->bind_param('i', $customer_id);
$cd_stmt->execute();
$cd_row = $cd_stmt->get_result()->fetch_assoc();
$cd_stmt->close();
if ($cd_row) {
    $cd_cust = ['orders' => (int) $cd_row['n'], 'done' => (int) $cd_row['done'], 'spent' => (float) $cd_row['spent']];
}
$cd_initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $i = mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '');
    return mb_strtoupper($i !== '' ? $i : '?');
};

$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$user_id = (int)$payload['user_id'];

// Fetch Gross Points (All-Time Earned) and Total Redeemed
$gross_res = mysqli_query($conn, "SELECT SUM(points_earned) as total FROM loyalty_transactions WHERE customer_id = $customer_id");
$gross_points = (float)(mysqli_fetch_assoc($gross_res)['total'] ?? 0);
$redeemed_res = mysqli_query($conn, "SELECT SUM(points_used) as total FROM reward_redemptions WHERE customer_id = $customer_id");
$total_redeemed_pts = (float)(mysqli_fetch_assoc($redeemed_res)['total'] ?? 0);

// Tier from lifetime points earned, so redeeming never drops a customer's tier (app/helpers/loyalty_tier_helper.php)
require_once __DIR__ . '/../../app/helpers/loyalty_tier_helper.php';
$tier = loyalty_tier_for($gross_points);

// Fetch Transaction History (10 per page, ?tpage=N)
require_once __DIR__ . '/../../app/helpers/ui_helper.php';
require_once __DIR__ . '/../../app/helpers/loyalty_helper.php'; // Order Amount column
$trans_total = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS n FROM loyalty_transactions WHERE customer_id = $customer_id"))['n'] ?? 0);
$trans_pg = ui_paginate($trans_total, (int) ($_GET['tpage'] ?? 1), 10);
$trans_stmt = $conn->prepare("SELECT lt.*, lt_o.subtotal AS order_amount FROM loyalty_transactions lt" . loyalty_tx_order_join() . "WHERE lt.customer_id = ? ORDER BY lt.created_at DESC, lt.id DESC LIMIT ? OFFSET ?");
$trans_stmt->bind_param("iii", $customer_id, $trans_pg['per_page'], $trans_pg['offset']);
$trans_stmt->execute();
$transactions = $trans_stmt->get_result();

// Fetch Redemption History (10 per page, ?rpage=N)
$redeem_total = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS n FROM reward_redemptions WHERE customer_id = $customer_id"))['n'] ?? 0);
$redeem_pg = ui_paginate($redeem_total, (int) ($_GET['rpage'] ?? 1), 10);
$redeem_stmt = $conn->prepare("SELECT * FROM reward_redemptions WHERE customer_id = ? ORDER BY redeemed_at DESC, id DESC LIMIT ? OFFSET ?");
$redeem_stmt->bind_param("iii", $customer_id, $redeem_pg['per_page'], $redeem_pg['offset']);
$redeem_stmt->execute();
$redemptions = $redeem_stmt->get_result();

// Activity Timeline: orders, returns and refunds (points have their own tabs), 10 per page, ?lpage=N
require_once __DIR__ . '/../../app/helpers/customer_activity_helper.php';
$timeline_pg = ui_paginate(customer_activity_count($conn, $customer_id), (int) ($_GET['lpage'] ?? 1), 10);
$timeline = customer_activity_fetch($conn, $customer_id, $timeline_pg['per_page'], $timeline_pg['offset']);

/** "Showing X-Y of N" + page links for one tab; $param is that tab's ?Xpage key. */
function cd_pager(array $pg, string $param, string $tab, string $label): string
{
    if ($pg['pages'] <= 1) {
        return '';
    }
    // target="_self": inside the pop-up, <base target="_top"> would otherwise open the page outside it.
    return '<div class="cd-pager"><span class="ui-pager-count">Showing ' . $pg['from'] . '–' . $pg['to'] . ' of ' . number_format($pg['total']) . '</span>' .
        str_replace('<a href=', '<a target="_self" href=', ui_pagination($pg['page'], $pg['pages'], fn($n) => ui_page_url([$param => $n], $tab), $label)) .
        '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Profile - <?php echo htmlspecialchars($customer['name']); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        body { background: linear-gradient(180deg, #f5f7ff 0%, #eef4ff 100%); }
        .profile-shell {
            max-width: 1180px;
            margin: 20px auto 28px;
            padding: 18px 18px 20px;
            background: linear-gradient(180deg, rgba(255,255,255,0.94), rgba(245,247,255,0.98));
            border: 1px solid rgba(99, 102, 241, 0.14);
            border-radius: 22px;
            box-shadow: 0 18px 40px rgba(79, 70, 229, 0.08), inset 0 1px 0 rgba(255,255,255,0.75);
        }
        .stat-card {
            background: rgba(255,255,255,0.9);
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 10px 24px rgba(148, 163, 184, 0.12);
            border: 1px solid rgba(148, 163, 184, 0.18);
            margin-bottom: 20px;
            overflow-x: auto;
        }
        .timeline-item { padding: 10px; border-left: 3px solid var(--dk-primary, #4a3e94); margin-bottom: 10px; background: linear-gradient(90deg, rgba(79,70,229,.05), rgba(79,70,229,0)); margin-left: 10px; border-radius: 0 10px 10px 0; }
        .tier-badge { padding: 5px 15px; border-radius: 20px; font-weight: bold; font-size: 0.9rem; display: inline-block; }

        /* Loyalty Status tab: Tier card + Points breakdown card side by side */
        .cd-status-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .cd-status-grid > div { min-width: 0; }
        @media (max-width: 700px) { .cd-status-grid { grid-template-columns: 1fr; } }

        /* Tabs: same segmented-pill pattern used elsewhere in the admin panel */
        .cd-tabs {
            display: flex; gap: 6px; margin-bottom: 20px;
            background: linear-gradient(180deg, rgba(241,245,249,0.98), rgba(224,231,255,0.7));
            padding: 6px; border-radius: 14px; border: 1px solid rgba(99, 102, 241, 0.12);
            flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.8);
        }
        body.dark-mode .cd-tabs { background: var(--dm-surface-2, #232a35); }
        .cd-tab-btn {
            flex: 1 1 0; min-width: 140px; text-align: center; white-space: nowrap;
            background: transparent; border: none; cursor: pointer;
            padding: 11px 18px; font-weight: 700; font-size: 0.9rem; color: #5b6473;
            border-radius: 10px; font-family: inherit;
            transition: color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
        }
        .cd-tab-btn:hover { color: #312e81; transform: translateY(-1px); }
        .cd-tab-btn.active { background: linear-gradient(180deg, #ffffff 0%, #eef2ff 100%); color: #312e81; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.12), inset 0 1px 0 rgba(255,255,255,0.9); border: 1px solid rgba(79, 70, 229, 0.12); }
        body.dark-mode .cd-tab-btn { color: var(--dm-text-muted, #9aa5b1); }
        body.dark-mode .cd-tab-btn:hover { color: var(--dm-link, #a5b4fc); }
        body.dark-mode .cd-tab-btn.active { background: var(--dm-surface, #1c222b); color: var(--dm-link, #a5b4fc); box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
        .cd-tab-panel { display: none; }
        .cd-tab-panel.active { display: block; }
        .cd-tab-panel .table-box { overflow-x: auto; }
        .cd-pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--border); }
    </style>
    <?php echo loyalty_tier_styles() . customer_activity_styles(); ?>
</head>
<?php $cd_embed = isset($_GET['embed']); // shown inside the pop-up (assets/js/customer-popup.js): no sidebar ?>
<body<?php echo $cd_embed ? ' class="cd-embed"' : ''; ?>>
    <?php if ($cd_embed): ?>
        <base target="_top">
        <style>
            body.cd-embed { background: var(--surface-2, #f1f5f9); }
            body.cd-embed .main-content { flex: 1 1 auto; min-width: 0; margin: 0 !important; padding: 18px 20px !important; width: 100% !important; box-sizing: border-box; max-width: none !important; min-height: 0 !important; }
            body.cd-embed .welcome-header h1 { font-size: 1.25rem; }
        </style>
        <script>
            try {
                document.body.classList.add('admin-portal');
                if (document.documentElement.getAttribute('data-theme') === 'dark') document.body.classList.add('dark-mode');
            } catch (e) {}
        </script>
    <?php else: ?>
        <?php include __DIR__ . '/sidebar.php'; ?>
    <?php endif; ?>

    <div class="main-content">
        <div class="profile-shell">
        <div class="welcome-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 18px;">
            <div>
                <h1>Customer Profile: <?php echo htmlspecialchars($customer['name']); ?></h1>
                <p>Email: <?php echo htmlspecialchars($customer['email']); ?> | Joined: <?php echo date('M d, Y', strtotime($customer['created_at'] ?? 'now')); ?></p>
            </div>
            <?php if (!$cd_embed): ?>
                <a href="customers.php" class="btn-add" style="background: #bdc3c7; color: #2c3e50;"><i class="fas fa-arrow-left"></i> Back to List</a>
            <?php endif; ?>
        </div>

        <!-- Customer Info: persistent identity header, always visible above the tabs -->
        <div class="stat-card">
                    <h3 style="margin-bottom: 15px;"><i class="fas fa-user"></i> Customer Info</h3>
                    <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 15px;">
                        <?php $cd_photo = profile_photo_get($conn, 'customer', $customer_id); ?>
                        <span style="width: 64px; height: 64px; border-radius: 50%; flex-shrink: 0; overflow: hidden; background: linear-gradient(135deg, #6d5bd0, #4a3e94); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.2rem;<?php echo $cd_photo ? ' cursor: zoom-in;' : ''; ?>"<?php if ($cd_photo): ?> title="Open the full photo" onclick="window.open(<?php echo htmlspecialchars(json_encode(BASE_URL . '/' . $cd_photo), ENT_QUOTES); ?>, '_blank')"<?php endif; ?>><?php echo profile_avatar_inner($cd_photo, (string) $customer['name']); ?></span>
                        <div>
                            <strong style="display: block; font-size: 1.05rem;"><?php echo htmlspecialchars($customer['name']); ?></strong>
                            <span style="color: #7f8c8d; font-size: 0.85rem;"><?php echo !empty($customer['created_at']) ? 'Customer since ' . htmlspecialchars(date('M Y', strtotime($customer['created_at']))) : 'Customer'; ?></span>
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; text-align: center; border-top: 1px solid #eee; border-bottom: 1px solid #eee; padding: 12px 0; margin-bottom: 12px;">
                        <div><strong style="display: block; font-size: 1.05rem;"><?php echo number_format($current_points, 2); ?></strong><span style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: #999;">Loyalty pts</span></div>
                        <div><strong style="display: block; font-size: 1.05rem;"><?php echo $cd_cust['orders']; ?></strong><span style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: #999;">Orders</span></div>
                        <div><strong style="display: block; font-size: 1.05rem;"><?php echo $cd_cust['done']; ?></strong><span style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: #999;">Completed</span></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 0.9rem;"><span style="color: #666;">Email</span><span style="font-weight: 600; text-align: right;"><?php echo htmlspecialchars($customer['email']); ?></span></div>
                    <div style="display: flex; justify-content: space-between; padding: 6px 0; font-size: 0.9rem;"><span style="color: #666;">Phone</span><span style="font-weight: 600;"><?php echo htmlspecialchars($customer['phone'] ?: '-'); ?></span></div>
                    <?php if (!empty($customer['address'])): ?>
                        <div style="padding: 6px 0; font-size: 0.9rem;"><span style="color: #666; display: block; margin-bottom: 4px;">Address on file</span><span style="font-weight: 500;"><?php echo htmlspecialchars($customer['address']); ?></span></div>
                    <?php endif; ?>
                    <div style="display: flex; justify-content: space-between; padding: 10px 0 0; margin-top: 8px; border-top: 1px solid #eee; font-size: 0.9rem;"><span style="color: #666;">Total spent (all orders)</span><span style="font-weight: 700; color: #27ae60;">PHP <?php echo number_format($cd_cust['spent'], 2); ?></span></div>
        </div>

        <div class="cd-tabs" role="tablist">
            <button type="button" class="cd-tab-btn" data-tab="status">Loyalty Status</button>
            <button type="button" class="cd-tab-btn" data-tab="timeline">Activity Timeline</button>
            <button type="button" class="cd-tab-btn" data-tab="transactions">Transaction History</button>
            <button type="button" class="cd-tab-btn" data-tab="redemptions">Redemption History</button>
        </div>

        <div class="cd-tab-panel" id="tab-status">
            <div class="cd-status-grid">
                <div class="stat-card" style="text-align: center; margin-bottom: 0;">
                    <i class="fas <?php echo $tier['icon']; ?>" style="font-size: 3rem; color: <?php echo htmlspecialchars($tier['icon_color'] ?? '#f1c40f'); ?>; margin-bottom: 10px;"></i>
                    <h3>Current Status</h3>
                    <div class="tier-badge" style="background: <?php echo $tier['color']; ?>; color: #2c3e50;">
                        <?php echo $tier['label']; ?> Tier
                    </div>
                    <h1 style="font-size: 2.5rem; margin-top: 15px;"><?php echo number_format($current_points, 2); ?></h1>
                    <p style="color: #7f8c8d;">Total Usable Points</p>
                    <?php echo loyalty_tier_explain_html($gross_points); ?>
                </div>

                <div class="stat-card" style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 0;">
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                        <span style="color: #666; font-weight: 600;">Points Earned:</span>
                        <span style="color: #27ae60; font-weight: bold;">+<?php echo number_format($gross_points, 2); ?> pts</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                        <span style="color: #666; font-weight: 600;">Total Redeemed:</span>
                        <span style="color: #e74c3c; font-weight: bold;">-<?php echo number_format($total_redeemed_pts, 2); ?> pts</span>
                    </div>
                    <div style="font-size: 0.75rem; color: #999; margin-top: 5px; font-style: italic;">
                        * Usable balance accounts for redeemed and expired points.
                    </div>
                </div>
            </div>
        </div>

        <div class="cd-tab-panel" id="tab-timeline">
                <div class="stat-card" style="margin-bottom: 0;">
                    <h3><i class="fas fa-stream"></i> Activity Timeline</h3>
                <p style="margin: 4px 0 0; font-size: 0.8rem; color: #7f8c8d;">Orders, returns and refunds. Points are in Transaction History; rewards in Redemption History.</p>
                <?php echo customer_activity_html($timeline, BASE_URL . '/modules/admin/order_details.php'); ?>
                <?php echo cd_pager($timeline_pg, 'lpage', 'timeline', 'Activity timeline pages'); ?>
                </div>
        </div>

        <div class="cd-tab-panel" id="tab-transactions">
                <div class="table-box" style="margin-bottom: 0;">
                    <h2><i class="fas fa-receipt"></i> Transaction History</h2>
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                    <thead>
                        <tr style="background: #f8f9fa; text-align: left;">
                            <th style="padding: 10px;">Product</th>
                            <th style="padding: 10px;" title="Order total the points came from (1 point per PHP 100)">Order Amount</th>
                            <th style="padding: 10px;">Points</th>
                            <th style="padding: 10px;">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($transactions->num_rows > 0): ?>
                            <?php while($row = $transactions->fetch_assoc()): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 10px;"><?php echo htmlspecialchars($row['product_name']); ?></td>
                                    <td style="padding: 10px;"><?php echo loyalty_tx_amount($row); ?></td>
                                    <td style="padding: 10px; font-weight: bold; color: <?php echo (float) $row['points_earned'] < 0 ? '#e74c3c' : '#27ae60'; ?>;"><?php echo loyalty_tx_points((float) $row['points_earned']); ?></td>
                                    <td style="padding: 10px; font-size: 0.85rem;"><?php echo $row['created_at']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="padding: 20px; text-align: center;">No transactions found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php echo cd_pager($trans_pg, 'tpage', 'transactions', 'Transaction history pages'); ?>
                </div>
        </div>

        <div class="cd-tab-panel" id="tab-redemptions">
                <div class="table-box" style="margin-bottom: 0;">
                    <h2><i class="fas fa-gift"></i> Redemption History</h2>
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                    <thead>
                        <tr style="background: #f8f9fa; text-align: left;">
                            <th style="padding: 10px;">Reward</th>
                            <th style="padding: 10px;">Points Used</th>
                            <th style="padding: 10px;">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($redemptions->num_rows > 0): ?>
                            <?php while($row = $redemptions->fetch_assoc()): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 10px;"><?php echo htmlspecialchars($row['reward_name']); ?></td>
                                    <td style="padding: 10px; font-weight: bold; color: #e74c3c;">-<?php echo number_format($row['points_used'], 2); ?></td>
                                    <td style="padding: 10px; font-size: 0.85rem;"><?php echo $row['redeemed_at']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="padding: 20px; text-align: center;">No redemptions found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php echo cd_pager($redeem_pg, 'rpage', 'redemptions', 'Redemption history pages'); ?>
                </div>
        </div>
        </div>
    </div>

    <script>
        (function () {
            var tabButtons = document.querySelectorAll('.cd-tab-btn');
            var tabPanels = document.querySelectorAll('.cd-tab-panel');
            var validTabs = ['status', 'timeline', 'transactions', 'redemptions'];
            var STORAGE_KEY = 'customerDetailsActiveTab';

            function activateTab(tab, updateHistory) {
                if (validTabs.indexOf(tab) === -1) tab = 'status';
                tabButtons.forEach(function (btn) {
                    btn.classList.toggle('active', btn.dataset.tab === tab);
                });
                tabPanels.forEach(function (panel) {
                    panel.classList.toggle('active', panel.id === 'tab-' + tab);
                });
                try { localStorage.setItem(STORAGE_KEY, tab); } catch (e) {}
                if (updateHistory) {
                    history.replaceState(null, '', '#' + tab);
                }
            }

            tabButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    activateTab(btn.dataset.tab, true);
                });
            });

            // Page links end in their tab's #hash; a ?Xpage= link without one still belongs to that tab.
            var initialTab = (window.location.hash || '').replace('#', '');
            if (!initialTab && /[?&]tpage=/.test(window.location.search)) initialTab = 'transactions';
            if (!initialTab && /[?&]rpage=/.test(window.location.search)) initialTab = 'redemptions';
            if (!initialTab && /[?&]lpage=/.test(window.location.search)) initialTab = 'timeline';
            if (!initialTab) {
                try { initialTab = localStorage.getItem(STORAGE_KEY) || ''; } catch (e) { initialTab = ''; }
            }
            activateTab(initialTab || 'status', false);
        })();
    </script>
</body>
</html>
