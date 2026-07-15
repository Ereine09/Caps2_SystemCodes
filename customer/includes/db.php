<?php
require_once __DIR__ . '/../../app/config/config.php';

// Reuse the existing database connection from config.php
if (!isset($conn) || !$conn) {
    die('Database connection failed.');
}

// Keep a local reference for convenience
$customer_db = $conn;
