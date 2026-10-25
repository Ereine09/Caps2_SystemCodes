<?php
/**
 * Staff portal pages that ARE the admin page, shown in "staff mode".
 *
 * A staff page such as modules/staff/messages.php is just:
 *
 *     require __DIR__ . '/../shared/staff_mirror.php';   // staff login only (admins are sent to their portal)
 *     require __DIR__ . '/../admin/messages.php';         // the admin page itself
 *
 * so staff get every feature of the admin page, now and after future updates. In staff mode:
 *   - modules/customers/sidebar.php prints the Staff sidebar (only the staff nav links);
 *   - links, redirects and AJAX URLs that point at an admin/customers page that also exists in
 *     modules/staff/ are rewritten to the staff copy, so staff stay on /modules/staff/ URLs;
 *   - admin pages hide links to admin-only sections (Analytics, Delivery, Staff Management ...)
 *     with staff_portal_mode(), and keep their own role checks for admin-only actions.
 *
 * Must be required at file scope (not inside a function) so the admin page keeps its globals.
 */
require_once __DIR__ . '/staff_auth.php';

$payload = require_staff(); // valid JWT + role === 'staff'; admins go to the admin dashboard

if (!defined('STAFF_PORTAL_MODE')) {
    define('STAFF_PORTAL_MODE', true);
}

if (!function_exists('staff_portal_mode')) {
    /** True while a staff page is showing an admin page (see above). */
    function staff_portal_mode(): bool
    {
        return defined('STAFF_PORTAL_MODE');
    }
}

if (!function_exists('staff_portal_url_rewrite')) {
    /**
     * modules/admin/x.php, modules/customers/x.php and ../admin|../customers/x.php -> the staff copy,
     * only when modules/staff/x.php exists (APIs such as remittance_api.php keep their admin path).
     * Handles JSON-escaped slashes too (modules\/admin\/x.php).
     */
    function staff_portal_url_rewrite(string $text): string
    {
        $staff_dir = __DIR__ . '/../staff/';
        return preg_replace_callback(
            '#(?:modules(\\\\?/)(?:admin|customers)\\\\?/|\.\.(\\\\?/)(?:admin|customers)\\\\?/)([a-z0-9_]+\.php)#',
            static function (array $m) use ($staff_dir): string {
                if (!is_file($staff_dir . $m[3]) || $m[3] === 'sidebar.php') {
                    return $m[0];
                }
                if ($m[1] !== '') {
                    return 'modules' . $m[1] . 'staff' . $m[1] . $m[3];      // absolute path
                }
                return $m[3];                                               // ../customers/x.php -> x.php (same folder)
            },
            $text
        ) ?? $text;
    }
}

// Redirect targets (Location headers) - rewritten just before the headers are sent.
header_register_callback(static function (): void {
    foreach (headers_list() as $h) {
        if (stripos($h, 'Location:') === 0) {
            $to = trim(substr($h, 9));
            $new = staff_portal_url_rewrite($to);
            if ($new !== $to) {
                header('Location: ' . $new, true);
            }
        }
    }
});

// Page HTML and JSON responses.
ob_start(static function (string $buffer): string {
    // Browser tab: "Orders - DPS Admin" -> "Orders - DPS Staff".
    $buffer = preg_replace_callback('#<title>(.*?)</title>#is', static function (array $m): string {
        return '<title>' . preg_replace('/\bAdmin\b/', 'Staff', $m[1]) . '</title>';
    }, $buffer, 1) ?? $buffer;
    return staff_portal_url_rewrite($buffer);
});
