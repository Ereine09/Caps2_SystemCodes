<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_customer_login();

$customer = current_customer();
$customer_id = (int)$customer['id'];

$success_message = '';
$error_message = '';
// Which tab should be showing after this request. Only forced on a POST that
// belongs to a specific tab, so an error (e.g. wrong current password) keeps
// that tab open instead of silently landing back on Personal Information.
$active_tab = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure the table exists before any operations
    ensure_customer_tables($conn);

    if (isset($_POST['update_personal_info'])) {
        csrf_require();
        $active_tab = 'personal';

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '' || $email === '' || $phone === '') {
            $error_message = 'Name, email, and phone number are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'Please enter a valid email address.';
        } else {
            $existing = get_customer_by_email($email);
            if ($existing && (int)$existing['id'] !== $customer_id) {
                $error_message = 'Another account is already using that email address.';
            } else {
                $stmt_personal = $conn->prepare("UPDATE customers SET name = ?, email = ?, phone = ? WHERE id = ?");
                $stmt_personal->bind_param('sssi', $name, $email, $phone, $customer_id);
                if ($stmt_personal->execute()) {
                    $success_message = 'Your personal information was updated successfully!';
                    // Refresh $customer so the rest of this render (avatar initial,
                    // name, email, phone) reflects the change immediately.
                    $customer = current_customer();
                } else {
                    $error_message = 'Unable to update your information right now. Please try again.';
                }
                $stmt_personal->close();
            }
        }
    } elseif (isset($_POST['change_password'])) {
        csrf_require();
        $active_tab = 'security';

        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $cred_stmt = $conn->prepare("SELECT password FROM customer_login_credentials WHERE customer_id = ? LIMIT 1");
        $cred_stmt->bind_param('i', $customer_id);
        $cred_stmt->execute();
        $credential = $cred_stmt->get_result()->fetch_assoc();
        $cred_stmt->close();

        if (!$credential || !password_verify($current_password, $credential['password'])) {
            $error_message = 'Your current password is incorrect.';
        } elseif (strlen($new_password) < 8) {
            $error_message = 'New password must be at least 8 characters.';
        } elseif ($new_password !== $confirm_password) {
            $error_message = 'New passwords do not match.';
        } else {
            ensure_customer_password_meta_schema($conn);
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $up_stmt = $conn->prepare("UPDATE customer_login_credentials SET password = ?, password_changed_at = NOW() WHERE customer_id = ?");
            $up_stmt->bind_param('si', $new_hash, $customer_id);
            if ($up_stmt->execute()) {
                $success_message = 'Your password was changed successfully!';
                // password_changed_at just moved forward, which would otherwise log THIS
                // browser out too on its next request (see customer_token_is_stale()) -
                // issue a fresh token now so only other devices/sessions are affected.
                // (Not customer_login(): that helper expects the customer_id/customer_email
                // keys from get_customer_account_by_email(), not this row's id/email.)
                setJWTCookie(generateJWT($customer_id, $customer['email'], 'customer'));
            } else {
                $error_message = 'Unable to change your password right now. Please try again.';
            }
            $up_stmt->close();
        }
    } elseif (isset($_POST['add_address'])) {
        $active_tab = 'personal';
        $label = mysqli_real_escape_string($conn, $_POST['label']);
        $addr = mysqli_real_escape_string($conn, $_POST['address']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        $latitude = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;

        // Coordinates are required here (not just at checkout) so a saved address always has
        // them - this is what used to leave checkout showing "A delivery location is required"
        // for an address that already looked correct: it was saved before this map picker
        // existed, so it had no coordinates at all. Same 5km + real-city check checkout uses.
        if (trim($label) === '' || trim($addr) === '') {
            $error_message = 'Address label and address are required.';
        } elseif (trim($phone) === '') {
            $error_message = 'Contact number is required.';
        } elseif ($latitude === null || $longitude === null) {
            $error_message = 'Please pin your delivery location on the map.';
        } elseif (calculate_distance_km(14.652023833372462, 120.97593745927361, $latitude, $longitude) > 5.0) {
            $error_message = 'Sorry, that location is outside our delivery area (Caloocan City, within 5km of our shop).';
        } elseif (!is_location_in_service_area($latitude, $longitude)) {
            $error_message = 'Sorry, we only deliver within Caloocan City (Caloocan, 10th Avenue, Grace Park). Please pin a location inside our service area.';
        }

        // Start transaction
        if ($error_message === '') {
            mysqli_begin_transaction($conn);
        }
        try {
            if ($error_message !== '') {
                throw new Exception($error_message);
            }
            // 1. Set all existing addresses for this customer to not default
            $count_res = mysqli_query($conn, "SELECT COUNT(*) as total FROM customer_addresses WHERE customer_id = $customer_id");
            $is_first_address = (int)mysqli_fetch_assoc($count_res)['total'] === 0;

            $is_default = isset($_POST['is_default']) || $is_first_address ? 1 : 0;
            if ($is_default) mysqli_query($conn, "UPDATE customer_addresses SET is_default = 0 WHERE customer_id = $customer_id");

            // 2. Insert the new address and set it as default
            $stmt_insert = $conn->prepare("INSERT INTO customer_addresses (customer_id, label, full_address, phone, latitude, longitude, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_insert->bind_param('isssddi', $customer_id, $label, $addr, $phone, $latitude, $longitude, $is_default);
            $stmt_insert->execute();
            $stmt_insert->close();

            mysqli_commit($conn);
            $success_message = "New address added and set as primary successfully!";
        } catch (Exception $e) {
            if ($error_message === '') {
                mysqli_rollback($conn);
                $error_message = "Error adding address: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['set_default_address'])) {
        $active_tab = 'personal';
        $address_id = (int)$_POST['address_id'];

        mysqli_begin_transaction($conn);
        try {
            // 1. Set all existing addresses for this customer to not default
            $stmt_reset_default = $conn->prepare("UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?");
            $stmt_reset_default->bind_param('i', $customer_id);
            $stmt_reset_default->execute();
            $stmt_reset_default->close();

            // 2. Set the selected address as default
            $stmt_set_default = $conn->prepare("UPDATE customer_addresses SET is_default = 1 WHERE id = ? AND customer_id = ?");
            $stmt_set_default->bind_param('ii', $address_id, $customer_id);
            $stmt_set_default->execute();
            $stmt_set_default->close();

            mysqli_commit($conn);
            $success_message = "Primary address updated successfully!";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error_message = "Error setting primary address: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_address'])) {
        $active_tab = 'personal';
        $address_id = (int)$_POST['address_id'];
        $stmt_delete = $conn->prepare("DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?");
        $stmt_delete->bind_param('ii', $address_id, $customer_id);
        $stmt_delete->execute();
        $stmt_delete->close();
        $success_message = "Address deleted successfully!";
    } elseif (isset($_POST['update_address'])) {
        $active_tab = 'personal';
        $address_id = (int)$_POST['address_id'];
        $label = mysqli_real_escape_string($conn, $_POST['label']);
        $addr = mysqli_real_escape_string($conn, $_POST['address']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        // The edit modal's map pre-fills these from the address's existing coordinates
        // (if any), so an address that already had them just resubmits the same values;
        // one with none (the old "no map at save time" addresses) forces a fresh pin.
        $latitude = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;

        $existing_stmt = $conn->prepare("SELECT id FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1");
        $existing_stmt->bind_param('ii', $address_id, $customer_id);
        $existing_stmt->execute();
        $existing_address = $existing_stmt->get_result()->fetch_assoc();
        $existing_stmt->close();

        if (trim($label) === '' || trim($addr) === '' || trim($phone) === '' || !$existing_address) {
            $error_message = !$existing_address ? 'Address not found.' : 'Address label, address, and contact number are required.';
        } elseif ($latitude === null || $longitude === null) {
            $error_message = 'Please pin your delivery location on the map.';
        } elseif (calculate_distance_km(14.652023833372462, 120.97593745927361, $latitude, $longitude) > 5.0) {
            $error_message = 'Sorry, that location is outside our delivery area (Caloocan City, within 5km of our shop).';
        } elseif (!is_location_in_service_area($latitude, $longitude)) {
            $error_message = 'Sorry, we only deliver within Caloocan City (Caloocan, 10th Avenue, Grace Park). Please pin a location inside our service area.';
        } else {
            $stmt_update = $conn->prepare("UPDATE customer_addresses SET label = ?, full_address = ?, phone = ?, latitude = ?, longitude = ? WHERE id = ? AND customer_id = ?");
            $stmt_update->bind_param('sssddii', $label, $addr, $phone, $latitude, $longitude, $address_id, $customer_id);
            $stmt_update->execute();
            $stmt_update->close();
            $success_message = "Address updated successfully!";
        }
    }
}
?>
<?php $page_title = 'My Account'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<!-- Free Map Alternative: Leaflet.js (No API Key or Billing Required) - same library
     checkout.php uses, so a saved address's coordinates are captured directly here
     instead of relying on a free-text Nominatim search at checkout time. -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
<script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>

<style>
    .profile-card { background: var(--customer-surface, #fff); border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); padding: 30px; border: 1px solid var(--customer-border, #f1f5f9); }
    .profile-header { display: flex; align-items: center; gap: 20px; margin-bottom: 30px; border-bottom: 1px solid var(--customer-border, #f1f5f9); padding-bottom: 20px; }
    .profile-avatar { width: 80px; height: 80px; background: #6366f1; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 800; flex-shrink: 0; }
    .profile-info h3 { margin: 0; color: var(--customer-text, #1e293b); font-size: 1.5rem; }
    .profile-info p { margin: 5px 0 0; color: var(--customer-muted, #64748b); }

    /* Account Settings tabs */
    .acct-tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--customer-border, #e2e8f0); margin-bottom: 24px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .acct-tab-btn {
        flex: 0 0 auto; padding: 12px 20px; border: none; background: none; cursor: pointer;
        font-weight: 700; font-size: 0.92rem; color: var(--customer-muted, #64748b);
        border-bottom: 3px solid transparent; white-space: nowrap; margin-bottom: -2px;
        transition: color .15s ease, border-color .15s ease; font-family: inherit;
    }
    .acct-tab-btn:hover { color: var(--customer-text, #1e293b); }
    .acct-tab-btn.active { color: var(--customer-link, #6366f1); border-bottom-color: var(--customer-link, #6366f1); }
    .acct-tab-panel { display: none; }
    .acct-tab-panel.active { display: block; }
    @media (max-width: 480px) { .acct-tab-btn { padding: 10px 14px; font-size: 0.85rem; } }

    .address-card { background: var(--customer-surface-2, #f8fafc); border-radius: 12px; padding: 15px; border: 1px solid var(--customer-border, #e2e8f0); margin-bottom: 10px; display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap; }
    .address-card.default { border-color: #6366f1; background: color-mix(in srgb, #6366f1 8%, var(--customer-surface, #fff)); }
    .address-label { font-weight: 800; text-transform: uppercase; font-size: 0.75rem; color: var(--link); margin-bottom: 8px; display: block; }
    .address-text { font-size: 0.95rem; color: var(--customer-text, #1e293b); line-height: 1.5; margin: 0; }
    .address-phone { color: var(--customer-muted, #64748b); font-size: 0.85rem; margin-top: 5px; }
    .address-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
    .address-actions button {
        padding: 6px 12px; border-radius: 8px; font-size: 0.8rem; cursor: pointer;
        border: 1px solid var(--customer-border, #cbd5e1); background: var(--customer-surface, #fff); color: var(--customer-text, #475569);
    }
    .address-actions button.set-default { background: #6366f1; color: white; border-color: #6366f1; }
    .address-actions button.set-default:hover { background: #4f46e5; }
    .address-actions button.delete { background: #fee2f2; color: var(--danger); border-color: #fca5a5; }
    .address-actions button.delete:hover { background: #ef4444; color: white; }
    html[data-theme="dark"] .address-actions button.delete { background: #3a1414; color: #fca5a5; border-color: #7f1d1d; }

    .add-address-form { background: var(--customer-surface-2, #f8fafc); padding: 25px; border-radius: 12px; border: 2px dashed var(--customer-border, #e2e8f0); margin-bottom: 30px; }

    .detail-group { margin-bottom: 20px; }
    .detail-label { font-size: 0.75rem; font-weight: 700; color: var(--customer-muted, #94a3b8); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; display: block; }
    .detail-value { font-size: 1.1rem; color: var(--customer-text, #334155); font-weight: 600; }

    /* Shared form-group styling: personal info, change password, edit-address modal */
    .form-group { margin-bottom: 15px; }
    .form-group label {
        display: block;
        font-weight: 600;
        color: var(--customer-text, #475569);
        margin-bottom: 6px;
        font-size: 0.9rem;
    }
    .form-group input[type="text"], .form-group input[type="email"], .form-group input[type="password"], .form-group textarea {
        width: 100%; padding: 10px 14px; border: 1px solid var(--customer-border, #cbd5e1); border-radius: 8px; box-sizing: border-box;
        font-size: 0.95rem; background: var(--customer-surface, #fff); color: var(--customer-text, #1e293b);
    }
    .form-group textarea { min-height: 100px; resize: vertical; }
    .form-group small.hint { display: block; margin-top: 5px; color: var(--customer-muted, #64748b); font-size: 0.8rem; }
    .password-field { position: relative; }
    .password-field .password-toggle {
        position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
        background: transparent; border: 0; color: var(--customer-muted, #6b7280); cursor: pointer; padding: 6px;
    }
    .settings-section { max-width: 480px; }

    /* Privacy tab */
    .privacy-block { background: var(--customer-surface-2, #f8fafc); border: 1px solid var(--customer-border, #e2e8f0); border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; }
    .privacy-block h4 { margin: 0 0 8px; color: var(--customer-text, #1e293b); font-size: 1rem; }
    .privacy-block p { margin: 0; color: var(--customer-muted, #64748b); font-size: 0.9rem; line-height: 1.6; }

    /* Edit Address Modal */
    .modal {
        display: none; position: fixed; z-index: 1001; left: 0; top: 0;
        width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6);
        align-items: center; justify-content: center;
    }
    .modal-content {
        background-color: var(--customer-surface, #fff); margin: auto; padding: 25px; border-radius: 12px;
        width: 90%; max-width: 550px; box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        animation: modal-fade-in 0.3s;
    }
    @keyframes modal-fade-in {
        from { transform: translateY(-30px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    /* Address map picker (Add New Address form + Edit Address modal) */
    .addr-map-field { margin-bottom: 15px; }
    .addr-map-field > label { display: block; font-weight: 600; color: var(--customer-text, #475569); margin-bottom: 6px; font-size: 0.9rem; }
    .addr-map { height: 260px; border-radius: 8px; border: 1px solid var(--customer-border, #cbd5e1); }
    .addr-map-msg { margin-top: 8px; padding: 10px 12px; border-radius: 8px; font-size: 0.82rem; font-weight: 600; }
    .addr-map-msg.valid { background: var(--success-soft); color: var(--success); border: 1px solid #a7f3d0; }
    .addr-map-msg.invalid { background: var(--danger-soft); color: var(--danger); border: 1px solid #fecaca; }
    html[data-theme="dark"] .addr-map-msg.valid { background: #052e21; color: #6ee7b7; border-color: #065f46; }
    html[data-theme="dark"] .addr-map-msg.invalid { background: #3a1414; color: #fca5a5; border-color: #7f1d1d; }
</style>

<section class="customer-panel">
    <div class="section-header">
        <h2>Account Settings ⚙️</h2>
        <p>View and manage your account details.</p>
    </div>

    <?php if ($success_message): ?>
        <div class="alert-message alert-success" style="margin-bottom: 20px;"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert-message alert-error" style="margin-bottom: 20px;"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <div class="acct-tabs" role="tablist" aria-label="Account settings">
        <button type="button" class="acct-tab-btn" data-tab="personal" role="tab">Personal Information</button>
        <button type="button" class="acct-tab-btn" data-tab="security" role="tab">Security</button>
        <button type="button" class="acct-tab-btn" data-tab="privacy" role="tab">Privacy</button>
    </div>

    <?php
    // Fetch the current primary address
    $default_address_res = mysqli_query($conn, "SELECT * FROM customer_addresses WHERE customer_id = $customer_id AND is_default = 1 LIMIT 1");
    $default_address = $default_address_res ? mysqli_fetch_assoc($default_address_res) : null;
    ?>

    <div id="tab-personal" class="acct-tab-panel">
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

        <form method="POST" class="settings-section">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="pi_name">Full Name</label>
                <input type="text" id="pi_name" name="name" value="<?php echo htmlspecialchars($customer['name']); ?>" required>
            </div>
            <div class="form-group">
                <label for="pi_email">Email Address</label>
                <input type="email" id="pi_email" name="email" value="<?php echo htmlspecialchars($customer['email']); ?>" required>
            </div>
            <div class="form-group">
                <label for="pi_phone">Phone Number</label>
                <input type="text" id="pi_phone" name="phone" value="<?php echo htmlspecialchars($customer['phone']); ?>" required>
            </div>
            <button type="submit" name="update_personal_info" class="button">Save Changes</button>
        </form>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 25px;">
            <div class="detail-group">
                <span class="detail-label">Current Loyalty Points</span>
                <span class="detail-value"><?php echo number_format((float) ($customer['loyalty_points'] ?? 0), 2); ?> points</span>
            </div>
            <div class="detail-group">
                <span class="detail-label">Primary Address</span>
                <span class="detail-value">
                    <?php if ($default_address): ?>
                        <?php echo nl2br(htmlspecialchars($default_address['full_address'])); ?>
                        <br><small style="color: var(--customer-muted, #64748b);"><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($default_address['phone']); ?></small>
                    <?php else: ?>
                        No primary address set. Please add one below.
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--customer-border, #f1f5f9);">
            <h3 style="margin-top: 0; margin-bottom: 20px; color: var(--customer-text, #1e293b);"><i class="fas fa-map-marker-alt"></i> Manage My Addresses</h3>

            <div class="add-address-form">
                <h4 style="margin-top: 0; margin-bottom: 15px; color: #334155;">Add New Address</h4>
                <form method="POST" id="add-address-form">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div class="input-box" style="margin-top:0;">
                            <input type="text" name="label" placeholder="Label (e.g. Home, Office)" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px;">
                        </div>
                        <div class="input-box" style="margin-top:0;">
                            <input type="text" name="phone" placeholder="Contact Phone" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px;">
                        </div>
                        <div class="input-box" style="margin-top:0; grid-column: 1 / -1;">
                            <textarea name="address" placeholder="Full Address Details" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; height: 60px;"></textarea>
                        </div>
                    </div>

                    <div class="addr-map-field">
                        <label>Pin your location on the map <span style="color:#ef4444;">*</span></label>
                        <div id="addAddressMap" class="addr-map"></div>
                        <input type="hidden" name="latitude" id="new_address_latitude">
                        <input type="hidden" name="longitude" id="new_address_longitude">
                        <div id="add-address-map-msg"></div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; color: #334155;">
                            <input type="checkbox" name="is_default" value="1"> Set as primary address
                        </label>
                        <button type="submit" name="add_address" class="button">Save New Address</button>
                    </div>
                </form>
            </div>

            <div class="address-list">
                <?php
                $addresses_res = mysqli_query($conn, "SELECT * FROM customer_addresses WHERE customer_id = $customer_id ORDER BY is_default DESC, created_at DESC");
                if ($addresses_res && mysqli_num_rows($addresses_res) > 0): ?>
                    <?php while($addr = mysqli_fetch_assoc($addresses_res)): ?>
                        <div class="address-card <?php echo $addr['is_default'] ? 'default' : ''; ?>">
                            <div>
                                <span class="address-label">
                                    <?php echo htmlspecialchars($addr['label']); ?>
                                    <?php if ($addr['is_default']): ?>
                                        <span style="background: #6366f1; color: white; padding: 2px 8px; border-radius: 5px; margin-left: 8px; font-size: 0.7rem;">PRIMARY</span>
                                    <?php endif; ?>
                                </span>
                                <p class="address-text"><?php echo nl2br(htmlspecialchars($addr['full_address'])); ?></p>
                                <div class="address-phone"><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($addr['phone']); ?></div>
                            </div>
                            <div class="address-actions">
                                <?php if (!$addr['is_default']): ?>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="address_id" value="<?php echo $addr['id']; ?>">
                                        <button type="submit" name="set_default_address" class="set-default">Set as Primary</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this address?');" style="margin:0;">
                                        <input type="hidden" name="address_id" value="<?php echo $addr['id']; ?>">
                                        <button type="submit" name="delete_address" class="delete">Delete</button>
                                    </form>
                                <?php endif; ?>
                                <button type="button" class="edit-address-btn" data-address='<?php echo htmlspecialchars(json_encode($addr), ENT_QUOTES, 'UTF-8'); ?>' style="background: var(--customer-surface-2, #f1f5f9); color: var(--customer-text, #475569); border-color: var(--customer-border, #e2e8f0);">Edit</button>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="text-align: center; color: var(--customer-muted, #64748b); padding: 20px 0;">No addresses saved yet. Add one using the form above!</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </div><!-- /#tab-personal -->

    <div id="tab-security" class="acct-tab-panel">
    <div class="profile-card">
        <h3 style="margin-top: 0; margin-bottom: 20px; color: var(--customer-text, #1e293b);"><i class="fas fa-lock"></i> Change Password</h3>
        <form method="POST" class="settings-section">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="sec_current_password">Current Password</label>
                <div class="password-field">
                    <input type="password" id="sec_current_password" name="current_password" required>
                    <button type="button" class="password-toggle" data-target="sec_current_password" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
                </div>
            </div>
            <div class="form-group">
                <label for="sec_new_password">New Password</label>
                <div class="password-field">
                    <input type="password" id="sec_new_password" name="new_password" required minlength="8">
                    <button type="button" class="password-toggle" data-target="sec_new_password" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
                </div>
                <small class="hint">At least 8 characters.</small>
            </div>
            <div class="form-group">
                <label for="sec_confirm_password">Confirm New Password</label>
                <div class="password-field">
                    <input type="password" id="sec_confirm_password" name="confirm_password" required minlength="8">
                    <button type="button" class="password-toggle" data-target="sec_confirm_password" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
                </div>
            </div>
            <button type="submit" name="change_password" class="button">Change Password</button>
        </form>
    </div>

    <div class="profile-card" style="margin-top: 20px;">
        <h3 style="margin-top: 0; margin-bottom: 8px; color: var(--customer-text, #1e293b);"><i class="fas fa-user-shield"></i> Can't access your account?</h3>
        <p style="margin: 0 0 16px; color: var(--customer-muted, #64748b); font-size: 0.9rem; line-height: 1.6;">
            Forgot your password or lost your phone?<br>
            Please visit Darius Poultry Supplies in person. Our Admin will verify your identity and assist with your account recovery.
        </p>
        <button type="button" class="button button-secondary" id="openAccountRecoveryInfo">Account Recovery Information</button>
    </div>
    </div><!-- /#tab-security -->

<!-- Account Recovery Information modal: informational only, no password field, no online reset. -->
<div id="accountRecoveryModal" class="modal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin:0; color: var(--customer-text, #1e293b);">Account Recovery</h3>
            <span onclick="closeAccountRecoveryModal()" style="cursor: pointer; font-size: 1.5rem; font-weight: bold; color: var(--customer-muted, #94a3b8);">&times;</span>
        </div>
        <p style="color: var(--customer-text, #334155); line-height: 1.7; margin: 0 0 12px;">
            If you forgot your password, lost your phone, or your phone was stolen, please visit Darius Poultry Supplies.
        </p>
        <p style="color: var(--customer-text, #334155); line-height: 1.7; margin: 0 0 12px;">
            Our Admin will verify your identity and help you change/reset your account password.
        </p>
        <p style="color: var(--customer-text, #334155); line-height: 1.7; margin: 0 0 12px;">
            After your password has been reset, you can log in again using your new password.
        </p>
        <p style="color: var(--customer-muted, #64748b); line-height: 1.7; margin: 0;">
            For security, never share your current password with anyone.
        </p>
        <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
            <button type="button" onclick="closeAccountRecoveryModal()" class="button">Close</button>
        </div>
    </div>
</div>

    <div id="tab-privacy" class="acct-tab-panel">
    <div class="profile-card">
        <h3 style="margin-top: 0; margin-bottom: 20px; color: var(--customer-text, #1e293b);"><i class="fas fa-user-shield"></i> Privacy</h3>

        <div class="privacy-block">
            <h4>How we use your information</h4>
            <p>Your name, email, phone number, and the addresses you save under Personal Information are used only to process and deliver your orders, track your loyalty points, and contact you about your account. We don't sell or share your information with third parties.</p>
        </div>
        <div class="privacy-block">
            <h4>Your saved addresses</h4>
            <p>You're in control of your delivery addresses — add, edit, or remove them anytime from the Personal Information tab. Your primary address is used to estimate delivery distance and fees.</p>
        </div>
        <div class="privacy-block">
            <h4>Account data requests</h4>
            <p>To request a copy of your account data, or to have your account and data deleted, please contact the shop directly using the details on our Contact page.</p>
        </div>
    </div>
    </div><!-- /#tab-privacy -->
</section>

<!-- Edit Address Modal -->
<div id="editAddressModal" class="modal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin:0; color: #1e293b;">Edit Address</h3>
            <span onclick="closeEditModal()" style="cursor: pointer; font-size: 1.5rem; font-weight: bold; color: #94a3b8;">&times;</span>
        </div>
        <form method="POST" id="edit-address-form">
            <input type="hidden" name="action" value="update_address">
            <input type="hidden" name="address_id" id="edit_address_id">
            <div class="form-group">
                <label for="edit_label">Label</label>
                <input type="text" name="label" id="edit_label" required>
            </div>
            <div class="form-group">
                <label for="edit_phone">Contact Phone</label>
                <input type="text" name="phone" id="edit_phone" required>
            </div>
            <div class="form-group">
                <label for="edit_address">Full Address</label>
                <textarea name="address" id="edit_address" required style="height: 80px;"></textarea>
            </div>
            <div class="addr-map-field">
                <label>Pin your location on the map <span style="color:#ef4444;">*</span></label>
                <div id="editAddressMap" class="addr-map"></div>
                <input type="hidden" name="latitude" id="edit_latitude">
                <input type="hidden" name="longitude" id="edit_longitude">
                <div id="edit-address-map-msg"></div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                <button type="button" onclick="closeEditModal()" class="button button-secondary">Cancel</button>
                <button type="submit" name="update_address" class="button">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Reusable Leaflet picker for a saved address's coordinates. Powers both the "Add New
    // Address" form and the "Edit Address" modal below, using the same 5km + real-city
    // (Caloocan) verification checkout.php's map uses. This is what fixes checkout showing
    // "A delivery location is required" for a saved address that looks correct - it was
    // saved before this picker existed, so it had no coordinates stored at all.
    const ADDR_STORE_LAT = 14.652023833372462;
    const ADDR_STORE_LON = 120.97593745927361;
    const ADDR_MAX_KM = 5.0;

    function addrDistanceKm(lat, lon) {
        const R = 6371;
        const dLat = (lat - ADDR_STORE_LAT) * (Math.PI / 180);
        const dLon = (lon - ADDR_STORE_LON) * (Math.PI / 180);
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                  Math.cos(ADDR_STORE_LAT * (Math.PI / 180)) * Math.cos(lat * (Math.PI / 180)) *
                  Math.sin(dLon / 2) * Math.sin(dLon / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }
    function addrCityFromNominatim(data) {
        const addr = (data && data.address) || {};
        return (addr.city || addr.municipality || addr.town || addr.city_district || '').toLowerCase();
    }
    function addrIsCaloocan(city) {
        return city.includes('caloocan') || city.includes('kalookan');
    }

    function createAddressMapPicker(mapElId, latInputId, lonInputId, msgElId) {
        let map = null;
        let marker = null;

        function latInput() { return document.getElementById(latInputId); }
        function lonInput() { return document.getElementById(lonInputId); }
        function msgEl() { return document.getElementById(msgElId); }

        function reject() {
            if (marker) { map.removeLayer(marker); marker = null; }
            latInput().value = '';
            lonInput().value = '';
            const el = msgEl();
            if (el) {
                el.innerHTML = '<div class="addr-map-msg invalid">✕ Outside our delivery area. We currently deliver within Caloocan City (Caloocan, 10th Avenue, Grace Park), up to 5km from our shop.</div>';
            }
        }

        function accept(latlng) {
            if (marker) map.removeLayer(marker);
            marker = L.marker(latlng).addTo(map);
            latInput().value = latlng.lat;
            lonInput().value = latlng.lng;
            const el = msgEl();
            if (el) {
                el.innerHTML = '<div class="addr-map-msg valid">✓ Location pinned.</div>';
            }
        }

        function verifyAndAccept(latlng) {
            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat=${latlng.lat}&lon=${latlng.lng}`)
                .then(r => r.json())
                .then(data => {
                    if (!addrIsCaloocan(addrCityFromNominatim(data))) { reject(); return; }
                    accept(latlng);
                })
                .catch(() => accept(latlng));
        }

        function handleCandidate(latlng) {
            if (addrDistanceKm(latlng.lat, latlng.lng) > ADDR_MAX_KM) { reject(); return; }
            verifyAndAccept(latlng);
        }

        function init() {
            if (map) { map.invalidateSize(); return; }
            map = L.map(mapElId).setView([ADDR_STORE_LAT, ADDR_STORE_LON], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            L.Control.geocoder({
                defaultMarkGeocode: false,
                placeholder: "Search for address...",
                errorMessage: "Address not found."
            }).on('markgeocode', function (e) {
                const latlng = e.geocode.center;
                map.setView(latlng, 16);
                handleCandidate(latlng);
            }).addTo(map);

            map.on('click', function (e) { handleCandidate(e.latlng); });
        }

        // Places (or clears) the marker for an already-known address, e.g. when the Edit
        // modal opens - does not re-verify since these coordinates were already accepted
        // once (either by this picker before, or a legacy save that had none).
        function setExisting(lat, lon) {
            if (marker) { map.removeLayer(marker); marker = null; }
            if (lat && lon && isFinite(lat) && isFinite(lon)) {
                marker = L.marker([lat, lon]).addTo(map);
                map.setView([lat, lon], 16);
                latInput().value = lat;
                lonInput().value = lon;
            } else {
                latInput().value = '';
                lonInput().value = '';
            }
            const el = msgEl();
            if (el) el.innerHTML = '';
        }

        return { init, setExisting };
    }

    const addAddressMapPicker = createAddressMapPicker('addAddressMap', 'new_address_latitude', 'new_address_longitude', 'add-address-map-msg');
    const editAddressMapPicker = createAddressMapPicker('editAddressMap', 'edit_latitude', 'edit_longitude', 'edit-address-map-msg');

    const addAddressForm = document.getElementById('add-address-form');
    if (addAddressForm) {
        addAddressForm.addEventListener('submit', function (e) {
            const lat = document.getElementById('new_address_latitude').value;
            const lon = document.getElementById('new_address_longitude').value;
            if (!lat || !lon) {
                e.preventDefault();
                alert('Please pin your address location on the map before saving.');
            }
        });
    }

    const editAddressForm = document.getElementById('edit-address-form');
    if (editAddressForm) {
        editAddressForm.addEventListener('submit', function (e) {
            const lat = document.getElementById('edit_latitude').value;
            const lon = document.getElementById('edit_longitude').value;
            if (!lat || !lon) {
                e.preventDefault();
                alert('Please pin your address location on the map before saving.');
            }
        });
    }

    const editModal = document.getElementById('editAddressModal');

    document.querySelectorAll('.edit-address-btn').forEach(button => {
        button.addEventListener('click', function() {
            const addressData = JSON.parse(this.getAttribute('data-address'));
            document.getElementById('edit_address_id').value = addressData.id;
            document.getElementById('edit_label').value = addressData.label;
            document.getElementById('edit_phone').value = addressData.phone;
            document.getElementById('edit_address').value = addressData.full_address;
            editModal.style.display = 'flex';
            setTimeout(function () {
                editAddressMapPicker.init();
                const lat = addressData.latitude ? parseFloat(addressData.latitude) : null;
                const lon = addressData.longitude ? parseFloat(addressData.longitude) : null;
                editAddressMapPicker.setExisting(lat, lon);
            }, 50);
        });
    });

    function closeEditModal() {
        editModal.style.display = 'none';
    }

    const accountRecoveryModal = document.getElementById('accountRecoveryModal');
    const openAccountRecoveryBtn = document.getElementById('openAccountRecoveryInfo');
    if (openAccountRecoveryBtn) {
        openAccountRecoveryBtn.addEventListener('click', () => { accountRecoveryModal.style.display = 'flex'; });
    }
    function closeAccountRecoveryModal() {
        accountRecoveryModal.style.display = 'none';
    }

    window.onclick = (event) => {
        if (event.target == editModal) closeEditModal();
        if (event.target == accountRecoveryModal) closeAccountRecoveryModal();
    };

    // --- Account Settings tabs -------------------------------------------------
    // Priority for which tab opens: (1) a tab this exact POST just forced open
    // (e.g. a failed password change keeps Security open), (2) #hash in the URL,
    // (3) the last tab remembered in localStorage, (4) Personal Information.
    (function () {
        const TAB_IDS = ['personal', 'security', 'privacy'];
        const STORAGE_KEY = 'customerProfileActiveTab';
        const forcedTab = <?php echo json_encode($active_tab); ?>;

        function isValidTab(tab) { return TAB_IDS.includes(tab); }

        function activateTab(tab, updateHistory) {
            if (!isValidTab(tab)) tab = 'personal';
            document.querySelectorAll('.acct-tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.tab === tab);
            });
            document.querySelectorAll('.acct-tab-panel').forEach(panel => {
                panel.classList.toggle('active', panel.id === 'tab-' + tab);
            });
            try { localStorage.setItem(STORAGE_KEY, tab); } catch (e) { /* private mode, etc. - ignore */ }
            if (updateHistory) {
                history.replaceState(null, '', '#' + tab);
            }
            // The Add New Address map sits inside the Personal tab's panel, which may be
            // display:none at this point - Leaflet can't size itself against a hidden
            // container, so (re-)init only once the panel is actually visible.
            if (tab === 'personal') {
                setTimeout(function () { addAddressMapPicker.init(); }, 50);
            }
        }

        document.querySelectorAll('.acct-tab-btn').forEach(btn => {
            btn.addEventListener('click', () => activateTab(btn.dataset.tab, true));
        });

        let initialTab = forcedTab;
        if (!initialTab) {
            const hashTab = (location.hash || '').replace('#', '');
            if (isValidTab(hashTab)) {
                initialTab = hashTab;
            } else {
                try { initialTab = localStorage.getItem(STORAGE_KEY); } catch (e) { initialTab = null; }
            }
        }
        activateTab(initialTab || 'personal', false);
    })();

    // Show/Hide for the three password fields in the Security tab.
    document.querySelectorAll('.password-toggle').forEach(toggle => {
        toggle.addEventListener('click', function () {
            const input = document.getElementById(this.dataset.target);
            if (!input) return;
            const icon = this.querySelector('i');
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            if (icon) {
                icon.classList.toggle('fa-eye', !isHidden);
                icon.classList.toggle('fa-eye-slash', isHidden);
            }
            this.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>