<?php
/**
 * Admin - Rider Remittances. The page itself lives in modules/shared/remittance_page.php
 * (shared with the staff portal); this file only does the admin auth gate and sidebar badges.
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/remittance_schema_helper.php';
require_once __DIR__ . '/../../app/helpers/admin_badge_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token)) || strtolower(trim($payload['role'] ?? '')) !== 'admin') {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}
$username = htmlspecialchars($payload['username']);
$user_role = htmlspecialchars($payload['role']);
$user_id = (int)$payload['user_id'];

// Sidebar badges
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$pending_remittances_count = get_pending_remittance_count($conn);
$unread_count = get_unread_count_staff($user_id);

$remit_sidebar = __DIR__ . '/../customers/sidebar.php';
$remit_api = 'remittance_api.php';
$remit_title = 'Rider Remittances - Admin';
require __DIR__ . '/../shared/remittance_page.php';
