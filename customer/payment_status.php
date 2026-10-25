<?php
/**
 * Customer payment status for an online (PayMongo) order. PayMongo sends the customer back here
 * after paying (success_url and cancel_url both point here). Nothing in the URL is trusted: the
 * status shown is what our server recorded from PayMongo's signed webhook, or what our server
 * gets by asking PayMongo directly (payment_inquire). The customer can start a new attempt
 * after a failure/expiry, never mark anything paid.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/helpers/payment_helper.php';
require_once __DIR__ . '/../app/helpers/remittance_schema_helper.php';
require_customer_login();
payment_ensure_schema($conn);

$customer = current_customer();
$order_id = (int) ($_GET['order'] ?? 0);
$order = $order_id > 0 ? get_order_by_id($order_id, (int) $customer['id']) : null;
if (!$order || !in_array($order['payment_method'], ['gcash', 'bank'], true)) {
    header('Location: ' . BASE_URL . '/customer/orders.php');
    exit();
}
$self = BASE_URL . '/customer/payment_status.php?order=' . $order_id;
$notice = '';

// "Change payment method" after a failed / expired / cancelled / not-started online payment:
// pay on delivery (COD) or at the shop instead, or try the other online method.
$pay_methods = $order['fulfillment_type'] === 'pickup'
    ? ['pay_at_shop' => ['fa-store', 'Pay at the shop', 'Pay in cash when you pick up your order']]
    : ['cod' => ['fa-money-bill-wave', 'Cash on Delivery', 'Pay the rider in cash when your order arrives']];
if (payment_online_available()) {
    $pay_methods['gcash'] = ['fa-mobile-alt', 'GCash', 'Pay online again with GCash'];
    $pay_methods['bank'] = ['fa-university', 'Online banking', 'Pay online again from your bank'];
}
$open_for_change = static fn(array $o): bool => in_array($o['payment_status'], ['unpaid', 'failed', 'expired', 'cancelled'], true)
    && $o['payment_provider'] !== 'manual' && !in_array($o['order_status'], ['cancelled', 'refunded', 'completed', 'reviews'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    if ($action === 'change_method') {
        $new = (string) ($_POST['method'] ?? '');
        $last = payment_latest($conn, 'order', $order_id);
        if ($last && $last['status'] === 'pending') {
            // An attempt is still open at PayMongo: close it first so the customer can't pay twice.
            $chk = payment_cancel($conn, (int) $last['id']);
            $order = get_order_by_id($order_id, (int) $customer['id']);
            if (!$chk['ok'] || in_array($chk['status'] ?? '', ['paid', 'review'], true)) {
                $_SESSION['pay_notice'] = $chk['ok'] ? 'Your previous payment went through, so the payment method was not changed.' : $chk['message'];
                header('Location: ' . $self);
                exit();
            }
        }
        if (!isset($pay_methods[$new]) || !$open_for_change($order)) {
            $_SESSION['pay_notice'] = 'The payment method of this order can no longer be changed.';
            header('Location: ' . $self);
            exit();
        }
        $offline = in_array($new, ['cod', 'pay_at_shop'], true);
        $provider = $offline ? null : 'paymongo';
        $stmt = $conn->prepare("UPDATE tbl_orders SET payment_method = ?, payment_status = 'unpaid', payment_provider = ?, updated_at = NOW()
                                WHERE id = ? AND customer_id = ? AND payment_status IN ('unpaid','failed','expired','cancelled')
                                  AND order_status NOT IN ('cancelled','refunded','completed','reviews')");
        $cid = (int) $customer['id'];
        $stmt->bind_param('ssii', $new, $provider, $order_id, $cid);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$changed) {
            $_SESSION['pay_notice'] = 'The payment method of this order can no longer be changed.';
            header('Location: ' . $self);
            exit();
        }
        payment_log_event($conn, ['order_id' => $order_id, 'event' => 'payment_method_changed', 'status' => 'unpaid',
            'amount' => (float) $order['total'], 'source' => 'customer',
            'details' => 'Customer changed the payment method from ' . $order['payment_method'] . ' to ' . $new . ' after the online payment was not completed.']);
        if ($offline) {
            $_SESSION['message'] = 'Payment method changed to ' . $pay_methods[$new][1] . '. Your order continues as usual - pay '
                . ($new === 'cod' ? 'the rider when it arrives.' : 'at the shop when you pick it up.');
            $_SESSION['message_type'] = 'success';
            header('Location: ' . BASE_URL . '/customer/orders.php?id=' . $order_id);
            exit();
        }
        // Another online method: start its payment right away.
        $start = payment_start_checkout($conn, [
            'purpose' => 'order', 'order_id' => $order_id, 'amount' => (float) $order['total'], 'method' => $new,
            'description' => 'Order #' . $order['order_number'], 'success_url' => SITE_URL . '/customer/payment_status.php?order=' . $order_id,
            'cancel_url' => SITE_URL . '/customer/payment_status.php?order=' . $order_id . '&cancelled=1',
            'name' => (string) ($customer['name'] ?? ''), 'email' => (string) ($customer['email'] ?? ''),
        ]);
        if ($start['ok']) {
            header('Location: ' . $start['checkout_url']);
            exit();
        }
        $_SESSION['pay_notice'] = $start['message'] . ' You can choose another payment method below.';
        header('Location: ' . $self);
        exit();
    }
    if ($action === 'retry' && in_array($order['payment_status'], ['unpaid', 'failed', 'expired', 'cancelled', 'pending'], true)
        && !in_array($order['order_status'], ['cancelled', 'refunded'], true) && $order['payment_provider'] !== 'manual') {
        // Only start again when the last attempt is really over (a pending one is checked first).
        $last = payment_latest($conn, 'order', $order_id);
        if ($last && $last['status'] === 'pending') {
            $chk = payment_inquire($conn, (int) $last['id']);
            if (!$chk['ok'] || in_array($chk['status'], ['pending', 'paid', 'review'], true)) {
                $_SESSION['pay_notice'] = $chk['ok'] ? 'Your previous payment is still being processed. Please do not pay again.' : $chk['message'];
                header('Location: ' . $self);
                exit();
            }
        }
        $start = payment_start_checkout($conn, [
            'purpose' => 'order', 'order_id' => $order_id, 'amount' => (float) $order['total'], 'method' => $order['payment_method'],
            'description' => 'Order #' . $order['order_number'], 'success_url' => SITE_URL . '/customer/payment_status.php?order=' . $order_id,
            'cancel_url' => SITE_URL . '/customer/payment_status.php?order=' . $order_id . '&cancelled=1',
            'name' => (string) ($customer['name'] ?? ''), 'email' => (string) ($customer['email'] ?? ''),
        ]);
        if ($start['ok']) {
            header('Location: ' . $start['checkout_url']);
            exit();
        }
        $_SESSION['pay_notice'] = $start['message'];
    }
    header('Location: ' . $self);
    exit();
}
if (!empty($_SESSION['pay_notice'])) {
    $notice = (string) $_SESSION['pay_notice'];
    unset($_SESSION['pay_notice']);
}

// Coming back from PayMongo (or refreshing): ask PayMongo for the result if it is still open.
// Back through the cancel link: PayMongo is asked first, and an open session is expired AT PayMongo
// before it is closed here, so the customer can start a fresh attempt.
$last = payment_latest($conn, 'order', $order_id);
$came_back_cancelled = ($_GET['cancelled'] ?? '') === '1';
if ($last && $last['status'] === 'pending' && ($came_back_cancelled || empty($last['last_checked_at']) || strtotime($last['last_checked_at']) < time() - 5)) {
    $chk = $came_back_cancelled ? payment_cancel($conn, (int) $last['id']) : payment_inquire($conn, (int) $last['id']);
    if (!$chk['ok'] && $notice === '') {
        $notice = $chk['message'];
    }
    $order = get_order_by_id($order_id, (int) $customer['id']);
    $last = payment_latest($conn, 'order', $order_id);
}

$ps = (string) $order['payment_status'];
$views = [
    'paid'    => ['fa-circle-check', 'ok',   'Payment successful', 'We received your payment. Your order is confirmed.'],
    'pending' => ['fa-hourglass-half', 'wait', 'Payment pending', 'We are waiting for payment confirmation. Please do not pay again unless instructed.'],
    'pending_verification' => ['fa-hourglass-half', 'wait', 'Pending verification', 'Our staff are checking your transfer. You will be notified once it is verified.'],
    'review'  => ['fa-magnifying-glass', 'wait', 'Payment under review', 'Your payment was received but did not match the order total. Our staff will review it and contact you.'],
    'failed'  => ['fa-circle-xmark', 'bad', 'Payment failed', 'Your payment was not completed. You may try again.'],
    'expired' => ['fa-clock', 'bad', 'Payment expired', 'The payment session expired before it was completed. You may try again.'],
    'cancelled' => ['fa-circle-xmark', 'bad', 'Payment not completed', 'No payment was made for this order.'],
    'unpaid'  => ['fa-wallet', 'bad', 'Not paid yet', 'You have not completed payment for this order yet.'],
];
[$icon, $tone, $title, $text] = $views[$ps] ?? $views['unpaid'];
$can_retry = in_array($ps, ['unpaid', 'failed', 'expired', 'cancelled'], true) && $order['payment_provider'] !== 'manual'
    && !in_array($order['order_status'], ['cancelled', 'refunded'], true);
$can_change = $can_retry && $open_for_change($order);
$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$page_title = 'Payment Status';
include __DIR__ . '/includes/header.php';
?>
<style>
    .pst { max-width: 560px; margin: 24px auto; padding: 28px 24px; text-align: center; border-radius: 18px; background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-sm); }
    .pst-icon { width: 68px; height: 68px; margin: 0 auto 14px; border-radius: 50%; display: grid; place-items: center; font-size: 1.8rem; }
    .pst.ok .pst-icon { background: var(--success-soft); color: var(--success); }
    .pst.wait .pst-icon { background: var(--warning-soft); color: var(--warning); }
    .pst.bad .pst-icon { background: var(--danger-soft); color: var(--danger); }
    .pst h2 { margin: 0 0 6px; font-size: 1.3rem; color: var(--text); }
    .pst p { margin: 0 0 14px; color: var(--muted); }
    .pst dl { display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; margin: 16px 0; text-align: left; font-size: .9rem; }
    .pst dt { color: var(--muted); }
    .pst dd { margin: 0; color: var(--text); font-weight: 600; overflow-wrap: anywhere; }
    .pst-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 8px; }
    .pst-change { margin-top: 22px; padding-top: 18px; border-top: 1px dashed var(--border); text-align: left; }
    .pst-change h3 { margin: 0 0 4px; font-size: 1rem; color: var(--text); }
    .pst-change > p { margin: 0 0 12px; font-size: .86rem; }
    .pst-methods { display: grid; gap: 8px; }
    .pst-methods form { margin: 0; }
    .pst-method { display: flex; align-items: center; gap: 12px; width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface);
                  color: var(--text); font: inherit; text-align: left; cursor: pointer; }
    .pst-method:hover { border-color: var(--primary); background: var(--primary-soft); }
    .pst-method i { width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center; background: var(--surface-2); color: var(--primary); flex-shrink: 0; }
    .pst-method strong { display: block; font-size: .92rem; }
    .pst-method small { display: block; color: var(--muted); font-size: .8rem; }
    .pst-method.is-current { opacity: .55; cursor: default; }
    .pst-note { margin: 0 0 14px; padding: 10px 12px; border-radius: 10px; background: var(--warning-soft); color: var(--text); font-size: .88rem; }
</style>
<section class="customer-panel">
    <div class="pst <?php echo $tone; ?>">
        <div class="pst-icon"><i class="fas <?php echo $icon; ?>"></i></div>
        <h2><?php echo $h($title); ?></h2>
        <p><?php echo $h($text); ?></p>
        <?php if ($notice !== ''): ?><div class="pst-note"><?php echo $h($notice); ?></div><?php endif; ?>
        <dl>
            <dt>Order</dt><dd>#<?php echo $h($order['order_number']); ?></dd>
            <dt>Amount</dt><dd>PHP <?php echo number_format((float) $order['total'], 2); ?></dd>
            <dt>Payment</dt><dd><?php echo $order['payment_method'] === 'bank' ? 'Online banking' : 'GCash'; ?><?php echo $order['payment_provider'] === 'paymongo' ? ' via PayMongo' : ''; ?></dd>
            <?php if ($last && $ps === 'paid' && !empty($last['provider_reference'])): ?><dt>Reference</dt><dd><?php echo $h($last['provider_reference']); ?></dd><?php endif; ?>
            <?php if ($ps === 'paid' && !empty($order['paid_at'])): ?><dt>Paid at</dt><dd><?php echo $h(date('M j, Y g:i A', strtotime($order['paid_at']))); ?></dd><?php endif; ?>
        </dl>
        <div class="pst-actions">
            <?php if ($can_retry): ?>
                <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="retry">
                    <button type="submit" class="button"><i class="fas fa-rotate-right"></i> <?php echo $ps === 'unpaid' ? 'Pay now' : 'Try again'; ?></button></form>
            <?php elseif ($ps === 'pending'): ?>
                <a class="button" href="<?php echo $h($self); ?>"><i class="fas fa-rotate"></i> Check again</a>
            <?php endif; ?>
            <a class="button button-secondary" href="<?php echo BASE_URL; ?>/customer/orders.php?id=<?php echo $order_id; ?>">View order</a>
        </div>
        <?php if ($can_change): ?>
            <div class="pst-change" id="change-method">
                <h3><i class="fas fa-exchange-alt"></i> Change payment method</h3>
                <p>Payment didn't go through? Choose another way to pay for this order. You won't be charged twice.</p>
                <div class="pst-methods">
                    <?php foreach ($pay_methods as $key => [$micon, $mlabel, $mhelp]): ?>
                        <?php $is_current = $key === $order['payment_method']; ?>
                        <form method="POST" data-confirm="<?php echo $h('Change this order\'s payment to ' . $mlabel . '?'); ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="change_method"><input type="hidden" name="method" value="<?php echo $h($key); ?>">
                            <button type="submit" class="pst-method<?php echo $is_current ? ' is-current' : ''; ?>"<?php echo $is_current ? ' disabled' : ''; ?>>
                                <i class="fas <?php echo $micon; ?>"></i><span><strong><?php echo $h($mlabel); ?><?php echo $is_current ? ' (current)' : ''; ?></strong><small><?php echo $h($mhelp); ?></small></span>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
            <script>
            document.querySelectorAll('.pst-methods form[data-confirm]').forEach(function (f) {
                f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
            });
            </script>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
