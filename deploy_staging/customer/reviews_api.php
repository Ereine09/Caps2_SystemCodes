<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/auth.php';

$response = [
    'success' => false,
    'summary' => null,
    'reviews' => [],
    'message' => ''
];

// Same audience as the products page: signed-in customers only.
if (!customer_is_logged_in()) {
    http_response_code(401);
    $response['message'] = 'Please sign in to view reviews.';
    echo json_encode($response);
    exit();
}

$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

if ($product_id > 0) {
    try {
        $rows = get_product_reviews($product_id, true); // Only get approved reviews

        // Whitelist the public fields: reviewer names are masked and no customer
        // id / email / order id is ever sent to other customers.
        $response['reviews'] = array_map(static function ($r) {
            return [
                'id'         => (int)$r['id'],
                'name'       => mask_review_name($r['customer_name'] ?? ''),
                'rating'     => (int)$r['rating'],
                'text'       => (string)($r['review_text'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
                'variation'  => (string)($r['variation'] ?? ''),
                'images'     => $r['images'] ?? [],
                // The shop's public reply (text + dates only; no admin identity is exposed).
                'reply'      => !empty($r['reply']) ? [
                    'text'       => (string)$r['reply']['text'],
                    'created_at' => (string)$r['reply']['created_at'],
                    'edited'     => !empty($r['reply']['updated_at']),
                ] : null,
            ];
        }, $rows);
        $response['summary'] = summarize_reviews($rows);
        $response['success'] = true;
    } catch (Exception $e) {
        $response['message'] = 'An error occurred while fetching reviews.';
    }
} else {
    $response['message'] = 'Invalid product ID.';
}

echo json_encode($response);
