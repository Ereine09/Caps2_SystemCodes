<?php
// The autoloader must be included before any `use` statements for vendor libraries.
// auth.php includes functions.php, which in turn includes the Composer autoloader.
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    require_customer_login();
    $customer = current_customer();
    $order_id_to_cancel = (int)($_POST['order_id'] ?? 0);
    $cancellation_reason = trim($_POST['cancellation_reason'] ?? '');

    if ($order_id_to_cancel <= 0 || $cancellation_reason === '') {
        $_SESSION['message'] = 'Please provide a reason for cancelling the order.';
        $_SESSION['message_type'] = 'error';
    } else {
        $order = get_order_by_id($order_id_to_cancel, (int)$customer['id']);

        if ($order && in_array($order['order_status'], ['pending', 'confirmed'])) {
            $conn->begin_transaction();
            try {
                // 1. Restore stock ONLY if the order was already confirmed (as stock is now deducted on confirmation)
                if ($order['order_status'] === 'confirmed') {
                    $order_items_to_restore = get_order_items($order_id_to_cancel);
                    foreach ($order_items_to_restore as $item) {
                        $variant_id = isset($item['variant_id']) && !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                        restore_product_stock((int)$item['product_id'], $variant_id, (int)$item['quantity']);
                    }
                }

                // 2. Update the order status to 'cancelled'
                $update_stmt = $conn->prepare("UPDATE tbl_orders SET order_status = 'cancelled', cancellation_reason = ? WHERE id = ?");
                $update_stmt->bind_param('si', $cancellation_reason, $order_id_to_cancel);
                $update_stmt->execute();
                $update_stmt->close();

                $conn->commit();
                $_SESSION['message'] = 'Your order has been successfully cancelled.';
                $_SESSION['message_type'] = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['message'] = 'There was an error cancelling your order. Please contact support.';
                $_SESSION['message_type'] = 'error';
            }
        } else {
            $_SESSION['message'] = 'This order can no longer be cancelled.';
            $_SESSION['message_type'] = 'error';
        }
    }
    header('Location: ' . BASE_URL . '/customer/orders.php?id=' . $order_id_to_cancel);
    exit();
}

require_customer_login();

$customer = current_customer();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_delivery_address') {
    $order_id_to_update = (int)($_POST['order_id'] ?? 0);
    $address_id = (int)($_POST['address_id'] ?? 0);
    $address = '';
    $phone = '';
    $latitude = null;
    $longitude = null;

    if ($address_id > 0) {
        $address_stmt = $conn->prepare(
            "SELECT full_address, phone, latitude, longitude
             FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1"
        );
        $address_stmt->bind_param('ii', $address_id, $customer['id']);
        $address_stmt->execute();
        $saved_address = $address_stmt->get_result()->fetch_assoc();
        $address_stmt->close();
        if ($saved_address) {
            $address = trim($saved_address['full_address']);
            $phone = trim($saved_address['phone'] ?? '');
            $latitude = is_numeric($saved_address['latitude']) ? (float)$saved_address['latitude'] : null;
            $longitude = is_numeric($saved_address['longitude']) ? (float)$saved_address['longitude'] : null;
        }
    } else {
        $address = trim($_POST['new_delivery_address'] ?? '');
        $phone = trim($_POST['new_delivery_phone'] ?? '');
        $latitude = is_numeric($_POST['latitude'] ?? null) ? (float)$_POST['latitude'] : null;
        $longitude = is_numeric($_POST['longitude'] ?? null) ? (float)$_POST['longitude'] : null;
    }

    $address_update = update_customer_order_delivery(
        $order_id_to_update,
        (int)$customer['id'],
        $address,
        $phone,
        $latitude,
        $longitude
    );
    $_SESSION['message'] = $address_update['message'];
    $_SESSION['message_type'] = $address_update['success'] ? 'success' : 'error';
    header('Location: ' . BASE_URL . '/customer/orders.php?id=' . $order_id_to_update);
    exit();
}

