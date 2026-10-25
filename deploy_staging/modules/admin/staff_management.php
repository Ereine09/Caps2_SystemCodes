<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';
require_once __DIR__ . '/../../app/helpers/csrf_helper.php';

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

    if (!in_array($new_role, ['admin', 'staff'], true)) {
        $new_role = 'staff';
    }

    if ($new_username === '' || $first_name === '' || $last_name === '' || $email === '' || $password_raw === '') {
        $error_message = 'All user fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } elseif (strlen($password_raw) < 8) {
        $error_message = 'Password must be at least 8 characters long.';
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

            //add new user, then log the action in activity_logs
            if ($stmt->execute()) {
                $details = "Added new " . ucfirst($new_role) . " account: $new_username";
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Manage Staff', ?)");
                $log_stmt->bind_param("is", $user_id, $details);
                $log_stmt->execute();

                header('Location: staff_management.php?success=user_added');
                exit();
            } else {
                error_log('Staff account creation failed: ' . $stmt->error);
                $error_message = 'Could not add the user. Please try again.';
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management - DPS Admin</title>
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
            <div class="toolbar">
                <form method="GET" class="filter-form" action="staff_management.php">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search users...">
                    </div>
                    <select name="role_filter" onchange="this.form.submit()">
                        <option value="">All Roles</option>
                        <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="staff" <?php echo $role_filter === 'staff' ? 'selected' : ''; ?>>Staff</option>
                        <option value="rider" <?php echo $role_filter === 'rider' ? 'selected' : ''; ?>>Rider</option>
                    </select>
                    <button type="submit" class="btn-clear-filter" style="background:#3498db; color:#fff; border-color:#3498db;">Search</button>
                    <?php if ($search !== '' || $role_filter !== ''): ?>
                        <a href="staff_management.php" class="btn-clear-filter">Clear</a>
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
                                            <button type="button" class="btn-remove-access"
                                                onclick="openRemoveModal(<?php echo (int) $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['username']), ENT_QUOTES); ?>')">
                                                <i class="fas fa-user-slash"></i> Remove Access
                                            </button>
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
            <p class="modal-sub">Create an Admin or Staff account. The user can sign in immediately with the temporary password below.</p>
            <form method="POST" action="staff_management.php">
                <?php echo csrf_field(); ?>
                <div class="user-form-grid">
                    <div>
                        <label for="uf_username">Username</label>
                        <input type="text" id="uf_username" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="uf_email">Email Address</label>
                        <input type="email" id="uf_email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="uf_first_name">First Name</label>
                        <input type="text" id="uf_first_name" name="first_name" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="uf_last_name">Last Name</label>
                        <input type="text" id="uf_last_name" name="last_name" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="uf_password">Temporary Password</label>
                        <input type="password" id="uf_password" name="password" required minlength="8" placeholder="Min. 8 characters">
                    </div>
                    <div>
                        <label for="uf_role">Role</label>
                        <select id="uf_role" name="role">
                            <option value="staff" <?php echo (($_POST['role'] ?? 'staff') === 'staff') ? 'selected' : ''; ?>>Staff</option>
                            <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
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

    <script>
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
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAddUserModal();
                closeRemoveModal();
            }
        });
        <?php if ($show_add_form): ?>
        document.addEventListener('DOMContentLoaded', openAddUserModal);
        <?php endif; ?>
    </script>
</body>
</html>
