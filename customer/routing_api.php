<?php
/**
 * Routing API Proxy
 * Safely fetches road distance from OSRM on the server-side to avoid browser CORS issues.
 */
header('Content-Type: application/json');

// We need the function from the main functions file.
require_once __DIR__ . '/../includes/functions.php';

$response = ['success' => false, 'distance' => null, 'message' => 'An error occurred.'];

try {
    $lat1 = filter_input(INPUT_GET, 'lat1', FILTER_VALIDATE_FLOAT);
    $lon1 = filter_input(INPUT_GET, 'lon1', FILTER_VALIDATE_FLOAT);
    $lat2 = filter_input(INPUT_GET, 'lat2', FILTER_VALIDATE_FLOAT);
    $lon2 = filter_input(INPUT_GET, 'lon2', FILTER_VALIDATE_FLOAT);

    if ($lat1 === false || $lon1 === false || $lat2 === false || $lon2 === false) {
        throw new Exception('Invalid coordinates provided.');
    }

    // Use the existing server-side function to get the route distance
    $distance_km = calculate_route_distance_km($lat1, $lon1, $lat2, $lon2);

    if ($distance_km === null) {
        throw new Exception('Could not calculate a delivery route. The location may be unreachable.');
    }

    $response['success'] = true;
    $response['distance'] = $distance_km;
    $response['message'] = 'Route calculated successfully.';

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);