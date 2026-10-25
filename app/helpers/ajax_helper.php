<?php
/**
 * Small helpers so an existing PHP page can answer a fetch()/AJAX request with
 * JSON while it keeps working exactly as before for a normal browser request.
 *
 * AJAX is only a transport: callers must run the SAME authentication, CSRF,
 * role and validation checks first, then just emit JSON instead of redirecting.
 */

// An AJAX caller expects clean JSON: a PHP notice/warning printed into the body would corrupt it, so
// for those requests errors go to the log only (never shown to the user).
if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
    @ini_set('display_errors', '0');
}

if (!function_exists('is_ajax_request')) {
    /** True for requests sent by assets/js/live-ui.js (or any fetch asking for JSON). */
    function is_ajax_request(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    }
}

if (!function_exists('ajax_json')) {
    /** Sends a JSON body and stops. $status is the HTTP status code. */
    function ajax_json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

if (!function_exists('ajax_fail')) {
    /** Standard error body. Never put raw SQL / exception text in $message. */
    function ajax_fail(string $message, int $status = 422, array $extra = []): void
    {
        ajax_json(array_merge(['success' => false, 'message' => $message], $extra), $status);
    }
}

if (!function_exists('ajax_auth_required')) {
    /**
     * Use where a page would redirect an unauthenticated visitor to the login page:
     * an AJAX caller gets a JSON 401 (the client then sends the user to log in)
     * instead of receiving the login page's HTML.
     */
    function ajax_auth_required(): void
    {
        if (is_ajax_request()) {
            ajax_json(['success' => false, 'code' => 'auth', 'message' => 'Your session has expired. Please log in again.'], 401);
        }
    }
}
