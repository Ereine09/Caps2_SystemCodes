<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/ajax_helper.php';
require_once __DIR__ . '/../../app/helpers/customer_reset_schema_helper.php';

$token = getJWTFromCookie();
if (!$token) {
    ajax_auth_required();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$payload = verifyJWT($token);
if (!$payload) {
    clearJWTCookie();
    ajax_auth_required();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$user_id = (int) ($payload['user_id'] ?? 0);
$role = strtolower(trim($payload['role'] ?? 'staff'));

$search = trim($_GET['search'] ?? '');
$gender_filter = trim($_GET['gender'] ?? '');
$age_group = trim($_GET['age_group'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
if (!in_array($status_filter, ['active', 'inactive'], true)) {
    $status_filter = '';
}

if (isset($_POST['reset_customer_password'])) {
    // ADMIN → RESET CUSTOMER PASSWORD. In-person only: the customer walked into the
    // store, Admin verified their identity, and this is the ONLY path that can ever
    // change a customer's password - there is no online/self-service reset flow.
    csrf_require();

    if ($role !== 'admin') {
        if (is_ajax_request()) {
            ajax_fail('Only an Admin account can reset a customer password.', 403);
        }
        header('Location: customers.php#customers-section');
        exit();
    }

    $reset_customer_id = (int) ($_POST['customer_id'] ?? 0);
    $new_password = (string) ($_POST['new_password'] ?? '');
    $confirm_new_password = (string) ($_POST['confirm_new_password'] ?? '');

    $reset_error = null;
    if ($reset_customer_id <= 0) {
        $reset_error = 'Invalid customer.';
    } elseif (strlen($new_password) < 8) {
        $reset_error = 'New password must be at least 8 characters.';
    } elseif ($new_password !== $confirm_new_password) {
        $reset_error = 'New password and confirmation do not match.';
    }

    $reset_customer_name = null;
    if ($reset_error === null) {
        $cust_stmt = $conn->prepare("SELECT name FROM customers WHERE id = ? LIMIT 1");
        $cust_stmt->bind_param('i', $reset_customer_id);
        $cust_stmt->execute();
        $cust_row = $cust_stmt->get_result()->fetch_assoc();
        $cust_stmt->close();
        if (!$cust_row) {
            $reset_error = 'That customer no longer exists.';
        } else {
            $reset_customer_name = $cust_row['name'];
        }
    }

    if ($reset_error === null) {
        ensure_customer_password_meta_schema($conn);
        // password_changed_at also revokes any JWT session issued before this moment
        // (customer_token_is_stale() in customer/includes/auth.php) - this is the "old
        // phone/device should no longer remain authenticated" requirement, reusing the
        // existing stateless JWT architecture rather than a second session system.
        // Clearing login_attempts/lock_until too: Admin has just verified identity in
        // person, so any prior lockout no longer serves a purpose.
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $reset_stmt = $conn->prepare(
            "UPDATE customer_login_credentials
             SET password = ?, password_changed_at = NOW(), login_attempts = 0, lock_until = NULL
             WHERE customer_id = ?"
        );
        $reset_stmt->bind_param('si', $new_hash, $reset_customer_id);
        $reset_stmt->execute();
        $updated = $reset_stmt->affected_rows > 0;
        $reset_stmt->close();

        if (!$updated) {
            // affected_rows is 0 only when no credentials row exists for this customer_id
            // (password_changed_at = NOW() guarantees a real change on every real update).
            $reset_error = 'This customer does not have an online account to reset.';
        }
    }

    if ($reset_error === null) {
        $log_details = 'Admin reset the password for customer ' . $reset_customer_name . '.';
        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Reset Customer Password', ?)");
        $log_stmt->bind_param('is', $user_id, $log_details);
        $log_stmt->execute();
        $log_stmt->close();
    }

    if (is_ajax_request()) {
        if ($reset_error !== null) {
            ajax_fail($reset_error, 422);
        }
        ajax_json(['success' => true, 'message' => 'Password reset successfully. The customer can now log in using the new password.']);
    }
    $_SESSION['message'] = $reset_error ?? 'Password reset successfully.';
    $_SESSION['message_type'] = $reset_error === null ? 'success' : 'error';
    header('Location: customers.php#customers-section');
    exit();
}

if (isset($_POST['del'])) {
    csrf_require();
    $id = (int) $_POST['del'];
    $deleted_name = null;

    try {
        $res = mysqli_query($conn, "SELECT name FROM customers WHERE id='$id'");
        if ($customer = mysqli_fetch_assoc($res)) {
            $customer_name = $customer['name'];
            mysqli_query($conn, "DELETE FROM customers WHERE id='$id'");

            $details = "Deleted customer: $customer_name";
            $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'DELETE', ?)");
            $stmt->bind_param("is", $user_id, $details);
            $stmt->execute();
            $deleted_name = $customer_name;
        }
    } catch (Throwable $e) {
        if (!is_ajax_request()) {
            throw $e; // normal page request: behave exactly as before
        }
        error_log('customers.php delete failed: ' . $e->getMessage());
        // 1451 = a foreign key blocks the delete (the customer has loyalty / notification / redemption history)
        $blocked = ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1451);
        ajax_fail(
            $blocked ? 'This customer has loyalty, notification or reward history, so they cannot be deleted.'
                     : 'This customer could not be deleted. Please try again.',
            $blocked ? 409 : 500
        );
    }

    // AJAX: JSON answer; the page removes just this row (no redirect / reload).
    if (is_ajax_request()) {
        if ($deleted_name === null) {
            ajax_fail('That customer no longer exists.', 404, ['customer_id' => $id]);
        }
        ajax_json(['success' => true, 'message' => 'Customer "' . $deleted_name . '" was deleted.', 'customer_id' => $id]);
    }

    header('Location: customers.php#customers-section');
    exit();
}

if (isset($_POST['add_customer'])) {
    // "New Customer" modal: same fields and the same INSERT + activity log as modules/customers/add_customer.php,
    // but answered as JSON so the page can insert the row without leaving. Server-side validation is authoritative.
    csrf_require();
    $new_name = trim((string) ($_POST['name'] ?? ''));
    $new_email = trim((string) ($_POST['email'] ?? ''));
    $new_phone = trim((string) ($_POST['phone'] ?? ''));
    $new_gender = trim((string) ($_POST['gender'] ?? ''));
    $new_age = (($_POST['age'] ?? '') !== '') ? (int) $_POST['age'] : null;
    $new_address = trim((string) ($_POST['address'] ?? ''));

    $add_error = null;
    if ($new_name === '' || mb_strlen($new_name) > 255) {
        $add_error = 'Please enter the customer\'s name.';
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL) || mb_strlen($new_email) > 255) {
        $add_error = 'Please enter a valid email address.';
    } elseif ($new_phone === '' || mb_strlen($new_phone) > 50) {
        $add_error = 'Please enter a contact number (up to 50 characters).';
    } elseif ($new_gender !== '' && !in_array($new_gender, ['Male', 'Female'], true)) {
        $add_error = 'Please choose a valid gender.';
    } elseif ($new_age !== null && ($new_age < 0 || $new_age > 120)) {
        $add_error = 'Age must be between 0 and 120.';
    } elseif (mb_strlen($new_address) > 500) {
        $add_error = 'The address is too long (500 characters at most).';
    }

    $new_id = 0;
    if ($add_error === null) {
        try {
            $dup = $conn->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
            $dup->bind_param('s', $new_email);
            $dup->execute();
            $is_dup = $dup->get_result()->num_rows > 0;
            $dup->close();
            if ($is_dup) {
                $add_error = 'That email address is already used by another customer.';
            } else {
                $ins = $conn->prepare("INSERT INTO customers (name, email, phone, gender, age, address) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->bind_param('ssssis', $new_name, $new_email, $new_phone, $new_gender, $new_age, $new_address);
                $ins->execute();
                $new_id = (int) $conn->insert_id;
                $ins->close();

                $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Add Customer', ?)");
                $log_details = 'Added new customer: ' . $new_name;
                $log->bind_param('is', $user_id, $log_details);
                $log->execute();
                $log->close();
            }
        } catch (Throwable $e) {
            error_log('customers.php add failed: ' . $e->getMessage());
            $add_error = ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062)
                ? 'That email address is already used by another customer.'
                : 'The customer could not be saved. Please try again.';
        }
    }

    if (is_ajax_request()) {
        if ($add_error !== null) {
            ajax_fail($add_error, 422);
        }
        ajax_json(['success' => true, 'message' => 'Customer "' . $new_name . '" was added.', 'customer_id' => $new_id]);
    }
    $_SESSION['message'] = $add_error ?? ('Customer "' . $new_name . '" was added.');
    $_SESSION['message_type'] = $add_error === null ? 'success' : 'error';
    header('Location: customers.php#customers-section');
    exit();
}
$update = false;
$id = 0;
$name = '';
$email = '';
$phone = '';
$gender = '';
$age = '';

if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $update = true;
    $result_edit = mysqli_query($conn, "SELECT * FROM customers WHERE id='$id'");
    if ($row_edit = mysqli_fetch_assoc($result_edit)) {
        $name = $row_edit['name'];
        $email = $row_edit['email'];
        $phone = $row_edit['phone'];
        $gender = $row_edit['gender'] ?? '';
        $age = $row_edit['age'] ?? '';
    }
}

