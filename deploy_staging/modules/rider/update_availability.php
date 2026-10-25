<?php
/**
 * Update Rider Availability API
 *
 * POST JSON: { "availability": "available" | "unavailable" }
 * Requires rider JWT authentication.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null,
];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('POST method required.');
    }

    ensureRiderSchema($conn);

    $token = '';
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }

    if (empty($token)) {
        $token = getJWTFromCookie();
    }

    if (empty($token)) {
        http_response_code(401);
        throw new Exception('Authorization token not found.');
    }

    $payload = verifyJWT($token);
    if (!$payload || !isset($payload['role']) || strtolower($payload['role']) !== 'rider') {
        http_response_code(403);
        throw new Exception('Unauthorized. Rider role required.');
    }

    $rider_user_id = (int)($payload['user_id'] ?? 0);
    if ($rider_user_id <= 0) {
        throw new Exception('Invalid rider user ID in token.');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || !isset($input['availability'])) {
        throw new Exception('Invalid JSON payload. "availability" is required.');
    }

    $availability = strtolower(trim((string)$input['availability']));

    if (!in_array($availability, ['available', 'unavailable'])) {
        throw new Exception('Invalid availability status. Must be "available" or "unavailable".');
    }

    $stmt = $conn->prepare("UPDATE riders SET availability_status = ? WHERE user_id = ?");
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    $stmt->bind_param('si', $availability, $rider_user_id);

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Availability status updated successfully.';
        $response['data'] = ['availability' => $availability];
    } else {
        throw new Exception('Failed to update availability status.');
    }

    $stmt->close();

} catch (Exception $e) {
    if ($response['message'] === '') {
        $response['message'] = $e->getMessage();
    }
}

echo json_encode($response);
?>