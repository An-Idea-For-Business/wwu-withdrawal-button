/**
 * WWU UI Kit — Segmented control controller
 *
 * Wires a `.wwu-ui-segmented` group with click + keyboard arrow nav.
 * Uses `wwuUIKit.aria.activateInGroup` + `activateAdjacent` under the
 * hood (DRY — no duplication of state-toggle logic).
 *
 * Usage:
 *
 *   var segmented = wwuUIKit.segmented.bind('.my-segmented', {
 *       onChange: function (value, btn) {
 *           console.log('selected:', value);
 *       },
 *       initial: 'visual',
 *   });
 *
 *   segmented.setValue('split');      // programmatic activation
 *   segmented.getValue();             // current data-value
 *   segmented.destroy();
 *
 * @since 0.9.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (window.console && console.error) {
            console.error('[wwu-ui-kit] segmented.js loaded before ui-kit.js');
        }
        return;
    }

    if (!window.wwuUIKit.aria) {
        if (window.console && console.error) {
            console.error('[wwu-ui-kit] segmented.js requires aria.js to be loaded first');
        }
        return;
    }

    var aria = window.wwuUIKit.aria;

    /**
     * Resolve selector | element.
     *
     * @param {string|HTMLElement|null} ref
     * @returns {HTMLElement|null}
     */
    function resolve(ref) {
        if (!ref) { return null; }
        if (typeof ref === 'string') { return document.querySelector(ref); }
        return ref.nodeType === 1 ? ref : null;
    }

    /**
     * Bind a segmented group.
     *
     * @param {string|HTMLElement} target
     * @param {object} [opts]
     * @param {Function} [opts.onChange]  Fired after activation with `(value, btn)`.
     * @param {string}   [opts.initial]   data-value of initial active button.
     *                                    If not provided, current aria-pressed wins.
     * @returns {{setValue:Function, getValue:Function, getButtons:Function, destroy:Function, element:HTMLElement}|null}
     */
    function bind(target, opts) {
        var root = resolve(target);
        if (!root) { return null; }

        opts = opts || {};
        var onChange = typeof opts.onChange === 'function' ? opts.onChange : null;

        var buttons = root.querySelectorAll('.wwu-ui-segmented__btn');
        if (!buttons.length) { return null; }

        // Ensure ARIA role on container.
        if (!root.hasAttribute('role')) {
            root.setAttribute('role', 'group');
        }

        // Initial activation.
        if (opts.initial) {
            var initial = null;
            Array.prototype.forEach.call(buttons, function (btn) {
                if (btn.getAttribute('data-value') === opts.initial) {
                    initial = btn;
                }
            });
            if (initial) {
                aria.activateInGroup(buttons, initial);
            }
        }

        function getActive() {
            for (var i = 0; i < buttons.length; i++) {
                if (aria.isPressed(buttons[i])) {
                    return buttons[i];
                }
            }
            return null;
        }

        function fire(btn) {
            if (!onChange || !btn) { return; }
            onChange(btn.getAttribute('data-value'), btn);
        }

        function onClick(e) {
            var btn = e.target.closest('.wwu-ui-segmented__btn');
            if (!btn || !root.contains(btn)) { return; }
            if (btn.disabled) { return; }
            if (aria.isPressed(btn)) { return; } // already active
            aria.activateInGroup(buttons, btn);
            fire(btn);
        }

        function onKeydown(e) {
            var key = e.key;
            // Only act on focused button inside this group.
            var active = document.activeElement;
            if (!active || !root.contains(active)) { return; }
            if (!active.classList.contains('wwu-ui-segmented__btn')) { return; }

            var dir = 0;
            if (key === 'ArrowRight' || key === 'ArrowDown') { dir = 1; }
            else if (key === 'ArrowLeft' || key === 'ArrowUp') { dir = -1; }
            else if (key === 'Home') {
                e.preventDefault();
                aria.activateInGroup(buttons, buttons[0]);
                buttons[0].focus();
                fire(buttons[0]);
                return;
            }
            else if (key === 'End') {
                e.preventDefault();
                var last = buttons[buttons.length - 1];
                aria.activateInGroup(buttons, last);
                last.focus();
                fire(last);
                return;
            }

            if (dir === 0) { return; }
            e.preventDefault();
            var next = aria.activateAdjacent(buttons, active, dir);
            if (next) {
                next.focus();
                fire(next);
            }
        }

        root.addEventListener('click', onClick);
        root.addEventListener('keydown', onKeydown);

        function setValue(value) {
            for (var i = 0; i < buttons.length; i++) {
                if (buttons[i].getAttribute('data-value') === value) {
                    aria.activateInGroup(buttons, buttons[i]);
                    return true;
                }
            }
            return false;
        }

        function getValue() {
            var active = getActive();
            return active ? active.getAttribute('data-value') : null;
        }

        function destroy() {
            root.removeEventListener('click', onClick);
            root.removeEventListener('keydown', onKeydown);
        }

        return {
            setValue: setValue,
            getValue: getValue,
            getButtons: function () { return Array.prototype.slice.call(buttons); },
            destroy: destroy,
            element: root,
        };
    }

    /**
     * Auto-bind all `.wwu-ui-segmented[data-wwu-ui-segmented]` on the page.
     * Each container's `onChange` can be wired via consumer code; this
     * helper only enables click + keyboard nav.
     *
     * Idempotent — flagged with `data-wwu-ui-segmented-bound="1"`.
     *
     * @returns {number}
     */
    function autoBind() {
        var nodes = document.querySelectorAll('.wwu-ui-segmented[data-wwu-ui-segmented]:not([data-wwu-ui-segmented-bound="1"])');
        var count = 0;
        Array.prototype.forEach.call(nodes, function (root) {
            bind(root);
            root.setAttribute('data-wwu-ui-segmented-bound', '1');
            count++;
        });
        return count;
    }

    window.wwuUIKit.segmented = {
        bind: bind,
        autoBind: autoBind,
    };
}());
