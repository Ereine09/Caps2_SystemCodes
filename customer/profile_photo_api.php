<?php
/**
 * Customer profile photo (JSON, POST + CSRF).
 *   { "action": "set", "image": "data:image/jpeg;base64,..." }  -> { success, url }
 *   { "action": "remove" }                                     -> { success }
 * Storage/validation: app/helpers/profile_photo_helper.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/profile_photo_helper.php';

if (!customer_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in again.']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit();
}
csrf_require();

$customer = current_customer();
$customer_id = (int) ($customer['id'] ?? 0);
$input = json_decode(file_get_contents('php://input'), true);
$action = is_array($input) ? (string) ($input['action'] ?? '') : '';

if ($action === 'set') {
    $result = profile_photo_save($conn, 'customer', $customer_id, (string) ($input['image'] ?? ''));
    echo json_encode($result['ok']
        ? ['success' => true, 'url' => BASE_URL . '/' . $result['url'], 'message' => 'Profile photo updated.']
        : ['success' => false, 'message' => $result['error']]);
} elseif ($action === 'remove') {
    $ok = profile_photo_remove($conn, 'customer', $customer_id);
    echo json_encode(['success' => $ok, 'message' => $ok ? 'Profile photo removed.' : 'Could not remove the photo.']);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
