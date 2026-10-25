<?php
// i will set the global timezone for my application
date_default_timezone_set('Asia/Manila');

// Load local secrets (untracked). Falls back to environment variables.
// Nothing sensitive should be hard-coded in this file anymore.
$LOCAL_SECRETS = file_exists(__DIR__ . '/config.local.php')
    ? (array) (require __DIR__ . '/config.local.php')
    : [];

// --- APP ENVIRONMENT ---
// 'local' (default, safe for XAMPP) or 'production' (set via config.local.php
// -> ['APP_ENV' => 'production'] or the APP_ENV env var on the host). This
// only controls error visibility below — it does NOT change any business
// logic, routes, or DB behavior.
if (!defined('APP_ENV')) {
    define('APP_ENV', $LOCAL_SECRETS['APP_ENV'] ?? getenv('APP_ENV') ?: 'local');
}

// Never show raw PHP errors/warnings/SQL/paths to visitors in production.
// Locally this keeps the same behavior as before (relies on XAMPP's default
// php.ini, which normally has display_errors On for development).
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
}

// --- DATABASE CONNECTION ---
// Local defaults below match the existing XAMPP setup exactly (nothing
// changes locally). On Hostinger, override via app/config/config.local.php
// (same untracked-file pattern already used for jwt_secret/SMTP_PASSWORD
// below) or via real environment variables (DB_HOST, DB_NAME, DB_USER,
// DB_PASS, DB_PORT) if your hosting panel supports setting them.
$servername = $LOCAL_SECRETS['DB_HOST'] ?? getenv('DB_HOST') ?: "localhost"; // my server name
$servername_ip = $LOCAL_SECRETS['DB_HOST'] ?? getenv('DB_HOST') ?: "127.0.0.1"; // Use IP for reliability
$username = $LOCAL_SECRETS['DB_USER'] ?? getenv('DB_USER') ?: "root"; // my database username
$password = $LOCAL_SECRETS['DB_PASS'] ?? getenv('DB_PASS') ?: ""; // my database password
$dbname = $LOCAL_SECRETS['DB_NAME'] ?? getenv('DB_NAME') ?: "capstone_db"; // my database name
$port = (int) ($LOCAL_SECRETS['DB_PORT'] ?? getenv('DB_PORT') ?: 3306); // Default MySQL port

// Enable mysqli exceptions for error handling
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // i will make a connection to my database
    $conn = new mysqli($servername_ip, $username, $password, $dbname, $port);
} catch (mysqli_sql_exception $e) {
    // Log the real reason for the operator; never leak it to the visitor.
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(503); // Service Unavailable
    die("<h2>Service Unavailable</h2><p>The site is temporarily unable to reach its database. Please try again shortly.</p>");
}

// Set charset to handle special characters correctly
$conn->set_charset("utf8mb4");

// my base url for my links and redirects
if (!defined('BASE_URL')) {
    $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    // 'api' added alongside the existing modules/app/customer entries — without
    // it, any absolute URL built from api/*.php (e.g. admin_app_api.php's
    // logo_url/payment_proof_url for the Flutter apps) resolved to
    // ".../loyalty_managements/api/assets/..." instead of ".../assets/...".
    $baseUrl = preg_replace('#/(?:modules|app|customer|api)(?:/.*)?$#', '', $scriptDirectory);
    if ($baseUrl === '/' || $baseUrl === '\\') {
        $baseUrl = '';
    }
    define('BASE_URL', rtrim($baseUrl, '/'));
}

// Define SITE_URL for external links (critical for images to show in emails)
if (!defined('SITE_URL')) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? 80) == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $protocol = $is_https ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('SITE_URL', $protocol . $host . BASE_URL);
}

// JWT secret — from config.local.php or the JWT_SECRET env var. Never commit the real value.
$jwt_secret = $LOCAL_SECRETS['jwt_secret'] ?? getenv('JWT_SECRET') ?: '';
if ($jwt_secret === '') {
    error_log('FATAL: no JWT secret configured (app/config/config.local.php or JWT_SECRET env var).');
    http_response_code(500);
    die('Server configuration error.');
}

// --- EXTERNAL SERVICES CONFIGURATION ---
// We will try to load these from the database first.
$db_settings = [];
$settings_res = mysqli_query($conn, "SHOW TABLES LIKE 'system_settings'");
if ($settings_res && mysqli_num_rows($settings_res) > 0) {
    $res = mysqli_query($conn, "SELECT setting_key, setting_value FROM system_settings");
    while ($row = mysqli_fetch_assoc($res)) {
        $db_settings[$row['setting_key']] = $row['setting_value'];
    }
}

