<?php

if (!function_exists('get_pending_remittance_count')) {
    function get_pending_remittance_count(mysqli $conn): int
    {
        $result = $conn->query(
            "SELECT COUNT(*) AS total FROM tbl_rider_remittances WHERE status = 'pending'"
        );
        return (int)($result?->fetch_assoc()['total'] ?? 0);
    }
}

if (!function_exists('render_pending_remittance_badge')) {
    function render_pending_remittance_badge(mysqli $conn): string
    {
        $count = get_pending_remittance_count($conn);
        if ($count <= 0) {
            return '';
        }
        return '<span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;">' . $count . '</span>';
    }
}
