<?php
/**
 * Rider Profile API (JSON)
 *
 * GET  - Fetch the authenticated rider's profile
 *        (username, email, vehicle_type, plate_number, is_on_duty, last_seen)
 * POST - Update the rider's profile
 *        body: { "vehicle_type": "Motorcycle", "plate_number": "ABC123" }
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
    'message' => 'An error occurred.',
    'data' => null,
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
    if (!$payload || ($payload['role'] ?? '') !== 'rider') {
        throw new Exception('Unauthorized access.');
    }

    $rider_user_id = (int)$payload['user_id'];
    if ($rider_user_id <= 0) {
        throw new Exception('Invalid rider account.');
    }

    ensureRiderSchema($conn);

    // Auto-create the riders row if it does not exist yet (same pattern as rider_status_api).
    $rider_id = 0;
    $rider_stmt = $conn->prepare("SELECT id FROM riders WHERE user_id = ? LIMIT 1");
    $rider_stmt->bind_param('i', $rider_user_id);
    $rider_stmt->execute();
    $rider_res = $rider_stmt->get_result()->fetch_assoc();
    $rider_stmt->close();

    if ($rider_res) {
        $rider_id = (int)$rider_res['id'];
    } else {
        $insert_stmt = $conn->prepare("INSERT INTO riders (user_id, is_on_duty) VALUES (?, 0)");
        $insert_stmt->bind_param('i', $rider_user_id);
        $insert_stmt->execute();
        $rider_id = $conn->insert_id;
        $insert_stmt->close();
    }

    if ($rider_id <= 0) {
        throw new Exception('Rider profile not found. Please re-login or contact support.');
    }

    // Make sure the profile columns exist (older databases may not have them).
    $check_vt = $conn->query("SHOW COLUMNS FROM riders LIKE 'vehicle_type'");
    if (!$check_vt || $check_vt->num_rows === 0) {
        $conn->query("ALTER TABLE riders ADD COLUMN vehicle_type VARCHAR(50) DEFAULT NULL COMMENT 'e.g., Motorcycle, Bicycle'");
    }
    $check_pn = $conn->query("SHOW COLUMNS FROM riders LIKE 'plate_number'");
    if (!$check_pn || $check_pn->num_rows === 0) {
        $conn->query("ALTER TABLE riders ADD COLUMN plate_number VARCHAR(20) DEFAULT NULL");
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // --- Fetch profile ---
        $stmt = $conn->prepare(
            "SELECT u.id AS user_id, u.username, u.email,
                    r.id AS rider_id, r.vehicle_type, r.plate_number, r.is_on_duty, r.last_seen
             FROM riders r
             JOIN users u ON r.user_id = u.id
             WHERE r.user_id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $rider_user_id);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$profile) {
            throw new Exception('Rider profile not found.');
        }

        $response['success'] = true;
        $response['message'] = 'Rider profile fetched successfully.';
        $response['data'] = [
            'user_id' => (int)$profile['user_id'],
            'username' => $profile['username'] ?? '',
            'email' => $profile['email'] ?? '',
            'rider_id' => (int)$profile['rider_id'],
            'vehicle_type' => $profile['vehicle_type'] ?? '',
            'plate_number' => $profile['plate_number'] ?? '',
            'is_on_duty' => (bool)(int)($profile['is_on_duty'] ?? 0),
            'last_seen' => $profile['last_seen'] ?? null,
        ];
    } elseif ($method === 'POST') {
        // --- Update profile ---
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            throw new Exception('Invalid JSON payload');
        }

        $vehicle_type = trim((string)($input['vehicle_type'] ?? ''));
        $plate_number = trim((string)($input['plate_number'] ?? ''));

        $update_stmt = $conn->prepare(
            "UPDATE riders SET vehicle_type = ?, plate_number = ?, last_seen = NOW() WHERE user_id = ?"
        );
        $update_stmt->bind_param('ssi', $vehicle_type, $plate_number, $rider_user_id);
        $update_stmt->execute();
        $update_stmt->close();

        $response['success'] = true;
        $response['message'] = 'Profile updated successfully.';
        $response['data'] = [
            'vehicle_type' => $vehicle_type,
            'plate_number' => $plate_number,
        ];
    } else {
        throw new Exception('Method not allowed');
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);

