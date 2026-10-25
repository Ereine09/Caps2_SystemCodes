<?php
/*
 * Rider Status API (JSON) - availability: Online / On Break / Offline.
 *
 * GET  -> status, reason, details, changed_at, the reason list, and the rider's active deliveries
 *         (so the portal can warn before going On Break / Offline).
 * POST { "status": "online" | "on_break" | "offline", "reason": "<key>", "details": "..." }
 *      On Break / Offline need a reason (details too when the reason is "other"); admins are notified.
 *      Active deliveries stay assigned - nothing is unassigned automatically.
 * POST { "is_on_duty": true|false }  (older callers) - true = Online; false = Offline, still needs a reason.
 *
 * The rider is always the logged-in account (JWT); availability never logs the rider out.
 * Logic: app/helpers/rider_dispatch_helper.php.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/rider_dispatch_helper.php';

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $token = '';
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (isset($headers['Authorization']) && preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $m)) {
        $token = $m[1];
    }
    if ($token === '') {
        $token = (string) getJWTFromCookie();
    }
    $payload = verifyJWT($token);
    if (!$payload) {
        http_response_code(401);
        throw new Exception('Unauthorized access token');
    }
    if (strtolower(trim((string) ($payload['role'] ?? ''))) !== 'rider') {
        http_response_code(403);
        throw new Exception('Unauthorized role');
    }
    $user_id = (int) ($payload['user_id'] ?? 0);
    if ($user_id <= 0) {
        http_response_code(401);
        throw new Exception('Invalid rider account session');
    }

    $row = rider_profile_row($conn, $user_id);
    if (!$row) {
        throw new Exception('Rider profile not found. Please re-login or contact support.');
    }

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $availability = rider_availability_from_row($row);
        $response['success'] = true;
        $response['message'] = 'Rider status fetched successfully.';
        $response['data'] = array_merge($availability, [
            'rider_id'     => (int) $row['id'],
            'user_id'      => $user_id,
            'vehicle_type' => $row['vehicle_type'] ?? '',
            'plate_number' => $row['plate_number'] ?? '',
            'is_on_duty'   => $availability['status'] !== 'offline',
            'last_seen'    => $row['last_seen'] ?? null,
            'reasons'      => rider_break_reasons(),
            'active'       => rider_active_deliveries($conn, $user_id, (int) $row['id']),
        ]);
    } elseif ($method === 'POST') {
        // Browser calls carry the page's CSRF token (Bearer-token API clients have no cookie to forge).
        if (!isset($headers['Authorization']) && !csrf_check()) {
            http_response_code(403);
            throw new Exception('Your session expired. Please reload the page.');
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            throw new Exception('Invalid JSON payload');
        }
        if (isset($input['status'])) {
            $status = strtolower(trim((string) $input['status']));
        } else {
            $status = !empty($input['is_on_duty']) ? 'online' : 'offline';
        }
        $result = rider_availability_set($conn, $user_id, $status, (string) ($input['reason'] ?? ''), (string) ($input['details'] ?? ''));
        if (!$result['ok']) {
            http_response_code(422);
            throw new Exception($result['message']);
        }
        $response['success'] = true;
        $response['message'] = $result['message'];
        $response['data'] = array_merge($result['availability'], [
            'is_on_duty' => $result['availability']['status'] !== 'offline',
            'active'     => $result['active'],
        ]);
    } else {
        http_response_code(405);
        throw new Exception('Method not allowed');
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    if (http_response_code() < 400) {
        http_response_code(400);
    }
}

echo json_encode($response);
