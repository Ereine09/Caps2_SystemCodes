<?php
/**
 * Staff portal sidebar.
 *
 * Included by every modules/staff/*.php page AFTER $conn is available.
 * It is self-contained: if the parent page has not already computed the badge
 * counts, they are derived here so the partial is safe to include anywhere.
 *
 * Every link points to a /modules/staff/* route. No admin-only links.
 *
 * Markup intentionally mirrors modules/customers/sidebar.php (the shared
 * Admin sidebar partial, despite its folder name) — same .sidebar /
 * .sidebar-brand / .nav-links / .sidebar-footer / .logout-link classes, no
 * competing inline layout styles — so both portals get the exact same
 * responsive/dark-mode/scroll-persistence behavior from assets/css/
 * admin_style.css and assets/js/nav-toggle.js for free. Only the nav items
 * differ (Staff's own approved subset — see the <ul> below), plus this
 * "Staff Portal" subtitle line.
 */

if (!isset($conn) || !$conn) {
    return;
}

// Resolve the current staff user id (needed for the unread-messages badge).
if (!isset($staff_sidebar_user_id)) {
    if (isset($user_id) && (int) $user_id > 0) {
        $staff_sidebar_user_id = (int) $user_id;
    } else {
        $sb_token = function_exists('getJWTFromCookie') ? getJWTFromCookie() : null;
        $sb_payload = ($sb_token && function_exists('verifyJWT')) ? verifyJWT($sb_token) : null;
        $staff_sidebar_user_id = is_array($sb_payload) ? (int) ($sb_payload['user_id'] ?? 0) : 0;
    }
}

if (!isset($pending_orders_count)) {
    $sb_res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM tbl_orders WHERE order_status = 'pending'");
    $pending_orders_count = $sb_res ? (int) (mysqli_fetch_assoc($sb_res)['total'] ?? 0) : 0;
}

if (!isset($pending_remittances_count)) {
    require_once __DIR__ . '/../../app/helpers/admin_badge_helper.php';
    $pending_remittances_count = get_pending_remittance_count($conn);
}

if (!isset($unread_messages_count)) {
    if (function_exists('get_unread_count_staff') && $staff_sidebar_user_id > 0) {
        $unread_messages_count = (int) get_unread_count_staff($staff_sidebar_user_id);
    } else {
        $sb_res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM tbl_messages WHERE is_read = 0 AND sender_type = 'customer'");
        $unread_messages_count = $sb_res ? (int) (mysqli_fetch_assoc($sb_res)['total'] ?? 0) : 0;
    }
}

// Allow a parent page whose URL doesn't match any nav item (e.g.
// create_customer_order.php, reached from the Customers list) to force
// which link reads as active, the same way modules/customers/sidebar.php's
// $current_page override already works for Admin.
$staff_current_page = $staff_current_page ?? basename($_SERVER['PHP_SELF']);

// Detail pages that should highlight their parent nav item.
$staff_nav_parent = [
    'order_details.php' => 'orders.php',
    'scan.php' => 'orders.php',
    'print_receipt.php' => 'orders.php',
    'loyalty_statement.php' => 'loyalty_points.php',
    'customer_details.php' => 'customers.php',
    'active_carts.php' => 'customers.php',
    'create_customer_order.php' => 'customers.php',
    'create_customer_order_success.php' => 'customers.php',
];
$staff_nav_current = $staff_nav_parent[$staff_current_page] ?? $staff_current_page;

