<?php
/** The "Sale Prices" / "Voucher Promos" tabs of Manage Rewards. Data comes from promotions_actions.php. */
?>
    <style>
        .pr-layout { grid-template-columns: minmax(320px, 1fr) minmax(0, 1.6fr); }
        @media (max-width: 1100px) { .pr-layout { grid-template-columns: minmax(0, 1fr); } }
        .pr-panel[hidden] { display: none; }
        .pr-was { color: var(--muted); font-size: .85em; }
        .pr-cust-box { max-height: 240px; overflow-y: auto; border: 1px solid var(--border); border-radius: 10px; padding: 6px 10px; background: var(--surface); }
        .pr-cust-box label { display: flex; padding: 5px 0; border-bottom: 1px solid var(--border); }
        .pr-cust-box label:last-child { border-bottom: 0; }
        .pr-cust-box small { color: var(--muted); margin-left: 4px; }
        .pr-pulled td { opacity: .6; }
        @media (min-width: 761px) { .pr-layout .ui-btn-group { flex-wrap: nowrap; } }
    </style>
        <?php if ($tab === 'sales'): ?>
        <?php
            $f = $edit_sale ?: [];
            $f_target = $post['target'] ?? 'product';
            $f_pid = (int) ($post['product_id'] ?? ($f['id'] ?? 0));
            $f_type = $post['promo_type'] ?? ($f['promo_type'] ?? 'percent');
            $f_value = $post['promo_value'] ?? ($f['promo_value'] ?? '');
            $f_start = $post['starts_at'] ?? $pr_input_dt($f['promo_starts_at'] ?? null);
            $f_end = $post['ends_at'] ?? $pr_input_dt($f['promo_ends_at'] ?? null);
        ?>
        <div class="ui-grid-main pr-layout">
            <section class="ui-card" id="sale-form">
                <div class="ui-card-head">
                    <div>
                        <h2 class="ui-card-title"><i class="fas <?php echo $edit_sale ? 'fa-edit' : 'fa-plus-circle'; ?>"></i> <?php echo $edit_sale ? 'Edit Sale' : 'Add Sale Price'; ?></h2>
                        <p class="ui-card-sub">The sale applies to every size of the product. Leave the dates empty to start now and run until you pull it.</p>
                    </div>
                </div>
                <form method="POST" class="ui-card-body ui-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="save_sale">
                    <div class="ui-form-grid">
                        <div class="ui-field span-2">
                            <label>Apply to</label>
                            <div class="ui-btn-group">
                                <label class="ui-check"><input type="radio" name="target" value="product" <?php echo $f_target !== 'category' ? 'checked' : ''; ?>> One product</label>
                                <label class="ui-check"><input type="radio" name="target" value="category" <?php echo $f_target === 'category' ? 'checked' : ''; ?> <?php echo $edit_sale ? 'disabled' : ''; ?>> A whole category</label>
                            </div>
                        </div>
                        <div class="ui-field span-2" data-target="product">
                            <label for="pr_product">Product *</label>
                            <select id="pr_product" name="product_id" class="ui-select">
                                <option value="">Choose a product…</option>
                                <?php foreach ($pr_products as $p): ?>
                                    <option value="<?php echo (int) $p['id']; ?>" <?php echo $f_pid === (int) $p['id'] ? 'selected' : ''; ?>><?php echo $h($p['name']); ?> (<?php echo (int) $p['variant_count'] > 0 ? 'from ' . $peso($p['min_price']) : $peso($p['price']); ?>)<?php echo promo_state($p) !== 'none' ? ' · on sale' : ''; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ui-field span-2" data-target="category" hidden>
                            <label for="pr_category">Category *</label>
                            <select id="pr_category" name="category" class="ui-select">
                                <?php foreach ($pr_categories as $c): ?>
                                    <option value="<?php echo $h($c); ?>" <?php echo ($post['category'] ?? '') === $c ? 'selected' : ''; ?>><?php echo $h($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="ui-hint">Replaces any sale those products already have.</p>
                        </div>
                        <div class="ui-field">
                            <label for="pr_type">Discount *</label>
                            <select id="pr_type" name="promo_type" class="ui-select">
                                <option value="percent" <?php echo $f_type === 'percent' ? 'selected' : ''; ?>>Percent off (%)</option>
                                <option value="amount" <?php echo $f_type === 'amount' ? 'selected' : ''; ?>>Peso amount off (₱)</option>
                            </select>
                        </div>
                        <div class="ui-field">
                            <label for="pr_value">Value *</label>
                            <input type="number" step="0.01" min="0.01" id="pr_value" name="promo_value" required class="ui-input" value="<?php echo $h($f_value); ?>" placeholder="e.g. 10">
                        </div>
                        <div class="ui-field">
                            <label for="pr_start">Starts</label>
                            <input type="datetime-local" id="pr_start" name="starts_at" class="ui-input" value="<?php echo $h($f_start); ?>">
                            <p class="ui-hint">Empty = right away.</p>
                        </div>
                        <div class="ui-field">
                            <label for="pr_end">Ends</label>
                            <input type="datetime-local" id="pr_end" name="ends_at" class="ui-input" value="<?php echo $h($f_end); ?>">
                            <p class="ui-hint">Empty = until you pull it.</p>
                        </div>
                    </div>
                    <div class="ui-form-actions">
                        <?php if ($edit_sale): ?><a href="manage_rewards.php?tab=sales" class="ui-btn">Cancel</a><?php endif; ?>
                        <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-tag"></i> <?php echo $edit_sale ? 'Update Sale' : 'Add Sale'; ?></button>
                    </div>
                </form>
            </section>

            <section class="ui-card">
                <div class="ui-card-head">
                    <h2 class="ui-card-title"><i class="fas fa-tags"></i> Products on Sale</h2>
                    <?php if ($sale_rows): ?>
                        <form method="POST" onsubmit="return confirm('Pull every sale? All products go back to their regular price.');" style="margin: 0;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="act" value="pull_all_sales">
                            <button type="submit" class="ui-btn ui-btn-sm ui-btn-danger-soft"><i class="fas fa-ban"></i> Pull all</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if (!$sale_rows): ?>
                    <div class="ui-empty"><i class="fas fa-tags"></i><strong>No sales yet</strong><p>Add a sale price using the form.</p></div>
                <?php else: ?>
                    <div class="ui-table-wrap">
                        <table class="ui-table ui-table-stack">
                            <thead><tr><th>Product</th><th>Sale</th><th class="num">Price</th><th>Schedule</th><th class="num">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($sale_rows as $p): $st = promo_state($p); ?>
                                    <tr>
                                        <td data-label="Product"><span><span class="ui-cell-main"><?php echo $h($p['name']); ?></span><span class="ui-cell-sub ui-mono"><?php echo $h($p['sku']); ?></span></span></td>
                                        <td data-label="Sale"><span class="ui-badge ui-badge-danger"><?php echo $h(promo_label($p)); ?></span> <?php echo $state_badge[$st]; ?></td>
                                        <td data-label="Price" class="num">
                                            <?php // price while the sale runs (also shown for scheduled/ended ones) ?>
                                            <strong><?php echo $peso(promo_apply((float) $p['price'], array_merge($p, ['promo_starts_at' => null, 'promo_ends_at' => null]))); ?></strong>
                                            <div class="pr-was"><s><?php echo $peso($p['price']); ?></s><?php echo (int) $p['variant_count'] > 0 ? ' · ' . (int) $p['variant_count'] . ' sizes' : ''; ?></div>
                                        </td>
                                        <td data-label="Schedule">
                                            <span class="ui-cell-sub">
                                                <?php echo $p['promo_starts_at'] ? 'From ' . date('M j, Y g:i A', strtotime($p['promo_starts_at'])) : 'Started'; ?><br>
                                                <?php echo $p['promo_ends_at'] ? 'Until ' . date('M j, Y g:i A', strtotime($p['promo_ends_at'])) : 'No end date'; ?>
                                            </span>
                                        </td>
                                        <td data-label="Actions" class="actions">
                                            <div class="ui-btn-group" style="justify-content: flex-end;">
                                                <a href="manage_rewards.php?tab=sales&amp;edit_sale=<?php echo (int) $p['id']; ?>#sale-form" class="ui-btn ui-btn-sm"><i class="fas fa-edit"></i> Edit</a>
                                                <form method="POST" onsubmit="return confirm('Pull this sale? The product goes back to its regular price.');" style="margin: 0;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="act" value="pull_sale">
                                                    <input type="hidden" name="product_id" value="<?php echo (int) $p['id']; ?>">
                                                    <button type="submit" class="ui-btn ui-btn-sm ui-btn-danger-soft"><i class="fas fa-ban"></i> Pull</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <?php elseif ($tab === 'vouchers'): ?>
        <?php
            $v_aud = $post['audience'] ?? 'public';
            $v_type = $post['discount_type'] ?? 'fixed';
            $v_sel = array_map('intval', (array) ($post['customer_ids'] ?? []));
        ?>
        <div class="ui-grid-main pr-layout">
            <section class="ui-card">
                <div class="ui-card-head">
                    <div>
                        <h2 class="ui-card-title"><i class="fas fa-plus-circle"></i> New Voucher Promo</h2>
                        <p class="ui-card-sub">Vouchers work on online orders at checkout.</p>
                    </div>
                </div>
                <form method="POST" class="ui-card-body ui-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="create_voucher">
                    <div class="ui-form-grid">
                        <div class="ui-field span-2">
                            <label>Who can use it</label>
                            <div class="ui-stack-sm">
                                <label class="ui-check"><input type="radio" name="audience" value="public" <?php echo $v_aud === 'public' ? 'checked' : ''; ?>> Public promo code (anyone who types it)</label>
                                <label class="ui-check"><input type="radio" name="audience" value="customers" <?php echo $v_aud === 'customers' ? 'checked' : ''; ?>> Give to chosen customers</label>
                                <label class="ui-check"><input type="radio" name="audience" value="all_customers" <?php echo $v_aud === 'all_customers' ? 'checked' : ''; ?>> Give to all customers (<?php echo count($customers); ?>)</label>
                            </div>
                        </div>
                        <div class="ui-field span-2">
                            <label for="vp_desc">Promo name *</label>
                            <input type="text" id="vp_desc" name="description" maxlength="255" required class="ui-input" placeholder="e.g. Payday Sale" value="<?php echo $h($post['description'] ?? ''); ?>">
                        </div>
                        <div class="ui-field span-2">
                            <label for="vp_code" data-aud-label="public">Code</label>
                            <input type="text" id="vp_code" name="code" maxlength="20" class="ui-input ui-mono" placeholder="e.g. PAYDAY50" value="<?php echo $h($post['code'] ?? ''); ?>" style="text-transform: uppercase;">
                            <p class="ui-hint" data-aud="public">Leave empty to make one up automatically.</p>
                            <p class="ui-hint" data-aud="given" hidden>Optional prefix. Each customer gets their own one-time code (e.g. GIFT-7KQ2XD).</p>
                        </div>
                        <div class="ui-field">
                            <label for="vp_type">Discount *</label>
                            <select id="vp_type" name="discount_type" class="ui-select">
                                <option value="fixed" <?php echo $v_type === 'fixed' ? 'selected' : ''; ?>>Peso amount off (₱)</option>
                                <option value="percent" <?php echo $v_type === 'percent' ? 'selected' : ''; ?>>Percent off (%)</option>
                                <option value="free_shipping" <?php echo $v_type === 'free_shipping' ? 'selected' : ''; ?>>Free delivery</option>
                            </select>
                        </div>
                        <div class="ui-field" data-value-field>
                            <label for="vp_value">Value *</label>
                            <input type="number" step="0.01" min="0" id="vp_value" name="discount_value" class="ui-input" value="<?php echo $h($post['discount_value'] ?? ''); ?>" placeholder="e.g. 50">
                        </div>
                        <div class="ui-field">
                            <label for="vp_min">Minimum spend (₱)</label>
                            <input type="number" step="0.01" min="0" id="vp_min" name="min_order_amount" class="ui-input" value="<?php echo $h($post['min_order_amount'] ?? ''); ?>" placeholder="None">
                        </div>
                        <div class="ui-field">
                            <label for="vp_exp">Expires</label>
                            <input type="datetime-local" id="vp_exp" name="expires_at" class="ui-input" value="<?php echo $h($post['expires_at'] ?? ''); ?>">
                            <p class="ui-hint">Empty = until you pull it.</p>
                        </div>
                        <div class="ui-field" data-aud="public">
                            <label for="vp_limit">Total uses</label>
                            <input type="number" min="0" id="vp_limit" name="usage_limit" class="ui-input" value="<?php echo $h($post['usage_limit'] ?? ''); ?>" placeholder="Unlimited">
                        </div>
                        <div class="ui-field" data-aud="public">
                            <label for="vp_per">Uses per customer</label>
                            <input type="number" min="0" id="vp_per" name="per_customer_limit" class="ui-input" value="<?php echo $h($post['per_customer_limit'] ?? '1'); ?>">
                            <p class="ui-hint">0 = no limit.</p>
                        </div>
                        <div class="ui-field span-2" data-aud="customers" hidden>
                            <label for="vp_find">Customers *</label>
                            <input type="search" id="vp_find" class="ui-input" placeholder="Search name or email…" style="margin-bottom: 8px;">
                            <div class="pr-cust-box" id="vp_customers">
                                <?php foreach ($customers as $c): ?>
                                    <label class="ui-check" data-search="<?php echo $h(strtolower($c['name'] . ' ' . $c['email'])); ?>"><input type="checkbox" name="customer_ids[]" value="<?php echo (int) $c['id']; ?>" <?php echo in_array((int) $c['id'], $v_sel, true) ? 'checked' : ''; ?>> <?php echo $h($c['name']); ?> <small><?php echo $h($c['email']); ?></small></label>
                                <?php endforeach; ?>
                            </div>
                            <p class="ui-hint"><span id="vp_count">0</span> selected. Each gets a notification.</p>
                        </div>
                    </div>
                    <div class="ui-form-actions">
                        <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-ticket"></i> Create Promo</button>
                    </div>
                </form>
            </section>

            <section class="ui-card">
                <div class="ui-card-head">
                    <h2 class="ui-card-title"><i class="fas fa-ticket"></i> Voucher Promos</h2>
                </div>
                <?php if (!$voucher_rows): ?>
                    <div class="ui-empty"><i class="fas fa-ticket"></i><strong>No voucher promos yet</strong><p>Create a promo code or give vouchers to customers.</p></div>
                <?php else: ?>
                    <div class="ui-table-wrap">
                        <table class="ui-table ui-table-stack">
                            <thead><tr><th>Promo</th><th>Discount</th><th>Used</th><th>Status</th><th class="num">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($voucher_rows as $v):
                                    $given = $v['promo_kind'] === 'assigned';
                                    $expired = $v['expires_at'] && strtotime($v['expires_at']) <= time();
                                    $pulled = (int) $v['any_active'] === 0;
                                ?>
                                    <tr class="<?php echo $pulled ? 'pr-pulled' : ''; ?>">
                                        <td data-label="Promo"><span>
                                            <span class="ui-cell-main"><?php echo $h($v['description']); ?></span>
                                            <span class="ui-cell-sub"><?php echo $given ? 'Given to ' . (int) $v['codes'] . ' customer(s)' : '<span class="ui-mono">' . $h($v['code']) . '</span> · public'; ?></span>
                                        </span></td>
                                        <td data-label="Discount">
                                            <?php echo $h(voucher_discount_label($v)); ?>
                                            <?php if ((float) $v['min_order_amount'] > 0): ?><div class="ui-cell-sub">Min. <?php echo $peso($v['min_order_amount']); ?></div><?php endif; ?>
                                        </td>
                                        <td data-label="Used">
                                            <?php echo (int) $v['used']; ?><?php echo $given ? ' / ' . (int) $v['codes'] : ((int) $v['usage_limit'] > 0 ? ' / ' . (int) $v['usage_limit'] : ''); ?>
                                            <?php if (!$given && (int) $v['per_customer_limit'] > 0): ?><div class="ui-cell-sub"><?php echo (int) $v['per_customer_limit']; ?>× per customer</div><?php endif; ?>
                                        </td>
                                        <td data-label="Status">
                                            <?php if ($pulled): ?><span class="ui-badge ui-badge-neutral">Pulled</span>
                                            <?php elseif ($expired): ?><span class="ui-badge ui-badge-warning">Expired</span>
                                            <?php else: ?><span class="ui-badge ui-badge-success">Active</span><?php endif; ?>
                                            <div class="ui-cell-sub"><?php echo $v['expires_at'] ? 'Until ' . date('M j, Y g:i A', strtotime($v['expires_at'])) : 'No expiry'; ?></div>
                                        </td>
                                        <td data-label="Actions" class="actions">
                                            <div class="ui-btn-group" style="justify-content: flex-end;">
                                                <form method="POST" style="margin: 0;" <?php echo $pulled ? '' : 'onsubmit="return confirm(\'Pull this promo? Unused codes stop working at checkout.\');"'; ?>>
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="act" value="<?php echo $pulled ? 'restore_voucher' : 'pull_voucher'; ?>">
                                                    <input type="hidden" name="<?php echo $given ? 'batch' : 'voucher_id'; ?>" value="<?php echo $h($given ? $v['promo_batch'] : $v['id']); ?>">
                                                    <?php if ($pulled): ?>
                                                        <button type="submit" class="ui-btn ui-btn-sm"><i class="fas fa-rotate-left"></i> Restore</button>
                                                    <?php else: ?>
                                                        <button type="submit" class="ui-btn ui-btn-sm ui-btn-danger-soft"><i class="fas fa-ban"></i> Pull</button>
                                                    <?php endif; ?>
                                                </form>
                                                <?php if ((int) $v['used'] === 0): ?>
                                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('Delete this promo for good?');">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="act" value="delete_voucher">
                                                        <input type="hidden" name="<?php echo $given ? 'batch' : 'voucher_id'; ?>" value="<?php echo $h($given ? $v['promo_batch'] : $v['id']); ?>">
                                                        <button type="submit" class="ui-btn ui-btn-sm ui-btn-ghost" title="Delete"><i class="fas fa-trash"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php endif; ?>
    <script>
    (function () {
        // Sale form: product vs category
        var targets = document.querySelectorAll('input[name="target"]');
        function syncTarget() {
            var t = (document.querySelector('input[name="target"]:checked') || {}).value || 'product';
            document.querySelectorAll('[data-target]').forEach(function (el) { el.hidden = el.dataset.target !== t; });
            var pr = document.getElementById('pr_product');
            if (pr) pr.required = t === 'product';
        }
        targets.forEach(function (r) { r.addEventListener('change', syncTarget); });
        if (targets.length) syncTarget();

        // Voucher form: audience + discount type
        var auds = document.querySelectorAll('input[name="audience"]');
        function syncAudience() {
            var a = (document.querySelector('input[name="audience"]:checked') || {}).value || 'public';
            document.querySelectorAll('[data-aud]').forEach(function (el) {
                var k = el.dataset.aud;
                el.hidden = !(k === a || (k === 'given' && a !== 'public'));
            });
            var lbl = document.querySelector('[data-aud-label]');
            if (lbl) lbl.textContent = a === 'public' ? 'Code' : 'Code prefix';
        }
        auds.forEach(function (r) { r.addEventListener('change', syncAudience); });
        if (auds.length) syncAudience();

        var vt = document.getElementById('vp_type');
        function syncType() {
            var f = document.querySelector('[data-value-field]');
            if (!vt || !f) return;
            f.hidden = vt.value === 'free_shipping';
            document.getElementById('vp_value').required = vt.value !== 'free_shipping';
        }
        if (vt) { vt.addEventListener('change', syncType); syncType(); }

        // Customer picker search + count
        var find = document.getElementById('vp_find'), box = document.getElementById('vp_customers'), count = document.getElementById('vp_count');
        function syncCount() { if (count && box) count.textContent = box.querySelectorAll('input:checked').length; }
        if (find && box) {
            find.addEventListener('input', function () {
                var q = find.value.trim().toLowerCase();
                box.querySelectorAll('label').forEach(function (l) { l.style.display = !q || l.dataset.search.indexOf(q) !== -1 ? '' : 'none'; });
            });
            box.addEventListener('change', syncCount);
            syncCount();
        }
    })();
    </script>
