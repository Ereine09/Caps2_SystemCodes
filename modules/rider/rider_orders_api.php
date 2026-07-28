<?php
/**
 * Rider Orders API
 *
 * Returns a rider-friendly list of assigned deliveries/orders with real order_status
 * from tbl_orders filtered for the authenticated rider.
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
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

// Ensure rider schema is up-to-date
ensureRiderSchema($conn);

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
        throw new Exception('Unauthorized access token');
    }

    $role = strtolower(trim((string)($payload['role'] ?? '')));
    if ($role !== 'rider') {
        throw new Exception('Unauthorized role');
    }

    $user_id = (int)($payload['user_id'] ?? 0);
    if ($user_id <= 0) {
        throw new Exception('Invalid rider account session');
    }

    // Check if user is mapped in riders table or use user_id directly as rider_id
    $rider_id = $user_id;
    $rider_check = $conn->prepare("SELECT id FROM riders WHERE user_id = ? LIMIT 1");
    if ($rider_check) {
        $rider_check->bind_param("i", $user_id);
        $rider_check->execute();
        $res = $rider_check->get_result();
        if ($row = $res->fetch_assoc()) {
            $rider_id = (int)$row['id'];
        }
        $rider_check->close();
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

    // Fetch orders assigned to this rider, or unassigned available orders
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
                o.payment_method,
                o.created_at,
                o.rider_id,
                GROUP_CONCAT(CONCAT(oi.product_name, ' x', oi.quantity) SEPARATOR ', ') AS items_summary,
                c.name AS customer_name,
                c.email AS customer_email
         FROM tbl_delivery d
         JOIN tbl_orders o ON d.order_id = o.id
         LEFT JOIN tbl_order_items oi ON o.id = oi.order_id
         LEFT JOIN customers c ON o.customer_id = c.id
         WHERE d.status IN ('pending','in_transit')
           AND (o.rider_id = ? OR o.rider_id IS NULL OR o.rider_id = 0)
         GROUP BY d.id, d.order_id, d.address, d.phone, d.instructions, d.status, d.delivered_at,
                  o.order_number, o.order_status, o.total, o.fulfillment_type, o.payment_method,
                  o.created_at, o.rider_id, c.name, c.email
         ORDER BY d.created_at DESC
         LIMIT 50"
    );

    $stmt->bind_param("i", $rider_id);
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
            'phone' => $row['phone'] ?? '',
            'instructions' => $row['instructions'] ?? '',
            'delivery_status' => $row['delivery_status'] ?? '',
            'order_status' => $orderStatusKey,
            'order_status_label' => $orderStatusLabel,
            'fulfillment_type' => $row['fulfillment_type'] ?? '',
            'payment_method' => $row['payment_method'] ?? 'N/A',
            'items_summary' => $row['items_summary'] ?? 'No items summary',
            'total' => $row['total'] ?? 0,
            'customer_email' => $row['customer_email'] ?? '',
            'created_at' => $row['created_at'] ?? '',
            'rider_id' => (int)($row['rider_id'] ?? 0)
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