if (isset($_POST['update_customer'])) {
    csrf_require();
    $id = (int) $_POST['id'];
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $gender = mysqli_real_escape_string($conn, $_POST['gender'] ?? '');
    $age = isset($_POST['age']) && $_POST['age'] !== '' ? (int) $_POST['age'] : 'NULL';

    if (is_ajax_request()) {
        // The browser form already checks these; re-check on the server because AJAX callers could skip it.
        $raw_name = trim((string) ($_POST['name'] ?? ''));
        $raw_email = trim((string) ($_POST['email'] ?? ''));
        if ($id <= 0 || $raw_name === '') {
            ajax_fail('Please enter the customer\'s name.', 422);
        }
        if (!filter_var($raw_email, FILTER_VALIDATE_EMAIL)) {
            ajax_fail('Please enter a valid email address.', 422);
        }
        if ($age !== 'NULL' && ($age < 0 || $age > 120)) {
            ajax_fail('Age must be between 0 and 120.', 422);
        }
        $exists = $conn->prepare("SELECT id FROM customers WHERE id = ?");
        $exists->bind_param('i', $id);
        $exists->execute();
        $found = $exists->get_result()->num_rows > 0;
        $exists->close();
        if (!$found) {
            ajax_fail('That customer no longer exists.', 404);
        }
    }

    try {
        $sql = "UPDATE customers SET name='$name', email='$email', phone='$phone', gender='$gender', age=$age WHERE id='$id'";
        mysqli_query($conn, $sql);

        $details = "Updated customer: $name";
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'UPDATE', ?)");
        $stmt->bind_param("is", $user_id, $details);
        $stmt->execute();
    } catch (Throwable $e) {
        if (!is_ajax_request()) {
            throw $e; // normal page request: behave exactly as before
        }
        error_log('customers.php update failed: ' . $e->getMessage());
        $dup = ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062);
        ajax_fail($dup ? 'That email address is already used by another customer.' : 'The customer could not be saved. Please try again.', $dup ? 422 : 500);
    }

    if (is_ajax_request()) {
        ajax_json(['success' => true, 'message' => 'Customer "' . trim((string) $_POST['name']) . '" was updated.', 'customer_id' => $id]);
    }

    header('Location: customers.php#customers-section');
    exit();
}

$total_customers = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM customers"))['total'];
// Same viewed-aware badge query every other admin page uses (see the
// Orders sidebar badge fix) - this file was missed from that pass.
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);

/* --------------------------------------------------------------------
   Summary cards — real, unfiltered counts straight from the customers
   table (same "active" rule already used per-row below: a loyalty
   transaction within the last 30 days). Nothing hard-coded, nothing
   invented — this is the exact same status logic the table already used,
   just also rolled up into totals.
   -------------------------------------------------------------------- */
$active_customers = (int) mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT COUNT(*) AS c FROM customers c
     WHERE EXISTS (
        SELECT 1 FROM loyalty_transactions lt
        WHERE lt.customer_id = c.id AND lt.created_at > (NOW() - INTERVAL 30 DAY)
     )"
))['c'];
$inactive_customers = max(0, $total_customers - $active_customers);
$total_loyalty_points = (float) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(loyalty_points), 0) AS total FROM customers"))['total'];

