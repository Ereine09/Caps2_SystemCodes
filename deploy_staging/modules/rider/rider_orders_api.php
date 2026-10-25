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

    /*
     * Which delivery orders does this rider see?
     *
     * Only DELIVERY orders (fulfillment_type = 'delivery') - pickup orders are never listed.
     *
     * "Mine" = assigned to this rider by EITHER assignment mechanism the system uses:
     *   - tbl_orders.rider_id   = riders.id      (set when the rider claims / updates an order)
     *   - tbl_delivery.rider_id = users.id       (set when an admin assigns a rider, admin_app_api.php)
     * A rider who is assigned keeps seeing the order through every active stage, including
     * Out for Delivery, then in Delivered (completed) and On-Hold (cancelled).
     *
     * "Unclaimed" = neither column names a rider. Those orders are available to any rider from
     * Processing onward (Processing / Ready for Pickup / To Ship) so the rider can see the
     * upcoming delivery. An order assigned to a DIFFERENT rider is never shown here.
     *
     * Visibility does not change status: the rider still moves an order forward only through the
     * QR-confirmed steps in rider_update_status_api.php.
     *
     * The latest tbl_delivery row is LEFT JOINed (an order may not have one yet); address/phone
     * fall back to the values stored on the order itself.
     */
    $mine_sql = "((COALESCE(o.rider_id, 0) = ? AND COALESCE(o.rider_id, 0) > 0) OR (COALESCE(d.rider_id, 0) = ? AND COALESCE(d.rider_id, 0) > 0))";
    $unclaimed_sql = "(COALESCE(o.rider_id, 0) = 0 AND COALESCE(d.rider_id, 0) = 0)";

    $stmt = $conn->prepare(
        "SELECT d.id AS delivery_id,
                o.id AS order_id,
                COALESCE(NULLIF(d.address, ''), o.delivery_address) AS address,
                COALESCE(NULLIF(d.phone, ''), o.delivery_phone) AS phone,
                COALESCE(NULLIF(d.instructions, ''), o.delivery_instructions) AS instructions,
                COALESCE(d.status, 'pending') AS delivery_status,
                d.delivered_at,
                o.order_number,
                o.order_status,
                o.total,
                o.fulfillment_type,
                o.payment_method,
                o.created_at,
                o.rider_id,
                d.rider_id AS delivery_rider_id,
                (SELECT GROUP_CONCAT(CONCAT(oi.product_name, ' x', oi.quantity) SEPARATOR ', ')
                   FROM tbl_order_items oi WHERE oi.order_id = o.id) AS items_summary,
                c.id AS customer_id,
                c.name AS customer_name,
                c.phone AS customer_phone,
                c.email AS customer_email
         FROM tbl_orders o
         LEFT JOIN tbl_delivery d ON d.id = (SELECT MAX(d2.id) FROM tbl_delivery d2 WHERE d2.order_id = o.id)
         LEFT JOIN customers c ON o.customer_id = c.id
         WHERE o.fulfillment_type = 'delivery'
           AND (
                -- assigned to me: any active stage, plus Delivered / On-Hold history
                ($mine_sql
                  AND o.order_status IN ('processing', 'ready_for_pickup', 'to_ship', 'to_receive', 'out_for_delivery')
                  AND COALESCE(d.status, 'pending') IN ('pending', 'in_transit'))
                OR ($mine_sql AND o.order_status = 'completed')
                OR ($mine_sql AND o.order_status = 'cancelled')
                -- not assigned to anyone yet: visible to riders from Processing onward
                OR ($unclaimed_sql
                  AND o.order_status IN ('processing', 'ready_for_pickup', 'to_ship')
                  AND COALESCE(d.status, 'pending') = 'pending')
           )
         ORDER BY (o.order_status IN ('completed', 'cancelled')) ASC,
                  COALESCE(d.created_at, o.created_at) DESC
         LIMIT 100"
    );

    // $mine_sql appears three times, each with (riders.id, users.id).
    $stmt->bind_param('iiiiii', $rider_id, $user_id, $rider_id, $user_id, $rider_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $assignments = [];
    while ($row = $result->fetch_assoc()) {
        $orderStatusKey = (string)($row['order_status'] ?? 'pending');
        $orderStatusLabel = $statuses[$orderStatusKey] ?? $orderStatusKey;
        $orderRiderId = (int)($row['rider_id'] ?? 0);
        $deliveryRiderId = (int)($row['delivery_rider_id'] ?? 0);
        $assignedToMe = ($orderRiderId > 0 && $orderRiderId === $rider_id)
            || ($deliveryRiderId > 0 && $deliveryRiderId === $user_id);

        $assignments[] = [
            'delivery_id' => (int)($row['delivery_id'] ?? 0),
            'order_id' => (int)($row['order_id'] ?? 0),
            'assigned_to_me' => $assignedToMe,
            'is_unclaimed' => $orderRiderId === 0 && $deliveryRiderId === 0,
            'order_number' => $row['order_number'] ?? '',
            'customer_id' => (int)($row['customer_id'] ?? 0),
            'customer_name' => $row['customer_name'] ?? '',
            'customer_phone' => $row['customer_phone'] ?? '',
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
            , 'pickup_label' => $orderStatusKey === 'to_ship' ? 'Ready to Pick Up' : ''
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