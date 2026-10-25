<?php
/*
 * Active Cart Activity API (JSON) — Admin/Staff.
 *
 * Read-only view of the EXISTING tbl_cart table — no new table, no schema
 * change. "Active Cart Activity" is simply every row currently in tbl_cart
 * that the EXISTING abandoned-cart threshold (24h, see the Abandoned Carts
 * panel in modules/customers/customers.php) has not yet classified as
 * abandoned. Same table, same JOINs, same "last activity" calculation as
 * that existing query — just the inverse HAVING condition — so a cart can
 * never appear in both lists, and the moment it crosses the 24h threshold it
 * disappears from here and only shows up there. Nothing here writes to the
 * cart, orders, or notifications - this is visibility only.
 *
 * Reused by BOTH modules/admin/active_carts.php and
 * modules/staff/active_carts.php (via the shared window.__DPS_BASE_URL
 * absolute-path bridge already used project-wide) - one API, gated for
 * either role, exactly like modules/admin/notifications_api.php already is.
 *
 * GET ?action=get_list             -> every active/idle cart, newest first
 * GET ?action=get_detail&customer_id=N -> one customer's itemized cart
 *
 * Requires an admin/staff JWT (cookie jwt_token_staff), same as every other
 * admin/staff endpoint in this project.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null,
];

// Must match the Abandoned Carts panel's own threshold exactly (modules/
// customers/customers.php) so the two views are always perfect complements
// of each other with zero gap/overlap. That panel doesn't expose this as a
// constant to import, so it's mirrored here as the same literal value - if
// that threshold is ever changed, update both places.
const ACTIVE_CART_ABANDON_THRESHOLD_HOURS = 24;
// A cart is "just touched" (green/Active) inside this many minutes; anything
// older than that but still under the abandonment threshold is "Idle"
// (amber) - purely a presentational split, not a business rule, and not
// used anywhere else.
const ACTIVE_CART_RECENT_MINUTES = 10;

try {
    $token = getJWTFromCookie();
    $payload = $token ? verifyJWT($token) : null;
    if (!$payload) {
        throw new Exception('Unauthorized');
    }

    $role = strtolower(trim((string) ($payload['role'] ?? '')));
    if (!in_array($role, ['admin', 'staff'], true)) {
        throw new Exception('Unauthorized role');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Method not allowed');
    }

    $action = $_GET['action'] ?? '';

    if ($action === 'get_list') {
        require_once __DIR__ . '/../../app/helpers/promo_helper.php';
        promo_ensure_schema($conn); // cart totals use the sale price while a promo runs
        $stmt = $conn->prepare(
            "SELECT
                c.customer_id,
                cu.name AS customer_name,
                cu.email AS customer_email,
                COUNT(*) AS product_count,
                SUM(c.quantity) AS total_quantity,
                SUM(c.quantity * " . promo_price_sql('COALESCE(v.price, p.price)') . ") AS cart_total,
                GROUP_CONCAT(CONCAT(p.name, IF(v.size IS NOT NULL, CONCAT(' (', v.size, ')'), ''), ' (x', c.quantity, ')') SEPARATOR '<br>') AS items_summary,
                MAX(COALESCE(c.updated_at, c.created_at)) AS last_activity
            FROM tbl_cart c
            JOIN customers cu ON cu.id = c.customer_id
            JOIN tbl_product_inventory p ON p.id = c.product_id
            LEFT JOIN tbl_product_variants v ON v.id = c.variant_id
            GROUP BY c.customer_id, cu.name, cu.email
            HAVING last_activity > DATE_SUB(NOW(), INTERVAL " . ACTIVE_CART_ABANDON_THRESHOLD_HOURS . " HOUR)
            ORDER BY last_activity DESC"
        );
        $stmt->execute();
        $result = $stmt->get_result();

        $carts = [];
        $recent_cutoff = time() - (ACTIVE_CART_RECENT_MINUTES * 60);
        while ($row = $result->fetch_assoc()) {
            $last_activity_ts = strtotime($row['last_activity']);
            $carts[] = [
                'customer_id' => (int) $row['customer_id'],
                'customer_name' => $row['customer_name'],
                'customer_email' => $row['customer_email'],
                'product_count' => (int) $row['product_count'],
                'total_quantity' => (int) $row['total_quantity'],
                'cart_total' => (float) $row['cart_total'],
                'items_summary' => $row['items_summary'],
                'last_activity' => $row['last_activity'],
                'last_activity_iso' => date('c', $last_activity_ts),
                // 'active' vs 'idle' only - never 'abandoned' here by
                // construction (see the HAVING clause above): once a cart
                // crosses the threshold it simply stops appearing in this
                // list at all, so this status can never be shown stale.
                'status' => $last_activity_ts >= $recent_cutoff ? 'active' : 'idle',
            ];
        }
        $stmt->close();

        $response['success'] = true;
        $response['data'] = [
            'carts' => $carts,
            'count' => count($carts),
        ];
    } elseif ($action === 'get_detail') {
        $customer_id = (int) ($_GET['customer_id'] ?? 0);
        if ($customer_id <= 0) {
            throw new Exception('Invalid customer_id.');
        }

        $cust_stmt = $conn->prepare("SELECT id, name, email FROM customers WHERE id = ?");
        $cust_stmt->bind_param('i', $customer_id);
        $cust_stmt->execute();
        $customer = $cust_stmt->get_result()->fetch_assoc();
        $cust_stmt->close();
        if (!$customer) {
            throw new Exception('Customer not found.');
        }

        $item_stmt = $conn->prepare(
            "SELECT
                p.name AS product_name,
                v.size,
                COALESCE(v.price, p.price) AS unit_price,
                c.quantity,
                p.image_url,
                COALESCE(c.updated_at, c.created_at) AS line_activity
            FROM tbl_cart c
            JOIN tbl_product_inventory p ON p.id = c.product_id
            LEFT JOIN tbl_product_variants v ON v.id = c.variant_id
            WHERE c.customer_id = ?
            ORDER BY line_activity DESC"
        );
        $item_stmt->bind_param('i', $customer_id);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();

        $items = [];
        $cart_total = 0.0;
        $last_activity = null;
        while ($row = $item_result->fetch_assoc()) {
            $unit_price = (float) $row['unit_price'];
            $quantity = (int) $row['quantity'];
            $cart_total += $unit_price * $quantity;
            if ($last_activity === null || $row['line_activity'] > $last_activity) {
                $last_activity = $row['line_activity'];
            }
            $items[] = [
                'product_name' => $row['product_name'],
                'size' => $row['size'],
                'unit_price' => $unit_price,
                'quantity' => $quantity,
                'subtotal' => $unit_price * $quantity,
                'image_url' => $row['image_url'],
            ];
        }
        $item_stmt->close();

        // The cart may have just been checked out or cleared between the
        // list poll and this click (genuinely empty now, not an error) -
        // report it plainly so the UI can show "no longer active" instead of
        // a confusing empty table.
        $response['success'] = true;
        $response['data'] = [
            'customer' => [
                'id' => (int) $customer['id'],
                'name' => $customer['name'],
                'email' => $customer['email'],
            ],
            'items' => $items,
            'cart_total' => $cart_total,
            'last_activity' => $last_activity,
            'is_active' => count($items) > 0,
        ];
    } else {
        throw new Exception('Invalid action specified.');
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
