<?php
require_once __DIR__ . '/app/config/config.php';

$updates = [
    ['MANAGE_REWARDS', 'Manage Rewards'],
    ['Manage_Staff', 'Manage Staff'],
    ['manage_staff', 'Manage Staff'],
    ['SECURITY_SIM', 'Security Simulation'],
];

foreach ($updates as $u) {
    $stmt = $conn->prepare("UPDATE activity_logs SET action = ? WHERE action = ?");
    $stmt->bind_param("ss", $u[1], $u[0]);
    $stmt->execute();
    echo "Updated " . $stmt->affected_rows . " rows: " . $u[0] . " -> " . $u[1] . "\n";
}

echo "Done updating database records.\n";

