<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/active_session_helper.php'; // one signed-in account per browser (server-side)

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function generateJWT($user_id, $username, $role = 'staff') {
    global $jwt_secret;
    $issuedAt = time();

    // Session lifetime: effectively unlimited (10 years) - a login lasts until Log out or a
    // password reset. Set JWT_TTL_DAYS to bring back a limit.
    $ttlDays = (int) (getenv('JWT_TTL_DAYS') ?: 3650);
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
        $decoded = (array) JWT::decode($token, new Key($jwt_secret, 'HS256'));
        // A password change (reset link, or an admin's "Recover Account") signs a staff, admin or
        // rider account out everywhere: tokens issued before users.password_reset_at stop working.
        // (Customers have the same rule in customer_token_is_stale().)
        if (jwt_revoked_by_password_reset($decoded)) {
            return false;
        }
        // One signed-in account per browser: a user's login token only works while it is the live
        // session recorded for its browser (tbl_active_sessions). Logged out, replaced, idle for too
        // long or revoked -> no longer accepted, on every page and API (cookie or Bearer).
        // (Internal system tokens, user_id <= 0, e.g. the WebSocket push token, are not user sessions.)
        if ((int) ($decoded['user_id'] ?? 0) > 0 && !active_session_validate($token)) {
            return false;
        }
        return $decoded;
    } catch (Exception $e) {
        // If verification fails, return false — caller can clear cookie / force login
        return false;
    }
}

/** True when a staff/admin/rider token was issued before that account's last password reset. */
function jwt_revoked_by_password_reset(array $payload): bool {
    global $conn;
    static $reset_at = [];
    $role = strtolower(trim((string) ($payload['role'] ?? '')));
    $user_id = (int) ($payload['user_id'] ?? 0);
    if ($role === 'customer' || $user_id <= 0 || !($conn instanceof mysqli)) {
        return false;
    }
    if (!array_key_exists($user_id, $reset_at)) {
        $reset_at[$user_id] = null;
        try {
            $stmt = $conn->prepare("SELECT password_reset_at FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $reset_at[$user_id] = !empty($row['password_reset_at']) ? strtotime($row['password_reset_at']) : null;
        } catch (Throwable $e) {
            error_log('jwt_revoked_by_password_reset: ' . $e->getMessage());
        }
    }
    return $reset_at[$user_id] !== null && (int) ($payload['iat'] ?? 0) < $reset_at[$user_id];
}

/**
 * One login cookie per portal, so one portal's login can never be read by another:
 *   customer -> jwt_token_customer   (/customer/*)
 *   rider    -> jwt_token_rider      (/rider/* web portal and its /modules/rider/* APIs)
 *   staff    -> jwt_token_staff      (admin + staff: /modules/admin, /modules/staff, /modules/customers, /api)
 * Riders used to share jwt_token_staff with admin/staff, which let a rider login leak into
 * the admin notifications. The Flutter apps send a Bearer token and are not affected.
 */
const JWT_PORTAL_COOKIES = [
    'customer' => 'jwt_token_customer',
    'rider'    => 'jwt_token_rider',
    'staff'    => 'jwt_token_staff',
];

/** Which portal the current request belongs to (decides which login cookie it reads). */
function jwt_request_portal(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // The customer site, but NOT the staff's customer-management module (/modules/customers/).
    if (stripos($uri, '/customer/') !== false && stripos($uri, '/modules/') === false) {
        return 'customer';
    }
    if (stripos($uri, '/rider/') !== false) { // /rider/*.php and /modules/rider/*.php
        return 'rider';
    }
    return 'staff';
}

/** Portal a role logs in to. */
function jwt_role_portal(string $role): string {
    $role = strtolower(trim($role));
    return $role === 'customer' ? 'customer' : ($role === 'rider' ? 'rider' : 'staff');
}

function getJWTFromCookie() {
    if (admin_auth_disabled()) {
        return ADMIN_AUTH_BYPASS_TOKEN;
    }
    // Each area reads only its own login cookie, so the areas never affect each other.
    return $_COOKIE[JWT_PORTAL_COOKIES[jwt_request_portal()]] ?? null;
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
    if (stripos($uri, '/modules/admin/') === false && stripos($uri, '/modules/customers/') === false) {
        return false;
    }
    // While a real admin/staff account is signed in on this browser, the "no login" admin view
    // does not apply - that account's own login decides. (A customer or rider may share the browser.)
    return !active_session_device_active('staff');
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

/**
 * Signs the browser in with this token. Returns false - and sets NOTHING - when a different account
 * already has a live session on this browser (one signed-in account per browser, all roles); the
 * login page must then show ACTIVE_SESSION_BLOCKED_MESSAGE. The check and the claim are one atomic
 * database step (active_session_claim()).
 */
function setJWTCookie($token): bool {
    global $jwt_secret;
    try {
        // Decode directly: the session record for this token doesn't exist until it is claimed below.
        $payload = (array) JWT::decode($token, new Key($jwt_secret, 'HS256'));
    } catch (Exception $e) {
        return false;
    }
    $role = strtolower(trim((string) ($payload['role'] ?? 'staff')));
    if (!active_session_claim((int) ($payload['user_id'] ?? 0), $role, (string) $token, (int) ($payload['exp'] ?? time()))) {
        return false;
    }
    
    // Save to the role's own portal cookie so one session never overwrites or leaks into another.
    $cookie_name = JWT_PORTAL_COOKIES[jwt_role_portal($role)];

    // Cookie lifetime matches the JWT TTL.
    $ttlDays = (int) (getenv('JWT_TTL_DAYS') ?: 3650);
    setcookie($cookie_name, $token, jwt_cookie_options(time() + $ttlDays * 24 * 60 * 60));
    $_COOKIE[$cookie_name] = $token;
    return true;
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
        foreach (JWT_PORTAL_COOKIES as $name) {
            active_session_release_token((string) ($_COOKIE[$name] ?? '')); // frees this browser's session
            setcookie($name, '', jwt_cookie_options(time() - 3600));
            unset($_COOKIE[$name]);
        }
        return;
    }

    $cookie_name = JWT_PORTAL_COOKIES[jwt_request_portal()];
    // Logout: end this token's server-side session so the browser is free for the next sign-in.
    active_session_release_token((string) ($_COOKIE[$cookie_name] ?? ''));

    setcookie($cookie_name, '', jwt_cookie_options(time() - 3600));
    if (isset($_COOKIE[$cookie_name])) {
        unset($_COOKIE[$cookie_name]);
    }
}

?>