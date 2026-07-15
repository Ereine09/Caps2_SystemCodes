<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

$customer = current_customer();
?>
<?php $page_title = 'My Account'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    .profile-card { background: white; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); padding: 30px; border: 1px solid #f1f5f9; }
    .profile-header { display: flex; align-items: center; gap: 20px; margin-bottom: 30px; border-bottom: 1px solid #f1f5f9; padding-bottom: 20px; }
    .profile-avatar { width: 80px; height: 80px; background: #6366f1; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 800; }
    .profile-info h3 { margin: 0; color: #1e293b; font-size: 1.5rem; }
    .profile-info p { margin: 5px 0 0; color: #64748b; }
    
    .detail-group { margin-bottom: 20px; }
    .detail-label { font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; display: block; }
    .detail-value { font-size: 1.1rem; color: #334155; font-weight: 600; }
</style>

<section class="customer-panel">
    <div class="section-header">
        <h2>Personal Information 👤</h2>
        <p>View and manage your account details.</p>
    </div>

    <div class="profile-card">
        <div class="profile-header">
            <div class="profile-avatar">
                <?php echo strtoupper(substr($customer['name'], 0, 1)); ?>
            </div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($customer['name']); ?></h3>
                <p>Member since <?php echo date('F Y', strtotime($customer['created_at'] ?? 'now')); ?></p>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
            <div class="detail-group">
                <span class="detail-label">Email Address</span>
                <span class="detail-value"><?php echo htmlspecialchars($customer['email']); ?></span>
            </div>
            <div class="detail-group">
                <span class="detail-label">Phone Number</span>
                <span class="detail-value"><?php echo htmlspecialchars($customer['phone']); ?></span>
            </div>
            <div class="detail-group" style="grid-column: span 2;">
                <span class="detail-label">Primary Address</span>
                <span class="detail-value"><?php echo htmlspecialchars($customer['address']); ?></span>
            </div>
        </div>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #f1f5f9; display: flex; gap: 15px;">
            <a href="addresses.php" class="button">Manage Addresses</a>
            <a href="logout.php" class="button button-secondary" style="background: #fee2e2; color: #ef4444; border: none;">Logout</a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>