<?php
/**
 * Rider Orders API
 *
 * Returns a rider-friendly list of assigned deliveries/orders with real order_status
 * from tbl_orders (so the rider app does NOT show everything as pending).
 */

header('Content-Type: application/json');

// --- CORS & Preflight Headers ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  exit(0);
}

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

$response = [
  'success' => false,
  'message' => '',
  'data' => null
];

try {
  $token = '';
  $headers = getallheaders();
  if (isset($headers['Authorization'])) {
    $authHeader = $headers['Authorization'];
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
      $token = $matches[1];
    }
  }

  if (empty($token)) {
    $token = getJWTFromCookie();
  }

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

  $statuses = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'processing' => 'Processing',
    'ready_for_pickup' => 'Ready for Pickup',
    'out_for_delivery' => 'Out for Delivery',
    'to_ship' => 'To Ship',
    'to_receive' => 'To Receive',
    'reviews' => 'Reviews',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
  ];

  // NOTE: current schema uses tbl_delivery.status to determine which deliveries are visible.
  // There is no visible rider_id column in tbl_delivery/tbl_orders in the code we saw.
  // This endpoint returns the REAL order_status from tbl_orders.
  $stmt = $conn->prepare(
    "SELECT d.id AS delivery_id,
            d.order_id,
            d.address,
            d.phone,
            d.instructions,
            d.status AS delivery_status,
            d.delivered_at,
            o.order_number,
            o.order_status,
            o.total,
            o.fulfillment_type,
            c.name AS customer_name,
            c.email AS customer_email
     FROM tbl_delivery d
     JOIN tbl_orders o ON d.order_id = o.id
     LEFT JOIN customers c ON o.customer_id = c.id
     WHERE d.status IN ('pending','in_transit')
     ORDER BY d.created_at DESC
     LIMIT 50"
  );

  $stmt->execute();
  $result = $stmt->get_result();

  $assignments = [];
  while ($row = $result->fetch_assoc()) {
    $orderStatusKey = (string)($row['order_status'] ?? 'pending');
    $orderStatusLabel = $statuses[$orderStatusKey] ?? $orderStatusKey;

    $assignments[] = [
      'delivery_id' => (int)($row['delivery_id'] ?? 0),
      'order_id' => (int)($row['order_id'] ?? 0),
      'order_number' => $row['order_number'] ?? '',
      'customer_name' => $row['customer_name'] ?? '',
      'address' => $row['address'] ?? '',
      'delivery_status' => $row['delivery_status'] ?? '',
      'order_status' => $orderStatusKey,
      'order_status_label' => $orderStatusLabel,
      'fulfillment_type' => $row['fulfillment_type'] ?? '',
      'total' => $row['total'] ?? 0,
      'customer_email' => $row['customer_email'] ?? '',
    ];
  }

  $stmt->close();

  $response['success'] = true;
  $response['data'] = ['assignments' => $assignments];
} catch (Exception $e) {
  $response['success'] = false;
  $response['message'] = $e->getMessage();
  http_response_code(401);
}

echo json_encode($response);
?>

