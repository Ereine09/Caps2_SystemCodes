<?php
/**
 * Promotions, shown as the "Sale Prices" and "Voucher Promos" tabs of Manage Rewards (admin only).
 * Included by manage_rewards.php before any output: handles the promotion POSTs (redirect back to
 * manage_rewards.php?tab=...) and loads the data promotions_view.php renders.
 *   Sale Prices    - a percent / peso-off sale on a product or a whole category, optionally scheduled;
 *                    "Pull" ends it and the product goes back to its regular price.
 *   Voucher Promos - a public promo code anyone can type at checkout, or single-use vouchers given to
 *                    chosen customers (notified, listed on My Vouchers); "Pull" switches codes off.
 * Rules live in app/helpers/promo_helper.php; checkout enforces them.
 * Expects $conn, $user_id, $tab.
 */
require_once __DIR__ . '/../../app/helpers/notification_helper.php';
require_once __DIR__ . '/../../app/helpers/promo_helper.php';

promo_ensure_schema($conn);

$pr_log = static function (string $details) use ($conn, $user_id): void {
    $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, 'Promotions', ?)");
    if ($log) {
        $log->bind_param('is', $user_id, $details);
        $log->execute();
        $log->close();
    }
};
/** "2026-10-20T08:00" (datetime-local) -> "2026-10-20 08:00:00", or null when empty/invalid. */
$pr_dt = static function (string $v): ?string {
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
};
$pr_input_dt = static fn(?string $v): string => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$pr_split_cats = static fn(?string $c): array => array_values(array_filter(array_map('trim', explode(',', (string) $c)), 'strlen'));

// Products (+ their lowest price including sizes, so a peso-off sale can't make anything free).
$pr_products = [];
$res = $conn->query(
    "SELECT p.id, p.name, p.sku, p.price, p.category, p.promo_type, p.promo_value, p.promo_starts_at, p.promo_ends_at,
            LEAST(p.price, COALESCE(MIN(v.price), p.price)) AS min_price, COUNT(v.id) AS variant_count
     FROM tbl_product_inventory p
     LEFT JOIN tbl_product_variants v ON v.product_id = p.id
     GROUP BY p.id
     ORDER BY p.name ASC"
);
while ($res && ($row = $res->fetch_assoc())) {
    $pr_products[(int) $row['id']] = $row;
}
$pr_categories = [];
foreach ($pr_products as $p) {
    foreach ($pr_split_cats($p['category']) as $c) {
        $pr_categories[strtolower($c)] = $c;
    }
}
natcasesort($pr_categories);

