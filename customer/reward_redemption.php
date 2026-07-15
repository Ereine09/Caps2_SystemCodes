<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

$customer = current_customer();

// Modify table to allow NULL user_id for customer redemptions
mysqli_query($conn, "ALTER TABLE reward_redemptions MODIFY COLUMN user_id INT NULL");
mysqli_query($conn, "ALTER TABLE reward_redemptions DROP FOREIGN KEY IF EXISTS reward_redemptions_ibfk_2");

// Add voucher details columns if not exist
mysqli_query($conn, "ALTER TABLE reward_redemptions ADD COLUMN IF NOT EXISTS card_number VARCHAR(20) NULL");
mysqli_query($conn, "ALTER TABLE reward_redemptions ADD COLUMN IF NOT EXISTS pin_code VARCHAR(10) NULL");
mysqli_query($conn, "ALTER TABLE reward_redemptions ADD COLUMN IF NOT EXISTS expiry_date DATE NULL");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reward_id'])) {
    $reward_id = (int) $_POST['reward_id'];

    // Get reward details
    $reward_query = mysqli_query($conn, "SELECT * FROM rewards WHERE id = $reward_id");
    if (!$reward_query || mysqli_num_rows($reward_query) == 0) {
        die("Reward not found.");
    }
    $reward = mysqli_fetch_assoc($reward_query);

    // Check if customer can afford
    if ($customer['loyalty_points'] < $reward['points']) {
        die("Insufficient points.");
    }

    // Check stock
    if ($reward['stock'] <= 0) {
        die("Out of stock.");
    }

    // Start transaction
    mysqli_begin_transaction($conn);

    try {
        // Deduct points
        $new_points = $customer['loyalty_points'] - $reward['points'];
        mysqli_query($conn, "UPDATE customers SET loyalty_points = $new_points WHERE id = {$customer['id']}");

        // Decrease stock
        mysqli_query($conn, "UPDATE rewards SET stock = stock - 1 WHERE id = $reward_id");

        // Record redemption
        $reward_code = $reward['reward_code']; // Use existing reward_code to satisfy foreign key constraint
        $reward_name = mysqli_real_escape_string($conn, $reward['name']);
        $card_number = null;
        $pin_code = null;
        $expiry_date = null;
        if (preg_match('/₱50/', $reward['name'])) {
            $card_number = '7500017573373806';
            $pin_code = '8746';
            $expiry_date = '2125-12-30';
        }
        mysqli_query($conn, "INSERT INTO reward_redemptions (customer_id, user_id, reward_code, reward_name, points_used, card_number, pin_code, expiry_date) VALUES ({$customer['id']}, NULL, '$reward_code', '$reward_name', {$reward['points']}, " . ($card_number ? "'$card_number'" : "NULL") . ", " . ($pin_code ? "'$pin_code'" : "NULL") . ", " . ($expiry_date ? "'$expiry_date'" : "NULL") . ")");

        mysqli_commit($conn);

        // Display the gift card
        $page_title = 'Reward Redeemed';
        include __DIR__ . '/includes/header.php';

        // Extract monetary value
        $price_value = 'Voucher';
        if (preg_match('/[₱P](\d+)/', $reward['name'], $matches)) {
            $price_value = '₱' . $matches[1];
        }
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
                    <p>Thank you for being a loyal member!</p>
                </div>
                <div class="gift-card-body">
                    <div>
                        <strong>Remaining amount:</strong> <?php echo htmlspecialchars($price_value); ?>
                    </div>
                    <?php if (!empty($expiry_date)): ?>
                    <div>
                        <strong>Expires on:</strong> <?php echo date('m/d/Y', strtotime($expiry_date)); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($card_number) || !empty($pin_code)): ?>
                    <div style="margin-top: 20px;">
                        <p>If you have difficulty scanning your barcode, you can also enter the codes manually:</p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($card_number)): ?>
                    <div class="card-number">
                        <strong>Card n° :</strong>
                        <?php echo htmlspecialchars($card_number); ?>
                        <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($card_number); ?>')">Copy</button>
                    </div>
                    <?php endif; ?>
                    <?php
                    // Placeholder for barcode image if needed
                    // echo '<div style="text-align: center; margin: 20px 0;"><img src="path/to/barcode_generator.php?code=' . htmlspecialchars($card_number) . '" alt="Barcode" style="max-width: 100%;"></div>';
                    ?>

                    <?php if (!empty($pin_code)): ?>
                    <div class="pin-code">
                        <strong>Pin code:</strong> <?php echo htmlspecialchars($pin_code); ?>
                        <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($pin_code); ?>')">Copy</button>
                    </div>
                    <?php endif; ?>
                    <div style="margin-top: 20px;">
                        <strong>How to use rewards?</strong><br>
                        Thank you for being a loyal member of Decathlon Philippines! Here's a <?php echo htmlspecialchars($price_value); ?> gift card as a reward for your continuous support in our brand. Let's go!<br><br>
                        <strong>More information</strong><br>
                        This gift card can be used in all Darius Poultry Supply stores and online.
                    </div>
                    <div style="margin-top: 20px; text-align: center;">
                        <a href="reward_catalog.php" class="copy-btn" style="background: #10b981;">Back to Catalog</a>
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
    } catch (Exception $e) {
        mysqli_rollback($conn);
        die("Error processing redemption: " . $e->getMessage());
    }
} else {
    // If not POST, redirect to catalog
    header("Location: reward_catalog.php");
    exit();
}
?>