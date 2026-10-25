<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

// This feature has been removed. Redirect to the dashboard.
header('Location: dashboard.php');
exit();
?>