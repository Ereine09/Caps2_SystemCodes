<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/ajax_helper.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token))) {
    clearJWTCookie();
    ajax_auth_required(); // AJAX callers get a JSON 401 instead of the login page
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$user_id = (int) ($payload['user_id'] ?? 0);
$role = strtolower(trim($payload['role'] ?? 'staff'));

// Only admin can access this page
if ($role !== 'admin') {
    if (is_ajax_request()) {
        ajax_fail('You do not have permission to do that.', 403);
    }
    header("Location: " . BASE_URL . "/modules/staff/dashboard.php");
    exit();
}

// Ensure supplier deliveries table exists
$conn->query("CREATE TABLE IF NOT EXISTS tbl_supplier_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_email VARCHAR(255) DEFAULT NULL,
    supplier_name VARCHAR(255) NOT NULL,
    items_summary TEXT NOT NULL,
    tracking_number VARCHAR(100) DEFAULT NULL,
    status ENUM('pending', 'in_transit', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
    expected_date DATE DEFAULT NULL,
    received_date DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// --- FIX: Ensure the supplier_email column exists ---
$check_col = $conn->query("SHOW COLUMNS FROM `tbl_supplier_deliveries` LIKE 'supplier_email'");
if ($check_col && $check_col->num_rows === 0) {
    $conn->query("ALTER TABLE `tbl_supplier_deliveries` ADD COLUMN `supplier_email` VARCHAR(255) DEFAULT NULL AFTER `id`");
}
// --- End of fix ---

// --- Supplier directory (persistent list of suppliers) ---
$conn->query("CREATE TABLE IF NOT EXISTS tbl_suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(255) NOT NULL,
    brand_name VARCHAR(255) DEFAULT NULL,
    contact_person VARCHAR(255) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Link a delivery record to a directory supplier (nullable, non-breaking).
$link_col = $conn->query("SHOW COLUMNS FROM `tbl_supplier_deliveries` LIKE 'supplier_id'");
if ($link_col && $link_col->num_rows === 0) {
    $conn->query("ALTER TABLE `tbl_supplier_deliveries` ADD COLUMN `supplier_id` INT DEFAULT NULL AFTER `id`");
}

// One-time back-fill: seed the directory from suppliers already used in past deliveries.
$directory_empty = (int) ($conn->query("SELECT COUNT(*) AS c FROM tbl_suppliers")->fetch_assoc()['c'] ?? 0) === 0;
if ($directory_empty) {
    $conn->query("INSERT INTO tbl_suppliers (supplier_name, email)
                  SELECT TRIM(supplier_name), MAX(NULLIF(TRIM(supplier_email), ''))
                  FROM tbl_supplier_deliveries
                  WHERE TRIM(supplier_name) <> ''
                  GROUP BY TRIM(supplier_name)");
    $conn->query("UPDATE tbl_supplier_deliveries d
                  JOIN tbl_suppliers s ON s.supplier_name = TRIM(d.supplier_name)
                  SET d.supplier_id = s.id
                  WHERE d.supplier_id IS NULL");
}

// Handle POST actions
$message = '';
$error = '';
$delivery_saved = false; // add_delivery inserted the row (even if the follow-up email failed)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_supplier') {
        $sid       = (int) ($_POST['supplier_id'] ?? 0);
        $s_name    = trim($_POST['supplier_name'] ?? '');
        $s_brand   = trim($_POST['brand_name'] ?? '');
        $s_person  = trim($_POST['contact_person'] ?? '');
        $s_email   = trim($_POST['email'] ?? '');
        $s_phone   = trim($_POST['phone'] ?? '');
        $s_address = trim($_POST['address'] ?? '');
        $s_notes   = trim($_POST['supplier_notes'] ?? '');

        if ($s_name === '') {
            $error = 'Supplier Name is required.';
        } elseif ($s_email !== '' && !filter_var($s_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid supplier email address.';
        } else {
            // Store blanks as NULL so the table stays clean.
            $b = $s_brand   !== '' ? $s_brand   : null;
            $p = $s_person  !== '' ? $s_person  : null;
            $e = $s_email   !== '' ? $s_email   : null;
            $ph = $s_phone  !== '' ? $s_phone   : null;
            $a = $s_address !== '' ? $s_address : null;
            $n = $s_notes   !== '' ? $s_notes   : null;

            if ($sid > 0) {
                $stmt = $conn->prepare("UPDATE tbl_suppliers SET supplier_name=?, brand_name=?, contact_person=?, email=?, phone=?, address=?, notes=? WHERE id=?");
                $stmt->bind_param('sssssssi', $s_name, $b, $p, $e, $ph, $a, $n, $sid);
                if ($stmt->execute()) {
                    $message = "Supplier \"" . $s_name . "\" updated.";
                } else {
                    $error = 'Could not update the supplier.';
                }
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO tbl_suppliers (supplier_name, brand_name, contact_person, email, phone, address, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssssss', $s_name, $b, $p, $e, $ph, $a, $n);
                if ($stmt->execute()) {
                    $message = "Supplier \"" . $s_name . "\" added to the directory.";
                } else {
                    $error = 'Could not add the supplier.';
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'toggle_supplier') {
        $sid = (int) ($_POST['supplier_id'] ?? 0);
        if ($sid > 0) {
            $stmt = $conn->prepare("UPDATE tbl_suppliers SET is_active = 1 - is_active WHERE id = ?");
            $stmt->bind_param('i', $sid);
            $stmt->execute();
            $stmt->close();
            $message = 'Supplier status updated.';
        }
    } elseif ($action === 'add_delivery') {
        $supplier_id     = (int) ($_POST['supplier_id'] ?? 0);
        $supplier_name   = trim($_POST['supplier_name'] ?? '');
        $supplier_email  = trim($_POST['supplier_email'] ?? '');
        $items_summary   = trim($_POST['items_summary'] ?? '');
        $tracking_number = trim($_POST['tracking_number'] ?? '');
        $expected_date   = !empty($_POST['expected_date']) ? $_POST['expected_date'] : null;
        $notes           = trim($_POST['notes'] ?? '');

        // If a directory supplier was chosen, fill any blanks from the directory record.
        if ($supplier_id > 0) {
            $sstmt = $conn->prepare("SELECT supplier_name, email FROM tbl_suppliers WHERE id = ?");
            $sstmt->bind_param('i', $supplier_id);
            $sstmt->execute();
            $srow = $sstmt->get_result()->fetch_assoc();
            $sstmt->close();
            if ($srow) {
                if ($supplier_name === '')  $supplier_name  = (string) $srow['supplier_name'];
                if ($supplier_email === '') $supplier_email = (string) ($srow['email'] ?? '');
            } else {
                $supplier_id = 0; // stale id, treat as manual entry
            }
        }

        if ($supplier_name !== '' && $items_summary !== '') {
            $supplier_id_param = $supplier_id > 0 ? $supplier_id : null;
            $stmt = $conn->prepare("INSERT INTO tbl_supplier_deliveries (supplier_id, supplier_name, supplier_email, items_summary, tracking_number, expected_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssss", $supplier_id_param, $supplier_name, $supplier_email, $items_summary, $tracking_number, $expected_date, $notes);
            if ($stmt->execute()) {
                $delivery_saved = true;
                $message = "Supplier delivery recorded successfully!";
                if (!empty($supplier_email) && filter_var($supplier_email, FILTER_VALIDATE_EMAIL)) {
                    if (send_supplier_order_email($supplier_email, $supplier_name, $items_summary, $notes)) {
                        $message .= " Purchase order email sent to supplier.";
                    } else {
                        $error = "Delivery was recorded, but the purchase order email could not be sent.";
                    }
                }
            } else {
                $error = "Failed to record delivery.";
                error_log('delivery.php add_delivery failed: ' . $conn->error);
            }
            $stmt->close();
        } else {
            $error = "Supplier Name and Items Summary are required.";
        }
    } elseif ($action === 'update_status') {
        $delivery_id = (int) ($_POST['delivery_id'] ?? 0);
        $new_status = $_POST['status'] ?? '';

        if ($delivery_id > 0 && in_array($new_status, ['delivered', 'cancelled'], true)) {
            if ($new_status === 'delivered') {
                $stmt = $conn->prepare("UPDATE tbl_supplier_deliveries SET status = 'delivered', received_date = NOW() WHERE id = ?");
                $stmt->bind_param("i", $delivery_id);
                $message = "Delivery marked as received!";
            } else {
                $stmt = $conn->prepare("UPDATE tbl_supplier_deliveries SET status = 'cancelled' WHERE id = ?");
                $stmt->bind_param("i", $delivery_id);
                $message = "Delivery has been cancelled.";
            }
            if (!$stmt->execute()) {
                $error = "Failed to update status.";
                error_log('delivery.php update_status failed: ' . $conn->error);
            }
            $stmt->close();
        } else {
            $error = "Invalid action or delivery ID.";
        }
    }

    // AJAX: the action above has already run with the same validation as before. Answer with JSON so the
    // page can patch just the affected row/counters instead of reloading.
    if (is_ajax_request()) {
        if ($error !== '' && !$delivery_saved) {
            ajax_fail($error, 422);
        }
        ajax_json([
            'success' => true,
            'message' => $message !== '' ? $message : 'Saved.',
            // e.g. the delivery was recorded but the purchase-order email could not be sent
            'warning' => $error !== '' ? $error : null,
        ]);
    }
}

// Fetch order metrics for sidebar badge & headers
$order_summary = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total_orders, SUM(total) AS total_sales, SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) AS pending_orders, SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) AS completed_orders FROM tbl_orders"));
if (!$order_summary) {
    $order_summary = ['total_orders' => 0, 'total_sales' => 0, 'pending_orders' => 0, 'completed_orders' => 0];
}
$order_summary = array_map(function ($value) {
    return $value === null ? 0 : $value;
}, $order_summary);

$unread_count = get_unread_count_staff($user_id);

// Fetch Supplier Directory (with a per-supplier delivery count)
$suppliers = [];
$suppliers_query = mysqli_query($conn, "
    SELECT s.*,
        (SELECT COUNT(*) FROM tbl_supplier_deliveries d WHERE d.supplier_id = s.id) AS delivery_count
    FROM tbl_suppliers s
    ORDER BY s.is_active DESC, s.supplier_name ASC");
if ($suppliers_query) {
    while ($row = mysqli_fetch_assoc($suppliers_query)) {
        $suppliers[] = $row;
    }
}
$active_suppliers = array_values(array_filter($suppliers, static fn ($s) => (int) $s['is_active'] === 1));

// Fetch Supplier Deliveries (join the directory for brand / phone)
$deliveries_query = mysqli_query($conn, "
    SELECT d.*, s.brand_name AS supplier_brand, s.phone AS supplier_phone
    FROM tbl_supplier_deliveries d
    LEFT JOIN tbl_suppliers s ON s.id = d.supplier_id
    ORDER BY d.created_at DESC");
$deliveries = [];
if ($deliveries_query) {
    while ($row = mysqli_fetch_assoc($deliveries_query)) {
        $deliveries[] = $row;
    }
}

/**
 * Sends a purchase order email to a supplier.
 */
function send_supplier_order_email(string $supplier_email, string $supplier_name, string $items_summary, string $notes): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = SMTP_AUTH;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM_EMAIL, htmlspecialchars(SYSTEM_NAME));
        $mail->addAddress($supplier_email, htmlspecialchars($supplier_name));
        $mail->addReplyTo(SMTP_FROM_EMAIL, htmlspecialchars(SYSTEM_NAME));

        $mail->isHTML(true);
        $mail->Subject = 'New Purchase Order from ' . htmlspecialchars(SYSTEM_NAME);
        $mail->Body    = "
            <p>Hello " . htmlspecialchars($supplier_name) . ",</p>
            <p>We would like to place a new order for the following items:</p>
            <pre>" . nl2br(htmlspecialchars($items_summary)) . "</pre>
            <p><strong>Notes:</strong> " . nl2br(htmlspecialchars($notes)) . "</p>
            <p>Please confirm this order and provide an estimated delivery date.</p>
            <p>Thank you,<br>" . htmlspecialchars(SYSTEM_NAME) . "</p>
        ";
        $mail->AltBody = "Hello " . htmlspecialchars($supplier_name) . ",\n\nWe would like to place a new order for the following items:\n\n" . htmlspecialchars($items_summary) . "\n\nNotes: " . htmlspecialchars($notes) . "\n\nPlease confirm this order and provide an estimated delivery date.\n\nThank you,\n" . htmlspecialchars(SYSTEM_NAME);

        return $mail->send();
    } catch (Exception $e) {
        error_log("Supplier email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// Deliveries status breakdown
$delivery_counts = ['total' => count($deliveries), 'pending' => 0, 'in_transit' => 0, 'delivered' => 0];
foreach ($deliveries as $d) {
    if (isset($delivery_counts[$d['status']])) {
        $delivery_counts[$d['status']]++;
    }
}
$supplier_counts = [
    'total'  => count($suppliers),
    'active' => count($active_suppliers),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers &amp; Deliveries - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/live-ui.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/live-ui.css') ?: time(); ?>">
    <script src="<?php echo BASE_URL; ?>/assets/js/live-ui.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/live-ui.js') ?: time(); ?>"></script>
    <style>
        body { overflow-x: hidden; margin: 0; background-color: var(--surface-2); }

        .delivery-container {
            background: var(--surface); padding: 25px; border-radius: 12px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05); margin-bottom: 30px;
        }
        .header-action { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 15px; flex-wrap: wrap; }
        .header-action h2 { margin: 0; color: var(--text); }
        .header-action > div { flex: 1 1 320px; min-width: 0; }
        .header-action > .btn-add { flex: 0 0 auto; align-self: flex-start; }
        .header-action .sub { margin: 4px 0 0; color: var(--muted); font-size: 0.88rem; }

        .btn-add {
            background: #2563eb; color: white; padding: 10px 18px; border-radius: 8px;
            text-decoration: none; font-weight: 600; display: inline-flex; align-items: center;
            gap: 8px; border: none; cursor: pointer; transition: background 0.2s;
        }
        .btn-add:hover { background: #1d4ed8; }

        .page-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid var(--border); overflow-x: auto; }
        .page-tab {
            background: none; border: none; border-bottom: 3px solid transparent; margin-bottom: -2px;
            padding: 12px 20px; font: inherit; font-size: 0.95rem; font-weight: 600; color: var(--muted);
            cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 8px;
        }
        .page-tab:hover { color: var(--info); }
        .page-tab.active { color: var(--info); border-bottom-color: #2563eb; }
        .tab-panel[hidden] { display: none; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: var(--surface-2); border: 1px solid var(--border); padding: 15px 20px; border-radius: 10px; display: flex; align-items: center; gap: 15px; }
        .stat-card i { font-size: 1.7rem; color: var(--info); }
        .stat-info h4 { margin: 0; font-size: 0.82rem; color: var(--muted); }
        .stat-info p { margin: 5px 0 0 0; font-size: 1.3rem; font-weight: bold; color: var(--text); }

        .delivery-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .delivery-table th, .delivery-table td {
            padding: 13px 15px; text-align: left; border-bottom: 1px solid var(--border);
            font-size: 0.92rem; vertical-align: top;
        }
        .delivery-table th { background-color: var(--surface-2); color: var(--text-2); font-weight: 600; }
        .delivery-table tr:hover td { background: #fcfdff; }
        .cell-strong { font-weight: 700; color: var(--text); }
        .cell-muted { color: var(--muted); font-size: 0.83rem; }
        .contact-lines { line-height: 1.55; }
        .contact-lines a { color: var(--info); text-decoration: none; }
        .contact-lines a:hover { text-decoration: underline; }

        .badge { padding: 4px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 600; text-transform: capitalize; display: inline-block; }
        .badge-pending { background: var(--warning-soft); color: var(--warning); }
        .badge-in_transit { background: var(--info-soft); color: var(--info); }
        .badge-delivered { background: var(--success-soft); color: var(--success); }
        .badge-cancelled { background: var(--danger-soft); color: var(--danger); }
        .badge-active { background: var(--success-soft); color: var(--success); }
        .badge-inactive { background: var(--surface-2); color: var(--muted); }
        .brand-chip { background: var(--primary-soft); color: var(--link); padding: 2px 8px; border-radius: 6px; font-size: 0.78rem; font-weight: 600; display: inline-block; }

        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: var(--success-soft); color: var(--success); border: 1px solid #bbf7d0; }
        .alert-error { background: var(--danger-soft); color: var(--danger); border: 1px solid #fecaca; }

        .action-buttons-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .btn-action {
            padding: 7px 13px; border-radius: 7px; font-size: 0.8rem; font-weight: 600;
            border: 1px solid transparent; cursor: pointer; display: inline-flex; align-items: center;
            gap: 6px; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); outline: none; white-space: nowrap;
        }
        .btn-approve { background-color: var(--success-soft); color: var(--success); border-color: #a7f3d0; }
        .btn-approve:hover { background-color: #059669; color: #fff; border-color: #059669; transform: translateY(-1px); }
        .btn-cancel { background-color: var(--danger-soft); color: var(--danger); border-color: #fecaca; }
        .btn-cancel:hover { background-color: #dc2626; color: #fff; border-color: #dc2626; transform: translateY(-1px); }
        .btn-edit { background-color: var(--info-soft); color: var(--info); border-color: #bfdbfe; }
        .btn-edit:hover { background-color: #2563eb; color: #fff; border-color: #2563eb; transform: translateY(-1px); }
        .btn-muted { background-color: var(--surface-2); color: var(--text-2); border-color: var(--border); }
        .btn-muted:hover { background-color: #e2e8f0; }
        .text-action-disabled { color: var(--muted); font-size: 0.83rem; display: inline-flex; align-items: center; gap: 5px; font-style: italic; }

        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000;
            padding: 20px; box-sizing: border-box; overflow-y: auto;
        }
        .modal-content {
            background: var(--surface); padding: 25px; border-radius: 12px; width: 100%;
            max-width: 500px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); margin: auto;
        }
        .modal-content.wide { max-width: 640px; }
        .modal-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-title h3 { margin: 0; }
        .modal-close { cursor: pointer; font-weight: bold; font-size: 1.3rem; color: var(--muted); line-height: 1; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: var(--text-2); font-size: 0.88rem; }
        .form-group .hint { font-weight: 400; color: var(--muted); font-size: 0.8rem; }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 6px;
            box-sizing: border-box; font-family: inherit; font-size: 0.92rem;
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        @media (max-width: 600px) { .grid-2 { grid-template-columns: 1fr; } }
        .empty-state { text-align: center; padding: 40px 20px; color: var(--muted); }
        .empty-state .empty-help { max-width: 460px; margin: 6px auto 0; font-size: 0.85rem; line-height: 1.5; }

        /* Help / description text */
        .stat-info small { display: block; margin-top: 3px; font-size: 0.72rem; line-height: 1.3; color: var(--muted); }
        .info-note {
            display: flex; gap: 10px; align-items: flex-start; margin-bottom: 14px; padding: 12px 14px;
            background: var(--info-soft); border: 1px solid #bfdbfe; color: var(--info); border-radius: 10px;
            font-size: 0.85rem; line-height: 1.5;
        }
        .info-note i { margin-top: 3px; }
        .info-note p { margin: 0; }
        .status-guide { margin-bottom: 16px; }
        .status-guide summary { cursor: pointer; font-size: 0.85rem; font-weight: 600; color: var(--info); width: fit-content; }
        .status-guide-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; margin-top: 10px; }
        .status-guide-grid > div { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; background: var(--surface-2); font-size: 0.82rem; line-height: 1.45; color: var(--text-2); }
        .status-guide-grid .badge { margin-bottom: 6px; }
        th[title] { cursor: help; }
        .field-help { display: block; margin-top: 5px; font-size: 0.78rem; line-height: 1.4; font-weight: 400; color: var(--muted); }
        .modal-intro { margin: -8px 0 18px; color: var(--muted); font-size: 0.88rem; line-height: 1.5; }

        body.dark-mode .info-note { background: rgba(59,130,246,0.12); border-color: rgba(59,130,246,0.35); }
        body.dark-mode .delivery-container .info-note { color: #93c5fd; }
        body.dark-mode .delivery-container .status-guide summary { color: var(--dm-link, #a5b4fc); }
        body.dark-mode .delivery-container .status-guide-grid > div { background: var(--dm-surface-2); border-color: var(--dm-border); color: var(--dm-text-muted); }
        body.dark-mode .stat-card .stat-info small,
        body.dark-mode .delivery-container .empty-help { color: var(--dm-text-muted); }
        body.dark-mode .field-help, body.dark-mode .modal-intro { color: var(--dm-text-muted); }
        /* The dark theme forces inherited text color inside .delivery-container; keep these pale chips readable. */
        body.dark-mode .delivery-container .badge-active { color: #15803d; }
        body.dark-mode .delivery-container .badge-inactive { color: #64748b; }
        body.dark-mode .delivery-container .btn-approve { color: #047857; }
        body.dark-mode .delivery-container .btn-approve:hover { color: #fff; }
    </style>
</head>
<body>
    <?php
    // Sidebar badge only — deliberately a SEPARATE query from $order_summary
    // above (which still reports the true pending-order business metric on
    // this page, untouched). This one only counts pending orders an admin
    // hasn't opened yet, so it clears on view independently of order status.
    $pending_orders_count = (int) (mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"
    ))['count'] ?? 0);
    include __DIR__ . '/../customers/sidebar.php';
    ?>

    <div class="main-content">
        <div class="welcome-header" style="margin-bottom: 30px;">
            <h1>Suppliers &amp; Deliveries 🚚</h1>
            <p>Keep a directory of your suppliers and track every incoming stock shipment.</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-truck-field"></i>
                <div class="stat-info"><h4>Suppliers</h4><p data-stat="suppliers_total"><?php echo $supplier_counts['total']; ?></p><small>Saved in your directory</small></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-circle-check" style="color: #16a34a;"></i>
                <div class="stat-info"><h4>Active Suppliers</h4><p data-stat="suppliers_active"><?php echo $supplier_counts['active']; ?></p><small>Can be picked for new deliveries</small></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-boxes-packing"></i>
                <div class="stat-info"><h4>Total Deliveries</h4><p data-stat="deliveries_total"><?php echo $delivery_counts['total']; ?></p><small>All records, including cancelled</small></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-clock" style="color: #d97706;"></i>
                <div class="stat-info"><h4>Pending</h4><p data-stat="deliveries_pending"><?php echo $delivery_counts['pending']; ?></p><small>Ordered, not yet received</small></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-truck-fast" style="color: #2563eb;"></i>
                <div class="stat-info"><h4>In Transit</h4><p data-stat="deliveries_in_transit"><?php echo $delivery_counts['in_transit']; ?></p><small>On the way from the supplier</small></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-circle-check" style="color: #16a34a;"></i>
                <div class="stat-info"><h4>Delivered</h4><p data-stat="deliveries_delivered"><?php echo $delivery_counts['delivered']; ?></p><small>Received and completed</small></div>
            </div>
        </div>

        <div class="page-tabs" role="tablist">
            <button type="button" class="page-tab active" role="tab" id="tab-deliveries" data-tab="deliveries" aria-selected="true" aria-controls="panel-deliveries"><i class="fas fa-truck-fast"></i> Incoming Deliveries</button>
            <button type="button" class="page-tab" role="tab" id="tab-suppliers" data-tab="suppliers" aria-selected="false" aria-controls="panel-suppliers"><i class="fas fa-address-book"></i> Supplier Directory</button>
        </div>

        <!-- ===================== INCOMING DELIVERIES ===================== -->
        <div class="delivery-container tab-panel" id="panel-deliveries" role="tabpanel" aria-labelledby="tab-deliveries">
            <div class="header-action">
                <div>
                    <h2>Incoming Deliveries</h2>
                    <p class="sub">Stock shipments coming in from suppliers. Use <strong>Record Delivery</strong> when you order stock from a supplier. If the supplier has an email address, a purchase order is emailed to them automatically. When the shipment arrives, click <strong>Mark Delivered</strong> to log the received date. This page only tracks shipments &mdash; it does <strong>not</strong> change product stock, so update quantities in Products after receiving.</p>
                </div>
                <button class="btn-add" onclick="openModal()"><i class="fas fa-plus"></i> Record Delivery</button>
            </div>

            <details class="status-guide">
                <summary>What do the delivery statuses mean?</summary>
                <div class="status-guide-grid">
                    <div><span class="badge badge-pending">Pending</span><br>Order recorded, but the shipment has not been received yet. You can still mark it delivered or cancel it.</div>
                    <div><span class="badge badge-in_transit">In Transit</span><br>The supplier has shipped the order and it is on the way. You can still mark it delivered or cancel it.</div>
                    <div><span class="badge badge-delivered">Delivered</span><br>The shipment was received and the received date was saved. This is final &mdash; no further actions.</div>
                    <div><span class="badge badge-cancelled">Cancelled</span><br>The order was called off and will not arrive. This is final &mdash; no further actions.</div>
                </div>
            </details>

            <div id="deliveries-wrap">
            <?php if (empty($deliveries)): ?>
                <div class="empty-state">
                    <i class="fas fa-truck-ramp-box" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 10px;"></i>
                    <p>No supplier deliveries logged yet. Click "Record Delivery" to track incoming stock.</p>
                    <p class="empty-help">Each record keeps the supplier, the items ordered, an optional tracking or invoice number, and the date you expect the shipment to arrive.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="delivery-table">
                        <thead>
                            <tr>
                                <th title="Who the stock was ordered from, with their email and phone if saved">Supplier</th>
                                <th title="The product brand this supplier provides">Brand</th>
                                <th title="The items and quantities ordered">Items / Description</th>
                                <th title="Supplier tracking code or invoice number">Tracking No.</th>
                                <th title="The date you expect the shipment to arrive">Expected Date</th>
                                <th title="Current stage of the shipment">Status</th>
                                <th title="Actions are only available while a delivery is Pending or In Transit">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deliveries as $item): ?>
                                <tr data-delivery-id="<?php echo (int) $item['id']; ?>">
                                    <td>
                                        <span class="cell-strong"><?php echo htmlspecialchars($item['supplier_name']); ?></span>
                                        <?php if (!empty($item['supplier_email'])): ?>
                                            <div class="cell-muted"><?php echo htmlspecialchars($item['supplier_email']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($item['supplier_phone'])): ?>
                                            <div class="cell-muted"><i class="fas fa-phone" style="width:14px;"></i> <?php echo htmlspecialchars($item['supplier_phone']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['supplier_brand'])): ?>
                                            <span class="brand-chip"><?php echo htmlspecialchars($item['supplier_brand']); ?></span>
                                        <?php else: ?>
                                            <span class="cell-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo nl2br(htmlspecialchars($item['items_summary'])); ?></td>
                                    <td><code><?php echo htmlspecialchars($item['tracking_number'] ?: 'N/A'); ?></code></td>
                                    <td><?php echo $item['expected_date'] ? date('M d, Y', strtotime($item['expected_date'])) : '—'; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo htmlspecialchars($item['status']); ?>">
                                            <?php echo str_replace('_', ' ', $item['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($item['status'] === 'pending' || $item['status'] === 'in_transit'): ?>
                                            <div class="action-buttons-group">
                                                <form method="POST" onsubmit="return confirm('Did you receive this delivery?');" style="margin: 0;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="delivery_id" value="<?php echo (int) $item['id']; ?>">
                                                    <input type="hidden" name="status" value="delivered">
                                                    <button type="submit" class="btn-action btn-approve" title="The shipment arrived: sets the status to Delivered and saves today's date as the received date"><i class="fas fa-check"></i> Mark Delivered</button>
                                                </form>
                                                <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this order?');" style="margin: 0;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="delivery_id" value="<?php echo (int) $item['id']; ?>">
                                                    <input type="hidden" name="status" value="cancelled">
                                                    <button type="submit" class="btn-action btn-cancel" title="Call off this order: sets the status to Cancelled. This cannot be undone"><i class="fas fa-times"></i> Cancel</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-action-disabled" title="Delivered and cancelled deliveries are final"><i class="fas fa-lock"></i> No actions</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div><!-- /#deliveries-wrap -->
        </div>

        <!-- ===================== SUPPLIER DIRECTORY ===================== -->
        <div class="delivery-container tab-panel" id="panel-suppliers" role="tabpanel" aria-labelledby="tab-suppliers" hidden>
            <div class="header-action">
                <div>
                    <h2>Supplier Directory</h2>
                    <p class="sub">Your list of suppliers &mdash; name, brand, contact details and address. Save each supplier once here, then pick them from the list when you record a delivery &mdash; their name and email fill in automatically. <strong>Deactivate</strong> a supplier you no longer order from: they disappear from the Record Delivery list, but their details and past deliveries are kept, and you can <strong>Reactivate</strong> them any time.</p>
                </div>
                <button class="btn-add" onclick="openSupplierModal()"><i class="fas fa-user-plus"></i> Add Supplier</button>
            </div>


            <div id="suppliers-wrap">
            <?php if (empty($suppliers)): ?>
                <div class="empty-state">
                    <i class="fas fa-address-book" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 10px;"></i>
                    <p>No suppliers yet. Click "Add Supplier" to build your directory.</p>
                    <p class="empty-help">Only the supplier name is required. Add the brand, contact person, phone, email and address so everything is in one place.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="delivery-table">
                        <thead>
                            <tr>
                                <th title="Supplier name. Any notes you saved (payment terms, lead time) appear underneath">Supplier</th>
                                <th title="The product brand this supplier provides">Brand</th>
                                <th title="Contact person, phone and email. Phone and email are clickable">Contact Info</th>
                                <th title="Where the supplier is located">Address</th>
                                <th title="How many deliveries have been recorded for this supplier">Deliveries</th>
                                <th title="Active suppliers can be picked when recording a delivery. Inactive ones are hidden from that list">Status</th>
                                <th title="Edit the supplier's details, or deactivate / reactivate them">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($suppliers as $s): ?>
                                <tr data-supplier-id="<?php echo (int) $s['id']; ?>">
                                    <td>
                                        <span class="cell-strong"><?php echo htmlspecialchars($s['supplier_name']); ?></span>
                                        <?php if (!empty($s['notes'])): ?>
                                            <div class="cell-muted"><?php echo nl2br(htmlspecialchars($s['notes'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['brand_name'])): ?>
                                            <span class="brand-chip"><?php echo htmlspecialchars($s['brand_name']); ?></span>
                                        <?php else: ?>
                                            <span class="cell-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="contact-lines">
                                        <?php if (!empty($s['contact_person'])): ?>
                                            <div><i class="fas fa-user" style="color:#94a3b8;width:16px;"></i> <?php echo htmlspecialchars($s['contact_person']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($s['phone'])): ?>
                                            <div><i class="fas fa-phone" style="color:#94a3b8;width:16px;"></i> <a href="tel:<?php echo htmlspecialchars($s['phone']); ?>"><?php echo htmlspecialchars($s['phone']); ?></a></div>
                                        <?php endif; ?>
                                        <?php if (!empty($s['email'])): ?>
                                            <div><i class="fas fa-envelope" style="color:#94a3b8;width:16px;"></i> <a href="mailto:<?php echo htmlspecialchars($s['email']); ?>"><?php echo htmlspecialchars($s['email']); ?></a></div>
                                        <?php endif; ?>
                                        <?php if (empty($s['contact_person']) && empty($s['phone']) && empty($s['email'])): ?>
                                            <span class="cell-muted">No contact info</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo !empty($s['address']) ? nl2br(htmlspecialchars($s['address'])) : '<span class="cell-muted">&mdash;</span>'; ?></td>
                                    <td><?php echo (int) $s['delivery_count']; ?></td>
                                    <td>
                                        <span class="badge <?php echo ((int) $s['is_active'] === 1) ? 'badge-active' : 'badge-inactive'; ?>">
                                            <?php echo ((int) $s['is_active'] === 1) ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons-group">
                                            <button type="button" class="btn-action btn-edit" title="Change this supplier's details"
                                                onclick='openSupplierModal(<?php echo json_encode([
                                                    'id' => (int) $s['id'],
                                                    'supplier_name' => $s['supplier_name'],
                                                    'brand_name' => $s['brand_name'],
                                                    'contact_person' => $s['contact_person'],
                                                    'email' => $s['email'],
                                                    'phone' => $s['phone'],
                                                    'address' => $s['address'],
                                                    'notes' => $s['notes'],
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)'>
                                                <i class="fas fa-pen"></i> Edit
                                            </button>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('<?php echo ((int) $s['is_active'] === 1) ? 'Deactivate' : 'Reactivate'; ?> this supplier?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_supplier">
                                                <input type="hidden" name="supplier_id" value="<?php echo (int) $s['id']; ?>">
                                                <button type="submit" class="btn-action btn-muted" title="<?php echo ((int) $s['is_active'] === 1) ? 'Hide this supplier from the Record Delivery list (their history is kept)' : 'Show this supplier in the Record Delivery list again'; ?>">
                                                    <i class="fas fa-<?php echo ((int) $s['is_active'] === 1) ? 'ban' : 'rotate-left'; ?>"></i>
                                                    <?php echo ((int) $s['is_active'] === 1) ? 'Deactivate' : 'Reactivate'; ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div><!-- /#suppliers-wrap -->
        </div>
    </div>

    <!-- Add / Edit Supplier Modal -->
    <div id="supplierModal" class="modal">
        <div class="modal-content wide">
            <div class="modal-title">
                <h3 id="supplierModalTitle">Add Supplier</h3>
                <span class="modal-close" onclick="closeSupplierModal()">&times;</span>
            </div>
            <p class="modal-intro">Suppliers saved here can be picked when you record a delivery. Only the supplier name is required.</p>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_supplier">
                <input type="hidden" name="supplier_id" id="supplier_id" value="">
                <div class="grid-2">
                    <div class="form-group">
                        <label>Supplier Name <span class="hint">*required</span></label>
                        <input type="text" name="supplier_name" id="s_supplier_name" required placeholder="e.g. San Miguel Foods Inc.">
                        <span class="field-help">The company or person you buy stock from.</span>
                    </div>
                    <div class="form-group">
                        <label>Brand Name</label>
                        <input type="text" name="brand_name" id="s_brand_name" placeholder="e.g. B-MEG, Pigrolac">
                        <span class="field-help">The product brand(s) they supply. Shown as a tag in the tables.</span>
                    </div>
                    <div class="form-group">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" id="s_contact_person" placeholder="e.g. Juan Dela Cruz">
                        <span class="field-help">Who to talk to when placing or following up an order.</span>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" id="s_phone" placeholder="e.g. +63 917 000 0000">
                        <span class="field-help">Becomes a tap-to-call link in the directory.</span>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Email</label>
                        <input type="email" name="email" id="s_email" placeholder="orders@supplier.com">
                        <span class="field-help">Purchase orders are sent to this address when you record a delivery for this supplier. Leave blank if you don't want emails sent.</span>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Address</label>
                        <textarea name="address" id="s_address" rows="2" placeholder="Street, Barangay, City, Province"></textarea>
                        <span class="field-help">Warehouse or office address, useful for pick-ups and returns.</span>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Notes</label>
                        <textarea name="supplier_notes" id="s_notes" rows="2" placeholder="Payment terms, lead time, minimum order, etc."></textarea>
                        <span class="field-help">For your own reference. Shown under the supplier's name in the directory and never sent to the supplier.</span>
                    </div>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                    <button type="button" onclick="closeSupplierModal()" style="padding: 9px 16px; border-radius: 6px; border: 1px solid #cbd5e1; background: #f8fafc; cursor: pointer;">Cancel</button>
                    <button type="submit" class="btn-add" id="supplierSubmitBtn">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Record Delivery Modal -->
    <div id="deliveryModal" class="modal">
        <div class="modal-content">
            <div class="modal-title">
                <h3>Record Supplier Delivery</h3>
                <span class="modal-close" onclick="closeModal()">&times;</span>
            </div>
            <p class="modal-intro">Log stock you have ordered from a supplier so you can track it until it arrives. New deliveries start as <strong>Pending</strong>.</p>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_delivery">
                <div class="form-group">
                    <label>Supplier <span class="hint">(pick from your directory)</span></label>
                    <select name="supplier_id" id="d_supplier_id" onchange="fillSupplierFromDirectory()">
                        <option value="0">— Type a supplier manually —</option>
                        <?php foreach ($active_suppliers as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>"
                                data-name="<?php echo htmlspecialchars($s['supplier_name'], ENT_QUOTES); ?>"
                                data-email="<?php echo htmlspecialchars((string) ($s['email'] ?? ''), ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($s['supplier_name'] . (!empty($s['brand_name']) ? ' — ' . $s['brand_name'] : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-help">Only active suppliers are listed. Choosing one fills in the name and email below. For a one-off supplier, leave this on "Type a supplier manually".</span>
                </div>
                <div class="form-group">
                    <label>Supplier Name <span class="hint">*required</span></label>
                    <input type="text" name="supplier_name" id="d_supplier_name" required placeholder="e.g. San Miguel Foods">
                    <span class="field-help">How the supplier appears in the deliveries table.</span>
                </div>
                <div class="form-group">
                    <label>Supplier Email <span class="hint">(optional — sends a purchase order)</span></label>
                    <input type="email" name="supplier_email" id="d_supplier_email" placeholder="supplier@example.com">
                    <span class="field-help">If you enter a valid email, a purchase order listing the items and your notes is emailed to the supplier as soon as you save. Leave blank to only record the delivery.</span>
                </div>
                <div class="form-group">
                    <label>Items Summary <span class="hint">*required</span></label>
                    <textarea name="items_summary" rows="3" required placeholder="e.g. 50x Poultry Feed 25kg, 20x Vitamin Supplements"></textarea>
                    <span class="field-help">List each item with its quantity and size. This text is also what the supplier sees in the purchase order email.</span>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Tracking / Invoice No.</label>
                        <input type="text" name="tracking_number" placeholder="Optional tracking code">
                        <span class="field-help">The supplier's tracking code or invoice number, if you have one.</span>
                    </div>
                    <div class="form-group">
                        <label>Expected Delivery Date</label>
                        <input type="date" name="expected_date">
                        <span class="field-help">When you expect the shipment to arrive. Optional.</span>
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="2" placeholder="Additional details or instructions"></textarea>
                    <span class="field-help">Special instructions for this order (delivery time, packaging, payment). Included in the purchase order email.</span>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                    <button type="button" onclick="closeModal()" style="padding: 9px 16px; border-radius: 6px; border: 1px solid #cbd5e1; background: #f8fafc; cursor: pointer;">Cancel</button>
                    <button type="submit" class="btn-add">Save Delivery</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() { document.getElementById('deliveryModal').style.display = 'flex'; }
        function closeModal() { document.getElementById('deliveryModal').style.display = 'none'; }

        function openSupplierModal(data) {
            const isEdit = data && typeof data === 'object';
            document.getElementById('supplierModalTitle').textContent = isEdit ? 'Edit Supplier' : 'Add Supplier';
            document.getElementById('supplierSubmitBtn').textContent = isEdit ? 'Update Supplier' : 'Save Supplier';
            document.getElementById('supplier_id').value      = isEdit ? data.id : '';
            document.getElementById('s_supplier_name').value   = isEdit ? (data.supplier_name || '') : '';
            document.getElementById('s_brand_name').value      = isEdit ? (data.brand_name || '') : '';
            document.getElementById('s_contact_person').value  = isEdit ? (data.contact_person || '') : '';
            document.getElementById('s_phone').value           = isEdit ? (data.phone || '') : '';
            document.getElementById('s_email').value           = isEdit ? (data.email || '') : '';
            document.getElementById('s_address').value         = isEdit ? (data.address || '') : '';
            document.getElementById('s_notes').value           = isEdit ? (data.notes || '') : '';
            document.getElementById('supplierModal').style.display = 'flex';
        }
        function closeSupplierModal() { document.getElementById('supplierModal').style.display = 'none'; }

        function fillSupplierFromDirectory() {
            const sel = document.getElementById('d_supplier_id');
            const opt = sel.options[sel.selectedIndex];
            const nameField = document.getElementById('d_supplier_name');
            const emailField = document.getElementById('d_supplier_email');
            if (sel.value !== '0' && opt) {
                nameField.value = opt.dataset.name || '';
                emailField.value = opt.dataset.email || '';
            } else {
                nameField.value = '';
                emailField.value = '';
            }
        }

        window.onclick = function (event) {
            ['deliveryModal', 'supplierModal'].forEach(function (id) {
                const modal = document.getElementById(id);
                if (event.target === modal) modal.style.display = 'none';
            });
        };
    </script>
    <script>
        // Tabs: Incoming Deliveries | Supplier Directory (remembers the last one; #suppliers / #deliveries also work)
        (function () {
            var tabs = document.querySelectorAll('.page-tab');
            function show(name, save) {
                if (!document.getElementById('panel-' + name)) name = 'deliveries';
                tabs.forEach(function (t) {
                    var on = t.dataset.tab === name;
                    t.classList.toggle('active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                    document.getElementById('panel-' + t.dataset.tab).hidden = !on;
                });
                if (save) { try { localStorage.setItem('deliveryTab', name); } catch (e) {} }
            }
            tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.tab, true); }); });
            var start = location.hash.replace('#', '');
            if (start !== 'suppliers' && start !== 'deliveries') { try { start = localStorage.getItem('deliveryTab') || ''; } catch (e) { start = ''; } }
            show(start, false);
        })();
    </script>
    <script>
        // No-refresh actions: every POST form on this page (add/edit supplier, activate/deactivate,
        // record delivery, mark delivered / cancel) is sent with fetch(); the JSON answer then updates
        // only the affected row, the counters and the supplier dropdown. Rows are keyed by id, so
        // nothing can appear twice.
        (function () {
            if (!window.LiveUI) return;

            var busyRow = function (tr) { return tr.classList.contains('liveui-row-busy'); };

            function syncTable(wrapId, keyAttr, freshDoc) {
                var cur = document.getElementById(wrapId), fr = freshDoc.getElementById(wrapId);
                if (!cur || !fr) return;
                var curBody = cur.querySelector('tbody'), frBody = fr.querySelector('tbody');
                if (curBody && frBody) LiveUI.syncKeyed(curBody, frBody, keyAttr, { skip: busyRow });
                else LiveUI.setHTML(cur, fr);            // empty state <-> table
            }

            // "Record Delivery" lists only ACTIVE suppliers; keep it in step with the directory.
            function syncSupplierOptions(freshDoc) {
                var cur = document.getElementById('d_supplier_id'), fr = freshDoc.getElementById('d_supplier_id');
                if (!cur || !fr || cur.innerHTML.trim() === fr.innerHTML.trim()) return;
                var keep = cur.value;
                cur.innerHTML = fr.innerHTML;
                cur.value = Array.prototype.some.call(cur.options, function (o) { return o.value === keep; }) ? keep : '0';
            }

            function refresh() {
                return LiveUI.fetchDoc(location.pathname + location.search).then(function (doc) {
                    doc.querySelectorAll('[data-stat]').forEach(function (el) {
                        LiveUI.setText(document.querySelector('[data-stat="' + el.getAttribute('data-stat') + '"]'), el.textContent);
                    });
                    syncTable('deliveries-wrap', 'data-delivery-id', doc);
                    syncTable('suppliers-wrap', 'data-supplier-id', doc);
                    syncSupplierOptions(doc);
                });
            }

            var LABELS = { save_supplier: 'Saving…', toggle_supplier: 'Updating…', add_delivery: 'Saving…', update_status: 'Updating…' };

            document.addEventListener('submit', function (e) {
                var form = e.target;
                if (!form.matches || !form.matches('form[method="POST"], form[method="post"]')) return;
                if (e.defaultPrevented) return;          // e.g. the admin pressed Cancel on the confirm() prompt
                e.preventDefault();

                var action = (form.querySelector('[name="action"]') || {}).value || '';
                var target = (form.querySelector('[name="delivery_id"], [name="supplier_id"]') || {}).value || '';
                var release = LiveUI.lock('delivery-' + action + '-' + target);
                if (!release) return;                    // already being processed: ignore the double click

                var btn = e.submitter || form.querySelector('button[type="submit"]');
                var row = form.closest('tr');
                var done = LiveUI.busy(btn, LABELS[action] || 'Working…');
                if (row) row.classList.add('liveui-row-busy');

                LiveUI.post(location.pathname + location.search, LiveUI.formData(form, btn)).then(function (res) {
                    LiveUI.toast(res.message, 'success');
                    if (res.warning) LiveUI.toast(res.warning, 'warn');
                    if (form.closest('#supplierModal')) closeSupplierModal();
                    if (form.closest('#deliveryModal')) { closeModal(); form.reset(); }
                    return true;
                }).catch(function (err) {
                    LiveUI.toast(err.message, 'error');  // the modal stays open so nothing typed is lost
                    return false;
                }).then(function (ok) {
                    done();
                    if (row) row.classList.remove('liveui-row-busy');
                    release();
                    if (ok) return refresh().catch(function (err) { LiveUI.toast(err.message, 'error'); });
                });
            });

            // Supplier deliveries / suppliers changed by another admin: a tiny probe every ~5s; the tables are
            // re-read only when the fingerprint changed (rows are keyed, so nothing is duplicated).
            LiveUI.watch('delivery', function () { return refresh(); }, { fallbackMs: 30000 });
        })();
    </script>
</body>
</html>
