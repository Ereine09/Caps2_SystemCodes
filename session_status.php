<?php
/**
 * Which account is signed in on this browser right now, for assets/js/account-guard.js.
 * Returns only an opaque "portal:user_id" key (or null) - no names, no token. Open tabs
 * compare it with the key they started with and reload when a different account took over,
 * so a tab can never keep showing one account's pages after another account signed in.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/helpers/jwt_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$who = jwt_signed_in_account();
echo json_encode(['account' => $who ? $who['portal'] . ':' . $who['user_id'] : null]);