// --- ADMIN AUTHENTICATION TOGGLE ---
// Lets Admin portal pages (modules/admin/*.php) be reached without logging in —
// off by default is "must log in" (unchanged behavior). Flip it off from
// Settings > Security (modules/admin/settings.php) for local dev/demo so you
// stop re-logging-in on every reload/defense run-through. Always forced ON
// in production regardless of what's saved, so this can never ship disabled.
define('ADMIN_AUTH_ENABLED', APP_ENV === 'production'
    ? true
    : filter_var($db_settings['ADMIN_AUTH_ENABLED'] ?? '1', FILTER_VALIDATE_BOOLEAN));

// Brand Identity
define('SYSTEM_NAME', $db_settings['SYSTEM_NAME'] ?? 'Darius Poultry Supplies');

// Shop Address & Coordinates
define('SHOP_ADDRESS', $db_settings['SHOP_ADDRESS'] ?? 'Darius Poultry Supply and general Merchandise, M. Hizon 1 Street, Barangay 64, Zone 6, Grace Park West, District 2, Caloocan, Northern Manila District, Metro Manila, 1406, Philippines');
define('SHOP_LAT', (float)($db_settings['SHOP_LAT'] ?? 14.652023833372462));
define('SHOP_LON', (float)($db_settings['SHOP_LON'] ?? 120.97593745927361));

// Logic to ensure Logo URL is absolute even if saved as a relative path in DB
$logo_path = $db_settings['SYSTEM_LOGO_URL'] ?? '/assets/img/loyalty_logo.png';
if (!preg_match('#^https?://#', $logo_path)) {
    $logo_path = SITE_URL . '/' . ltrim($logo_path, '/');
}
define('SYSTEM_LOGO_URL', $logo_path);

// Google Gemini AI API Key (DB setting > local secret > env var)
define('GEMINI_API_KEY', $db_settings['GEMINI_API_KEY'] ?? $LOCAL_SECRETS['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: '');

// SMTP Email Configuration (PHPMailer)
define('SMTP_HOST', $db_settings['SMTP_HOST'] ?? 'smtp.gmail.com');
define('SMTP_AUTH', isset($db_settings['SMTP_AUTH']) ? (bool)$db_settings['SMTP_AUTH'] : true);
define('SMTP_USERNAME', $db_settings['SMTP_USERNAME'] ?? 'dpsloyaltymanagement@gmail.com');

// SMTP password (DB setting > local secret > env var). Never hard-code it here.
define('SMTP_PASSWORD', $db_settings['SMTP_PASSWORD'] ?? $LOCAL_SECRETS['SMTP_PASSWORD'] ?? getenv('SMTP_PASSWORD') ?: '');

define('SMTP_PORT', (int)($db_settings['SMTP_PORT'] ?? 587));
define('SMTP_FROM_EMAIL', $db_settings['SMTP_FROM_EMAIL'] ?? 'dpsloyaltymanagement@gmail.com');
define('SMTP_FROM_NAME', $db_settings['SMTP_FROM_NAME'] ?? SYSTEM_NAME);

// Business Logic
define('LOYALTY_POINTS_EXPIRY_MONTHS', (int)($db_settings['LOYALTY_POINTS_EXPIRY_MONTHS'] ?? 12));

// --- WEBSOCKET SERVER CONFIGURATION ---
// Used by the notification push helper (ws_push_helper.php) to deliver
// real-time notifications to online riders via the Ratchet WS server
// (ws/run_ws_server.php — a persistent process; see README_DEPLOYMENT.md
// for why this does NOT run on ordinary Hostinger shared hosting).
// ws_push_helper.php already fails silently if this host is unreachable,
// so leaving these at their local defaults on a host with no WS server is
// safe — real-time push just won't fire; DB-persisted notifications still work.
define('WS_HOST', $LOCAL_SECRETS['WS_HOST'] ?? getenv('WS_HOST') ?: '127.0.0.1');
define('WS_PORT', (int) ($LOCAL_SECRETS['WS_PORT'] ?? getenv('WS_PORT') ?: 8080));
define('WS_SERVER_URL', 'ws://' . WS_HOST . ':' . WS_PORT);
?>
