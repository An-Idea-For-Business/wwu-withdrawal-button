/**
 * WWU UI Kit — Overflow menu controller
 *
 * Thin wrapper around `wwuUIKit.popover.bind()` with overflow-specific
 * conventions:
 *   - Trigger resolved via `.wwu-ui-overflow__trigger`
 *   - Menu resolved via `.wwu-ui-overflow__menu`
 *   - `closeOnSelect: '.wwu-ui-overflow__item'` (default)
 *   - Optional `onItem(value, btn)` callback wired to item clicks
 *     reading `data-value` attribute (Lite convenience over manual
 *     `closeOnSelect` + custom click handler).
 *
 * Usage:
 *
 *   var menu = wwuUIKit.overflow.bind('.wwu-ui-overflow', {
 *       onItem: function (value, btn) {
 *           switch (value) {
 *               case 'duplicate': duplicate(); break;
 *               case 'export':    exportPage(); break;
 *               case 'delete':    confirmDelete(); break;
 *           }
 *       },
 *   });
 *
 *   <button class="wwu-ui-overflow__item" data-value="export">Export</button>
 *
 * @since 0.9.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (window.console && console.error) {
            console.error('[wwu-ui-kit] overflow.js loaded before ui-kit.js');
        }
        return;
    }

    if (!window.wwuUIKit.popover) {
        if (window.console && console.error) {
            console.error('[wwu-ui-kit] overflow.js requires popover.js to be loaded first');
        }
        return;
    }

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
     * Bind an overflow controller.
     *
     * @param {string|HTMLElement} target  The `.wwu-ui-overflow` container.
     * @param {object} [opts]
     * @param {Function} [opts.onItem]  `(value, btn)` fired on item click.
     * @param {Function} [opts.onOpen]
     * @param {Function} [opts.onClose]
     * @returns {object|null}  popover controller, or null if markup invalid.
     */
    function bind(target, opts) {
        var root = resolve(target);
        if (!root) { return null; }

        var trigger = root.querySelector('.wwu-ui-overflow__trigger');
        var menu    = root.querySelector('.wwu-ui-overflow__menu');
        if (!trigger || !menu) {
            if (window.console && console.warn) {
                console.warn('[wwu-ui-kit] overflow.bind: missing __trigger or __menu');
            }
            return null;
        }

        opts = opts || {};
        var onItem = typeof opts.onItem === 'function' ? opts.onItem : null;

        // ARIA hint.
        if (!trigger.hasAttribute('aria-haspopup')) {
            trigger.setAttribute('aria-haspopup', 'menu');
        }
        if (!menu.hasAttribute('role')) {
            menu.setAttribute('role', 'menu');
        }
        Array.prototype.forEach.call(
            menu.querySelectorAll('.wwu-ui-overflow__item'),
            function (item) {
                if (!item.hasAttribute('role')) {
                    item.setAttribute('role', 'menuitem');
                }
            }
        );

        if (onItem) {
            menu.addEventListener('click', function (e) {
                var item = e.target.closest('.wwu-ui-overflow__item');
                if (!item || !menu.contains(item)) { return; }
                if (item.disabled || item.getAttribute('aria-disabled') === 'true') { return; }
                onItem(item.getAttribute('data-value') || '', item);
            });
        }

        return window.wwuUIKit.popover.bind({
            trigger: trigger,
            menu: menu,
            closeOnSelect: '.wwu-ui-overflow__item',
            onOpen: opts.onOpen,
            onClose: opts.onClose,
        });
    }

    /**
     * Auto-bind all `.wwu-ui-overflow[data-wwu-ui-overflow]` on the page.
     * Each container without explicit JS wiring gets click-to-toggle +
     * outside-click dismiss. Item callbacks must still be wired manually
     * (auto-bind cannot guess what each `data-value` should do).
     *
     * Idempotent — flagged with `data-wwu-ui-overflow-bound="1"`.
     *
     * @returns {number}
     */
    function autoBind() {
        var nodes = document.querySelectorAll('.wwu-ui-overflow[data-wwu-ui-overflow]:not([data-wwu-ui-overflow-bound="1"])');
        var count = 0;
        Array.prototype.forEach.call(nodes, function (root) {
            bind(root);
            root.setAttribute('data-wwu-ui-overflow-bound', '1');
            count++;
        });
        return count;
    }

    window.wwuUIKit.overflow = {
        bind: bind,
        autoBind: autoBind,
    };
}());
