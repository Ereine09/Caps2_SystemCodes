<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/background_tasks.php'; // Include the background task runner

if (session_status() === PHP_SESSION_NONE) {
    app_session_start();
}

$customer = current_customer();
$cart_count = cart_item_count();
$unread_msg_count = $customer ? get_unread_count_customer((int) $customer['id']) : 0;

// Customer notification unread count (bell) from the shared notifications table.
$notif_count = 0;
$rider_msg_unread = 0;

if ($customer) {
    $cid = (int) $customer['id'];

    notifications_ensure_schema($conn);

    // Same recipient rule as the toast poller (api/notifications_poll.php), so the badge doesn't jump.
    [$n_scope, $n_types, $n_args] = notifications_recipient_sql('customer', $cid);

    $n_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE $n_scope AND is_read = 0");
    $n_stmt->bind_param($n_types, ...$n_args);
    $n_stmt->execute();

    $notif_count = (int) ($n_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $n_stmt->close();

    $rm_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tbl_messages WHERE customer_id = ? AND sender_type = 'rider' AND is_read = 0");
    $rm_stmt->bind_param('i', $cid);
    $rm_stmt->execute();

    $rider_msg_unread = (int) ($rm_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $rm_stmt->close();
}

// Get current page for active state (allows manual override if set before including header)
$current_page = $current_page ?? basename($_SERVER['PHP_SELF'], '.php');

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <script>
        try {
            var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');
            if (t) document.documentElement.setAttribute('data-theme', t);
        } catch (e) {}
    </script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

    <title><?php echo htmlspecialchars($page_title ?? 'Darius Poultry Supply Customer Portal'); ?></title>

    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <?php require_once __DIR__ . '/../../app/helpers/pwa_helper.php'; echo pwa_head_tags('shop'); // installable "DPS Shop" app (manifest, theme color, home-screen icon) ?>

    <script>
        // Same bridge as the Admin sidebar (modules/customers/sidebar.php) -
        // lets the static, cache-busted assets/js/nav-toggle.js reach the
        // WebSocket server and build correct absolute API URLs without
        // hardcoding a host/path that would break in another deployment.
        window.__DPS_WS_PORT = <?php echo (int) WS_PORT; ?>;
        window.__DPS_WS_ENABLED = <?php echo WS_ENABLED ? 'true' : 'false'; ?>;
        window.__DPS_BASE_URL = <?php echo json_encode(BASE_URL); ?>;
        window.__DPS_PORTAL = <?php echo $customer ? "'customer'" : 'null'; ?>; // toast poller (assets/js/toast.js); logged-out pages don't poll
        window.toastOr = window.toastOr || function (m) {
            alert(m);
        }; // replaced by assets/js/toast.js
    </script>

    <script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/theme.js') ?: time(); ?>"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/toast.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/toast.js') ?: time(); ?>"></script>

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/customer/assets/css/customer_style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/customer_style.css') ?: time(); ?>" />

    <style>
        /* Customer sidebar: highlight the currently selected page */
        .customer-sidebar-nav.app-nav li>a.active,
        .customer-sidebar-nav.app-nav li>a[aria-current="page"] {
            background: rgba(99, 102, 241, 0.22) !important;
            color: #ffffff !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
        }

        .customer-sidebar-nav.app-nav li>a.active i,
        .customer-sidebar-nav.app-nav li>a.active svg,
        .customer-sidebar-nav.app-nav li>a[aria-current="page"] i,
        .customer-sidebar-nav.app-nav li>a[aria-current="page"] svg {
            color: #a5b4fc !important;
        }

        /* Guest top bar buttons (logged-out visitors browsing the shop) */
        .guest-auth-btn {
            display: inline-flex; align-items: center; justify-content: center; height: 38px; padding: 0 16px;
            border-radius: 10px; border: 1px solid var(--border, #e2e8f0); background: var(--surface, #fff);
            color: var(--text, #1e293b); font-weight: 700; font-size: 0.88rem; text-decoration: none; white-space: nowrap;
        }
        .guest-auth-btn:hover { border-color: var(--primary, #4f46e5); color: var(--primary, #4f46e5); }
        .guest-auth-btn.is-primary { background: var(--primary, #4f46e5); border-color: var(--primary, #4f46e5); color: #fff; }
        .guest-auth-btn.is-primary:hover { background: var(--primary-hover, #4338ca); color: #fff; }
        @media (max-width: 480px) {
            .app-topbar-guest .app-topbar-greeting { display: none; }
            .app-topbar-guest .app-topbar-actions { width: 100%; justify-content: flex-end; }
            .guest-auth-btn { height: 36px; padding: 0 13px; }
        }
    </style>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/js/all.min.js" defer></script>
</head>

<body class="customer-portal">

    <script>
        // Collapsed desktop sidebar, applied before first paint (nav-toggle.js owns the button).
        try {
            if (localStorage.getItem('customerSidebarCollapsed') === '1') {
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (e) {}
    </script>

    <?php
    // section => [page key, href, icon, label, badge count, badge element id]
    $customer_nav = [
        'Shop' => [
            ['dashboard', 'dashboard.php', 'fa-home', 'Dashboard', 0, ''],
            ['products', 'products.php', 'fa-store', 'Shop', 0, ''],
            ['cart', 'cart.php', 'fa-shopping-cart', 'Cart', (int) $cart_count, 'sidebar-cart-badge'],
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

    $customer_nav_parent = [
        'checkout' => 'cart',
        'payment' => 'cart',
        'submit_review' => 'orders',
        'reward_redemption' => 'reward_catalog',
        'my_achievements' => 'profile',
        'addresses' => 'profile'
    ];

    // Guests (not logged in) can browse the shop; everything else asks them to log in first.
    $guest_next = '';
    if (!$customer) {
        $customer_nav = [
            'Shop' => [
                ['products', 'products.php', 'fa-store', 'Shop', 0, ''],
                ['about', 'about.php', 'fa-info-circle', 'About Us', 0, ''],
            ],
            // Log in / Sign up live in the guest top bar (and the shop's welcome banner), not here.
        ];
        // After logging in from this page, come back to it (customer_next_url() in auth.php).
        $guest_next = '?next=' . rawurlencode(basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH))
            . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
    }

    $customer_nav_current = $customer_nav_parent[$current_page] ?? $current_page;
    ?>

    <!-- Sidebar (shared app shell — assets/css/ui-kit.css .app-sidebar) -->
    <div class="customer-sidebar app-sidebar">
        <div class="customer-sidebar-brand app-sidebar-brand">
            <img class="app-sidebar-logo" src="<?php echo SYSTEM_LOGO_URL; ?>" alt="<?php echo htmlspecialchars(SYSTEM_NAME); ?> logo">

            <p class="app-sidebar-name">
                <a href="<?php echo BASE_URL; ?>/customer/<?php echo $customer ? 'dashboard.php' : 'products.php'; ?>">
                    <?php echo htmlspecialchars(SYSTEM_NAME); ?>
                </a>
            </p>

            <p class="app-sidebar-role">Customer Portal</p>

            <button type="button" class="app-sidebar-collapse" aria-label="Collapse sidebar" title="Collapse sidebar">
                <i class="fas fa-angles-left"></i>
            </button>
        </div>

        <ul class="customer-sidebar-nav app-nav">
            <?php foreach ($customer_nav as $section => $items): ?>

                <?php if ($section === 'Account'): ?>
                    <li class="app-nav-section"><?php echo htmlspecialchars($section); ?></li>
                <?php endif; ?>

                <?php foreach ($items as [$key, $href, $icon, $label, $badge, $badge_id]): ?>
                    <li>
                        <a
                            href="<?php echo BASE_URL; ?>/customer/<?php echo $href . (!$customer && in_array($key, ['login', 'register'], true) ? htmlspecialchars($guest_next) : ''); ?>"
                            class="<?php echo $customer_nav_current === $key ? 'active' : ''; ?>"
                            <?php echo $customer_nav_current === $key ? ' aria-current="page"' : ''; ?>>

                            <i class="fas <?php echo $icon; ?>"></i>
                            <span class="app-nav-label"><?php echo htmlspecialchars($label); ?></span>

                            <?php if ($badge_id !== ''): ?>
                                <span
                                    id="<?php echo $badge_id; ?>"
                                    class="app-nav-badge cart-badge"
                                    <?php echo $badge > 0 ? '' : ' style="display: none;"'; ?>>
                                    <?php echo $badge; ?>
                                </span>
                            <?php elseif ($badge > 0): ?>
                                <span class="app-nav-badge cart-badge"><?php echo $badge; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>

            <?php endforeach; ?>
        </ul>

        <div class="customer-sidebar-logout app-sidebar-footer">
            <?php if ($customer): ?>
            <a href="<?php echo BASE_URL; ?>/customer/logout.php">
                <i class="fas fa-sign-out-alt"></i>
                <span class="app-footer-label">Logout</span>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Content -->
    <div class="customer-main-content">

        <?php if ($customer): ?>

            <!-- Top bar: greeting, loyalty balance, cart and notifications -->
            <header class="app-topbar">

                <div class="app-topbar-greeting">
                    <span class="app-topbar-hello">
                        Welcome back, <strong><?php echo htmlspecialchars($customer['name']); ?></strong>
                    </span>
                </div>

                <div class="app-topbar-actions">

                    <a
                        class="app-points-pill"
                        href="<?php echo BASE_URL; ?>/customer/reward_catalog.php"
                        title="Your loyalty points — browse rewards">

                        <i class="fas fa-star"></i>

                        <span>
                            <strong><?php echo number_format($customer['loyalty_points'] ?? 0, 2); ?></strong>
                            <span class="app-points-unit">pts</span>
                        </span>
                    </a>

                    <!-- Cart: floating mini cart (behavior in includes/footer.php, data from cart_mini_api.php).
                         The link still opens cart.php if scripts are off. #cart-count-badge is updated by
                         products.php / cart.php after add-to-cart and quantity changes. -->
                    <div class="mini-cart-wrap" id="mini-cart-wrap">
                        <a
                            class="app-icon-btn<?php echo $current_page === 'cart' ? ' is-current' : ''; ?>"
                            id="mini-cart-btn"
                            href="<?php echo BASE_URL; ?>/customer/cart.php"
                            aria-label="Cart"
                            aria-haspopup="dialog"
                            aria-expanded="false"
                            title="Cart">

                            <i class="fas fa-shopping-cart"></i>

                            <span class="app-icon-count" id="cart-count-badge"<?php echo $cart_count > 0 ? '' : ' style="display: none;"'; ?>><?php echo (int) $cart_count; ?></span>
                        </a>

                        <div class="mini-cart-panel" id="mini-cart-panel" role="dialog" aria-label="Your cart">
                            <div class="notif-panel-header">
                                <h3><i class="fas fa-shopping-cart"></i> My Cart <span class="mini-cart-qty" id="mini-cart-qty"></span></h3>
                                <button type="button" class="mini-cart-close" id="mini-cart-close" aria-label="Close cart"><i class="fas fa-times"></i></button>
                            </div>
                            <div class="mini-cart-body" id="mini-cart-body">
                                <div class="notif-panel-empty"><i class="fas fa-spinner fa-spin"></i><p style="margin:0;">Loading your cart…</p></div>
                            </div>
                            <div class="mini-cart-foot" id="mini-cart-foot" hidden>
                                <div class="mini-cart-total"><span>Subtotal <small>(incl. VAT)</small></span><strong id="mini-cart-total"></strong></div>
                                <div class="mini-cart-actions">
                                    <a class="mini-cart-btn" href="<?php echo BASE_URL; ?>/customer/cart.php">View Cart</a>
                                    <a class="mini-cart-btn primary" href="<?php echo BASE_URL; ?>/customer/cart.php?go=checkout">Checkout <i class="fas fa-arrow-right"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="app-icon-btn app-topbar-theme"
                        data-theme-toggle
                        aria-label="Switch theme">

                        <i class="fas fa-moon" aria-hidden="true"></i>
                    </button>

                    <!-- Notifications (behavior lives in includes/footer.php) -->
                    <div class="notif-bell-wrap" id="notif-bell-wrap">

                        <button
                            type="button"
                            class="notif-bell-btn app-icon-btn"
                            id="notif-bell-btn"
                            aria-label="Notifications"
                            title="Notifications">

                            <i class="fas fa-bell"></i>

                            <span
                                class="notif-bell-badge"
                                id="notif-bell-badge"
                                data-notif-count
                                <?php echo $notif_count > 0 ? '' : ' hidden'; ?>>
                                <?php echo $notif_count > 99 ? '99+' : (int) $notif_count; ?>
                            </span>
                        </button>

                        <div class="notif-panel" id="notif-panel">

                            <div class="notif-panel-header">
                                <h3>
                                    <i class="fas fa-bell"></i> Notifications
                                </h3>

                                <button
                                    class="notif-panel-markall"
                                    id="notif-panel-markall"
                                    style="<?php echo $notif_count > 0 ? '' : 'display:none;'; ?>">

                                    <i class="fas fa-check-double"></i> Mark all read
                                </button>
                            </div>

                            <div class="notif-panel-body" id="notif-panel-body">
                                <div class="notif-panel-empty">
                                    <i class="fas fa-bell-slash"></i>
                                    <p style="margin:0;">No new notifications</p>
                                </div>
                            </div>

                            <div class="notif-panel-footer">
                                <a href="<?php echo BASE_URL; ?>/customer/notifications.php">
                                    View all notifications
                                </a>
                            </div>

                        </div>
                    </div>

                </div>
            </header>

        <?php else: ?>

            <!-- Guest top bar: browsing is open to everyone; buying needs an account. -->
            <header class="app-topbar app-topbar-guest">
                <div class="app-topbar-greeting">
                    <span class="app-topbar-hello">Welcome to <strong><?php echo htmlspecialchars(SYSTEM_NAME); ?></strong></span>
                </div>
                <div class="app-topbar-actions">
                    <button type="button" class="app-icon-btn app-topbar-theme" data-theme-toggle aria-label="Switch theme">
                        <i class="fas fa-moon" aria-hidden="true"></i>
                    </button>
                    <a class="guest-auth-btn" href="<?php echo BASE_URL; ?>/customer/login.php<?php echo htmlspecialchars($guest_next); ?>">Log in</a>
                    <a class="guest-auth-btn is-primary" href="<?php echo BASE_URL; ?>/customer/register.php<?php echo htmlspecialchars($guest_next); ?>">Sign up</a>
                </div>
            </header>

        <?php endif; ?>

        <div class="customer-page">