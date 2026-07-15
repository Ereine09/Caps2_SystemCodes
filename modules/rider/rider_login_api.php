<?php
/**
 * Rider Login API (JSON)
 *
 * POST JSON:
 * { "username_email": "...", "password": "..." }
 *
 * If credentials are valid, returns:
 * { success: true, data: { token: "jwt...", role: "rider" } }
 *
 * NOTE: This issues JWT and sets JWT cookies using existing helper.
 * Your users table must have a row with role='rider'.
 */

// --- CORS & Preflight Headers Start ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
// --- CORS & Preflight Headers End ---

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

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

  $username_email = trim((string)($input['username_email'] ?? ''));
  $password = (string)($input['password'] ?? '');

  if ($username_email === '' || $password === '') {
    throw new Exception('username_email and password are required');
  }

  // Match users by username or email
  $stmt = $conn->prepare("SELECT id, username, email, password, role FROM users WHERE username = ? OR email = ? LIMIT 1");
  $stmt->bind_param('ss', $username_email, $username_email);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$res) {
    throw new Exception('Invalid username or password');
  }

  if (strtolower(trim((string)($res['role'] ?? ''))) !== 'rider') {
    throw new Exception('Not a rider account');
  }

  if (!password_verify($password, (string)$res['password'])) {
    throw new Exception('Invalid username or password');
  }

  $token = generateJWT((int)$res['id'], (string)$res['username'], (string)$res['role']);
  setJWTCookie($token);

  $response['success'] = true;
  $response['data'] = [
    'token' => $token,
    'role' => 'rider',
    'user_id' => (int)$res['id'],
    'username' => (string)$res['username']
  ];
}

catch (Exception $e) {
  $response['success'] = false;
  $response['message'] = $e->getMessage();
  http_response_code(400);
}

echo json_encode($response);
?>