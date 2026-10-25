<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_customer_login(); // the cart belongs to an account; guests browse products.php and log in to buy

$customer = current_customer();

// Viewing the real cart means leaving Buy Now mode - this page must always
// show the full persistent cart, never the single Buy Now item.
clear_buy_now();

// "Checkout" in the top-bar mini cart comes through here (after Buy Now is cleared above),
// so checkout.php always gets the full cart.
if (($_GET['go'] ?? '') === 'checkout' && $_SERVER['REQUEST_METHOD'] === 'GET' && cart_item_count() > 0) {
    header('Location: checkout.php');
    exit();
}

$message = '';
$message_type = 'success';
// Picks up the flash set by orders.php's "Buy Again" redirect (and any other
// page that redirects here with a session flash) - $message already had a
// render block below, it just had nothing populating it.
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'success';
    unset($_SESSION['message'], $_SESSION['message_type']);
}

// Handle updates from the cart UI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['select_items'])) {
        // Select / deselect for checkout: one line (product_id + variant_id) or every line (product_id = 0).
        header('Content-Type: application/json');
        if (!csrf_check()) {
            echo json_encode(['success' => false, 'message' => 'Your session expired. Please reload the page.']);
            exit();
        }
        $sel_variant = isset($_POST['variant_id']) && $_POST['variant_id'] ? (int) $_POST['variant_id'] : null;
        set_cart_selection((int) $customer['id'], ($_POST['selected'] ?? '1') === '1', (int) ($_POST['product_id'] ?? 0), $sel_variant);
        $picked = get_customer_cart(true);
        echo json_encode([
            'success' => true,
            'subtotal_net' => cart_subtotal_ex_vat(),
            'subtotal_gross' => cart_subtotal(),
            'selected_lines' => count($picked),
        ]);
        exit();
    }
    if (isset($_POST['update_quantity'])) {
        $product_id = (int)$_POST['product_id'];
        $variant_id = isset($_POST['variant_id']) && $_POST['variant_id'] ? (int)$_POST['variant_id'] : null;
        $quantity = (int)$_POST['quantity'];
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

        if (update_customer_cart_item((int)$customer['id'], $product_id, $quantity, $variant_id)) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Cart updated.',
                    'subtotal_net' => cart_subtotal_ex_vat(),
                    'subtotal_gross' => cart_subtotal()
                ]);
                exit();
            }
            header('Location: cart.php');
            exit();
        } else {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Failed to update cart.']);
                exit();
            }
        }
    } elseif (isset($_POST['remove_item'])) {
        $product_id = (int)$_POST['product_id'];
        $variant_id = isset($_POST['variant_id']) && $_POST['variant_id'] ? (int)$_POST['variant_id'] : null;
        remove_customer_cart_item((int)$customer['id'], $product_id, $variant_id);
        header('Location: cart.php');
        exit();
    }
}

$cart = get_customer_cart();
$subtotal_net = cart_subtotal_ex_vat();
$subtotal_gross = cart_subtotal();

