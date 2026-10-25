<?php
/**
 * Earnings & performance (Flutter Earnings / Performance / Drive Report) —
 * rider_earnings_api.php (summary, history, weekly/monthly chart) and
 * rider_ai_insight_api.php (AI tip, fetched only when the rider asks for it).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_rider();

rider_page_start('Earnings', ['back' => BASE_URL . '/rider/profile.php']);
?>
<div class="rd-stack" id="view">
    <div class="rd-skel" style="height:130px;"></div>
    <div class="rd-skel" style="height:90px;"></div>
    <div class="rd-skel" style="height:220px;"></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var R = window.Rider, esc = R.esc;
    var view = document.getElementById('view');
    var data = null, period = 'monthly', shown = 15;

    function bars(chart) {
        var vals = (chart && chart.values) || [], labels = (chart && chart.labels) || [];
        var max = Math.max.apply(null, vals.concat([1]));
        return '<div class="rd-bars" role="img" aria-label="Earnings chart">' + vals.map(function (v, i) {
            return '<div class="rd-bar" title="' + esc(labels[i]) + ': ' + R.peso(v) + '"><div class="rd-bar-fill" style="height:' + Math.max(2, Math.round(v / max * 100)) + '%;"></div><span>' + esc(labels[i]) + '</span></div>';
        }).join('') + '</div>';
    }
    function render() {
        var s = data.summary || {};
        var hist = data.earnings_history || [];
        var dl = data.daily || {}, days = dl.days || [];
        function ymd(dt) { return dt.getFullYear() + '-' + ('0' + (dt.getMonth() + 1)).slice(-2) + '-' + ('0' + dt.getDate()).slice(-2); } // local date (PH time)
        var todayStr = ymd(new Date()), yest = ymd(new Date(Date.now() - 864e5));
        function dayName(dt) {
            if (dt === todayStr) return 'Today';
            if (dt === yest) return 'Yesterday';
            return new Date(dt + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
        }
        var pgD = R.paged('earn-days', days), pgH = R.paged('earn-hist', hist);
        view.innerHTML =
            // Today first: what the rider made today, this week and this month
            '<section class="rd-hero"><div class="rd-hero-label">Today\'s earnings</div><div class="rd-hero-value">' + R.peso((dl.today || {}).earnings || 0) + '</div>' +
            '<div class="rd-hero-sub">' + (+(dl.today || {}).deliveries || 0) + ' deliveries today · delivery fees + tips</div></section>' +
            '<section class="rd-stats" style="grid-template-columns:repeat(3,minmax(0,1fr));">' +
            '<div class="rd-stat rd-tone-success"><span class="rd-stat-top">This week</span><span class="rd-stat-value" style="font-size:1.05rem;">' + R.peso((dl.week || {}).earnings || 0) + '</span></div>' +
            '<div class="rd-stat rd-tone-info"><span class="rd-stat-top">This month</span><span class="rd-stat-value" style="font-size:1.05rem;">' + R.peso((dl.month || {}).earnings || 0) + '</span></div>' +
            '<div class="rd-stat rd-tone-primary"><span class="rd-stat-top">All time</span><span class="rd-stat-value" style="font-size:1.05rem;">' + R.peso(s.total_earnings) + '</span></div></section>' +
            '<div class="rd-section-title">Daily earnings</div>' +
            (days.length ? '<section class="rd-card" style="padding:0;overflow:hidden;">' + pgD.items.map(function (d) {
                return '<div class="rd-day' + (d.date === todayStr ? ' is-today' : '') + '"><span class="rd-day-date"><strong>' + esc(dayName(d.date)) + '</strong>' +
                    '<small>' + (+d.deliveries) + ' ' + (+d.deliveries === 1 ? 'delivery' : 'deliveries') + ' · fees ' + R.peso(d.fees) + (R.num(d.tips) ? ' · tips ' + R.peso(d.tips) : '') + '</small></span>' +
                    '<span class="rd-day-amt">' + R.peso(d.earnings) + '</span></div>';
            }).join('') + '</section>' + R.pager(pgD)
                : '<div class="rd-card rd-empty"><i class="fas fa-calendar-day"></i><strong>No earnings in the last 90 days</strong>Each delivered order adds its delivery fee here, by day.</div>') +
            '<p class="rd-help" style="margin:-4px 2px 0;">You earn the delivery fee of every order you deliver (₱15 for the first km, ₱5 more per km), plus tips. Free-delivery orders still pay you the fee.</p>' +
            '<section class="rd-stats" style="grid-template-columns:repeat(3,minmax(0,1fr));">' +
            '<div class="rd-stat rd-tone-primary"><span class="rd-stat-top">Deliveries</span><span class="rd-stat-value" style="font-size:1.25rem;">' + (+s.total_deliveries || 0) + '</span></div>' +
            '<div class="rd-stat rd-tone-info"><span class="rd-stat-top">Fees</span><span class="rd-stat-value" style="font-size:1.05rem;">' + R.peso(s.total_delivery_fees) + '</span></div>' +
            '<div class="rd-stat rd-tone-success"><span class="rd-stat-top">Tips</span><span class="rd-stat-value" style="font-size:1.05rem;">' + R.peso(s.total_tips) + '</span></div></section>' +
            '<section class="rd-card"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;"><h2 class="rd-card-title" style="margin:0;">Earnings trend</h2>' +
            '<div class="rd-chips"><button type="button" class="rd-chip" data-period="weekly" aria-pressed="' + (period === 'weekly') + '" style="min-height:36px;">Weekly</button>' +
            '<button type="button" class="rd-chip" data-period="monthly" aria-pressed="' + (period === 'monthly') + '" style="min-height:36px;">Monthly</button></div></div>' +
            '<div id="chart">' + bars(data.chart) + '</div></section>' +
            '<section class="rd-card"><h2 class="rd-card-title"><span class="rd-ico"><i class="fas fa-lightbulb"></i></span>Performance tip</h2>' +
            '<p class="rd-muted" id="ai-text" style="margin:0 0 12px;">Get a short tip based on your deliveries, earnings and pending COD.</p>' +
            '<button type="button" class="rd-btn rd-btn-outline rd-btn-sm" id="ai-btn"><i class="fas fa-wand-magic-sparkles"></i> Get tip</button></section>' +
            '<div class="rd-section-title">Completed deliveries (' + hist.length + ')</div>' +
            (hist.length ? '<section class="rd-list">' + pgH.items.map(function (h) {
                return '<a class="rd-list-item" href="order.php?id=' + (+h.order_id) + '"><i class="fas fa-circle-check" style="color:var(--success);"></i>' +
                    '<span><strong style="display:block;">#' + esc(h.order_number) + '</strong><small>' + esc(R.fmtDate(h.created_at, false)) + ' · fee ' + R.peso(h.delivery_fee) + (R.num(h.tip) ? ' · tip ' + R.peso(h.tip) : '') + '</small></span>' +
                    '<strong>' + R.peso(h.earnings) + '</strong></a>';
            }).join('') + '</section>' + R.pager(pgH)
                : '<div class="rd-card rd-empty"><i class="fas fa-chart-line"></i><strong>No completed deliveries yet</strong>Your earnings appear here after your first delivery.</div>');
    }
    function load() {
        return R.call('rider_earnings_api.php').then(function (res) { data = res.data || {}; period = 'monthly'; render(); }, function (err) {
            if (!data) view.innerHTML = '<div class="rd-card">' + R.errorBox(err) + '</div>';
        });
    }
    R.onPager(view, render);   // 10 per page (daily list + completed deliveries)
    view.addEventListener('click', function (e) {
        if (e.target.closest('[data-retry]')) { load(); return; }
        if (e.target.closest('#more')) { shown += 15; render(); return; }
        var chip = e.target.closest('[data-period]');
        if (chip && chip.dataset.period !== period) {
            period = chip.dataset.period;
            view.querySelectorAll('[data-period]').forEach(function (c) { c.setAttribute('aria-pressed', c.dataset.period === period ? 'true' : 'false'); });
            R.call('rider_earnings_api.php?action=get_chart&period=' + period).then(function (res) {
                data.chart = res.data;
                document.getElementById('chart').innerHTML = bars(res.data);
            }, R.fail);
            return;
        }
        var ai = e.target.closest('#ai-btn');
        if (ai) {
            R.guarded(ai, 'Thinking…', function () { return R.call('rider_ai_insight_api.php', { timeout: 45000 }); }).then(function (res) {
                document.getElementById('ai-text').textContent = (res.data && res.data.insight) || 'No tip available right now.';
                document.getElementById('ai-text').classList.remove('rd-muted');
            }, R.fail);
        }
    });
    load();
    R.pullToRefresh(load);
});
</script>
<?php rider_page_end(''); ?>
