<?php
/**
 * Web Rider Portal — shared bootstrap (auth guard + app shell).
 *
 * Reuses the EXISTING rider authentication: riders are `users` rows with role 'rider',
 * logged in with the same JWT (generateJWT / setJWTCookie -> jwt_token_staff cookie) the
 * Flutter app's rider_login_api.php issues. Every page calls require_rider() first; data
 * and actions go through the existing modules/rider/*_api.php endpoints, which verify the
 * same cookie server-side (they never trust a rider/order id from the browser).
 */

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';

if (!function_exists('rider_e')) {
    function rider_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * The logged-in rider, or null. Checks the JWT AND the live users row, so a token for an
 * account that was deleted or is no longer a rider stops working immediately.
 */
function rider_current(): ?array
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    $cached = null;

    $payload = verifyJWT(getJWTFromCookie());
    if (!is_array($payload) || strtolower(trim((string) ($payload['role'] ?? ''))) !== 'rider') {
        return null;
    }
    $user_id = (int) ($payload['user_id'] ?? 0);
    if ($user_id <= 0) {
        return null;
    }

    global $conn;
    $stmt = $conn->prepare(
        "SELECT u.id, u.username, u.first_name, u.last_name, u.email, u.role, u.password_reset_at,
                r.id AS rider_id, r.is_on_duty, r.vehicle_type, r.plate_number
         FROM users u LEFT JOIN riders r ON r.user_id = u.id
         WHERE u.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || strtolower((string) $row['role']) !== 'rider') {
        return null;
    }
    // A password reset revokes sessions issued before it (same idea as the customer portal).
    if (!empty($row['password_reset_at']) && (int) ($payload['iat'] ?? 0) < strtotime($row['password_reset_at'])) {
        return null;
    }

    $full = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
    $row['display_name'] = $full !== '' ? $full : (string) $row['username'];
    return $cached = $row;
}

/** Page guard: sends anyone who isn't a logged-in rider to the rider login. */
function require_rider(): array
{
    rider_security_headers();
    $rider = rider_current();
    if (!$rider) {
        $next = basename($_SERVER['SCRIPT_NAME'] ?? '') . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
        // Logged in, but as admin/staff: say why instead of silently showing the login form.
        // (Their own session is left alone - it is not cleared from here.)
        $other = verifyJWT(getJWTFromCookie());
        $msg = is_array($other) ? '&msg=access_denied' : '';
        header('Location: ' . BASE_URL . '/rider/login.php?next=' . rawurlencode($next) . $msg);
        exit();
    }
    return $rider;
}

