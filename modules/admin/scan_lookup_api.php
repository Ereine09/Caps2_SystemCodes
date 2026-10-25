<?php
/**
 * Web version of the Flutter Admin App's scanner lookup (api/admin_app_api.php "lookup_order").
 *
 * The Flutter app sends a Bearer token; browser pages only have the HttpOnly staff cookie, so
 * this endpoint does the same lookup with the same admin cookie login every modules/admin page
 * uses. READ-ONLY: it validates the EXISTING Delivery QR ({delivery_id, order_id?, token} from
 * tbl_delivery.qr_confirmation_token) and returns a summary of the order. It never changes an
 * order - status changes stay on order_details.php behind its own confirm step.
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function scan_respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    scan_respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$payload = verifyJWT(getJWTFromCookie());
if (!is_array($payload)) {
    scan_respond(['success' => false, 'message' => 'Your login has expired. Please log in again.'], 401);
}
if (!in_array(strtolower(trim((string) ($payload['role'] ?? ''))), ['admin', 'staff'], true)) {
    scan_respond(['success' => false, 'message' => 'Only admins and staff can use this scanner.'], 403);
}

$body = json_decode(file_get_contents('php://input'), true);
$raw = is_array($body) ? (string) ($body['qr_data'] ?? '') : '';
$qr = strlen($raw) <= 1000 ? json_decode($raw, true) : null;
$delivery_id = is_array($qr) ? (int) ($qr['delivery_id'] ?? 0) : 0;
$qr_order_id = is_array($qr) ? (int) ($qr['order_id'] ?? 0) : 0;
$token = is_array($qr) && is_scalar($qr['token'] ?? null) ? (string) $qr['token'] : '';
if ($delivery_id <= 0 || $token === '') {
    scan_respond(['success' => false, 'message' => 'That is not a valid delivery QR code.'], 422);
}

// Same check as the Flutter lookup: the delivery row, its order and its secret token must all match.
// Older QRs have no order_id; the delivery row then decides which order it belongs to.
$stmt = $conn->prepare(
    'SELECT d.order_id FROM tbl_delivery d
     WHERE d.id = ? AND d.qr_confirmation_token = ? AND (? = 0 OR d.order_id = ?) LIMIT 1'
);
$stmt->bind_param('isii', $delivery_id, $token, $qr_order_id, $qr_order_id);
$stmt->execute();
$match = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$match) {
    scan_respond(['success' => false, 'message' => 'No matching order was found for this QR code. It may be old, or the order may already be completed.'], 404);
}
$order_id = (int) $match['order_id'];

$stmt = $conn->prepare(
    'SELECT o.id, o.order_number, o.order_status, o.fulfillment_type, o.total, o.payment_method,
            o.payment_settled, o.delivery_address, o.pickup_date, o.pickup_time, o.created_at,
            c.name AS customer_name,
            (SELECT COALESCE(SUM(i.quantity), 0) FROM tbl_order_items i WHERE i.order_id = o.id) AS item_count,
            (SELECT u.username FROM tbl_delivery d2 JOIN users u ON u.id = d2.rider_id
              WHERE d2.order_id = o.id ORDER BY d2.id DESC LIMIT 1) AS rider_name
     FROM tbl_orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     WHERE o.id = ? LIMIT 1'
);
$stmt->bind_param('i', $order_id);
$stmt->execute();
$o = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$o) {
    scan_respond(['success' => false, 'message' => 'No matching order was found for this QR code.'], 404);
}

scan_respond(['success' => true, 'data' => [
    'id'               => (int) $o['id'],
    'order_number'     => (string) $o['order_number'],
    'order_status'     => (string) $o['order_status'],
    'fulfillment_type' => (string) ($o['fulfillment_type'] ?? ''),
    'total'            => (float) $o['total'],
    'payment_method'   => (string) ($o['payment_method'] ?? ''),
    'payment_settled'  => (int) ($o['payment_settled'] ?? 0) === 1,
    'delivery_address' => $o['delivery_address'] !== null ? (string) $o['delivery_address'] : null,
    'pickup_date'      => $o['pickup_date'] !== null ? (string) $o['pickup_date'] : null,
    'pickup_time'      => $o['pickup_time'] !== null ? (string) $o['pickup_time'] : null,
    'created_at'       => (string) $o['created_at'],
    'customer_name'    => $o['customer_name'] !== null ? (string) $o['customer_name'] : null,
    'item_count'       => (int) $o['item_count'],
    'rider_name'       => $o['rider_name'] !== null ? (string) $o['rider_name'] : null,
]]);
