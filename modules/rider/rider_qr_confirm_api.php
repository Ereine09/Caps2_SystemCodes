<?php
/**
 * Rider QR Confirm API (starting point)
 *
 * Expected payload (JSON):
 * {
 *   "delivery_id": 123,
 *   "qr_code": "..." (or scanned token)
 * }
 *
 * NOTE: Backend storage for QR confirmation is not present in the currently visible code.
 * This endpoint will mark the delivery as in_transit/picked_up or delivered depending on your mapping.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

$response = [
  'success' => false,
  'message' => '',
  'data' => null
];

try {
  $token = getJWTFromCookie();
  $payload = verifyJWT($token);
  if (!$payload) {
    throw new Exception('Unauthorized');
  }

  $role = strtolower(trim((string)($payload['role'] ?? '')));
  if ($role !== 'rider') {
    throw new Exception('Unauthorized role');
  }

  $rider_id = (int)($payload['user_id'] ?? 0);
  if ($rider_id <= 0) {
    throw new Exception('Invalid rider');
  }

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new Exception('POST required');
  }

  $input = json_decode(file_get_contents('php://input'), true);
  if (!is_array($input)) $input = [];

  $delivery_id = (int)($input['delivery_id'] ?? 0);
  $qr_code = trim((string)($input['qr_code'] ?? ''));

  if ($delivery_id <= 0) throw new Exception('delivery_id is required');
  if ($qr_code === '') throw new Exception('qr_code is required');

  // TODO: Validate qr_code against a table / expected token per delivery.
  // For now: mark delivery as delivered.

  $order_id = 0;
  $stmt = $conn->prepare("SELECT order_id FROM tbl_delivery WHERE id = ? LIMIT 1");
  $stmt->bind_param('i', $delivery_id);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$res) throw new Exception('Delivery not found');
  $order_id = (int)$res['order_id'];

  $stmt1 = $conn->prepare("UPDATE tbl_orders SET order_status = 'completed', updated_at = NOW() WHERE id = ?");
  $stmt1->bind_param('i', $order_id);
  $stmt1->execute();
  $stmt1->close();

  $stmt2 = $conn->prepare(
    "UPDATE tbl_delivery
     SET status = 'delivered', delivered_at = NOW(), updated_at = NOW()
     WHERE id = ?"
  );
  $stmt2->bind_param('i', $delivery_id);
  $stmt2->execute();
  $stmt2->close();

  notifications_create($conn, [
    'user_id' => $rider_id,
    'customer_id' => (int)($conn->query("SELECT customer_id FROM tbl_orders WHERE id = " . (int)$order_id . " LIMIT 1")->fetch_assoc()['customer_id'] ?? 0),
    'type' => 'rider_qr_confirm',
    'channel' => 'in_app',
    'title' => 'Delivery confirmed',
    'message' => 'Your delivery has been confirmed by the rider.',
    'reference_table' => 'tbl_orders',
    'reference_id' => $order_id,
    'email_to' => null,
  ]);

  $response['success'] = true;
  $response['data'] = [
    'delivery_id' => $delivery_id,
    'order_id' => $order_id,
    'confirmed' => true,
  ];
}

catch (Exception $e) {
  $response['success'] = false;
  $response['message'] = $e->getMessage();
  http_response_code(400);
}

echo json_encode($response);
?>

