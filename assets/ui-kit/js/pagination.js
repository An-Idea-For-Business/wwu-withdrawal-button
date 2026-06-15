/**
 * WWU UI Kit — Pagination controller
 *
 * Renders a pagination bar with prev/next + numbered buttons + summary
 * + optional per-page dropdown. Consumer provides `total` count and
 * `pageSize`, kit owns rendering + active-page state + events.
 *
 * The kit does NOT fetch/slice data — consumer handles that via the
 * `onChange` callback.
 *
 * Usage:
 *   var pg = wwuUIKit.pagination.create('#my-pagination', {
 *       total: 248,
 *       pageSize: 25,
 *       page: 1,
 *       summaryTemplate: '{start}–{end} di {total}',
 *       perPageOptions: [10, 25, 50, 100],
 *       onChange: function (state) {
 *           fetchRows(state.page, state.pageSize);
 *       },
 *   });
 *
 *   // Imperative API:
 *   pg.goTo(3);
 *   pg.setTotal(310);         // after an update invalidates count
 *   pg.setPageSize(50);
 *   pg.refresh();             // re-render with current state
 *
 * @since 0.5.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] pagination.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    var escHtml = window.wwuUIKit.escHtml;

    /**
     * Create a pagination controller mounted on `target`.
     *
     * @param {string|HTMLElement} target
     * @param {object} opts
     * @param {number} opts.total                Total number of items
     * @param {number} [opts.pageSize=25]
     * @param {number} [opts.page=1]             1-indexed current page
     * @param {number} [opts.windowSize=2]       Page numbers around current to show
     * @param {number[]} [opts.perPageOptions]   e.g. [10, 25, 50, 100]
     * @param {string} [opts.summaryTemplate='{start}–{end} di {total}']
     * @param {string} [opts.prevLabel='‹']
     * @param {string} [opts.nextLabel='›']
     * @param {string} [opts.perPageLabel='Per pagina:']
     * @param {Function} [opts.onChange]         (state) => void
     * @returns {object}
     */
    function create(target, opts) {
        opts = opts || {};
        var root = typeof target === 'string' ? document.querySelector(target) : target;
        if (!root) { return createNoop(); }

        var state = {
            total: Number(opts.total) || 0,
            pageSize: Number(opts.pageSize) || 25,
            page: Math.max(1, Number(opts.page) || 1),
        };
        var windowSize = typeof opts.windowSize === 'number' ? opts.windowSize : 2;
        var perPageOptions = Array.isArray(opts.perPageOptions) ? opts.perPageOptions : null;
        var summaryTemplate = opts.summaryTemplate || '{start}–{end} di {total}';
        var prevLabel = opts.prevLabel || '\u2039'; // ‹
        var nextLabel = opts.nextLabel || '\u203A'; // ›
        var perPageLabel = opts.perPageLabel || 'Per pagina:';
        var onChange = typeof opts.onChange === 'function' ? opts.onChange : null;

        function totalPages() {
            if (state.pageSize <= 0) { return 1; }
            return Math.max(1, Math.ceil(state.total / state.pageSize));
        }

        function clampPage(p) {
            return Math.max(1, Math.min(totalPages(), p));
        }

        function buildSummary() {
            if (state.total === 0) { return 'Nessun risultato'; }
            var start = (state.page - 1) * state.pageSize + 1;
            var end = Math.min(state.total, state.page * state.pageSize);
            return summaryTemplate
                .replace('{start}', String(start))
                .replace('{end}', String(end))
                .replace('{total}', String(state.total));
        }

        /**
         * Build the list of page-number tokens with ellipses:
         *   current ± windowSize + always first/last.
         *
         * Returns e.g. [1, '...', 4, 5, 6, '...', 20]
         */
        function buildPageList() {
            var last = totalPages();
            var p = state.page;
            var w = windowSize;
            if (last <= 7 + w * 2) {
                var all = [];
                for (var i = 1; i <= last; i++) { all.push(i); }
                return all;
            }

            var tokens = [1];
            var from = Math.max(2, p - w);
            var to = Math.min(last - 1, p + w);
            if (from > 2) { tokens.push('...'); }
            for (var j = from; j <= to; j++) { tokens.push(j); }
            if (to < last - 1) { tokens.push('...'); }
            tokens.push(last);
            return tokens;
        }

        function render() {
            var last = totalPages();
            state.page = clampPage(state.page);
            var pages = buildPageList();

            var pageButtonsHtml = pages.map(function (tok) {
                if (tok === '...') {
                    return '<span class="wwu-ui-pagination-ellipsis" aria-hidden="true">…</span>';
                }
                var isActive = tok === state.page;
                return '<button type="button" class="wwu-ui-pagination-btn' +
                    (isActive ? ' is-active' : '') + '"' +
                    ' data-page="' + tok + '"' +
                    (isActive ? ' aria-current="page"' : '') +
                    ' aria-label="Pagina ' + tok + '">' + tok + '</button>';
            }).join('');

            var perPageHtml = '';
            if (perPageOptions) {
                perPageHtml = '<div class="wwu-ui-pagination-perpage">' +
                    '<label>' + escHtml(perPageLabel) +
                    '<select data-role="perpage">' +
                    perPageOptions.map(function (n) {
                        return '<option value="' + n + '"' +
                            (n === state.pageSize ? ' selected' : '') +
                            '>' + n + '</option>';
                    }).join('') +
                    '</select></label></div>';
            }

            root.className = 'wwu-ui-pagination';
            root.setAttribute('role', 'navigation');
            root.setAttribute('aria-label', 'Paginazione');
            root.innerHTML =
                '<div class="wwu-ui-pagination-summary">' + escHtml(buildSummary()) + '</div>' +
                perPageHtml +
                '<div class="wwu-ui-pagination-controls">' +
                    '<button type="button" class="wwu-ui-pagination-btn"' +
                        ' data-page="prev"' +
                        (state.page <= 1 ? ' disabled' : '') +
                        ' aria-label="Pagina precedente">' + escHtml(prevLabel) + '</button>' +
                    pageButtonsHtml +
                    '<button type="button" class="wwu-ui-pagination-btn"' +
                        ' data-page="next"' +
                        (state.page >= last ? ' disabled' : '') +
                        ' aria-label="Pagina successiva">' + escHtml(nextLabel) + '</button>' +
                '</div>';
        }

        function notify() {
            if (onChange) {
                try {
                    onChange({
                        page: state.page,
                        pageSize: state.pageSize,
                        total: state.total,
                        totalPages: totalPages(),
                    });
                } catch (err) {
                    if (console && console.warn) {
                        console.warn('[wwu-ui-kit] pagination onChange threw:', err);
                    }
                }
            }
        }

        function goTo(p) {
            if (p === 'prev') { p = state.page - 1; }
            else if (p === 'next') { p = state.page + 1; }
            p = clampPage(Number(p));
            if (p === state.page) {
                // Click on active page → no-op.
                return;
            }
            state.page = p;
            render();
            notify();
        }

        function setTotal(t) {
            state.total = Math.max(0, Number(t) || 0);
            state.page = clampPage(state.page);
            render();
        }

        function setPageSize(ps) {
            state.pageSize = Math.max(1, Number(ps) || 25);
            state.page = 1; // reset to first to avoid out-of-range
            render();
            notify();
        }

        // Event delegation — single listener, survives re-render since we
        // re-attach after every render. Simpler: put the listener on root ONCE.
        root.addEventListener('click', function (e) {
            var btn = e.target.closest('.wwu-ui-pagination-btn');
            if (!btn || btn.disabled) { return; }
            var pageAttr = btn.getAttribute('data-page');
            if (!pageAttr) { return; }
            e.preventDefault();
            goTo(pageAttr);
        });

        root.addEventListener('change', function (e) {
            var sel = e.target.closest('[data-role="perpage"]');
            if (!sel) { return; }
            setPageSize(Number(sel.value));
        });

        render();

        return {
            goTo: goTo,
            setTotal: setTotal,
            setPageSize: setPageSize,
            refresh: render,
            getState: function () {
                return {
                    page: state.page,
                    pageSize: state.pageSize,
                    total: state.total,
                    totalPages: totalPages(),
                };
            },
            element: root,
        };
    }

    function createNoop() {
        return {
            goTo: function () {},
            setTotal: function () {},
            setPageSize: function () {},
            refresh: function () {},
            getState: function () { return { page: 1, pageSize: 25, total: 0, totalPages: 1 }; },
            element: null,
        };
    }

    window.wwuUIKit.pagination = {
        create: create,
    };
}());
