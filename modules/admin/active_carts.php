<?php
/**
 * Admin — Active Cart Activity.
 *
 * Shows every customer who currently has items sitting in tbl_cart and
 * hasn't checked out yet — the "pre-abandoned" state that comes BEFORE the
 * existing Abandoned Carts panel (modules/customers/customers.php) ever
 * gets involved. Same table, same cart data, same 24h threshold as that
 * existing feature — this page only ever shows the complement of it (see
 * active_carts_api.php's HAVING clause), so a cart is never listed in both
 * places and this page never duplicates an abandoned-cart record/email.
 *
 * All data comes from modules/admin/active_carts_api.php (fetch + poll) -
 * this file only renders the page shell; no cart/order rows are queried or
 * rendered server-side, so there's nothing here to keep in sync with the
 * live JS render.
 */
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';

$token = getJWTFromCookie();
$payload = verifyJWT($token);

if (!$payload) {
    clearJWTCookie();
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$role = strtolower(trim($payload['role'] ?? ''));
if ($role !== 'admin' && !defined('STAFF_PORTAL_MODE')) { // modules/staff/active_carts.php shows this page in staff mode
    header('Location: ' . BASE_URL . '/modules/staff/active_carts.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Cart Activity - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        body { overflow-x: hidden; margin: 0; background-color: var(--surface-2); }

        .cart-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }
        .cart-search-box { position: relative; flex: 1 1 260px; max-width: 320px; }
        .cart-search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 0.85rem; }
        .cart-search-box input {
            width: 100%;
            padding: 10px 12px 10px 34px;
            border-radius: 8px;
            border: 1px solid var(--dm-border, #ddd);
            font-size: 0.9rem;
            box-sizing: border-box;
            background: var(--dm-surface, #fff);
            color: var(--dm-text, #1e293b);
        }
        .cart-count-label { font-size: 0.9rem; color: var(--dm-text-muted, #64748b); font-weight: 600; white-space: nowrap; }

        .cart-table-wrapper { overflow-x: auto; }
        #active-carts-table { width: 100%; border-collapse: collapse; }
        #active-carts-table th, #active-carts-table td { padding: 12px 10px; text-align: left; border-bottom: 1px solid var(--dm-border, #eee); }
        #active-carts-table th { background: var(--dm-surface-2, #f8f9fa); font-size: 0.8rem; text-transform: uppercase; color: var(--dm-text-muted, #64748b); }
        #active-carts-table tbody tr { cursor: pointer; transition: background .15s; }
        #active-carts-table tbody tr:hover { background: var(--dm-surface-2, #f8fafc); }

        .cart-customer-name { font-weight: 700; color: var(--dm-text, #1e293b); }
        .cart-customer-email { font-size: 0.78rem; color: var(--dm-text-muted, #94a3b8); }
        .cart-items-summary { font-size: 0.82rem; color: var(--dm-text-muted, #475569); }

        .cart-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .cart-status-active { background: var(--success-soft); color: var(--success); }
        .cart-status-idle { background: var(--warning-soft); color: var(--warning); }
        .cart-status-abandoned { background: var(--danger-soft); color: var(--danger); }
        body.dark-mode .cart-status-active { background: rgba(34,197,94,0.15); color: var(--success); }
        body.dark-mode .cart-status-idle { background: rgba(234,179,8,0.15); color: #facc15; }
        body.dark-mode .cart-status-abandoned { background: rgba(239,68,68,0.15); color: var(--danger); }

        .cart-empty-state { text-align: center; padding: 50px 20px; color: var(--dm-text-muted, #94a3b8); }
        .cart-empty-state i { font-size: 2.6rem; color: #cbd5e1; margin-bottom: 12px; display: block; }

        /* Detail modal */
        .cart-modal { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 2000; align-items: center; justify-content: center; padding: 16px; }
        .cart-modal.open { display: flex; }
        .cart-modal-shell {
            background: var(--dm-surface, #fff);
            color: var(--dm-text, #1e293b);
            border-radius: 14px;
            width: 100%;
            max-width: min(520px, calc(100vw - 32px));
            max-height: calc(100vh - 32px);
            overflow-y: auto;
            box-sizing: border-box;
            padding: 24px;
        }
        .cart-modal-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 16px; }
        .cart-modal-header h3 { margin: 0 0 3px; font-size: 1.1rem; }
        .cart-modal-header p { margin: 0; font-size: 0.82rem; color: var(--dm-text-muted, #64748b); }
        .cart-modal-close { cursor: pointer; font-size: 1.4rem; color: var(--dm-text-muted, #94a3b8); flex-shrink: 0; }
        .cart-modal-item { display: flex; justify-content: space-between; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--dm-border, #eee); }
        .cart-modal-item:last-of-type { border-bottom: none; }
        .cart-modal-item-name { font-weight: 600; font-size: 0.9rem; }
        .cart-modal-item-meta { font-size: 0.78rem; color: var(--dm-text-muted, #94a3b8); }
        .cart-modal-item-subtotal { font-weight: 700; white-space: nowrap; }
        .cart-modal-total { display: flex; justify-content: space-between; margin-top: 14px; padding-top: 14px; border-top: 2px solid var(--dm-border, #e2e8f0); font-weight: 800; font-size: 1.1rem; }

        @media (max-width: 640px) {
            .cart-toolbar { flex-direction: column; align-items: stretch; }
            .cart-search-box { max-width: 100%; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../customers/sidebar.php'; ?>

    <div class="main-content">
        <div class="welcome-header" style="margin-bottom: 20px;">
            <h1><i class="fas fa-cart-shopping" style="color: #4a3e94;"></i> Active Cart Activity</h1>
            <p>Customers currently shopping — items in their cart, not checked out yet. This is the stage BEFORE a cart can become abandoned.</p>
        </div>

        <div class="table-box" style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
            <div class="cart-toolbar">
                <div class="cart-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="cart-search-input" placeholder="Search customers...">
                </div>
                <span class="cart-count-label" id="cart-count-label">Loading…</span>
            </div>

            <div class="cart-table-wrapper">
                <table id="active-carts-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Last Activity</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="active-carts-tbody">
                        <tr><td colspan="5" style="text-align:center; padding: 30px;">Loading active carts…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Cart detail modal -->
    <div class="cart-modal" id="cart-detail-modal">
        <div class="cart-modal-shell">
            <div class="cart-modal-header">
                <div>
                    <h3 id="cart-detail-name">Customer</h3>
                    <p id="cart-detail-email"></p>
                </div>
                <span class="cart-modal-close" onclick="closeCartDetailModal()">&times;</span>
            </div>
            <div id="cart-detail-body"></div>
        </div>
    </div>

    <script>
        // Shared with modules/staff/active_carts.php - lets the one static
        // assets/js/active-cart-activity.js file build correct absolute API
        // URLs regardless of which portal folder the page lives in (same
        // bridge pattern as assets/js/nav-toggle.js already uses).
        window.__DPS_BASE_URL = <?php echo json_encode(BASE_URL); ?>;
    </script>
    <script src="<?php echo BASE_URL; ?>/assets/js/active-cart-activity.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/active-cart-activity.js') ?: time(); ?>"></script>
</body>
</html>
