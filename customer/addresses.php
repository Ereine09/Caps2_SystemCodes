<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

header('Location: profile.php');
exit();