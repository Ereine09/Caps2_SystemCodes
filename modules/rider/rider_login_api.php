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
require_once __DIR__ . '/../../app/helpers/auth_rules_helper.php';

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
  // Same length limits as every other login (app/helpers/auth_rules_helper.php).
  if (!auth_login_input_ok($username_email, $password)) {
    throw new Exception('Invalid username or password');
  }

  // Match users by username or email
  $stmt = $conn->prepare("SELECT id, username, email, password, role, login_attempts, lock_until FROM users WHERE username = ? OR email = ? LIMIT 1");
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

  // Same lockout as the web logins: 3 wrong passwords -> locked for 5 minutes.
  $now = time();
  $locked_until = !empty($res['lock_until']) ? strtotime((string)$res['lock_until']) : 0;
  if ($locked_until > $now) {
    throw new Exception('Too many failed attempts. Try again in ' . max(1, (int)ceil(($locked_until - $now) / 60)) . ' minute(s).');
  }
  if (!password_verify($password, (string)$res['password'])) {
    $attempts = ($locked_until > 0 ? 0 : (int)($res['login_attempts'] ?? 0)) + 1;
    if ($attempts >= 3) {
      $until = date('Y-m-d H:i:s', $now + 300);
      $lk = $conn->prepare("UPDATE users SET login_attempts = ?, lock_until = ? WHERE id = ?");
      $lk->bind_param('isi', $attempts, $until, $res['id']);
      $lk->execute();
      $lk->close();
      throw new Exception('Too many failed attempts. Try again in 5 minutes.');
    }
    $lk = $conn->prepare("UPDATE users SET login_attempts = ?, lock_until = NULL WHERE id = ?");
    $lk->bind_param('ii', $attempts, $res['id']);
    $lk->execute();
    $lk->close();
    throw new Exception('Invalid username or password. ' . (3 - $attempts) . ' attempt(s) left.');
  }
  $reset = $conn->prepare("UPDATE users SET login_attempts = 0, lock_until = NULL WHERE id = ?");
  $reset->bind_param('i', $res['id']);
  $reset->execute();
  $reset->close();

  $token = generateJWT((int)$res['id'], (string)$res['username'], (string)$res['role']);
  if (!setJWTCookie($token)) {
    http_response_code(409);
    throw new Exception(ACTIVE_SESSION_BLOCKED_MESSAGE);
  }

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
  http_response_code($e->getMessage() === ACTIVE_SESSION_BLOCKED_MESSAGE ? 409 : 400);
}

echo json_encode($response);
?>