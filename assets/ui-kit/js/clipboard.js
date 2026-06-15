/**
 * WWU UI Kit — Copy to clipboard helper
 *
 * Thin wrapper around `navigator.clipboard.writeText()` with a fallback
 * to `execCommand('copy')` for older browsers and non-HTTPS contexts.
 * Optionally shows a kit toast on success/failure.
 *
 * Usage:
 *   wwuUIKit.clipboard.copy('some text').then(function (ok) {
 *       if (ok) {
 *           wwuUIKit.toast('Copiato', 'success');
 *       }
 *   });
 *
 *   // Auto-bind any button with data-copy-text or data-copy-from:
 *   wwuUIKit.clipboard.bindButtons();
 *
 *   // Then markup like:
 *   // <button data-copy-text="literal text to copy">Copy</button>
 *   // <button data-copy-from="#source-textarea">Copy textarea</button>
 *
 * @since 0.3.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] clipboard.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Copy `text` to the clipboard. Tries the modern Promise-based API
     * first, falls back to execCommand on failure.
     *
     * @param {string} text
     * @returns {Promise<boolean>}  Resolves true on success, false on failure
     */
    function copy(text) {
        text = text == null ? '' : String(text);

        // Modern path — available in HTTPS contexts.
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text)
                .then(function () { return true; })
                .catch(function () { return fallback(text); });
        }

        // Legacy path — execCommand('copy') on a hidden textarea.
        return Promise.resolve(fallback(text));
    }

    /**
     * Fallback copy implementation for older browsers / non-HTTPS admin.
     * Returns true/false synchronously.
     *
     * @param {string} text
     * @returns {boolean}
     */
    function fallback(text) {
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '0';
            ta.style.left = '0';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return !!ok;
        } catch (_) {
            return false;
        }
    }

    /**
     * Copy with automatic toast feedback.
     *
     * @param {string} text
     * @param {object} [opts]
     * @param {string} [opts.successMessage='Copiato']
     * @param {string} [opts.errorMessage='Errore copia']
     * @param {boolean} [opts.silent=false]  Skip toasts if true
     * @returns {Promise<boolean>}
     */
    function copyWithToast(text, opts) {
        opts = opts || {};
        return copy(text).then(function (ok) {
            if (!opts.silent && window.wwuUIKit.toast) {
                window.wwuUIKit.toast(
                    ok ? (opts.successMessage || 'Copiato') : (opts.errorMessage || 'Errore copia'),
                    ok ? 'success' : 'error'
                );
            }
            return ok;
        });
    }

    /**
     * Scan the document for buttons with `data-copy-text` or
     * `data-copy-from` and wire them up via event delegation.
     *
     * Idempotent: calling multiple times only installs the listener once.
     *
     * Markup:
     *   <button data-copy-text="hello">Copy literal</button>
     *   <button data-copy-from="#my-textarea">Copy from element</button>
     *
     * Attribute `data-copy-silent="1"` suppresses toasts for that button.
     */
    var buttonsBound = false;
    function bindButtons() {
        if (buttonsBound) { return; }
        buttonsBound = true;

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-copy-text], [data-copy-from]');
            if (!btn) { return; }
            e.preventDefault();

            var text = '';
            if (btn.hasAttribute('data-copy-text')) {
                text = btn.getAttribute('data-copy-text');
            } else {
                var src = document.querySelector(btn.getAttribute('data-copy-from'));
                if (!src) { return; }
                text = (src.value != null) ? src.value : (src.textContent || '');
            }

            copyWithToast(text, { silent: btn.getAttribute('data-copy-silent') === '1' });
        });
    }

    /**
     * Copy text and flash a visual feedback class on the button (e.g.
     * "Copy → Copied!" effect without a toast). Useful when the button is
     * the trigger AND the visual feedback surface — toasts are overkill.
     *
     * Pattern picked up from the VB editor redesign (`[data-action="copy"]`
     * with `.is-copied` flash class 1.2s) — now formalised in the kit.
     *
     * @param {HTMLElement} btn        Element to flash. Receives `opts.flashClass`.
     * @param {string} text            Text to copy.
     * @param {object} [opts]
     * @param {string}  [opts.flashClass='is-copied']
     * @param {number}  [opts.flashMs=1200]
     * @param {string}  [opts.errorClass='is-copy-error']
     * @returns {Promise<boolean>}
     * @since 0.8.4
     */
    function copyWithFlash(btn, text, opts) {
        opts = opts || {};
        var flashClass  = opts.flashClass  || 'is-copied';
        var errorClass  = opts.errorClass  || 'is-copy-error';
        var flashMs     = typeof opts.flashMs === 'number' ? opts.flashMs : 1200;

        return copy(text).then(function (ok) {
            if (!btn || !btn.classList) { return ok; }
            var cls = ok ? flashClass : errorClass;
            btn.classList.add(cls);
            setTimeout(function () {
                btn.classList.remove(cls);
            }, flashMs);
            return ok;
        });
    }

    window.wwuUIKit.clipboard = {
        copy: copy,
        copyWithToast: copyWithToast,
        copyWithFlash: copyWithFlash,
        bindButtons: bindButtons,
    };
}());
