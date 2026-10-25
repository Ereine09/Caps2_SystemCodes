<?php
/**
 * Staff copy of modules/admin/scan_lookup_api.php, so the staff pages (admin pages in staff mode, see
 * modules/shared/staff_mirror.php) reach it by the same relative URL. Staff login only.
 */
require_once __DIR__ . '/../shared/staff_auth.php';
require_staff_api();
require __DIR__ . '/../admin/scan_lookup_api.php';