// ---- Display-only helpers (no new queries besides read-only stock look-ups) ----
$cart_money = static fn ($v): string => 'PHP ' . number_format((float) $v, 2);
$cart_total_qty = 0;   // pieces ticked for checkout
$cart_selected = 0;    // lines ticked for checkout
foreach ($cart as $c_item) {
    if (!empty($c_item['selected'])) {
        $cart_total_qty += (int) $c_item['quantity'];
        $cart_selected++;
    }
}
$vat_amount = $subtotal_net * 0.12;
?>
<?php $page_title = 'My Shopping Cart'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    /* ==========================================================================
       Cart (.cart-*): item cards on the left, a sticky Order Summary on the right
       (so the total + Checkout are always in view), a fixed checkout bar on phones.
       Colours come from the --customer-* theme variables, which flip in dark mode;
       text colours are scoped `.customer-panel .cart` so they out-rank the dark-mode
       "inherit" rule.
       ========================================================================== */
    .customer-panel .cart {
        --c-surface: var(--customer-surface, #fff);
        --c-surface-2: var(--customer-surface-2, #f8fafc);
        --c-text: var(--customer-text, #1e293b);
        --c-muted: var(--customer-muted, #64748b);
        --c-border: var(--customer-border, #e2e8f0);
        --c-edge: color-mix(in srgb, var(--c-text) 22%, var(--c-border));
        --c-link: var(--customer-link, var(--primary));
        min-width: 0;
    }
    .cart *, .cart *::before, .cart *::after { box-sizing: border-box; }
    .cart-layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 20px; align-items: start; }

    .cart-bar { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .customer-panel .cart .cart-count { font-size: 0.95rem; color: var(--c-muted); }
    .customer-panel .cart .cart-count strong { color: var(--c-text); font-size: 1.05rem; }
    .customer-panel .cart .cart-continue { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 9px; font-size: 0.85rem; font-weight: 700; text-decoration: none;
        color: var(--c-link); background: var(--c-surface); border: 1.5px solid var(--c-edge); }
    .customer-panel .cart .cart-continue:hover { border-color: var(--c-link); }

    .cart-list { display: flex; flex-direction: column; gap: 10px; }
    /* "Show more items" group: keeps a big cart short; opens/closes without a reload */
    .cart-more > summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 4px 12px; padding: 12px 16px; border-radius: 14px;
        background: var(--c-surface-2); border: 2px dashed var(--c-edge); font-size: 0.9rem; font-weight: 700; }
    .customer-panel .cart .cart-more > summary { color: var(--c-link); }
    .cart-more > summary::-webkit-details-marker { display: none; }
    .cart-more > summary:hover { border-style: solid; }
    .customer-panel .cart .cart-more .cm-note { font-size: 0.75rem; font-weight: 600; color: var(--c-muted); }
    .cart-more .cm-close { display: none; }
    .cart-more[open] .cm-open { display: none; }
    .cart-more[open] .cm-close { display: inline; }
    .cart-more[open] > summary { margin-bottom: 10px; border-style: solid; }
    .cart-more .cart-list-more { margin-top: 0; }
    .cart-item { display: grid; grid-template-columns: 72px minmax(0, 1fr) auto auto auto; grid-template-areas: "img info qty line rm"; align-items: center; gap: 8px 16px;
        padding: 12px 14px; border-radius: 14px; background: var(--c-surface); border: 2px solid var(--c-edge); box-shadow: 0 3px 10px rgba(15, 23, 42, 0.05); transition: opacity .15s ease; }
    .cart-item.is-busy { opacity: 0.6; pointer-events: none; }
    /* Select / deselect for checkout */
    .cart-item { position: relative; padding-left: 46px; }
    .ci-check { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); display: flex; cursor: pointer; }
    .ci-check input, .cart-selectall input { width: 19px; height: 19px; margin: 0; accent-color: var(--dk-primary, #4f46e5); cursor: pointer; }
    .cart-item.is-unpicked .ci-img, .cart-item.is-unpicked .ci-info, .cart-item.is-unpicked .ci-line { opacity: .5; }
    .cart-selectall { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; cursor: pointer; user-select: none; }
    .customer-panel .cart .cs-checkout.is-disabled, .customer-panel .cart-mobilebar a.is-disabled { opacity: .45; pointer-events: none; box-shadow: none; }
    .cart-item .ci-img { grid-area: img; width: 72px; height: 72px; object-fit: contain; border-radius: 12px; background: var(--c-surface-2); border: 1px solid var(--c-border); }
    .cart-item .ci-info { grid-area: info; min-width: 0; }
    .customer-panel .cart .ci-name { margin: 0 0 4px; font-size: 0.95rem; line-height: 1.35; color: var(--c-text); overflow-wrap: anywhere; }
    .ci-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; }
    .customer-panel .cart .ci-chip { padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; background: var(--c-surface-2); border: 1px solid var(--c-border); color: var(--c-muted); }
    .customer-panel .cart .ci-unit { font-size: 0.82rem; color: var(--c-muted); white-space: nowrap; }
    .customer-panel .cart .ci-warn { margin-top: 5px; font-size: 0.75rem; font-weight: 700; color: var(--warning); }
    .customer-panel .cart .ci-warn.is-out { color: var(--danger); }
    html[data-theme="dark"] .customer-panel .cart .ci-warn { color: var(--warning); }
    html[data-theme="dark"] .customer-panel .cart .ci-warn.is-out { color: var(--danger); }

    .cart-item .ci-qty { grid-area: qty; margin: 0; }
    .stepper { display: inline-flex; align-items: center; border-radius: 10px; overflow: hidden; background: var(--c-surface); border: 1.5px solid var(--c-edge); }
    .stepper button { width: 34px; height: 36px; border: 0; background: var(--c-surface-2); cursor: pointer; font-size: 1.05rem; font-weight: 700; line-height: 1; }
    .customer-panel .cart .stepper button { color: var(--c-text); }
    .stepper button:hover:not(:disabled) { background: color-mix(in srgb, var(--c-link) 16%, var(--c-surface-2)); }
    .stepper button:disabled { opacity: 0.4; cursor: not-allowed; }
    .stepper input { width: 46px; height: 36px; border: 0; border-left: 1px solid var(--c-border); border-right: 1px solid var(--c-border); background: var(--c-surface); text-align: center; font: inherit; font-weight: 700; font-size: 0.95rem; -moz-appearance: textfield; }
    .customer-panel .cart .stepper input { color: var(--c-text); }
    .stepper input::-webkit-outer-spin-button, .stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .stepper input:focus { outline: 2px solid color-mix(in srgb, var(--c-link) 45%, transparent); outline-offset: -2px; }

    .customer-panel .cart .ci-line { grid-area: line; min-width: 118px; text-align: right; font-weight: 800; font-size: 1rem; color: var(--c-text); white-space: nowrap; }
    .cart-item .ci-remove { grid-area: rm; margin: 0; }
    .customer-panel .cart .ci-remove button { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; cursor: pointer;
        background: transparent; border: 1.5px solid transparent; color: var(--danger); font-size: 0.95rem; }
    .customer-panel .cart .ci-remove button:hover { background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.4); }

    /* Order summary */
    .cart-summary { position: sticky; top: 16px; padding: 18px 20px; border-radius: 16px; background: var(--c-surface); border: 2px solid var(--c-edge); box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08); }
    .customer-panel .cart .cart-summary h3 { margin: 0 0 12px; padding-bottom: 10px; border-bottom: 1.5px solid var(--c-edge); font-size: 1.05rem; color: var(--c-text); }
    .cs-row { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; padding: 5px 0; font-size: 0.9rem; }
    .customer-panel .cart .cs-row span:first-child { color: var(--c-muted); }
    .customer-panel .cart .cs-row span:last-child { font-weight: 700; color: var(--c-text); }
    .customer-panel .cart .cs-row.cs-points span:last-child { color: var(--success); }
    .cs-total { margin-top: 8px; padding-top: 12px; border-top: 2px solid var(--c-edge); align-items: center; }
    .customer-panel .cart .cs-total span:first-child { font-size: 1.05rem; font-weight: 800; color: var(--c-text); }
    .customer-panel .cart .cs-total span:last-child { font-size: 1.35rem; font-weight: 800; color: var(--c-text); }
    .customer-panel .cart .cs-checkout { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; margin-top: 14px; padding: 14px; border-radius: 12px; font-weight: 800; font-size: 1rem; text-decoration: none;
        background: var(--dk-primary, #4f46e5); color: var(--dk-on-primary, #fff); box-shadow: 0 6px 14px rgba(79, 70, 229, 0.35); }
    .customer-panel .cart .cs-checkout:hover { background: var(--dk-primary-hover, #4338ca); }
    .customer-panel .cart .cs-note { margin: 10px 0 0; font-size: 0.75rem; line-height: 1.4; color: var(--c-muted); text-align: center; }

    /* Fixed checkout bar (phones) */
    .cart-mobilebar { display: none; }

    .cart-toast { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); z-index: 1200; max-width: calc(100vw - 32px); padding: 10px 18px; border-radius: 10px; font-size: 0.88rem; font-weight: 700;
        background: #1e293b; color: #fff; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3); opacity: 0; pointer-events: none; transition: opacity .2s ease; }
    .cart-toast.is-on { opacity: 1; }
    .cart-toast.is-error { background: #b91c1c; }

    .cart-empty { text-align: center; padding: 50px 20px; border-radius: 16px; background: var(--customer-surface, #fff); border: 2px dashed var(--customer-border, #e2e8f0); }
    .cart-empty .ce-icon { font-size: 3.5rem; margin-bottom: 14px; color: var(--customer-muted, #94a3b8); opacity: 0.6; }
    .cart-empty h3 { margin: 0 0 6px; }
    .cart-empty p { margin: 0 0 22px; color: var(--customer-muted, #64748b); }

    @media (max-width: 980px) {
        .cart-layout { grid-template-columns: 1fr; }
        .cart-summary { position: static; }
    }
    @media (max-width: 700px) {
        .cart-item { grid-template-columns: 64px minmax(0, 1fr) auto; grid-template-areas: "img info info" "qty line rm"; padding: 12px 12px 12px 40px; }
        .ci-check { left: 11px; top: 40px; transform: none; }
        .cart-item .ci-img { width: 64px; height: 64px; }
        .customer-panel .cart .ci-line { min-width: 0; text-align: right; }
        .cart { padding-bottom: 84px; }
        .cart-mobilebar { display: flex; align-items: center; justify-content: space-between; gap: 14px; position: fixed; left: 0; right: 0; bottom: 0; z-index: 900; padding: 10px 16px calc(10px + env(safe-area-inset-bottom));
            background: var(--customer-surface, #fff); border-top: 2px solid var(--customer-border, #e2e8f0); box-shadow: 0 -6px 18px rgba(15, 23, 42, 0.12); }
        .customer-panel .cart-mobilebar .mb-total { display: flex; flex-direction: column; line-height: 1.2; }
        .customer-panel .cart-mobilebar .mb-total small { font-size: 0.72rem; color: var(--customer-muted, #64748b); }
        .customer-panel .cart-mobilebar .mb-total strong { font-size: 1.1rem; color: var(--customer-text, #1e293b); }
        .customer-panel .cart-mobilebar a { flex: 1 1 auto; max-width: 220px; text-align: center; padding: 12px 16px; border-radius: 12px; font-weight: 800; text-decoration: none; background: var(--dk-primary, #4f46e5); color: var(--dk-on-primary, #fff); }
    }
</style>

<section class="customer-panel">
    <div class="section-header">
        <h2>Shopping Cart 🛒</h2>
        <p>Review and organize your items before proceeding to checkout.</p>
    </div>

    <?php if ($message): ?>
        <div class="alert-message alert-<?php echo $message_type === 'error' ? 'error' : 'success'; ?>" style="margin-bottom: 20px;"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($cart): ?>
        <div class="cart">
            <div class="cart-layout">
                <div class="cart-main">
                    <div class="cart-bar">
                        <label class="cart-selectall"><input type="checkbox" id="cartSelectAll"<?php echo $cart_selected === count($cart) ? ' checked' : ''; ?>> Select all</label>
                        <span class="cart-count"><strong id="cartLines"><?php echo count($cart); ?></strong> <span id="cartLinesWord"><?php echo count($cart) === 1 ? 'item' : 'items'; ?></span> in your cart</span>
                        <a href="products.php" class="cart-continue"><i class="fas fa-arrow-left"></i> Continue Shopping</a>
                    </div>

                    <?php $cart_visible = 5; $cart_seen = 0; // the first few products show; the rest sit in a collapsible group ?>
                    <div class="cart-list" id="cartList">
                        <?php foreach ($cart as $item): ?>
                            <?php if ($cart_seen === $cart_visible): ?>
                                <details class="cart-more" id="cartMore">
                                    <summary>
                                        <span class="cm-open"><i class="fas fa-chevron-down"></i> Show <?php echo count($cart) - $cart_visible; ?> more item<?php echo count($cart) - $cart_visible === 1 ? '' : 's'; ?></span>
                                        <span class="cm-close"><i class="fas fa-chevron-up"></i> Show fewer items</span>
                                        <span class="cm-note">Showing <?php echo $cart_visible; ?> of <?php echo count($cart); ?></span>
                                    </summary>
                                    <div class="cart-list cart-list-more">
                            <?php endif; $cart_seen++; ?>
                            <?php
                                $ci_variant = (int) ($item['variant_id'] ?? 0);
                                $ci_size = trim((string) ($item['size'] ?? ''));
                                $ci_name = trim((string) $item['name']);
                                if ($ci_size !== '' && substr($ci_name, -strlen(' (' . $ci_size . ')')) === ' (' . $ci_size . ')') {
                                    $ci_name = rtrim(substr($ci_name, 0, -strlen(' (' . $ci_size . ')')));
                                }
                                $ci_stock = get_product_stock((int) $item['id'], $ci_variant > 0 ? $ci_variant : null);
                                $ci_max = $ci_stock > 0 ? $ci_stock : 0; // 0 = unknown/unlimited on the client
                            ?>
                            <article class="cart-item<?php echo empty($item['selected']) ? ' is-unpicked' : ''; ?>" data-unit="<?php echo htmlspecialchars((string) $item['unit_price']); ?>" data-max="<?php echo $ci_max; ?>">
                                <label class="ci-check" title="Include in checkout"><input type="checkbox" class="ci-pick" data-product="<?php echo (int) $item['id']; ?>" data-variant="<?php echo $ci_variant > 0 ? $ci_variant : ''; ?>"<?php echo !empty($item['selected']) ? ' checked' : ''; ?> aria-label="Include <?php echo htmlspecialchars($ci_name); ?> in checkout"></label>
                                <img src="<?php echo htmlspecialchars($item['image_url'] ?: BASE_URL . '/assets/img/placeholder.png'); ?>" class="ci-img" alt="" loading="lazy">
                                <div class="ci-info">
                                    <h3 class="ci-name"><?php echo htmlspecialchars($ci_name); ?></h3>
                                    <div class="ci-meta">
                                        <?php if ($ci_size !== ''): ?><span class="ci-chip"><?php echo htmlspecialchars($ci_size); ?></span><?php endif; ?>
                                        <span class="ci-unit"><?php echo $cart_money($item['unit_price']); ?> each<?php if ((float) ($item['regular_price'] ?? 0) > (float) $item['unit_price']): ?> <s style="color: var(--muted); font-weight: 500;"><?php echo $cart_money($item['regular_price']); ?></s> <span class="ci-chip" style="background: var(--danger-soft); color: var(--danger);"><i class="fas fa-tag"></i> Sale</span><?php endif; ?></span>
                                    </div>
                                    <?php if ($ci_stock <= 0): ?>
                                        <div class="ci-warn is-out"><i class="fas fa-exclamation-circle"></i> Out of stock right now</div>
                                    <?php elseif ($ci_stock <= 5): ?>
                                        <div class="ci-warn"><i class="fas fa-fire"></i> Only <?php echo $ci_stock; ?> left</div>
                                    <?php endif; ?>
                                </div>

                                <form method="POST" class="ci-qty">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="product_id" value="<?php echo (int) $item['id']; ?>">
                                    <input type="hidden" name="variant_id" value="<?php echo $ci_variant > 0 ? $ci_variant : ''; ?>">
                                    <input type="hidden" name="update_quantity" value="1">
                                    <div class="stepper">
                                        <button type="button" data-step="-1" aria-label="Decrease quantity">&minus;</button>
                                        <input type="number" name="quantity" value="<?php echo (int) $item['quantity']; ?>" min="1"<?php echo $ci_max > 0 ? ' max="' . $ci_max . '"' : ''; ?> inputmode="numeric" aria-label="Quantity">
                                        <button type="button" data-step="1" aria-label="Increase quantity">+</button>
                                    </div>
                                    <noscript><button type="submit" class="button" style="padding: 8px 12px; font-size: 0.75rem;">Update</button></noscript>
                                </form>

                                <div class="ci-line"><?php echo $cart_money($item['unit_price'] * $item['quantity']); ?></div>

                                <form method="POST" class="ci-remove" onsubmit="return confirm('Remove this item?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="product_id" value="<?php echo (int) $item['id']; ?>">
                                    <input type="hidden" name="variant_id" value="<?php echo $ci_variant > 0 ? $ci_variant : ''; ?>">
                                    <button type="submit" name="remove_item" value="1" title="Remove from cart" aria-label="Remove from cart"><i class="fas fa-trash"></i></button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                        <?php if (count($cart) > $cart_visible): ?>
                                    </div>
                                </details>
                        <?php endif; ?>
                    </div>
                </div>

                <aside class="cart-summary" aria-label="Order summary">
                    <h3><i class="fas fa-receipt"></i> Order Summary</h3>
                    <div class="cs-row"><span>Selected items</span><span><span id="sumQty"><?php echo $cart_total_qty; ?></span> pcs</span></div>
                    <div class="cs-row"><span>Subtotal (Net)</span><span id="sumNet"><?php echo $cart_money($subtotal_net); ?></span></div>
                    <div class="cs-row"><span>VAT (12%)</span><span id="sumVat"><?php echo $cart_money($vat_amount); ?></span></div>
                    <div class="cs-row cs-points"><span>Estimated Points</span><span id="sumPts">+<?php echo number_format($subtotal_net / 100, 2); ?> pts</span></div>
                    <div class="cs-row cs-total"><span>Total</span><span id="sumTotal"><?php echo $cart_money($subtotal_gross); ?></span></div>
                    <a href="checkout.php" class="cs-checkout<?php echo $cart_selected ? '' : ' is-disabled'; ?>" id="csCheckout"><span>Check Out (<span class="js-picked"><?php echo $cart_selected; ?></span>)</span> <i class="fas fa-credit-card"></i></a>
                    <p class="cs-note">Delivery fee and any voucher are applied at checkout.</p>
                </aside>
            </div>

            <div class="cart-mobilebar">
                <div class="mb-total"><small>Total</small><strong id="mbTotal"><?php echo $cart_money($subtotal_gross); ?></strong></div>
                <a href="checkout.php" id="mbCheckout"<?php echo $cart_selected ? '' : ' class="is-disabled"'; ?>>Check Out (<span class="js-picked"><?php echo $cart_selected; ?></span>) <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
        <div class="cart-toast" id="cartToast" role="status" aria-live="polite"></div>

        <script>
        (function () {
            var list = document.getElementById('cartList');
            if (!list) return;
            var toast = document.getElementById('cartToast');
            var toastTimer = null;

            function fmt(n) { return 'PHP ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
            function say(msg, isError) {
                toast.textContent = msg;
                toast.classList.toggle('is-error', !!isError);
                toast.classList.add('is-on');
                clearTimeout(toastTimer);
                toastTimer = setTimeout(function () { toast.classList.remove('is-on'); }, 2200);
            }
            function setText(id, text) { var el = document.getElementById(id); if (el) el.textContent = text; }

            // Recompute the count badges from the rows currently on screen.
            function refreshCounts() {
                var qty = 0, picked = 0, pickedQty = 0, rows = list.querySelectorAll('.cart-item');
                rows.forEach(function (row) {
                    var n = parseInt(row.querySelector('.stepper input').value, 10) || 0;
                    qty += n;
                    if (row.querySelector('.ci-pick').checked) { picked++; pickedQty += n; }
                    row.classList.toggle('is-unpicked', !row.querySelector('.ci-pick').checked);
                });
                setText('sumQty', pickedQty);
                document.querySelectorAll('.js-picked').forEach(function (el) { el.textContent = picked; });
                ['csCheckout', 'mbCheckout'].forEach(function (id) { var a = document.getElementById(id); if (a) a.classList.toggle('is-disabled', picked === 0); });
                var all = document.getElementById('cartSelectAll');
                if (all) { all.checked = picked === rows.length; all.indeterminate = picked > 0 && picked < rows.length; }
                var badge = document.getElementById('cart-count-badge');
                if (badge) { badge.textContent = qty; badge.style.display = qty > 0 ? '' : 'none'; }
            }

            // Select / deselect for checkout (one line, or all with "Select all"); saved right away.
            var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
            function saveSelection(selected, productId, variantId, undo) {
                var body = new FormData();
                body.set('select_items', '1');
                body.set('selected', selected ? '1' : '0');
                body.set('product_id', productId || 0);
                body.set('variant_id', variantId || '');
                body.set('csrf_token', csrf);
                // Show the new total right away from the ticked rows (prices are before VAT); the server's answer confirms it.
                var net = 0;
                list.querySelectorAll('.cart-item').forEach(function (row) {
                    if (row.querySelector('.ci-pick').checked) net += (parseFloat(row.getAttribute('data-unit')) || 0) * (parseInt(row.querySelector('.stepper input').value, 10) || 0);
                });
                applyTotals(net, Math.round(net * 1.12 * 100) / 100);
                fetch(location.pathname, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.success) throw new Error(data && data.message || 'failed');
                        applyTotals(parseFloat(data.subtotal_net), parseFloat(data.subtotal_gross));
                    })
                    .catch(function (e) { undo(); refreshCounts(); say(e && e.message && e.message !== 'failed' ? e.message : 'Could not update your selection. Please try again.', true); });
            }
            list.querySelectorAll('.ci-pick').forEach(function (box) {
                box.addEventListener('change', function () {
                    saveSelection(box.checked, box.getAttribute('data-product'), box.getAttribute('data-variant'), function () { box.checked = !box.checked; });
                });
            });
            var selectAll = document.getElementById('cartSelectAll');
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    var boxes = list.querySelectorAll('.ci-pick'), before = [];
                    boxes.forEach(function (b) { before.push(b.checked); b.checked = selectAll.checked; });
                    saveSelection(selectAll.checked, 0, '', function () { boxes.forEach(function (b, i) { b.checked = before[i]; }); });
                });
            }
            function applyTotals(net, gross) {
                setText('sumNet', fmt(net));
                setText('sumVat', fmt(net * 0.12));
                setText('sumPts', '+' + Number(net / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' pts');
                setText('sumTotal', fmt(gross));
                setText('mbTotal', fmt(gross));
                refreshCounts();
            }

            function syncButtons(row) {
                var input = row.querySelector('.stepper input');
                var max = parseInt(row.getAttribute('data-max'), 10) || 0;
                var v = parseInt(input.value, 10) || 1;
                row.querySelector('[data-step="-1"]').disabled = v <= 1;
                row.querySelector('[data-step="1"]').disabled = max > 0 && v >= max;
            }

            var timers = new WeakMap();
            function save(row) {
                var input = row.querySelector('.stepper input');
                var form = row.querySelector('.ci-qty');
                var unit = parseFloat(row.getAttribute('data-unit')) || 0;
                var max = parseInt(row.getAttribute('data-max'), 10) || 0;
                var qty = parseInt(input.value, 10);
                if (!qty || qty < 1) qty = 1;
                if (max > 0 && qty > max) { qty = max; say('Only ' + max + ' in stock.', true); }
                var previous = parseInt(input.getAttribute('data-saved'), 10) || qty;
                input.value = qty;
                syncButtons(row);
                if (qty === previous) return;

                row.classList.add('is-busy');
                var body = new FormData(form);
                body.set('quantity', qty);
                fetch(location.pathname, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.success) throw new Error('failed');
                        input.setAttribute('data-saved', qty);
                        row.querySelector('.ci-line').textContent = fmt(unit * qty);
                        applyTotals(parseFloat(data.subtotal_net), parseFloat(data.subtotal_gross));
                    })
                    .catch(function () {
                        input.value = previous;
                        syncButtons(row);
                        row.querySelector('.ci-line').textContent = fmt(unit * previous);
                        refreshCounts();
                        say('Could not update your cart. Please try again.', true);
                    })
                    .then(function () { row.classList.remove('is-busy'); });
            }
            function queueSave(row) {
                clearTimeout(timers.get(row));
                timers.set(row, setTimeout(function () { save(row); }, 350));
            }

            refreshCounts();
            list.querySelectorAll('.cart-item').forEach(function (row) {
                var input = row.querySelector('.stepper input');
                input.setAttribute('data-saved', parseInt(input.value, 10) || 1);
                syncButtons(row);
                row.querySelectorAll('[data-step]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var max = parseInt(row.getAttribute('data-max'), 10) || 0;
                        var next = (parseInt(input.value, 10) || 1) + parseInt(btn.getAttribute('data-step'), 10);
                        if (next < 1) next = 1;
                        if (max > 0 && next > max) { next = max; say('Only ' + max + ' in stock.', true); }
                        input.value = next;
                        row.querySelector('.ci-line').textContent = fmt((parseFloat(row.getAttribute('data-unit')) || 0) * next);
                        syncButtons(row);
                        refreshCounts();
                        queueSave(row);
                    });
                });
                input.addEventListener('change', function () { save(row); });
                // Enter inside the number box should save via fetch, not reload the page.
                row.querySelector('.ci-qty').addEventListener('submit', function (e) { e.preventDefault(); save(row); });
            });

            // Removing an item reloads the page: keep the scroll position so the list doesn't jump to the top.
            var SCROLL_KEY = 'cartScrollY';
            try {
                var y = sessionStorage.getItem(SCROLL_KEY);
                if (y !== null) { sessionStorage.removeItem(SCROLL_KEY); window.scrollTo(0, parseInt(y, 10) || 0); }
            } catch (e) {}
            list.querySelectorAll('.ci-remove').forEach(function (f) {
                f.addEventListener('submit', function () { try { sessionStorage.setItem(SCROLL_KEY, String(window.scrollY)); } catch (e) {} });
            });

            // Remember whether the "more items" group was open, so removing an item (page reload) doesn't collapse it.
            var more = document.getElementById('cartMore');
            if (more) {
                try { if (sessionStorage.getItem('cartMoreOpen') === '1') more.open = true; } catch (e) {}
                more.addEventListener('toggle', function () {
                    try { sessionStorage.setItem('cartMoreOpen', more.open ? '1' : '0'); } catch (e) {}
                });
            }
        })();
        </script>
    <?php else: ?>
        <div class="cart-empty">
            <div class="ce-icon"><i class="fas fa-shopping-cart"></i></div>
            <h3>Your cart is empty</h3>
            <p>Looks like you haven't added anything to your cart yet.</p>
            <a href="products.php" class="button">Start Shopping</a>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
