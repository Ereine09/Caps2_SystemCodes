<?php
/**
 * The ONE place the PHP session is started (app_session_start()). Every page, include and API
 * that needs $_SESSION calls this instead of session_start().
 *
 * Each area has its own session cookie, so the admin side and the customer side never share
 * session data (CSRF tokens, flash messages, the buy-now cart, the admin 2FA step):
 *   LM_CUSTOMER  customer site: /customer/*  (pages and its API endpoints)
 *   LM_RIDER     rider portal:  /rider/* and /modules/rider/*
 *   LM_ADMIN     everything else: /modules/admin, /modules/staff, /modules/customers,
 *                /modules/auth (admin/staff login), /api/*
 * The area comes from the path of the script that is running (SCRIPT_NAME), not from the file
 * that calls this, so a customer page that includes an admin file still gets LM_CUSTOMER.
 * A shared endpoint that serves several areas can pick one by defining APP_SESSION_AREA
 * ('admin' | 'customer' | 'rider') before anything starts the session.
 *
 * The logins themselves are separate JWT cookies per area (app/helpers/jwt_helper.php):
 * jwt_token_customer / jwt_token_staff / jwt_token_rider.
 */

const APP_SESSION_NAMES = ['admin' => 'LM_ADMIN', 'customer' => 'LM_CUSTOMER', 'rider' => 'LM_RIDER'];

/**
 * Session idle timeout: kept as long as the login itself (which has no idle limit) - with PHP's 24-minute default, a page left open for half
 * an hour lost its CSRF token and every save failed with "session expired" while still logged in.
 */
const APP_SESSION_IDLE_SECONDS = 365 * 86400; // 1 year: in practice no limit, like the login

/**
 * Our own folder for session files. On shared hosting the default folder is cleaned by the server
 * with its own (24-minute) rule, which deleted sessions early whatever we set here.
 */
function app_session_save_path(): ?string
{
    $dir = dirname(__DIR__, 2) . '/storage/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        @file_put_contents(dirname($dir) . '/.htaccess', "Require all denied\n");
    }
    return is_dir($dir) && is_writable($dir) ? $dir : null;
}

/** Which area the running script belongs to: 'admin' | 'customer' | 'rider'. */
function app_session_area(): string
{
    if (defined('APP_SESSION_AREA') && isset(APP_SESSION_NAMES[APP_SESSION_AREA])) {
        return APP_SESSION_AREA;
    }
    $path = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#/customer/#i', $path) && !preg_match('#/modules/#i', $path)) {
        return 'customer';
    }
    if (preg_match('#/rider/#i', $path)) { // /rider/*.php and /modules/rider/*.php
        return 'rider';
    }
    return 'admin';
}

function app_session_name(): string
{
    return APP_SESSION_NAMES[app_session_area()];
}

/**
 * Starts this area's session (no-op if one is already active). Cookie: HttpOnly, SameSite=Lax,
 * Secure on HTTPS, path /; strict mode and cookie-only session IDs; idle timeout above.
 */
function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? 80) == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) APP_SESSION_IDLE_SECONDS);
    $save_path = app_session_save_path();
    if ($save_path !== null) {
        session_save_path($save_path);
    }
    session_name(app_session_name());
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Idle timeout: a session untouched for too long starts over empty (with a new ID).
    $now = time();
    if (isset($_SESSION['__last_activity']) && $now - (int) $_SESSION['__last_activity'] > APP_SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['__last_activity'] = $now;
}

/** Logout of this area: empties and destroys ONLY this area's session and expires its cookie. */
function app_session_end(): void
{
    app_session_start();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);
        session_destroy();
    }
}
