<?php
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/helpers/jwt_helper.php';
require_once __DIR__ . '/../../app/helpers/remittance_schema_helper.php';
require_once __DIR__ . '/../../app/helpers/messaging_helper.php';

$token = getJWTFromCookie();
if (!$token || !($payload = verifyJWT($token)) || !in_array($payload['role'], ['admin', 'staff'])) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}
$username = htmlspecialchars($payload['username']);
$user_role = htmlspecialchars($payload['role']);
$user_id = (int)$payload['user_id'];

// Fetch counts for sidebar badges
$pending_orders_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS count FROM tbl_orders WHERE order_status = 'pending'"))['count'] ?? 0;
$unread_count = get_unread_count_staff($user_id);

// Ensure DB schema is ready
ensure_remittance_schema($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rider Remittance Management - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        /* Hide sidebar scrollbar for all browsers */
        body {
            overflow-x: hidden;
        }
        .sidebar::-webkit-scrollbar {
            display: none;
        }
        .sidebar {
            -ms-overflow-style: none; /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 25px;
            border: 1px solid #888;
            width: 80%;
            max-width: 700px;
            border-radius: 12px;
        }
        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        #remittance-details-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        #remittance-details-table th, #remittance-details-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        #remittance-details-table th {
            background-color: #f2f2f2;
        }
        .modal-footer {
            margin-top: 20px;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand" style="text-align: center; padding: 20px 15px;">
            <img src="<?php echo SYSTEM_LOGO_URL; ?>" alt="Logo" style="max-width: 160px; max-height: 80px; display: block; margin: 0 auto 10px; border-radius: 5px;">
            <h2 style="color: white; font-size: 1rem; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars(SYSTEM_NAME); ?></h2>
        </div>
        <ul class="nav-links" style="list-style: none; padding: 0;">
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'class="active"' : ''; ?>><i class="fas fa-home"></i> Dashboard</a></li>
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'staff_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php" <?php echo basename($_SERVER['PHP_SELF']) === 'activity_logs.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Activity Logs</a></li>
            <?php endif; ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php" <?php echo basename($_SERVER['PHP_SELF']) === 'manage_rewards.php' ? 'class="active"' : ''; ?>><i class="fas fa-boxes"></i> Manage Rewards</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/products.php" <?php echo basename($_SERVER['PHP_SELF']) === 'products.php' ? 'class="active"' : ''; ?>><i class="fas fa-store"></i> Products</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/orders.php" <?php echo basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-shopping-cart"></i> Orders
                <?php if ($pending_orders_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php" <?php echo basename($_SERVER['PHP_SELF']) === 'messages.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-comment-dots"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
                <?php endif; ?>
            </a></li>            
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" <?php echo basename($_SERVER['PHP_SELF']) === 'remittance_management.php' ? 'class="active"' : ''; ?>><i class="fas fa-money-bill-wave"></i> Remittance</a></li>            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php' || basename($_SERVER['PHP_SELF']) == 'customer_details.php') ? 'active' : ''; ?>"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'loyalty_points.php') ? 'active' : ''; ?>"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php" class="<?php echo (basename($_SERVER['PHP_SELF']) == 'reward_redemption.php') ? 'active' : ''; ?>"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php" <?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'class="active"' : ''; ?>><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link" style="margin-top: auto; padding: 15px 20px; display: block; text-decoration: none;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="main-content">
        <header style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <h1 style="margin: 0;"><i class="fas fa-hand-holding-usd"></i> Rider Remittance Management</h1>
            <!-- Tinanggal ang extra icon link para malinis -->
        </header>

        <main>
            <div class="table-box">
                <h3>Pending Remittances</h3>
                <table id="pending-remittances-table">
                    <thead>
                        <tr>
                            <th>Rider Name</th>
                            <th>Total Unremitted COD Balance</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be loaded here by JavaScript -->
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Audit / Verification Modal -->
    <div id="remittance-modal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeModal()">&times;</span>
            <h2 id="modal-title">Remittance Details</h2>
            <p>Verify the physical cash handed over against the COD orders listed below.</p>
            <div id="modal-body">
                <table id="remittance-details-table">
                    <thead>
                        <tr>
                            <th>Order Number</th>
                            <th>Date</th>
                            <th>Amount (PHP)</th>
                        </tr>
                    </thead>
                    <tbody id="remittance-details-tbody">
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="2" style="text-align:right;">Total to Remit:</th>
                            <th id="remittance-total"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="modal-footer">
                <button class="button button-secondary" onclick="rejectRemittance()">Reject</button>
                <button class="button" onclick="approveRemittance()">Approve & Confirm Cash Received</button>
            </div>
        </div>
    </div>

<script>
let currentRiderId = null;
let currentRemittanceDetails = {
    amount: 0,
    order_ids: []
};

async function fetchPendingRemittances() {
    const response = await fetch('remittance_api.php?action=get_pending_remittances');
    const result = await response.json();
    const tableBody = document.querySelector('#pending-remittances-table tbody');
    tableBody.innerHTML = '';

    if (result.success && result.data.length > 0) {
        result.data.forEach(remittance => {
            const row = `
                <tr>
                    <td>${remittance.first_name} ${remittance.last_name}</td>
                    <td>PHP ${parseFloat(remittance.total_unremitted_cod).toFixed(2)}</td>
                    <td>
                        <button class="button" onclick="viewDetails(${remittance.rider_id})">View Details</button>
                    </td>
                </tr>
            `;
            tableBody.innerHTML += row;
        });
    } else {
        tableBody.innerHTML = '<tr><td colspan="3" style="text-align:center;">No pending remittances found.</td></tr>';
    }
}

async function viewDetails(riderId) {
    currentRiderId = riderId;
    const response = await fetch(`remittance_api.php?action=get_remittance_details&rider_id=${riderId}`);
    const result = await response.json();
    const modal = document.getElementById('remittance-modal');
    const detailsBody = document.getElementById('remittance-details-tbody');
    const totalCell = document.getElementById('remittance-total');
    
    detailsBody.innerHTML = '';
    let total = 0;
    currentRemittanceDetails.order_ids = [];

    if (result.success && result.data.length > 0) {
        result.data.forEach(order => {
            const row = `
                <tr>
                    <td>${order.order_number}</td>
                    <td>${new Date(order.created_at).toLocaleDateString()}</td>
                    <td>${parseFloat(order.total).toFixed(2)}</td>
                </tr>
            `;
            detailsBody.innerHTML += row;
            total += parseFloat(order.total);
            currentRemittanceDetails.order_ids.push(order.order_id);
        });
    }

    currentRemittanceDetails.amount = total;
    totalCell.textContent = `PHP ${total.toFixed(2)}`;
    modal.style.display = 'block';
}

function closeModal() {
    document.getElementById('remittance-modal').style.display = 'none';
    currentRiderId = null;
    currentRemittanceDetails = { amount: 0, order_ids: [] };
}

async function approveRemittance() {
    if (!currentRiderId || currentRemittanceDetails.order_ids.length === 0) {
        alert('No details to approve.');
        return;
    }

    if (!confirm(`Are you sure you have received PHP ${currentRemittanceDetails.amount.toFixed(2)} in cash? This action cannot be undone.`)) {
        return;
    }

    const response = await fetch('remittance_api.php?action=approve_remittance', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            rider_id: currentRiderId,
            amount: currentRemittanceDetails.amount,
            order_ids: currentRemittanceDetails.order_ids
        })
    });

    const result = await response.json();
    alert(result.message);

    if (result.success) {
        closeModal();
        fetchPendingRemittances();
    }
}

async function rejectRemittance() {
    if (!currentRiderId) {
        alert('No rider selected.');
        return;
    }

    const reason = prompt('Please enter the reason for rejecting this remittance (e.g., "Cash mismatch", "Incomplete orders"):');
    if (!reason) {
        alert('Rejection cancelled. A reason is required.');
        return;
    }

    const response = await fetch('remittance_api.php?action=reject_remittance', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            rider_id: currentRiderId,
            amount: currentRemittanceDetails.amount,
            notes: reason
        })
    });

    const result = await response.json();
    alert(result.message);

    if (result.success) {
        closeModal();
    }
}

document.addEventListener('DOMContentLoaded', fetchPendingRemittances);

// Close modal if user clicks outside of it
window.onclick = function(event) {
    const modal = document.getElementById('remittance-modal');
    if (event.target == modal) {
        closeModal();
    }
}
</script>

</body>
</html>