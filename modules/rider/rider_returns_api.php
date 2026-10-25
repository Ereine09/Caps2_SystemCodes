<?php
/*
 * Rider Return Pickups API (JSON)
 *
 * GET  - Return pickups this rider can see (see rr_rider_pickups()): approved returns nobody
 *        has claimed yet, plus the ones this rider claimed and hasn't dropped off.
 * POST - body: { "action": "claim" | "release" | "picked_up" | "dropped_off", "id": 12 }
 *        Any rider can claim an open pickup; only the claiming rider can do the later steps.
 *        "dropped_off" marks the returned item as received at the shop (rr_rider_action()).
 *
 * Requires rider JWT authentication (cookie or Bearer), like the other rider APIs, and the
 * page's CSRF token on POST (sent by rider.js as X-CSRF-Token).
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/return_refund_helper.php';

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $token = '';
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (isset($headers['Authorization']) && preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $m)) {
        $token = $m[1];
    }
    if ($token === '') {
        $token = getJWTFromCookie();
    }
    $payload = verifyJWT($token);
    if (!$payload) {
        throw new Exception('Unauthorized access token');
    }
    if (strtolower(trim((string)($payload['role'] ?? ''))) !== 'rider') {
        throw new Exception('Unauthorized role');
    }
    $rider_user_id = (int)($payload['user_id'] ?? 0);
    if ($rider_user_id <= 0) {
        throw new Exception('Invalid rider account session');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_check()) {
            throw new Exception('Your session expired. Please reload the page and try again.');
        }
        $input = json_decode((string) file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];
        $result = rr_rider_action($conn, $rider_user_id, (int)($input['id'] ?? 0), (string)($input['action'] ?? ''));
        if (!$result['ok']) {
            throw new Exception($result['message']);
        }
        $response['success'] = true;
        $response['message'] = $result['message'];
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $response['success'] = true;
        $response['data'] = ['pickups' => rr_rider_pickups($conn, $rider_user_id)];
    } else {
        throw new Exception('GET or POST required');
    }
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
