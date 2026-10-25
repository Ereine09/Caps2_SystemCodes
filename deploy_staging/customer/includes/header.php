<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/background_tasks.php'; // Include the background task runner

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$customer = current_customer();
$cart_count = cart_item_count();
$unread_msg_count = $customer ? get_unread_count_customer((int)$customer['id']) : 0;

// Customer notification unread count (bell) from the shared notifications table.
$notif_count = 0;
$rider_msg_unread = 0;
if ($customer) {
    $cid = (int)$customer['id'];
    notifications_ensure_schema($conn);
    $n_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE customer_id = ? AND is_read = 0");
    $n_stmt->bind_param('i', $cid);
    $n_stmt->execute();
    $notif_count = (int)($n_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $n_stmt->close();

    $rm_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tbl_messages WHERE customer_id = ? AND sender_type = 'rider' AND is_read = 0");
    $rm_stmt->bind_param('i', $cid);
    $rm_stmt->execute();
    $rider_msg_unread = (int)($rm_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $rm_stmt->close();
}

// Get current page for active state (allows manual override if set before including header)
$current_page = $current_page ?? basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($page_title ?? 'Darius Poultry Supply Customer Portal'); ?></title>
    <script>
        // Apply the saved Customer theme before anything paints, so the page
        // never flashes light-then-dark. This is a tiny, synchronous inline
        // script (NOT deferred, NOT external) placed before the stylesheets
        // on purpose — it must run while the parser is still in <head>, which
        // is the earliest a theme can possibly be applied. The actual toggle
        // button / click handling lives in the one shared assets/js/nav-toggle.js
        // (loaded later, in the footer) — this snippet only ever reads.
        (function () {
            try {
                if (localStorage.getItem('customerTheme') === 'dark') {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) { /* localStorage unavailable — Light Mode stays the default */ }
        })();
    </script>
    <script>
        // Same bridge as the Admin sidebar (modules/customers/sidebar.php) -
        // lets the static, cache-busted assets/js/nav-toggle.js reach the
        // WebSocket server and build correct absolute API URLs without
        // hardcoding a host/path that would break in another deployment.
        window.__DPS_WS_PORT = <?php echo (int) WS_PORT; ?>;
        window.__DPS_BASE_URL = <?php echo json_encode(BASE_URL); ?>;
    </script>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/customer/assets/css/customer_style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/customer_style.css') ?: time(); ?>" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/js/all.min.js" defer></script>
</head>
<body class="customer-portal">
    <script>
        // Collapsed desktop sidebar, applied before first paint (nav-toggle.js owns the button).
        try { if (localStorage.getItem('customerSidebarCollapsed') === '1') document.body.classList.add('sidebar-collapsed'); } catch (e) {}
    </script>
    <?php
    // section => [page key, href, icon, label, badge count, badge element id]
    $customer_nav = [
        'Shop' => [
            ['dashboard', 'dashboard.php', 'fa-home', 'Dashboard', 0, ''],
            ['products', 'products.php', 'fa-store', 'Shop', 0, ''],
            ['cart', 'cart.php', 'fa-shopping-cart', 'Cart', (int) $cart_count, 'cart-count-badge'],
            ['orders', 'orders.php', 'fa-history', 'Purchase History', 0, ''],
        ],
        'Loyalty' => [
            ['reward_catalog', 'reward_catalog.php', 'fa-award', 'Reward Catalog', 0, ''],
            ['my_rewards', 'my_rewards.php', 'fa-gift', 'My Vouchers', 0, ''],
        ],
        'Support' => [
            ['messages', 'messages.php', 'fa-comment-dots', 'Messages', (int) $unread_msg_count, 'sidebar-messages-badge'],
            ['rider_chat', 'rider_chat.php', 'fa-motorcycle', 'Message Rider', (int) $rider_msg_unread, ''],
        ],
        'Account' => [
            ['profile', 'profile.php', 'fa-user-circle', 'My Account', 0, ''],
            ['about', 'about.php', 'fa-info-circle', 'About Us', 0, ''],
        ],
    ];
    $customer_nav_parent = ['checkout' => 'cart', 'payment' => 'cart', 'submit_review' => 'orders', 'reward_redemption' => 'reward_catalog', 'my_achievements' => 'profile', 'addresses' => 'profile'];
    $customer_nav_current = $customer_nav_parent[$current_page] ?? $current_page;
    ?>
    <!-- Sidebar (shared app shell — assets/css/ui-kit.css .app-sidebar) -->
    <div class="customer-sidebar app-sidebar">
        <div class="customer-sidebar-brand app-sidebar-brand">
            <img class="app-sidebar-logo" src="<?php echo SYSTEM_LOGO_URL; ?>" alt="<?php echo htmlspecialchars(SYSTEM_NAME); ?> logo">
            <p class="app-sidebar-name"><a href="<?php echo BASE_URL; ?>/customer/dashboard.php"><?php echo htmlspecialchars(SYSTEM_NAME); ?></a></p>
            <p class="app-sidebar-role">Customer Portal</p>
            <button type="button" class="app-sidebar-collapse" aria-label="Collapse sidebar" title="Collapse sidebar"><i class="fas fa-angles-left"></i></button>
        </div>
        <ul class="customer-sidebar-nav app-nav">
            <?php foreach ($customer_nav as $section => $items): ?>
                <li class="app-nav-section"><?php echo htmlspecialchars($section); ?></li>
                <?php foreach ($items as [$key, $href, $icon, $label, $badge, $badge_id]): ?>
                    <li>
                        <a href="<?php echo BASE_URL; ?>/customer/<?php echo $href; ?>" class="<?php echo $customer_nav_current === $key ? 'active' : ''; ?>"<?php echo $customer_nav_current === $key ? ' aria-current="page"' : ''; ?>>
                            <i class="fas <?php echo $icon; ?>"></i><span class="app-nav-label"><?php echo htmlspecialchars($label); ?></span>
                            <?php if ($badge_id !== ''): ?>
                                <span id="<?php echo $badge_id; ?>" class="app-nav-badge cart-badge"<?php echo $badge > 0 ? '' : ' style="display: none;"'; ?>><?php echo $badge; ?></span>
                            <?php elseif ($badge > 0): ?>
                                <span class="app-nav-badge cart-badge"><?php echo $badge; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
        <div class="customer-sidebar-logout app-sidebar-footer">
            <a href="<?php echo BASE_URL; ?>/customer/logout.php">
                <i class="fas fa-sign-out-alt"></i><span class="app-footer-label">Logout</span>
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="customer-main-content">
        <?php if ($customer): ?>
        <!-- Top bar: greeting, loyalty balance, cart and notifications -->
        <header class="app-topbar">
            <div class="app-topbar-greeting">
                <span class="app-topbar-hello">Welcome back, <strong><?php echo htmlspecialchars($customer['name']); ?></strong></span>
            </div>
            <div class="app-topbar-actions">
                <a class="app-points-pill" href="<?php echo BASE_URL; ?>/customer/reward_catalog.php" title="Your loyalty points — browse rewards">
                    <i class="fas fa-star"></i>
                    <span><strong><?php echo number_format($customer['loyalty_points'] ?? 0, 2); ?></strong> <span class="app-points-unit">pts</span></span>
                </a>
                <a class="app-icon-btn" href="<?php echo BASE_URL; ?>/customer/cart.php" aria-label="Cart" title="Cart">
                    <i class="fas fa-shopping-cart"></i>
                    <?php if ($cart_count > 0): ?><span class="app-icon-count"><?php echo (int) $cart_count; ?></span><?php endif; ?>
                </a>
                <!-- Notifications (behavior lives in includes/footer.php) -->
                <div class="notif-bell-wrap" id="notif-bell-wrap">
                    <button type="button" class="notif-bell-btn app-icon-btn" id="notif-bell-btn" aria-label="Notifications" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if ($notif_count > 0): ?>
                            <span class="notif-bell-badge" id="notif-bell-badge"><?php echo (int)$notif_count; ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-panel" id="notif-panel">
                        <div class="notif-panel-header">
                            <h3><i class="fas fa-bell"></i> Notifications</h3>
                            <button class="notif-panel-markall" id="notif-panel-markall" style="<?php echo $notif_count > 0 ? '' : 'display:none;'; ?>">
                                <i class="fas fa-check-double"></i> Mark all read
                            </button>
                        </div>
                        <div class="notif-panel-body" id="notif-panel-body">
                            <div class="notif-panel-empty"><i class="fas fa-bell-slash"></i><p style="margin:0;">No new notifications</p></div>
                        </div>
                        <div class="notif-panel-footer">
                            <a href="<?php echo BASE_URL; ?>/customer/notifications.php">View all notifications</a>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <?php endif; ?>
        <div class="customer-page">
