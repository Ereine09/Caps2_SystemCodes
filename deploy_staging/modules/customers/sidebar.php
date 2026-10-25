<?php
require_once __DIR__ . '/../../app/helpers/admin_badge_helper.php';
if (!isset($pending_remittances_count)) {
    $pending_remittances_count = get_pending_remittance_count($conn);
}
// This file is included in various admin pages.
// Ensure necessary variables are defined in the parent script before including this file.
// Expected variables: $user_role, $pending_orders_count, $unread_count, $current_page (optional, for active state)
// Constants: SYSTEM_LOGO_URL, SYSTEM_NAME, BASE_URL

$user_role = strtolower(trim($payload['role'] ?? 'staff'));

// --- SECURITY GATE: ADMIN ONLY ---
if ($user_role !== 'admin') {
    header("Location: " . BASE_URL . "/modules/staff/dashboard.php");
    exit();
}

$current_page = $current_page ?? basename($_SERVER['PHP_SELF'], '.php');

// Detail/sub pages that should highlight their parent nav item.
$admin_nav_parent = [
    'order_details' => 'orders',
    'print_receipt' => 'orders',
    'customer_details' => 'customers',
    'add_customer' => 'customers',
    'create_customer_order' => 'customers',
    'create_customer_order_success' => 'customers',
    'active_carts' => 'customers',
];
$admin_nav_current = $admin_nav_parent[$current_page] ?? $current_page;

// Nav structure: section label => [page key, href, icon, label, badge count, badge element id]
$admin_nav = [
    'Overview' => [
        ['dashboard', '/modules/admin/dashboard.php', 'fa-home', 'Dashboard', 0, ''],
        ['analytics', '/modules/admin/analytics.php', 'fa-chart-line', 'Analytics & Reports', 0, ''],
    ],
    'Sales & Fulfillment' => [
        ['orders', '/modules/admin/orders.php', 'fa-shopping-cart', 'Orders', (int) ($pending_orders_count ?? 0), ''],
        ['products', '/modules/admin/products.php', 'fa-store', 'Products', 0, ''],
        ['delivery', '/modules/admin/delivery.php', 'fa-truck', 'Delivery', 0, ''],
        ['remittance_management', '/modules/admin/remittance_management.php', 'fa-money-bill-wave', 'Remittance', (int) $pending_remittances_count, ''],
        ['ebike_maintenance', '/modules/admin/ebike_maintenance.php', 'fa-bicycle', 'E-Bike', 0, ''],
    ],
    'Customers & Loyalty' => [
        ['customers', '/modules/customers/customers.php', 'fa-user-friends', 'Customers', 0, ''],
        ['loyalty_points', '/modules/customers/loyalty_points.php', 'fa-star', 'Loyalty Points', 0, ''],
        ['manage_rewards', '/modules/admin/manage_rewards.php', 'fa-boxes', 'Manage Rewards', 0, ''],
        ['reward_redemption', '/modules/customers/reward_redemption.php', 'fa-gift', 'Reward Redemption', 0, ''],
        ['messages', '/modules/admin/messages.php', 'fa-comment-dots', 'Messages', (int) ($unread_count ?? 0), 'sidebar-messages-badge'],
        ['reviews', '/modules/admin/reviews.php', 'fa-star-half-alt', 'Reviews', 0, ''],
    ],
    'Administration' => [
        ['staff_management', '/modules/admin/staff_management.php', 'fa-users-cog', 'Staff Management', 0, ''],
        ['activity_logs', '/modules/admin/activity_logs.php', 'fa-history', 'Activity Logs', 0, ''],
        ['settings', '/modules/admin/settings.php', 'fa-cog', 'Settings', 0, ''],
        ['about', '/modules/admin/about.php', 'fa-info-circle', 'About Us', 0, ''],
    ],
];
?>
<script>
    // Bridges PHP constants this shared sidebar already has access to, so the
    // static (cache-busted) assets/js/nav-toggle.js can reach the same
    // WebSocket server and API base every inline-PHP page already uses,
    // without hardcoding a host/path that would break in another deployment.
    window.__DPS_WS_PORT = <?php echo (int) WS_PORT; ?>;
    window.__DPS_BASE_URL = <?php echo json_encode(BASE_URL); ?>;
    // Apply saved Dark Mode / collapsed-sidebar before the page paints (this
    // partial is the first thing in <body>), so there is no light flash or
    // sidebar jump. nav-toggle.js owns the toggles themselves.
    (function () {
        try {
            if (localStorage.getItem('dps_admin_theme') === 'dark') document.body.classList.add('dark-mode');
            if (localStorage.getItem('dps_admin_sidebar_collapsed') === '1') document.body.classList.add('sidebar-collapsed');
        } catch (e) {}
    })();
</script>
<div class="sidebar app-sidebar">
    <div class="sidebar-brand app-sidebar-brand">
        <img class="app-sidebar-logo" src="<?php echo SYSTEM_LOGO_URL; ?>" alt="<?php echo htmlspecialchars(SYSTEM_NAME); ?> logo">
        <h2 class="app-sidebar-name"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        <p class="app-sidebar-role">Admin</p>
        <button type="button" class="app-sidebar-collapse" aria-label="Collapse sidebar" title="Collapse sidebar"><i class="fas fa-angles-left"></i></button>
    </div>
    <?php if (defined('ADMIN_AUTH_ENABLED') && !ADMIN_AUTH_ENABLED): ?>
        <a href="<?php echo BASE_URL; ?>/modules/admin/settings.php" class="app-sidebar-alert">
            <i class="fas fa-exclamation-triangle"></i> Admin login OFF — click to re-enable
        </a>
    <?php endif; ?>
    <ul class="nav-links app-nav">
        <?php foreach ($admin_nav as $section => $items): ?>
            <li class="app-nav-section"><?php echo htmlspecialchars($section); ?></li>
            <?php foreach ($items as [$key, $href, $icon, $label, $badge, $badge_id]): ?>
                <li><a href="<?php echo BASE_URL . $href; ?>" class="<?php echo $admin_nav_current === $key ? 'active' : ''; ?>"<?php echo $admin_nav_current === $key ? ' aria-current="page"' : ''; ?>>
                    <i class="fas <?php echo $icon; ?>"></i><span class="app-nav-label"><?php echo htmlspecialchars($label); ?></span>
                    <?php if ($badge_id !== ''): ?>
                        <span id="<?php echo $badge_id; ?>" class="app-nav-badge"<?php echo $badge > 0 ? '' : ' style="display: none;"'; ?>><?php echo $badge; ?></span>
                    <?php elseif ($badge > 0): ?>
                        <span class="app-nav-badge"><?php echo $badge; ?></span>
                    <?php endif; ?>
                </a></li>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </ul>
    <div class="sidebar-footer app-sidebar-footer">
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link">
            <i class="fas fa-sign-out-alt"></i><span class="app-footer-label">Logout</span>
        </a>
    </div>
</div>
<script defer src="<?php echo BASE_URL; ?>/assets/js/nav-toggle.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/nav-toggle.js') ?: time(); ?>"></script>
