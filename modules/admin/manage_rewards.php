<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$user_id = (int)$payload['user_id'];
$role = strtolower(trim($payload['role'] ?? 'staff'));

// Allow both admins and staff to manage rewards and stock
if ($role !== 'admin' && $role !== 'staff') {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

// --- Database Schema Check ---
mysqli_query(
    $conn,
    "CREATE TABLE IF NOT EXISTS rewards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reward_code VARCHAR(100) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        points DECIMAL(10,2) NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        validity_days INT(11) DEFAULT 7
    )"
);

// Add expiry_date column if it doesn't exist
$check_col = mysqli_query($conn, "SHOW COLUMNS FROM rewards LIKE 'expiry_date'");
if (mysqli_num_rows($check_col) == 0) {
    mysqli_query($conn, "ALTER TABLE rewards ADD COLUMN expiry_date DATE DEFAULT NULL AFTER stock");
}
// --- FIX: The previous version of this file was missing the validity_days column ---
$check_validity_col = mysqli_query($conn, "SHOW COLUMNS FROM rewards LIKE 'validity_days'");
if (mysqli_num_rows($check_validity_col) == 0) {
    mysqli_query($conn, "ALTER TABLE rewards ADD COLUMN validity_days INT(11) DEFAULT 7 AFTER stock");
}


$success_message = '';
$error_message = '';

// Promotions (Sale Prices / Voucher Promos tabs) - admin only; staff keep just the Rewards view.
$tab = in_array($_GET['tab'] ?? '', ['sales', 'vouchers'], true) && $role === 'admin' ? $_GET['tab'] : 'rewards';
if ($role === 'admin') {
    require __DIR__ . '/promotions_actions.php'; // handles promotion POSTs (redirects), loads tab data
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['act'])) { // 'act' = a promotion form
    csrf_require();
    if (isset($_POST['add_reward'])) {
        $code = trim($_POST['reward_code']);
        $name = trim($_POST['name']); // This is the reward name, e.g., "Gift Card of ₱50"
        $points = (float)$_POST['points'];
        $stock = (int)$_POST['stock'];
        $validity_days = (int)$_POST['validity_days'];
        $desc = trim($_POST['description']);

        try {
            $stmt = $conn->prepare("INSERT INTO rewards (reward_code, name, points, stock, validity_days, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdiis", $code, $name, $points, $stock, $validity_days, $desc);
            if ($stmt->execute()) {
                $success_message = "New reward item added successfully.";
                $details = "Added reward: $name ($code)";
                $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Manage Rewards', ?)");
                if ($log) {
                    $log->bind_param("is", $user_id, $details);
                $log->execute();
                }
            } else {
                $error_message = "Error adding reward: " . $conn->error;
            }
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) {
                $error_message = "The Reward Code '$code' is already in use. Please provide a unique code.";
            } else {
                $error_message = "Database Error: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_reward'])) {
        $id = (int)$_POST['reward_id'];
        $name = trim($_POST['name']);
        $points = (float)$_POST['points'];
        $stock = (int)$_POST['stock'];
        $validity_days = (int)$_POST['validity_days'];
        $desc = trim($_POST['description']);

        $stmt = $conn->prepare("UPDATE rewards SET name = ?, points = ?, stock = ?, validity_days = ?, description = ? WHERE id = ?");
        $stmt->bind_param("sdiisi", $name, $points, $stock, $validity_days, $desc, $id);
        if ($stmt->execute()) {
            $success_message = "Reward '$name' updated successfully.";
            $details = "Updated reward: $name (ID: $id)";
            $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Manage Rewards', ?)");
            if ($log) {
                $log->bind_param("is", $user_id, $details);
            $log->execute();
            }
        } else {
            $error_message = "Error updating reward: " . $conn->error;
        }
    } elseif (isset($_POST['delete_reward'])) {
        $id = (int)$_POST['reward_id'];

        // --- FIX: Check for existing redemptions before attempting to delete ---
        // First, get the reward_code from the reward ID
        $reward_code_stmt = $conn->prepare("SELECT reward_code, name FROM rewards WHERE id = ?");
        $reward_code_stmt->bind_param("i", $id);
        $reward_code_stmt->execute();
        $reward_data = $reward_code_stmt->get_result()->fetch_assoc();
        $reward_code_stmt->close();

        if ($reward_data) {
            $reward_code = $reward_data['reward_code'];
            $reward_name = $reward_data['name'];

            // Now, check if this reward_code exists in the redemptions table
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM reward_redemptions WHERE reward_code = ?");
            $check_stmt->bind_param("s", $reward_code);
            $check_stmt->execute();
            $redemption_count = (int)$check_stmt->get_result()->fetch_assoc()['count'];
            $check_stmt->close();

            if ($redemption_count > 0) {
                $error_message = "Cannot delete '$reward_name'. This reward has been redeemed $redemption_count time(s) and is part of your history.";
            } else {
                // No redemptions found, so it's safe to delete
                $stmt = $conn->prepare("DELETE FROM rewards WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $success_message = "Reward '$reward_name' has been deleted successfully.";
                    $details = "Deleted reward: $reward_name";
                    $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Manage Rewards', ?)");
                    if ($log) { $log->bind_param("is", $user_id, $details); $log->execute(); }
                }
            }
        }
    }
}

// Handle fetching a reward for editing
$edit_reward = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $conn->prepare("SELECT * FROM rewards WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $edit_reward = $result->fetch_assoc();
    }
    $stmt->close();
}