$has_active_filters = ($search !== '' || $gender_filter !== '' || $age_group !== '' || $status_filter !== '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - DPS Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/live-ui.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/live-ui.css') ?: time(); ?>">
    <script src="<?php echo BASE_URL; ?>/assets/js/live-ui.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/live-ui.js') ?: time(); ?>"></script>
    <style>
        .status-dot { height: 9px; width: 9px; border-radius: 50%; display: inline-block; }
        .status-active { background-color: #27ae60; box-shadow: 0 0 5px #27ae60; }
        .status-inactive { background-color: #bdc3c7; }
        .status-label { display: inline-flex; align-items: center; gap: 7px; font-size: 0.85rem; font-weight: 600; color: var(--text-2); }
        .progress-container { width: 100%; background-color: #eee; border-radius: 10px; margin-top: 6px; height: 8px; overflow: hidden; }
        .progress-bar { height: 100%; background: linear-gradient(90deg, #f1c40f, #f39c12); border-radius: 10px; transition: width 0.3s; }
        .progress-percent { font-size: 0.72rem; color: var(--muted); margin-top: 3px; display: block; }
        .quick-add-btn { background: #f1c40f; color: var(--text); padding: 6px 9px; border-radius: 6px; text-decoration: none; font-size: 0.78rem; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; }

        /* Summary tiles — same .stats-grid/.stat-card shape used on other
           Admin pages (Staff Management, Activity Logs); already Dark-Mode
           themed centrally via admin_style.css. */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin: 22px 0 26px; }
        .stat-card { background: var(--surface); padding: 18px 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid var(--border); display: flex; align-items: center; gap: 14px; }
        .stat-icon { width: 46px; height: 46px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
        .icon-blue { background: var(--info-soft); color: var(--info); }
        .icon-green { background: var(--success-soft); color: var(--success); }
        .icon-gray { background: var(--surface-2); color: var(--muted); }
        .icon-purple { background: var(--primary-soft); color: var(--accent); }
        .stat-info h3 { margin: 0; font-size: 1.5rem; font-weight: 800; color: var(--text); line-height: 1.1; }
        .stat-info p { margin: 2px 0 0; font-size: 0.8rem; color: var(--muted); font-weight: 600; }

        /* Toolbar */
        .cust-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 14px; }
        .cust-toolbar .search-box { position: relative; flex: 2 1 240px; min-width: 220px; }
        .cust-toolbar .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 0.85rem; }
        .cust-toolbar .search-box input { width: 100%; padding: 10px 12px 10px 34px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.9rem; box-sizing: border-box; }
        .cust-toolbar select { padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.9rem; min-width: 140px; flex: 1 1 140px; }
        .cust-toolbar .btn-search { padding: 10px 18px; border-radius: 8px; border: 1px solid #4a3e94; background: #4a3e94; color: #fff; font-weight: 600; cursor: pointer; font-size: 0.9rem; white-space: nowrap; }
        .cust-toolbar .btn-reset { padding: 10px 16px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text-2); text-decoration: none; font-size: 0.85rem; font-weight: 600; white-space: nowrap; }
        .result-count { color: var(--muted); font-size: 0.85rem; margin: 0 0 14px; }

        /* Table */
        .customer-table-wrapper { overflow-x: auto; }
        .customer-name-link { text-decoration: none; color: var(--link); font-weight: 700; display: inline-flex; align-items: center; gap: 8px; }
        .customer-name-link i { font-size: 1.3rem; color: #b3a9e0; }
        .customer-name-link:hover { text-decoration: underline; }
        .contact-cell span { display: block; }
        .contact-cell small { color: var(--muted); }
        .profile-cell { font-size: 0.85rem; color: var(--text-2); }

        .row-actions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .icon-action-btn {
            width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 8px; border: 1px solid transparent; background: none; cursor: pointer; text-decoration: none; font-size: 0.9rem;
        }
        .icon-action-btn.view { color: var(--success); background: var(--success-soft); }
        .icon-action-btn.edit { color: #3498db; background: var(--info-soft); }
        .icon-action-btn.order { color: var(--accent); background: var(--primary-soft); }
        .icon-action-btn.delete { color: var(--danger); background: var(--danger-soft); }
        .icon-action-btn.reset-pass { color: var(--warning); background: var(--warning-soft); }
        .icon-action-btn:hover { filter: brightness(0.96); }

        .empty-state-box { text-align: center; padding: 60px 20px; color: var(--muted); }
        .empty-state-box i { font-size: 2.4rem; color: #cbd5e1; margin-bottom: 12px; display: block; }
        .empty-state-box p.main { color: var(--muted); font-weight: 600; margin: 0 0 4px; }
        .empty-state-box p.sub { margin: 0 0 16px; font-size: 0.85rem; }

        /* Delete confirmation / Create Order confirmation modals (shared shell) */
        .cust-modal { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); z-index: 2000; align-items: center; justify-content: center; padding: 16px; }
        .cust-modal.active { display: flex; }
        /* min() keeps it comfortably sized on desktop (480px) while never
           exceeding the viewport minus a small margin on narrow phones. */
        .cust-modal .modal-content { background: var(--surface); border-radius: 14px; max-width: min(480px, calc(100vw - 32px)); width: 100%; padding: 26px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); box-sizing: border-box; }
        .cust-modal h3 { margin: 0 0 14px; font-size: 1.15rem; color: var(--danger); display: flex; align-items: center; gap: 10px; }
        .cust-modal p { margin: 0 0 18px; color: var(--text-2); font-size: 0.9rem; line-height: 1.5; }
        .cust-modal p strong { color: var(--text); }
        /* Both the Cancel <button> and the Continue <a> opt into identical
           sizing via this shared class — the earlier bug was scoping this
           to "button" only, so the <a> never got flex/padding and looked
           clipped next to a properly-sized Cancel button. */
        .cust-modal-actions { display: flex; gap: 12px; margin-top: 4px; }
        /* New Customer modal */
        #addCustomerModal .modal-content { max-height: calc(100vh - 32px); overflow-y: auto; }
        .cf-label { display: block; margin: 0 0 4px; font-size: 0.8rem; font-weight: 700; color: var(--text-2); }
        .cf { display: block; width: 100%; box-sizing: border-box; padding: 10px 12px; margin: 0 0 12px; border: 1.5px solid var(--border); border-radius: 8px; font: inherit; font-size: 0.92rem; background: var(--surface); color: var(--text); }
        .cf:focus { outline: none; border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.18); }
        .cf-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .cf-error { margin: 0 0 12px; padding: 9px 12px; border-radius: 8px; background: var(--danger-soft); color: var(--danger); font-size: 0.85rem; font-weight: 600; border: 1px solid #fecaca; }
        .btn-save-customer { background: #16a34a; color: #fff; border: 1px solid #16a34a; }
        .btn-save-customer:hover { background: #15803d; }
        body.dark-mode .cf { background: var(--dm-surface-2, #1b2436); color: var(--dm-text, #e5e7eb); border-color: var(--dm-border, #2f3b52); }
        body.dark-mode .cf-label { color: var(--dm-text-muted, #94a3b8); }
        @media (max-width: 480px) { .cf-row { grid-template-columns: 1fr; } }
        .cust-modal-actions .cust-modal-btn {
            flex: 1; min-width: 0; padding: 12px 14px; border-radius: 8px; font-weight: 700; font-size: 0.9rem;
            cursor: pointer; border: 1px solid var(--border); text-align: center; text-decoration: none;
            display: inline-flex; align-items: center; justify-content: center; gap: 7px; box-sizing: border-box;
            min-height: 44px; /* touch-friendly on mobile */
        }
        .btn-cancel-delete { background: var(--surface-2); color: var(--text-2); }
        .btn-confirm-delete { background: #dc2626; color: #fff; border-color: #dc2626; }

        /* Create Order modal's own customer-summary block */
        .aco-confirm-customer { display: flex; align-items: center; gap: 12px; background: var(--surface-2); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
        .aco-confirm-customer .avatar { width: 42px; height: 42px; border-radius: 50%; background: var(--primary-soft); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
        .aco-confirm-customer .name { font-size: 1rem; font-weight: 700; color: var(--text); }
        .aco-confirm-customer .cid { font-size: 0.78rem; color: var(--muted); font-weight: 600; margin-top: 1px; }
        .aco-confirm-customer .contact { font-size: 0.82rem; color: var(--text-2); margin-top: 5px; }
        .aco-confirm-customer .contact strong { color: var(--text); font-weight: 600; }

        @media (max-width: 420px) {
            .cust-modal-actions { flex-direction: column; }
        }

        @media (max-width: 640px) {
            .cust-toolbar { flex-direction: column; align-items: stretch; }
            .cust-toolbar select, .cust-toolbar .btn-search, .cust-toolbar .btn-reset { width: 100%; }
        }

        /* Customer list pagination — uses the same --dm-* variables
           admin_style.css already defines on :root/body.dark-mode (see
           modules/admin/orders.php's identical pagination for the reasoning),
           so it tracks Dark/Light Mode automatically with no separate
           override block needed. */
        .cust-pagination {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--dm-border, #eee);
        }
        .cust-pagination-nav {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            max-width: 100%;
        }
        .cust-page-numbers {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: nowrap;
            overflow-x: auto;
            max-width: 100%;
            padding-bottom: 2px;
        }
        .cust-page-btn, .cust-page-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: 8px;
            border: 1px solid var(--dm-border, #e2e8f0);
            background: var(--dm-surface, #fff);
            color: var(--dm-text, #1e293b);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            flex-shrink: 0;
            transition: background .15s, color .15s, border-color .15s;
        }
        a.cust-page-btn:hover, a.cust-page-num:hover {
            background: var(--dm-surface-2, #f1f5f9);
            border-color: #4a3e94;
        }
        .cust-page-num.active {
            background: #4a3e94;
            border-color: #4a3e94;
            color: #fff;
        }
        .cust-page-btn.disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .cust-page-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            color: var(--dm-text-muted, #94a3b8);
            font-size: 0.82rem;
            flex-shrink: 0;
        }

        @media (max-width: 480px) {
            .cust-pagination-nav { width: 100%; justify-content: center; }
            .cust-page-btn-label { display: none; }
        }

        /* ---------- Section tabs (Customers | Carts) + cart panels ----------
           Same --dm-* tokens as the pagination above, so light/dark follow the site toggle. */
        .cust-tabs {
            display: flex; flex-wrap: nowrap; gap: 4px; margin: 0 0 20px; padding: 6px;
            border-radius: 12px; border: 1px solid var(--dm-border, #e5e7eb);
            background: var(--dm-surface, #fff); overflow-x: auto; scrollbar-width: thin;
        }
        .cust-tab {
            flex: 1 0 auto; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 11px 18px; border-radius: 9px; font-size: 0.92rem; font-weight: 700; white-space: nowrap;
            text-decoration: none; color: var(--dm-text-muted, #64748b); border: 1px solid transparent;
            transition: background .15s ease, color .15s ease;
        }
        .cust-tab:hover { background: var(--dm-surface-2, #f1f5f9); color: var(--dm-text, #1e293b); }
        .cust-tab.is-active { background: #4a3e94; color: #fff; box-shadow: 0 3px 10px rgba(74, 62, 148, 0.30); }
        .cust-tab-count {
            padding: 1px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 700;
            background: var(--dm-surface-2, #f1f5f9); color: var(--dm-text-muted, #64748b);
        }
        .cust-tab-count-warn { background: var(--warning-soft); color: var(--warning); }
        .cust-tab.is-active .cust-tab-count { background: rgba(255, 255, 255, 0.22); color: #fff; }
        .cust-tab.is-active .cust-tab-count-warn { background: #fbbf24; color: #451a03; }
        .cust-panel[hidden] { display: none; }

        .cart-intro h2 { margin: 0 0 4px; font-size: 1.25rem; }
        .cart-intro p { margin: 0 0 18px; color: var(--dm-text-muted, #64748b); font-size: 0.9rem; }
        .cart-intro h2 i { color: var(--link); margin-right: 6px; }
        .cart-box { margin-bottom: 22px; padding: 20px; border-radius: 14px; background: var(--dm-surface, #fff);
            border: 1px solid var(--dm-border, #e5e7eb); border-left-width: 5px; box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06); }
        .cart-live { border-left-color: #10b981; }
        .cart-abandoned { border-left-color: #f59e0b; }
        .cart-box h3 { margin: 0 0 14px; display: flex; align-items: center; flex-wrap: wrap; gap: 8px; font-size: 1.05rem; }
        .cart-live h3 i { color: var(--success); }
        .cart-abandoned h3 i { color: #f59e0b; }
        .cart-note { font-weight: 400; font-size: 0.8rem; color: var(--dm-text-muted, #94a3b8); }
        .cart-pill { margin-left: auto; min-width: 26px; padding: 2px 10px; border-radius: 999px; text-align: center; font-size: 0.8rem; font-weight: 700; }
        .cart-pill-live { background: var(--success-soft); color: var(--success); }
        .cart-pill-warn { background: var(--warning-soft); color: var(--warning); }
        .cart-empty { margin: 4px 0 0; padding: 14px; text-align: center; border: 1px dashed var(--dm-border, #d1d5db); border-radius: 10px; color: var(--dm-text-muted, #64748b); }
        .cart-empty i { color: var(--success); margin-right: 6px; }
        table.cart-table { width: 100%; border-collapse: collapse; }
        .cart-table th { padding: 10px; text-align: left; font-size: 0.8rem; background: var(--dm-surface-2, #f8f9fa); border-bottom: 2px solid var(--dm-border, #eee); }
        .cart-table td { padding: 10px; border-bottom: 1px solid var(--dm-border, #f1f5f9); vertical-align: middle; }
        .cart-table tr:last-child td { border-bottom: 0; }
        .cart-table .num { text-align: right; white-space: nowrap; }
        .cart-table .ctr { text-align: center; }
        .cart-table .strong { font-weight: 700; }
        .cart-table small { display: block; margin-top: 2px; font-size: 0.75rem; color: var(--dm-text-muted, #94a3b8); }
        .cart-items { font-size: 0.85rem; color: var(--dm-text-muted, #64748b); max-width: 340px; overflow-wrap: anywhere; }
        .cart-when { font-size: 0.85rem; white-space: nowrap; }
        .cart-live-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 6px #10b981; margin-right: 4px; }
        .cart-remind { padding: 6px 12px; font-size: 0.8rem; background: #f59e0b; }
        /* dark mode: the shared dark rules reset table-box borders and text colours, so restate ours */
        body.dark-mode .table-box.cart-live { border-left-color: #10b981; }
        body.dark-mode .table-box.cart-abandoned { border-left-color: #f59e0b; }
        body.dark-mode .cart-box .cart-pill-live { background: #064e3b; color: #6ee7b7; }
        body.dark-mode .cart-box .cart-pill-warn { background: #4a3410; color: #fcd34d; }
        body.dark-mode .cust-tab-count-warn { background: #4a3410; color: #fcd34d; }
        body.dark-mode .cust-tab.is-active .cust-tab-count-warn { background: #fbbf24; color: #451a03; }
        @media (max-width: 640px) {
            .cust-tab { padding: 10px 9px; gap: 6px; font-size: 0.8rem; }
            .cust-tab > i { display: none; }
            .cust-tab-count { padding: 1px 6px; font-size: 0.68rem; }
            .cart-box { padding: 14px; }
            .cart-pill { margin-left: 0; }
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="welcome-header" style="margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h1>Customer Management 👥</h1>
                    <p>Manage customer accounts, loyalty information, and customer activity.</p>
                </div>
                <?php if (isset($role) && $role === 'admin'): ?>
                    <a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php#loyal-customers-section" style="background: #4a3e94; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600; white-space: nowrap;"><i class="fas fa-chart-bar"></i> View Customer Reports</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
            <div style="background: <?php echo $_SESSION['message_type'] === 'success' ? '#eafaf1' : '#fff5f5'; ?>; color: <?php echo $_SESSION['message_type'] === 'success' ? '#27ae60' : '#e74c3c'; ?>; padding: 15px; border-radius: 8px; border-left: 5px solid <?php echo $_SESSION['message_type'] === 'success' ? '#27ae60' : '#e74c3c'; ?>; margin-bottom: 20px; font-weight: 600;">
                <i class="fas <?php echo $_SESSION['message_type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message'], $_SESSION['message_type']); ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="fas fa-users"></i></div>
                <div class="stat-info"><h3 data-stat="total"><?php echo $total_customers; ?></h3><p>Total Customers</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green"><i class="fas fa-user-check"></i></div>
                <div class="stat-info"><h3 data-stat="active"><?php echo $active_customers; ?></h3><p>Active</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-gray"><i class="fas fa-user-clock"></i></div>
                <div class="stat-info"><h3 data-stat="inactive"><?php echo $inactive_customers; ?></h3><p>Inactive</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-purple"><i class="fas fa-star"></i></div>
                <div class="stat-info"><h3 data-stat="points"><?php echo number_format($total_loyalty_points, 2); ?></h3><p>Loyalty Points</p></div>
            </div>
        </div>

        <div class="cust-panel" id="panel-customers" data-panel="customers">
        <div id="customer-edit-panel">
        <?php if ($update == true): ?>
        <div class="table-box" style="margin-bottom: 25px; border-left: 5px solid #3498db; background: #fff; padding: 20px; border-radius: 12px;">
            <h3><i class="fas fa-edit"></i> Edit Customer Info</h3>
            <form action="customers.php" method="POST" style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="text" name="name" value="<?php echo htmlspecialchars($name); ?>" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd; flex: 1; min-width: 180px;">
                <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd; flex: 1; min-width: 180px;">
                <input type="text" name="phone" value="<?php echo htmlspecialchars($phone); ?>" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd; flex: 1; min-width: 160px;">
                <select name="gender" required style="padding: 10px; border-radius: 5px; border: 1px solid #ddd; flex: 1; min-width: 150px;">
                    <option value="">Select Gender</option>
                    <option value="Male" <?php echo $gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?php echo $gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                </select>
                <input type="number" name="age" value="<?php echo htmlspecialchars((string) $age); ?>" min="0" max="120" required placeholder="Age" style="padding: 10px; border-radius: 5px; border: 1px solid #ddd; flex: 1; min-width: 120px;">
                <button type="submit" name="update_customer" style="background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">Update</button>
                <a href="customers.php#customers-section" style="background: #bdc3c7; color: black; padding: 10px 20px; border-radius: 5px; text-decoration: none;">Cancel</a>
            </form>
        </div>
        <?php endif; ?>
        </div><!-- /#customer-edit-panel -->

        <div id="customers-section" class="table-box" style="background: white; padding: 25px; border-radius: 15px; box-shadow: 0 10px 20px rgba(0,0,0,0.05);">
            <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h2 style="margin: 0;">Customer List</h2>
                    <p style="margin: 6px 0 0; color: #666;">Search by name, email, or contact number, and filter by gender, age, or status.</p>
                </div>
                <a href="add_customer.php" id="btn-new-customer" class="btn-add" style="background: #27ae60; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600;">
                    <i class="fas fa-plus"></i> New Customer
                </a>
            </div>

            <form method="GET" action="customers.php#customers-section" class="cust-toolbar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search customer name, email, or phone...">
                </div>
                <select name="gender">
                    <option value="">All Genders</option>
                    <option value="Male" <?php echo $gender_filter === 'Male' ? 'selected' : ''; ?>>Men</option>
                    <option value="Female" <?php echo $gender_filter === 'Female' ? 'selected' : ''; ?>>Women</option>
                </select>
                <select name="age_group">
                    <option value="">All Ages</option>
                    <option value="18below" <?php echo $age_group === '18below' ? 'selected' : ''; ?>>18 below</option>
                    <option value="19to35" <?php echo $age_group === '19to35' ? 'selected' : ''; ?>>19 - 35</option>
                    <option value="36to59" <?php echo $age_group === '36to59' ? 'selected' : ''; ?>>36 - 59</option>
                    <option value="60above" <?php echo $age_group === '60above' ? 'selected' : ''; ?>>60 above</option>
                </select>
                <select name="status">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
                <button type="submit" class="btn-search"><i class="fas fa-filter"></i> Search</button>
                <a href="customers.php#customers-section" class="btn-reset">Reset</a>
            </form>

            <?php
            $sql = "SELECT c.*, MAX(lt.created_at) as last_activity
                    FROM customers c
                    LEFT JOIN loyalty_transactions lt ON c.id = lt.customer_id
                    WHERE 1=1";
            $params = [];
            $types = "";

            if ($search !== '') {
                $sql .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)";
                $like = "%$search%";
                array_push($params, $like, $like, $like);
                $types .= "sss";
            }

            if ($gender_filter !== '') {
                $sql .= " AND gender = ?";
                $params[] = $gender_filter;
                $types .= "s";
            }

            switch ($age_group) {
                case '18below':
                    $sql .= " AND age IS NOT NULL AND age <= 18";
                    break;
                case '19to35':
                    $sql .= " AND age BETWEEN 19 AND 35"; // No need for params here as values are fixed
                    break;
                case '36to59':
                    $sql .= " AND age BETWEEN 36 AND 59"; // No need for params here as values are fixed
                    break;
                case '60above':
                    $sql .= " AND age IS NOT NULL AND age >= 60";
                    break;
            }
            $sql .= " GROUP BY c.id";

            // Status filter reuses the exact same "active = loyalty activity
            // within the last 30 days" rule the Status column already shows —
            // applied on the aggregated last_activity, so it needs HAVING.
            if ($status_filter === 'active') {
                $sql .= " HAVING last_activity > (NOW() - INTERVAL 30 DAY)";
            } elseif ($status_filter === 'inactive') {
                $sql .= " HAVING last_activity IS NULL OR last_activity <= (NOW() - INTERVAL 30 DAY)";
            }
            // $sql at this point is the fully filtered (search/gender/age/status)
            // query, WITHOUT ordering/paging yet — reused below for both the
            // filtered count (wrapped) and the actual page of rows (LIMIT/OFFSET
            // appended), so the two can never drift out of sync with each other.

            // --- Pagination (server-side) ---
            $per_page = 15;

            $count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM ($sql) AS filtered_customers");
            if (!empty($params)) {
                $count_stmt->bind_param($types, ...$params);
            }
            $count_stmt->execute();
            $filtered_count = (int) ($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $count_stmt->close();

            $total_pages = max(1, (int) ceil($filtered_count / $per_page));
            $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
            if ($page < 1) {
                $page = 1;
            }
            if ($page > $total_pages) {
                $page = $total_pages;
            }
            $offset = ($page - 1) * $per_page;

            /**
             * Builds a customers.php URL for the given page number, preserving
             * search/gender/age_group/status (and any other existing query
             * param) so pagination never loses the active search/filters —
             * dropping only 'edit' so clicking a page number doesn't leave a
             * stale "Edit Customer" form open for a customer no longer on
             * screen.
             */
            function customers_page_url(int $target_page): string
            {
                $params = $_GET;
                unset($params['edit']);
                $params['page'] = $target_page;
                $qs = http_build_query($params);
                return 'customers.php' . ($qs !== '' ? '?' . $qs : '') . '#customers-section';
            }

            // Windowed page-number list with ellipsis, same approach already
            // used on modules/admin/orders.php's pagination.
            $page_window = 2;
            $page_numbers = [];
            for ($i = 1; $i <= $total_pages; $i++) {
                if ($i === 1 || $i === $total_pages || ($i >= $page - $page_window && $i <= $page + $page_window)) {
                    $page_numbers[] = $i;
                } elseif (end($page_numbers) !== '...') {
                    $page_numbers[] = '...';
                }
            }

            $sql .= " ORDER BY c.id ASC LIMIT ? OFFSET ?";
            $data_types = $types . 'ii';
            $data_params = array_merge($params, [$per_page, $offset]);

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($data_types, ...$data_params);
            $stmt->execute();
            $result = $stmt->get_result();

            $showing_from = $filtered_count > 0 ? $offset + 1 : 0;
            $showing_to = min($offset + $per_page, $filtered_count);
            ?>

            <div id="customers-live">
            <p class="result-count">Showing <?php echo number_format($showing_from); ?>&ndash;<?php echo number_format($showing_to); ?> of <?php echo number_format($filtered_count); ?> customers</p>

            <?php if ($filtered_count > 0): ?>
            <div class="customer-table-wrapper">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8f9fa; border-bottom: 2px solid #eee;">
                            <th style="padding: 12px 10px; text-align: left; font-size: 0.85rem;">Customer</th>
                            <th style="padding: 12px 10px; text-align: left; font-size: 0.85rem;">Status</th>
                            <th style="padding: 12px 10px; text-align: left; font-size: 0.85rem;">Loyalty</th>
                            <th style="padding: 12px 10px; text-align: left; font-size: 0.85rem;">Contact</th>
                            <th style="padding: 12px 10px; text-align: left; font-size: 0.85rem;">Profile</th>
                            <th style="padding: 12px 10px; text-align: left; font-size: 0.85rem;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $thresholds = notifications_reward_thresholds();
                        while ($row = mysqli_fetch_assoc($result)) {
                            $display_gender = !empty($row['gender']) ? htmlspecialchars($row['gender']) : 'Not set';
                            $display_age = isset($row['age']) && $row['age'] !== null ? (int) $row['age'] : 'N/A';
                            $pts = (float) $row['loyalty_points'];

                            // Status Logic: Active if transaction within 30 days (unchanged)
                            $isActive = (isset($row['last_activity']) && strtotime($row['last_activity']) > strtotime('-30 days'));
                            $statusClass = $isActive ? 'status-active' : 'status-inactive';
                            $statusLabel = $isActive ? 'Active' : 'Inactive';

                            // Progress Logic (unchanged)
                            $next_target = $thresholds[0];
                            foreach ($thresholds as $t) {
                                if ($pts < $t) { $next_target = $t; break; }
                                $next_target = $t;
                            }
                            $percent = min(100, ($pts / $next_target) * 100);

                            $safe_name_js = htmlspecialchars($row['name'], ENT_QUOTES);
                            ?>
                            <tr style="border-bottom: 1px solid #eee;" data-customer-id="<?php echo (int) $row['id']; ?>">
                                <td style="padding: 12px 10px;">
                                    <a href="customer_details.php?id=<?php echo (int) $row['id']; ?>" class="customer-name-link">
                                        <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($row['name']); ?>
                                    </a>
                                </td>
                                <td style="padding: 12px 10px;">
                                    <span class="status-label"><span class="status-dot <?php echo $statusClass; ?>"></span> <?php echo $statusLabel; ?></span>
                                </td>
                                <td style="padding: 12px 10px; font-size: 0.78rem; width: 170px;">
                                    <strong><?php echo number_format($pts, 2); ?> / <?php echo $next_target; ?></strong>
                                    <div class="progress-container"><div class="progress-bar" style="width: <?php echo $percent; ?>%"></div></div>
                                    <span class="progress-percent"><?php echo number_format($percent, 1); ?>%</span>
                                </td>
                                <td style="padding: 12px 10px; font-size: 0.85rem;" class="contact-cell">
                                    <span><?php echo htmlspecialchars($row['email']); ?></span>
                                    <small><?php echo htmlspecialchars($row['phone']); ?></small>
                                </td>
                                <td style="padding: 12px 10px;" class="profile-cell"><?php echo htmlspecialchars($display_gender); ?>, <?php echo htmlspecialchars($display_age); ?></td>
                                <td style="padding: 12px 10px;">
                                    <div class="row-actions">
                                        <a href="loyalty_points.php?customer_id=<?php echo (int) $row['id']; ?>" class="quick-add-btn" title="Add Loyalty Points"><i class="fas fa-plus"></i> Pts</a>
                                        <a href="customer_details.php?id=<?php echo (int) $row['id']; ?>" class="icon-action-btn view" title="View Customer"><i class="fas fa-eye"></i></a>
                                        <a href="customers.php?edit=<?php echo (int) $row['id']; ?>#customers-section" class="icon-action-btn edit" title="Edit Customer"><i class="fas fa-edit"></i></a>
                                        <button type="button" class="icon-action-btn order" title="Create an order on behalf of this customer"
                                            onclick="openCreateOrderModal(<?php echo (int) $row['id']; ?>, '<?php echo addslashes($safe_name_js); ?>', '<?php echo addslashes(htmlspecialchars($row['phone'] ?? 'N/A', ENT_QUOTES)); ?>')">
                                            <i class="fas fa-cart-plus"></i>
                                        </button>
                                        <?php if ($role === 'admin'): ?>
                                        <button type="button" class="icon-action-btn reset-pass" title="Reset Password"
                                            onclick="openResetPasswordModal(<?php echo (int) $row['id']; ?>, '<?php echo addslashes($safe_name_js); ?>', '<?php echo addslashes(htmlspecialchars($row['email'], ENT_QUOTES)); ?>')">
                                            <i class="fas fa-key"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="icon-action-btn delete" title="Delete Customer"
                                            onclick="openDeleteModal(<?php echo (int) $row['id']; ?>, '<?php echo addslashes($safe_name_js); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="cust-pagination">
                    <nav class="cust-pagination-nav" aria-label="Customers pagination">
                        <?php if ($page > 1): ?>
                            <a class="cust-page-btn" href="<?php echo htmlspecialchars(customers_page_url($page - 1)); ?>">
                                <i class="fas fa-chevron-left"></i> <span class="cust-page-btn-label">Previous</span>
                            </a>
                        <?php else: ?>
                            <span class="cust-page-btn disabled" aria-disabled="true">
                                <i class="fas fa-chevron-left"></i> <span class="cust-page-btn-label">Previous</span>
                            </span>
                        <?php endif; ?>

                        <div class="cust-page-numbers">
                            <?php foreach ($page_numbers as $pn): ?>
                                <?php if ($pn === '...'): ?>
                                    <span class="cust-page-ellipsis">&hellip;</span>
                                <?php elseif ((int) $pn === $page): ?>
                                    <span class="cust-page-num active" aria-current="page"><?php echo (int) $pn; ?></span>
                                <?php else: ?>
                                    <a class="cust-page-num" href="<?php echo htmlspecialchars(customers_page_url((int) $pn)); ?>"><?php echo (int) $pn; ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($page < $total_pages): ?>
                            <a class="cust-page-btn" href="<?php echo htmlspecialchars(customers_page_url($page + 1)); ?>">
                                <span class="cust-page-btn-label">Next</span> <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="cust-page-btn disabled" aria-disabled="true">
                                <span class="cust-page-btn-label">Next</span> <i class="fas fa-chevron-right"></i>
                            </span>
                        <?php endif; ?>
                    </nav>
            </div>
            <?php else: ?>
                <div class="empty-state-box">
                    <i class="fas fa-user-slash"></i>
                    <p class="main">No customers found</p>
                    <p class="sub">Try changing your search or filters.</p>
                    <?php if ($has_active_filters): ?>
                        <a href="customers.php#customers-section" class="btn-reset">Reset Filters</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            </div><!-- /#customers-live -->
        </div>
        </div><!-- /#panel-customers -->

    </div>

    <!-- Delete confirmation modal — one shared form submitting through the
         EXACT existing POST handler (csrf_token + del), populated per-row
         by JS instead of one native confirm() per row. -->
    <div class="cust-modal" id="deleteCustomerModal">
        <div class="modal-content">
            <h3><i class="fas fa-triangle-exclamation"></i> Delete Customer?</h3>
            <p>Are you sure you want to remove <strong id="deleteCustomerName"></strong>? This action cannot be undone.</p>
            <form method="POST" action="customers.php#customers-section" id="deleteCustomerForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="del" id="deleteCustomerId" value="">
                <div class="cust-modal-actions">
                    <button type="button" class="cust-modal-btn btn-cancel-delete" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="cust-modal-btn btn-confirm-delete"><i class="fas fa-trash"></i> Delete Customer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- New Customer: submits through fetch() to this page's own add_customer handler (JSON), no navigation. -->
    <div class="cust-modal" id="addCustomerModal" role="dialog" aria-modal="true" aria-labelledby="addCustTitle">
        <div class="modal-content">
            <h3 id="addCustTitle" style="color: #16a34a;"><i class="fas fa-user-plus"></i> New Customer</h3>
            <form method="POST" action="customers.php" id="addCustomerForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="add_customer" value="1">
                <label class="cf-label" for="ac-name">Full name</label>
                <input class="cf" type="text" name="name" id="ac-name" required maxlength="255" placeholder="e.g. Juan Dela Cruz" autocomplete="off">
                <label class="cf-label" for="ac-email">Email</label>
                <input class="cf" type="email" name="email" id="ac-email" required maxlength="255" placeholder="name@example.com" autocomplete="off">
                <label class="cf-label" for="ac-phone">Contact number</label>
                <input class="cf" type="text" name="phone" id="ac-phone" required maxlength="50" placeholder="09XXXXXXXXX" autocomplete="off">
                <div class="cf-row">
                    <div>
                        <label class="cf-label" for="ac-gender">Gender</label>
                        <select class="cf" name="gender" id="ac-gender">
                            <option value="">Not specified</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div>
                        <label class="cf-label" for="ac-age">Age</label>
                        <input class="cf" type="number" name="age" id="ac-age" min="0" max="120" placeholder="Optional">
                    </div>
                </div>
                <label class="cf-label" for="ac-address">Address</label>
                <textarea class="cf" name="address" id="ac-address" rows="2" maxlength="500" placeholder="Optional"></textarea>
                <p id="addCustomerError" class="cf-error" role="alert" hidden></p>
                <div class="cust-modal-actions">
                    <button type="button" class="cust-modal-btn btn-cancel-delete" id="addCustomerCancel">Cancel</button>
                    <button type="submit" class="cust-modal-btn btn-save-customer"><i class="fas fa-check"></i> Save Customer</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Create Order confirmation — a quick "is this the right customer?"
         check before jumping to the dedicated order-creation page (which
         does the actual work; this modal only navigates, it submits
         nothing itself). -->
    <div class="cust-modal" id="createOrderModal">
        <div class="modal-content">
            <h3><i class="fas fa-cart-plus" style="color:#7c3aed;"></i> Create Order for Customer</h3>

            <div class="aco-confirm-customer">
                <div class="avatar"><i class="fas fa-user"></i></div>
                <div>
                    <div class="name" id="createOrderName"></div>
                    <div class="cid" id="createOrderCid"></div>
                    <div class="contact">Contact: <strong id="createOrderPhone"></strong></div>
                </div>
            </div>

            <p>You'll be taken to the order creation screen to add products, fulfillment, and payment details on their behalf.</p>

            <div class="cust-modal-actions">
                <button type="button" class="cust-modal-btn btn-cancel-delete" onclick="closeCreateOrderModal()">Cancel</button>
                <a id="createOrderContinueLink" href="#" class="cust-modal-btn btn-confirm-delete" style="background:#7c3aed;border-color:#7c3aed;">Continue <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
    </div>

    <!-- Reset Customer Password — Admin-only. In-person identity verification is
         assumed to have already happened; this modal is the actual reset step.
         Submits through fetch() to this page's own reset_customer_password handler. -->
    <div class="cust-modal" id="resetPasswordModal" role="dialog" aria-modal="true" aria-labelledby="resetPassTitle">
        <div class="modal-content">
            <h3 id="resetPassTitle" style="color:#b45309;"><i class="fas fa-key"></i> Reset Customer Password</h3>

            <div class="aco-confirm-customer">
                <div class="avatar" style="background:#fffbeb;color:#b45309;"><i class="fas fa-user"></i></div>
                <div>
                    <div class="name" id="resetPassName"></div>
                    <div class="contact">Email: <strong id="resetPassEmail"></strong></div>
                </div>
            </div>

            <form method="POST" action="customers.php" id="resetPasswordForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="reset_customer_password" value="1">
                <input type="hidden" name="customer_id" id="resetPassCustomerId" value="">

                <label class="cf-label" for="rp-new-password">New Password</label>
                <input class="cf" type="password" name="new_password" id="rp-new-password" required minlength="8" autocomplete="new-password">

                <label class="cf-label" for="rp-confirm-password">Confirm New Password</label>
                <input class="cf" type="password" name="confirm_new_password" id="rp-confirm-password" required minlength="8" autocomplete="new-password">

                <p style="margin: 4px 0 16px; padding: 10px 12px; border-radius: 8px; background: #fffbeb; color: #92400e; font-size: 0.85rem; font-weight: 600; border: 1px solid #fde68a;">
                    <i class="fas fa-triangle-exclamation"></i> This action will change the customer's account password.
                </p>
                <p id="resetPasswordError" class="cf-error" role="alert" hidden></p>

                <div class="cust-modal-actions">
                    <button type="button" class="cust-modal-btn btn-cancel-delete" onclick="closeResetPasswordModal()">Cancel</button>
                    <button type="submit" class="cust-modal-btn btn-confirm-delete" style="background:#b45309;border-color:#b45309;"><i class="fas fa-key"></i> Reset Password</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openDeleteModal(id, name) {
            document.getElementById('deleteCustomerId').value = id;
            document.getElementById('deleteCustomerName').textContent = name;
            document.getElementById('deleteCustomerModal').classList.add('active');
        }
        function closeDeleteModal() {
            document.getElementById('deleteCustomerModal').classList.remove('active');
        }
        function openCreateOrderModal(id, name, phone) {
            document.getElementById('createOrderName').textContent = name;
            document.getElementById('createOrderCid').textContent = 'Customer ID: ' + id;
            document.getElementById('createOrderPhone').textContent = phone;
            document.getElementById('createOrderContinueLink').href = '<?php echo BASE_URL; ?>/modules/admin/create_customer_order.php?customer_id=' + id;
            document.getElementById('createOrderModal').classList.add('active');
        }
        function closeCreateOrderModal() {
            document.getElementById('createOrderModal').classList.remove('active');
        }
        function openResetPasswordModal(id, name, email) {
            var form = document.getElementById('resetPasswordForm');
            form.reset();
            document.getElementById('resetPasswordError').hidden = true;
            document.getElementById('resetPassName').textContent = name;
            document.getElementById('resetPassEmail').textContent = email;
            document.getElementById('resetPassCustomerId').value = id;
            document.getElementById('resetPasswordModal').classList.add('active');
            setTimeout(function () { document.getElementById('rp-new-password').focus(); }, 30);
        }
        function closeResetPasswordModal() {
            document.getElementById('resetPasswordModal').classList.remove('active');
        }
        window.addEventListener('click', function (e) {
            if (e.target.id === 'deleteCustomerModal') closeDeleteModal();
            if (e.target.id === 'createOrderModal') closeCreateOrderModal();
            if (e.target.id === 'resetPasswordModal') closeResetPasswordModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeDeleteModal(); closeCreateOrderModal(); closeResetPasswordModal(); }
        });

        // Customers | Carts tabs. Without JS both panels simply show one after the other.
        (function () {
            var tabs = document.querySelectorAll('.cust-tab');
            var panels = document.querySelectorAll('.cust-panel');
            if (!tabs.length || !panels.length) return;

            var CART_HASHES = ['#carts', '#live-carts-section'];

            function show(name) {
                panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
                tabs.forEach(function (t) {
                    var on = t.getAttribute('data-tab') === name;
                    t.classList.toggle('is-active', on);
                    if (on) t.setAttribute('aria-current', 'page'); else t.removeAttribute('aria-current');
                });
            }
            function fromHash() {
                var h = location.hash;
                show(CART_HASHES.indexOf(h) !== -1 ? 'carts' : 'customers');
                if (h && h !== '#carts' && CART_HASHES.indexOf(h) !== -1) {
                    var target = document.querySelector(h);
                    if (target) target.scrollIntoView();
                }
            }

            tabs.forEach(function (t) {
                t.addEventListener('click', function (e) {
                    e.preventDefault(); // no jump: switching tabs never moves the page
                    show(t.getAttribute('data-tab'));
                    if (history.replaceState) history.replaceState(null, '', t.getAttribute('href'));
                });
            });
            window.addEventListener('hashchange', fromHash);
            fromHash();
        })();
    </script>
    <script>
        // No-refresh customer management:
        //   search / filters / pagination, edit, delete and "Remind" all use fetch(); the answer updates only
        //   the affected rows, the counters and the pagination. Rows are keyed by data-customer-id, so a
        //   customer can never appear twice. ("Create Order" intentionally opens its own wizard page.)
        (function () {
            if (!window.LiveUI) return;

            var section = document.getElementById('customers-section');
            var live = document.getElementById('customers-live');
            var editPanel = document.getElementById('customer-edit-panel');
            var toolbar = section && section.querySelector('form.cust-toolbar');
            if (!section || !live || !toolbar) return;

            var rowBusy = function (tr) { return tr.classList.contains('liveui-row-busy'); };

            // The list URL that is currently on screen (filters + page), never carrying ?edit=.
            function listUrl() {
                var u = new URL(location.href);
                u.searchParams.delete('edit');
                return u.pathname + (u.search || '');
            }

            // ---- apply a freshly fetched page to the visible one --------------------------------
            function applyDoc(doc) {
                doc.querySelectorAll('[data-stat]').forEach(function (el) {
                    LiveUI.setText(document.querySelector('[data-stat="' + el.getAttribute('data-stat') + '"]'), el.textContent);
                });
                // Customers / Carts tab counts (only the tab contents change, so the active tab is kept)
                ['customers', 'carts'].forEach(function (name) {
                    LiveUI.setHTML(document.querySelector('.cust-tab[data-tab="' + name + '"]'), doc.querySelector('.cust-tab[data-tab="' + name + '"]'));
                });

                var fresh = doc.getElementById('customers-live');
                if (!fresh) return;
                var curBody = live.querySelector('.customer-table-wrapper tbody');
                var frBody = fresh.querySelector('.customer-table-wrapper tbody');
                if (curBody && frBody) {
                    LiveUI.syncKeyed(curBody, frBody, 'data-customer-id', { skip: rowBusy });
                    LiveUI.setHTML(live.querySelector('.result-count'), fresh.querySelector('.result-count'));
                    LiveUI.setHTML(live.querySelector('.cust-pagination'), fresh.querySelector('.cust-pagination'));
                } else {
                    LiveUI.setHTML(live, fresh);           // "No customers found" <-> table
                }
            }

            function refresh() {
                return LiveUI.fetchDoc(listUrl()).then(applyDoc);
            }

            // ---- search / filters / pagination --------------------------------------------------
            var loading = null;
            function load(url, push) {
                if (loading) loading.abort();
                var ctl = new AbortController();
                loading = ctl;
                live.classList.add('liveui-row-busy');
                LiveUI.fetchDoc(url, { signal: ctl.signal }).then(function (doc) {
                    applyDoc(doc);
                    if (push) history.pushState({ custList: true }, '', url);
                    editPanel.innerHTML = '';              // a new list closes any open edit form
                }).catch(function (err) {
                    if (err && err.code === 'aborted') return;
                    LiveUI.toast(err.message || 'Could not load the customer list.', 'error');
                }).then(function () {
                    if (loading === ctl) { loading = null; live.classList.remove('liveui-row-busy'); }
                });
            }

            function urlFromToolbar() {
                var params = new URLSearchParams();
                new FormData(toolbar).forEach(function (v, k) { if (String(v).trim() !== '') params.append(k, v); });
                var qs = params.toString();
                return location.pathname + (qs ? '?' + qs : '');   // no `page`: a new search starts at page 1
            }

            toolbar.addEventListener('submit', function (e) { e.preventDefault(); load(urlFromToolbar(), true); });
            var searchTimer = null;
            var searchBox = toolbar.querySelector('[name="search"]');
            if (searchBox) searchBox.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { load(urlFromToolbar(), true); }, 400);
            });
            toolbar.querySelectorAll('select').forEach(function (sel) {
                sel.addEventListener('change', function () { load(urlFromToolbar(), true); });
            });

            document.addEventListener('click', function (e) {
                if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                var a = e.target.closest ? e.target.closest('a') : null;
                if (!a) return;

                if (a.matches('.btn-reset') && section.contains(a)) {                 // Reset filters
                    e.preventDefault();
                    toolbar.reset();
                    toolbar.querySelectorAll('input[type="text"]').forEach(function (i) { i.value = ''; });
                    toolbar.querySelectorAll('select').forEach(function (s) { s.value = ''; });
                    load(location.pathname, true);
                } else if (a.matches('.cust-page-btn, .cust-page-num') && live.contains(a) && a.getAttribute('href')) {   // pagination
                    e.preventDefault();
                    var u = new URL(a.href, location.href);
                    load(u.pathname + u.search, true);
                } else if (a.matches('a.icon-action-btn.edit') && live.contains(a)) {                                   // Edit
                    e.preventDefault();
                    openEdit(a.href);
                } else if (editPanel.contains(a) && /customers\.php#customers-section$/.test(a.getAttribute('href') || '')) {   // Edit form "Cancel"
                    e.preventDefault();
                    editPanel.innerHTML = '';
                }
            });

            window.addEventListener('popstate', function () {
                var p = new URLSearchParams(location.search);
                ['search', 'gender', 'age_group', 'status'].forEach(function (n) {
                    var f = toolbar.querySelector('[name="' + n + '"]');
                    if (f) f.value = p.get(n) || '';
                });
                load(listUrl(), false);
            });

            // ---- edit ---------------------------------------------------------------------------
            function openEdit(href) {
                LiveUI.fetchDoc(href).then(function (doc) {
                    var fresh = doc.getElementById('customer-edit-panel');
                    if (!fresh || !fresh.children.length) throw new Error('That customer could not be opened for editing.');
                    editPanel.innerHTML = fresh.innerHTML;
                    editPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    var first = editPanel.querySelector('input[name="name"]');
                    if (first) first.focus();
                }).catch(function (err) { LiveUI.toast(err.message, 'error'); });
            }

            editPanel.addEventListener('submit', function (e) {
                var form = e.target;
                if (!form.matches || !form.matches('form')) return;
                e.preventDefault();
                var idField = form.querySelector('[name="id"]');
                var id = idField ? idField.value : '';
                var release = LiveUI.lock('customer-edit-' + id);
                if (!release) return;
                var btn = e.submitter || form.querySelector('button[type="submit"]');
                var done = LiveUI.busy(btn, 'Saving…');
                var row = document.querySelector('tr[data-customer-id="' + id + '"]');
                if (row) row.classList.add('liveui-row-busy');

                LiveUI.post('customers.php', LiveUI.formData(form, btn)).then(function (res) {
                    LiveUI.toast(res.message, 'success');
                    editPanel.innerHTML = '';
                    return true;
                }).catch(function (err) {
                    LiveUI.toast(err.message, 'error');       // the form stays open so nothing typed is lost
                    return false;
                }).then(function (ok) {
                    done();
                    if (row) row.classList.remove('liveui-row-busy');
                    release();
                    if (ok) return refresh().catch(function (err) { LiveUI.toast(err.message, 'error'); });
                });
            });

            // ---- delete (the existing confirmation modal) -----------------------------------------
            var delForm = document.getElementById('deleteCustomerForm');
            if (delForm) delForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var id = document.getElementById('deleteCustomerId').value;
                var release = LiveUI.lock('customer-del-' + id);
                if (!release) return;
                var btn = delForm.querySelector('.btn-confirm-delete');
                var done = LiveUI.busy(btn, 'Deleting…');
                var row = document.querySelector('tr[data-customer-id="' + id + '"]');
                if (row) row.classList.add('liveui-row-busy');

                LiveUI.post('customers.php', LiveUI.formData(delForm, btn)).then(function (res) {
                    LiveUI.toast(res.message, 'success');
                    closeDeleteModal();
                    if (row) LiveUI.removeNode(row);
                    return true;
                }).catch(function (err) {
                    LiveUI.toast(err.message, 'error');
                    if (row) row.classList.remove('liveui-row-busy');
                    if (err.data && err.data.customer_id) { closeDeleteModal(); return true; }   // already gone: just resync
                    return false;
                }).then(function (ok) {
                    done();
                    release();
                    if (ok) return refresh().catch(function (err) { LiveUI.toast(err.message, 'error'); });   // counts, next row into the page, pagination
                });
            });

            // ---- New Customer (modal + fetch, no navigation) ------------------------------------------
            (function () {
                var openBtn = document.getElementById('btn-new-customer');
                var modal = document.getElementById('addCustomerModal');
                var form = document.getElementById('addCustomerForm');
                if (!openBtn || !modal || !form) return;
                var errBox = document.getElementById('addCustomerError');

                function openAdd() {
                    errBox.hidden = true;
                    modal.classList.add('active');
                    var first = document.getElementById('ac-name');
                    if (first) setTimeout(function () { first.focus(); }, 30);
                }
                function closeAdd() { modal.classList.remove('active'); }

                openBtn.addEventListener('click', function (e) {
                    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
                    e.preventDefault();                               // the href stays as the no-JavaScript fallback
                    openAdd();
                });
                document.getElementById('addCustomerCancel').addEventListener('click', closeAdd);
                modal.addEventListener('click', function (e) { if (e.target === modal) closeAdd(); });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('active')) closeAdd(); });

                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var release = LiveUI.lock('customer-add');
                    if (!release) return;                             // already saving: ignore the double click
                    var btn = e.submitter || form.querySelector('button[type="submit"]');
                    var done = LiveUI.busy(btn, 'Saving…');
                    errBox.hidden = true;

                    LiveUI.post('customers.php', LiveUI.formData(form, btn)).then(function (res) {
                        LiveUI.toast(res.message, 'success');
                        form.reset();
                        closeAdd();
                        return true;
                    }).catch(function (err) {
                        errBox.textContent = err.message;             // the modal stays open so nothing typed is lost
                        errBox.hidden = false;
                        LiveUI.toast(err.message, 'error');
                        return false;
                    }).then(function (ok) {
                        done();
                        release();
                        // read the list once so the new row, the counters and the pagination line up (rows are keyed: no duplicates)
                        if (ok) return refresh().catch(function (err) { LiveUI.toast(err.message, 'error'); });
                    });
                });
            })();

            // ---- Reset Customer Password (Admin-only modal + fetch, no navigation) ------------------
            (function () {
                var form = document.getElementById('resetPasswordForm');
                if (!form) return; // not rendered for non-admin roles
                var errBox = document.getElementById('resetPasswordError');

                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var newPass = document.getElementById('rp-new-password').value;
                    var confirmPass = document.getElementById('rp-confirm-password').value;
                    if (newPass !== confirmPass) {
                        errBox.textContent = 'New password and confirmation do not match.';
                        errBox.hidden = false;
                        return;
                    }
                    if (!confirm("Are you sure you want to reset this customer's password?")) return;

                    var release = LiveUI.lock('customer-reset-password');
                    if (!release) return;
                    var btn = e.submitter || form.querySelector('button[type="submit"]');
                    var done = LiveUI.busy(btn, 'Resetting…');
                    errBox.hidden = true;

                    LiveUI.post('customers.php', LiveUI.formData(form, btn)).then(function (res) {
                        LiveUI.toast(res.message, 'success');
                        closeResetPasswordModal();
                        form.reset();
                    }).catch(function (err) {
                        errBox.textContent = err.message;   // the modal stays open so nothing typed is lost
                        errBox.hidden = false;
                        LiveUI.toast(err.message, 'error');
                    }).then(function () {
                        done();
                        release();
                    });
                });
            })();

            // ---- pick up customers registered / changed elsewhere ----------------------------------
            // A tiny probe every ~5s; the list is re-read only when the customers fingerprint changed.
            LiveUI.watch('customers', function () {
                if (loading || document.querySelector('.cust-modal.active')) return;
                if (searchBox && document.activeElement === searchBox && searchTimer) return;   // still typing
                return refresh();
            }, { fallbackMs: 30000 });
        })();
    </script>
</body>
</html>
