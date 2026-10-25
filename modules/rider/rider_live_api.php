<?php
/**
 * Rider live change probe (read-only, cheap) for Rider.live() in rider/assets/rider.js.
 *
 *   GET rider_live_api.php?scopes=deliveries,order:12
 *   -> { "success": true, "data": { "deliveries": "9f2c…", "order:12": "1ab0…" } }
 *
 * Each value is a short fingerprint; the page re-reads its data only when it changes, so a new
 * To Ship order, an order another rider just accepted, or an admin assignment shows within seconds.
 * Unknown scopes answer 'na' (the page then relies on its slower safety refresh).
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

$payload = verifyJWT((string) getJWTFromCookie());
if (!$payload || strtolower(trim((string) ($payload['role'] ?? ''))) !== 'rider' || (int) ($payload['user_id'] ?? 0) <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access token']);
    exit();
}
$user_id = (int) $payload['user_id'];
$r = $conn->prepare("SELECT id FROM riders WHERE user_id = ? LIMIT 1");
$r->bind_param('i', $user_id);
$r->execute();
$riders_id = (int) ($r->get_result()->fetch_assoc()['id'] ?? 0);
$r->close();

$sig = static function (mysqli $conn, string $sql, string $types = '', array $args = []): string {
    try {
        $st = $conn->prepare($sql);
        if ($types !== '') {
            $st->bind_param($types, ...$args);
        }
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ? substr(md5(json_encode($row)), 0, 12) : 'na';
    } catch (Throwable $e) {
        return 'na';
    }
};

$out = [];
foreach (array_filter(array_map('trim', explode(',', (string) ($_GET['scopes'] ?? '')))) as $scope) {
    if ($scope === 'deliveries') {
        // Every delivery order a rider could see (available To Ship + anyone's assignments), and my availability.
        $out[$scope] = $sig($conn,
            "SELECT
               (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', o.id, o.order_status, COALESCE(o.rider_id,0), COALESCE(o.payment_settled,0)))),0)
                  FROM tbl_orders o WHERE o.fulfillment_type = 'delivery' AND o.order_status IN ('to_ship','to_receive','out_for_delivery','completed','cancelled')) os,
               (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', d.id, COALESCE(d.rider_id,0), d.status))),0) FROM tbl_delivery d) ds,
               (SELECT CONCAT_WS('|', COALESCE(is_on_duty,0), availability_status) FROM riders WHERE id = ?) me",
            'i', [$riders_id]);
    } elseif (preg_match('/^order:(\d+)$/', $scope, $m)) {
        $oid = (int) $m[1];
        $out[$scope] = $sig($conn,
            "SELECT o.order_status, COALESCE(o.rider_id,0) r, o.payment_settled, o.updated_at,
                    (SELECT CONCAT_WS('|', COALESCE(d.rider_id,0), d.status, COALESCE(d.updated_at,'')) FROM tbl_delivery d WHERE d.order_id = o.id ORDER BY d.id DESC LIMIT 1) dl
             FROM tbl_orders o WHERE o.id = ?",
            'i', [$oid]);
    } else {
        $out[$scope] = 'na';
    }
}
echo json_encode(['success' => true, 'data' => $out]);
