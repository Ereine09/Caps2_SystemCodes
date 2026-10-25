<?php
/**
 * Staff - Rider Remittances. Same page as the admin one (modules/shared/remittance_page.php);
 * modules/admin/remittance_api.php already accepts both admin and staff, so only the auth gate,
 * the sidebar and the API URL differ here.
 */
require_once __DIR__ . '/../shared/staff_auth.php';
require_once __DIR__ . '/../../app/helpers/remittance_schema_helper.php';
require_once __DIR__ . '/../../app/helpers/admin_badge_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$payload = require_staff();
$username = htmlspecialchars($payload['username']);
$user_role = 'staff';
$user_id = (int) $payload['user_id'];

// Sidebar badges (staff has no admin_viewed_at "opened" tracking).
$pending_orders_count = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0);
$pending_remittances_count = get_pending_remittance_count($conn);
$unread_messages_count = get_unread_count_staff($user_id);

$remit_sidebar = __DIR__ . '/sidebar.php';
$remit_api = BASE_URL . '/modules/admin/remittance_api.php';
$remit_title = 'Rider Remittances - Staff';
require __DIR__ . '/../shared/remittance_page.php';
