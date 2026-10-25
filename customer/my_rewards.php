<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login(); // Ensure customer is logged in

$customer = current_customer();

// Free Shipping rewards: every 10 completed orders in a month (app/helpers/free_shipping_helper.php).
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/notification_helper.php';
$fs_ok = false;
$fs_rewards = [];
$fs_progress = ['count' => 0, 'next' => 10];
$fs_flash = $_SESSION['fs_flash'] ?? null;
unset($_SESSION['fs_flash']);
try {
    $fs_file = __DIR__ . '/../app/helpers/free_shipping_helper.php';
    if (!is_file($fs_file)) {
        throw new RuntimeException('app/helpers/free_shipping_helper.php is missing (upload it).');
    }
    require_once $fs_file;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_free_shipping'])) {
        csrf_require();
        $fs_claim = free_shipping_claim($conn, (int) $customer['id'], (int) $_POST['claim_free_shipping']);
        $_SESSION['fs_flash'] = [$fs_claim['ok'] ? 'ok' : 'error', $fs_claim['message']];
        header('Location: ' . BASE_URL . '/customer/my_rewards.php#free-shipping');
        exit;
    }
    free_shipping_sync($conn, (int) $customer['id']);
    $fs_rewards = array_values(array_filter(free_shipping_rewards($conn, (int) $customer['id']), static fn($r) => $r['state'] !== 'used')); // used ones are not listed
    $fs_progress = free_shipping_progress($conn, (int) $customer['id']);
    $fs_ok = true;
} catch (Throwable $e) {
    // Never take the whole page down: the rest of My Vouchers still works.
    error_log('[my_rewards] Free Shipping section unavailable: ' . $e->getMessage());
}

// Promo vouchers from the shop (admin Promotions page): ones given to this customer + public promo codes.
$promo_vouchers = [];
try {
    promo_ensure_schema($conn);
    $pv_stmt = $conn->prepare(
        "SELECT code, description, discount_type, discount_value, min_order_amount, usage_limit, used_count,
                per_customer_limit, promo_kind, expires_at
         FROM tbl_vouchers
         WHERE active = 1 AND (expires_at IS NULL OR expires_at > NOW())
           AND ((promo_kind = 'assigned' AND owner_customer_id = ?) OR promo_kind = 'public')
         ORDER BY promo_kind = 'assigned' DESC, created_at DESC"
    );
    $pv_cid = (int) $customer['id'];
    $pv_stmt->bind_param('i', $pv_cid);
    $pv_stmt->execute();
    foreach ($pv_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $pv) {
        if ((int) $pv['usage_limit'] > 0 && (int) $pv['used_count'] >= (int) $pv['usage_limit']) {
            continue; // used up
        }
        if ($pv['promo_kind'] === 'public' && (int) $pv['per_customer_limit'] > 0
            && voucher_customer_use_count($conn, $pv['code'], $pv_cid) >= (int) $pv['per_customer_limit']) {
            continue; // this customer already used it
        }
        $promo_vouchers[] = $pv;
    }
    $pv_stmt->close();
} catch (Throwable $e) {
    error_log('[my_rewards] Promo vouchers unavailable: ' . $e->getMessage());
}

$page_title = 'My Rewards';
include __DIR__ . '/includes/header.php';

