/**
 * WWU UI Kit — Drawer controller
 *
 * Manages open/close state of a `.wwu-ui-drawer` paired with a
 * `.wwu-ui-overlay`. Handles:
 * - ESC key to dismiss
 * - Click on overlay to dismiss
 * - <body> scroll lock while open
 * - Idempotent open/close (double-call safe)
 *
 * Accessibility (0.4.0+):
 * - Drawer gets role="dialog" + aria-modal="true" (unless consumer markup
 *   already sets role to something else like "complementary")
 * - Focus trap active while open (opt-out via `trapFocus: false`)
 * - Focus is restored to the previously-focused element on close
 *
 * Drawer/overlay markup is provided by the consumer plugin. The kit only
 * controls visibility via `element.hidden` (the CSS `[hidden] { display: none
 * !important }` rule guarantees correct hiding — see trap #19).
 *
 * Usage:
 *   var drawer = wwuUIKit.drawer.create({
 *       drawerSelector: '#my-drawer',
 *       overlaySelector: '#my-overlay',
 *       fabSelector: '#my-fab',          // optional — auto-binds click-to-open
 *       onOpen: function () { ... },     // fired after open
 *       onClose: function () { ... },    // fired after close
 *       lockScroll: true                 // default true
 *   });
 *
 *   drawer.open();
 *   drawer.close();
 *   drawer.toggle();
 *
 * @since 0.2.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] drawer.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    var BODY_LOCK_CLASS = 'wwu-ui-drawer-open';

    /**
     * Create a drawer controller.
     *
     * @param {object} opts
     * @returns {object} { open(), close(), toggle(), isOpen() }
     */
    function create(opts) {
        opts = opts || {};
        var drawerEl = typeof opts.drawerSelector === 'string'
            ? document.querySelector(opts.drawerSelector)
            : opts.drawerEl;
        var overlayEl = typeof opts.overlaySelector === 'string'
            ? document.querySelector(opts.overlaySelector)
            : opts.overlayEl;

        if (!drawerEl) {
            if (console && console.warn) {
                console.warn('[wwu-ui-kit] drawer.create: drawer element not found', opts);
            }
            return createNoop();
        }

        var lockScroll = opts.lockScroll !== false; // default true
        var trapFocus = opts.trapFocus !== false;   // default true (0.4.0+)
        var onOpen = typeof opts.onOpen === 'function' ? opts.onOpen : null;
        var onClose = typeof opts.onClose === 'function' ? opts.onClose : null;

        // Start hidden (consumer markup should too, but belt-and-suspenders).
        drawerEl.hidden = true;
        if (overlayEl) { overlayEl.hidden = true; }

        // Apply ARIA attributes once on init — kit owns semantic role for drawer.
        // Consumer can override in markup if they need role="complementary" etc.
        if (!drawerEl.hasAttribute('role')) {
            drawerEl.setAttribute('role', 'dialog');
        }
        if (!drawerEl.hasAttribute('aria-modal')) {
            drawerEl.setAttribute('aria-modal', 'true');
        }

        // Track focus + trap lifecycle.
        var activeTrap = null;
        var previouslyFocused = null;

        function isOpen() {
            return !drawerEl.hidden;
        }

        function open() {
            if (isOpen()) { return; }
            previouslyFocused = document.activeElement;

            drawerEl.hidden = false;
            if (overlayEl) { overlayEl.hidden = false; }
            if (lockScroll) { document.body.classList.add(BODY_LOCK_CLASS); }

            if (trapFocus && window.wwuUIKit.focusTrap) {
                activeTrap = window.wwuUIKit.focusTrap.activate(drawerEl, {
                    returnFocus: previouslyFocused,
                });
            }

            if (onOpen) {
                try { onOpen(drawerEl); } catch (_) { /* no-op */ }
            }
        }

        function close() {
            if (!isOpen()) { return; }
            drawerEl.hidden = true;
            if (overlayEl) { overlayEl.hidden = true; }
            if (lockScroll) { document.body.classList.remove(BODY_LOCK_CLASS); }

            if (activeTrap) {
                activeTrap.deactivate();
                activeTrap = null;
            }

            if (onClose) {
                try { onClose(drawerEl); } catch (_) { /* no-op */ }
            }
        }

        function toggle() {
            if (isOpen()) { close(); } else { open(); }
        }

        // Overlay click dismisses.
        if (overlayEl) {
            overlayEl.addEventListener('click', close);
        }

        // ESC to close — only acts when THIS drawer is open.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) {
                close();
            }
        });

        // Optional FAB binding — single click-to-open trigger.
        if (opts.fabSelector) {
            var fab = document.querySelector(opts.fabSelector);
            if (fab) {
                fab.addEventListener('click', function (e) {
                    e.preventDefault();
                    toggle();
                });
            }
        }

        // Optional close-button binding inside the drawer itself.
        if (opts.closeSelector) {
            drawerEl.addEventListener('click', function (e) {
                if (e.target.closest(opts.closeSelector)) {
                    e.preventDefault();
                    close();
                }
            });
        }

        return {
            open: open,
            close: close,
            toggle: toggle,
            isOpen: isOpen,
            element: drawerEl,
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

    window.wwuUIKit.drawer = {
        create: create,
    };
}());
