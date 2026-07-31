<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

$response = [
    'success' => false,
    'reviews' => [],
    'message' => ''
];

$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

if ($product_id > 0) {
    try {
        $response['reviews'] = get_product_reviews($product_id, true); // Only get approved reviews
        $response['success'] = true;
    } catch (Exception $e) {
        $response['message'] = 'An error occurred while fetching reviews.';
    }
} else {
    $response['message'] = 'Invalid product ID.';
}

echo json_encode($response);