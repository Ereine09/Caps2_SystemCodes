<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

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
            .pin-code {
                font-size: 1.2rem;
                text-align: center;
                margin: 20px 0;
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
        </style>

        <div class="customer-panel">
            <div class="gift-card-container">
                <div class="gift-card-header">
                    <h1>Gift Card of <?php echo htmlspecialchars($price_value); ?></h1>
                    <p>Redeemed on <?php echo date('M d, Y', strtotime($redemption['redeemed_at'])); ?></p>
                </div>
                <div class="gift-card-body">
                    <div>
                        <strong>Remaining amount:</strong> <?php echo htmlspecialchars($price_value); ?>
                    </div>
                    <?php if (!empty($redemption['expiry_date'])): // Check if expiry_date is not empty ?>
                    <div>
                        <strong>Expires on:</strong> <?php echo date('m/d/Y', strtotime($redemption['expiry_date'])); ?>
                    </div>
                    <?php endif; ?>                    
                    <?php if (!empty($redemption['card_number'])): // Check if card_number (voucher code) is not empty ?>
                    <div style="margin-top: 20px;">
                        <p>Use the code below during checkout to apply your discount.</p>
                    </div>
                    <div class="card-number">
                        <strong>Voucher Code:</strong>
                        <?php echo htmlspecialchars($redemption['card_number']); ?>
                        <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($redemption['card_number']); ?>')">Copy</button>
                    </div>
                    <?php endif; ?>
                    <div style="margin-top: 20px;">
                        <strong>How to use rewards?</strong><br>
                        Thank you for being a loyal member of Darius Poultry Supplies! Here's a <?php echo htmlspecialchars($price_value); ?> gift card as a reward for your continuous support in our shop. Let's go!<br><br>
                        <strong>More information</strong><br>
                        This gift card can be used in all Darius Poultry Supply stores and online.
                    </div>
                    <div style="margin-top: 20px; text-align: center;">
                        <a href="my_rewards.php" class="copy-btn" style="background: #10b981;">Back to My Rewards</a>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function copyToClipboard(text) {
                navigator.clipboard.writeText(text).then(function() {
                    alert('Copied to clipboard!');
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
            <a href="reward_catalog.php" class="copy-btn" style="background: #0082c3; padding: 12px 25px;">Browse Rewards</a>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
            <?php foreach ($redemptions as $redemption): 
                $price_value = 'Voucher';
                if (preg_match('/[₱P](\d+)/', $redemption['reward_name'], $matches)) {
                    $price_value = '₱' . $matches[1];
                }
            ?>
                <div style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h3><?php echo htmlspecialchars($redemption['reward_name']); ?></h3>
                    <p><strong>Points Used:</strong> <?php echo number_format($redemption['points_used'], 2); ?></p>
                    <p><strong>Redeemed:</strong> <?php echo date('M d, Y', strtotime($redemption['redeemed_at'])); ?></p>
                    <a href="?view=<?php echo $redemption['id']; ?>" style="background: #0082c3; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: inline-block; margin-top: 10px;">View Voucher</a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>