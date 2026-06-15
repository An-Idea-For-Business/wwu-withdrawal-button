/**
 * WWU UI Kit — Debug Bar controller
 *
 * Manages open/close state of a `.wwu-ui-debug-bar` element + wires
 * action buttons (copy report, highlight DOM). Consumer plugin builds
 * the markup + provides content; kit owns behaviour.
 *
 * Usage:
 *   var bar = wwuUIKit.debugBar.create('#my-debug-bar', {
 *       chipSelector: '.wwu-ui-debug-bar-chip',
 *       highlightMap: {
 *           'defer':  '.wwu-ui-debug-hl-defer',
 *           'async':  '.wwu-ui-debug-hl-async',
 *       },
 *       copyReport: function () {
 *           return JSON.stringify(collectReport(), null, 2);
 *       },
 *       onOpen: function () { /* ... */ /* },
 *       onClose: function () { /* ... */ /* },
 *   });
 *
 *   bar.open();
 *   bar.close();
 *
 * @since 0.6.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] debug-bar.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Create a debug bar controller.
     *
     * @param {string|HTMLElement} target      Selector or element for `.wwu-ui-debug-bar`
     * @param {object} [opts]
     * @param {string} [opts.chipSelector='.wwu-ui-debug-bar-chip']
     *                                          Element that toggles open/close on click
     * @param {string} [opts.copySelector='[data-debug-action="copy"]']
     *                                          Button that triggers copyReport()
     * @param {string} [opts.highlightSelector='[data-debug-action="highlight"]']
     *                                          Button that toggles highlight mode
     * @param {Function} [opts.copyReport]      Returns the text to copy. Default: panel textContent.
     * @param {Function} [opts.onOpen]
     * @param {Function} [opts.onClose]
     * @returns {object}                        { open, close, toggle, isOpen, element }
     */
    function create(target, opts) {
        opts = opts || {};
        var root = typeof target === 'string' ? document.querySelector(target) : target;
        if (!root) { return createNoop(); }

        var chipSelector = opts.chipSelector || '.wwu-ui-debug-bar-chip';
        var copySelector = opts.copySelector || '[data-debug-action="copy"]';
        var highlightSelector = opts.highlightSelector || '[data-debug-action="highlight"]';

        function isOpen() {
            return root.classList.contains('is-open');
        }

        function open() {
            if (isOpen()) { return; }
            root.classList.add('is-open');
            if (typeof opts.onOpen === 'function') {
                try { opts.onOpen(root); } catch (_) { /* no-op */ }
            }
        }

        function close() {
            if (!isOpen()) { return; }
            root.classList.remove('is-open');
            if (typeof opts.onClose === 'function') {
                try { opts.onClose(root); } catch (_) { /* no-op */ }
            }
        }

        function toggle() {
            if (isOpen()) { close(); } else { open(); }
        }

        // Click on chip → toggle.
        root.addEventListener('click', function (e) {
            if (e.target.closest(chipSelector)) {
                e.preventDefault();
                toggle();
                return;
            }

            // Action: copy report.
            if (e.target.closest(copySelector)) {
                e.preventDefault();
                var text = '';
                if (typeof opts.copyReport === 'function') {
                    try { text = String(opts.copyReport(root) || ''); } catch (_) { text = ''; }
                }
                if (!text) {
                    // Fallback: dump panel textContent.
                    var panel = root.querySelector('.wwu-ui-debug-bar-panel');
                    text = panel ? panel.textContent.replace(/\s+/g, ' ').trim() : '';
                }
                if (window.wwuUIKit.clipboard) {
                    window.wwuUIKit.clipboard.copyWithToast(text, {
                        successMessage: 'Report copiato',
                    });
                }
                return;
            }

            // Action: toggle DOM highlight mode.
            if (e.target.closest(highlightSelector)) {
                e.preventDefault();
                var btn = e.target.closest(highlightSelector);
                btn.classList.toggle('active');
                document.body.classList.toggle('wwu-ui-debug-highlight-on');
                return;
            }

            // URL click → copy to clipboard, flash green.
            var url = e.target.closest('.wwu-ui-debug-bar-url');
            if (url) {
                e.preventDefault();
                var urlText = url.getAttribute('data-url') || url.textContent.trim();
                if (window.wwuUIKit.clipboard) {
                    window.wwuUIKit.clipboard.copy(urlText).then(function (ok) {
                        if (ok) {
                            url.classList.add('copied');
                            setTimeout(function () {
                                url.classList.remove('copied');
                            }, 1200);
                        }
                    });
                }
            }
        });

        // ESC to close when open.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) {
                close();
            }
        });

        return {
            open: open,
            close: close,
            toggle: toggle,
            isOpen: isOpen,
            element: root,
        };
    }

    function createNoop() {
        return {
            open: function () {},
            close: function () {},
            toggle: function () {},
            isOpen: function () { return false; },
            element: null,
        };
    }

    window.wwuUIKit.debugBar = {
        create: create,
    };
}());
