<?php
// Promotions moved into Manage Rewards (tabs "Sale Prices" / "Voucher Promos").
$tab = ($_GET['tab'] ?? '') === 'vouchers' ? 'vouchers' : 'sales';
header('Location: manage_rewards.php?tab=' . $tab);
exit();
