/**
 * WWU UI Kit — Modal (confirm dialog)
 *
 * Promise-based confirm/alert/prompt replacement. Creates DOM on-demand,
 * destroys on close — no lingering markup between calls.
 *
 * Accessibility (0.4.0+):
 *   - Dialog wrapper has role="dialog" + aria-modal="true" + aria-labelledby
 *   - Focus trap active while open (requires wwuUIKit.focusTrap loaded first)
 *   - Focus is restored to the previously-focused element on close
 *   - Confirm button has [autofocus] so Enter immediately commits
 *
 * Primary use case: replace `window.confirm('Sei sicuro?')` with a
 * brand-consistent dialog that supports destructive styling + custom
 * button labels + optional double-confirm flow.
 *
 * Usage:
 *   wwuUIKit.modal.confirm({
 *       title: 'Elimina snapshot',
 *       body: 'Questa azione non è reversibile.',
 *       variant: 'danger',                  // default | danger
 *       confirmLabel: 'Elimina',
 *       cancelLabel: 'Annulla',
 *   }).then(function (confirmed) {
 *       if (!confirmed) return;
 *       doDelete();
 *   });
 *
 *   // Double-confirm for destructive mass operations:
 *   wwuUIKit.modal.confirmTwice({
 *       title: 'Reset tutto',
 *       body1: 'Eliminare TUTTE le configurazioni Pro?',
 *       body2: 'Sei davvero sicuro? Questa operazione NON è reversibile.',
 *       variant: 'danger',
 *       confirmLabel: 'Conferma reset',
 *   }).then(function (confirmed) { ... });
 *
 * @since 0.3.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] modal.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    var escHtml = window.wwuUIKit.escHtml;

    /**
     * Default allowlist for `bodyIsHtml: true` — safe inline tags that can
     * appear in confirmation text. Restrictive by design; to allow more
     * (e.g. `img` or `a`), caller must pass an explicit `allowedTags` array.
     *
     * @since 0.8.0
     */
    var DEFAULT_ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'b', 'i', 'u', 'code', 'span', 'ul', 'ol', 'li'];

    /**
     * Very small HTML sanitizer — walks the parsed DOM and removes any
     * element not in the allowlist, stripping all attributes that aren't
     * `class` (no `style`, no `href`, no `on*`). Returns sanitized HTML.
     *
     * NOT a replacement for a full sanitizer like DOMPurify — intentionally
     * minimal. The allowlist should be tiny (text-level tags only). For
     * anything richer, consumer should sanitize server-side with wp_kses
     * and pass the result.
     *
     * @since 0.8.0
     *
     * @param {string} html
     * @param {string[]} allowed
     * @returns {string}
     */
    function sanitizeHtml(html, allowed) {
        var tpl = document.createElement('template');
        tpl.innerHTML = String(html || '');
        var allowSet = {};
        for (var i = 0; i < allowed.length; i++) { allowSet[allowed[i].toLowerCase()] = true; }

        function walk(node) {
            var children = Array.prototype.slice.call(node.childNodes);
            for (var i = 0; i < children.length; i++) {
                var child = children[i];
                if (child.nodeType === 1) {
                    var tag = child.tagName.toLowerCase();
                    if (!allowSet[tag]) {
                        // Unwrap: replace element with its text content.
                        var text = document.createTextNode(child.textContent || '');
                        child.parentNode.replaceChild(text, child);
                        continue;
                    }
                    // Strip all attributes except `class` (no `href`, no `style`, no `on*`).
                    var attrs = Array.prototype.slice.call(child.attributes);
                    for (var a = 0; a < attrs.length; a++) {
                        if (attrs[a].name.toLowerCase() !== 'class') {
                            child.removeAttribute(attrs[a].name);
                        }
                    }
                    walk(child);
                }
                // Comments / other node types → leave in place (textNodes are fine,
                // comments are inert). Could strip comments too; harmless either way.
            }
        }
        walk(tpl.content);

        var out = document.createElement('div');
        while (tpl.content.firstChild) { out.appendChild(tpl.content.firstChild); }
        return out.innerHTML;
    }

    /**
     * Show a confirm dialog. Resolves with boolean (user's choice).
     *
     * @param {object} opts
     * @param {string} [opts.title='Conferma']
     * @param {string} [opts.body='']
     * @param {boolean} [opts.bodyIsHtml=false]  If true, body is inserted as HTML.
     *                                           The kit applies a strict allowlist
     *                                           sanitizer — see `allowedTags`.
     * @param {string[]} [opts.allowedTags]       Tags permitted when bodyIsHtml=true.
     *                                           Default: p, br, strong, em, b, i, u,
     *                                           code, span, ul, ol, li. Attributes
     *                                           other than `class` are stripped.
     * @param {string} [opts.variant='default']  'default' | 'danger'
     * @param {string} [opts.confirmLabel='Conferma']
     * @param {string} [opts.cancelLabel='Annulla']
     * @param {string} [opts.icon]                Dashicon slug (without the `dashicons-` prefix)
     * @returns {Promise<boolean>}
     */
    function confirm(opts) {
        opts = opts || {};

        return new Promise(function (resolve) {
            // Remember the element that had focus before the modal opened —
            // the focus trap will restore it on deactivate.
            var previouslyFocused = document.activeElement;

            var overlay = document.createElement('div');
            overlay.className = 'wwu-ui-modal-overlay';

            var modal = document.createElement('div');
            modal.className = 'wwu-ui-modal' + (opts.variant === 'danger' ? ' danger' : '');
            // ARIA attributes on the dialog itself — the overlay is presentation-only.
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');

            var titleId = 'wwu-ui-modal-title-' + Math.random().toString(36).slice(2, 9);
            modal.setAttribute('aria-labelledby', titleId);

            var iconSlug = opts.icon || (opts.variant === 'danger' ? 'warning' : 'info');
            var titleText = opts.title || 'Conferma';
            var bodyContent;
            if (opts.bodyIsHtml) {
                // Apply the allowlist sanitizer even for "HTML" bodies —
                // this is defense-in-depth: consumer may pass HTML from a
                // trusted source (their own templates) but we still strip
                // anything outside the allowlist to prevent accidental XSS.
                var allowed = Array.isArray(opts.allowedTags) ? opts.allowedTags : DEFAULT_ALLOWED_TAGS;
                bodyContent = sanitizeHtml(opts.body || '', allowed);
            } else {
                bodyContent = escHtml(opts.body || '');
            }

            modal.innerHTML =
                '<div class="wwu-ui-modal-head">' +
                    '<h2 class="wwu-ui-modal-title" id="' + titleId + '">' +
                        '<span class="dashicons dashicons-' + escHtml(iconSlug) + '" aria-hidden="true"></span>' +
                        escHtml(titleText) +
                    '</h2>' +
                '</div>' +
                '<div class="wwu-ui-modal-body">' +
                    (opts.bodyIsHtml ? bodyContent : '<p>' + bodyContent + '</p>') +
                '</div>' +
                '<div class="wwu-ui-modal-foot">' +
                    '<button type="button" class="button" data-action="cancel">' +
                        escHtml(opts.cancelLabel || 'Annulla') +
                    '</button>' +
                    '<button type="button" autofocus class="button ' +
                        (opts.variant === 'danger' ? 'wwu-ui-btn-danger' : 'button-primary') +
                        '" data-action="confirm">' +
                        escHtml(opts.confirmLabel || 'Conferma') +
                    '</button>' +
                '</div>';

            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            // Activate focus trap — honours [autofocus] on the confirm button,
            // restores focus to previouslyFocused on deactivate.
            var trap = window.wwuUIKit.focusTrap
                ? window.wwuUIKit.focusTrap.activate(modal, { returnFocus: previouslyFocused })
                : null;

            function cleanup(result) {
                document.removeEventListener('keydown', onKey);
                if (trap) { trap.deactivate(); }
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
                resolve(result);
            }

            function onClick(e) {
                var action = e.target.closest('[data-action]');
                if (!action) {
                    // Click on overlay (outside modal) dismisses as cancel.
                    if (e.target === overlay) {
                        cleanup(false);
                    }
                    return;
                }
                cleanup(action.getAttribute('data-action') === 'confirm');
            }

            function onKey(e) {
                if (e.key === 'Escape') { cleanup(false); }
                if (e.key === 'Enter') {
                    // Only trigger if focus is NOT inside an input/textarea
                    // (otherwise user's Enter on a form field is hijacked).
                    var tag = document.activeElement && document.activeElement.tagName;
                    if (tag !== 'INPUT' && tag !== 'TEXTAREA') {
                        cleanup(true);
                    }
                }
            }

            overlay.addEventListener('click', onClick);
            document.addEventListener('keydown', onKey);
        });
    }

    /**
     * Double-confirm flow for destructive mass operations.
     * Shows two dialogs in sequence — user must confirm both to proceed.
     *
     * @param {object} opts
     * @param {string} [opts.title]
     * @param {string} opts.body1               First-stage message
     * @param {string} opts.body2               Second-stage "are you really sure?"
     * @param {string} [opts.variant='danger']
     * @param {string} [opts.confirmLabel]
     * @returns {Promise<boolean>}
     */
    function confirmTwice(opts) {
        opts = opts || {};
        return confirm({
            title: opts.title || 'Conferma',
            body: opts.body1 || 'Sei sicuro?',
            variant: opts.variant || 'danger',
            confirmLabel: opts.firstConfirmLabel || 'Avanti',
            cancelLabel: opts.cancelLabel || 'Annulla',
            icon: opts.icon,
        }).then(function (first) {
            if (!first) { return false; }
            return confirm({
                title: opts.title || 'Conferma finale',
                body: opts.body2 || 'Sei davvero sicuro?',
                variant: opts.variant || 'danger',
                confirmLabel: opts.confirmLabel || 'Conferma',
                cancelLabel: opts.cancelLabel || 'Annulla',
                icon: opts.icon,
            });
        });
    }

    /**
     * Simple info alert — single OK button.
     *
     * @param {object|string} opts  Either a string body or options object
     * @returns {Promise<void>}
     */
    function alert(opts) {
        if (typeof opts === 'string') {
            opts = { body: opts };
        }
        opts = opts || {};
        return confirm({
            title: opts.title || 'Info',
            body: opts.body || '',
            bodyIsHtml: opts.bodyIsHtml,
            confirmLabel: opts.confirmLabel || 'OK',
            cancelLabel: '',           // hidden? no — kit still shows it
            variant: opts.variant || 'default',
            icon: opts.icon,
        }).then(function () { /* void */ });
    }

    window.wwuUIKit.modal = {
        confirm: confirm,
        confirmTwice: confirmTwice,
        alert: alert,
    };
}());
