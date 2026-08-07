<?php
/**
 * Rider Proof of Delivery API
 *
 * Accepts a captured proof-of-delivery photo (base64) + optional notes for a
 * completed/out-for-delivery order and logs it into the `delivery_tracking`
 * table (status = 'delivered', proof_image_url, notes).
 *
* POST JSON: {
 *   "delivery_id": 123,
 *   "order_id": 456,
 *   "image": "data:image/png;base64,...",  // base64 encoded photo
 *   "notes": "Left at front desk"          // optional
 * }
 *
 * Requires a rider JWT.
 */

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/db_schema_helper.php';

$response = ['success' => false, 'message' => 'An error occurred.', 'data' => null];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    // --- JWT Authentication ---
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

    // Resolve riders.id from users.id
    $rider_id = 0;
    $rider_stmt = $conn->prepare("SELECT id FROM riders WHERE user_id = ? LIMIT 1");
    $rider_stmt->bind_param('i', $rider_user_id);
    $rider_stmt->execute();
    $rider_res = $rider_stmt->get_result()->fetch_assoc();
    $rider_stmt->close();
    if ($rider_res) {
        $rider_id = (int)$rider_res['id'];
    }
    if ($rider_id <= 0) {
        throw new Exception('Rider profile not found.');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $delivery_id = (int)($input['delivery_id'] ?? 0);
    $order_id = (int)($input['order_id'] ?? 0);
    $image_data = trim((string)($input['image'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));

    if ($delivery_id <= 0 && $order_id <= 0) {
        throw new Exception('delivery_id or order_id is required.');
    }
    if ($image_data === '') {
        throw new Exception('Proof of delivery photo is required.');
    }

    // --- Validate / decode the base64 image ---
    // Accept either a full data URI or raw base64.
    $mime = 'image/png';
    if (preg_match('#^data:(image/\w+);base64,(.+)$#s', $image_data, $m)) {
        $mime = $m[1];
        $image_data = $m[2];
    }
    $image_bytes = base64_decode($image_data);
    if ($image_bytes === false || $image_bytes === '') {
        throw new Exception('Invalid image data.');
    }

    $ext = 'png';
    if (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) {
        $ext = 'jpg';
    } elseif (strpos($mime, 'webp') !== false) {
        $ext = 'webp';
    }

    // --- Save the image to uploads/proofs ---
    $upload_dir = __DIR__ . '/../../uploads/proofs/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $filename = 'proof_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $file_path = $upload_dir . $filename;
    if (file_put_contents($file_path, $image_bytes) === false) {
        throw new Exception('Failed to save proof image.');
    }
    $proof_url = 'uploads/proofs/' . $filename;

    // --- Optionally resolve delivery_id from order_id if not provided ---
    if ($delivery_id <= 0) {
        $dstmt = $conn->prepare("SELECT id FROM tbl_delivery WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $dstmt->bind_param('i', $order_id);
        $dstmt->execute();
        $drow = $dstmt->get_result()->fetch_assoc();
        $dstmt->close();
        if ($drow) {
            $delivery_id = (int)$drow['id'];
        }
    }

    // --- Log into delivery_tracking ---
    $track = $conn->prepare(
        "INSERT INTO delivery_tracking (order_id, rider_id, status, notes, proof_image_url, created_at)
         VALUES (?, ?, 'delivered', ?, ?, NOW())"
    );
    $track->bind_param('iiss', $order_id, $rider_id, $notes, $proof_url);
    $track->execute();
    $track_id = $conn->insert_id;
    $track->close();

    $response['success'] = true;
    $response['message'] = 'Proof of delivery uploaded successfully.';
    $response['data'] = [
        'tracking_id' => (int)$track_id,
        'delivery_id' => (int)$delivery_id,
        'order_id' => (int)$order_id,
        'proof_url' => $proof_url,
    ];

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
