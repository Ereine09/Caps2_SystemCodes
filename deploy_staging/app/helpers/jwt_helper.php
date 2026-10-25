<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function generateJWT($user_id, $username, $role = 'staff') {
    global $jwt_secret;
    $issuedAt = time();

    // Session lifetime. 30 days balances "don't log people out constantly" with
    // limiting the damage window of a leaked token. Bump JWT_TTL_DAYS if needed.
    $ttlDays = (int) (getenv('JWT_TTL_DAYS') ?: 30);
    $expirationTime = $issuedAt + ($ttlDays * 24 * 60 * 60);

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
    if ($token === ADMIN_AUTH_BYPASS_TOKEN && admin_auth_disabled()) {
        return admin_auth_bypass_payload();
    }
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
    if (admin_auth_disabled()) {
        return ADMIN_AUTH_BYPASS_TOKEN;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // Use specific cookie names to prevent session clashing between Customer and Staff portals
    // We check if the URI is in the customer portal but NOT in the staff's customer management module
    $is_customer_portal = (stripos($uri, '/customer/') !== false) && (stripos($uri, '/modules/') === false);

    $cookie_name = $is_customer_portal ? 'jwt_token_customer' : 'jwt_token_staff';
    return $_COOKIE[$cookie_name] ?? null;
}

const ADMIN_AUTH_BYPASS_TOKEN = '__ADMIN_AUTH_DISABLED_BYPASS__';

/**
 * True only when Settings > Security has turned Admin login OFF (never true in
 * production — see ADMIN_AUTH_ENABLED in config.php) AND the current request is
 * for an Admin-portal route. Covers /modules/admin/ AND /modules/customers/ —
 * despite its folder name, modules/customers/ (customers.php, loyalty_points.php,
 * reward_redemption.php, customer_details.php, its own sidebar.php, etc.) is the
 * other half of the Admin portal, reached from the same admin sidebar; the real
 * customer-facing site lives under the separate top-level /customer/ (singular)
 * folder and is never matched here. Staff (/modules/staff/) and Customer logins
 * are never touched by this toggle.
 */
function admin_auth_disabled(): bool {
    if (!defined('ADMIN_AUTH_ENABLED') || ADMIN_AUTH_ENABLED) {
        return false;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return stripos($uri, '/modules/admin/') !== false || stripos($uri, '/modules/customers/') !== false;
}

/**
 * Fake "logged in as the first real admin account" JWT payload, used only while
 * admin_auth_disabled() is true. Uses a genuine users.id (not 0) so writes that
 * log $payload['user_id'] (activity_logs, order status changes, etc.) don't
 * violate the activity_logs -> users foreign key.
 */
function admin_auth_bypass_payload(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    global $conn;
    $user_id = 1;
    $username = 'Admin (auth disabled)';
    if (isset($conn) && $conn instanceof mysqli) {
        $res = @mysqli_query($conn, "SELECT id, username FROM users WHERE LOWER(role) = 'admin' ORDER BY id ASC LIMIT 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if (!$row) {
            $res = @mysqli_query($conn, "SELECT id, username FROM users ORDER BY id ASC LIMIT 1");
            $row = $res ? mysqli_fetch_assoc($res) : null;
        }
        if ($row) {
            $user_id = (int) $row['id'];
            $username = $row['username'];
        }
    }
    return $cached = [
        'iat' => time(),
        'exp' => time() + 86400,
        'user_id' => $user_id,
        'username' => $username,
        'role' => 'admin',
    ];
}

function setJWTCookie($token) {
    $payload = verifyJWT($token);
    $role = (is_array($payload) && isset($payload['role'])) ? strtolower(trim($payload['role'])) : 'staff';
    
    // Save to a role-specific cookie so the browser doesn't overwrite one session with another
    $cookie_name = ($role === 'customer') ? 'jwt_token_customer' : 'jwt_token_staff';

    // Cookie lifetime matches the JWT TTL.
    $ttlDays = (int) (getenv('JWT_TTL_DAYS') ?: 30);
    setcookie($cookie_name, $token, jwt_cookie_options(time() + $ttlDays * 24 * 60 * 60));
}

/**
 * Shared cookie flags: HttpOnly always, Secure when the request is HTTPS,
 * SameSite=Lax so the cookie survives normal top-level navigation but is not
 * sent on cross-site POSTs.
 */
function jwt_cookie_options(int $expires): array {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? 80) == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    return [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Clears JWT cookies. Pass true to force-logout both portal types.
 */
function clearJWTCookie($all = false) {
    if ($all) {
        setcookie('jwt_token_customer', '', jwt_cookie_options(time() - 3600));
        setcookie('jwt_token_staff', '', jwt_cookie_options(time() - 3600));
        if (isset($_COOKIE['jwt_token_customer'])) unset($_COOKIE['jwt_token_customer']);
        if (isset($_COOKIE['jwt_token_staff'])) unset($_COOKIE['jwt_token_staff']);
        return;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $is_customer_portal = (stripos($uri, '/customer/') !== false) && (stripos($uri, '/modules/') === false);
    $cookie_name = $is_customer_portal ? 'jwt_token_customer' : 'jwt_token_staff';

    setcookie($cookie_name, '', jwt_cookie_options(time() - 3600));
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