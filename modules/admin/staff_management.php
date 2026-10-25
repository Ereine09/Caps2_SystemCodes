<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/auth_rules_helper.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../../app/helpers/account_recovery_helper.php';
require_once __DIR__ . '/../../app/helpers/ebike_helper.php';

// JWT authentication and role check
$token = getJWTFromCookie();
if (!$token) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$payload = verifyJWT($token);
if (!$payload) {
    clearJWTCookie();
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$username = $payload['username'];
$user_id = (int) ($payload['user_id'] ?? 0);
$role = strtolower(trim($payload['role'] ?? 'staff'));
if ($role !== 'admin') {
    header("Location: " . BASE_URL . "/modules/staff/dashboard.php?error=access_denied");
    exit();
}

$admin = $username;
$success_message = '';
$error_message = '';
$show_add_form = isset($_GET['add_user']);

if (isset($_GET['success']) && $_GET['success'] === 'user_added') {
    $success_message = 'New user added successfully.';
}
if (isset($_GET['error']) && $_GET['error'] === 'cannot_delete_self') {
    $error_message = 'You cannot remove your own admin access.';
}
if (isset($_GET['error']) && $_GET['error'] === 'delete_failed') {
    $error_message = 'Could not delete that account. It may still be linked to other records.';
}

// --- ACCOUNT RECOVERY ("Recover Account" on every row except your own) ---
// For an admin, staff or rider who forgot their password or is locked out. Result messages
// come back through the redirect (PRG), so a refresh never re-sends an email.
$recover_notices = [
    'link_sent'    => ['ok', 'A password reset link was emailed to %s. It expires in ' . ACCOUNT_RESET_TTL_MINUTES . ' minutes.'],
    'password_set' => ['ok', 'Temporary password set for %s. Give it to them in person; they are signed out of every device and can log in with it now.'],
    'unlocked'     => ['ok', '%s is unlocked and can try logging in again.'],
    'link_recent'  => ['err', 'A reset link was sent to %s less than a minute ago. Wait a moment before sending another.'],
    'mail_failed'  => ['err', 'The reset email to %s could not be sent. Check the email settings, or set a temporary password instead.'],
    'no_email'     => ['err', '%s has no email address. Set a temporary password instead.'],
];
if (isset($recover_notices[$_GET['recover_notice'] ?? ''])) {
    [$kind, $text] = $recover_notices[$_GET['recover_notice']];
    $who = user_recovery_find($conn, (int) ($_GET['uid'] ?? 0));
    $msg = sprintf($text, $who ? $who['username'] : 'the account');
    if ($kind === 'ok') { $success_message = $msg; } else { $error_message = $msg; }
}
$recover_error = '';
$recover_user_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recover_account'])) {
    csrf_require();
    $recover_user_id = (int) ($_POST['recover_user_id'] ?? 0);
    $mode = (string) ($_POST['recover_mode'] ?? '');
    $target = user_recovery_find($conn, $recover_user_id);
    $return_role = in_array($_POST['return_role'] ?? '', ['admin', 'staff', 'rider'], true) ? $_POST['return_role'] : '';
    $back = static function (string $code) use ($recover_user_id, $return_role): void {
        header('Location: staff_management.php?' . ($return_role !== '' ? 'role_filter=' . $return_role . '&' : '') . 'recover_notice=' . $code . '&uid=' . $recover_user_id);
        exit();
    };
    $log = static function (string $details) use ($conn, $user_id): void {
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Account Recovery', ?)");
        $stmt->bind_param('is', $user_id, $details);
        $stmt->execute();
        $stmt->close();
    };

    if (!$target) {
        $error_message = 'That account was not found.';
    } elseif ((int) $target['id'] === $user_id) {
        // Your own password is changed from your own account, not from here.
        $error_message = 'You cannot recover your own account from here.';
    } elseif ($mode === 'link') {
        if (!filter_var((string) $target['email'], FILTER_VALIDATE_EMAIL)) {
            $back('no_email');
        }
        if (recovery_recently_sent($target['reset_expiry'])) {
            $back('link_recent');
        }
        user_recovery_unlock($conn, (int) $target['id']);
        if (!user_recovery_send_link($conn, $target, true)) {
            $back('mail_failed');
        }
        $log("Sent a password reset link to {$target['role']} {$target['username']}");
        $back('link_sent');
    } elseif ($mode === 'temp') {
        $temp = (string) ($_POST['temp_password'] ?? '');
        $recover_error = auth_password_error($temp, [$target['username'], $target['email'], $target['first_name'], $target['last_name']]) ?? '';
        if ($recover_error === '' && !hash_equals($temp, (string) ($_POST['temp_password_confirm'] ?? ''))) {
            $recover_error = 'The two passwords do not match.';
        }
        if ($recover_error === '') {
            if (!user_recovery_set_password($conn, (int) $target['id'], $temp)) {
                $recover_error = 'Could not save the new password. Please try again.';
            } else {
                $log("Set a temporary password for {$target['role']} {$target['username']}");
                $back('password_set');
            }
        }
    } elseif ($mode === 'unlock') {
        user_recovery_unlock($conn, (int) $target['id']);
        $log("Unlocked {$target['role']} account {$target['username']}");
        $back('unlocked');
    } else {
        $error_message = 'Choose how to recover the account.';
    }
}

