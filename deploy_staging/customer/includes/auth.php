<?php
// Use the central bootstrap file to ensure autoloader is always available first.
require_once __DIR__ . '/../../modules/admin/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/customer_reset_schema_helper.php';

/**
 * True when this JWT was issued BEFORE the customer's password last changed —
 * i.e. it belongs to a device/browser that predates a password reset (self-service
 * or Admin-triggered) and must stop being accepted. The JWT itself is stateless
 * (no server-side token store), so this timestamp comparison is what makes
 * revocation possible at all without a second auth system.
 */
function customer_token_is_stale(int $customer_id, int $issued_at): bool {
    global $customer_db;
    ensure_customer_password_meta_schema($customer_db);

    $stmt = $customer_db->prepare("SELECT password_changed_at FROM customer_login_credentials WHERE customer_id = ? LIMIT 1");
    $stmt->bind_param('i', $customer_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !$row['password_changed_at']) {
        return false; // password was never changed since this column existed - nothing to invalidate
    }
    return $issued_at < strtotime($row['password_changed_at']);
}

function customer_is_logged_in(): bool {
    $token = getJWTFromCookie();
    if (!$token) {
        return false;
    }
    $payload = verifyJWT($token);
    if (!is_array($payload) || strtolower(trim($payload['role'] ?? '')) !== 'customer') {
        return false;
    }
    if (customer_token_is_stale((int) ($payload['user_id'] ?? 0), (int) ($payload['iat'] ?? 0))) {
        return false;
    }
    return true;
}

function require_customer_login(): void {
    // A valid JWT token alone is not enough — the customer must ALSO still
    // exist in the database. This guards against stale sessions pointing to a
    // customer record that was deleted (or a database that was reset).
    if (!customer_is_logged_in()) {
        header('Location: ' . BASE_URL . '/customer/login.php');
        exit();
    }

    $customer = current_customer();
    if (!$customer) {
        // The token is valid but the customer no longer exists in the DB.
        // Clear the stale token and force a fresh login.
        clearJWTCookie(true);
        header('Location: ' . BASE_URL . '/customer/login.php?error=session_expired');
        exit();
    }
}

function current_customer(): ?array {
    if (!customer_is_logged_in()) {
        return null;
    }
    $payload = verifyJWT(getJWTFromCookie());
    if (!is_array($payload)) {
        return null;
    }
    return get_customer_by_id((int) $payload['user_id']);
}

function customer_login(array $customer): void {
    $token = generateJWT((int)$customer['customer_id'], $customer['customer_email'], 'customer');
    setJWTCookie($token);
}

function customer_logout(): void {
    clearJWTCookie();
    if (session_status() !== PHP_SESSION_NONE) {
        session_unset();
        session_destroy();
    }
}

function customer_redirect_if_logged_in(): void {
    if (customer_is_logged_in()) {
        header('Location: ' . BASE_URL . '/customer/dashboard.php');
        exit();
    }
}
