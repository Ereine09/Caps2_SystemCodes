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
        
        // --- New Voucher Generation Logic ---
        $voucher_code = strtoupper('V-' . bin2hex(random_bytes(6))); // e.g., V-A8B2C5D6E7F1
        $discount_value = 0.00;
        if (preg_match('/[₱P](\d+(\.\d{1,2})?)/', $reward['name'], $matches)) {
            $discount_value = (float) $matches[1];
        }

        $expiry_date = null;
        if (!empty($reward['validity_days'])) {
            $expiry_date = date('Y-m-d H:i:s', strtotime('+' . (int)$reward['validity_days'] . ' days'));
        }

        // Insert into tbl_vouchers to make it usable at checkout
        $voucher_stmt = mysqli_prepare($conn, "INSERT INTO tbl_vouchers (code, description, discount_type, discount_value, usage_limit, active, expires_at) VALUES (?, ?, 'fixed', ?, 1, 1, ?)");
        mysqli_stmt_bind_param($voucher_stmt, 'ssds', $voucher_code, $reward_name, $discount_value, $expiry_date);
        mysqli_stmt_execute($voucher_stmt);
        $new_voucher_id = mysqli_insert_id($conn);
        mysqli_stmt_close($voucher_stmt);

        // Record the redemption, linking it to the new voucher code
        $redemption_stmt = mysqli_prepare($conn, "INSERT INTO reward_redemptions (customer_id, user_id, reward_code, reward_name, points_used, card_number, expiry_date) VALUES (?, NULL, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($redemption_stmt, 'issdss', $customer['id'], $reward_code, $reward_name, $reward['points'], $voucher_code, $expiry_date);
        mysqli_stmt_execute($redemption_stmt);
        // --- End New Logic ---

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
                    <?php if (!empty($expiry_date)): // Check if expiry_date is not empty ?>
                    <div>
                        <strong>Expires on:</strong> <?php echo date('m/d/Y', strtotime($expiry_date)); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($voucher_code)): // Check if either exists to show the instruction ?>
                    <div style="margin-top: 20px;">
                        <p>Use the code below during checkout to apply your discount.</p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($voucher_code)): // Check if card_number is not empty ?>
                    <div class="card-number">
                        <strong>Voucher Code:</strong>
                        <?php echo htmlspecialchars($voucher_code); ?>
                        <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($voucher_code); ?>')">Copy</button>
                    </div>
                    <?php endif; ?>
                    <div style="margin-top: 20px;">
                        <strong>How to use rewards?</strong><br>
                        Thank you for being a loyal member of Darius Poultry Supply! Here's a <?php echo htmlspecialchars($price_value); ?> gift card as a reward for your continuous support in our shop. Let's go!<br><br>
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