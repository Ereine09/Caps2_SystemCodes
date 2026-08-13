<?php
require_once __DIR__ . '/includes/auth.php';
require_customer_login();

$customer = current_customer();
$customer_id = (int)$customer['id'];

// Fetch unlocked achievements for the customer
$query = "
    SELECT a.name, a.description, a.icon_class, ca.unlocked_at
    FROM customer_achievements ca
    JOIN achievements a ON ca.achievement_id = a.id
    WHERE ca.customer_id = ?
    ORDER BY ca.unlocked_at DESC
";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $customer_id);
$stmt->execute();
$result = $stmt->get_result();
$achievements = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$page_title = 'My Achievements'; // This will be used by the header
include __DIR__ . '/includes/header.php'; // Include the main customer header
?>

<style>
    .achievements-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    .achievement-card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        text-align: center;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .achievement-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.07);
    }
    .achievement-icon {
        font-size: 2.5rem;
        color: #6366f1;
        margin-bottom: 15px;
        width: 70px;
        height: 70px;
        line-height: 70px;
        border-radius: 50%;
        background: #eef2ff;
        display: inline-block;
    }
    .achievement-card h3 {
        margin: 0 0 8px 0;
        font-size: 1.1rem;
        color: #1e293b;
    }
    .achievement-card p {
        color: #64748b;
        font-size: 0.9rem;
        line-height: 1.5;
        margin: 0 0 10px 0;
    }
    .achievement-card .unlocked-date {
        font-size: 0.8rem;
        color: #94a3b8;
        font-weight: 600;
    }
</style>

<section class="customer-panel">
    <div class="welcome-header">
        <h1>My Achievements 🏆</h1>
        <p>Your collection of unlocked badges and milestones.</p>
    </div>

    <div class="achievements-grid">
        <?php if (empty($achievements)): ?>
            <p>You haven't unlocked any achievements yet. Keep shopping to earn badges!</p>
        <?php else: ?>
            <?php foreach ($achievements as $ach): ?>
                <div class="achievement-card">
                    <div class="achievement-icon"><i class="fas <?php echo htmlspecialchars($ach['icon_class']); ?>"></i></div>
                    <h3><?php echo htmlspecialchars($ach['name']); ?></h3>
                    <p><?php echo htmlspecialchars($ach['description']); ?></p>
                    <span class="unlocked-date">Unlocked on <?php echo date('M d, Y', strtotime($ach['unlocked_at'])); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; // Include the main customer footer ?>