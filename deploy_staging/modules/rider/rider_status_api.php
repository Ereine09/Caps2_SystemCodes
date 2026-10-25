<?php
/*
 * Rider Status API (JSON)
 *
 * GET  - Fetch current rider status (is_on_duty, vehicle_type, plate_number, last_seen)
 * POST - Toggle rider on-duty status
 *
 * POST JSON:
 * { "is_on_duty": true|false }
 *
 * Requires rider JWT authentication.
 */

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    // --- Authentication ---
    $token = '';
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }

    if (empty($token)) {
        $token = getJWTFromCookie();
    }

    $payload = verifyJWT($token);
    if (!$payload) {
        throw new Exception('Unauthorized access token');
    }

    $role = strtolower(trim((string)($payload['role'] ?? '')));
    if ($role !== 'rider') {
        throw new Exception('Unauthorized role');
    }

    $rider_user_id = (int)($payload['user_id'] ?? 0);
    if ($rider_user_id <= 0) {
        throw new Exception('Invalid rider account session');
    }

    ensureRiderSchema($conn);

    // --- Look up riders.id from users.id ---
    $rider_id = 0;
    $rider_stmt = $conn->prepare("SELECT id FROM riders WHERE user_id = ? LIMIT 1");
    $rider_stmt->bind_param('i', $rider_user_id);
    $rider_stmt->execute();
    $rider_res = $rider_stmt->get_result()->fetch_assoc();
    $rider_stmt->close();

    if ($rider_res) {
        $rider_id = (int)$rider_res['id'];
    }

    // Auto-create rider profile if missing
    if ($rider_id <= 0) {
        $insert_stmt = $conn->prepare("INSERT INTO riders (user_id, is_on_duty) VALUES (?, 0)");
        $insert_stmt->bind_param('i', $rider_user_id);
        $insert_stmt->execute();
        $rider_id = $conn->insert_id;
        $insert_stmt->close();
    }

    if ($rider_id <= 0) {
        throw new Exception('Rider profile not found. Please re-login or contact support.');
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // --- Fetch current status ---
        $stmt = $conn->prepare(
            "SELECT id, user_id, vehicle_type, plate_number, is_on_duty, last_seen
             FROM riders WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $rider_id);
        $stmt->execute();
        $rider = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$rider) {
            throw new Exception('Rider profile not found.');
        }

        $response['success'] = true;
        $response['message'] = 'Rider status fetched successfully.';
        $response['data'] = [
            'rider_id' => (int)$rider['id'],
            'user_id' => (int)$rider['user_id'],
            'vehicle_type' => $rider['vehicle_type'] ?? '',
            'plate_number' => $rider['plate_number'] ?? '',
            'is_on_duty' => (bool)(int)($rider['is_on_duty'] ?? 0),
            'last_seen' => $rider['last_seen'] ?? null
        ];
    } elseif ($method === 'POST') {
        // --- Toggle on-duty status ---
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            throw new Exception('Invalid JSON payload');
        }

        $is_on_duty = (bool)($input['is_on_duty'] ?? false);
        $on_duty_int = $is_on_duty ? 1 : 0;

        $update_stmt = $conn->prepare(
            "UPDATE riders SET is_on_duty = ?, last_seen = NOW() WHERE id = ?"
        );
        $update_stmt->bind_param('ii', $on_duty_int, $rider_id);
        $update_stmt->execute();
        $update_stmt->close();

        $response['success'] = true;
        $response['message'] = $is_on_duty ? 'You are now ONLINE and available for deliveries.' : 'You are now OFFLINE. No new deliveries will be assigned.';
        $response['data'] = [
            'rider_id' => $rider_id,
            'is_on_duty' => $is_on_duty
        ];
    } else {
        throw new Exception('Method not allowed');
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);

