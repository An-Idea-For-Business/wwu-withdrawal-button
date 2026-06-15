/**
 * WWU UI Kit — Tabs controller
 *
 * Manages tab activation + panel visibility + URL hash persistence +
 * lazy-load hooks via custom event.
 *
 * Reference pattern from AM Lite admin.js:98-203 (Lite 1.3.21+).
 * Trap #40: programmatic activateTab() must dispatch a custom event so
 * lazy-load listeners fire for BOTH click and hash-restore paths.
 *
 * Markup contract (matches the CSS in tabs.css):
 *
 *   <a class="wwu-ui-tab" data-tab="foo">Foo</a>
 *   <a class="wwu-ui-tab" data-tab="bar">Bar</a>
 *   ...
 *   <div class="wwu-ui-panel" data-panel="foo">...</div>
 *   <div class="wwu-ui-panel" data-panel="bar">...</div>
 *
 * Usage:
 *   var tabs = wwuUIKit.tabs.create({
 *       prefix: 'wwu-pm',             // event namespace ("wwu-pm:tab-activated")
 *       defaultTab: 'foo',            // fallback when no hash is present
 *       persistHash: true             // sync #hash with active tab (default true)
 *   });
 *
 *   tabs.activate('bar');
 *   tabs.onVisible('bar', function () { loadBarData(); });
 *
 * @since 0.2.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] tabs.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Create a tab controller scoped to `.wwu-ui-tab` / `.wwu-ui-panel`.
     *
     * @param {object} opts
     * @param {string} [opts.prefix='wwu-ui']     Event namespace
     * @param {string} [opts.defaultTab]          Fallback tab when no hash
     * @param {boolean} [opts.persistHash=true]   Write #hash on activate
     * @param {boolean} [opts.roving=false]       Enable roving-tabindex keyboard nav (0.4.0+)
     * @param {string} [opts.tablistSelector]     Container for ARIA role="tablist" + roving scope
     * @returns {object}                          { activate, onVisible, current, restoreFromHash }
     */
    function create(opts) {
        opts = opts || {};
        var prefix = opts.prefix || 'wwu-ui';
        var persistHash = opts.persistHash !== false; // default true
        var eventName = prefix + ':tab-activated';

        // Track whether each lazy listener has already fired.
        var fired = {};

        // 0.4.0+ — ARIA roles on existing markup. Non-destructive: only sets
        // attributes that are missing, so consumer markup can override.
        applyAriaRoles();

        // Optional: roving tabindex for keyboard navigation.
        if (opts.roving && window.wwuUIKit.rovingTabindex) {
            var tablistEl = opts.tablistSelector
                ? document.querySelector(opts.tablistSelector)
                : (function () {
                    // Fallback: the parent element of the first .wwu-ui-tab.
                    var firstTab = document.querySelector('.wwu-ui-tab');
                    return firstTab ? firstTab.parentElement : null;
                }());
            if (tablistEl) {
                window.wwuUIKit.rovingTabindex.bind(tablistEl, {
                    itemSelector: '.wwu-ui-tab',
                    orientation: opts.orientation || 'vertical',
                });
            }
        }

        /**
         * Apply tab/tabpanel ARIA roles and aria-selected state.
         * Only sets attributes that are MISSING — consumer-provided values win.
         */
        function applyAriaRoles() {
            var tabs = document.querySelectorAll('.wwu-ui-tab');
            for (var i = 0; i < tabs.length; i++) {
                var t = tabs[i];
                if (!t.hasAttribute('role')) { t.setAttribute('role', 'tab'); }
                t.setAttribute('aria-selected', t.classList.contains('active') ? 'true' : 'false');
                var tabName = t.getAttribute('data-tab');
                if (tabName && !t.hasAttribute('aria-controls')) {
                    var panel = document.querySelector('.wwu-ui-panel[data-panel="' + cssEscape(tabName) + '"]');
                    if (panel) {
                        // Ensure panel has an id for aria-controls to reference.
                        if (!panel.id) {
                            panel.id = 'wwu-ui-panel-' + tabName;
                        }
                        t.setAttribute('aria-controls', panel.id);
                    }
                }
            }
            var panels = document.querySelectorAll('.wwu-ui-panel');
            for (var j = 0; j < panels.length; j++) {
                var p = panels[j];
                if (!p.hasAttribute('role')) { p.setAttribute('role', 'tabpanel'); }
                if (!p.hasAttribute('tabindex')) { p.setAttribute('tabindex', '0'); }
                var panelName = p.getAttribute('data-panel');
                if (panelName && !p.hasAttribute('aria-labelledby')) {
                    var tab = document.querySelector('.wwu-ui-tab[data-tab="' + cssEscape(panelName) + '"]');
                    if (tab) {
                        if (!tab.id) { tab.id = 'wwu-ui-tab-' + panelName; }
                        p.setAttribute('aria-labelledby', tab.id);
                    }
                }
            }
        }

        /**
         * Activate a tab by its data-tab value.
         * - Flips `.active` on `.wwu-ui-tab` + `.wwu-ui-panel`
         * - Dispatches `{prefix}:tab-activated` CustomEvent
         * - Optionally updates URL hash (history.replaceState — no new entry)
         *
         * @param {string} target data-tab value
         * @param {object} [activateOpts]  { skipHash: bool }
         */
        function activate(target, activateOpts) {
            activateOpts = activateOpts || {};

            var allTabs = document.querySelectorAll('.wwu-ui-tab');
            var allPanels = document.querySelectorAll('.wwu-ui-panel');
            for (var i = 0; i < allTabs.length; i++) {
                allTabs[i].classList.remove('active');
                allTabs[i].setAttribute('aria-selected', 'false');
            }
            for (var j = 0; j < allPanels.length; j++) {
                allPanels[j].classList.remove('active');
            }

            var tab = document.querySelector('.wwu-ui-tab[data-tab="' + cssEscape(target) + '"]');
            var panel = document.querySelector('.wwu-ui-panel[data-panel="' + cssEscape(target) + '"]');
            if (tab) {
                tab.classList.add('active');
                tab.setAttribute('aria-selected', 'true');
            }
            if (panel) { panel.classList.add('active'); }

            if (persistHash && !activateOpts.skipHash && window.history && history.replaceState) {
                try {
                    history.replaceState(null, '', '#' + encodeURIComponent(target));
                } catch (_) { /* no-op */ }
            }

            try {
                document.dispatchEvent(new CustomEvent(eventName, {
                    detail: { target: target },
                }));
            } catch (_) { /* IE9+ supports CustomEvent — safe for WP 5.6+ */ }
        }

        /**
         * Run `fn` exactly once, the first time the given tab becomes visible.
         * Covers both click-driven and hash-restore entry paths.
         *
         * @param {string}   tabName data-tab value
         * @param {Function} fn       Callback
         */
        function onVisible(tabName, fn) {
            document.addEventListener(eventName, function (e) {
                if (!e.detail || e.detail.target !== tabName) { return; }
                if (fired[tabName]) { return; }
                fired[tabName] = true;
                try { fn(); } catch (err) {
                    if (console && console.warn) {
                        console.warn('[wwu-ui-kit] onVisible callback error:', err);
                    }
                }
            });

            // Case: tab already active when onVisible() is called (rare, but
            // possible if init order is off). Fire immediately.
            var panel = document.querySelector('.wwu-ui-panel[data-panel="' + cssEscape(tabName) + '"]');
            if (panel && panel.classList.contains('active') && !fired[tabName]) {
                fired[tabName] = true;
                try { fn(); } catch (_) { /* no-op */ }
            }
        }

        /**
         * Parse URL hash and activate the matching tab, if any.
         * Silent fallback to `defaultTab` if the hash doesn't match.
         */
        function restoreFromHash() {
            var hash = location.hash.replace('#', '');
            if (hash) {
                hash = decodeURIComponent(hash);
                // Support "tabName/subvalue" for deep-link within tab context.
                var parts = hash.split('/');
                var tabName = parts[0];
                var tabEl = document.querySelector('.wwu-ui-tab[data-tab="' + cssEscape(tabName) + '"]');
                if (tabEl) {
                    activate(tabName, { skipHash: true });
                    // Also expose sub-path as detail for consumers that need it.
                    if (parts.length > 1) {
                        try {
                            document.dispatchEvent(new CustomEvent(eventName + ':subpath', {
                                detail: { target: tabName, subpath: parts.slice(1).join('/') },
                            }));
                        } catch (_) { /* no-op */ }
                    }
                    return;
                }
            }
            if (opts.defaultTab) {
                activate(opts.defaultTab, { skipHash: !hash });
            }
        }

        /**
         * Return the currently active tab's data-tab value.
         * @returns {string|null}
         */
        function current() {
            var active = document.querySelector('.wwu-ui-tab.active');
            return active ? active.getAttribute('data-tab') : null;
        }

        // Wire up click listeners on all existing tabs.
        // Event delegation is used so dynamically-added tabs also work.
        document.addEventListener('click', function (e) {
            var tab = e.target.closest('.wwu-ui-tab');
            if (!tab) { return; }
            var target = tab.getAttribute('data-tab');
            if (!target) { return; }
            e.preventDefault();
            activate(target);
        });

        return {
            activate: activate,
            onVisible: onVisible,
            current: current,
            restoreFromHash: restoreFromHash,
        };
    }

    /**
     * Minimal CSS.escape shim. Most values are simple slugs, but we guard
     * against tabs named e.g. `foo.bar` or `2nd-tab` which would confuse
     * querySelector.
     */
    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/([^\w-])/g, '\\$1');
    }

    window.wwuUIKit.tabs = {
        create: create,
    };
}());
