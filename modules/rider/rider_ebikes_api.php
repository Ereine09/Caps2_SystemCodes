<?php
/*
 * Rider E-Bikes API (JSON)
 *
 * GET - The e-bikes this rider can take when confirming a pickup (see ebike_rider_choices()):
 *       switched on, Available/In Use, and not out with another rider. The bike the rider
 *       already has on this trip comes first with "mine": true.
 *
 * Requires rider JWT authentication (cookie or Bearer), like the other rider APIs.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/ebike_helper.php';

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('GET required');
    }

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

    $bikes = ebike_rider_choices($conn, $rider_user_id);
    $response['success'] = true;
    $response['data'] = [
        'ebikes' => $bikes,
        // The rider's pick from Profile > My E-Bike (null = none); used automatically at pickup.
        'profile_bike' => ebike_rider_profile_bike($conn, $rider_user_id),
        // Any e-bike registered at all? (If none, pickup doesn't ask for one.)
        'fleet_total' => ebike_fleet_summary($conn)['total'],
    ];
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
