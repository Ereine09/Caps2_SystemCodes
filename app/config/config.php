<?php
// i will set the global timezone for my application
date_default_timezone_set('Asia/Manila');

// my database info
$servername = "localhost"; // my server name
$servername_ip = "127.0.0.1"; // Use IP for reliability
$username = "root"; // my database username
$password = ""; // my database password
$dbname = "capstone_db"; // my database name
$port = 3306; // Default MySQL port

// Enable mysqli exceptions for error handling
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // i will make a connection to my database
    $conn = new mysqli($servername_ip, $username, $password, $dbname, $port);
} catch (mysqli_sql_exception $e) {
    // If connection fails, provide a clear error message and stop.
    // This is much more user-friendly than a fatal error.
    http_response_code(503); // Service Unavailable
    die("<h2>Database Connection Error</h2><p>Could not connect to the database. Please ensure the MySQL server is running in XAMPP and that the credentials are correct.</p><p><strong>Error details:</strong> " . $e->getMessage() . "</p>");
}

// Set charset to handle special characters correctly
$conn->set_charset("utf8mb4");

// my base url for my links and redirects
if (!defined('BASE_URL')) {
    $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $baseUrl = preg_replace('#/(?:modules|app|customer)(?:/.*)?$#', '', $scriptDirectory);
    if ($baseUrl === '/' || $baseUrl === '\\') {
        $baseUrl = '';
    }
    define('BASE_URL', rtrim($baseUrl, '/'));
}

// Define SITE_URL for external links (critical for images to show in emails)
if (!defined('SITE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('SITE_URL', $protocol . $host . BASE_URL);
}

// my jwt secret key. i should change this to a secure random key.
$jwt_secret = "your_super_secret_jwt_key_12345_very_long_and_secure_key_for_jwt_authentication_2024"; 

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

// Brand Identity
define('SYSTEM_NAME', $db_settings['SYSTEM_NAME'] ?? 'Darius Poultry Supplies');

// Logic to ensure Logo URL is absolute even if saved as a relative path in DB
$logo_path = $db_settings['SYSTEM_LOGO_URL'] ?? '/assets/img/loyalty_logo.png';
if (!preg_match('#^https?://#', $logo_path)) {
    $logo_path = SITE_URL . '/' . ltrim($logo_path, '/');
}
define('SYSTEM_LOGO_URL', $logo_path);

// Google Gemini AI API Key
define('GEMINI_API_KEY', $db_settings['GEMINI_API_KEY'] ?? 'AIzaSyCofnT2g8NjU4-_9noaFcHbibX0qnwRW-8');

// SMTP Email Configuration (PHPMailer)
define('SMTP_HOST', $db_settings['SMTP_HOST'] ?? 'smtp.gmail.com');
define('SMTP_AUTH', isset($db_settings['SMTP_AUTH']) ? (bool)$db_settings['SMTP_AUTH'] : true);
define('SMTP_USERNAME', $db_settings['SMTP_USERNAME'] ?? 'dpsloyaltymanagement@gmail.com');

// FORCING CODE PASSWORD: We ignore the database for this specific line to ensure your new App Password is used.
define('SMTP_PASSWORD', 'nxnr qiii bovq cutq');

define('SMTP_PORT', (int)($db_settings['SMTP_PORT'] ?? 587));
define('SMTP_FROM_EMAIL', $db_settings['SMTP_FROM_EMAIL'] ?? 'dpsloyaltymanagement@gmail.com');
define('SMTP_FROM_NAME', $db_settings['SMTP_FROM_NAME'] ?? SYSTEM_NAME);

// Business Logic
define('LOYALTY_POINTS_EXPIRY_MONTHS', (int)($db_settings['LOYALTY_POINTS_EXPIRY_MONTHS'] ?? 12));

// --- WEBSOCKET SERVER CONFIGURATION ---
// Used by the notification push helper (ws_push_helper.php) to deliver
// real-time notifications to online riders via the Ratchet WS server.
define('WS_HOST', '127.0.0.1');
define('WS_PORT', 8080);
define('WS_SERVER_URL', 'ws://' . WS_HOST . ':' . WS_PORT);
?>
