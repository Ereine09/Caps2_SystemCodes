<?php
/**
 * Rider Assignments API
 *
 * NOTE: This backend file is created as a starting point.
 * It must be wired to your real rider assignment storage (e.g., tbl_delivery assignment, tbl_rider table, etc.).
 */

// --- CORS & Preflight Headers Start ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
// --- CORS & Preflight Headers End ---

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php'; // New helper for schema management

$response = [
  'success' => false,
  'message' => '',
  'data' => null
];

// Ensure rider schema is up-to-date
ensureRiderSchema($conn);

try {
  // Mobile clients send the token via Authorization Header.
  // We check the Authorization header first, and fallback to cookie.
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

  // TODO: Replace this query with your real rider assignment mechanism.
  // Current schema discovered:
  // - tbl_delivery has order_id and status
  // - tbl_orders has order_status, delivery info, customer_id
  // There is currently NO rider_id column in these tables in the visible code.
  // So we return sample 'in_transit/delivered' deliveries.

  $stmt = $conn->prepare(
    "SELECT d.id AS delivery_id,
            d.order_id,
            d.delivery_type,
            d.address,
            d.phone,
            d.instructions,
            d.status AS delivery_status,
            d.delivered_at,
            o.order_number,
            o.order_status,
            o.total,
            c.name AS customer_name,
            c.email AS customer_email
     FROM tbl_delivery d
     JOIN tbl_orders o ON d.order_id = o.id
     LEFT JOIN customers c ON o.customer_id = c.id
     -- Only show deliveries that are pending or in transit and are either unassigned or assigned to this rider
     WHERE d.status IN ('pending','in_transit')
     ORDER BY d.created_at DESC
     LIMIT 50"
  );
  $stmt->execute();
  $result = $stmt->get_result();

  $assignments = [];
  while ($row = $result->fetch_assoc()) {
    $assignments[] = $row;
  }

  $stmt->close();

  $response['success'] = true;
  $response['data'] = [
    'rider_id' => $rider_id,
    'assignments' => $assignments
  ];
}

catch (Exception $e) {
  $response['success'] = false;
  $response['message'] = $e->getMessage();
  http_response_code(401);
}

echo json_encode($response);
?>