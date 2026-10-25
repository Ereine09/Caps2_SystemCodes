<?php
/**
 * Tiny JSON endpoint used by customer/checkout.php to re-check delivery availability
 * (when Delivery is picked, periodically, and right before submitting).
 * Reads the same live e-bike fleet state as the Admin E-Bike page and returns
 * nothing but a yes/no - no e-bike details are exposed to customers.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/ebike_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!customer_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['available' => null]);
    exit();
}

echo json_encode(['available' => ebike_delivery_available($conn)]);
