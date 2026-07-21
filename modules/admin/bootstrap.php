<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Define the absolute path to the project root for reliable includes.
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', realpath(__DIR__ . '/../../'));
}

// 2. Add the Composer autoloader to make all vendor libraries available.
// This MUST be included before any file that uses vendor classes (like PHPMailer).
$autoloadPath = ROOT_PATH . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}