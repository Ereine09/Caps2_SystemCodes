<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

$customer = current_customer();
$customer_id = (int)$customer['id'];

// Ensure address management table exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS customer_addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    label VARCHAR(50) NOT NULL,
    full_address TEXT NOT NULL,
    phone VARCHAR(20) NULL,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_address'])) {
    $label = mysqli_real_escape_string($conn, $_POST['label']);
    $addr = mysqli_real_escape_string($conn, $_POST['address']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    
    mysqli_query($conn, "INSERT INTO customer_addresses (customer_id, label, full_address, phone) VALUES ($customer_id, '$label', '$addr', '$phone')");
    header("Location: addresses.php?success=1");
    exit();
}

$addresses = mysqli_query($conn, "SELECT * FROM customer_addresses WHERE customer_id = $customer_id ORDER BY created_at DESC");
?>
<?php $page_title = 'My Addresses'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    .address-card { background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-start; }
    .address-label { font-weight: 800; text-transform: uppercase; font-size: 0.75rem; color: #6366f1; margin-bottom: 8px; display: block; }
    .address-text { font-size: 0.95rem; color: #1e293b; line-height: 1.5; margin: 0; }
    .address-phone { color: #64748b; font-size: 0.85rem; margin-top: 5px; }
    
    .add-address-form { background: #f8fafc; padding: 25px; border-radius: 12px; border: 2px dashed #e2e8f0; margin-bottom: 30px; }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    @media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } }
</style>

<section class="customer-panel">
    <div class="section-header">
        <h2>My Addresses 📍</h2>
        <p>Manage your delivery locations for faster checkout.</p>
    </div>

    <div class="add-address-form">
        <h3 style="margin-top: 0; margin-bottom: 15px;">Add New Address</h3>
        <form method="POST">
            <div class="form-grid">
                <div class="input-box" style="margin-top:0;">
                    <input type="text" name="label" placeholder="Label (e.g. Home, Office)" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px;">
                </div>
                <div class="input-box" style="margin-top:0;">
                    <input type="text" name="phone" placeholder="Contact Phone" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px;">
                </div>
            </div>
            <div class="input-box" style="margin-top:15px;">
                <textarea name="address" placeholder="Full Address Details" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; height: 80px;"></textarea>
            </div>
            <button type="submit" name="add_address" class="button" style="margin-top: 15px;">Save Address</button>
        </form>
    </div>

    <div class="address-list">
        <?php if (mysqli_num_rows($addresses) > 0): ?>
            <?php while($addr = mysqli_fetch_assoc($addresses)): ?>
                <div class="address-card">
                    <div>
                        <span class="address-label"><?php echo htmlspecialchars($addr['label']); ?></span>
                        <p class="address-text"><?php echo nl2br(htmlspecialchars($addr['full_address'])); ?></p>
                        <div class="address-phone"><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($addr['phone']); ?></div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; background: white; border-radius: 12px; border: 1px solid #e2e8f0;">
                <i class="fas fa-map-marker-alt fa-2x" style="color: #cbd5e1; margin-bottom: 10px;"></i>
                <p style="color: #64748b;">No addresses saved yet.</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>