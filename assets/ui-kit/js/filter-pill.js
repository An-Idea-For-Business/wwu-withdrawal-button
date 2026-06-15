/**
 * WWU UI Kit — Filter Pills controller
 *
 * Manages the active/inactive state of `.wwu-ui-filter-pill` buttons and
 * emits a custom event when filters change. The kit does NOT apply the
 * filters to a target list — that's consumer-specific (show/hide rows
 * based on data attributes). The kit only owns the UI state + events.
 *
 * Pairs with `wwuUIKit.accordion.summaryClickSafe()` if pills are placed
 * inside `<summary>` — trap #42.
 *
 * Usage:
 *   <div class="my-toolbar">
 *       <button class="wwu-ui-filter-pill" data-filter="off">Off</button>
 *       <button class="wwu-ui-filter-pill" data-filter="pro">PRO</button>
 *       <button class="wwu-ui-filter-pill" data-filter="active">Attive</button>
 *   </div>
 *
 *   wwuUIKit.filterPill.bind('.my-toolbar', {
 *       onChange: function (activeFilters, groupEl) {
 *           applyFiltersToRows(activeFilters);
 *       },
 *       exclusive: false,   // default: stackable (multiple active allowed)
 *   });
 *
 * @since 0.3.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] filter-pill.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Bind pill click handling within a group container.
     *
     * @param {string|HTMLElement} group       Selector or element
     * @param {object} opts
     * @param {Function} opts.onChange        (activeFilters: string[], groupEl) => void
     * @param {boolean} [opts.exclusive=false] true = radio-like (only 1 active)
     * @returns {object} { getActive(), setActive(filters), reset() }
     */
    function bind(group, opts) {
        opts = opts || {};
        var el = typeof group === 'string' ? document.querySelector(group) : group;
        if (!el) { return createNoop(); }

        el.addEventListener('click', function (e) {
            var pill = e.target.closest('.wwu-ui-filter-pill');
            if (!pill) { return; }
            // Skip static count badges.
            if (pill.classList.contains('wwu-ui-filter-pill--count')) { return; }
            if (!el.contains(pill)) { return; }

            e.preventDefault();

            if (opts.exclusive) {
                // Radio behaviour — deactivate others, activate this (or toggle off).
                var wasActive = pill.classList.contains('is-active');
                var others = el.querySelectorAll('.wwu-ui-filter-pill.is-active');
                for (var i = 0; i < others.length; i++) {
                    others[i].classList.remove('is-active');
                }
                if (!wasActive) { pill.classList.add('is-active'); }
            } else {
                pill.classList.toggle('is-active');
            }

            notify();
        });

        function getActive() {
            var pills = el.querySelectorAll('.wwu-ui-filter-pill.is-active');
            var filters = [];
            for (var i = 0; i < pills.length; i++) {
                var v = pills[i].getAttribute('data-filter');
                if (v) { filters.push(v); }
            }
            return filters;
        }

        function setActive(filters) {
            filters = filters || [];
            var pills = el.querySelectorAll('.wwu-ui-filter-pill');
            for (var i = 0; i < pills.length; i++) {
                var p = pills[i];
                var v = p.getAttribute('data-filter');
                p.classList.toggle('is-active', filters.indexOf(v) !== -1);
            }
            notify();
        }

        function reset() {
            setActive([]);
        }

        function notify() {
            if (typeof opts.onChange === 'function') {
                try { opts.onChange(getActive(), el); } catch (err) {
                    if (console && console.warn) {
                        console.warn('[wwu-ui-kit] filter-pill onChange threw:', err);
                    }
                }
            }
        }

        return {
            getActive: getActive,
            setActive: setActive,
            reset: reset,
            element: el,
        };
    }

    function createNoop() {
        return {
            getActive: function () { return []; },
            setActive: function () {},
            reset: function () {},
            element: null,
        };
    }

    window.wwuUIKit.filterPill = {
        bind: bind,
    };
}());