// --- ADD USER LOGIC ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_user_submit'])) {
    csrf_require();
    $show_add_form = true;
    $new_username = trim($_POST['username'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password_raw = $_POST['password'] ?? '';
    $new_role = strtolower(trim($_POST['role'] ?? 'staff'));

    if (!in_array($new_role, ['admin', 'staff', 'rider'], true)) {
        $new_role = 'staff';
    }
    // Rider accounts also get their delivery profile (riders row): optional plate number.
    // The shop delivers by e-bike only, so every rider's vehicle is an e-bike.
    $vehicle_type = 'E-bike';
    $plate_number = strtoupper(trim((string) ($_POST['plate_number'] ?? '')));
    $rider_ebike_id = (int) ($_POST['ebike_id'] ?? 0); // 0 = no shop e-bike

    if ($new_username === '' || $first_name === '' || $last_name === '' || $email === '' || $password_raw === '') {
        $error_message = 'All user fields are required.';
    } elseif ($new_role === 'rider' && ($plate_number !== '' && !preg_match('/^[A-Z0-9 -]{2,20}$/', $plate_number))) {
        $error_message = 'Plate number can only use letters, numbers, spaces and dashes (up to 20).';
    } elseif (($rule_error = auth_username_error($new_username)
            ?? auth_name_error($first_name, 'First name')
            ?? auth_name_error($last_name, 'Last name')
            ?? auth_email_error($email)
            ?? auth_password_error((string) $password_raw, [$new_username, $first_name, $last_name, $email])) !== null) {
        $error_message = $rule_error;
    } else {
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $checkStmt->bind_param("ss", $new_username, $email);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            $error_message = 'Username or email already exists.';
        } else {
            $password = password_hash($password_raw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $new_username, $first_name, $last_name, $email, $password, $new_role);

            //add new user (+ the rider profile for riders, both or neither), then log the action in activity_logs
            ebike_usage_ensure_schema($conn); // DDL before the transaction (it would commit it)
            $conn->begin_transaction();
            $saved = false;
            try {
                $saved = $stmt->execute();
                if ($saved && $new_role === 'rider') {
                    $new_user_id = (int) $conn->insert_id;
                    $plate = $plate_number !== '' ? $plate_number : null;
                    $rider_stmt = $conn->prepare("INSERT INTO riders (user_id, vehicle_type, plate_number, is_on_duty) VALUES (?, ?, ?, 0)");
                    $rider_stmt->bind_param('iss', $new_user_id, $vehicle_type, $plate);
                    $rider_stmt->execute();
                    $rider_stmt->close();
                    if ($rider_ebike_id > 0) {
                        ebike_set_rider_bike($conn, $new_user_id, $rider_ebike_id); // throws if another rider took it meanwhile
                    }
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                $saved = false;
                error_log('Staff account creation failed: ' . $e->getMessage());
                $ebike_taken = $rider_ebike_id > 0 && str_contains($e->getMessage(), 'e-bike');
            }
            if ($saved) {
                $details = "Added new " . ucfirst($new_role) . " account: $new_username"
                    . ($new_role === 'rider' ? " ($vehicle_type" . ($plate_number !== '' ? ", plate $plate_number" : '')
                        . ($rider_ebike_id > 0 ? ', e-bike ' . ($conn->query('SELECT bike_code FROM tbl_ebikes WHERE id = ' . $rider_ebike_id)->fetch_assoc()['bike_code'] ?? '#' . $rider_ebike_id) : '') . ')' : '');
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Manage Staff', ?)");
                $log_stmt->bind_param("is", $user_id, $details);
                $log_stmt->execute();

                header('Location: staff_management.php?success=user_added');
                exit();
            } else {
                $error_message = !empty($ebike_taken)
                    ? 'That e-bike was just taken by another rider. Pick another one.'
                    : 'Could not add the user. Please try again.';
            }
        }
    }
}

// --- DELETE STAFF LOGIC ---
if(isset($_POST['del_staff'])){
    csrf_require();
    $id = (int) $_POST['del_staff'];

    // An admin must never be able to delete their own account out from under themselves.
    if ($id === $user_id) {
        header('Location: staff_management.php?error=cannot_delete_self');
        exit();
    }

    // Get the username for the logs
    $res = mysqli_query($conn, "SELECT username FROM users WHERE id='$id'");
    $staff = mysqli_fetch_assoc($res);
    $staff_name = $staff['username'] ?? 'Unknown User';

    // Several tables reference users(id) with a plain (RESTRICT) foreign key and
    // no ON DELETE CASCADE, so the child rows have to be cleared first or the
    // DELETE below fails with a foreign key constraint error. Tables that already
    // cascade or SET NULL (riders, tbl_rider_remittances, tbl_delivery, orders)
    // are left for the database to handle. Wrapped in a transaction so a partial
    // failure doesn't leave the account half-deleted.
    $conn->begin_transaction();
    try {
        foreach (['notifications', 'activity_logs', 'loyalty_transactions'] as $child_table) {
            $del = $conn->prepare("DELETE FROM {$child_table} WHERE user_id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
        }

        $del_user = $conn->prepare("DELETE FROM users WHERE id = ?");
        $del_user->bind_param("i", $id);
        $del_user->execute();
        $del_user->close();

        // Log the action against the current admin (this row is written after the
        // deleted user's own activity_logs were cleared above, so it survives).
        $details = "Deleted staff account: $staff_name";
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Manage Staff', ?)");
        $stmt->bind_param("is", $user_id, $details);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        error_log('Staff account deletion failed for user ' . $id . ': ' . $e->getMessage());
        header('Location: staff_management.php?error=delete_failed');
        exit();
    }

    header('location: staff_management.php');
    exit();
}

// --- Summary stats (real counts straight from the users table — nothing
// hardcoded). "Total Accounts" always matches exactly what the table below
// lists; the per-role tiles are a breakdown of that same set. ---
$total_accounts = (int) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM users"))['c'] ?? 0);
$role_counts = ['admin' => 0, 'staff' => 0, 'rider' => 0];
// E-bikes nobody owns yet (switched on, not claimed or out with a rider) for the new-rider form.
$free_ebikes = ebike_rider_choices($conn, 0);
$role_count_res = mysqli_query($conn, "SELECT role, COUNT(*) AS c FROM users GROUP BY role");
if ($role_count_res) {
    while ($rc = mysqli_fetch_assoc($role_count_res)) {
        $r = strtolower(trim($rc['role'] ?? ''));
        if (isset($role_counts[$r])) {
            $role_counts[$r] = (int) $rc['c'];
        }
    }
}

// --- Search / role filter for the accounts table (read-only; same GET-
// param + prepared-statement pattern already used on
// modules/customers/customers.php — reused, not reinvented). ---
$search = trim($_GET['search'] ?? '');
$role_filter = strtolower(trim($_GET['role_filter'] ?? ''));
if (!in_array($role_filter, ['admin', 'staff', 'rider'], true)) {
    $role_filter = '';
}

$where_parts = [];
$where_params = [];
$where_types = '';
if ($search !== '') {
    $where_parts[] = "(username LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $like = "%$search%";
    array_push($where_params, $like, $like, $like, $like);
    $where_types .= 'ssss';
}
if ($role_filter !== '') {
    $where_parts[] = "role = ?";
    $where_params[] = $role_filter;
    $where_types .= 's';
}
$where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

$list_stmt = $conn->prepare("SELECT * FROM users $where_sql ORDER BY id ASC");
if ($where_params) {
    $list_stmt->bind_param($where_types, ...$where_params);
}
$list_stmt->execute();
$result = $list_stmt->get_result();

$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending' AND admin_viewed_at IS NULL"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);

/** Small helpers kept local to this page — pure presentation, no business logic. */
function staff_role_badge_class(string $role): string {
    switch ($role) {
        case 'admin': return 'role-badge role-admin';
        case 'staff': return 'role-badge role-staff';
        case 'rider': return 'role-badge role-rider';
        default: return 'role-badge role-staff';
    }
}
function staff_role_icon(string $role): string {
    switch ($role) {
        case 'admin': return 'fas fa-user-shield';
        case 'staff': return 'fas fa-user-tie';
        case 'rider': return 'fas fa-motorcycle';
        default: return 'fas fa-user';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <script>try{var p=location.pathname.toLowerCase(),k=p.indexOf('/customer/')!==-1&&p.indexOf('/modules/')===-1?'customerTheme':p.indexOf('/rider/')!==-1?'riderTheme':'dps_admin_theme';var t=localStorage.getItem(k)||localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management - DPS Admin</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="alternate icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/ui-kit.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/ui-kit.css') ?: time(); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/css/admin_style.css') ?: time(); ?>">
    <style>
        /* Summary tiles — same .stats-grid/.stat-card/.stat-icon shape already
           used on Reviews/Delivery/etc. (already Dark-Mode themed centrally in
           admin_style.css); only a new "purple" icon tint is introduced here
           for the Rider tile/badge, since no such role tint existed before. */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin: 22px 0 26px; }
        .stat-card { background: var(--surface); padding: 18px 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid var(--border); display: flex; align-items: center; gap: 14px; }
        .stat-icon { width: 46px; height: 46px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
        .icon-blue { background: var(--info-soft); color: var(--info); }
        .icon-red { background: var(--danger-soft); color: var(--danger); }
        .icon-green { background: var(--success-soft); color: var(--success); }
        .icon-purple { background: var(--primary-soft); color: var(--accent); }
        .stat-info h3 { margin: 0; font-size: 1.5rem; font-weight: 800; color: var(--text); line-height: 1.1; }
        .stat-info p { margin: 2px 0 0; font-size: 0.8rem; color: var(--muted); font-weight: 600; }

        /* Toolbar (search + role filter + Add New User) */
        .toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 20px; }
        .toolbar form.filter-form { display: flex; flex-wrap: wrap; gap: 10px; flex: 1; }
        .toolbar .search-box { position: relative; flex: 1; min-width: 200px; }
        .toolbar .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 0.85rem; }
        .toolbar .search-box input { width: 100%; padding: 10px 12px 10px 34px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.9rem; }
        .toolbar select { padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.9rem; min-width: 150px; }
        .staff-tabs { margin: -4px 0 18px; }
        .toolbar .btn-clear-filter { padding: 10px 14px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text-2); text-decoration: none; font-size: 0.85rem; font-weight: 600; white-space: nowrap; }

        /* Role badges — consistent padding/radius/weight; colors already used
           elsewhere in the system (admin=red, staff=green) kept as-is, plus a
           new purple tint for rider. All three are wired into Dark Mode via
           the existing badge-family groups in admin_style.css. */
        .role-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; text-transform: capitalize; }
        .role-admin { background: var(--danger-soft); color: var(--danger); }
        .role-staff { background: var(--success-soft); color: var(--success); }
        .role-rider { background: var(--primary-soft); color: var(--accent); }

        /* Status (derived from the existing login_attempts/lock_until lockout
           columns — real security data already in the schema, not invented). */
        .account-status { display: inline-flex; align-items: center; gap: 6px; font-size: 0.82rem; font-weight: 600; }
        .account-status.is-active { color: var(--success); }
        .account-status.is-locked { color: var(--warning); }
        .account-status i { font-size: 0.6rem; }

        /* Current-account marker replacing the old plain "No Actions" text */
        .current-account-tag { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; font-weight: 700; color: var(--muted); }
        .current-account-tag i { color: var(--muted); }
        .you-pill { background: var(--primary-soft); color: var(--link); font-size: 0.68rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; margin-left: 6px; text-transform: uppercase; letter-spacing: 0.03em; }

        .user-cell strong { display: block; }
        .user-cell small { color: var(--muted); }

        .btn-remove-access { display: inline-flex; align-items: center; gap: 6px; background: var(--danger-soft); color: var(--danger); border: 1px solid #fecaca; padding: 7px 12px; border-radius: 8px; cursor: pointer; font-size: 0.82rem; font-weight: 600; }
        .btn-remove-access:hover { background: var(--danger-soft); }
        .row-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .btn-recover { display: inline-flex; align-items: center; gap: 6px; background: var(--primary-soft); color: var(--link); border: 1px solid transparent; padding: 7px 12px; border-radius: 8px; cursor: pointer; font-size: 0.82rem; font-weight: 600; }
        .btn-recover:hover { border-color: var(--primary); }

        /* Recover rider account modal */
        .recover-box .modal-content { max-width: 480px; width: 100%; max-height: calc(100vh - 40px); overflow-y: auto; padding: 26px; border-radius: 14px; box-sizing: border-box; }
        .recover-box h3 { margin: 0 0 4px; font-size: 1.15rem; display: flex; align-items: center; gap: 10px; }
        .recover-box .modal-sub { margin: 0 0 16px; color: var(--muted); font-size: 0.88rem; }
        .recover-option { border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; }
        .recover-option h4 { margin: 0 0 4px; font-size: 0.95rem; display: flex; align-items: center; gap: 8px; }
        .recover-option p { margin: 0 0 10px; color: var(--muted); font-size: 0.84rem; line-height: 1.45; }
        .recover-option form { margin: 0; }
        .recover-option input { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.9rem; box-sizing: border-box; margin-bottom: 8px; }
        .recover-option button[type=submit] { width: 100%; padding: 10px; border-radius: 8px; border: 0; font-weight: 700; cursor: pointer; background: var(--primary); color: #fff; }
        .recover-option button[disabled] { opacity: .55; cursor: not-allowed; }
        .recover-option.is-warn { background: var(--warning-soft); border-color: transparent; }
        .recover-option.is-warn button[type=submit] { background: var(--warning); }
        .recover-error { background: var(--danger-soft); color: var(--danger); border-radius: 8px; padding: 10px 12px; font-size: 0.85rem; font-weight: 600; margin-bottom: 12px; }
        .recover-cancel { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text-2); font-weight: 600; cursor: pointer; }

        /* Password fields: show/hide eye inside the box (the rules checklist goes below the box). */
        .password-field { position: relative; }
        .password-field input { padding-right: 44px !important; }
        .recover-option .password-field { margin-bottom: 8px; }
        .recover-option .password-field input { margin-bottom: 0; }
        .pw-eye { position: absolute; top: 50%; right: 6px; transform: translateY(-50%); width: 32px; height: 32px; padding: 0; border: 0; border-radius: 6px; background: transparent; color: var(--muted); cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .pw-eye:hover { color: var(--text); background: var(--surface-2); }
        .pw-eye:focus-visible { outline: 2px solid var(--primary); outline-offset: 1px; }

        .empty-row td { text-align: center; padding: 40px 20px; color: var(--muted); }
        .empty-row i { font-size: 2rem; color: #cbd5e1; margin-bottom: 8px; display: block; }

        /* Add New User modal */
        .user-modal .modal-content { max-width: 620px; width: 100%; padding: 28px; border-radius: 14px; }
        .user-modal .modal-title-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
        .user-modal .modal-title-row h2 { margin: 0; font-size: 1.25rem; }
        .user-modal .modal-close-btn { background: none; border: none; font-size: 1.3rem; color: var(--muted); cursor: pointer; line-height: 1; padding: 4px; }
        .user-modal .modal-sub { margin: 0 0 20px; color: var(--muted); font-size: 0.88rem; }
        .user-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
        .user-form-grid .full-width { grid-column: 1 / -1; }
        .user-form-grid label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--text-2); margin-bottom: 6px; }
        .user-form-grid input, .user-form-grid select { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.9rem; box-sizing: border-box; }
        .user-modal-actions { grid-column: 1 / -1; display: flex; gap: 10px; justify-content: flex-end; margin-top: 6px; }

        /* Remove Access confirmation modal */
        .confirm-remove-box .modal-content { max-width: 420px; padding: 26px; border-radius: 14px; }
        .confirm-remove-box h3 { margin: 0 0 6px; font-size: 1.15rem; color: var(--danger); display: flex; align-items: center; gap: 10px; }
        .confirm-remove-box p { margin: 0 0 18px; color: var(--text-2); font-size: 0.9rem; line-height: 1.5; }
        .confirm-remove-box strong { color: var(--text); }
        .confirm-remove-actions { display: flex; gap: 10px; }
        .confirm-remove-actions button { flex: 1; padding: 11px; border-radius: 8px; font-weight: 700; cursor: pointer; border: 1px solid var(--border); }
        .btn-cancel-remove { background: var(--surface-2); color: var(--text-2); }
        .btn-confirm-remove { background: #dc2626; color: #fff; border-color: #dc2626; }

        /* Modal overlay shell — same fixed/centered/dark-scrim convention
           already used by other pages' confirm dialogs (e.g.
           modules/customers/reward_redemption.php's .confirm-modal). The
           overlay itself is intentionally the same dark scrim in both
           themes; only .modal-content (covered centrally in
           admin_style.css) needs to flip with Dark Mode. */
        .modal { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); z-index: 2000; align-items: center; justify-content: center; padding: 20px; }
        .modal-content { background: var(--surface); border-radius: 14px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); }

        @media (max-width: 640px) {
            .toolbar, .toolbar form.filter-form { flex-direction: column; align-items: stretch; }
            .toolbar select, .toolbar .btn-clear-filter { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../customers/sidebar.php'; ?>

    <div class="main-content">
        <div class="welcome-header">
            <h1><i class="fas fa-user-shield" style="font-size: 1.4rem; color: #4a3e94;"></i> Staff Management</h1>
            <p>Control who has access to your administrative system.</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="fas fa-users"></i></div>
                <div class="stat-info"><h3><?php echo $total_accounts; ?></h3><p>Total Accounts</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-red"><i class="fas fa-user-shield"></i></div>
                <div class="stat-info"><h3><?php echo $role_counts['admin']; ?></h3><p>Admin</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green"><i class="fas fa-user-tie"></i></div>
                <div class="stat-info"><h3><?php echo $role_counts['staff']; ?></h3><p>Staff</p></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-purple"><i class="fas fa-motorcycle"></i></div>
                <div class="stat-info"><h3><?php echo $role_counts['rider']; ?></h3><p>Rider</p></div>
            </div>
        </div>

        <?php if ($success_message !== ''): ?>
            <div style="margin-bottom: 20px; padding: 12px 15px; background: #eafaf1; color: #27ae60; border-left: 4px solid #27ae60; border-radius: 8px; font-weight: 600;">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message !== ''): ?>
            <div style="margin-bottom: 20px; padding: 12px 15px; background: #fff5f5; color: #e74c3c; border-left: 4px solid #e74c3c; border-radius: 8px; font-weight: 600;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="table-box">
            <!-- Role tabs (same role_filter GET param the old dropdown used; the search is kept). -->
            <?php
            $staff_tabs = [
                ''      => ['All Accounts', 'fas fa-users', $total_accounts],
                'admin' => ['Admins', 'fas fa-user-shield', $role_counts['admin']],
                'staff' => ['Staff', 'fas fa-user-tie', $role_counts['staff']],
                'rider' => ['Riders', 'fas fa-motorcycle', $role_counts['rider']],
            ];
            ?>
            <nav class="ui-tabs ui-tabs-fill staff-tabs" aria-label="Filter accounts by role">
                <?php foreach ($staff_tabs as $tab_role => [$tab_label, $tab_icon, $tab_count]):
                    $tab_query = http_build_query(array_filter(['role_filter' => $tab_role, 'search' => $search], 'strlen'));
                ?>
                    <a href="staff_management.php<?php echo $tab_query !== '' ? '?' . htmlspecialchars($tab_query) : ''; ?>"
                       class="<?php echo $role_filter === $tab_role ? 'active' : ''; ?>"<?php echo $role_filter === $tab_role ? ' aria-current="page"' : ''; ?>>
                        <i class="<?php echo $tab_icon; ?>"></i> <?php echo $tab_label; ?> <span class="ui-tab-count"><?php echo (int) $tab_count; ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="toolbar">
                <form method="GET" class="filter-form" action="staff_management.php">
                    <?php if ($role_filter !== ''): ?><input type="hidden" name="role_filter" value="<?php echo htmlspecialchars($role_filter); ?>"><?php endif; ?>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search <?php echo $role_filter !== '' ? htmlspecialchars($staff_tabs[$role_filter][0]) : 'users'; ?>...">
                    </div>
                    <button type="submit" class="btn-clear-filter" style="background:#3498db; color:#fff; border-color:#3498db;">Search</button>
                    <?php if ($search !== ''): ?>
                        <a href="staff_management.php<?php echo $role_filter !== '' ? '?role_filter=' . htmlspecialchars($role_filter) : ''; ?>" class="btn-clear-filter">Clear</a>
                    <?php endif; ?>
                </form>
                <a href="staff_management.php?add_user=1" class="btn-add" style="background: #3498db; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600; white-space: nowrap;" onclick="openAddUserModal(); return false;">
                    <i class="fas fa-user-plus"></i> Add New User
                </a>
            </div>

            <div style="overflow-x: auto;">
                <table class="custom-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8f9fa; border-bottom: 2px solid #eee;">
                            <th style="padding: 15px; text-align: left;">User</th>
                            <th style="padding: 15px; text-align: left;">Contact</th>
                            <th style="padding: 15px; text-align: left;">Role</th>
                            <th style="padding: 15px; text-align: left;">Status</th>
                            <th style="padding: 15px; text-align: left;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                <?php
                                $is_current_user = ($row['username'] === $username);
                                $row_role = strtolower(trim($row['role'] ?? 'staff'));
                                $full_name = trim($row['first_name'] . ' ' . $row['last_name']);
                                $is_locked = !empty($row['lock_until']) && strtotime($row['lock_until']) > time();
                                ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 15px;" class="user-cell">
                                        <strong><?php echo htmlspecialchars($row['username']); ?><?php if ($is_current_user): ?><span class="you-pill">You</span><?php endif; ?></strong>
                                        <?php if ($full_name !== ''): ?><small><?php echo htmlspecialchars($full_name); ?></small><?php endif; ?>
                                    </td>
                                    <td style="padding: 15px; font-size: 0.85rem; color: #475569;"><?php echo htmlspecialchars($row['email'] ?? '—'); ?></td>
                                    <td style="padding: 15px;">
                                        <span class="<?php echo staff_role_badge_class($row_role); ?>">
                                            <i class="<?php echo staff_role_icon($row_role); ?>"></i> <?php echo htmlspecialchars(ucfirst($row_role)); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 15px;">
                                        <?php if ($is_locked): ?>
                                            <span class="account-status is-locked"><i class="fas fa-circle"></i> Locked</span>
                                        <?php else: ?>
                                            <span class="account-status is-active"><i class="fas fa-circle"></i> Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 15px;">
                                        <?php if (!$is_current_user): ?>
                                            <div class="row-actions">
                                                <button type="button" class="btn-recover" data-recover
                                                    data-id="<?php echo (int) $row['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($row['username']); ?>"
                                                    data-email="<?php echo htmlspecialchars((string) ($row['email'] ?? '')); ?>"
                                                    data-role="<?php echo htmlspecialchars($row_role); ?>"
                                                    data-locked="<?php echo $is_locked ? '1' : '0'; ?>">
                                                    <i class="fas fa-key"></i> <?php echo $is_locked ? 'Unlock / Recover' : 'Recover Account'; ?>
                                                </button>
                                            <button type="button" class="btn-remove-access"
                                                onclick="openRemoveModal(<?php echo (int) $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['username']), ENT_QUOTES); ?>')">
                                                <i class="fas fa-user-slash"></i> Remove Access
                                            </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="current-account-tag"><i class="fas fa-lock"></i> Protected (Current Account)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr class="empty-row">
                                <td colspan="5">
                                    <i class="fas fa-user-slash"></i>
                                    No user accounts matched your search/filter.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add New User modal — same fields/backend as before, now wrapped in a
         modal shell instead of an inline reveal. Falls back to a normal
         GET/POST reload (?add_user=1) if JS is unavailable; PHP still decides
         whether it's open ($show_add_form), so a validation error re-opens it
         with the previously-entered values intact, exactly as before. -->
    <div class="modal user-modal" id="addUserModal" style="<?php echo $show_add_form ? 'display:flex;' : 'display:none;'; ?>">
        <div class="modal-content">
            <div class="modal-title-row">
                <h2><i class="fas fa-user-plus"></i> Add New User</h2>
                <button type="button" class="modal-close-btn" onclick="closeAddUserModal()" aria-label="Close">&times;</button>
            </div>
            <p class="modal-sub">Create an Admin, Staff or Rider account. The user can sign in immediately with the temporary password below.</p>
            <form method="POST" action="staff_management.php">
                <?php echo csrf_field(); ?>
                <div class="user-form-grid">
                    <div>
                        <label for="uf_username">Username</label>
                        <input type="text" id="uf_username" name="username" required minlength="4" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9._]*" title="4–30 characters: letters, numbers, dots or underscores" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="uf_email">Email Address</label>
                        <input type="email" id="uf_email" name="email" required maxlength="100" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="uf_first_name">First Name</label>
                        <input type="text" id="uf_first_name" name="first_name" required maxlength="50" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="uf_last_name">Last Name</label>
                        <input type="text" id="uf_last_name" name="last_name" required maxlength="50" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="uf_password">Temporary Password</label>
                        <div class="password-field">
                            <input type="password" id="uf_password" name="password" required minlength="8" maxlength="64" placeholder="8+ with A-Z, a-z, 0-9 and a symbol" data-password-rules data-avoid="username,first_name,last_name,email" title="<?php echo htmlspecialchars(auth_password_rules_text()); ?>">
                            <button type="button" class="pw-eye" data-pw-toggle aria-label="Show password" aria-pressed="false"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                    <div>
                        <label for="uf_role">Role</label>
                        <select id="uf_role" name="role">
                            <option value="staff" <?php echo (($_POST['role'] ?? 'staff') === 'staff') ? 'selected' : ''; ?>>Staff</option>
                            <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin</option>
                            <option value="rider" <?php echo (($_POST['role'] ?? '') === 'rider') ? 'selected' : ''; ?>>Rider</option>
                        </select>
                    </div>
                    <!-- Rider only: the delivery profile the rider portal and order assignment use. -->
                    <div data-rider-field>
                        <label for="uf_ebike">E-Bike <span style="font-weight: 400; color: var(--muted);">(optional)</span></label>
                        <select id="uf_ebike" name="ebike_id">
                            <option value="0">None</option>
                            <?php foreach ($free_ebikes as $fb): $fb_meta = array_filter([$fb['model'], $fb['plate_number']]); ?>
                                <option value="<?php echo (int) $fb['id']; ?>" <?php echo (int) ($_POST['ebike_id'] ?? 0) === (int) $fb['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($fb['bike_code'] . ($fb_meta ? ' (' . implode(' · ', $fb_meta) . ')' : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$free_ebikes): ?><small style="color: var(--muted);">Every e-bike already has a rider (or none are switched on).</small><?php endif; ?>
                    </div>
                    <div data-rider-field>
                        <label for="uf_plate">Plate Number <span style="font-weight: 400; color: var(--muted);">(optional)</span></label>
                        <input type="text" id="uf_plate" name="plate_number" maxlength="20" pattern="[A-Za-z0-9 \-]{2,20}" placeholder="e.g. ABC 1234" value="<?php echo htmlspecialchars($_POST['plate_number'] ?? ''); ?>">
                    </div>
                    <script>
                        (function () {
                            var role = document.getElementById('uf_role');
                            function sync() {
                                var rider = role.value === 'rider';
                                document.querySelectorAll('[data-rider-field]').forEach(function (el) { el.hidden = !rider; });
                            }
                            role.addEventListener('change', sync);
                            sync();
                        })();
                    </script>
                    <div class="user-modal-actions">
                        <button type="button" class="btn-clear-filter" onclick="closeAddUserModal()">Cancel</button>
                        <button type="submit" name="add_user_submit" style="background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600;">
                            <i class="fas fa-check"></i> Save User
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Remove Access confirmation modal — one shared form that submits
         through the EXACT existing POST handler (csrf_token + del_staff),
         just populated per-row by JS instead of one <form> per row. -->
    <div class="modal confirm-remove-box" id="removeAccessModal" style="display:none;">
        <div class="modal-content">
            <h3><i class="fas fa-triangle-exclamation"></i> Remove Staff Access?</h3>
            <p>Are you sure you want to remove access for <strong id="removeAccessName"></strong>? This user will no longer be able to access the staff system.</p>
            <form method="POST" action="staff_management.php" id="removeAccessForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="del_staff" id="removeAccessId" value="">
                <div class="confirm-remove-actions">
                    <button type="button" class="btn-cancel-remove" onclick="closeRemoveModal()">Cancel</button>
                    <button type="submit" class="btn-confirm-remove"><i class="fas fa-user-slash"></i> Remove Access</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Recover an account (admin, staff or rider): email a reset link, set a temporary password, or unlock. -->
    <div class="modal recover-box" id="recoverModal" role="dialog" aria-modal="true" aria-labelledby="recoverTitle" style="display:none;">
        <div class="modal-content">
            <h3 id="recoverTitle"><i class="fas fa-key" style="color:var(--link);"></i> Recover account: <span id="recoverName"></span></h3>
            <p class="modal-sub" id="recoverSub"></p>
            <?php if ($recover_error !== ''): ?><div class="recover-error" role="alert"><?php echo htmlspecialchars($recover_error); ?></div><?php endif; ?>

            <div class="recover-option is-warn" id="recoverUnlock" hidden>
                <h4><i class="fas fa-lock-open"></i> Account is locked</h4>
                <p>Too many wrong passwords. Unlock it if they still know their password.</p>
                <form method="POST" action="staff_management.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="recover_account" value="1">
                    <input type="hidden" name="return_role" value="<?php echo htmlspecialchars($role_filter); ?>">
                    <input type="hidden" name="recover_mode" value="unlock">
                    <input type="hidden" name="recover_user_id" data-recover-id>
                    <button type="submit"><i class="fas fa-lock-open"></i> Unlock only</button>
                </form>
            </div>

            <div class="recover-option">
                <h4><i class="fas fa-envelope"></i> Email a reset link</h4>
                <p id="recoverEmailText"></p>
                <form method="POST" action="staff_management.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="recover_account" value="1">
                    <input type="hidden" name="return_role" value="<?php echo htmlspecialchars($role_filter); ?>">
                    <input type="hidden" name="recover_mode" value="link">
                    <input type="hidden" name="recover_user_id" data-recover-id>
                    <button type="submit" id="recoverLinkBtn"><i class="fas fa-paper-plane"></i> Send reset link</button>
                </form>
            </div>

            <div class="recover-option">
                <h4><i class="fas fa-user-lock"></i> Set a temporary password</h4>
                <p>When they can't get to their email. Tell them the password in person. They are signed out of every device.</p>
                <form method="POST" action="staff_management.php" id="recoverTempForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="recover_account" value="1">
                    <input type="hidden" name="return_role" value="<?php echo htmlspecialchars($role_filter); ?>">
                    <input type="hidden" name="recover_mode" value="temp">
                    <input type="hidden" name="recover_user_id" data-recover-id>
                    <div class="password-field">
                        <input type="password" name="temp_password" required maxlength="64" autocomplete="new-password" placeholder="Temporary password" aria-label="Temporary password">
                        <button type="button" class="pw-eye" data-pw-toggle aria-label="Show password" aria-pressed="false"><i class="fas fa-eye"></i></button>
                    </div>
                    <div class="password-field">
                        <input type="password" name="temp_password_confirm" required maxlength="64" autocomplete="new-password" placeholder="Type it again" aria-label="Confirm temporary password">
                        <button type="button" class="pw-eye" data-pw-toggle aria-label="Show password" aria-pressed="false"><i class="fas fa-eye"></i></button>
                    </div>
                    <p style="margin:0 0 10px;"><?php echo htmlspecialchars(auth_password_rules_text()); ?></p>
                    <button type="submit"><i class="fas fa-check"></i> Set password</button>
                </form>
            </div>

            <button type="button" class="recover-cancel" onclick="closeRecoverModal()">Cancel</button>
        </div>
    </div>

    <script>
        var RESET_MINUTES = <?php echo (int) ACCOUNT_RESET_TTL_MINUTES; ?>;
        var ROLE_WORD = { admin: 'admin', staff: 'staff member', rider: 'rider' };
        function openRecoverModal(btn) {
            var m = document.getElementById('recoverModal');
            var email = btn.dataset.email || '';
            var who = ROLE_WORD[btn.dataset.role] || 'user';
            document.getElementById('recoverName').textContent = btn.dataset.name;
            document.getElementById('recoverSub').textContent = 'For ' + (who === 'admin' ? 'an ' : 'a ') + who
                + ' who forgot their password or is locked out. Both options below also unlock the account.';
            m.querySelectorAll('[data-recover-id]').forEach(function (i) { i.value = btn.dataset.id; });
            document.getElementById('recoverUnlock').hidden = btn.dataset.locked !== '1';
            document.getElementById('recoverLinkBtn').disabled = email === '';
            document.getElementById('recoverEmailText').textContent = email
                ? 'Sends a link to ' + email + ' so they can choose a new password (expires in ' + RESET_MINUTES + ' minutes).'
                : 'This account has no email address. Set a temporary password instead.';
            m.style.display = 'flex';
        }
        function closeRecoverModal() {
            document.getElementById('recoverModal').style.display = 'none';
        }
        document.querySelectorAll('[data-recover]').forEach(function (b) {
            b.addEventListener('click', function () { openRecoverModal(b); });
        });
        document.getElementById('recoverTempForm').addEventListener('submit', function (e) {
            if (this.temp_password.value !== this.temp_password_confirm.value) {
                e.preventDefault();
                alert('The two passwords do not match.');
            }
        });
        <?php if ($recover_error !== '' && $recover_user_id > 0): ?>
        document.addEventListener('DOMContentLoaded', function () {
            var b = document.querySelector('[data-recover][data-id="<?php echo (int) $recover_user_id; ?>"]');
            if (b) openRecoverModal(b);
        });
        <?php endif; ?>

        function openAddUserModal() {
            document.getElementById('addUserModal').style.display = 'flex';
        }
        function closeAddUserModal() {
            document.getElementById('addUserModal').style.display = 'none';
        }
        function openRemoveModal(id, name) {
            document.getElementById('removeAccessId').value = id;
            document.getElementById('removeAccessName').textContent = name;
            document.getElementById('removeAccessModal').style.display = 'flex';
        }
        function closeRemoveModal() {
            document.getElementById('removeAccessModal').style.display = 'none';
        }
        // Click the dark overlay (not the modal box itself) to close either modal.
        window.addEventListener('click', function (e) {
            if (e.target.id === 'addUserModal') closeAddUserModal();
            if (e.target.id === 'removeAccessModal') closeRemoveModal();
            if (e.target.id === 'recoverModal') closeRecoverModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAddUserModal();
                closeRemoveModal();
                closeRecoverModal();
            }
        });
        <?php if ($show_add_form): ?>
        document.addEventListener('DOMContentLoaded', openAddUserModal);
        <?php endif; ?>
    </script>
<script src="<?php echo BASE_URL; ?>/assets/js/password-rules.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/password-rules.js') ?: time(); ?>" defer></script>
<script>
    // Show/hide eye on each password field.
    (function () {
        function setShown(btn, shown) {
            var input = btn.parentElement.querySelector('input');
            if (!input) return;
            input.type = shown ? 'text' : 'password';
            btn.setAttribute('aria-pressed', shown ? 'true' : 'false');
            btn.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
            btn.querySelector('i').className = shown ? 'fas fa-eye-slash' : 'fas fa-eye';
        }
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-pw-toggle]');
            if (!btn) return;
            setShown(btn, btn.getAttribute('aria-pressed') !== 'true');
            var input = btn.parentElement.querySelector('input');
            if (input) input.focus();
        });
        // Hide them again when a form is sent or cleared, so a password is never left showing.
        document.addEventListener('submit', function (e) {
            e.target.querySelectorAll('[data-pw-toggle]').forEach(function (b) { setShown(b, false); });
        }, true);
        document.addEventListener('reset', function (e) {
            e.target.querySelectorAll('[data-pw-toggle]').forEach(function (b) { setShown(b, false); });
        }, true);
    })();
</script>
</body>
</html>
