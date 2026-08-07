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

$action = strtolower(trim((string)($_GET['action'] ?? '')));

    // Handle get_order_details early so we can return a single rich order object.
    if ($action === 'get_order_details') {
        $order_id = (int)($_GET['id'] ?? 0);
        if ($order_id <= 0) {
            throw new Exception('Invalid order id');
        }

        $order_q = $conn->prepare("SELECT * FROM tbl_orders WHERE id = ? LIMIT 1");
        $order_q->bind_param('i', $order_id);
        $order_q->execute();
        $order_row = $order_q->get_result()->fetch_assoc();
        $order_q->close();

        if (!$order_row) {
            throw new Exception('Order not found');
        }

        $items_q = $conn->prepare(
            "SELECT oi.id, oi.order_id, oi.product_id, oi.product_name, oi.quantity,
                    oi.unit_price, oi.total_price
             FROM tbl_order_items oi WHERE oi.order_id = ?"
        );
        $items_q->bind_param('i', $order_id);
        $items_q->execute();
        $items = $items_q->get_result()->fetch_all(MYSQLI_ASSOC);
        $items_q->close();

$cust_name = '';
        $cust_phone = '';
        $cust_email = '';
        if (!empty($order_row['customer_id'])) {
            $cst = $conn->prepare("SELECT name, phone, email FROM customers WHERE id = ? LIMIT 1");
            $cst->bind_param('i', $order_row['customer_id']);
            $cst->execute();
            $cst_row = $cst->get_result()->fetch_assoc();
            $cst->close();
            if ($cst_row) {
                $cust_name = $cst_row['name'] ?? '';
                $cust_phone = $cst_row['phone'] ?? '';
                $cust_email = $cst_row['email'] ?? '';
            }
        }

        // Fetch the delivery record so the detail screen shows the SAME address
        // and phone that the list screen uses (tbl_delivery.address). This keeps
        // the "Navigate" action working for both delivery and pickup orders.
        $delivery_address = $order_row['delivery_address'] ?? '';
        $delivery_phone = $order_row['delivery_phone'] ?? '';
        $delivery_q = $conn->prepare("SELECT address, phone FROM tbl_delivery WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        if ($delivery_q) {
            $delivery_q->bind_param('i', $order_id);
            $delivery_q->execute();
            $delivery_row = $delivery_q->get_result()->fetch_assoc();
            $delivery_q->close();
            if ($delivery_row) {
                if (!empty($delivery_row['address'])) {
                    $delivery_address = $delivery_row['address'];
                }
                if (!empty($delivery_row['phone'])) {
                    $delivery_phone = $delivery_row['phone'];
                }
            }
        }

        $response['success'] = true;
        $response['data'] = array_merge($order_row, [
            'id' => (int)$order_row['id'],
            'customer_name' => $cust_name,
            'customer_phone' => $cust_phone,
            'customer_email' => $cust_email,
            // Provide the real delivery address/phone from tbl_delivery under
            // both keys so the Flutter Order model always picks it up.
            'delivery_address' => $delivery_address,
            'address' => $delivery_address,
            'delivery_phone' => $delivery_phone,
            'phone' => $delivery_phone,
            'items' => $items,
        ]);
        echo json_encode($response);
        exit;
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