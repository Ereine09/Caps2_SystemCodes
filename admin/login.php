<?php
/** Short address for the admin/staff login: /admin/login.php -> the existing modules/auth/login.php. */
require_once __DIR__ . '/../app/config/config.php';
header('Location: ' . BASE_URL . '/modules/auth/login.php');
exit();
