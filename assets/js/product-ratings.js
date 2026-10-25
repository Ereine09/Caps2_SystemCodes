/* Product Ratings - shared behaviour (filter chips, photo lightbox, and the
   client-side renderer used by the customer product modal). Server-rendered
   pages (Admin/Staff) only need PRRatings.init(scopeEl). */
(function (w) {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function stars(n) {
        n = Math.max(0, Math.min(5, Math.round(Number(n) || 0)));
        return '<span class="pr-stars" role="img" aria-label="' + n + ' out of 5">' +
            '★'.repeat(n) + '<span class="pr-stars-off">' + '★'.repeat(5 - n) + '</span></span>';
    }

    function lightbox(src) {
        var box = document.createElement('div');
        box.className = 'pr-lightbox';
        box.innerHTML = '<img alt="Review photo" src="' + esc(src) + '">';
        function close() { box.remove(); document.removeEventListener('keydown', onKey, true); }
        function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } }
        box.addEventListener('click', close);
        document.addEventListener('keydown', onKey, true);
        document.body.appendChild(box);
    }

    function matches(item, filter) {
        if (filter === 'all') return true;
        if (filter === 'comments') return item.getAttribute('data-comment') === '1';
        if (filter === 'media') return item.getAttribute('data-media') === '1';
        if (filter.indexOf('star-') === 0) return item.getAttribute('data-rating') === filter.slice(5);
        return true;
    }

    function applyFilter(scope, filter) {
        var shown = 0;
        scope.querySelectorAll('.pr-chip').forEach(function (c) {
            c.classList.toggle('active', c.getAttribute('data-filter') === filter);
        });
        scope.querySelectorAll('.pr-item').forEach(function (item) {
            var ok = matches(item, filter);
            item.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        var empty = scope.querySelector('.pr-filter-empty');
        if (empty) empty.style.display = shown === 0 ? '' : 'none';
    }

    function init(scope) {
        if (!scope || scope.__prReady) return;
        scope.__prReady = true;
        scope.addEventListener('click', function (e) {
            var chip = e.target.closest('.pr-chip');
            if (chip && scope.contains(chip)) { applyFilter(scope, chip.getAttribute('data-filter')); return; }
            var thumb = e.target.closest('.pr-thumb');
            if (thumb && scope.contains(thumb)) { lightbox(thumb.getAttribute('data-full')); }
        });
    }

    // Customer product modal: data = { summary:{avg,count,stars:{1..5},with_comments,with_media}, reviews:[...] }
    function renderCustomer(container, data) {
        var s = data.summary || {};
        var count = Number(s.count) || 0;
        var avg = Number(s.avg) || 0;
        if (count === 0) {
            container.innerHTML = '<h3 class="pr-title">Product Ratings</h3><div class="pr-empty">No reviews yet for this product.</div>';
            return;
        }
        var st = s.stars || {};
        var chips = '<button type="button" class="pr-chip active" data-filter="all">All</button>';
        for (var i = 5; i >= 1; i--) {
            chips += '<button type="button" class="pr-chip" data-filter="star-' + i + '">' + i + ' Star (' + (Number(st[i]) || 0) + ')</button>';
        }
        chips += '<button type="button" class="pr-chip" data-filter="comments">With Comments (' + (Number(s.with_comments) || 0) + ')</button>';
        chips += '<button type="button" class="pr-chip" data-filter="media">With Media (' + (Number(s.with_media) || 0) + ')</button>';

        var rows = (data.reviews || []).map(function (r) {
            var text = String(r.text || '').trim();
            var imgs = r.images || [];
            var meta = esc(String(r.created_at || '').slice(0, 16));
            if (r.variation) meta += ' | Variation: ' + esc(r.variation);
            return '<div class="pr-item" data-rating="' + esc(r.rating) + '" data-comment="' + (text ? 1 : 0) + '" data-media="' + (imgs.length ? 1 : 0) + '">' +
                '<div class="pr-avatar">' + esc(String(r.name || '?').charAt(0)) + '</div>' +
                '<div class="pr-body">' +
                '<div class="pr-user">' + esc(r.name) + '</div>' +
                '<div class="pr-stars-row">' + stars(r.rating) + '</div>' +
                '<div class="pr-meta">' + meta + '</div>' +
                (text ? '<div class="pr-text">' + esc(text) + '</div>' : '') +
                (imgs.length ? '<div class="pr-media">' + imgs.map(function (u) {
                    return '<button type="button" class="pr-thumb" data-full="' + esc(u) + '" aria-label="View photo"><img loading="lazy" alt="" src="' + esc(u) + '"></button>';
                }).join('') + '</div>' : '') +
                (r.reply && r.reply.text ? '<div class="pr-reply">' +
                    '<div class="pr-reply-head"><i class="fas fa-store"></i> <strong>Shop reply</strong> <time>' + esc(String(r.reply.created_at || '').slice(0, 10)) + (r.reply.edited ? ' &middot; edited' : '') + '</time></div>' +
                    '<p class="pr-reply-text">' + esc(r.reply.text) + '</p></div>' : '') +
                '</div></div>';
        }).join('');

        container.innerHTML =
            '<h3 class="pr-title">Product Ratings</h3>' +
            '<div class="pr-summary"><div class="pr-score"><div class="pr-score-num">' + avg.toFixed(1) + ' <small>out of 5</small></div>' + stars(avg) + '</div>' +
            '<div class="pr-chips">' + chips + '</div></div>' +
            '<div class="pr-list">' + rows + '<div class="pr-empty pr-filter-empty" style="display:none">No reviews match this filter.</div></div>';
        init(container);
    }

    w.PRRatings = { init: init, renderCustomer: renderCustomer, applyFilter: applyFilter };
})(window);
