/**
 * WWU UI Kit — Focus trap
 *
 * Constrain keyboard focus (Tab / Shift+Tab) inside a container until
 * deactivated. Required for WCAG-compliant modal + drawer behaviour:
 * without a trap, users tabbing inside an open modal eventually land on
 * elements BEHIND the overlay, which violates the modal contract.
 *
 * On activate:
 *   - focuses the first focusable element in the container
 *     (or `[autofocus]` if present)
 *   - stores the previously-focused element so it can be restored
 *
 * On deactivate:
 *   - returns focus to the previously-focused element
 *
 * Usage:
 *   var trap = wwuUIKit.focusTrap.activate(modalEl);
 *   // ... modal is open ...
 *   trap.deactivate();
 *
 * Used internally by wwuUIKit.modal and wwuUIKit.drawer starting 0.4.0.
 *
 * @since 0.4.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] focus-trap.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * CSS selectors for elements that can receive focus.
     * Note: `[tabindex="-1"]` is EXCLUDED — such elements are programmatically
     * focusable but skipped in natural tab order.
     *
     * @type {string}
     */
    var FOCUSABLE_SELECTOR = [
        'a[href]',
        'area[href]',
        'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
        '[contenteditable="true"]',
        'audio[controls]',
        'video[controls]',
    ].join(', ');

    /**
     * Check if an element is currently visible (not hidden by attribute,
     * display:none, visibility:hidden, or offscreen via opacity:0 clip).
     *
     * Uses offsetParent + hidden attribute — cheap and correct for 99% of
     * admin UI cases. Does NOT cover clip-path / transform-based hiding.
     *
     * @param {HTMLElement} el
     * @returns {boolean}
     */
    function isVisible(el) {
        if (!el) { return false; }
        if (el.hidden) { return false; }
        if (el.getAttribute('aria-hidden') === 'true') { return false; }
        // offsetParent is null when an ancestor has display:none.
        if (el.offsetParent === null && el.tagName !== 'BODY') { return false; }
        return true;
    }

    /**
     * Collect all focusable descendants of `container`, in DOM order.
     *
     * @param {HTMLElement} container
     * @returns {HTMLElement[]}
     */
    function getFocusable(container) {
        if (!container) { return []; }
        var nodes = container.querySelectorAll(FOCUSABLE_SELECTOR);
        var result = [];
        for (var i = 0; i < nodes.length; i++) {
            if (isVisible(nodes[i])) { result.push(nodes[i]); }
        }
        return result;
    }

    /**
     * Activate the focus trap on the given container.
     *
     * @param {HTMLElement} container
     * @param {object} [opts]
     * @param {HTMLElement} [opts.returnFocus]  Element to restore focus on deactivate (default: document.activeElement at activation time)
     * @param {boolean} [opts.initialFocus=true] Focus the first focusable element on activation
     * @returns {object}  { deactivate() }
     */
    function activate(container, opts) {
        opts = opts || {};
        if (!container) { return { deactivate: function () {} }; }

        var returnTo = opts.returnFocus || document.activeElement;

        if (opts.initialFocus !== false) {
            var autofocus = container.querySelector('[autofocus]');
            var target = autofocus || getFocusable(container)[0];
            if (target) {
                // setTimeout(0) to wait for any in-progress CSS transition
                // that might interfere with focus (e.g. animated drawer).
                setTimeout(function () {
                    try { target.focus(); } catch (_) { /* no-op */ }
                }, 0);
            }
        }

        function onKeyDown(e) {
            if (e.key !== 'Tab') { return; }
            var focusable = getFocusable(container);
            if (!focusable.length) {
                // No focusable children — keep focus on container itself.
                e.preventDefault();
                try { container.focus(); } catch (_) { /* no-op */ }
                return;
            }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            var active = document.activeElement;

            // If focus escaped the container (e.g. clicked outside + Tab),
            // bring it back to first/last.
            if (!container.contains(active)) {
                (e.shiftKey ? last : first).focus();
                e.preventDefault();
                return;
            }

            if (e.shiftKey && active === first) {
                last.focus();
                e.preventDefault();
            } else if (!e.shiftKey && active === last) {
                first.focus();
                e.preventDefault();
            }
        }

        container.addEventListener('keydown', onKeyDown);

        // Ensure the container itself is focusable as a safety net
        // (tabindex="-1" makes it programmatically focusable but skipped
        // in natural tab order).
        var originalTabindex = container.getAttribute('tabindex');
        if (originalTabindex === null) {
            container.setAttribute('tabindex', '-1');
        }

        return {
            deactivate: function () {
                container.removeEventListener('keydown', onKeyDown);
                if (originalTabindex === null) {
                    container.removeAttribute('tabindex');
                } else {
                    container.setAttribute('tabindex', originalTabindex);
                }
                if (returnTo && typeof returnTo.focus === 'function') {
                    // Delay to let any close animation settle first.
                    setTimeout(function () {
                        try { returnTo.focus(); } catch (_) { /* no-op */ }
                    }, 0);
                }
            },
        };
    }

    window.wwuUIKit.focusTrap = {
        activate: activate,
        getFocusable: getFocusable,
    };
}());
