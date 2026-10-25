<?php
// Use the central bootstrap file for path definitions.
if (!defined('ROOT_PATH')) {
    // Define ROOT_PATH here as a fallback if db.php is included directly.
    define('ROOT_PATH', realpath(__DIR__ . '/../..'));
}
require_once __DIR__ . '/../../app/config/config.php';

// Reuse the existing database connection from config.php
if (!isset($conn) || !$conn) {
    die('Database connection failed.');
}

// Keep a local reference for convenience
$customer_db = $conn;
