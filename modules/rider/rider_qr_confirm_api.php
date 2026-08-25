<?php
/**
 * Rider QR Confirm API
 *
 * Expected payload (JSON):
 * {
 *   "delivery_id": 123,
 *   "qr_code": "{\"delivery_id\":123,\"token\":\"...\"}"
 * }
 */

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/loyalty_helper.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];
$transaction_started = false;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    // --- JWT Authentication ---
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

    $rider_user_id = (int)($payload['user_id'] ?? 0);
    if ($rider_user_id <= 0) {
        throw new Exception('Invalid rider account session');
    }

    $rider_stmt = $conn->prepare("SELECT id FROM riders WHERE user_id = ? LIMIT 1");
    $rider_stmt->bind_param('i', $rider_user_id);
    $rider_stmt->execute();
    $rider_row = $rider_stmt->get_result()->fetch_assoc();
    $rider_stmt->close();
    $rider_id = (int)($rider_row['id'] ?? 0);
    if ($rider_id <= 0) {
        throw new Exception('Rider profile not found.');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $action = strtolower(trim((string)($input['action'] ?? 'preview')));
    $delivery_id = (int)($input['delivery_id'] ?? 0);
    $qr_code_content = trim((string)($input['qr_code'] ?? ''));

    if ($delivery_id <= 0) throw new Exception('delivery_id is required');
    if ($qr_code_content === '') throw new Exception('qr_code is required');

    // --- Validate QR Code Token ---
    $qr_data = json_decode($qr_code_content, true);
    if (!is_array($qr_data) || !isset($qr_data['delivery_id']) || !isset($qr_data['token'])) {
        throw new Exception('Invalid QR code format');
    }

    $qr_delivery_id = (int)$qr_data['delivery_id'];
    $qr_token = (string)$qr_data['token'];

    if ($delivery_id !== $qr_delivery_id) {
        throw new Exception('QR code does not match this delivery');
    }

    $stmt = $conn->prepare("SELECT order_id, rider_id AS delivery_rider_id, qr_confirmation_token FROM tbl_delivery WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $delivery_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$res) throw new Exception('Delivery not found');

    if (!$res['qr_confirmation_token'] || $res['qr_confirmation_token'] !== $qr_token) {
        throw new Exception('Invalid confirmation token.');
    }

    $order_id = (int)$res['order_id'];

    $order_stmt = $conn->prepare(
        "SELECT o.order_number, o.order_status, o.rider_id, o.customer_id, c.name AS customer_name,
                d.rider_id AS delivery_rider_id,
                CASE WHEN o.rider_id = r.id OR o.rider_id = r.user_id
                          OR d.rider_id = r.id OR d.rider_id = r.user_id
                     THEN 1 ELSE 0 END AS assigned_to_authenticated_rider
         FROM tbl_orders o
         LEFT JOIN tbl_delivery d ON d.id = ? AND d.order_id = o.id
         LEFT JOIN riders r ON r.user_id = ?
         LEFT JOIN customers c ON c.id = o.customer_id
         WHERE o.id = ? LIMIT 1"
    );
    $order_stmt->bind_param('iii', $delivery_id, $rider_user_id, $order_id);
    $order_stmt->execute();
    $order = $order_stmt->get_result()->fetch_assoc();
    $order_stmt->close();

    if (!$order) throw new Exception('Order not found.');
    if (($order['order_status'] ?? '') === 'completed') {
        throw new Exception('Order is already completed.');
    }
    if (($order['order_status'] ?? '') === 'cancelled') {
        throw new Exception('This order has been cancelled.');
    }
    $eligible_statuses = ['to_ship', 'to_receive', 'out_for_delivery'];
    if (!in_array(($order['order_status'] ?? ''), $eligible_statuses, true)) {
        throw new Exception('Order is not yet eligible for delivery confirmation.');
    }
    $is_authenticated_rider = (int)($order['assigned_to_authenticated_rider'] ?? 0) === 1;
    $is_available_for_pickup = ($order['order_status'] ?? '') === 'to_ship'
        && (int)($order['rider_id'] ?? 0) <= 0
        && (int)($order['delivery_rider_id'] ?? 0) <= 0;

    // A physical parcel QR authorizes the pickup claim for an unassigned To Ship
    // order. Later stages still require the rider assignment above.
    if (!$is_authenticated_rider && !$is_available_for_pickup) {
        throw new Exception('You are not authorized to confirm this delivery.');
    }

    if ($action === 'confirm' && (!$is_authenticated_rider
        || ($order['order_status'] ?? '') !== 'out_for_delivery')) {
        throw new Exception('This order must be Out for Delivery before it can be completed.');
    }

    if ($action !== 'confirm') {
        $response['success'] = true;
        $response['data'] = [
            'delivery_id' => $delivery_id,
            'order_id' => $order_id,
            'order_number' => $order['order_number'],
            'order_status' => $order['order_status'],
            'customer_name' => $order['customer_name'] ?? '',
            'confirmed' => false,
        ];
        echo json_encode($response);
        exit;
    }

    $conn->begin_transaction();
    $transaction_started = true;

    $stmt1 = $conn->prepare("UPDATE tbl_orders SET order_status = 'completed', rider_id = ?, updated_at = NOW() WHERE id = ? AND order_status IN ('out_for_delivery', 'to_ship', 'to_receive')");
    $stmt1->bind_param('ii', $rider_id, $order_id);
    $stmt1->execute();
    if ($stmt1->affected_rows !== 1) {
        $stmt1->close();
        throw new Exception('The order status changed before confirmation. Please scan again.');
    }
    $stmt1->close();

    // Update delivery status to delivered
    $stmt2 = $conn->prepare(
        "UPDATE tbl_delivery 
         SET status = 'delivered', delivered_at = NOW(), updated_at = NOW(), rider_id = ?
         WHERE id = ?"
    );
    $stmt2->bind_param('ii', $rider_id, $delivery_id);
    $stmt2->execute();
    $stmt2->close();

    // Nullify the token after use to prevent re-scanning
    $clear_stmt = $conn->prepare("UPDATE tbl_delivery SET qr_confirmation_token = NULL WHERE id = ?");
    $clear_stmt->bind_param('i', $delivery_id);
    $clear_stmt->execute();
    $clear_stmt->close();

    loyalty_award_completed_order($conn, $order_id);
    $conn->commit();
    $transaction_started = false;

    // Notify Customer
    $cust_stmt = $conn->prepare("SELECT customer_id FROM tbl_orders WHERE id = ? LIMIT 1");
    $cust_stmt->bind_param('i', $order_id);
    $cust_stmt->execute();
    $cust_res = $cust_stmt->get_result()->fetch_assoc();
    $cust_stmt->close();

    $customer_id = $cust_res ? (int)$cust_res['customer_id'] : 0;

    notifications_create($conn, [
        'user_id' => $customer_id,
        'customer_id' => $customer_id,
        'type' => 'rider_qr_confirm',
        'channel' => 'in_app',
        'title' => 'Delivery confirmed',
        'message' => 'Your delivery has been confirmed by the rider.',
        'reference_table' => 'tbl_orders',
        'reference_id' => $order_id,
        'email_to' => null,
    ]);

    $response['success'] = true;
    $response['message'] = 'Order #' . $order['order_number'] . ' successfully marked as Completed.';
    $response['data'] = [
        'delivery_id' => $delivery_id,
        'order_id' => $order_id,
        'confirmed' => true,
    ];

} catch (Exception $e) {
    if ($transaction_started) {
        $conn->rollback();
    }
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>