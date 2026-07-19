<?php
/**
 * Order Creation API
 *
 * Receives checkout data from a mobile/web client and creates an order.
 *
 * POST JSON:
 * {
 *   "customer_id": 1,
 *   "cart": [ { "product_id": 1, "quantity": 2 }, ... ],
 *   "fulfillment_type": "delivery", // or "pickup"
 *   "delivery_address": "...",
 *   "delivery_phone": "...",
 *   "delivery_instructions": "...",
 *   "pickup_date": "...",
 *   "pickup_time": "...",
 *   "payment_method": "gcash", // or "cod", "pay_at_shop"
 *   "gcash_reference_number": "..."
 * }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../customer/includes/functions.php';

$response = [
  'success' => false,
  'message' => '',
  'data' => null
];

try {
    // AUTH: You might want to secure this with JWT, but for now we trust the customer_id from the client.
    // $token = getJWTFromCookie();
    // $payload = verifyJWT($token);
    // if (!$payload) throw new Exception('Unauthorized');
    // $customer_id = (int)$payload['customer_id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST method required');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    // --- Basic Validation ---
    $customer_id = (int)($input['customer_id'] ?? 0);
    $cart = $input['cart'] ?? [];
    $fulfillment_type = $input['fulfillment_type'] ?? 'pickup';
    $payment_method = $input['payment_method'] ?? 'cod';

    // Allowed payment methods
    if (!in_array($payment_method, ['cod', 'gcash', 'pay_at_shop'], true)) {
        throw new Exception('Invalid payment method.');
    }

    // pay_at_shop rules
    if ($payment_method === 'pay_at_shop' && $fulfillment_type !== 'pickup') {
        throw new Exception('Pay at shop is only available for pickup orders.');
    }
    if ($payment_method === 'cod' && $fulfillment_type !== 'delivery') {
        throw new Exception('Cash on delivery is only available for delivery orders.');
    }


    if ($customer_id <= 0) throw new Exception('Invalid customer ID.');
    if (empty($cart)) throw new Exception('Cart cannot be empty.');

    // --- Re-create cart subtotal on the server for security ---
    $subtotal = 0;
    $server_cart = [];
    foreach ($cart as $item) {
        $product = get_product_by_id((int)$item['product_id']);
        if ($product) {
            $item_subtotal = (float)$product['price'] * (int)$item['quantity'];
            $subtotal += $item_subtotal;
            $server_cart[] = [
                'id' => $product['id'],
                'product_id' => $product['id'],
                'name' => $product['name'],
                'unit_price' => (float)$product['price'],
                'quantity' => (int)$item['quantity'],
                'image_url' => $product['image_url']
            ];
        }
    }

    // --- Delivery Fee Calculation (from checkout.php) ---
    $delivery_fee = 0;
    $delivery_address = trim($input['delivery_address'] ?? '');
    if ($fulfillment_type === 'delivery') {
        $addr_lower = strtolower($delivery_address);
        if (strpos($addr_lower, '10th ave') !== false || strpos($addr_lower, '10th avenue') !== false || strpos($addr_lower, 'grace park') !== false) {
            $delivery_fee = 0;
        } elseif (strpos($addr_lower, 'caloocan') !== false) {
            $delivery_fee = ($subtotal >= 2000) ? 0 : 50;
        } else {
            $delivery_fee = 120; // Default for other areas
        }
    }

    // --- Stock Check ---
    foreach ($server_cart as $item) {
        $available_stock = get_product_stock((int)$item['id']);
        if ($item['quantity'] > $available_stock) {
            throw new Exception('Insufficient stock for ' . htmlspecialchars($item['name']) . '. Only ' . $available_stock . ' available.');
        }
    }

    // --- Create Order ---
    $conn->begin_transaction();

    try {
        // 1. Deduct stock
        foreach ($server_cart as $item) {
            if (!deduct_product_stock((int)$item['id'], (int)$item['quantity'])) {
                throw new Exception('Failed to update stock for ' . htmlspecialchars($item['name']));
            }
        }

        // 2. Create the order using the existing function
        $details = [
            'delivery_address' => $delivery_address,
            'delivery_phone' => trim($input['delivery_phone'] ?? ''),
            'delivery_instructions' => trim($input['delivery_instructions'] ?? ''),
            'pickup_date' => trim($input['pickup_date'] ?? ''),
            'pickup_time' => trim($input['pickup_time'] ?? ''),
            'payment_method' => $payment_method,
            'gcash_reference_number' => ($payment_method === 'gcash') ? trim($input['gcash_reference_number'] ?? '') : null,
            'delivery_fee' => $delivery_fee
        ];

        if ($payment_method === 'gcash') {
            $ref = trim((string)($input['gcash_reference_number'] ?? ''));
            if ($ref === '') {
                throw new Exception('GCash reference number is required for GCash payments.');
            }
            if (!preg_match('/^\d{13}$/', $ref)) {
                throw new Exception('Please enter a valid 13-digit GCash reference number.');
            }
        }


        $order_id = create_customer_order($customer_id, $fulfillment_type, $details, $server_cart);

        if ($order_id === null) {
            throw new Exception('Order creation failed.');
        }

        // 3. Award Loyalty Points (simplified from checkout.php)
        $points_earned = round($subtotal / 100, 2);
        if ($points_earned > 0) {
            $transaction_stmt = $conn->prepare("INSERT INTO loyalty_transactions (customer_id, product_name, points_earned, order_id) VALUES (?, ?, ?, ?)");
            $product_name_for_transaction = 'Online Purchase (Order #' . $order_id . ')';
            $transaction_stmt->bind_param("isdi", $customer_id, $product_name_for_transaction, $points_earned, $order_id);
            $transaction_stmt->execute();
            notifications_sync_customer_loyalty_points($conn, $customer_id);
        }

        $conn->commit();

        $response['success'] = true;
        $response['message'] = 'Order placed successfully!';
        $response['data'] = ['order_id' => $order_id];

    } catch (Exception $e) {
        $conn->rollback();
        throw $e; // Re-throw to be caught by the outer catch block
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>