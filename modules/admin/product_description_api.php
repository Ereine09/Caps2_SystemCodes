<?php
/**
 * POST (form fields of the product form) -> {"success":true,"data":{"text":"...","source":"ai|template"}}
 * Used by the Generate button next to Description on the admin and staff Products pages.
 * Admin / staff only, CSRF-checked.
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/product_description_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$payload = verifyJWT((string) getJWTFromCookie());
$role = strtolower(trim((string) ($payload['role'] ?? '')));
if (!$payload || !in_array($role, ['admin', 'staff'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please reload the page.']);
    exit();
}
if (trim((string) ($_POST['name'] ?? '')) === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Type the product name first.']);
    exit();
}
echo json_encode(['success' => true, 'data' => product_description_generate($_POST)]);
