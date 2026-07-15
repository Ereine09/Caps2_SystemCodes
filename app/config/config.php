<?php
// i will set the global timezone for my application
date_default_timezone_set('Asia/Manila');

// my database info
$servername = "localhost"; // my server name
$username = "root"; // my database username
$password = ""; // my database password
$dbname = "capstone_db"; // my database name

// i will make a connection to my database
$conn = new mysqli($servername, $username, $password, $dbname);

// i will check my connection
if ($conn->connect_error) {
    // if i have an error, i will stop and show it
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to handle special characters correctly
$conn->set_charset("utf8mb4");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
define('SMTP_USERNAME', $db_settings['SMTP_USERNAME'] ?? 'elaisareinebelandres09@gmail.com');

// FORCING CODE PASSWORD: We ignore the database for this specific line to ensure your new App Password is used.
define('SMTP_PASSWORD', 'ytuo glsr bjhk upzu');

define('SMTP_PORT', (int)($db_settings['SMTP_PORT'] ?? 587));
define('SMTP_FROM_EMAIL', $db_settings['SMTP_FROM_EMAIL'] ?? 'ereinebelandres09@gmail.com');
define('SMTP_FROM_NAME', $db_settings['SMTP_FROM_NAME'] ?? SYSTEM_NAME);

// Business Logic
define('LOYALTY_POINTS_EXPIRY_MONTHS', (int)($db_settings['LOYALTY_POINTS_EXPIRY_MONTHS'] ?? 12));
?>