$order = null;
$order_items = [];
$delivery_details = null;
$delivery_proof = null;
$orders = [];
$customer_addresses = [];
$address_change_allowed = false;
if (isset($_GET['id'])) {
    $order_id = (int) $_GET['id'];
    $order = get_order_by_id($order_id, (int) $customer['id']);
    $address_change_allowed = $order
        && $order['fulfillment_type'] === 'delivery'
        && in_array($order['order_status'], ['pending', 'confirmed', 'processing'], true);
    $delivery_details = $order ? get_delivery_details_by_order_id($order['id']) : null;
    $delivery_proof = $order ? get_order_delivery_proof($order['id']) : null;


    // --- DYNAMICALLY ENSURE DELIVERY RECORD & QR TOKEN FOR ANY ORDER ID ---
    if ($order && $order['order_status'] !== 'cancelled') {
        if (!$delivery_details) {
            // Create the shared delivery record used by the admin and rider flows.
            $token = bin2hex(random_bytes(16));
            $insert_stmt = $conn->prepare("INSERT INTO tbl_delivery (order_id, delivery_type, status, qr_confirmation_token) VALUES (?, ?, 'pending', ?)");
            if ($insert_stmt) {
                $delivery_type = $order['fulfillment_type'] ?? 'delivery';
                $insert_stmt->bind_param("iss", $order['id'], $delivery_type, $token);
                $insert_stmt->execute();
                $insert_stmt->close();
                // Refresh details
                $delivery_details = get_delivery_details_by_order_id($order['id']);
            }
        } elseif (empty($delivery_details['qr_confirmation_token'])) {
            // Auto-generate token if record exists but token is missing
            $token = bin2hex(random_bytes(16));
            $update_stmt = $conn->prepare("UPDATE tbl_delivery SET qr_confirmation_token = ? WHERE id = ?");
            if ($update_stmt) {
                $update_stmt->bind_param("si", $token, $delivery_details['id']);
                $update_stmt->execute();
                $update_stmt->close();
                $delivery_details['qr_confirmation_token'] = $token;
            }
        }
    }

    if ($order) {
        $order_items = get_order_items($order['id']);
    }

    $customer_addresses = [];
    if ($order && $order['fulfillment_type'] === 'delivery') {
        $address_stmt = $conn->prepare(
            "SELECT id, label, full_address, phone, latitude, longitude
             FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, created_at DESC"
        );
        $address_stmt->bind_param('i', $customer['id']);
        $address_stmt->execute();
        $customer_addresses = $address_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $address_stmt->close();
    }

    // Include the new QR helper
    require_once __DIR__ . '/../modules/admin/qr_helper.php';
} else {
    $orders = get_customer_orders((int) $customer['id']);
}
?>
<?php $page_title = 'Your Orders'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>
<?php if (!empty($address_change_allowed)): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
<script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
<?php endif; ?>