// Flat list of [file, icon, label, badge count, badge element id]
$staff_nav = [
    ['dashboard.php', 'fa-home', 'Dashboard', 0, ''],
    ['orders.php', 'fa-shopping-cart', 'Orders', (int) $pending_orders_count, 'sidebar-orders-badge'],
    ['products.php', 'fa-store', 'Products', 0, ''],
    ['delivery.php', 'fa-truck', 'Deliveries', 0, ''],
    ['remittance_management.php', 'fa-money-bill-wave', 'Remittance', (int) $pending_remittances_count, 'sidebar-remittance-badge'],
    ['customers.php', 'fa-user-friends', 'Customers', 0, ''],
    ['loyalty_points.php', 'fa-star', 'Loyalty Points', 0, ''],
    ['manage_rewards.php', 'fa-boxes', 'Manage Rewards', 0, ''],
    ['reward_redemption.php', 'fa-gift', 'Reward Redemption', 0, ''],
    ['messages.php', 'fa-comment-dots', 'Messages', (int) $unread_messages_count, 'sidebar-messages-badge'],
    ['reviews.php', 'fa-star-half-alt', 'Reviews', 0, ''],
    ['about.php', 'fa-info-circle', 'About Us', 0, ''],
];
?>
<?php require_once __DIR__ . '/../../app/helpers/pwa_helper.php'; echo pwa_head_script('staff'); // installable "DPS Staff" app ?>
<?php require_once __DIR__ . '/../../customer/includes/background_tasks.php'; // auto-confirm COD/pay-at-shop orders ?>
<?php require_once __DIR__ . '/../../app/helpers/nav_notif_helper.php'; $nav_notif_counts = nav_notif_for_sidebar($conn); // number badges for Reviews, cleared when opened ?>
<script>
    // Same bridge as modules/customers/sidebar.php - see its comment.
    window.__DPS_WS_PORT = <?php echo (int) WS_PORT; ?>;
    window.__DPS_WS_ENABLED = <?php echo WS_ENABLED ? 'true' : 'false'; ?>;
    window.__DPS_BASE_URL = <?php echo json_encode(BASE_URL); ?>;
    window.__DPS_PORTAL = 'staff'; // which login the toast poller reads (assets/js/toast.js)
    window.toastOr = window.toastOr || function (m) { alert(m); }; // replaced by assets/js/toast.js
    // Apply saved Dark Mode / collapsed-sidebar before first paint.
    (function () {
        try {
            document.body.classList.add('staff-portal'); // Literata typeface (assets/css/ui-kit.css), distinct from Customer's Poppins
            if (document.documentElement.getAttribute('data-theme') === 'dark') document.body.classList.add('dark-mode'); // legacy hook for page CSS
            if (localStorage.getItem('dps_admin_sidebar_collapsed') === '1') document.body.classList.add('sidebar-collapsed');
        } catch (e) {}
    })();
</script>
<div id="toasts" class="toast-stack"></div>
<script src="<?php echo BASE_URL; ?>/assets/js/theme.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/theme.js') ?: time(); ?>"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/toast.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/toast.js') ?: time(); ?>"></script>
<div class="sidebar app-sidebar">
    <div class="sidebar-brand app-sidebar-brand">
        <img class="app-sidebar-logo" src="<?php echo SYSTEM_LOGO_URL; ?>" alt="<?php echo htmlspecialchars(SYSTEM_NAME); ?> logo">
        <h2 class="app-sidebar-name"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        <p class="app-sidebar-role">Staff Portal</p>
        <button type="button" class="app-sidebar-collapse" aria-label="Collapse sidebar" title="Collapse sidebar"><i class="fas fa-angles-left"></i></button>
    </div>
    <ul class="nav-links app-nav">
        <?php foreach ($staff_nav as [$file, $icon, $label, $badge, $badge_id]): ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/staff/<?php echo $file; ?>" class="<?php echo $staff_nav_current === $file ? 'active' : ''; ?>"<?php echo $staff_nav_current === $file ? ' aria-current="page"' : ''; ?>>
                <i class="fas <?php echo $icon; ?>"></i><span class="app-nav-label"><?php echo htmlspecialchars($label); ?></span>
                <?php if ($badge_id !== ''): ?>
                    <span id="<?php echo $badge_id; ?>" class="app-nav-badge"<?php echo $badge > 0 ? '' : ' style="display: none;"'; ?>><?php echo $badge; ?></span>
                <?php elseif ($badge > 0): ?>
                    <span class="app-nav-badge"><?php echo $badge; ?></span>
                <?php else: ?>
                    <?php echo nav_notif_badge($file, $nav_notif_counts); ?>
                <?php endif; ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
    <div class="sidebar-footer app-sidebar-footer">
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link">
            <i class="fas fa-sign-out-alt"></i><span class="app-footer-label">Logout</span>
        </a>
    </div>
</div>
<script defer src="<?php echo BASE_URL; ?>/assets/js/nav-toggle.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/nav-toggle.js') ?: time(); ?>"></script>
<script defer src="<?php echo BASE_URL; ?>/assets/js/return-spot.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/return-spot.js') ?: time(); ?>"></script>
