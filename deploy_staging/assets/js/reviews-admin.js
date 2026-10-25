/* Admin / Staff Customer Reviews - client-side filtering, search, sort and
   "show more" paging over the review rows the page already rendered.
   Replies are posted with fetch() by the page script (see reviews.php); after a change the page
   calls RVAdmin.applyFresh() to bring in just the cards / counters that changed (no page reload). */
(function (w) {
    'use strict';

    var PAGE_SIZE = 10;

    function init(root) {
        if (!root || root.__rvReady) return;

        var list = root.querySelector('#rvList');
        if (!list) return; // empty-state page: nothing to wire up
        root.__rvReady = true;

        var search = root.querySelector('#rvSearch');
        var productSel = root.querySelector('#rvProduct');
        var sortSel = root.querySelector('#rvSort');
        var countEl = root.querySelector('#rvCount');
        var clearBtn = root.querySelector('#rvClear');
        var moreBtn = root.querySelector('#rvMore');
        var emptyEl = root.querySelector('#rvEmpty');

        var state = { star: '', flag: '', q: '', product: '', sort: 'newest', limit: PAGE_SIZE };

        // Cards can be added / replaced / removed by a live update, so never keep a stale array.
        function items() { return Array.prototype.slice.call(list.querySelectorAll('.rv-item')); }
        function num(el, attr) { return Number(el.getAttribute(attr)) || 0; }

        function matches(el) {
            if (state.star && el.getAttribute('data-rating') !== state.star) return false;
            if (state.flag === 'comments' && el.getAttribute('data-comment') !== '1') return false;
            if (state.flag === 'media' && el.getAttribute('data-media') !== '1') return false;
            if (state.flag === 'unreplied' && el.getAttribute('data-replied') === '1') return false;
            if (state.product && el.getAttribute('data-product') !== state.product) return false;
            if (state.q && el.getAttribute('data-search').indexOf(state.q) === -1) return false;
            return true;
        }

        function compare(a, b) {
            switch (state.sort) {
                case 'oldest':  return num(a, 'data-ts') - num(b, 'data-ts');
                case 'highest': return num(b, 'data-rating') - num(a, 'data-rating') || num(b, 'data-ts') - num(a, 'data-ts');
                case 'lowest':  return num(a, 'data-rating') - num(b, 'data-rating') || num(b, 'data-ts') - num(a, 'data-ts');
                default:        return num(b, 'data-ts') - num(a, 'data-ts');
            }
        }

        function isFiltered() {
            return !!(state.star || state.flag || state.q || state.product);
        }

        function render() {
            var all = items();
            var matched = all.filter(matches).sort(compare);

            // Reorder in the DOM, then show only the first `limit`.
            var frag = document.createDocumentFragment();
            all.forEach(function (el) { el.hidden = true; });
            matched.forEach(function (el, i) {
                el.hidden = i >= state.limit;
                frag.appendChild(el);
            });
            all.filter(function (el) { return matched.indexOf(el) === -1; }).forEach(function (el) { frag.appendChild(el); });
            list.appendChild(frag);

            var shown = Math.min(matched.length, state.limit);
            countEl.textContent = matched.length === 0
                ? 'No reviews found'
                : 'Showing ' + shown + ' of ' + matched.length + (matched.length === 1 ? ' review' : ' reviews');
            emptyEl.hidden = matched.length !== 0;
            list.hidden = matched.length === 0;
            moreBtn.hidden = matched.length <= state.limit;
            if (!moreBtn.hidden) {
                moreBtn.textContent = 'Show more reviews (' + (matched.length - state.limit) + ' left)';
            }
            clearBtn.hidden = !isFiltered();

            root.querySelectorAll('.rv-bar').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-star') === state.star ? 'true' : 'false'); });
            root.querySelectorAll('.rv-toggle').forEach(function (t) { t.setAttribute('aria-pressed', t.getAttribute('data-flag') === state.flag ? 'true' : 'false'); });
        }

        function change(patch) {
            Object.keys(patch).forEach(function (k) { state[k] = patch[k]; });
            state.limit = PAGE_SIZE; // any filter/sort change starts again from the first page
            render();
        }

        // Rating bars and quick-filter toggles: delegated, so they keep working after a live update
        // replaces the overview panel.
        root.addEventListener('click', function (e) {
            var bar = e.target.closest ? e.target.closest('.rv-bar') : null;
            if (bar && root.contains(bar) && !bar.disabled) {
                var star = bar.getAttribute('data-star');
                change({ star: state.star === star ? '' : star });
                return;
            }
            var tog = e.target.closest ? e.target.closest('.rv-toggle') : null;
            if (tog && root.contains(tog)) {
                var flag = tog.getAttribute('data-flag');
                change({ flag: state.flag === flag ? '' : flag });
            }
        });

        var timer = null;
        search.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { change({ q: search.value.trim().toLowerCase() }); }, 150);
        });
        productSel.addEventListener('change', function () { change({ product: productSel.value }); });
        sortSel.addEventListener('change', function () { change({ sort: sortSel.value }); });
        moreBtn.addEventListener('click', function () { state.limit += PAGE_SIZE; render(); });
        clearBtn.addEventListener('click', function () {
            search.value = '';
            productSel.value = '';
            change({ star: '', flag: '', q: '', product: '' });
        });

        /**
         * Bring in what changed on the server (fresh = the #reviewsRatings element of a freshly fetched
         * page). Overview numbers are refreshed, cards are synced by their id (changed -> replaced, new ->
         * added, gone -> removed, unchanged -> untouched), then the current filter / sort is re-applied.
         * opts.forceId: a card that must be replaced even if its reply form is open (the one just saved).
         */
        root.__rvApply = function (fresh, opts) {
            if (!fresh || !w.LiveUI) return false;
            opts = opts || {};
            var freshList = fresh.querySelector('#rvList');
            if (!freshList) return false;

            ['.rv-kpis', '.rv-aside'].forEach(function (sel) { LiveUI.setHTML(root.querySelector(sel), fresh.querySelector(sel)); });

            var fp = fresh.querySelector('#rvProduct');
            if (fp && productSel.innerHTML !== fp.innerHTML) {
                var keep = productSel.value;
                productSel.innerHTML = fp.innerHTML;
                productSel.value = Array.prototype.some.call(productSel.options, function (o) { return o.value === keep; }) ? keep : '';
                state.product = productSel.value;
            }

            LiveUI.syncKeyed(list, freshList, 'id', {
                ignoreAttrs: ['hidden', 'style'],
                skip: function (el) {
                    if (opts.forceId && el.id === opts.forceId) return false;
                    // never clobber a reply somebody is typing
                    return !!el.querySelector('details[open]') || el.contains(document.activeElement);
                }
            });
            render();
            return true;
        };

        render();
    }

    /** Apply a freshly fetched page to the live reviews panel. Returns true if something was applied. */
    function applyFresh(root, fresh, opts) {
        if (!root || !fresh) return false;
        if (!root.querySelector('#rvList')) {
            // The panel was in its "no reviews yet" state and now has some: rebuild it once.
            if (!fresh.querySelector('#rvList')) return false;
            root.innerHTML = fresh.innerHTML;
            root.__rvReady = false;
            init(root);
            return true;
        }
        return root.__rvApply ? root.__rvApply(fresh, opts) : false;
    }

    w.RVAdmin = { init: init, applyFresh: applyFresh };
})(window);