<style>
    .status-tracker {
        display: flex;
        justify-content: space-between;
        margin-bottom: 40px;
        position: relative;
        padding: 20px 0;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .status-tracker::before {
        content: '';
        position: absolute;
        top: 42px;
        left: 10%;
        right: 10%;
        height: 3px;
        background: #e2e8f0;
        z-index: 1;
    }
    .status-step {
        position: relative;
        z-index: 2;
        text-align: center;
        flex: 1;
    }
    .step-icon {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: #fff;
        border: 4px solid #e2e8f0;
        margin: 0 auto 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        color: #94a3b8;
        transition: all 0.3s ease;
    }
    .status-step.completed .step-icon { border-color: #10b981; background: #10b981; color: #fff; }
    .status-step.active .step-icon { border-color: #312e81; background: #312e81; color: #fff; box-shadow: 0 0 0 4px rgba(49, 46, 129, 0.1); }
    .step-label { font-size: 0.8rem; font-weight: 700; color: #64748b; }
    .status-step.active .step-label { color: #312e81; }
    .status-step.completed .step-label { color: #10b981; }

    .order-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; margin-top: 20px; }
    .info-card { background: #fff; padding: 25px; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid #f1f5f9; margin-bottom: 25px; }
    .info-card h4 { margin-top: 0; margin-bottom: 20px; color: #1e293b; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; }
    .info-item { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 0.95rem; }
    .info-label { color: #64748b; font-weight: 500; }
    .info-value { color: #1e293b; font-weight: 700; }
    
    .item-img { width: 60px; height: 60px; border-radius: 10px; object-fit: cover; background: #f8fafc; border: 1px solid #eee; }
    .order-summary-box { background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px dashed #cbd5e1; }
    .summary-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.95rem; color: #475569; }
    .summary-total { margin-top: 15px; padding-top: 15px; border-top: 2px solid #e2e8f0; font-size: 1.2rem; color: #1e293b; font-weight: 800; }

    /* Modal Header/Footer Styling */
    .modal-header {
        padding: 15px 25px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .modal-header h3 { margin: 0; }
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1000;
        align-items: center;
        justify-content: center;
        padding: 15px;
        background: rgba(15, 23, 42, 0.55);
    }
    .modal-content {
        background: #fff;
        border-radius: 12px;
        width: 600px;
        max-width: 100%;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
    }
    .cancel-modal-content .close-modal {
        position: static;
        border: 0;
        background: transparent;
        color: #64748b;
        font-size: 26px;
        line-height: 1;
        cursor: pointer;
    }
    .modal-footer {
        padding: 15px 25px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
    }
    .cancel-modal-content {
        width: min(560px, calc(100% - 30px));
    }
    .cancellation-warning {
        margin: 15px 0 0;
        padding: 12px 14px;
        color: #92400e;
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 8px;
        font-size: 0.9rem;
    }
    #cancellation_reason {
        display: block;
        width: 100%;
        min-height: 130px;
        box-sizing: border-box;
        padding: 12px 14px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font: inherit;
        line-height: 1.5;
        resize: vertical;
    }
    #cancellation_reason:focus {
        outline: 2px solid rgba(99, 102, 241, 0.2);
        border-color: #6366f1;
    }
    .delivery-address-value { overflow-wrap: anywhere; line-height: 1.5; }
    .delivery-address-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px; }
    .address-change-modal-content { width: min(620px, calc(100% - 30px)); }
    .address-change-body { padding: 25px; }
    .address-change-body select, .address-change-body input[type="text"] { width: 100%; box-sizing: border-box; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.95rem; }
    .address-change-body label { display: block; margin: 0 0 6px; color: #475569; font-weight: 600; font-size: 0.9rem; }
    .address-change-map { height: 280px; margin-top: 15px; border: 1px solid #cbd5e1; border-radius: 8px; }
    .address-change-divider { margin: 18px 0; text-align: center; color: #94a3b8; font-weight: 700; }
    .address-change-status { margin-top: 10px; font-size: 0.9rem; }
    /* Status Pill Colors */
    .status-pill { display: inline-flex; align-items: center; justify-content: center; padding: 4px 12px; border-radius: 999px; font-size: 0.75rem; color: white; font-weight: 700; text-transform: uppercase; }
    .status-pending { background: #f39c12; }
    .status-confirmed, .status-processing, .status-ready_for_pickup { background: #3498db; }
    .status-to_ship, .status-to_receive { background: #16a085; }
    .status-reviews { background: #f1c40f; color: #333; }
    .status-out_for_delivery { background: #8e44ad; }
    .status-completed { background: #27ae60; }
    .status-cancelled { background: #c0392b; }

    @media (max-width: 900px) { .order-grid { grid-template-columns: 1fr; } }
</style>

<section class="customer-panel">
    <div class="section-header">
        <h2>Your Orders</h2>
        <p>Track fulfillment status, delivery details, and order history.</p>
    </div>
    <?php if ($order): ?>
        <?php
            $address_change_allowed = $order['fulfillment_type'] === 'delivery'
                && in_array($order['order_status'], ['pending', 'confirmed', 'processing'], true);
            $flash_message = $_SESSION['message'] ?? '';
            $flash_type = $_SESSION['message_type'] ?? 'success';
            unset($_SESSION['message'], $_SESSION['message_type']);
        ?>
        <?php if ($flash_message): ?>
            <div class="alert-message alert-<?php echo $flash_type === 'error' ? 'error' : 'success'; ?>" style="margin-bottom: 20px;"><?php echo htmlspecialchars($flash_message); ?></div>
        <?php endif; ?>
        <?php 
            $status = $order['order_status'];
            $steps = [
                'pending' => ['label' => 'Placed', 'icon' => 'fa-clipboard-list', 'pos' => 0],
                'confirmed' => ['label' => 'Confirmed', 'icon' => 'fa-check-circle', 'pos' => 1],
                'processing' => ['label' => 'Processing', 'icon' => 'fa-cog', 'pos' => 2],
                'shipping' => ['label' => 'Shipping', 'icon' => 'fa-truck', 'pos' => 3],
                'completed' => ['label' => 'Delivered', 'icon' => 'fa-box-open', 'pos' => 4]
            ];

            $current_pos = 0;
            if ($status === 'confirmed') $current_pos = 1;
            elseif ($status === 'processing') $current_pos = 2;
            elseif (in_array($status, ['ready_for_pickup', 'out_for_delivery', 'to_ship', 'to_receive', 'shipping'])) $current_pos = 3;
            elseif ($status === 'completed') $current_pos = 4;
        ?>

        <div class="order-details">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
                <h3 style="margin: 0;">Order #<?php echo htmlspecialchars($order['order_number']); ?></h3>
                <div style="display: flex; align-items: center; gap: 15px;">
                    <span class="status-pill status-<?php echo $status; ?>" style="padding: 6px 15px; font-size: 0.9rem;">
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order['order_status']))); ?>
                    </span>
                    <?php if (in_array($order['order_status'], ['pending', 'confirmed'], true)): ?>
                        <button type="button" class="button" onclick="openCancelModal()" style="background: #ef4444; color: white; padding: 8px 14px; font-size: 0.85rem;">
                            <i class="fas fa-times-circle"></i> Cancel Order
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($status === 'cancelled'): ?>
                <div class="alert-message alert-error" style="margin-bottom: 25px;">This order has been cancelled. Reason: <?php echo htmlspecialchars($order['cancellation_reason'] ?: 'Not provided'); ?></div>
            <?php else: ?>
                <div class="status-tracker">
                    <?php foreach ($steps as $key => $s): ?>
                        <?php 
                            $class = '';
                            if ($current_pos > $s['pos']) $class = 'completed';
                            elseif ($current_pos == $s['pos']) $class = 'active';
                        ?>
                        <div class="status-step <?php echo $class; ?>">
                            <div class="step-icon"><i class="fas <?php echo $s['icon']; ?>"></i></div>
                            <div class="step-label"><?php echo $s['label']; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
<?php if ($delivery_proof): ?>
                <div class="info-card" style="margin-top: 25px; border-left: 5px solid #10b981;">
                    <h4><i class="fas fa-camera"></i> Proof of Delivery</h4>
                    <div style="display: flex; gap: 25px; align-items: flex-start; flex-wrap: wrap;">
                        <div style="max-width: 240px;">
                            <a href="<?php echo BASE_URL . '/' . htmlspecialchars($delivery_proof['proof_image_url']); ?>" target="_blank" title="View full proof image">
                                <img src="<?php echo BASE_URL . '/' . htmlspecialchars($delivery_proof['proof_image_url']); ?>" alt="Proof of Delivery" style="width: 100%; max-width: 240px; border-radius: 10px; border: 4px solid #f1f5f9; box-shadow: 0 2px 8px rgba(0,0,0,0.12); cursor: zoom-in;">
                            </a>
                            <a href="<?php echo BASE_URL . '/' . htmlspecialchars($delivery_proof['proof_image_url']); ?>" target="_blank" class="button" style="display: block; text-align: center; margin-top: 12px; font-size: 0.85rem;">
                                <i class="fas fa-expand"></i> View Proof of Delivery
                            </a>
                        </div>
                        <div style="flex: 1; min-width: 220px;">
                            <?php if (!empty($delivery_proof['rider_name']) && trim($delivery_proof['rider_name']) !== ''): ?>
                                <div class="info-item"><span class="info-label">Delivered By</span><span class="info-value"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($delivery_proof['rider_name']); ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($delivery_proof['plate_number'])): ?>
                                <div class="info-item"><span class="info-label">Vehicle</span><span class="info-value"><?php echo htmlspecialchars($delivery_proof['vehicle_type'] . ' - ' . $delivery_proof['plate_number']); ?></span></div>
                            <?php endif; ?>
                            <div class="info-item"><span class="info-label">Delivered At</span><span class="info-value"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($delivery_proof['created_at']))); ?></span></div>
                            <?php if (!empty($delivery_proof['notes'])): ?>
                                <div class="info-item" style="flex-direction: column; gap: 5px;">
                                    <span class="info-label">Delivery Notes</span>
                                    <span class="info-value" style="font-weight: 500; font-size: 0.9rem; background: #f8fafc; padding: 10px 14px; border-radius: 8px; border: 1px solid #eef2f7;"><?php echo nl2br(htmlspecialchars($delivery_proof['notes'])); ?></span>
                                </div>
                            <?php endif; ?>
                            <p style="font-size: 0.8rem; color: #94a3b8; margin: 8px 0 0;">This photo confirms your order was delivered. If you were not home, please check the notes for where it was left.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($delivery_details && $order['order_status'] !== 'cancelled'): ?>

                <div class="info-card" style="margin-top: 25px; text-align: center; border-left: 5px solid #10b981;">
                    <h4 style="justify-content: center;"><i class="fas fa-qrcode"></i> Delivery Confirmation QR</h4>
                    <p style="font-size: 0.9rem; color: #475569; max-width: 450px; margin: 0 auto 20px;">
                        Please present this QR code to the rider upon delivery to confirm you have received your order.
                    </p>
                    <?php
                    // Dynamic JSON payload per order
                    $qr_data = json_encode([
                        'delivery_id' => (int)$delivery_details['id'],
                        'order_id'    => (int)$order['id'],
                        'token'       => $delivery_details['qr_confirmation_token']
                    ]);

                    $qr_code_uri = null;

                    // Option 1: Try Endroid PHP Library
                    if (class_exists('Endroid\\QrCode\\QrCode') && class_exists('Endroid\\QrCode\\Writer\\PngWriter')) {
                        try {
                            $qrCode = \Endroid\QrCode\QrCode::create($qr_data);
                            $writer = new \Endroid\QrCode\Writer\PngWriter();
                            $qr_code_uri = $writer->write($qrCode)->getDataUri();
                        } catch (Throwable $t) {
                            $qr_code_uri = null;
                        }
                    }

                    // Option 2: Fallback to QuickChart API (Works reliably for any order)
                    if (!$qr_code_uri) {
                        $qr_code_uri = "https://quickchart.io/qr?size=250&text=" . urlencode($qr_data);
                    }
                    ?>
                    <img src="<?php echo $qr_code_uri; ?>" alt="Delivery Confirmation QR Code" style="width: 220px; height: 220px; border: 5px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border-radius: 8px;">
                    <p style="margin-top: 10px; font-size: 0.75rem; color: #94a3b8;">Delivery Ref ID: #<?php echo (int)$delivery_details['id']; ?></p>
                </div>
            <?php endif; ?>

            <div class="order-grid">
                <div class="order-main">
                    <div class="info-card">
                        <h4><i class="fas fa-shopping-basket"></i> Order Items</h4>
                        <table class="customer-table">
                            <thead><tr><th></th><th>Item</th><th>Qty</th><th>Total</th></tr></thead>
                            <tbody>
                                <?php foreach ($order_items as $item): ?>
                                    <tr>
                                        <td style="width: 60px;"><img src="<?php echo htmlspecialchars($item['image_url'] ?: BASE_URL . '/assets/img/placeholder.png'); ?>" class="item-img" alt=""></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong><br>
                                            <small style="color: #64748b;">PHP <?php echo number_format($item['unit_price'], 2); ?></small>
                                            <?php if ($order['order_status'] === 'completed'): ?>
                                                <?php if (has_customer_reviewed_product((int)$customer['id'], (int)$item['product_id'], (int)$order['id'])): ?>
                                                    <div style="margin-top: 8px; font-size: 0.9rem; color: #10b981; font-weight: 600;"><i class="fas fa-check-circle"></i> Thank you for your review!</div>
                                                <?php else: ?>
                                                    <a href="submit_review.php?order_id=<?php echo $order['id']; ?>&product_id=<?php echo $item['product_id']; ?>" 
                                                       class="button" 
                                                       style="font-size: 0.85rem; padding: 8px 12px; margin-top: 8px; background: #f59e0b; display: inline-block;">
                                                       <i class="fas fa-star"></i> Write a Review
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>x<?php echo htmlspecialchars($item['quantity']); ?></td>
                                        <td><strong>PHP <?php echo number_format($item['total_price'], 2); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="order-sidebar">
                    <div class="info-card">
                        <h4><i class="fas fa-info-circle"></i> Fulfillment Info</h4>
                        <div class="info-item"><span class="info-label">Method</span><span class="info-value"><?php echo ucfirst($order['fulfillment_type']); ?></span></div>
                        <?php if ($order['fulfillment_type'] === 'pickup'): ?>
                            <div class="info-item"><span class="info-label">Date</span><span class="info-value"><?php echo htmlspecialchars($order['pickup_date']); ?></span></div>
                            <div class="info-item"><span class="info-label">Time</span><span class="info-value"><?php echo htmlspecialchars($order['pickup_time']); ?></span></div>
                        <?php else: ?>
                            <div class="info-item" style="flex-direction: column; gap: 5px;">
                                <span class="info-label">Delivery Address</span>
                                <span class="info-value delivery-address-value" style="font-weight: 500; font-size: 0.85rem;"><?php echo htmlspecialchars($order['delivery_address']); ?></span>
                                <?php if ($address_change_allowed): ?>
                                    <div class="delivery-address-actions">
                                        <button type="button" class="button" onclick="confirmDeliveryAddress()"><i class="fas fa-check"></i> Confirm Address</button>
                                        <button type="button" class="button button-secondary" onclick="openAddressChangeModal()"><i class="fas fa-edit"></i> Change Address</button>
                                    </div>
                                <?php else: ?>
                                    <small style="color: #64748b;">Address changes are no longer available because this order is already being fulfilled.</small>
                                <?php endif; ?>
                            </div>
                            <div class="info-item"><span class="info-label">Phone</span><span class="info-value"><?php echo htmlspecialchars($order['delivery_phone']); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($order['order_notes'])): ?>
                            <div class="info-item" style="flex-direction: column; gap: 5px;"><span class="info-label">Order Notes</span><span class="info-value" style="font-weight: 500; font-size: 0.85rem;"><?php echo nl2br(htmlspecialchars($order['order_notes'])); ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="info-card">
                        <h4><i class="fas fa-credit-card"></i> Payment</h4>
                        <div class="info-item"><span class="info-label">Method</span><span class="info-value"><?php echo strtoupper($order['payment_method']); ?></span></div>
                        <?php if ($order['payment_method'] === 'bank'): ?>
                            <div class="info-item"><span class="info-label">Bank Name</span><span class="info-value"><?php echo htmlspecialchars($order['bank_name']); ?></span></div>
                            <div class="info-item"><span class="info-label">Account Name</span><span class="info-value"><?php echo htmlspecialchars($order['bank_account_name']); ?></span></div>
                            <div class="info-item"><span class="info-label">Reference #</span><span class="info-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div>
                            <?php if ($order['payment_proof_path']): ?>
                                <div class="info-item">
                                    <span class="info-label">Proof</span>
                                    <span class="info-value">
                                        <button type="button" class="button-link" onclick="openProofModal('<?php echo BASE_URL . htmlspecialchars($order['payment_proof_path']); ?>')">View Proof</button>
                                    </span>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($order['payment_reference']): ?>
                            <div class="info-item"><span class="info-label">Reference</span><span class="info-value" style="font-size: 0.8rem;"><?php echo htmlspecialchars($order['payment_reference']); ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="info-card">
                        <h4><i class="fas fa-file-invoice-dollar"></i> Summary</h4>
                        <div class="order-summary-box">
                            <div class="summary-row"><span>Subtotal</span><span>PHP <?php echo number_format($order['subtotal'], 2); ?></span></div>
                            <div class="summary-row"><span>VAT (12%)</span><span>PHP <?php echo number_format($order['vat_amount'], 2); ?></span></div>
                            <div class="summary-row"><span>Delivery Fee</span><span>PHP <?php echo number_format($order['delivery_fee'], 2); ?></span></div>
                            <?php if ((float)$order['discount_amount'] > 0): ?>
                                <div class="summary-row" style="color: #10b981; font-weight: 600;"><span>Discount</span><span>-PHP <?php echo number_format($order['discount_amount'], 2); ?></span></div>
                            <?php endif; ?>
                            <div class="summary-row" style="color: #27ae60;"><span>Loyalty Earned</span><span>+<?php echo number_format($order['loyalty_points_earned'], 2); ?> pts</span></div>
                            <div class="summary-row summary-total"><span>Total Paid</span><span>PHP <?php echo number_format($order['total'], 2); ?></span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div style="margin-top: 30px;">
                <a class="button" href="<?php echo BASE_URL; ?>/customer/orders.php"><i class="fas fa-arrow-left"></i> Back to All Orders</a>
            </div>
        </div>
    <?php else: ?>
        <?php if ($orders): ?>
            <table class="customer-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Order</th>
                        <th>Items Purchased</th>
                        <th>Status</th>
                        <th>Fulfillment</th>
                        <th>Total</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($orders as $order_item): ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><?php echo htmlspecialchars($order_item['order_number']); ?></td>
                            <td style="max-width: 200px; font-size: 0.85rem; color: #64748b; line-height: 1.4;">
                                <?php echo htmlspecialchars($order_item['items_summary'] ?? 'N/A'); ?>
                            </td>
                            <td>
                                <span class="status-pill status-<?php echo $order_item['order_status']; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order_item['order_status']))); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars(ucfirst($order_item['fulfillment_type'])); ?></td>
                            <td>PHP <?php echo number_format($order_item['total'], 2); ?></td>
                            <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($order_item['created_at']))); ?></td>
                            <td><a class="button button-secondary" href="<?php echo BASE_URL; ?>/customer/orders.php?id=<?php echo $order_item['id']; ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="empty-state">You have no orders yet. Start shopping to place your first order.</p>
            <a class="button" href="<?php echo BASE_URL; ?>/customer/products.php">Browse Products</a>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Proof of Payment Modal -->
    <div id="proofModal" class="modal">
        <div class="modal-content" style="max-width: 600px; padding: 0;">
            <span class="close-modal" onclick="closeProofModal()" style="top: 15px; right: 25px; color: #fff; font-size: 30px;">&times;</span>
            <img id="proofModalImage" style="width: 100%; border-radius: 12px;">
        </div>
    </div>

    <?php if ($order && in_array($order['order_status'], ['pending', 'confirmed'], true)): ?>
    <div id="cancellationModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="cancellation-title" aria-hidden="true">
        <div class="modal-content cancel-modal-content">
            <div class="modal-header">
                <h3 id="cancellation-title">Cancel Order</h3>
                <button type="button" class="close-modal" onclick="closeCancelModal()" aria-label="Close cancellation dialog">&times;</button>
            </div>
            <form method="POST" id="cancel-order-form" onsubmit="return confirm('Are you sure you want to cancel this order? This action cannot be undone.');">
                <input type="hidden" name="action" value="cancel_order">
                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                <div class="form-group" style="padding: 25px;">
                    <p style="font-weight: 700; margin: 0 0 20px;">Order #<?php echo htmlspecialchars($order['order_number']); ?></p>
                    <label for="cancellation_reason">Why do you want to cancel this order?</label>
                    <textarea name="cancellation_reason" id="cancellation_reason" required maxlength="1000" style="min-height: 100px;" placeholder="Please tell us why you want to cancel."></textarea>
                    <p class="cancellation-warning"><i class="fas fa-exclamation-triangle"></i> Please note: Cancelling this order is final and cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="button button-secondary" onclick="closeCancelModal()">Keep Order</button>
                    <button type="submit" class="button" style="background: #ef4444; color: white;">Yes, Cancel This Order</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($order && $address_change_allowed): ?>
    <div id="addressChangeModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="address-change-title" aria-hidden="true">
        <div class="modal-content address-change-modal-content">
            <div class="modal-header">
                <h3 id="address-change-title">Change Delivery Address</h3>
                <button type="button" class="close-modal" onclick="closeAddressChangeModal()" aria-label="Close address dialog">&times;</button>
            </div>
            <form method="POST" id="address-change-form">
                <input type="hidden" name="action" value="change_delivery_address">
                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                <div class="address-change-body">
                    <label for="change_address_id">Select Saved Address</label>
                    <select name="address_id" id="change_address_id">
                        <option value="">Choose a saved address</option>
                        <?php foreach ($customer_addresses as $address): ?>
                            <option value="<?php echo (int)$address['id']; ?>" data-phone="<?php echo htmlspecialchars($address['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($address['label'] . ' - ' . $address['full_address']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="address-change-divider">OR</div>
                    <button type="button" class="button button-secondary" onclick="showNewAddressMap()"><i class="fas fa-map"></i> Choose a new address from map</button>
                    <div id="change-map-section" style="display:none;">
                        <div id="change-address-map" class="address-change-map"></div>
                        <input type="hidden" name="latitude" id="change_address_latitude">
                        <input type="hidden" name="longitude" id="change_address_longitude">
                        <div id="change-address-status" class="address-change-status"></div>
                        <div style="margin-top: 15px;">
                            <label for="new_delivery_address">Selected Address</label>
                            <input type="text" name="new_delivery_address" id="new_delivery_address" readonly placeholder="Pin a location on the map">
                        </div>
                        <div style="margin-top: 15px;">
                            <label for="new_delivery_phone">Contact Number</label>
                            <input type="text" name="new_delivery_phone" id="new_delivery_phone" value="<?php echo htmlspecialchars($order['delivery_phone'] ?? $customer['phone'] ?? ''); ?>" maxlength="50">
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <label for="change_delivery_phone">Contact Number</label>
                        <input type="text" id="change_delivery_phone" readonly value="<?php echo htmlspecialchars($order['delivery_phone'] ?? ''); ?>">
                    </div>
                    <div style="margin-top: 15px; display:flex; justify-content:space-between; gap:15px; flex-wrap:wrap;">
                        <span class="info-label">Estimated Delivery Fee</span>
                        <strong id="change_delivery_fee">Select an address</strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="button button-secondary" onclick="closeAddressChangeModal()">Cancel</button>
                    <button type="submit" class="button">Save Address Change</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        let changeAddressMap = null;
        let changeAddressMarker = null;
        const addressChangeModal = document.getElementById('addressChangeModal');

        function confirmDeliveryAddress() {
            const status = document.querySelector('.delivery-address-actions');
            if (status) status.insertAdjacentHTML('afterend', '<small style="display:block;color:#16a34a;margin-top:8px;">Address confirmed for this order.</small>');
        }

        function openAddressChangeModal() {
            if (!addressChangeModal) return;
            addressChangeModal.style.display = 'flex';
            addressChangeModal.setAttribute('aria-hidden', 'false');
            updateChangeAddressSelection();
        }

        function closeAddressChangeModal() {
            if (!addressChangeModal) return;
            addressChangeModal.style.display = 'none';
            addressChangeModal.setAttribute('aria-hidden', 'true');
        }

        function showNewAddressMap() {
            const section = document.getElementById('change-map-section');
            const select = document.getElementById('change_address_id');
            section.style.display = 'block';
            select.value = '';
            document.getElementById('change_delivery_phone').value = document.getElementById('new_delivery_phone').value;
            if (!changeAddressMap) {
                changeAddressMap = L.map('change-address-map').setView([14.6594, 120.9838], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(changeAddressMap);
                L.Control.geocoder({ defaultMarkGeocode: false, placeholder: 'Search for address...' })
                    .on('markgeocode', e => setChangeAddressLocation(e.geocode.center, e.geocode.name))
                    .addTo(changeAddressMap);
                changeAddressMap.on('click', e => {
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${e.latlng.lat}&lon=${e.latlng.lng}`)
                        .then(response => response.json())
                        .then(data => setChangeAddressLocation(e.latlng, data.display_name || ''))
                        .catch(() => document.getElementById('change-address-status').textContent = 'Address lookup failed. Please try again.')
                });
            }
            setTimeout(() => changeAddressMap.invalidateSize(), 100);
        }

        function setChangeAddressLocation(latlng, address) {
            if (changeAddressMarker) changeAddressMap.removeLayer(changeAddressMarker);
            changeAddressMarker = L.marker(latlng).addTo(changeAddressMap);
            changeAddressMap.setView(latlng, 16);
            document.getElementById('change_address_latitude').value = latlng.lat;
            document.getElementById('change_address_longitude').value = latlng.lng;
            document.getElementById('new_delivery_address').value = address;
            document.getElementById('change_delivery_phone').value = document.getElementById('new_delivery_phone').value;
            updateChangeFee(latlng.lat, latlng.lng);
        }

        function updateChangeAddressSelection() {
            const select = document.getElementById('change_address_id');
            if (!select) return;
            const option = select.options[select.selectedIndex];
            const section = document.getElementById('change-map-section');
            section.style.display = select.value ? 'none' : section.style.display;
            document.getElementById('change_delivery_phone').value = select.value ? option.dataset.phone : document.getElementById('new_delivery_phone').value;
            document.getElementById('change_delivery_fee').textContent = select.value ? 'Calculated when saved' : 'Select an address';
        }

        function updateChangeFee(lat, lon) {
            const storeLat = 14.6594, storeLon = 120.9838;
            const toRadians = value => value * Math.PI / 180;
            const dLat = toRadians(lat - storeLat), dLon = toRadians(lon - storeLon);
            const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRadians(storeLat)) * Math.cos(toRadians(lat)) * Math.sin(dLon / 2) ** 2;
            const distance = 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            const fee = distance <= 2 ? 0 : distance <= 3 ? 50 : distance <= 4 ? 60 : distance <= 5 ? 70 : -1;
            document.getElementById('change_delivery_fee').textContent = fee < 0 ? 'Outside supported delivery area' : (fee === 0 ? 'Free' : 'PHP ' + fee.toFixed(2));
            document.getElementById('change-address-status').textContent = fee < 0 ? 'This location is beyond the supported delivery range.' : `Selected location: ${distance.toFixed(2)} km from the store.`;
        }

        const changeAddressSelect = document.getElementById('change_address_id');
        if (changeAddressSelect) changeAddressSelect.addEventListener('change', updateChangeAddressSelection);

        function toggleNewAddressForm() {
        const selectedOption = document.querySelector('input[name="address_option"]:checked');
        const isNew = selectedOption && selectedOption.value === 'new';
        const newAddressFields = document.getElementById('new-address-fields');
        const newAddressInput = document.getElementById('delivery_address');
        const savedAddressText = document.getElementById('saved_address_text');
        const savedAddressPhone = document.getElementById('saved_address_phone');
        if (isNew) {
            newAddressFields.style.display = 'block';
            savedAddressText.value = ''; 
            savedAddressPhone.value = '';
        } else {
            newAddressFields.style.display = 'none';
            newAddressInput.value = ''; 
            if (selectedOption) {
                savedAddressText.value = selectedOption.dataset.address;
                savedAddressPhone.value = selectedOption.dataset.phone;
            }
        } 
        updateDeliveryFeeDisplay(); 
    }

        const proofModal = document.getElementById('proofModal');
        const modalImg = document.getElementById('proofModalImage');
        const cancelModal = document.getElementById('cancellationModal');

        function openProofModal(src) {
            proofModal.style.display = "flex";
            modalImg.src = src;
        }
        function closeProofModal() {
            proofModal.style.display = "none";
        }
        function openCancelModal() {
            if (!cancelModal) return;
            cancelModal.style.display = "flex";
            cancelModal.setAttribute('aria-hidden', 'false');
            document.getElementById('cancellation_reason').focus();
        }
        function closeCancelModal() {
            if (!cancelModal) return;
            cancelModal.style.display = "none";
            cancelModal.setAttribute('aria-hidden', 'true');
        }

        window.onclick = (event) => {
            if (event.target == proofModal) closeProofModal();
            if (cancelModal && event.target == cancelModal) closeCancelModal();
            if (event.target == addressChangeModal) closeAddressChangeModal();
        };
    </script>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>