<?php
require_once __DIR__ . '/includes/bootstrap.php';

header('Location: ' . BASE_URL . '/rider/' . (rider_current() ? 'dashboard.php' : 'login.php'));
exit();
