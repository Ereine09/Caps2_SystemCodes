<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_customer_login();

$customer = current_customer();
$rewards_res = mysqli_query($conn, "SELECT * FROM rewards ORDER BY points ASC");
$all_rewards = [];
while ($r = mysqli_fetch_assoc($rewards_res)) {
    $all_rewards[] = $r;
}

// Smart Reward Suggestion: the single closest in-stock reward the customer
// hasn't unlocked yet (rewards are already ordered by points ASC, so the
// first not-yet-affordable, in-stock one is the nearest real goal) - every
// number here comes straight from the same $customer/$reward data the
// per-card progress bars below already use, nothing invented.
$closest_reward = null;
foreach ($all_rewards as $r) {
    if ((float)$customer['loyalty_points'] < (float)$r['points'] && (int)$r['stock'] > 0) {
        $closest_reward = $r;
        break;
    }
}

$page_title = 'Reward Catalog';
include __DIR__ . '/includes/header.php';
?>

<style>
    .catalog-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    .catalog-card {
        background: var(--surface);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        transition: 0.3s;
        border: 1px solid var(--border);
        display: flex;
        flex-direction: column;
    }
    .catalog-card:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }
    
    .card-top {
        background: linear-gradient(135deg, #0082c3 0%, #005d8c 100%);
        padding: 30px 20px;
        text-align: center;
        color: white;
        position: relative;
        border-bottom: 2px dashed rgba(255,255,255,0.3);
    }
    /* Perforated holes effect */
    .card-top::before, .card-top::after {
        content: '';
        position: absolute;
        bottom: -10px;
        width: 20px;
        height: 20px;
        background: var(--dk-surface-2, #f6f4ff); /* Matches body background */
        border-radius: 50%;
    }
    .card-top::before { left: -10px; }
    .card-top::after { right: -10px; }

    .card-top i { font-size: 2rem; opacity: 0.2; position: absolute; right: 15px; top: 15px; }
    
    .gift-card-brand { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; margin-bottom: 5px; display: block; }
    .gift-card-value {
        font-size: 1.8rem;
        font-weight: 900;
        margin-bottom: 2px;
        line-height: 1.2;
        display: block;
    }
    .points-required-display {
        font-size: 1rem;
        font-weight: 700;
        background: rgba(255,255,255,0.2);
        padding: 2px 10px;
        border-radius: 4px;
        display: inline-block;
    }
    
    .card-body { padding: 20px; flex-grow: 1; display: flex; flex-direction: column; }
    .card-body h3 { margin: 0 0 10px 0; font-size: 1.1rem; color: var(--text); }
    .card-body p { color: var(--muted); font-size: 0.9rem; line-height: 1.5; margin-bottom: 20px; flex-grow: 1; }
    
    .card-footer { padding: 20px; border-top: 1px solid var(--border); background: var(--surface-2); }
    
    .redeem-btn {
        width: 100%;
        background: #0082c3; /* Decathlon Blue */
        color: white;
        border: none;
        padding: 12px;
        border-radius: 8px;
        font-weight: 800;
        text-transform: uppercase;
        cursor: pointer;
        transition: 0.2s;
    }
    .redeem-btn:hover { background: #005d8c; }
    .redeem-btn:disabled { background: var(--surface-3); color: var(--muted); cursor: not-allowed; box-shadow: none; transform: none; }

    .user-points-banner {
        background: var(--dk-primary, #6366f1);
        color: var(--dk-on-primary, white);
        padding: 20px;
        border-radius: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
    }

    /* Progress bar styles */
    .progress-section {
        margin-top: 15px;
        margin-bottom: 10px;
        text-align: left;
    }
    .points-left {
        font-size: 0.85rem;
        color: var(--muted);
        margin-bottom: 5px;
    }
    .progress-bar-container {
        background: #e2e8f0;
        border-radius: 5px;
        height: 8px;
        overflow: hidden;
        margin-bottom: 5px;
    }
    .progress-bar {
        background: var(--dk-primary, #6366f1); /* Decathlon-like blue for progress */
        height: 100%;
        border-radius: 5px;
        transition: width 0.5s ease-in-out;
    }
    .progress-percentage {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--link);
        text-align: right;
    }

    /* Progress Bar specific styles */
    .progress-container { margin: 15px 0; text-align: left; }
    .progress-text { font-size: 0.85rem; font-weight: 600; color: var(--muted); margin-bottom: 6px; display: flex; justify-content: space-between; }
    .progress-bar-bg { background: #e2e8f0; height: 8px; border-radius: 10px; overflow: hidden; position: relative; }
    .progress-bar-fill { 
        background: linear-gradient(90deg, var(--dk-primary, #6366f1), #0082c3); 
        height: 100%; 
        border-radius: 10px; 
        transition: width 0.5s ease; 
    }
    .unlocked-badge { color: var(--success); font-weight: 800; font-size: 0.85rem; display: flex; align-items: center; gap: 5px; }
    .locked-info { color: var(--muted); font-size: 0.85rem; font-weight: 600; }
    .percentage-label { color: #0082c3; font-weight: 800; }

    .reward-suggestion-banner {
        background: linear-gradient(135deg, var(--dk-warning-soft, #fef3c7), var(--dk-warning-soft, #fde68a));
        color: var(--warning);
        border: 1px solid #fcd34d;
        border-radius: 12px;
        padding: 14px 18px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 0.92rem;
    }
    .reward-suggestion-banner i { font-size: 1.3rem; flex-shrink: 0; }
    html[data-theme="dark"] .reward-suggestion-banner { background: rgba(252, 211, 77, 0.12); color: var(--warning); border-color: rgba(252, 211, 77, 0.35); }
</style>

<section class="customer-panel">
    <div class="welcome-header">
        <h1>Reward Catalog 🎁</h1>
        <p>Redeem your hard-earned points for exclusive vouchers and items.</p>
    </div>

    <div class="user-points-banner">
        <div>
            <span style="font-size: 0.9rem; opacity: 0.9;">Your Balance</span>
            <h2 style="margin: 5px 0 0 0; color: white;"><?php echo number_format($customer['loyalty_points'], 2); ?> Points</h2>
        </div>
        <i class="fas fa-star fa-2x"></i>
    </div>

    <?php if ($closest_reward): ?>
        <div class="reward-suggestion-banner">
            <i class="fas fa-magic"></i>
            <span>You're only <strong><?php echo number_format((float)$closest_reward['points'] - (float)$customer['loyalty_points'], 2); ?> points</strong> away from <strong><?php echo htmlspecialchars($closest_reward['name']); ?></strong>!</span>
        </div>
    <?php endif; ?>

    <?php
    // Free Shipping reward (earned by orders, not points): progress + claim / ready state, managed on My Vouchers.
    $fsc = null;
    try {
        if (is_file(__DIR__ . '/../app/helpers/free_shipping_helper.php')) {
            require_once __DIR__ . '/../app/helpers/notification_helper.php';
            require_once __DIR__ . '/../app/helpers/free_shipping_helper.php';
            free_shipping_sync($conn, (int) $customer['id']);
            $fsc = ['progress' => free_shipping_progress($conn, (int) $customer['id']), 'claimable' => null, 'ready' => null];
            foreach (free_shipping_rewards($conn, (int) $customer['id']) as $fr) {
                if ($fr['state'] === 'claimable' && !$fsc['claimable']) { $fsc['claimable'] = $fr; }
                if ($fr['state'] === 'claimed' && !$fsc['ready']) { $fsc['ready'] = $fr; }
            }
        }
    } catch (Throwable $e) {
        error_log('[reward_catalog] Free Shipping card: ' . $e->getMessage());
        $fsc = null;
    }
    ?>
    <?php if ($fsc): $fsc_done = $fsc['progress']['count'] % FREE_SHIP_ORDERS_PER_REWARD; if ($fsc['progress']['count'] > 0 && $fsc_done === 0) { $fsc_done = FREE_SHIP_ORDERS_PER_REWARD; } ?>
        <div class="fsc">
            <span class="fsc-ico"><i class="fas fa-truck-fast"></i></span>
            <div class="fsc-main">
                <strong>Free Shipping Voucher <span class="fsc-tag">Earned by orders, not points</span></strong>
                <?php if ($fsc['claimable']): ?>
                    <p class="fsc-hot"><i class="fas fa-clock"></i> You earned one! Claim it by <?php echo date('M j, g:i A', strtotime($fsc['claimable']['claim_by'])); ?> or it expires.</p>
                <?php elseif ($fsc['ready']): ?>
                    <p>Your voucher <code><?php echo htmlspecialchars($fsc['ready']['voucher_code']); ?></code> is ready. Pick it at checkout for free delivery.</p>
                <?php else: ?>
                    <p>Complete <?php echo FREE_SHIP_ORDERS_PER_REWARD; ?> orders in a month to earn free delivery. Claim it within <?php echo FREE_SHIP_CLAIM_DAYS; ?> days.</p>
                <?php endif; ?>
                <div class="fsc-bar"><span style="width: <?php echo round($fsc_done / FREE_SHIP_ORDERS_PER_REWARD * 100); ?>%;"></span></div>
                <small><?php echo $fsc_done; ?> of <?php echo FREE_SHIP_ORDERS_PER_REWARD; ?> orders this month</small>
            </div>
            <a class="fsc-btn<?php echo $fsc['claimable'] ? ' is-hot' : ''; ?>" href="my_rewards.php#free-shipping"><?php echo $fsc['claimable'] ? 'Claim now' : ($fsc['ready'] ? 'View voucher' : 'See details'); ?> <i class="fas fa-arrow-right"></i></a>
        </div>
        <style>
            .fsc { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin: 0 0 22px; padding: 18px 20px; border: 1px solid var(--border); border-radius: 16px; background: var(--surface); box-shadow: 0 4px 14px rgba(15, 23, 42, .05); }
            .fsc-ico { flex-shrink: 0; display: grid; place-items: center; width: 52px; height: 52px; border-radius: 14px; background: var(--primary-soft); color: var(--primary, #4f46e5); font-size: 1.35rem; }
            .fsc-main { flex: 1 1 260px; min-width: 0; }
            .fsc-main strong { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 1.05rem; color: var(--text); }
            .fsc-tag { padding: 2px 9px; border-radius: 999px; background: var(--surface-2); color: var(--muted); font-size: .72rem; font-weight: 700; }
            .fsc-main p { margin: 4px 0 8px; color: var(--muted); font-size: .9rem; }
            .fsc-main p code { font-weight: 700; color: var(--text); }
            .fsc-hot { color: var(--warning) !important; font-weight: 700; }
            .fsc-bar { height: 8px; border-radius: 999px; background: var(--surface-2); overflow: hidden; }
            .fsc-bar span { display: block; height: 100%; border-radius: inherit; background: var(--primary, #4f46e5); }
            .fsc-main small { display: block; margin-top: 4px; color: var(--muted); font-size: .78rem; }
            .fsc-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text) !important; font-weight: 700; font-size: .9rem; text-decoration: none; white-space: nowrap; }
            .fsc-btn.is-hot { border-color: transparent; background: var(--primary, #4f46e5); color: #fff !important; }
        </style>
    <?php endif; ?>

    <div class="catalog-grid">
        <?php foreach ($all_rewards as $reward):
            $can_afford = $customer['loyalty_points'] >= $reward['points'];
            $out_of_stock = $reward['stock'] <= 0;
            
            // Extract monetary value (e.g., "₱50") from Name
            $display_name = htmlspecialchars($reward['name']);
            $price_value = 'Voucher';
            if (preg_match('/[₱P](\d+)/', $reward['name'], $matches)) {
                $price_value = '₱' . $matches[1];
            }

            // Progress calculation
            $progress_pct = ($customer['loyalty_points'] / $reward['points']) * 100;
            $progress_pct = min(100, round($progress_pct));
            $pts_left = max(0, $reward['points'] - $customer['loyalty_points']);
        ?>
            <div class="catalog-card" id="reward-<?php echo $reward['id']; ?>" data-reward-id="<?php echo $reward['id']; ?>" data-reward-name="<?php echo htmlspecialchars($reward['name']); ?>" data-points-required="<?php echo $reward['points']; ?>">
                <div class="card-top">
                    <span class="gift-card-brand"><?php echo SYSTEM_NAME; ?></span>
                    <span class="gift-card-value">Gift Card of <?php echo $price_value; ?></span>
                    <div class="points-required-display"><?php echo number_format($reward['points']); ?> pts</div>
                    <i class="fas fa-gift"></i>
                </div>
                <div class="card-body">
                    <p><?php echo htmlspecialchars($reward['description']); ?></p>
                    
                    <div class="progress-container">
                        <div class="progress-text">
                            <?php if ($can_afford): ?>
                                <span class="unlocked-badge"><i class="fas fa-unlock"></i> Ready to be unlocked</span>
                            <?php else: ?>
                                <span class="locked-info"><?php echo number_format($pts_left, 2); ?> pts left to earn</span>
                                <span class="percentage-label"><?php echo $progress_pct; ?>%</span>
                            <?php endif; ?>
                        </div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width: <?php echo $progress_pct; ?>%;"></div>
                        </div>
                    </div>

                    <div style="font-size: 0.8rem; font-weight: 600; color: <?php echo $out_of_stock ? '#ef4444' : '#10b981'; ?>;">
                        <i class="fas <?php echo $out_of_stock ? 'fa-times-circle' : 'fa-check-circle'; ?>"></i>
                        <?php echo $out_of_stock ? 'Out of Stock' : $reward['stock'] . ' vouchers remaining'; ?>
                    </div>
                </div>
                <div class="card-footer">
                    <?php if ($out_of_stock): ?>
                        <button class="redeem-btn" disabled>Out of Stock</button>
                    <?php elseif (!$can_afford): ?>
                        <button class="redeem-btn" disabled>Insufficient Points</button>
                    <?php else: ?>
                        <button type="button" class="redeem-btn" onclick="openRedeemModal(<?php echo $reward['id']; ?>, '<?php echo htmlspecialchars($reward['name']); ?>', <?php echo $reward['points']; ?>)">Redeem Voucher</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Redemption Confirmation Modal -->
<div id="redemptionModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6);">
    <div style="background-color: #fefefe; margin: 10% auto; padding: 30px; border-radius: 15px; max-width: 450px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0; color: #1e293b; font-size: 1.5rem;"><i class="fas fa-lock"></i> Unlock this reward</h2>
            <span style="color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer;" onclick="closeRedeemModal()">&times;</span>
        </div>

        <div style="text-align: center; margin-bottom: 25px;">
            <h3 id="modalRewardName" style="color: #0082c3; font-size: 1.8rem; margin-bottom: 10px;"></h3>
            <p style="font-size: 1.1rem; color: #475569; margin-bottom: 5px;">You will use</p>
            <p id="modalPointsUsed" style="font-size: 2rem; font-weight: 900; color: #ef4444; margin-bottom: 20px;"></p>
            
            <div style="background: #f8fafc; border-radius: 10px; padding: 15px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="color: #64748b;">Your Current Balance:</span>
                    <span id="modalCurrentBalance" style="font-weight: 700; color: #1e293b;"></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 1.1rem; font-weight: 800; color: #1e293b;">
                    <span>Total balance left:</span>
                    <span id="modalBalanceLeft"></span>
                </div>
            </div>

            <p style="font-size: 0.9rem; color: #64748b;">Vouchers are valid after activation, in store and online.</p>
        </div>

        <div style="display: flex; gap: 15px; justify-content: center;">
            <button type="button" onclick="closeRedeemModal()" style="background: #e2e8f0; color: #475569; border: none; padding: 12px 25px; border-radius: 8px; cursor: pointer; font-weight: 700;">Not Now</button>
            <form method="POST" action="reward_redemption.php" style="margin: 0;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="reward_id" id="modalRewardId">
                <button type="submit" class="redeem-btn" style="background: #0082c3; color: white; border: none; padding: 12px 25px; border-radius: 8px; cursor: pointer; font-weight: 700;">Convert</button>
            </form>
        </div>
    </div>
</div>

<script>
    const customerLoyaltyPoints = <?php echo $customer['loyalty_points']; ?>;

    function openRedeemModal(rewardId, rewardName, pointsRequired) {
        const modal = document.getElementById('redemptionModal');
        document.getElementById('modalRewardName').innerText = rewardName;
        document.getElementById('modalPointsUsed').innerText = `-${pointsRequired} pts`;
        document.getElementById('modalCurrentBalance').innerText = `${customerLoyaltyPoints.toLocaleString()} pts`;
        
        const balanceLeft = customerLoyaltyPoints - pointsRequired;
        const modalBalanceLeftElement = document.getElementById('modalBalanceLeft');
        modalBalanceLeftElement.innerText = `${balanceLeft.toLocaleString()} pts`;

        if (balanceLeft < 0) {
            modalBalanceLeftElement.style.color = '#ef4444'; // Red for negative balance
        } else {
            modalBalanceLeftElement.style.color = '#10b981'; // Green for positive balance
        }

        document.getElementById('modalRewardId').value = rewardId;
        modal.style.display = 'block';
    }

    function closeRedeemModal() {
        document.getElementById('redemptionModal').style.display = 'none';
    }

    // Close modal if user clicks outside of it
    window.onclick = function(event) {
        const modal = document.getElementById('redemptionModal');
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>