/**
 * WWU UI Kit — Rule Card helpers
 *
 * The rule-card component is CSS-first — it works with zero JS because
 * the native `<label>` wraps both checkbox and text. These helpers are
 * only convenience: sync `.is-active` class to checkbox state and emit
 * a change event, so consumers can use CSS selectors that depend on state
 * without relying on `:has(:checked)` (Safari 15.4+ only).
 *
 * Usage:
 *   wwuUIKit.ruleCard.bind('.wwu-ui-rule-grid', {
 *       onChange: function (cardEl, checked, value) {
 *           saveRule(value, checked);
 *       }
 *   });
 *
 * @since 0.5.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] rule-card.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Bind active-state sync + change callback on all `.wwu-ui-rule-card`
     * descendants of the given container.
     *
     * Idempotent: safe to call multiple times on the same container.
     *
     * @param {string|HTMLElement} container
     * @param {object} [opts]
     * @param {Function} [opts.onChange]   (cardEl, checked, value) => void
     * @returns {object} { refresh(), destroy() }
     */
    function bind(container, opts) {
        opts = opts || {};
        var el = typeof container === 'string' ? document.querySelector(container) : container;
        if (!el) { return { refresh: function () {}, destroy: function () {} }; }
        if (el.__wwuUiKitRuleCard) { return el.__wwuUiKitRuleCard; }

        function syncOne(card) {
            var input = card.querySelector('input[type="checkbox"], input[type="radio"]');
            if (!input) { return; }
            card.classList.toggle('is-active', !!input.checked);
        }

        function syncAll() {
            var cards = el.querySelectorAll('.wwu-ui-rule-card');
            for (var i = 0; i < cards.length; i++) {
                syncOne(cards[i]);
            }
        }

        function onChange(e) {
            var input = e.target;
            if (!input || (input.type !== 'checkbox' && input.type !== 'radio')) { return; }
            var card = input.closest('.wwu-ui-rule-card');
            if (!card || !el.contains(card)) { return; }

            // Radio buttons — sync siblings too (they group-deselect).
            if (input.type === 'radio' && input.name) {
                var group = el.querySelectorAll('input[type="radio"][name="' + input.name + '"]');
                for (var i = 0; i < group.length; i++) {
                    var siblingCard = group[i].closest('.wwu-ui-rule-card');
                    if (siblingCard) { syncOne(siblingCard); }
                }
            } else {
                syncOne(card);
            }

            if (typeof opts.onChange === 'function') {
                try {
                    opts.onChange(card, input.checked, input.value);
                } catch (err) {
                    if (console && console.warn) {
                        console.warn('[wwu-ui-kit] rule-card onChange threw:', err);
                    }
                }
            }
        }

        el.addEventListener('change', onChange);
        syncAll();

        var controller = {
            refresh: syncAll,
            destroy: function () {
                el.removeEventListener('change', onChange);
                el.__wwuUiKitRuleCard = null;
            },
        };

        el.__wwuUiKitRuleCard = controller;
        return controller;
    }

    window.wwuUIKit.ruleCard = {
        bind: bind,
    };
}());
