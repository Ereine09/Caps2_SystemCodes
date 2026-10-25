<?php
/**
 * CSRF protection helper.
 *
 * Usage:
 *   - In a <form>:  echo csrf_field();
 *   - On POST:      csrf_require();   // dies with 403 on a missing/bad token
 *   - Manual check: if (!csrf_check()) { ... }
 *
 * The token lives in the PHP session, so csrf_helper.php will start one if needed.
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
    }
}

if (!function_exists('csrf_check')) {
    /**
     * Returns true when the request carries a valid token.
     * Accepts the token from POST body or the X-CSRF-Token header (for fetch/AJAX).
     */
    function csrf_check(): bool
    {
        $sent = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';

        if (!is_string($sent) || $sent === '' || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $sent);
    }
}

if (!function_exists('csrf_require')) {
    function csrf_require(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        if (!csrf_check()) {
            http_response_code(403);
            if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
                || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token.']);
            } else {
                echo 'Invalid or missing security token. Please go back, reload the page, and try again.';
            }
            exit;
        }
    }
}
