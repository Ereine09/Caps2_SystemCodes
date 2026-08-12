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

// Fetch all remittance records from tbl_rider_remittances
$remittances = [];
try {
    $query = "
        SELECT 
            rr.id AS remittance_id,
            rr.amount,
            rr.reference_number,
            rr.status,
            COALESCE(rr.processed_at, rr.remitted_at) AS remitted_at,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS rider_name,
            u.username AS rider_username
        FROM tbl_rider_remittances rr
        JOIN users u ON rr.rider_id = u.id
        ORDER BY rr.id DESC;
    ";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $remittances[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching all remittances: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Remittance Management Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin_style.css">
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
            --success-color: #16a34a;
            --danger-color: #dc2626;
            --bg-light: #f8fafc;
            --border-color: #e2e8f0;
            --text-dark: #1e293b;
            --text-muted: #64748b;
        }

        body { 
            overflow-x: hidden; 
            background-color: #f1f5f9;
        }
        /* Sidebar Container Solution */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            display: flex;
            flex-direction: column;
            background-color: #1e293b;
            z-index: 1000;
            box-sizing: border-box;
        }

        .sidebar-brand {
            flex-shrink: 0;
            padding: 20px 15px;
            text-align: center;
        }

        /* Ginawang scrollable ang listahan kapag mahaba */
        .nav-links {
            flex: 1;
            overflow-y: auto;
            list-style: none;
            padding: 0 10px !important;
            margin: 0;
        }

        /* Subtle Scrollbar Style */
        .nav-links::-webkit-scrollbar {
            width: 5px;
        }
        .nav-links::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        /* Sidebar Footer para sa Logout (Pinned at the bottom) */
        .sidebar-footer {
            flex-shrink: 0;
            padding: 16px 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            background-color: #1e293b;
        }

        .logout-link {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ef4444 !important;
            font-weight: 600;
            text-decoration: none;
            transition: opacity 0.2s ease;
        }

        .logout-link:hover {
            opacity: 0.8;
        }

        /* Main Dashboard Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            background: #ffffff;
            padding: 20px 25px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .page-header h1 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Section Container Box */
        .content-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
        }
        .content-card h3 {
            margin-top: 0;
            margin-bottom: 18px;
            font-size: 1.15rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Pending Action Cards View */
        .pending-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 18px;
        }
        .rider-remit-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 18px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .rider-remit-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.05);
            border-color: #cbd5e1;
        }
        .rider-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }
        .rider-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #eff6ff;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
        }
        .rider-details h4 {
            margin: 0;
            font-size: 1rem;
            color: var(--text-dark);
        }
        .rider-details p {
            margin: 2px 0 0 0;
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .remit-amount-box {
            background: var(--bg-light);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 16px;
            text-align: center;
        }
        .remit-amount-box span {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 600;
        }
        .remit-amount-box h2 {
            margin: 4px 0 0 0;
            font-size: 1.5rem;
            color: var(--text-dark);
        }

        /* Buttons */
        .btn-action {
            width: 100%;
            padding: 10px 16px;
            border-radius: 8px;
            border: none;
            background: var(--primary-color);
            color: #ffffff;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s ease;
        }
        .btn-action:hover {
            background: var(--primary-hover);
        }

        /* Table Styling */
        .custom-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .custom-table th {
            background: var(--bg-light);
            padding: 12px 16px;
            text-align: left;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
        }
        .custom-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
            color: var(--text-dark);
            vertical-align: middle;
        }
        .custom-table tr:last-child td {
            border-bottom: none;
        }

        /* Status Badges */
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .badge-approved { background: #dcfce7; color: #15803d; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; }
        .badge-pending  { background: #fef3c7; color: #b45309; }

        /* Modal Redesign */
        .modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
        }
        .modal-content {
            background-color: #ffffff;
            margin: 6% auto;
            padding: 30px;
            border: none;
            width: 90%;
            max-width: 650px;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            animation: modalSlide 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalSlide {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .close-button {
            color: var(--text-muted);
            float: right;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .close-button:hover { color: var(--text-dark); }

        .modal-footer {
            margin-top: 25px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn-modal-reject {
            background: #f1f5f9;
            color: var(--danger-color);
            border: 1px solid #cbd5e1;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-modal-reject:hover {
            background: #fee2e2;
            border-color: #fca5a5;
        }
        .btn-modal-approve {
            background: var(--success-color);
            color: #ffffff;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s ease;
        }
        .btn-modal-approve:hover {
            background: #15803d;
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
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php"><i class="fas fa-users-cog"></i> Staff Management</a></li>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/activity_logs.php"><i class="fas fa-history"></i> Activity Logs</a></li>
            <?php endif; ?>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/manage_rewards.php"><i class="fas fa-boxes"></i> Manage Rewards</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/products.php"><i class="fas fa-store"></i> Products</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/orders.php">
                <i class="fas fa-shopping-cart"></i> Orders
                <?php if ($pending_orders_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo $pending_orders_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/delivery.php"><i class="fas fa-truck"></i> Delivery</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/about.php"><i class="fas fa-info-circle"></i> About Us</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/messages.php">
                <i class="fas fa-comment-dots"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span style="background: #e74c3c; color: white; border-radius: 999px; padding: 2px 8px; font-size: 0.75rem; margin-left: 5px; font-weight: bold;"><?php echo (int)$unread_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/reviews.php"><i class="fas fa-star-half-alt"></i> Reviews</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/admin/remittance_management.php" class="active"><i class="fas fa-money-bill-wave"></i> Remittance</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/customers.php"><i class="fas fa-user-friends"></i> Customers</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/loyalty_points.php"><i class="fas fa-star"></i> Loyalty Points</a></li>
            <li><a href="<?php echo BASE_URL; ?>/modules/customers/reward_redemption.php"><i class="fas fa-gift"></i> Reward Redemption</a></li>
            <?php if ($user_role === 'admin'): ?>
                <li><a href="<?php echo BASE_URL; ?>/modules/admin/analytics.php"><i class="fas fa-chart-line"></i> Analytics & Reports</a></li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-footer">
            <a href="<?php echo BASE_URL; ?>/modules/auth/logout.php" class="logout-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-hand-holding-usd" style="color: var(--primary-color);"></i> Rider Remittance Management</h1>
        </div>

        <main>
            <!-- Pending Remittances Action Area -->
            <div class="content-card">
                <h3><i class="fas fa-clock" style="color: #f59e0b;"></i> Pending Approvals</h3>
                <div id="pending-container">
                    <p style="text-align: center; color: var(--text-muted); padding: 20px 0;">Loading pending remittances...</p>
                </div>
            </div>

            <!-- All History Table Area -->
            <div class="content-card">
                <h3><i class="fas fa-history" style="color: var(--primary-color);"></i> All Remittance Logs</h3>
                <?php if (empty($remittances)): ?>
                    <p style="text-align: center; padding: 30px; color: var(--text-muted);">No historical remittance logs recorded yet.</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="custom-table" id="all-remittances-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Rider</th>
                                    <th>Amount</th>
                                    <th>Ref Number</th>
                                    <th>Status</th>
                                    <th>Date Processed</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($remittances as $remittance): ?>
                                    <tr>
                                        <td><strong>#<?php echo htmlspecialchars($remittance['remittance_id']); ?></strong></td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars(trim($remittance['rider_name']) ?: $remittance['rider_username']); ?></div>
                                            <small style="color: var(--text-muted);">@<?php echo htmlspecialchars($remittance['rider_username']); ?></small>
                                        </td>
                                        <td style="font-weight: 700; color: var(--text-dark);">PHP <?php echo number_format((float)$remittance['amount'], 2); ?></td>
                                        <td><code style="background: var(--bg-light); padding: 2px 6px; border-radius: 4px; border: 1px solid var(--border-color);"><?php echo htmlspecialchars($remittance['reference_number'] ?? 'N/A'); ?></code></td>
                                        <td>
                                            <?php 
                                                $st = strtolower($remittance['status'] ?? 'approved'); 
                                                $icon = $st === 'approved' ? 'fa-check-circle' : ($st === 'rejected' ? 'fa-times-circle' : 'fa-hourglass-half');
                                            ?>
                                            <span class="badge-status badge-<?php echo $st; ?>">
                                                <i class="fas <?php echo $icon; ?>"></i> <?php echo ucfirst($st); ?>
                                            </span>
                                        </td>
                                        <td style="color: var(--text-muted); font-size: 0.85rem;"><?php echo htmlspecialchars($remittance['remitted_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Audit / Verification Modal -->
    <div id="remittance-modal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeModal()">&times;</span>
            <h2 id="modal-title" style="margin-top: 0; color: var(--text-dark); display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-file-invoice-dollar" style="color: var(--primary-color);"></i> Remittance Audit
            </h2>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Cross-check physical cash received against the unsettled COD orders below.</p>
            
            <div id="modal-body">
                <table id="remittance-details-table">
                    <thead>
                        <tr>
                            <th>Order Number</th>
                            <th>Date</th>
                            <th>Total (PHP)</th>
                        </tr>
                    </thead>
                    <tbody id="remittance-details-tbody">
                    </tbody>
                    <tfoot>
                        <tr style="background: var(--bg-light);">
                            <th colspan="2" style="text-align: right; padding: 12px; font-size: 0.95rem;">Total Cash to Confirm:</th>
                            <th id="remittance-total" style="font-size: 1.1rem; color: var(--success-color); font-weight: 700; padding: 12px;"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="modal-footer">
                <button class="btn-modal-reject" onclick="rejectRemittance()">
                    <i class="fas fa-times"></i> Reject Payment
                </button>
                <button class="btn-modal-approve" onclick="approveRemittance()">
                    <i class="fas fa-check"></i> Accept & Confirm Cash Received
                </button>
            </div>
        </div>
    </div>

    <script>
    let currentRiderId = null;
    let currentRemittanceDetails = { amount: 0, order_ids: [] };

    async function fetchPendingRemittances() {
        try {
            const response = await fetch('remittance_api.php?action=get_pending_remittances');
            const result = await response.json();
            const container = document.getElementById('pending-container');
            container.innerHTML = '';

            if (result.success && result.data.length > 0) {
                const grid = document.createElement('div');
                grid.className = 'pending-cards-grid';

                result.data.forEach(remittance => {
                    const initials = (remittance.rider_name || remittance.username).substring(0, 2).toUpperCase();
                    const cardHtml = `
                        <div class="rider-remit-card">
                            <div>
                                <div class="rider-info">
                                    <div class="rider-avatar">${initials}</div>
                                    <div class="rider-details">
                                        <h4>${remittance.rider_name}</h4>
                                        <p>@${remittance.username}</p>
                                    </div>
                                </div>
                                <div class="remit-amount-box">
                                    <span>Unremitted COD Balance</span>
                                    <h2>PHP ${parseFloat(remittance.total_unremitted_cod).toLocaleString('en-US', {minimumFractionDigits: 2})}</h2>
                                </div>
                            </div>
                            <button class="btn-action" onclick="viewDetails(${remittance.rider_id})">
                                <i class="fas fa-clipboard-check"></i> Review & Approve
                            </button>
                        </div>
                    `;
                    grid.innerHTML += cardHtml;
                });
                container.appendChild(grid);
            } else {
                container.innerHTML = `
                    <div style="text-align: center; padding: 30px; color: var(--text-muted);">
                        <i class="fas fa-check-circle" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 10px;"></i>
                        <p style="margin: 0;">No pending remittances requiring approval.</p>
                    </div>
                `;
            }
        } catch (err) {
            console.error('Error fetching pending remittances:', err);
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
                        <td><strong>#${order.order_number}</strong></td>
                        <td>${new Date(order.created_at).toLocaleDateString()}</td>
                        <td style="font-weight: 600;">PHP ${parseFloat(order.total).toFixed(2)}</td>
                    </tr>
                `;
                detailsBody.innerHTML += row;
                total += parseFloat(order.total);
                currentRemittanceDetails.order_ids.push(order.order_id);
            });
            currentRemittanceDetails.amount = total;
            totalCell.textContent = 'PHP ' + total.toLocaleString('en-US', {minimumFractionDigits: 2});
            modal.style.display = 'block';
        }
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
            location.reload(); 
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
            fetchPendingRemittances();
        }
    }

    document.addEventListener('DOMContentLoaded', fetchPendingRemittances);

    window.onclick = function(event) {
        const modal = document.getElementById('remittance-modal');
        if (event.target === modal) {
            closeModal();
        }
    };
    </script>
</body>
</html>