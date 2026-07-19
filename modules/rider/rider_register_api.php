<?php
/**
 * Rider Registration API (JSON)
 *
 * POST JSON:
 * { "username": "...", "email": "...", "password": "..." }
 *
 * It registers the rider into the database with a default role of 'rider'.
 */

// --- CORS & Preflight Headers ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/config/config.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $username = trim((string)($input['username'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($username === '' || $email === '' || $password === '') {
        throw new Exception('Username, email, and password are required');
    }

    // I-check kung may kaparehong username o email na sa database
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
    $stmt->bind_param('ss', $username, $email);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res) {
        throw new Exception('Username or Email is already taken');
    }

    // I-hash ang password gamit ang bcrypt
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
    $role = 'rider';

    // I-insert ang bagong account sa table (i-adjust ang columns depende sa iyong table setup)
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('ssss', $username, $email, $hashed_password, $role);
    
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Account registered successfully as a Rider!';
    } else {
        throw new Exception('Database error during registration');
    }
    $stmt->close();

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>