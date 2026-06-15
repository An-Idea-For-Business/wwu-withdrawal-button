/**
 * WWU UI Kit — Accordion helpers
 *
 * The accordion itself uses native HTML5 <details>/<summary> — no JS
 * required for toggle. This file only provides safety helpers for
 * interactive children inside <summary>.
 *
 * Historical context (trap #3 + #42 in main CLAUDE.md):
 * When you put a <button> or <input> inside <summary>, clicking the
 * interactive child also fires summary-click → toggles <details>.
 * The only reliable fix is capture-phase + preventDefault + stopPropagation.
 *
 * Usage:
 *   // Call once on document ready. Idempotent.
 *   wwuUIKit.accordion.summaryClickSafe(document);
 *
 *   // Or scoped to a container if you re-render dynamically.
 *   wwuUIKit.accordion.summaryClickSafe(document.querySelector('#my-panel'));
 *
 * @since 0.1.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] accordion.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Selectors considered "interactive children" of <summary> that should
     * NOT propagate to the native summary toggle.
     *
     * Extend this via the third arg of summaryClickSafe() if your plugin
     * adds custom interactive elements (e.g. a Kit-internal filter pill
     * extension). Default covers the 95% case.
     *
     * @type {string}
     */
    var DEFAULT_INTERACTIVE_SELECTOR = [
        'input[type="checkbox"]',
        'input[type="radio"]',
        'input[type="text"]',
        'input[type="number"]',
        'input[type="search"]',
        'select',
        'button',
        '.wwu-ui-group-toggle',
    ].join(', ');

    /**
     * Install capture-phase click blocker on all <summary> elements within
     * `root`. Click on any matched interactive descendant will NOT toggle
     * the parent <details>.
     *
     * Idempotent: safe to call multiple times on the same root.
     *
     * @param {Element|Document} root
     * @param {string} [extraSelector]  Additional CSS selector appended
     *                                  to the default interactive list.
     */
    function summaryClickSafe(root, extraSelector) {
        if (!root || typeof root.addEventListener !== 'function') { return; }
        if (root.__wwuUiKitSummarySafe) { return; } // idempotent guard
        root.__wwuUiKitSummarySafe = true;

        var selector = DEFAULT_INTERACTIVE_SELECTOR;
        if (typeof extraSelector === 'string' && extraSelector.length) {
            selector += ', ' + extraSelector;
        }

        root.addEventListener('click', function (e) {
            // Walk up to the summary (if any) the click originated from.
            var summary = e.target.closest('summary.wwu-ui-accordion-header, summary');
            if (!summary) { return; }

            // Is the actual target (or ancestor up to summary) interactive?
            var interactive = e.target.closest(selector);
            if (interactive && summary.contains(interactive) && interactive !== summary) {
                // Block the native summary-toggle behaviour by preventing the
                // click from reaching <summary> in capture phase. We use ONLY
                // stopPropagation (no preventDefault): the interactive child's
                // own default action (checkbox toggle, input focus, button
                // callback) MUST still fire. preventDefault on a click to a
                // checkbox would cancel the checkbox toggle too — bug.
                //
                // Consumers that need to also cancel a button's default action
                // (e.g. a submit button inside <summary>) should add their own
                // preventDefault in their own click handler, not rely on this
                // kit helper for that.
                e.stopPropagation();
            }
        }, /* capture = */ true);
    }

    /**
     * Update accordion meta badges in bulk.
     *
     * Reads `data-count-*` attributes on a target badge element and
     * re-renders its text. Helper for consumers that want consistent
     * badge formatting across the kit.
     *
     * @param {HTMLElement} badgeEl
     * @param {number} count
     * @param {string} [labelSingular='item']
     * @param {string} [labelPlural='items']
     */
    function updateBadge(badgeEl, count, labelSingular, labelPlural) {
        if (!badgeEl) { return; }
        labelSingular = labelSingular || 'item';
        labelPlural = labelPlural || 'items';
        count = Number(count) || 0;

        if (count === 0) {
            badgeEl.textContent = '';
            badgeEl.hidden = true;
            return;
        }
        badgeEl.hidden = false;
        badgeEl.textContent = count + ' ' + (count === 1 ? labelSingular : labelPlural);
    }

    window.wwuUIKit.accordion = {
        summaryClickSafe: summaryClickSafe,
        updateBadge: updateBadge,
    };
}());
