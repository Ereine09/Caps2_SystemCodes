<?php
/**
 * Admin & Staff Authentication Helper
 *
 * Provides functions to manage admin and staff user sessions and protect admin-only pages.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

/**
 * Checks if a user with the role 'admin' or 'staff' is currently logged in.
 *
 * @return bool True if an admin or staff is logged in, false otherwise.
 */
function admin_is_logged_in(): bool {
    $token = getJWTFromCookie();
    if (!$token) {
        return false;
    }

    $payload = verifyJWT($token);
    if (!is_array($payload) || !isset($payload['role'])) {
        return false;
    }

    $role = strtolower(trim($payload['role']));
    return in_array($role, ['admin', 'staff'], true);
}

/**
 * If no admin or staff is logged in, this function redirects to the admin login page and stops script execution.
 */
function require_admin_login(): void {
    if (!admin_is_logged_in()) {
        // Clear any potentially conflicting cookies and redirect.
        clearJWTCookie(true);
        header('Location: ' . BASE_URL . '/modules/auth/login.php?error=session_expired');
        exit();
    }
}

/**
 * Retrieves the database record for the currently logged-in admin or staff user.
 *
 * @return array|null The user's data as an associative array, or null if not logged in or not found.
 */
function current_user(): ?array {
    global $conn;
    if (!admin_is_logged_in()) {
        return null;
    }

    $payload = verifyJWT(getJWTFromCookie());
    if (!is_array($payload) || !isset($payload['user_id'])) {
        return null;
    }

    $user_id = (int) $payload['user_id'];
    $stmt = $conn->prepare("SELECT id, username, first_name, last_name, role, email FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

?>