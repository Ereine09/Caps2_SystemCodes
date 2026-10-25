<?php
/**
 * Shared portal auth guard for the STAFF portal.
 *
 * This file only adds helpers used by modules/staff/*. It does NOT change the
 * existing Admin authentication flow (admin pages keep their own inline checks).
 *
 * Every staff page must call require_staff() as its first action:
 *
 *     $payload = require_staff();          // valid JWT + role === 'staff'
 *     $user_id = (int) $payload['user_id'];
 *
 * JSON endpoints call require_staff_api() instead (returns 401 JSON, no redirect).
 */

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';

if (!function_exists('portal_current_payload')) {
    function portal_current_payload(): ?array
    {
        $token = getJWTFromCookie();
        if (!$token) {
            return null;
        }
        $payload = verifyJWT($token);
        return is_array($payload) ? $payload : null;
    }
}

if (!function_exists('portal_role')) {
    function portal_role(?array $payload): string
    {
        return strtolower(trim($payload['role'] ?? ''));
    }
}

if (!function_exists('require_staff')) {
    /**
     * Guarantees, in order:
     *   1. a JWT cookie exists
     *   2. the JWT is valid
     *   3. the token role is exactly 'staff'
     *
     * - missing / invalid JWT   -> clear cookie, redirect to login
     * - role 'admin'            -> redirect to the admin dashboard (admins use the admin portal)
     * - any other role (e.g. customer) -> clear cookie, redirect to login
     *
     * @return array the verified JWT payload
     */
    function require_staff(): array
    {
        $payload = portal_current_payload();

        if (!$payload) {
            clearJWTCookie();
            header('Location: ' . BASE_URL . '/modules/auth/login.php?error=login_required');
            exit();
        }

        $role = portal_role($payload);

        if ($role === 'admin') {
            // A valid admin should not land on staff pages; send them home.
            header('Location: ' . BASE_URL . '/modules/admin/dashboard.php');
            exit();
        }

        if ($role !== 'staff') {
            // Customers (or anything else) are never allowed here.
            clearJWTCookie();
            header('Location: ' . BASE_URL . '/modules/auth/login.php?error=access_denied');
            exit();
        }

        return $payload;
    }
}

if (!function_exists('require_staff_api')) {
    /**
     * JSON-API variant of require_staff(): emits a 401 JSON body instead of a redirect.
     *
     * @return array the verified JWT payload
     */
    function require_staff_api(): array
    {
        $payload = portal_current_payload();
        $role = portal_role($payload);

        if (!$payload || $role !== 'staff') {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized. Staff access required.']);
            exit();
        }

        return $payload;
    }
}
