/**
 * WWU UI Kit — Save Bar helpers
 *
 * Dirty-state tracking + event delegation for save buttons rendered
 * dynamically by JS (pattern from AM Lite 1.3.23+, see trap #44).
 *
 * Usage:
 *   // Declare bar + its primary save action once.
 *   wwuUIKit.saveBar.bind({
 *       barSelector: '#my-save-bar',
 *       buttonSelector: '#my-save-button',
 *       formSelector: '#my-panel',     // listen for changes here
 *       onSave: function () { ... }    // called when button clicked
 *   });
 *
 *   // Manually set dirty (e.g. after a programmatic change).
 *   wwuUIKit.saveBar.setDirty('#my-save-bar', true);
 *
 * @since 0.2.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] save-bar.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Bind a save bar to a form/panel. Installs:
     * - Change listener on `formSelector` that sets dirty=true
     * - Event-delegated click on `buttonSelector` (so the button can be
     *   re-rendered by innerHTML without losing the handler — trap #44)
     *
     * Safe to call multiple times on different bars per page.
     *
     * @param {object} opts
     * @param {string} opts.barSelector     CSS selector of the .wwu-ui-save-bar
     * @param {string} opts.buttonSelector  CSS selector of the primary save button
     * @param {string} [opts.formSelector]  Container to watch for change events
     * @param {Function} [opts.onSave]      Called when the save button is clicked
     * @param {Function} [opts.isDirtyFn]   Custom dirty predicate (optional)
     */
    function bind(opts) {
        opts = opts || {};

        // Event-delegated click: button can be re-rendered without rebinding.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest(opts.buttonSelector);
            if (!btn) { return; }
            if (typeof opts.onSave === 'function') {
                opts.onSave(btn, e);
            }
        });

        // Dirty tracking: any change within the form marks the bar dirty.
        if (opts.formSelector) {
            var form = document.querySelector(opts.formSelector);
            if (form) {
                form.addEventListener('change', function () {
                    setDirty(opts.barSelector, true);
                });
                form.addEventListener('input', function () {
                    setDirty(opts.barSelector, true);
                });
            }
        }
    }

    /**
     * Toggle the dirty state of a save bar.
     * Adds/removes `.is-dirty` class on the bar root.
     *
     * @param {string|HTMLElement} bar   Selector or element
     * @param {boolean} dirty
     */
    function setDirty(bar, dirty) {
        var el = typeof bar === 'string' ? document.querySelector(bar) : bar;
        if (!el) { return; }
        el.classList.toggle('is-dirty', !!dirty);
    }

    /**
     * Read current dirty state.
     *
     * @param {string|HTMLElement} bar
     * @returns {boolean}
     */
    function isDirty(bar) {
        var el = typeof bar === 'string' ? document.querySelector(bar) : bar;
        return !!(el && el.classList.contains('is-dirty'));
    }

    window.wwuUIKit.saveBar = {
        bind: bind,
        setDirty: setDirty,
        isDirty: isDirty,
    };
}());