// Check if viewing a specific voucher
$view_id = isset($_GET['view']) ? (int) $_GET['view'] : 0;
if ($view_id > 0) {
    // Fetch the redemption
    $redemption_query = mysqli_query($conn, "SELECT * FROM reward_redemptions WHERE id = $view_id AND customer_id = {$customer['id']}");
    if ($redemption_query && mysqli_num_rows($redemption_query) > 0) {
        $redemption = mysqli_fetch_assoc($redemption_query);

        // Extract monetary value
        $price_value = 'Voucher';
        if (preg_match('/[₱P](\d+)/', $redemption['reward_name'], $matches)) {
            $price_value = '₱' . $matches[1];
        }

        // Logic for Expiry (7 days from redemption date) and Usage Status
        $redeemed_time = strtotime($redemption['redeemed_at']);
        $current_time = time();
        $days_difference = ($current_time - $redeemed_time) / (60 * 60 * 24);

        $is_used = (isset($redemption['status']) && strtolower($redemption['status']) === 'used');
        $is_expired = ($days_difference > 7);

        // Display the voucher
        ?>
        <style>
            .gift-card-container {
                max-width: 600px;
                margin: 50px auto;
                background: var(--surface);
                border-radius: 16px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            .gift-card-header {
                background: linear-gradient(135deg, #0082c3 0%, #005d8c 100%);
                color: white;
                padding: 30px;
                text-align: center;
            }
            .gift-card-body {
                padding: 30px;
            }
            .card-number {
                font-size: 1.5rem;
                font-weight: bold;
                text-align: center;
                margin: 20px 0;
                letter-spacing: 2px;
            }
            .copy-btn {
                background: #0082c3;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 4px;
                cursor: pointer;
                margin-left: 10px;
            }
            .copy-btn:hover {
                background: #005d8c;
            }
            .status-badge {
                display: block;
                text-align: center;
                padding: 15px;
                margin: 20px 0;
                border-radius: 8px;
                font-weight: bold;
                font-size: 1.2rem;
            }
            .status-used {
                background-color: var(--danger-soft);
                color: var(--danger);
                border: 1px solid #fca5a5;
            }
            .status-expired {
                background-color: var(--warning-soft);
                color: var(--warning);
                border: 1px solid #fcd34d;
            }
        </style>

        <div class="customer-panel">
            <div class="gift-card-container">
                <div class="gift-card-header">
                    <h1>Gift Card of <?php echo htmlspecialchars($price_value); ?></h1>
                    <p>Redeemed on <?php echo date('M d, Y', strtotime($redemption['redeemed_at'])); ?></p>
                </div>
                <div class="gift-card-body">
                    
                    <?php if ($is_used): ?>
                        <!-- Condition 1: Voucher has been used -->
                        <div class="status-badge status-used">
                            ⚠️ This voucher has already been used.
                        </div>
                        <p style="text-align: center; color: #64748b;">This reward card was successfully applied to a previous order and is no longer valid.</p>

                    <?php elseif ($is_expired): ?>
                        <!-- Condition 2: Voucher is past 7 days -->
                        <div class="status-badge status-expired">
                            ⏳ This voucher has expired.
                        </div>
                        <p style="text-align: center; color: #64748b;">Rewards must be used within 7 days of redemption. This voucher expired on <?php echo date('M d, Y', strtotime($redemption['redeemed_at'] . ' + 7 days')); ?>.</p>

                    <?php else: ?>
                        <!-- Condition 3: Voucher is valid and active -->
                        <div>
                            <strong>Remaining amount:</strong> <?php echo htmlspecialchars($price_value); ?>
                        </div>
                        <div>
                            <strong>Expires on:</strong> <?php echo date('M d, Y', strtotime($redemption['redeemed_at'] . ' + 7 days')); ?>
                        </div>                    
                        
                        <?php if (!empty($redemption['card_number'])): ?>
                        <div style="margin-top: 20px;">
                            <p>Use the code below during checkout to apply your discount.</p>
                        </div>
                        <div style="margin-top: 10px; text-align: center; font-size: 0.85rem; color: #666;">
                            <i class="fas fa-info-circle"></i> This voucher is valid for 7 days from redemption.
                            It can be used in-store or online.
                        </div>
                        <div class="card-number">
                            <strong>Voucher Code:</strong>
                            <?php echo htmlspecialchars($redemption['card_number']); ?>
                            <button class="copy-btn" onclick="copyToClipboard(this, '<?php echo htmlspecialchars($redemption['card_number']); ?>')">Copy</button>
                        </div>
                        <?php endif; ?>

                        <div style="margin-top: 20px;">
                            <strong>How to use rewards?</strong><br>
                            Thank you for being a loyal member of Darius Poultry Supplies! Here's a <?php echo htmlspecialchars($price_value); ?> gift card as a reward for your continuous support in our shop. Let's go!<br><br>
                            <strong>More information</strong><br>
                            This gift card can be used in all Darius Poultry Supply stores and online.
                        </div>
                    <?php endif; ?>

                    <div style="margin-top: 30px; text-align: center;">
                        <a href="my_rewards.php" class="copy-btn" style="background: #10b981; text-decoration: none;">Back to My Rewards</a>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function copyToClipboard(buttonElement, text) {
                navigator.clipboard.writeText(text).then(function() {
                    const originalText = buttonElement.innerText;
                    buttonElement.innerText = 'Copied!';
                    setTimeout(function() {
                        buttonElement.innerText = originalText;
                    }, 1500); // Revert after 1.5 seconds
                });
            }
        </script>

        <?php
        include __DIR__ . '/includes/footer.php';
        exit();
    }
}

// List all redemptions
$redemptions_query = mysqli_query($conn, "SELECT * FROM reward_redemptions WHERE customer_id = {$customer['id']} ORDER BY redeemed_at DESC");
$redemptions = [];
if ($redemptions_query) {
    while ($row = mysqli_fetch_assoc($redemptions_query)) {
        $redemptions[] = $row;
    }
}
?>

<?php
// Same status rule as the voucher's detail page above: used, or expired 7 days after redeeming.
$mv_items = [];
$mv_counts = ['all' => 0, 'active' => 0, 'expired' => 0];
foreach ($redemptions as $rd) {
    $value = 'Voucher';
    if (preg_match('/[₱P](\d+)/u', (string) $rd['reward_name'], $m)) {
        $value = '₱' . $m[1];
    }
    $redeemed_ts = strtotime($rd['redeemed_at']);
    $expires_ts = $redeemed_ts + 7 * 86400;
    $state = strtolower((string) ($rd['status'] ?? '')) === 'used' ? 'used' : (time() > $expires_ts ? 'expired' : 'active');
    if ($state === 'used') {
        continue; // already used vouchers are not listed any more
    }
    if ($state === 'used') {
        continue; // already-used vouchers are not listed
    }
    $mv_counts['all']++;
    $mv_counts[$state]++;
    $mv_items[] = ['row' => $rd, 'value' => $value, 'state' => $state, 'redeemed' => $redeemed_ts, 'expires' => $expires_ts,
        'online' => strtoupper((string) ($rd['channel'] ?? '')) === 'ONLINE'];
}
// Active first, then newest.
usort($mv_items, static fn($x, $y) => [$x['state'] !== 'active', -$x['redeemed']] <=> [$y['state'] !== 'active', -$y['redeemed']]);
$mv_state_label = ['active' => 'Active', 'used' => 'Used', 'expired' => 'Expired'];
?>
<section class="customer-panel mv">
    <div class="mv-head">
        <div>
            <h1>My Vouchers</h1>
            <p>Your redeemed rewards and vouchers. Use active ones at checkout or show them in store.</p>
        </div>
        <a class="mv-browse" href="reward_catalog.php"><i class="fas fa-gift"></i> Browse rewards</a>
    </div>

    <?php if ($promo_vouchers): ?>
    <section class="pv-box" id="promo-vouchers">
        <h2><i class="fas fa-ticket"></i> Promo Vouchers</h2>
        <p>Shop promos you can use on your next online order. Type or pick the code at checkout.</p>
        <ul class="pv-list">
            <?php foreach ($promo_vouchers as $pv): ?>
                <li class="pv-item">
                    <div class="pv-main">
                        <strong><?php echo htmlspecialchars(voucher_discount_label($pv)); ?><?php echo $pv['description'] ? ' &middot; ' . htmlspecialchars($pv['description']) : ''; ?></strong>
                        <span>
                            <?php echo $pv['promo_kind'] === 'assigned' ? 'Just for you' : 'Open to everyone'; ?>
                            <?php if ((float) $pv['min_order_amount'] > 0): ?> &middot; Min. spend ₱<?php echo number_format((float) $pv['min_order_amount'], 2); ?><?php endif; ?>
                            <?php if ($pv['expires_at']): ?> &middot; Until <?php echo date('M j, Y g:i A', strtotime($pv['expires_at'])); ?><?php endif; ?>
                        </span>
                    </div>
                    <button type="button" class="pv-code" title="Copy code" onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.code); this.querySelector('em').textContent = 'Copied';" data-code="<?php echo htmlspecialchars($pv['code']); ?>"><code><?php echo htmlspecialchars($pv['code']); ?></code> <em>Copy</em></button>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <style>
        .pv-box { margin: 18px 0 8px; padding: 18px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); }
        .pv-box h2 { margin: 0 0 4px; font-size: 1.1rem; color: var(--text); display: flex; gap: 8px; align-items: center; }
        .pv-box h2 i { color: var(--danger, #dc2626); }
        .pv-box > p { margin: 0; color: var(--muted); font-size: .9rem; }
        .pv-list { list-style: none; margin: 14px 0 0; padding: 0; display: grid; gap: 10px; }
        .pv-item { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 14px; border: 1px dashed var(--danger, #dc2626); border-radius: 12px; background: var(--danger-soft); }
        .pv-main { display: grid; gap: 3px; min-width: 0; }
        .pv-main strong { color: var(--text); }
        .pv-main span { color: var(--text-2); font-size: .85rem; }
        .pv-code { display: inline-flex; align-items: center; gap: 8px; padding: 7px 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); color: var(--text); cursor: pointer; font: inherit; }
        .pv-code code { font-weight: 800; letter-spacing: .04em; }
        .pv-code em { font-style: normal; font-size: .78rem; color: var(--link); font-weight: 700; }
    </style>
    <?php endif; ?>

    <!-- Free Shipping rewards: every 10 completed orders in a month -->
    <?php if ($fs_ok): ?>
    <section class="fs-box" id="free-shipping">
        <div class="fs-head">
            <span class="fs-ico"><i class="fas fa-truck-fast"></i></span>
            <div>
                <h2>Free Shipping Rewards</h2>
                <p>Every <?php echo FREE_SHIP_ORDERS_PER_REWARD; ?> completed orders in a month earns you a Free Shipping voucher. Claim it within <?php echo FREE_SHIP_CLAIM_DAYS; ?> days, or it expires.</p>
            </div>
        </div>
        <?php if ($fs_flash): ?>
            <div class="fs-flash fs-flash-<?php echo $fs_flash[0] === 'ok' ? 'ok' : 'error'; ?>"><?php echo htmlspecialchars($fs_flash[1]); ?></div>
        <?php endif; ?>
        <?php
            // Right after the 10th (20th ...) order the bar shows full instead of starting over at 0.
            $fs_done = $fs_progress['count'] % FREE_SHIP_ORDERS_PER_REWARD;
            $fs_just = $fs_progress['count'] > 0 && $fs_done === 0;
            if ($fs_just) { $fs_done = FREE_SHIP_ORDERS_PER_REWARD; }
        ?>
        <div class="fs-progress">
            <div class="fs-progress-top">
                <span><strong><?php echo $fs_done; ?></strong> of <?php echo FREE_SHIP_ORDERS_PER_REWARD; ?> orders this month</span>
                <span><?php echo $fs_just ? 'Voucher earned! Keep ordering for the next one' : $fs_progress['next'] . ' more for your next voucher'; ?></span>
            </div>
            <div class="fs-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo FREE_SHIP_ORDERS_PER_REWARD; ?>" aria-valuenow="<?php echo $fs_done; ?>"><span style="width: <?php echo round($fs_done / FREE_SHIP_ORDERS_PER_REWARD * 100); ?>%;"></span></div>
            <small>Only completed orders count. The count starts again on the 1st of each month.</small>
        </div>
        <?php if ($fs_rewards): ?>
            <ul class="fs-list">
                <?php foreach (array_filter($fs_rewards, static fn($x) => $x['state'] !== 'used') as $fr):
                    $fr_month = date('F Y', strtotime($fr['period'] . '-01'));
                    $fr_left = (int) ceil((strtotime($fr['claim_by']) - time()) / 86400);
                ?>
                    <li class="fs-item fs-<?php echo htmlspecialchars($fr['state']); ?>">
                        <div class="fs-item-main">
                            <strong>Free Shipping Voucher</strong>
                            <span>For <?php echo (int) $fr['milestone']; ?> orders in <?php echo htmlspecialchars($fr_month); ?></span>
                            <?php if ($fr['state'] === 'claimable'): ?>
                                <span class="fs-due"><i class="fas fa-clock"></i> Claim by <?php echo date('M j, Y g:i A', strtotime($fr['claim_by'])); ?> (<?php echo max(0, $fr_left); ?> day<?php echo $fr_left === 1 ? '' : 's'; ?> left)</span>
                            <?php elseif ($fr['state'] === 'claimed'): ?>
                                <span>Code <code><?php echo htmlspecialchars($fr['voucher_code']); ?></code> &middot; use at checkout by <?php echo date('M j, Y', strtotime($fr['voucher_expires_at'])); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="fs-item-side">
                            <?php if ($fr['state'] === 'claimable'): ?>
                                <form method="post" action="my_rewards.php">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" name="claim_free_shipping" value="<?php echo (int) $fr['id']; ?>" class="fs-claim"><i class="fas fa-hand-pointer"></i> Claim</button>
                                </form>
                            <?php elseif ($fr['state'] === 'claimed'): ?>
                                <span class="fs-tag ok">Ready to use</span>
                            <?php elseif ($fr['state'] === 'used'): ?>
                                <span class="fs-tag muted">Used</span>
                            <?php elseif ($fr['state'] === 'voucher_expired'): ?>
                                <span class="fs-tag muted">Voucher expired</span>
                            <?php else: ?>
                                <span class="fs-tag warn">Expired (not claimed)</span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <style>
        .fs-box { margin: 18px 0 26px; padding: 18px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); }
        .fs-head { display: flex; gap: 14px; align-items: flex-start; }
        .fs-head h2 { margin: 0 0 4px; font-size: 1.1rem; color: var(--text); }
        .fs-head p { margin: 0; color: var(--muted); font-size: .9rem; line-height: 1.5; }
        .fs-ico { flex-shrink: 0; display: grid; place-items: center; width: 44px; height: 44px; border-radius: 12px; background: var(--primary-soft); color: var(--primary, #4f46e5); font-size: 1.15rem; }
        .fs-flash { margin-top: 14px; padding: 10px 14px; border-radius: 10px; font-weight: 600; font-size: .9rem; }
        .fs-flash-ok { background: var(--success-soft); color: var(--success); }
        .fs-flash-error { background: var(--danger-soft); color: var(--danger); }
        .fs-progress { margin-top: 16px; }
        .fs-progress-top { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; font-size: .88rem; color: var(--text-2); }
        .fs-bar { height: 10px; margin: 8px 0 6px; border-radius: 999px; background: var(--surface-2); overflow: hidden; }
        .fs-bar span { display: block; height: 100%; border-radius: inherit; background: var(--primary, #4f46e5); }
        .fs-progress small { color: var(--muted); font-size: .78rem; }
        .fs-list { list-style: none; margin: 16px 0 0; padding: 0; display: grid; gap: 10px; }
        .fs-item { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 14px; border: 1px solid var(--border); border-radius: 12px; }
        .fs-item.fs-claimable { border-color: var(--primary, #4f46e5); background: var(--primary-soft); }
        .fs-item.fs-expired, .fs-item.fs-used, .fs-item.fs-voucher_expired { opacity: .7; }
        .fs-item-main { display: grid; gap: 3px; min-width: 0; }
        .fs-item-main strong { color: var(--text); }
        .fs-item-main span { color: var(--muted); font-size: .85rem; overflow-wrap: anywhere; }
        .fs-item-main code { font-weight: 700; color: var(--text); }
        .fs-due { color: var(--warning) !important; font-weight: 600; }
        .fs-claim { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border: 0; border-radius: 10px; background: var(--primary, #4f46e5); color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        .fs-tag { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: .78rem; font-weight: 700; }
        .fs-tag.ok { background: var(--success-soft); color: var(--success); }
        .fs-tag.warn { background: var(--warning-soft); color: var(--warning); }
        .fs-tag.muted { background: var(--surface-2); color: var(--muted); }
    </style>
    <?php endif; ?>

    <?php if (empty($mv_items)): ?>
        <div class="mv-empty">
            <span class="mv-empty-ico"><i class="fas fa-ticket"></i></span>
            <h3>No vouchers yet</h3>
            <p>Earn points with every purchase, then redeem them for discount vouchers.</p>
            <a class="mv-browse" href="reward_catalog.php"><i class="fas fa-gift"></i> Browse rewards</a>
        </div>
    <?php else: ?>
        <!-- Same tab bar as the admin pages (ui-kit .ui-tabs .ui-tabs-fill) -->
        <div class="ui-tabs ui-tabs-fill mv-tabs" role="tablist" aria-label="Filter vouchers">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'expired' => 'Expired'] as $k => $label): ?>
                <button type="button" role="tab" class="mv-tab" data-filter="<?php echo $k; ?>" aria-selected="<?php echo $k === 'all' ? 'true' : 'false'; ?>">
                    <?php echo $label; ?> <span class="ui-tab-count"><?php echo $mv_counts[$k]; ?></span>
                </button>
            <?php endforeach; ?>
        </div>
        <div class="mv-grid" id="mv-grid">
            <?php foreach ($mv_items as $it): $rd = $it['row']; ?>
                <article class="mv-ticket is-<?php echo $it['state']; ?>" data-state="<?php echo $it['state']; ?>">
                    <div class="mv-stub">
                        <strong><?php echo htmlspecialchars($it['value']); ?></strong>
                        <span>OFF</span>
                    </div>
                    <div class="mv-body">
                        <div class="mv-top">
                            <h3><?php echo htmlspecialchars($rd['reward_name']); ?></h3>
                            <span class="mv-pill is-<?php echo $it['state']; ?>"><?php echo $mv_state_label[$it['state']]; ?></span>
                        </div>
                        <ul class="mv-meta">
                            <li><i class="fas fa-star"></i> <?php echo number_format((float) $rd['points_used'], 2); ?> points</li>
                            <li><i class="fas <?php echo $it['online'] ? 'fa-globe' : 'fa-store'; ?>"></i> <?php echo $it['online'] ? 'Online checkout' : 'In-store'; ?></li>
                            <li><i class="fas fa-calendar-check"></i> Redeemed <?php echo date('M j, Y', $it['redeemed']); ?></li>
                            <li class="<?php echo $it['state'] === 'active' ? 'mv-due' : ''; ?>"><i class="fas fa-hourglass-half"></i>
                                <?php echo $it['state'] === 'expired' ? 'Expired' : ($it['state'] === 'used' ? 'Valid until' : 'Use by'); ?> <?php echo date('M j, Y', $it['expires']); ?></li>
                        </ul>
                        <a class="mv-view" href="?view=<?php echo (int) $rd['id']; ?>"><?php echo $it['state'] === 'active' ? 'Use voucher' : 'View details'; ?> <i class="fas fa-chevron-right"></i></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="mv-none" id="mv-none" hidden>No vouchers here.</p>
        <script>
        (function () {
            var tabs = document.querySelectorAll('.mv-tab'), cards = document.querySelectorAll('.mv-ticket'), none = document.getElementById('mv-none');
            tabs.forEach(function (t) {
                t.addEventListener('click', function () {
                    var f = t.getAttribute('data-filter'), shown = 0;
                    tabs.forEach(function (x) { x.setAttribute('aria-selected', x === t ? 'true' : 'false'); });
                    cards.forEach(function (c) { var on = f === 'all' || c.getAttribute('data-state') === f; c.hidden = !on; if (on) shown++; });
                    none.hidden = shown > 0;
                });
            });
        })();
        </script>
    <?php endif; ?>
    <style>
        .mv-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 14px; flex-wrap: wrap; margin-bottom: 8px; }
        .fs-box + .mv-tabs, .mv-head + .mv-tabs { margin-top: 22px; }
        .mv-head h1 { margin: 0 0 4px; font-size: 1.6rem; color: var(--text); }
        .mv-head p { margin: 0; color: var(--muted); font-size: .92rem; }
        .mv-browse { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; background: var(--primary, #4f46e5); color: #fff !important; font-weight: 700; font-size: .9rem; text-decoration: none; }
        .mv-browse:hover { filter: brightness(1.08); }
        .mv-tabs { margin: 22px 0 20px; } /* clear space from the header (or the Free Shipping card) and the vouchers */
        .mv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr)); gap: 16px; }
        .mv-ticket { display: flex; min-width: 0; border: 1px solid var(--border); border-radius: 16px; background: var(--surface); box-shadow: 0 6px 18px rgba(15, 23, 42, .06); overflow: hidden; }
        .mv-ticket[hidden] { display: none; }
        .mv-stub { position: relative; flex: 0 0 96px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; padding: 14px 8px; background: linear-gradient(160deg, var(--primary, #4f46e5), #7c3aed); color: #fff; text-align: center; }
        .mv-stub::after { content: ''; position: absolute; top: 10px; bottom: 10px; right: -1px; border-right: 2px dashed rgba(255, 255, 255, .55); }
        .mv-stub strong { font-size: 1.5rem; line-height: 1.1; letter-spacing: -.01em; overflow-wrap: anywhere; }
        .mv-stub span { font-size: .7rem; font-weight: 800; letter-spacing: .14em; opacity: .9; }
        .mv-ticket.is-used .mv-stub, .mv-ticket.is-expired .mv-stub { background: linear-gradient(160deg, #94a3b8, #64748b); }
        .mv-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 10px; padding: 14px 16px; }
        .mv-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .mv-top h3 { margin: 0; font-size: 1rem; color: var(--text); line-height: 1.35; }
        .mv-pill { flex-shrink: 0; padding: 3px 10px; border-radius: 999px; font-size: .74rem; font-weight: 800; }
        .mv-pill.is-active { background: var(--success-soft); color: var(--success); }
        .mv-pill.is-used { background: var(--surface-2); color: var(--muted); }
        .mv-pill.is-expired { background: var(--warning-soft); color: var(--warning); }
        .mv-meta { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: 1fr; gap: 5px; color: var(--text-2); font-size: .82rem; }
        .mv-meta li { display: flex; align-items: center; gap: 7px; min-width: 0; }
        .mv-meta i { width: 14px; color: var(--muted); text-align: center; }
        .mv-meta .mv-due { color: var(--warning); font-weight: 700; }
        .mv-meta .mv-due i { color: var(--warning); }
        .mv-view { align-self: flex-end; display: inline-flex; align-items: center; gap: 6px; margin-top: auto; padding: 8px 14px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text) !important; font-size: .85rem; font-weight: 700; text-decoration: none; }
        .mv-ticket.is-active .mv-view { border-color: transparent; background: var(--primary, #4f46e5); color: #fff !important; }
        .mv-ticket.is-used, .mv-ticket.is-expired { opacity: .85; }
        .mv-empty, .mv-none { text-align: center; padding: 40px 16px; border: 1px dashed var(--border); border-radius: 16px; background: var(--surface); color: var(--muted); }
        .mv-empty h3 { margin: 12px 0 6px; color: var(--text); }
        .mv-empty p { margin: 0 0 16px; }
        .mv-empty-ico { display: inline-grid; place-items: center; width: 60px; height: 60px; border-radius: 50%; background: var(--primary-soft); color: var(--primary, #4f46e5); font-size: 1.5rem; }
        @media (max-width: 420px) {
            .mv-stub { flex-basis: 78px; }
            .mv-stub strong { font-size: 1.25rem; }
        }
    </style>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>