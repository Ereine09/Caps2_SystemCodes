<?php
// CONSIDER: Use Composer's autoloader instead of manual requires for third-party libraries.
require_once __DIR__ . '/../../vendor/autoload.php';

// Manual requires (if not using Composer autoloader)
require_once __DIR__ . '/../../vendor/firebase/php-jwt/src/JWT.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function generateJWT($user_id, $username, $role = 'staff') {
    global $jwt_secret;
    $issuedAt = time();

    // Extended expiration to a long period (10 years) to prevent auto-logout due to token expiry
    $expirationTime = $issuedAt + (10 * 365 * 24 * 60 * 60); // 10 years

    $payload = array(
        'iat' => $issuedAt,
        'exp' => $expirationTime,
        'user_id' => $user_id,
        'username' => $username,
        'role' => $role
    );

    return JWT::encode($payload, $jwt_secret, 'HS256');
}

function verifyJWT($token) {
    global $jwt_secret;
    if (empty($token)) {
        return false;
    }
    try {
        $decoded = JWT::decode($token, new Key($jwt_secret, 'HS256'));
        return (array) $decoded;
    } catch (Exception $e) {
        // If verification fails, return false — caller can clear cookie / force login
        return false;
    }
}

function getJWTFromCookie() {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // Use specific cookie names to prevent session clashing between Customer and Staff portals
    // We check if the URI is in the customer portal but NOT in the staff's customer management module
    $is_customer_portal = (stripos($uri, '/customer/') !== false) && (stripos($uri, '/modules/') === false);
    
    $cookie_name = $is_customer_portal ? 'jwt_token_customer' : 'jwt_token_staff';
    return $_COOKIE[$cookie_name] ?? null;
}

function setJWTCookie($token) {
    $payload = verifyJWT($token);
    $role = (is_array($payload) && isset($payload['role'])) ? strtolower(trim($payload['role'])) : 'staff';
    
    // Save to a role-specific cookie so the browser doesn't overwrite one session with another
    $cookie_name = ($role === 'customer') ? 'jwt_token_customer' : 'jwt_token_staff';

    // Set cookie lifetime to match the extended JWT expiry (10 years)
    $cookie_life = 10 * 365 * 24 * 60 * 60; // 10 years
    setcookie($cookie_name, $token, time() + $cookie_life, '/', '', false, true);
}

/**
 * Clears JWT cookies. Pass true to force-logout both portal types.
 */
function clearJWTCookie($all = false) {
    if ($all) {
        setcookie('jwt_token_customer', '', time() - 3600, '/', '', false, true);
        setcookie('jwt_token_staff', '', time() - 3600, '/', '', false, true);
        if (isset($_COOKIE['jwt_token_customer'])) unset($_COOKIE['jwt_token_customer']);
        if (isset($_COOKIE['jwt_token_staff'])) unset($_COOKIE['jwt_token_staff']);
        return;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $is_customer_portal = (stripos($uri, '/customer/') !== false) && (stripos($uri, '/modules/') === false);
    $cookie_name = $is_customer_portal ? 'jwt_token_customer' : 'jwt_token_staff';

    setcookie($cookie_name, '', time() - 3600, '/', '', false, true);
    if (isset($_COOKIE[$cookie_name])) {
        unset($_COOKIE[$cookie_name]);
    }
}

/**
 * Security Guard: Detects if a user from the 'other' portal is trying to access this one.
 * If detected, it clears all cookies and forces a logout.
 */
function enforcePortalGuard($required_role_group = 'staff') {
    $other_cookie = ($required_role_group === 'staff') ? 'jwt_token_customer' : 'jwt_token_staff';
    
    // If they aren't logged in here, but are logged in 'there', clear everything.
    $token = getJWTFromCookie();
    if (!$token && isset($_COOKIE[$other_cookie])) {
        clearJWTCookie(true);
        $redirect = ($required_role_group === 'staff') ? '/modules/auth/login.php' : '/customer/login.php';
        header("Location: " . BASE_URL . $redirect . "?error=session_conflict");
        exit();
    }
}
?>