$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);

$rewards = mysqli_query($conn, "SELECT * FROM rewards ORDER BY points DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Rewards - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        .rw-layout { grid-template-columns: minmax(320px, 1fr) minmax(0, 1.6fr); }
        .rw-editing td { background: var(--primary-soft) !important; }
        @media (min-width: 761px) { .rw-layout .ui-btn-group { flex-wrap: nowrap; } }
        @media (max-width: 1100px) { .rw-layout { grid-template-columns: minmax(0, 1fr); } }
    </style>
</head>
<body>
    <?php if ($role === 'staff'): ?>
        <?php include __DIR__ . '/../staff/sidebar.php'; ?>
    <?php endif; ?>
    <?php if ($role === 'admin'): ?>
        <?php include __DIR__ . '/../customers/sidebar.php'; ?>
    <?php endif; ?>

    <div class="main-content">
        <div class="ui-page-head">
            <div>
                <h1 class="ui-page-title">Manage Rewards</h1>
                <p class="ui-page-sub"><?php echo $role === 'admin' ? 'Rewards customers redeem with points, plus sale prices and voucher promos. Pull any promo anytime.' : 'Add rewards customers can redeem with points, and keep their stock up to date.'; ?></p>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="ui-alert ui-alert-success" role="status"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success_message); ?></span></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="ui-alert ui-alert-danger" role="alert"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error_message); ?></span></div>
        <?php endif; ?>

        <?php if ($role === 'admin'): ?>
            <?php if (!empty($flash)): ?>
                <div class="ui-alert ui-alert-<?php echo $flash[0] === 'ok' ? 'success' : 'danger'; ?>" role="status"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($flash[1]); ?></span></div>
            <?php endif; ?>
            <?php foreach ($errors as $e): ?>
                <div class="ui-alert ui-alert-danger" role="alert"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($e); ?></span></div>
            <?php endforeach; ?>
            <div class="ui-tabs ui-tabs-fill" role="tablist">
                <a href="manage_rewards.php" class="<?php echo $tab === 'rewards' ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Rewards</a>
                <a href="manage_rewards.php?tab=sales" class="<?php echo $tab === 'sales' ? 'active' : ''; ?>"><i class="fas fa-tags"></i> Sale Prices <span class="ui-tab-count"><?php echo $active_sales; ?></span></a>
                <a href="manage_rewards.php?tab=vouchers" class="<?php echo $tab === 'vouchers' ? 'active' : ''; ?>"><i class="fas fa-ticket"></i> Voucher Promos <span class="ui-tab-count"><?php echo count(array_filter($voucher_rows, static fn($v) => (int) $v['any_active'] === 1)); ?></span></a>
            </div>
        <?php endif; ?>

        <?php if ($tab !== 'rewards'): ?>
            <?php include __DIR__ . '/promotions_view.php'; ?>
        <?php else: ?>
        <div class="ui-grid-main rw-layout">
            <section class="ui-card" id="reward-form">
                <div class="ui-card-head">
                    <div>
                        <h2 class="ui-card-title"><i class="fas <?php echo $edit_reward ? 'fa-edit' : 'fa-plus-circle'; ?>"></i> <?php echo $edit_reward ? 'Edit Reward' : 'Add New Reward'; ?></h2>
                        <p class="ui-card-sub"><?php echo $edit_reward ? 'Update the details of this reward.' : 'Required fields are marked *.'; ?></p>
                    </div>
                </div>
                <form method="POST" class="ui-card-body ui-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="reward_id" value="<?php echo (int) ($edit_reward['id'] ?? 0) ?: ''; ?>">
                    <div class="ui-form-grid">
                        <div class="ui-field">
                            <label for="rw_code">Reward code *</label>
                            <input type="text" id="rw_code" name="reward_code" placeholder="e.g. DPS-RW-01" required class="ui-input" value="<?php echo htmlspecialchars($edit_reward['reward_code'] ?? ''); ?>" <?php echo $edit_reward ? 'readonly' : ''; ?>>
                            <?php if ($edit_reward): ?><p class="ui-hint">The code can't be changed after the reward is created.</p><?php endif; ?>
                        </div>
                        <div class="ui-field">
                            <label for="rw_points">Points required *</label>
                            <input type="number" step="0.01" id="rw_points" name="points" required class="ui-input" value="<?php echo htmlspecialchars($edit_reward['points'] ?? ''); ?>">
                        </div>
                        <div class="ui-field span-2">
                            <label for="rw_name">Reward name *</label>
                            <input type="text" id="rw_name" name="name" placeholder="e.g. Gift Card of ₱50" required class="ui-input" value="<?php echo htmlspecialchars($edit_reward['name'] ?? ''); ?>">
                            <p class="ui-hint">For gift cards, include the value in the name (e.g., "Gift Card of ₱100").</p>
                        </div>
                        <div class="ui-field">
                            <label for="rw_stock"><?php echo $edit_reward ? 'Stock *' : 'Initial stock *'; ?></label>
                            <input type="number" id="rw_stock" name="stock" required class="ui-input" value="<?php echo htmlspecialchars($edit_reward['stock'] ?? ''); ?>">
                        </div>
                        <div class="ui-field">
                            <label for="rw_valid">Voucher validity (days)</label>
                            <input type="number" id="rw_valid" name="validity_days" class="ui-input" value="<?php echo htmlspecialchars($edit_reward['validity_days'] ?? '30'); ?>">
                        </div>
                        <div class="ui-field span-2">
                            <label for="rw_desc">Short description</label>
                            <textarea id="rw_desc" name="description" rows="3" class="ui-textarea"><?php echo htmlspecialchars($edit_reward['description'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="ui-form-actions">
                        <?php if ($edit_reward): ?><a href="manage_rewards.php" class="ui-btn">Cancel</a><?php endif; ?>
                        <button type="submit" name="<?php echo $edit_reward ? 'update_reward' : 'add_reward'; ?>" class="ui-btn ui-btn-primary"><i class="fas fa-save"></i> <?php echo $edit_reward ? 'Update Reward' : 'Save Reward'; ?></button>
                    </div>
                </form>
            </section>

            <section class="ui-card">
                <div class="ui-card-head">
                    <h2 class="ui-card-title"><i class="fas fa-warehouse"></i> Current Inventory</h2>
                </div>
                <?php $rw_rows = []; while ($r = mysqli_fetch_assoc($rewards)) { $rw_rows[] = $r; } ?>
                <?php if (empty($rw_rows)): ?>
                    <div class="ui-empty"><i class="fas fa-gift"></i><strong>No rewards yet</strong><p>Add your first reward using the form.</p></div>
                <?php else: ?>
                    <div class="ui-table-wrap">
                        <table class="ui-table ui-table-stack">
                            <thead><tr><th>Reward</th><th class="num">Points</th><th>Stock</th><th>Validity</th><th class="num">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($rw_rows as $r): ?>
                                    <tr<?php echo (int) ($edit_reward['id'] ?? 0) === (int) $r['id'] ? ' class="rw-editing"' : ''; ?>>
                                        <td data-label="Reward"><span><span class="ui-cell-main"><?php echo htmlspecialchars($r['name']); ?></span><span class="ui-cell-sub ui-mono"><?php echo htmlspecialchars($r['reward_code']); ?></span></span></td>
                                        <td data-label="Points" class="num"><?php echo number_format($r['points'], 2); ?></td>
                                        <td data-label="Stock">
                                            <?php if ($r['stock'] <= 0): ?>
                                                <span class="ui-badge ui-badge-danger"><i class="fas fa-exclamation-triangle"></i> Out of stock</span>
                                            <?php elseif ($r['stock'] <= 5): ?>
                                                <span class="ui-badge ui-badge-warning"><?php echo (int) $r['stock']; ?> left · Low</span>
                                            <?php else: ?>
                                                <span class="ui-badge ui-badge-success"><?php echo (int) $r['stock']; ?> in stock</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Validity"><?php echo htmlspecialchars($r['validity_days']); ?> days</td>
                                        <td data-label="Actions" class="actions">
                                            <div class="ui-btn-group" style="justify-content: flex-end;">
                                                <a href="manage_rewards.php?edit_id=<?php echo (int) $r['id']; ?>#reward-form" class="ui-btn ui-btn-sm"><i class="fas fa-edit"></i> Edit</a>
                                                <form method="POST" onsubmit="return confirm('Delete this reward? This cannot be undone.');" style="margin: 0;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="reward_id" value="<?php echo (int) $r['id']; ?>">
                                                    <button type="submit" name="delete_reward" class="ui-btn ui-btn-sm ui-btn-danger-soft"><i class="fas fa-trash"></i> Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>