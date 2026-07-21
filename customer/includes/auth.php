<?php
// Use the central bootstrap file to ensure autoloader is always available first.
require_once __DIR__ . '/../../modules/admin/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

function customer_is_logged_in(): bool {
    $token = getJWTFromCookie();
    if (!$token) {
        return false;
    }
    $payload = verifyJWT($token);
    return is_array($payload) && strtolower(trim($payload['role'] ?? '')) === 'customer';
}

function require_customer_login(): void {
    if (!customer_is_logged_in()) {
        header('Location: ' . BASE_URL . '/customer/login.php');
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
