<?php
// This script will be included in customer/includes/header.php

// Define constants for background task management
define('AUTO_CONFIRM_LAST_RUN_FILE', __DIR__ . '/last_auto_confirm_run.txt');
define('AUTO_CONFIRM_RUN_INTERVAL_SECONDS', 60); // Run every 60 seconds (1 minute)

// Ensure necessary helper functions are available
// config.php and customer/includes/functions.php are typically included by header.php (via auth.php)
// notification_helper.php might not be, so include it here.
require_once __DIR__ . '/../../app/helpers/notification_helper.php';

// Function to safely run the auto-confirm logic
function trigger_auto_confirm_if_needed($conn) {
    // Check if the last run file exists
    $last_run_time = 0;
    if (file_exists(AUTO_CONFIRM_LAST_RUN_FILE)) {
        $last_run_time = (int)file_get_contents(AUTO_CONFIRM_LAST_RUN_FILE);
    }

    // If enough time has passed since the last run
    if ((time() - $last_run_time) >= AUTO_CONFIRM_RUN_INTERVAL_SECONDS) {
        // Update the last run time immediately to prevent concurrent runs
        file_put_contents(AUTO_CONFIRM_LAST_RUN_FILE, time());

        // Include the auto-confirm script and run its function
        // Suppress output to avoid interfering with the main page content
        ob_start();
        require_once __DIR__ . '/../../customer/auto_confirm_orders.php'; // This will define run_auto_confirm_orders()
        if (function_exists('run_auto_confirm_orders')) {
            $log = run_auto_confirm_orders($conn);
            // Optionally log this output to a file for debugging
            // file_put_contents(__DIR__ . '/../../auto_confirm_log.txt', $log . "\n", FILE_APPEND);
        }
        ob_end_clean(); // Discard any output from the included script
    }
}

// Call the trigger function during page load
// Ensure $conn is available (it should be from config.php included in header.php)
if (isset($conn)) {
    trigger_auto_confirm_if_needed($conn);
}