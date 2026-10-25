<?php
/**
 * Floating mini cart in the customer top bar (includes/header.php + includes/footer.php).
 *   GET  -> the cart's items, item count and subtotals as JSON.
 *   POST action=remove (product_id, variant_id, CSRF token) -> removes one line, then the same JSON.
 * The full cart page (cart.php) is still where quantities are changed and checkout starts.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$customer = current_customer();
if (!$customer) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in again.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session expired. Please reload the page.']);
        exit();
    }
    if (($_POST['action'] ?? '') === 'remove') {
        $variant_id = !empty($_POST['variant_id']) ? (int) $_POST['variant_id'] : null;
        remove_customer_cart_item((int) $customer['id'], (int) ($_POST['product_id'] ?? 0), $variant_id);
    }
}

// Always the real cart, never a pending Buy Now item. The Buy Now flag is only set aside for
// this read (not cleared), so a Buy Now checkout open in another tab is left alone.
$buy_now = $_SESSION['buy_now_item'] ?? null;
unset($_SESSION['buy_now_item']);

$items = [];
foreach (get_customer_cart() as $item) {
    $items[] = [
        'product_id' => (int) $item['id'],
        'variant_id' => (int) ($item['variant_id'] ?? 0),
        'name'       => (string) $item['name'],
        'quantity'   => (int) $item['quantity'],
        'unit_price' => (float) $item['unit_price'],
        'line_total' => round((float) $item['unit_price'] * (int) $item['quantity'], 2),
        'image'      => (string) ($item['image_url'] ?: BASE_URL . '/assets/img/placeholder.png'),
    ];
}

$payload = [
    'success'        => true,
    'items'          => $items,
    'count'          => cart_item_count(),
    'subtotal_net'   => round(cart_subtotal_ex_vat(), 2),
    'subtotal_gross' => round(cart_subtotal(), 2),
];
if ($buy_now !== null) {
    $_SESSION['buy_now_item'] = $buy_now;
}
echo json_encode($payload);
