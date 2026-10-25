<?php
/**
 * The floating "Return / Refund" window on the admin and staff Orders pages (Return / Refund tab).
 *
 *   GET  ?id=<return id>                      -> { success, html, status }  the order summary + that request's review box
 *   POST id, action=rr_*, staff_note, item_condition, inspection_note, csrf_token
 *                                             -> { success, message, html, status }  runs the step, returns the box again
 *
 * Same box and the same rules as Order Details (modules/shared/order_return.php -> rr_process()), so the window
 * and the order page can never disagree. Admin and staff only.
 */
require_once __DIR__ . '/../admin/bootstrap.php';
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/ajax_helper.php';
require_once __DIR__ . '/../../customer/includes/functions.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/order_return.php';

$payload = verifyJWT((string) getJWTFromCookie());
$role = is_array($payload) ? strtolower(trim((string) ($payload['role'] ?? ''))) : '';
if (!in_array($role, ['admin', 'staff'], true)) {
    ajax_json(['success' => false, 'message' => 'Please log in again.'], $payload ? 403 : 401);
}
$actor_id = (int) ($payload['user_id'] ?? 0);

$request_id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
rr_ensure_schema($conn);
$stmt = $conn->prepare('SELECT order_id FROM tbl_return_requests WHERE id = ?');
$stmt->bind_param('i', $request_id);
$stmt->execute();
$order_id = (int) ($stmt->get_result()->fetch_assoc()['order_id'] ?? 0);
$stmt->close();
$order = $order_id > 0 ? get_order_by_id($order_id) : null;
if (!$order) {
    ajax_json(['success' => false, 'message' => 'That return request was not found.'], 404);
}

$message = '';
$ok = true;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        ajax_json(['success' => false, 'message' => 'Your session expired. Please reload the page.'], 403);
    }
    $map = ['rr_approve' => 'approve', 'rr_reject' => 'reject', 'rr_receive' => 'receive', 'rr_inspect' => 'inspect', 'rr_refund' => 'refund', 'rr_replace' => 'replace'];
    $action = (string) ($_POST['action'] ?? '');
    if (!isset($map[$action])) {
        ajax_json(['success' => false, 'message' => 'Unknown action.'], 422);
    }
    $result = rr_process($conn, $order, $request_id, $map[$action], $actor_id, [
        'note'            => (string) ($_POST['staff_note'] ?? ''),
        'condition'       => (string) ($_POST['item_condition'] ?? ''),
        'inspection_note' => (string) ($_POST['inspection_note'] ?? ''),
    ]);
    $ok = (bool) $result['ok'];
    $message = (string) ($result['message'] ?? '');
    if ($ok && $message === '') {
        $message = ['approve' => 'Return approved.', 'reject' => 'Return rejected.', 'receive' => 'Item marked as received.',
                    'inspect' => 'Inspection saved.', 'refund' => 'Refund processed.', 'replace' => 'Replacement processed.'][$map[$action]];
    }
    $order = get_order_by_id($order_id) ?: $order;
}

// Order summary at the top of the window.
$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$customer = get_customer_by_id((int) $order['customer_id']) ?: [];
$pay = ['cod' => 'Cash on Delivery', 'gcash' => 'GCash', 'bank' => 'Bank Transfer', 'pay_at_shop' => 'Pay at Shop'];
$full = BASE_URL . '/modules/' . ($role === 'admin' ? 'admin' : 'staff') . '/order_details.php?id=' . (int) $order['id'] . '#return-' . $request_id;
$summary = '<div class="rrm-order">'
    . '<div><span>Order</span><strong>#' . $h($order['order_number']) . '</strong></div>'
    . '<div><span>Customer</span><strong>' . $h($customer['name'] ?? 'Customer') . '</strong>'
        . (!empty($customer['phone']) ? '<a href="tel:' . $h($customer['phone']) . '">' . $h($customer['phone']) . '</a>' : '') . '</div>'
    . '<div><span>Paid by</span><strong>' . $h($pay[$order['payment_method']] ?? strtoupper((string) $order['payment_method'])) . '</strong>'
        . '<small>' . (($order['fulfillment_type'] ?? '') === 'pickup' ? 'Pickup' : 'Delivery') . ' &middot; PHP ' . number_format((float) $order['total'], 2) . '</small></div>'
    . '<a class="rrm-full" href="' . $h($full) . '"><i class="fas fa-up-right-from-square"></i> Full order</a>'
    . '</div>';

$status = '';
foreach (rr_get_for_order($conn, (int) $order['id']) as $r) {
    if ((int) $r['id'] === $request_id) {
        $status = (string) $r['status'];
    }
}

ajax_json([
    'success' => $ok,
    'message' => $message,
    'status' => $status,
    'title' => 'Return #' . $request_id,
    'html' => order_return_card($conn, $order, $request_id, csrf_field(), $summary),
], $ok ? 200 : 422);
