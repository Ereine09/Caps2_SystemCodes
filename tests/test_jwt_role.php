<?php
require_once __DIR__ . '/../app/helpers/jwt_helper.php';

echo "Testing JWT with role...\n";

// Generate token with admin role
$token = generateJWT(1, 'adminuser', 'admin');
$payload = verifyJWT($token);

echo "User ID: " . $payload['user_id'] . "\n";
echo "Username: " . $payload['username'] . "\n";
echo "Role: " . ($payload['role'] ?? 'not set') . "\n";
echo "Token verified: " . ($payload ? 'YES' : 'NO') . "\n";
?>