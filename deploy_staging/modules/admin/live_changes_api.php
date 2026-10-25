<?php
/**
 * Live change probe (read-only, very cheap).
 *
 *   GET live_changes_api.php?scopes=orders,reviews
 *   -> { "success": true, "data": { "orders": "9f2c...", "reviews": "1ab0..." } }
 *
 * Each value is a short fingerprint of what the matching admin page displays. The page keeps the last
 * fingerprint it saw and only re-reads its list (through its own existing code) when the fingerprint
 * CHANGES - so an idle page costs one tiny request every few seconds instead of downloading and
 * re-comparing the whole page each time. Nothing here changes data, and no row content is returned.
 *
 * Same authentication as the other admin APIs (JWT cookie); admin-only scopes stay admin-only.
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/ajax_helper.php';

$token = getJWTFromCookie();
$payload = $token ? verifyJWT($token) : null;
$role = $payload ? strtolower(trim((string)($payload['role'] ?? ''))) : '';
if (!$payload || !in_array($role, ['admin', 'staff'], true)) {
    ajax_json(['success' => false, 'code' => 'auth', 'message' => 'Your session has expired. Please log in again.'], 401);
}

// scope => roles that may watch it (mirrors who can open the page)
$allowed = [
    'orders'     => ['admin', 'staff'],
    'delivery'   => ['admin'],
    'reviews'    => ['admin', 'staff'],
    'remittance' => ['admin'],
    'customers'  => ['admin', 'staff'],
];

/** One SELECT that returns a single row of aggregates; the row is hashed into the fingerprint. */
$queries = [
    // orders: count, newest id, every status/payment/rider/total/updated change, and how many were opened (sidebar badge)
    'orders' => "SELECT COUNT(*) c, COALESCE(MAX(id),0) mx,
                        COALESCE(SUM(CRC32(CONCAT_WS('|', id, order_status, payment_settled, COALESCE(rider_id,0), total, COALESCE(payment_method,''), COALESCE(updated_at,'')))),0) s,
                        COALESCE(SUM(admin_viewed_at IS NOT NULL),0) v
                 FROM tbl_orders",
    // supplier deliveries + supplier directory (what delivery.php lists) + the order headline numbers it shows
    'delivery' => "SELECT
                     (SELECT COUNT(*) FROM tbl_supplier_deliveries) dc,
                     (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, COALESCE(supplier_id,0), COALESCE(tracking_number,''), COALESCE(expected_date,''), COALESCE(received_date,''), COALESCE(updated_at,''), items_summary))),0) FROM tbl_supplier_deliveries) ds,
                     (SELECT COUNT(*) FROM tbl_suppliers) sc,
                     (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', id, supplier_name, COALESCE(brand_name,''), COALESCE(email,''), COALESCE(phone,''), is_active, COALESCE(updated_at,''), COALESCE(contact_person,''), COALESCE(address,'')))),0) FROM tbl_suppliers) ss,
                     (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', id, order_status, total))),0) FROM tbl_orders) os",
    // reviews and the admin replies shown under them
    'reviews' => "SELECT
                     (SELECT COUNT(*) FROM tbl_product_reviews) rc,
                     (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', id, rating, is_approved, COALESCE(review_text,'')))),0) FROM tbl_product_reviews) rs,
                     (SELECT COUNT(*) FROM tbl_review_replies) pc,
                     (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', id, review_id, COALESCE(updated_at,created_at), reply_text))),0) FROM tbl_review_replies) ps",
    // remittance records + the completed COD orders still waiting to be handed over (the "Awaiting Handover" rows)
    'remittance' => "SELECT
                     (SELECT COUNT(*) FROM tbl_rider_remittances) rc,
                     (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, amount, COALESCE(received_by_user_id,0), COALESCE(processed_by_user_id,0), COALESCE(received_at,''), COALESCE(processed_at,''), COALESCE(discrepancy_reason,''), COALESCE(notes,'')))),0) FROM tbl_rider_remittances) rs,
                     (SELECT COUNT(*) FROM tbl_orders WHERE payment_method = 'cod' AND order_status = 'completed' AND payment_settled = 0) ac,
                     (SELECT COALESCE(SUM(total),0) FROM tbl_orders WHERE payment_method = 'cod' AND order_status = 'completed' AND payment_settled = 0) ato",
    // customer records, their activity status (recent orders) and the carts listed on the page
    'customers' => "SELECT
                     (SELECT COUNT(*) FROM customers) cc,
                     (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', id, name, email, COALESCE(phone,''), COALESCE(gender,''), COALESCE(age,''), loyalty_points))),0) FROM customers) cs,
                     (SELECT COUNT(*) FROM tbl_orders) oc,
                     (SELECT COALESCE(MAX(created_at),'') FROM tbl_orders) om,
                     (SELECT COUNT(*) FROM tbl_cart) kc,
                     (SELECT COALESCE(MAX(COALESCE(updated_at, created_at)),'') FROM tbl_cart) km,
                     (SELECT COALESCE(SUM(quantity),0) FROM tbl_cart) kq",
];

$wanted = array_filter(array_map('trim', explode(',', (string)($_GET['scopes'] ?? ''))));
$out = [];
foreach ($wanted as $scope) {
    if (!isset($queries[$scope]) || !in_array($role, $allowed[$scope], true)) {
        continue;                         // unknown or not permitted: simply not answered
    }
    try {
        $res = $conn->query($queries[$scope]);
        $row = $res ? $res->fetch_assoc() : null;
        $out[$scope] = $row ? substr(md5(json_encode($row)), 0, 12) : 'na';
    } catch (Throwable $e) {
        // e.g. a table that is created lazily by its page and does not exist yet
        $out[$scope] = 'na';
    }
}

ajax_json(['success' => true, 'data' => $out]);
