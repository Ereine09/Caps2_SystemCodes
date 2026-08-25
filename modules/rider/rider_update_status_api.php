<?php
/**
 * Rider Update Status API
 *
 * Expected actions:
 * - to_receive
 * - out_for_delivery
 * - completed
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
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/loyalty_helper.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

// Ensure rider schema is up-to-date
ensureRiderSchema($conn);

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

    $delivery_id = (int)($input['delivery_id'] ?? 0);
    $order_id = (int)($input['order_id'] ?? 0);
    $status = strtolower(trim((string)($input['status'] ?? '')));
    $qr_code = trim((string)($input['qr_code'] ?? ''));

    if ($delivery_id <= 0 && $order_id <= 0) {
        throw new Exception('delivery_id or order_id is required');
    }
    if ($status === '') {
        throw new Exception('status is required');
    }

    $allowed = ['to_receive', 'out_for_delivery', 'completed'];
    if (!in_array($status, $allowed, true)) {
        throw new Exception('Invalid status');
    }

    if ($qr_code === '') {
        throw new Exception('Scan the parcel QR code before updating the delivery status.');
    }
    $qr_data = json_decode($qr_code, true);
    if (!is_array($qr_data) || !isset($qr_data['delivery_id'], $qr_data['token'])) {
        throw new Exception('Invalid parcel QR code.');
    }
    $qr_delivery_id = (int)$qr_data['delivery_id'];
    $qr_token = (string)$qr_data['token'];
    if ($delivery_id <= 0) {
        $delivery_id = $qr_delivery_id;
    }
    if ($delivery_id !== $qr_delivery_id || $delivery_id <= 0) {
        throw new Exception('QR code does not match this delivery.');
    }

    $order_status = $status;
    $delivery_status = $status === 'completed' ? 'delivered' : 'in_transit';

    // Load order id if only delivery_id is provided
    if ($order_id <= 0) {
        $stmt = $conn->prepare("SELECT order_id FROM tbl_delivery WHERE id = ? AND qr_confirmation_token = ? LIMIT 1");
        $stmt->bind_param('is', $delivery_id, $qr_token);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res) throw new Exception('Invalid or already used parcel QR code.');
        $order_id = (int)$res['order_id'];
    } else {
        $qr_stmt = $conn->prepare("SELECT order_id FROM tbl_delivery WHERE id = ? AND qr_confirmation_token = ? LIMIT 1");
        $qr_stmt->bind_param('is', $delivery_id, $qr_token);
        $qr_stmt->execute();
        $qr_res = $qr_stmt->get_result()->fetch_assoc();
        $qr_stmt->close();
        if (!$qr_res || ($order_id > 0 && (int)$qr_res['order_id'] !== $order_id)) {
            throw new Exception('QR code does not match this order.');
        }
        $order_id = (int)$qr_res['order_id'];
    }

    $current_order_stmt = $conn->prepare('SELECT order_status FROM tbl_orders WHERE id = ? LIMIT 1');
    $current_order_stmt->bind_param('i', $order_id);
    $current_order_stmt->execute();
    $current_order = $current_order_stmt->get_result()->fetch_assoc();
    $current_order_stmt->close();
    if (!$current_order) {
        throw new Exception('Order not found');
    }
    $transition_map = [
        'to_ship' => 'to_receive',
        'to_receive' => 'out_for_delivery',
        'out_for_delivery' => 'completed',
    ];
    if (($transition_map[$current_order['order_status']] ?? '') !== $order_status) {
        throw new Exception('Invalid delivery status transition. Follow To Ship, To Receive, Out Delivery, then Completed.');
    }

    $auth_stmt = $conn->prepare(
        "SELECT o.rider_id, d.rider_id AS delivery_rider_id
         FROM tbl_orders o JOIN tbl_delivery d ON d.id = ?
         WHERE o.id = ? LIMIT 1"
    );
    $auth_stmt->bind_param('ii', $delivery_id, $order_id);
    $auth_stmt->execute();
    $auth_row = $auth_stmt->get_result()->fetch_assoc();
    $auth_stmt->close();
    $previous_status = (string)$current_order['order_status'];
    $order_rider_id = (int)($auth_row['rider_id'] ?? 0);
    $delivery_rider_id = (int)($auth_row['delivery_rider_id'] ?? 0);
    $is_assigned_rider = $order_rider_id === $rider_id
        || $order_rider_id === $rider_user_id
        || $delivery_rider_id === $rider_user_id;
    $is_unassigned_pickup = $previous_status === 'to_ship'
        && ($order_rider_id === 0 || $order_rider_id === $rider_id);

    if (!$auth_row || (!$is_assigned_rider && !$is_unassigned_pickup)) {
        throw new Exception('You are not authorized to update this delivery.');
    }
    $status_changed = $current_order['order_status'] !== $order_status;

    $conn->begin_transaction();

    // Update tbl_orders
    $stmt1 = $conn->prepare("UPDATE tbl_orders SET order_status = ?, rider_id = ?, updated_at = NOW() WHERE id = ? AND order_status = ?");
    $stmt1->bind_param('siis', $order_status, $rider_id, $order_id, $previous_status);
    $stmt1->execute();
    if ($stmt1->affected_rows !== 1) {
        $stmt1->close();
        throw new Exception('The order status changed before confirmation. Please scan again.');
    }
    $stmt1->close();

    // Update tbl_delivery if delivery_id exists
    if ($delivery_id > 0) {
        $stmt2 = $conn->prepare(
            "UPDATE tbl_delivery
             SET status = ?, delivered_at = CASE WHEN ? = 'delivered' THEN NOW() ELSE delivered_at END,
                 updated_at = NOW()
               WHERE id = ? AND qr_confirmation_token = ?"
        );
        $delStatus = $delivery_status;
        $deliveredFlag = $status === 'completed' ? 'delivered' : 'not_delivered';
           $stmt2->bind_param('ssis', $delStatus, $deliveredFlag, $delivery_id, $qr_token);
        $stmt2->execute();
        $stmt2->close();
    }

    if ($order_status === 'completed') {
        loyalty_award_completed_order($conn, $order_id);
        $clear_qr_stmt = $conn->prepare("UPDATE tbl_delivery SET qr_confirmation_token = NULL WHERE id = ? AND qr_confirmation_token = ?");
        $clear_qr_stmt->bind_param('is', $delivery_id, $qr_token);
        $clear_qr_stmt->execute();
        $clear_qr_stmt->close();
    }
    $conn->commit();

    // Notify customer
    $customer_stmt = $conn->prepare("SELECT customer_id FROM tbl_orders WHERE id = ? LIMIT 1");
    $customer_stmt->bind_param('i', $order_id);
    $customer_stmt->execute();
    $customer_res = $customer_stmt->get_result()->fetch_assoc();
    $customer_stmt->close();

    $customer_id = $customer_res ? (int)$customer_res['customer_id'] : 0;

    if ($customer_id > 0 && $status_changed) {
        notifications_create($conn, [
            'user_id' => $customer_id,
            'customer_id' => $customer_id,
            'type' => 'rider_status_update',
            'channel' => 'in_app',
            'title' => 'Delivery update',
            'message' => 'Your order has been updated by your rider.',
            'reference_table' => 'tbl_orders',
            'reference_id' => $order_id,
            'email_to' => null,
        ]);
    }

    $response['success'] = true;
    $response['data'] = [
        'order_id' => $order_id,
        'delivery_id' => $delivery_id,
        'order_status' => $order_status,
        'delivery_status' => $delivery_status,
    ];

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>