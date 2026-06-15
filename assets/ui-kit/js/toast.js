/**
 * WWU UI Kit — Toast notification
 *
 * Fire-and-forget toast that appears top-right for N ms, then fades out.
 * Creates its own DOM element on first call so consumer plugins don't need
 * to include any markup. Safe to call before DOMContentLoaded (deferred
 * via requestAnimationFrame if <body> not yet available).
 *
 * Extracted from AM Pro admin.js:19-30 + AM Lite equivalent.
 *
 * Usage:
 *   wwuUIKit.toast('Saved!');                    // default: success, 3s
 *   wwuUIKit.toast('Error', 'error');
 *   wwuUIKit.toast('Heads up', 'warning', 5000); // custom duration
 *
 * @since 0.1.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] toast.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    var ELEMENT_ID = 'wwu-ui-toast';
    var DEFAULT_DURATION = 3000;
    var FADE_DURATION = 300;
    var activeTimer = null;
    var fadeTimer = null;

    /**
     * Lazily create the toast element. Idempotent — returns existing one
     * if already present.
     *
     * ARIA (0.4.0+):
     *   role="status" + aria-live="polite" = non-interrupting announcement.
     *   aria-atomic="true" = screen reader announces the entire new message,
     *   not just the diff. Critical because toasts often reuse the same DOM
     *   node and a partial diff reads incoherently.
     *
     * @returns {HTMLElement|null}
     */
    function ensureElement() {
        var el = document.getElementById(ELEMENT_ID);
        if (el) { return el; }

        if (!document.body) {
            // Called before <body> exists — defer until DOM is ready.
            return null;
        }

        el = document.createElement('div');
        el.id = ELEMENT_ID;
        el.className = 'wwu-ui-toast';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.setAttribute('aria-atomic', 'true');
        document.body.appendChild(el);
        return el;
    }

    /**
     * Show a toast. If another toast is already visible, it is replaced
     * immediately (last-call-wins — no queue).
     *
     * @param {string} message
     * @param {string} [type='success']  One of: success, error, info, warning
     * @param {number} [duration=3000]   Visible time in ms
     */
    function show(message, type, duration) {
        type = type || 'success';
        duration = typeof duration === 'number' ? duration : DEFAULT_DURATION;

        var el = ensureElement();
        if (!el) {
            // Body not ready — defer one tick.
            window.requestAnimationFrame(function () {
                show(message, type, duration);
            });
            return;
        }

        // Clear any pending hide from the previous toast.
        if (activeTimer) { clearTimeout(activeTimer); activeTimer = null; }
        if (fadeTimer) { clearTimeout(fadeTimer); fadeTimer = null; }

        // Screen-reader re-announcement trick: briefly clear textContent
        // before setting the new value so assistive tech detects a true
        // change (not just a text diff that may be swallowed when the same
        // element is reused rapidly).
        el.textContent = '';

        // rAF delay is enough for the SR live region to register the clear
        // before the new value is set. 0ms setTimeout would also work but
        // rAF aligns with the browser's paint cycle.
        window.requestAnimationFrame(function () {
            el.textContent = message == null ? '' : String(message);
            el.className = 'wwu-ui-toast ' + type + ' is-visible';
        });

        activeTimer = setTimeout(function () {
            el.classList.remove('is-visible');
            // Wait for CSS transition before `display: none` kicks in.
            fadeTimer = setTimeout(function () {
                // Defensive: guard against DOM removal during transition.
                var stillThere = document.getElementById(ELEMENT_ID);
                if (stillThere) { stillThere.style.display = ''; }
            }, FADE_DURATION);
        }, duration);
    }

    /**
     * Dismiss the current toast immediately (if any).
     * Useful when navigating away or after a secondary action.
     */
    function dismiss() {
        if (activeTimer) { clearTimeout(activeTimer); activeTimer = null; }
        if (fadeTimer) { clearTimeout(fadeTimer); fadeTimer = null; }
        var el = document.getElementById(ELEMENT_ID);
        if (el) { el.classList.remove('is-visible'); }
    }

    // `wwuUIKit.toast(msg, type, duration)` is the main entry.
    // Also exposes `.dismiss()` for edge cases.
    window.wwuUIKit.toast = show;
    window.wwuUIKit.toast.dismiss = dismiss;
}());
