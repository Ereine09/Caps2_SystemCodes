<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login(); // Ensure customer is logged in

$customer = current_customer();

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
                background: white;
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
                background-color: #fee2e2;
                color: #dc2626;
                border: 1px solid #fca5a5;
            }
            .status-expired {
                background-color: #fef3c7;
                color: #d97706;
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

<section class="customer-panel">
    <div class="welcome-header">
        <h1>My Rewards 🎁</h1>
        <p>View and access your redeemed vouchers.</p>
    </div>

    <?php if (empty($redemptions)): ?>
        <div style="text-align: center; padding: 50px;">
            <i class="fas fa-gift fa-3x" style="color: #cbd5e1; margin-bottom: 20px;"></i>
            <h3>No rewards redeemed yet</h3>
            <p>Start shopping and earning points to redeem rewards!</p>
            <a href="reward_catalog.php" class="copy-btn" style="background: #0082c3; color: white; padding: 12px 25px; text-decoration: none; display: inline-block; margin-top: 15px;">Browse Rewards</a>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
            <?php foreach ($redemptions as $redemption): 
                $price_value = 'Voucher';
                if (preg_match('/[₱P](\d+)/', $redemption['reward_name'], $matches)) {
                    $price_value = '₱' . $matches[1];
                }
                
                // Fast status checking for the grid dashboard list
                $redeemed_time = strtotime($redemption['redeemed_at']);
                $days_diff = (time() - $redeemed_time) / (60 * 60 * 24);
                $is_used = (isset($redemption['status']) && strtolower($redemption['status']) === 'used');
                $is_expired = ($days_diff > 7);
            ?>
                <div style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); position: relative; opacity: <?php echo ($is_used || $is_expired) ? '0.7' : '1'; ?>;">
                    <h3><?php echo htmlspecialchars($redemption['reward_name']); ?></h3>
                    <p><strong>Points Used:</strong> <?php echo number_format($redemption['points_used'], 2); ?></p>
                    <p><strong>Redeemed:</strong> <?php echo date('M d, Y', strtotime($redemption['redeemed_at'])); ?></p>
                    
                    <div style="margin-top: 15px;">
                        <?php if ($is_used): ?>
                            <span style="color: #dc2626; font-weight: bold;">[Used]</span>
                        <?php elseif ($is_expired): ?>
                            <span style="color: #d97706; font-weight: bold;">[Expired]</span>
                        <?php else: ?>
                            <span style="color: #16a34a; font-weight: bold;">[Active]</span>
                        <?php endif; ?>
                        
                        <a href="?view=<?php echo $redemption['id']; ?>" style="background: #0082c3; color: white; padding: 6px 14px; border-radius: 6px; text-decoration: none; float: right; font-size: 0.9rem;">View Details</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>