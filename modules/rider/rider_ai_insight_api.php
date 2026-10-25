<?php
/**
 * Rider AI performance insight endpoint.
 * Returns a short Gemini strategy using only the authenticated rider's metrics.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

$response = ['success' => false, 'message' => 'An error occurred.', 'data' => null];

try {
    require_once __DIR__ . '/../../app/config/config.php';
    require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
    require_once __DIR__ . '/../../app/helpers/gemini_helper.php';

    $token = '';
    $headers = getallheaders();
    if (isset($headers['Authorization']) && preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
        $token = $matches[1];
    }
    if ($token === '') {
        $token = getJWTFromCookie();
    }

    $payload = verifyJWT($token);
    if (!$payload || ($payload['role'] ?? '') !== 'rider') {
        throw new Exception('Unauthorized access.');
    }

    $rider_user_id = (int)($payload['user_id'] ?? 0);
    if ($rider_user_id <= 0) {
        throw new Exception('Invalid rider account.');
    }

    $rider_stmt = $conn->prepare('SELECT id FROM riders WHERE user_id = ? LIMIT 1');
    $rider_stmt->bind_param('i', $rider_user_id);
    $rider_stmt->execute();
    $rider = $rider_stmt->get_result()->fetch_assoc();
    $rider_stmt->close();
    $rider_id = (int)($rider['id'] ?? 0);
    if ($rider_id <= 0) {
        throw new Exception('Rider profile not found.');
    }

    $stats_stmt = $conn->prepare(
        "SELECT
            COUNT(CASE WHEN o.order_status = 'completed' THEN 1 END) AS completed_deliveries,
            COALESCE(SUM(CASE WHEN o.order_status = 'completed' THEN COALESCE(o.delivery_fee, 0) + COALESCE(o.tip, 0) ELSE 0 END), 0) AS total_earnings,
            COUNT(CASE WHEN o.order_status NOT IN ('completed', 'cancelled') THEN 1 END) AS active_deliveries,
            COUNT(CASE WHEN o.order_status = 'completed' AND o.payment_method = 'cod' AND o.payment_settled = 0 THEN 1 END) AS unsettled_cod_deliveries
         FROM tbl_orders o
         WHERE o.rider_id = ?"
    );
    $stats_stmt->bind_param('i', $rider_id);
    $stats_stmt->execute();
    $stats = $stats_stmt->get_result()->fetch_assoc() ?: [];
    $stats_stmt->close();

    $insight = getGeminiBusinessInsight([
        'context' => 'rider_strategy',
        'completed_deliveries' => (int)($stats['completed_deliveries'] ?? 0),
        'active_deliveries' => (int)($stats['active_deliveries'] ?? 0),
        'unsettled_cod_deliveries' => (int)($stats['unsettled_cod_deliveries'] ?? 0),
        'total_earnings' => (float)($stats['total_earnings'] ?? 0),
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Rider AI insight fetched successfully.',
        'data' => ['insight' => $insight],
    ]);
} catch (Throwable $e) {
    http_response_code($e->getMessage() === 'Unauthorized access.' ? 401 : 500);
    $response['message'] = $e->getMessage();
    echo json_encode($response);
}
