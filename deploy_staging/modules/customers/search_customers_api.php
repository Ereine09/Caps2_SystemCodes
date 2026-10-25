<?php
/**
 * Customer search API — used by modules/customers/reward_redemption.php's
 * "Search Customer" box (and reusable by any other Admin/Staff page that
 * needs the same lookup) to query the customers table directly instead of
 * filtering an already-rendered list client-side.
 *
 * GET ?q=<search text>
 * Returns: { success: true, customers: [ { id, name, email, loyalty_points }, ... ] }
 *
 * Auth: same JWT/role check as reward_redemption.php — Admin/Staff only,
 * derived from the verified cookie, never from a request parameter.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

$response = ['success' => false, 'message' => '', 'customers' => []];

$token = getJWTFromCookie();
$payload = $token ? verifyJWT($token) : null;
if (!$payload) {
    http_response_code(401);
    $response['message'] = 'Not authenticated.';
    echo json_encode($response);
    exit();
}

$role = strtolower(trim($payload['role'] ?? ''));
if ($role !== 'admin' && $role !== 'staff') {
    http_response_code(403);
    $response['message'] = 'Not authorized.';
    echo json_encode($response);
    exit();
}

$q = trim((string) ($_GET['q'] ?? ''));

// Same authoritative balance calculation used everywhere else in the
// redemption flow: earned - redeemed from the transaction tables, not a
// cached column.
$balance_expr = "((SELECT COALESCE(SUM(points_earned), 0) FROM loyalty_transactions WHERE customer_id = customers.id) -
                  (SELECT COALESCE(SUM(points_used), 0) FROM reward_redemptions WHERE customer_id = customers.id)) AS loyalty_points";

if ($q === '') {
    // Empty query: same "first page" list the main page renders server-side.
    $stmt = $conn->prepare("SELECT id, name, email, $balance_expr FROM customers ORDER BY name ASC LIMIT 50");
    $stmt->execute();
} else {
    $like = '%' . $q . '%';
    $stmt = $conn->prepare("SELECT id, name, email, $balance_expr FROM customers WHERE name LIKE ? OR email LIKE ? ORDER BY name ASC LIMIT 50");
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
}

$result = $stmt->get_result();
$customers = [];
while ($row = $result->fetch_assoc()) {
    $customers[] = [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'loyalty_points' => (float) $row['loyalty_points'],
    ];
}
$stmt->close();

$response['success'] = true;
$response['customers'] = $customers;
echo json_encode($response);
