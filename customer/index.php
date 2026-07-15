<?php
require_once __DIR__ . '/includes/auth.php';
if (customer_is_logged_in()) {
    header('Location: ' . BASE_URL . '/customer/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/customer/login.php');
}
exit();
