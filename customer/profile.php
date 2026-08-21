<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

$customer = current_customer();
$customer_id = (int)$customer['id'];

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure the table exists before any operations
    ensure_customer_tables($conn);

    if (isset($_POST['add_address'])) {
        $label = mysqli_real_escape_string($conn, $_POST['label']);
        $addr = mysqli_real_escape_string($conn, $_POST['address']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        $latitude = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;

        // Start transaction
        mysqli_begin_transaction($conn);
        try {
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
            mysqli_rollback($conn);
            $error_message = "Error adding address: " . $e->getMessage();
        }
    } elseif (isset($_POST['set_default_address'])) {
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
        $address_id = (int)$_POST['address_id'];
        $stmt_delete = $conn->prepare("DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?");
        $stmt_delete->bind_param('ii', $address_id, $customer_id);
        $stmt_delete->execute();
        $stmt_delete->close();
        $success_message = "Address deleted successfully!";
    } elseif (isset($_POST['update_address'])) {
        $address_id = (int)$_POST['address_id'];
        $label = mysqli_real_escape_string($conn, $_POST['label']);
        $addr = mysqli_real_escape_string($conn, $_POST['address']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        $latitude = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;

        $stmt_update = $conn->prepare("UPDATE customer_addresses SET label = ?, full_address = ?, phone = ?, latitude = ?, longitude = ? WHERE id = ? AND customer_id = ?");
        $stmt_update->bind_param('sssddii', $label, $addr, $phone, $latitude, $longitude, $address_id, $customer_id);
        $stmt_update->execute();
        $stmt_update->close();
        $success_message = "Address updated successfully!";
    }
}
?>
<?php $page_title = 'My Account'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<!-- Leaflet CSS & JS for the map -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    .profile-card { background: white; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); padding: 30px; border: 1px solid #f1f5f9; }
    .profile-header { display: flex; align-items: center; gap: 20px; margin-bottom: 30px; border-bottom: 1px solid #f1f5f9; padding-bottom: 20px; }
    .profile-avatar { width: 80px; height: 80px; background: #6366f1; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 800; }
    .profile-info h3 { margin: 0; color: #1e293b; font-size: 1.5rem; }
    .profile-info p { margin: 5px 0 0; color: #64748b; }

    .address-card { background: #f8fafc; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: flex-start; }
    .address-card.default { border-color: #6366f1; background: #eef2ff; }
    .address-label { font-weight: 800; text-transform: uppercase; font-size: 0.75rem; color: #6366f1; margin-bottom: 8px; display: block; }
    .address-text { font-size: 0.95rem; color: #1e293b; line-height: 1.5; margin: 0; }
    .address-phone { color: #64748b; font-size: 0.85rem; margin-top: 5px; }
    .address-actions { display: flex; gap: 8px; margin-top: 10px; }
    .address-actions button {
        padding: 6px 12px; border-radius: 8px; font-size: 0.8rem; cursor: pointer;
        border: 1px solid #cbd5e1; background: #fff; color: #475569;
    }
    .address-actions button.set-default { background: #6366f1; color: white; border-color: #6366f1; }
    .address-actions button.set-default:hover { background: #4f46e5; }
    .address-actions button.delete { background: #fee2f2; color: #ef4444; border-color: #fca5a5; }
    .address-actions button.delete:hover { background: #ef4444; color: white; }

    .add-address-form { background: #f8fafc; padding: 25px; border-radius: 12px; border: 2px dashed #e2e8f0; margin-bottom: 30px; }
    
    .detail-group { margin-bottom: 20px; }
    .detail-label { font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; display: block; }
    .detail-value { font-size: 1.1rem; color: #334155; font-weight: 600; }

    /* Form group styling for modals */
    .form-group { margin-bottom: 15px; }
    .form-group label {
        display: block;
        font-weight: 600;
        color: #475569;
        margin-bottom: 6px;
        font-size: 0.9rem;
    }
    .form-group input[type="text"], .form-group textarea { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; font-size: 0.95rem; }
    .form-group textarea { min-height: 100px; resize: vertical; }
    /* Edit Address Modal */
    .modal {
        display: none; position: fixed; z-index: 1001; left: 0; top: 0;
        width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6);
        align-items: center; justify-content: center;
    }
    .modal-content {
        background-color: #fff; margin: auto; padding: 25px; border-radius: 12px;
        width: 90%; max-width: 550px; box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        animation: modal-fade-in 0.3s;
    }
    @keyframes modal-fade-in {
        from { transform: translateY(-30px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
</style>

<section class="customer-panel">
    <div class="section-header">
        <h2>Personal Information 👤</h2>
        <p>View and manage your account details.</p>
    </div>

    <?php if ($success_message): ?>
        <div class="alert-message alert-success" style="margin-bottom: 20px;"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert-message alert-error" style="margin-bottom: 20px;"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php
    // Fetch the current primary address
    $default_address_res = mysqli_query($conn, "SELECT * FROM customer_addresses WHERE customer_id = $customer_id AND is_default = 1 LIMIT 1");
    $default_address = $default_address_res ? mysqli_fetch_assoc($default_address_res) : null;
    ?>

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
            <div class="detail-group">
                <span class="detail-label">Current Loyalty Points</span>
                <span class="detail-value"><?php echo number_format((float) ($customer['loyalty_points'] ?? 0), 2); ?> points</span>
            </div>
            <div class="detail-group" style="grid-column: span 2;">
                <span class="detail-label">Primary Address</span>
                <span class="detail-value">
                    <?php if ($default_address): ?>
                        <?php echo nl2br(htmlspecialchars($default_address['full_address'])); ?>
                        <br><small style="color: #64748b;"><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($default_address['phone']); ?></small>
                    <?php else: ?>
                        No primary address set. Please add one below.
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #f1f5f9;">
            <h3 style="margin-top: 0; margin-bottom: 20px; color: #1e293b;"><i class="fas fa-map-marker-alt"></i> Manage My Addresses</h3>

            <div class="add-address-form">
                <h4 style="margin-top: 0; margin-bottom: 15px; color: #334155;">Add New Address</h4>
                <form method="POST">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div class="input-box" style="margin-top:0;">
                            <input type="text" name="label" placeholder="Label (e.g. Home, Office)" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px;">
                        </div>
                        <div class="input-box" style="margin-top:0;">
                            <input type="text" name="phone" placeholder="Contact Phone" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px;">
                        </div>
                        <div class="input-box" style="margin-top:0; grid-column: 1 / -1;">
                            <textarea name="address" id="new_address_text" placeholder="Full Address Details" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; height: 60px;"></textarea>
                        </div>
                    </div>
                    <div id="map-add-address" style="height: 250px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #cbd5e1;"></div>
                    <input type="hidden" name="latitude" id="new_address_lat">
                    <input type="hidden" name="longitude" id="new_address_lon">
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
                                <button type="button" class="edit-address-btn" data-address='<?php echo htmlspecialchars(json_encode($addr), ENT_QUOTES, 'UTF-8'); ?>' style="background: #f1f5f9; color: #475569; border-color: #e2e8f0;">Edit</button>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="text-align: center; color: #64748b; padding: 20px 0;">No addresses saved yet. Add one using the form above!</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Edit Address Modal -->
<div id="editAddressModal" class="modal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin:0; color: #1e293b;">Edit Address</h3>
            <span onclick="closeEditModal()" style="cursor: pointer; font-size: 1.5rem; font-weight: bold; color: #94a3b8;">&times;</span>
        </div>
        <form method="POST">
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
            <div id="map-edit-address" style="height: 250px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #cbd5e1;"></div>
            <input type="hidden" name="latitude" id="edit_address_lat">
            <input type="hidden" name="longitude" id="edit_address_lon">

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                <button type="button" onclick="closeEditModal()" class="button button-secondary">Cancel</button>
                <button type="submit" name="update_address" class="button">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    const editModal = document.getElementById('editAddressModal');
    let addMap, editMap, addMarker, editMarker;

    document.querySelectorAll('.edit-address-btn').forEach(button => {
        button.addEventListener('click', function() {
            const addressData = JSON.parse(this.getAttribute('data-address'));
            document.getElementById('edit_address_id').value = addressData.id;
            document.getElementById('edit_label').value = addressData.label;
            document.getElementById('edit_phone').value = addressData.phone;
            document.getElementById('edit_address').value = addressData.full_address;
            document.getElementById('edit_address_lat').value = addressData.latitude || '';
            document.getElementById('edit_address_lon').value = addressData.longitude || '';
            editModal.style.display = 'flex';
            setTimeout(() => initMap('edit', addressData.latitude, addressData.longitude), 100);
        });
    });

    function initMap(type, lat, lon) {
        const mapId = `map-${type}-address`;
        let mapInstance = type === 'add' ? addMap : editMap;
        let markerInstance = type === 'add' ? addMarker : editMarker;
        const latInputId = `${type}_address_lat`;
        const lonInputId = `${type}_address_lon`;
        const addressInputId = type === 'add' ? 'new_address_text' : 'edit_address';

        if (mapInstance) {
            mapInstance.invalidateSize();
            return;
        }

        const initialCoords = (lat && lon) ? [lat, lon] : [14.6594, 120.9838];
        mapInstance = L.map(mapId).setView(initialCoords, 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(mapInstance);

        if (lat && lon) {
            markerInstance = L.marker(initialCoords).addTo(mapInstance);
        }

        mapInstance.on('click', function(e) {
            if (markerInstance) mapInstance.removeLayer(markerInstance);
            markerInstance = L.marker(e.latlng).addTo(mapInstance);
            document.getElementById(latInputId).value = e.latlng.lat;
            document.getElementById(lonInputId).value = e.latlng.lng;

            // Reverse geocode to get address string
            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${e.latlng.lat}&lon=${e.latlng.lng}`)
                .then(res => res.json())
                .then(data => {
                    if (data.display_name) document.getElementById(addressInputId).value = data.display_name;
                });
        });

        if (type === 'add') addMap = mapInstance; else editMap = mapInstance;
    }

    function closeEditModal() {
        editModal.style.display = 'none';
    }

    window.onclick = (event) => event.target == editModal ? closeEditModal() : null;

    document.addEventListener('DOMContentLoaded', () => initMap('add'));
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>