// $tab (rewards | sales | vouchers) is set by manage_rewards.php
app_session_start(); // the flash message survives the redirect after a POST
$flash = $_SESSION['promo_flash'] ?? null;
unset($_SESSION['promo_flash']);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $act = (string) ($_POST['act'] ?? '');
    $done = null;

    // ---------------------------------------------------------------- sale prices
    if ($act === 'save_sale') {
        $tab = 'sales';
        $target = ($_POST['target'] ?? 'product') === 'category' ? 'category' : 'product';
        $type = ($_POST['promo_type'] ?? '') === 'amount' ? 'amount' : 'percent';
        $value = round((float) ($_POST['promo_value'] ?? 0), 2);
        $starts = $pr_dt((string) ($_POST['starts_at'] ?? ''));
        $ends = $pr_dt((string) ($_POST['ends_at'] ?? ''));

        $ids = [];
        if ($target === 'product') {
            $pid = (int) ($_POST['product_id'] ?? 0);
            if (!isset($pr_products[$pid])) {
                $errors[] = 'Choose a product.';
            } else {
                $ids[] = $pid;
            }
        } else {
            $cat = strtolower(trim((string) ($_POST['category'] ?? '')));
            foreach ($pr_products as $pid => $p) {
                if (in_array($cat, array_map('strtolower', $pr_split_cats($p['category'])), true)) {
                    $ids[] = $pid;
                }
            }
            if (!$ids) {
                $errors[] = 'That category has no products.';
            }
        }
        if ($type === 'percent' && ($value < 1 || $value > 95)) {
            $errors[] = 'Percent off must be between 1 and 95.';
        }
        if ($type === 'amount' && $value <= 0) {
            $errors[] = 'Enter how many pesos to take off.';
        }
        if ($starts && $ends && strtotime($ends) <= strtotime($starts)) {
            $errors[] = 'The end must be after the start.';
        }
        if ($ends && strtotime($ends) <= time()) {
            $errors[] = 'The end date is already in the past.';
        }

        if (!$errors) {
            $skipped = [];
            $stmt = $conn->prepare("UPDATE tbl_product_inventory SET promo_type = ?, promo_value = ?, promo_starts_at = ?, promo_ends_at = ?, updated_at = NOW() WHERE id = ?");
            $applied = 0;
            foreach ($ids as $pid) {
                if ($type === 'amount' && $value >= (float) $pr_products[$pid]['min_price']) {
                    $skipped[] = $pr_products[$pid]['name'];
                    continue;
                }
                $stmt->bind_param('sdssi', $type, $value, $starts, $ends, $pid);
                $stmt->execute();
                $applied++;
            }
            $stmt->close();
            $label = $type === 'percent' ? rtrim(rtrim(number_format($value, 2), '0'), '.') . '% off' : '₱' . number_format($value, 2) . ' off';
            if ($applied === 0) {
                $errors[] = "₱" . number_format($value, 2) . " off is more than the price of every selected product. Use a smaller amount.";
            } else {
                $what = $target === 'product' ? $pr_products[$ids[0]]['name'] : $applied . ' product(s) in ' . trim((string) $_POST['category']);
                $pr_log("Sale price $label on $what" . ($starts ? " from $starts" : '') . ($ends ? " until $ends" : ''));
                $msg = "Sale added: $label on $what.";
                if ($skipped) {
                    $msg .= ' Skipped (discount is bigger than their price): ' . implode(', ', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? '…' : '') . '.';
                }
                $done = ['ok', $msg];
            }
        }
    } elseif ($act === 'pull_sale' || $act === 'pull_all_sales') {
        $tab = 'sales';
        if ($act === 'pull_sale') {
            $pid = (int) ($_POST['product_id'] ?? 0);
            $stmt = $conn->prepare("UPDATE tbl_product_inventory SET promo_type = NULL, promo_value = NULL, promo_starts_at = NULL, promo_ends_at = NULL, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $pid);
            $stmt->execute();
            $stmt->close();
            $name = $pr_products[$pid]['name'] ?? ('#' . $pid);
            $pr_log("Pulled sale price on $name");
            $done = ['ok', "Sale pulled. $name is back to its regular price."];
        } else {
            $conn->query("UPDATE tbl_product_inventory SET promo_type = NULL, promo_value = NULL, promo_starts_at = NULL, promo_ends_at = NULL, updated_at = NOW() WHERE promo_type IS NOT NULL");
            $n = $conn->affected_rows;
            $pr_log("Pulled all sale prices ($n products)");
            $done = ['ok', "All sales pulled ($n products back to regular price)."];
        }

    // ---------------------------------------------------------------- vouchers
    } elseif ($act === 'create_voucher') {
        $tab = 'vouchers';
        $audience = in_array($_POST['audience'] ?? '', ['public', 'customers', 'all_customers'], true) ? $_POST['audience'] : 'public';
        $dtype = in_array($_POST['discount_type'] ?? '', ['fixed', 'percent', 'free_shipping'], true) ? $_POST['discount_type'] : 'fixed';
        $dvalue = $dtype === 'free_shipping' ? 0.0 : round((float) ($_POST['discount_value'] ?? 0), 2);
        $min = (float) ($_POST['min_order_amount'] ?? 0) > 0 ? round((float) $_POST['min_order_amount'], 2) : null;
        $expires = $pr_dt((string) ($_POST['expires_at'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['code'] ?? '')));

        if ($desc === '') {
            $errors[] = 'Give the promo a name (customers see it).';
        }
        if ($dtype === 'percent' && ($dvalue < 1 || $dvalue > 100)) {
            $errors[] = 'Percent off must be between 1 and 100.';
        }
        if ($dtype === 'fixed' && $dvalue <= 0) {
            $errors[] = 'Enter the peso amount off.';
        }
        if ($expires && strtotime($expires) <= time()) {
            $errors[] = 'The expiry is already in the past.';
        }
        $desc = mb_substr($desc, 0, 255);

        if ($audience === 'public') {
            if ($code === '') {
                $code = voucher_generate_code($conn);
            } elseif (strlen($code) < 4) {
                $errors[] = 'The code needs at least 4 letters/numbers.';
            } else {
                $chk = $conn->prepare("SELECT 1 FROM tbl_vouchers WHERE code = ? LIMIT 1");
                $chk->bind_param('s', $code);
                $chk->execute();
                if ($chk->get_result()->fetch_row()) {
                    $errors[] = "The code $code is already used. Pick another one.";
                }
                $chk->close();
            }
            $limit = (int) ($_POST['usage_limit'] ?? 0) > 0 ? (int) $_POST['usage_limit'] : null;
            $per = (int) ($_POST['per_customer_limit'] ?? 0) > 0 ? (int) $_POST['per_customer_limit'] : null;
            if (!$errors) {
                $kind = 'public';
                $stmt = $conn->prepare(
                    "INSERT INTO tbl_vouchers (code, description, discount_type, discount_value, min_order_amount, usage_limit, per_customer_limit, active, expires_at, promo_kind, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)"
                );
                $stmt->bind_param('sssddiissi', $code, $desc, $dtype, $dvalue, $min, $limit, $per, $expires, $kind, $user_id);
                $stmt->execute();
                $stmt->close();
                $pr_log("Created promo code $code ($desc)");
                $done = ['ok', "Promo code $code is live. Customers can type it at checkout."];
            }
        } else {
            $cids = [];
            if ($audience === 'all_customers') {
                $r = $conn->query("SELECT id FROM customers");
                while ($r && ($row = $r->fetch_row())) {
                    $cids[] = (int) $row[0];
                }
            } else {
                $cids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['customer_ids'] ?? [])))));
            }
            if (!$cids) {
                $errors[] = 'Choose at least one customer.';
            }
            if (!$errors) {
                $batch = 'B' . date('ymdHis') . strtoupper(bin2hex(random_bytes(2)));
                $prefix = $code !== '' ? $code : 'GIFT';
                $kind = 'assigned';
                $one = 1;
                $stmt = $conn->prepare(
                    "INSERT INTO tbl_vouchers (code, description, discount_type, discount_value, min_order_amount, usage_limit, owner_customer_id, active, expires_at, promo_kind, promo_batch, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)"
                );
                $given = 0;
                $offer = voucher_discount_label(['discount_type' => $dtype, 'discount_value' => $dvalue]);
                foreach ($cids as $cid) {
                    $vcode = voucher_generate_code($conn, $prefix);
                    $stmt->bind_param('sssddiisssi', $vcode, $desc, $dtype, $dvalue, $min, $one, $cid, $expires, $kind, $batch, $user_id);
                    if (!$stmt->execute()) {
                        continue;
                    }
                    $given++;
                    try {
                        notifications_create($conn, [
                            'customer_id'     => $cid,
                            'type'            => 'promo_voucher',
                            'channel'         => 'in_app',
                            'title'           => 'You got a voucher: ' . $offer,
                            'message'         => "$desc. Use code $vcode at checkout" . ($expires ? ' before ' . date('M j, Y g:i A', strtotime($expires)) : '') . '. See it on My Vouchers.',
                            'reference_table' => 'tbl_vouchers',
                            'reference_id'    => (int) $conn->insert_id,
                        ]);
                    } catch (Throwable $e) {
                        error_log('[promotions] notify failed: ' . $e->getMessage());
                    }
                }
                $stmt->close();
                $pr_log("Gave voucher \"$desc\" to $given customer(s) (batch $batch)");
                $done = ['ok', "Voucher given to $given customer(s). They were notified and can see it on My Vouchers."];
            }
        }
    } elseif (in_array($act, ['pull_voucher', 'restore_voucher', 'delete_voucher'], true)) {
        $tab = 'vouchers';
        $vid = (int) ($_POST['voucher_id'] ?? 0);
        $batch = (string) ($_POST['batch'] ?? '');
        $where = $batch !== '' ? 'promo_batch = ?' : 'id = ? AND promo_kind IS NOT NULL';
        $types = $batch !== '' ? 's' : 'i';
        $key = $batch !== '' ? $batch : $vid;
        if ($act === 'delete_voucher') {
            // Only promos nobody has used yet can be deleted; otherwise pull them instead (keeps order history readable).
            $c = $conn->prepare("SELECT COALESCE(SUM(used_count), 0) FROM tbl_vouchers WHERE $where");
            $c->bind_param($types, $key);
            $c->execute();
            $used = (int) $c->get_result()->fetch_row()[0];
            $c->close();
            if ($used > 0) {
                $errors[] = 'This promo was already used on orders, so it can only be pulled, not deleted.';
            } else {
                $stmt = $conn->prepare("DELETE FROM tbl_vouchers WHERE $where");
                $stmt->bind_param($types, $key);
                $stmt->execute();
                $stmt->close();
                $pr_log('Deleted voucher promo ' . ($batch !== '' ? "batch $batch" : "#$vid"));
                $done = ['ok', 'Promo deleted.'];
            }
        } else {
            $on = $act === 'restore_voucher' ? 1 : 0;
            $stmt = $conn->prepare("UPDATE tbl_vouchers SET active = ?, updated_at = NOW() WHERE $where");
            $stmt->bind_param('i' . $types, $on, $key);
            $stmt->execute();
            $stmt->close();
            $pr_log(($on ? 'Restored' : 'Pulled') . ' voucher promo ' . ($batch !== '' ? "batch $batch" : "#$vid"));
            $done = ['ok', $on ? 'Promo is active again.' : 'Promo pulled. Unused codes no longer work at checkout.'];
        }
    }

    if ($done && !$errors) {
        $_SESSION['promo_flash'] = $done;
        header('Location: manage_rewards.php?tab=' . $tab);
        exit();
    }
}