function rider_security_headers(): void
{
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    // Camera is needed for the QR scanner and proof photos, on this site only.
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    // Rider pages show customer data: never cache them (Back after logout must not reveal them).
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

/** Cache-busted URL for a file in the project. */
function rider_asset(string $path): string
{
    $file = __DIR__ . '/../../' . ltrim($path, '/');
    return BASE_URL . '/' . ltrim($path, '/') . '?v=' . (@filemtime($file) ?: time());
}

/**
 * Opens the page: <head>, top app bar and the content wrapper.
 *
 * @param string $title Page title (top bar + <title>)
 * @param array  $opts  back => URL for a back button instead of the logo; body_class => extra class
 */
function rider_page_start(string $title, array $opts = []): void
{
    $rider = rider_current();
    $back = $opts['back'] ?? null;
    $body_class = trim('rider-app ' . ($opts['body_class'] ?? ''));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#4f46e5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#14120f" media="(prefers-color-scheme: dark)">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="DPS Rider">
    <title><?php echo rider_e($title); ?> · DPS Rider</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/rider/assets/icons/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/rider/assets/icons/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/rider/assets/icons/apple-touch-icon.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>/rider/manifest.json">
    <script>if('serviceWorker' in navigator){window.addEventListener('load',function(){navigator.serviceWorker.register('<?php echo BASE_URL; ?>/sw.js',{scope:'<?php echo BASE_URL; ?>/'}).catch(function(){});});}</script>
    <script>
        window.__DPS_BASE_URL = <?php echo json_encode(BASE_URL); ?>;
        window.__DPS_PORTAL = <?php echo $rider ? "'rider'" : 'null'; ?>; // toast poller (assets/js/toast.js)
        window.__DPS_CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
    </script>
    <script src="<?php echo rider_asset('assets/js/theme.js'); ?>"></script>
    <script src="<?php echo rider_asset('assets/js/toast.js'); ?>"></script>
    <link rel="stylesheet" href="<?php echo rider_asset('assets/css/ui-kit.css'); ?>">
    <link rel="stylesheet" href="<?php echo rider_asset('rider/assets/rider.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="<?php echo rider_asset('rider/assets/rider.js'); ?>" defer></script>
</head>
<body class="<?php echo rider_e($body_class); ?>">
<div class="rd-offline" id="rd-offline" role="status" hidden><i class="fas fa-wifi"></i> You're offline. Check your internet connection.</div>
<header class="rd-topbar">
    <?php if ($back): ?>
        <a class="rd-icon-btn rd-back" href="<?php echo rider_e($back); ?>" data-back aria-label="Back"><i class="fas fa-arrow-left"></i></a>
    <?php else: ?>
        <a class="rd-brand" href="<?php echo BASE_URL; ?>/rider/dashboard.php" aria-label="Rider home">
            <img src="<?php echo rider_e(SYSTEM_LOGO_URL); ?>" alt="">
        </a>
    <?php endif; ?>
    <h1 class="rd-topbar-title"><?php echo rider_e($title); ?></h1>
    <div class="rd-topbar-actions">
        <button type="button" class="rd-icon-btn" data-theme-toggle aria-label="Switch theme"><i class="fas fa-moon" aria-hidden="true"></i></button>
        <?php if ($rider):
            // Unread customer messages to this rider (same rows rider_messaging_api's unread count reads).
            global $conn;
            $msg_unread = 0;
            if ($conn instanceof mysqli) {
                $mu = $conn->prepare("SELECT COUNT(*) FROM tbl_messages WHERE sender_type = 'customer' AND user_id = ? AND is_read = 0");
                $rid = (int) $rider['id'];
                $mu->bind_param('i', $rid);
                $mu->execute();
                $msg_unread = (int) ($mu->get_result()->fetch_row()[0] ?? 0);
                $mu->close();
            }
        ?>
        <a class="rd-icon-btn" href="<?php echo BASE_URL; ?>/rider/messages.php" aria-label="Messages<?php echo $msg_unread ? ' (' . $msg_unread . ' unread)' : ''; ?>">
            <i class="fas fa-comment-dots"></i>
            <span class="rd-count" id="rd-msg-count"<?php echo $msg_unread ? '' : ' hidden'; ?>><?php echo $msg_unread; ?></span>
        </a>
        <script>
            // Keep the Messages badge current while the page is open (customers reply mid-delivery).
            (function () {
                var badge = document.getElementById('rd-msg-count');
                function refresh() {
                    if (document.hidden || !badge) return;
                    fetch(window.__DPS_BASE_URL + '/modules/rider/rider_messaging_api.php?action=get_unread_count', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            var n = d && d.success ? Number(d.data && d.data.unread_count) || 0 : null;
                            if (n === null) return;
                            badge.textContent = n > 99 ? '99+' : String(n);
                            badge.hidden = n === 0;
                        })
                        .catch(function () {});
                }
                setInterval(refresh, 30000);
                document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
            })();
        </script>
        <a class="rd-icon-btn" href="<?php echo BASE_URL; ?>/rider/notifications.php" aria-label="Notifications">
            <i class="fas fa-bell"></i>
            <span class="rd-count" data-notif-count hidden>0</span>
        </a>
        <?php endif; ?>
    </div>
</header>
<main class="rd-main" id="rd-main">
    <?php
}

/**
 * Closes the page: bottom navigation and toast stack.
 *
 * @param string $active Bottom-nav key: home | deliveries | scan | remittance | profile ('' = none)
 */
function rider_page_end(string $active): void
{
    $nav = [
        'home'       => ['dashboard.php', 'fa-house', 'Home'],
        'deliveries' => ['deliveries.php', 'fa-box', 'Deliveries'],
        'scan'       => ['scan.php', 'fa-qrcode', 'Scan'],
        'remittance' => ['remittance.php', 'fa-wallet', 'Remittance'],
        'profile'    => ['profile.php', 'fa-user', 'Profile'],
    ];
    ?>
</main>
<nav class="rd-bottomnav" aria-label="Rider navigation">
    <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
        <a href="<?php echo BASE_URL; ?>/rider/<?php echo $href; ?>" class="rd-nav-item<?php echo $key === 'scan' ? ' rd-nav-scan' : ''; ?><?php echo $key === $active ? ' is-active' : ''; ?>"<?php echo $key === $active ? ' aria-current="page"' : ''; ?>>
            <span class="rd-nav-icon"><i class="fas <?php echo $icon; ?>"></i></span>
            <span class="rd-nav-label"><?php echo $label; ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<div id="toasts" class="toast-stack" aria-live="polite"></div>
</body>
</html>
    <?php
}