// Sale being edited (prefills the form)
$edit_sale = null;
if (isset($_GET['edit_sale']) && isset($pr_products[(int) $_GET['edit_sale']])) {
    $edit_sale = $pr_products[(int) $_GET['edit_sale']];
}
$sale_rows = array_filter($pr_products, static fn($p) => promo_state($p) !== 'none');
$active_sales = count(array_filter($sale_rows, static fn($p) => promo_state($p) === 'active'));

// Voucher promos: public codes one row each, given vouchers one row per batch.
$voucher_rows = [];
$vr = $conn->query(
    "SELECT MIN(id) AS id, promo_kind, MAX(promo_batch) AS promo_batch, MIN(code) AS code, MAX(description) AS description,
            MAX(discount_type) AS discount_type, MAX(discount_value) AS discount_value, MAX(min_order_amount) AS min_order_amount,
            MAX(usage_limit) AS usage_limit, MAX(per_customer_limit) AS per_customer_limit, SUM(used_count) AS used,
            COUNT(*) AS codes, MAX(active) AS any_active, MIN(active) AS all_active, MAX(expires_at) AS expires_at, MIN(created_at) AS created_at
     FROM tbl_vouchers
     WHERE promo_kind IN ('public', 'assigned')
     GROUP BY promo_kind, IF(promo_kind = 'assigned', promo_batch, id)
     ORDER BY created_at DESC"
);
while ($vr && ($row = $vr->fetch_assoc())) {
    $voucher_rows[] = $row;
}

$customers = [];
$cr = $conn->query("SELECT id, name, email FROM customers ORDER BY name ASC");
while ($cr && ($row = $cr->fetch_assoc())) {
    $customers[] = $row;
}

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$peso = static fn($v) => '₱' . number_format((float) $v, 2);
$state_badge = [
    'active'    => '<span class="ui-badge ui-badge-success"><i class="fas fa-bolt"></i> Running</span>',
    'scheduled' => '<span class="ui-badge ui-badge-info"><i class="fas fa-clock"></i> Scheduled</span>',
    'ended'     => '<span class="ui-badge ui-badge-neutral">Ended</span>',
];
$post = $errors ? $_POST : [];
