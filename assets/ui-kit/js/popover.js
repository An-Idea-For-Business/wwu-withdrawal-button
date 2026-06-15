/**
 * WWU UI Kit — Popover / dropdown menu controller
 *
 * Generic toggle-on-click popover with dismissal on outside click, Escape,
 * blur (focus-out), and trigger-second-click. Used internally by the
 * `overflow` component (0.9.0) and exposed as a public utility for any
 * consumer that needs a small dropdown without rolling its own.
 *
 * The kit also provides:
 *   - wwuUIKit.drawer  — large side-in panel (modal-ish)
 *   - wwuUIKit.modal   — centered dialog
 *   - wwuUIKit.popover — small floating menu anchored to a trigger (THIS)
 *
 * Usage:
 *
 *   var pop = wwuUIKit.popover.bind({
 *       trigger: document.querySelector('[data-action="toggle-menu"]'),
 *       menu:    document.querySelector('.my-menu'),
 *       onOpen:  function () { ... },
 *       onClose: function () { ... },
 *       // Optional:
 *       closeOnSelect: '.my-menu__item', // selector for items inside menu
 *                                         // that should close it on click
 *       container: document.body,         // listen to outside-click within
 *   });
 *   pop.open(); pop.close(); pop.toggle(); pop.isOpen(); pop.destroy();
 *
 * The menu element MUST start with `hidden` attribute (or `[hidden]` CSS
 * fallback). The controller toggles `hidden` + `aria-expanded` on the
 * trigger + emits `wwu-ui-popover:open` / `:close` CustomEvents on the
 * menu element (bubbling) so external listeners can react.
 *
 * Trap #19 reminder: if your CSS sets `display: flex|block|...` on the
 * menu, pair it with `.your-menu[hidden] { display: none !important }`
 * — otherwise the `hidden` attribute is overridden by author CSS.
 *
 * @since 0.8.4
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (window.console && console.error) {
            console.error('[wwu-ui-kit] popover.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Resolve an element from selector | HTMLElement | null.
     *
     * @param {string|HTMLElement|null|undefined} ref
     * @param {Document|HTMLElement} [root=document]
     * @returns {HTMLElement|null}
     */
    function resolve(ref, root) {
        if (!ref) { return null; }
        if (typeof ref === 'string') {
            return (root || document).querySelector(ref);
        }
        if (ref.nodeType === 1) { return ref; }
        return null;
    }

    /**
     * Bind a popover controller.
     *
     * @param {object} opts
     * @param {string|HTMLElement} opts.trigger
     * @param {string|HTMLElement} opts.menu
     * @param {Function} [opts.onOpen]
     * @param {Function} [opts.onClose]
     * @param {string}   [opts.closeOnSelect]  CSS selector for menu items
     *                                         that should close the popover.
     * @param {HTMLElement} [opts.container=document]  Outside-click watch root.
     * @returns {{open:Function, close:Function, toggle:Function, isOpen:Function, destroy:Function, trigger:HTMLElement, menu:HTMLElement}|null}
     */
    function bind(opts) {
        opts = opts || {};
        var trigger = resolve(opts.trigger);
        var menu    = resolve(opts.menu);

        if (!trigger || !menu) {
            if (window.console && console.warn) {
                console.warn('[wwu-ui-kit] popover.bind: trigger or menu not found.');
            }
            return null;
        }

        var container = opts.container || document;
        var onOpen    = typeof opts.onOpen === 'function' ? opts.onOpen : null;
        var onClose   = typeof opts.onClose === 'function' ? opts.onClose : null;
        var closeSel  = typeof opts.closeOnSelect === 'string' ? opts.closeOnSelect : null;

        var ariaSetter = window.wwuUIKit.aria && window.wwuUIKit.aria.setExpanded;
        if (ariaSetter) {
            ariaSetter(trigger, false);
        } else {
            trigger.setAttribute('aria-expanded', 'false');
        }
        // Ensure initial hidden state on the menu.
        if (!menu.hasAttribute('hidden')) {
            menu.hidden = true;
        }

        var isOpenState = false;
        var destroyed   = false;

        function dispatchEvent(name) {
            try {
                var ev = new CustomEvent('wwu-ui-popover:' + name, {
                    bubbles: true,
                    detail: { trigger: trigger, menu: menu },
                });
                menu.dispatchEvent(ev);
            } catch (_) {
                // CustomEvent constructor unavailable (very legacy IE) — ignore.
            }
        }

        function open() {
            if (destroyed || isOpenState) { return; }
            isOpenState = true;
            menu.hidden = false;
            if (ariaSetter) {
                ariaSetter(trigger, true);
            } else {
                trigger.setAttribute('aria-expanded', 'true');
            }
            // Defer listener install to next tick so the trigger's own click
            // event (which is bubbling right now) doesn't immediately match
            // as an "outside click" and close us again.
            setTimeout(function () {
                if (!isOpenState) { return; }
                container.addEventListener('click', onOutsideClick, true);
                document.addEventListener('keydown', onKeyDown, true);
            }, 0);
            if (onOpen) { onOpen(); }
            dispatchEvent('open');
        }

        function close() {
            if (destroyed || !isOpenState) { return; }
            isOpenState = false;
            menu.hidden = true;
            if (ariaSetter) {
                ariaSetter(trigger, false);
            } else {
                trigger.setAttribute('aria-expanded', 'false');
            }
            container.removeEventListener('click', onOutsideClick, true);
            document.removeEventListener('keydown', onKeyDown, true);
            if (onClose) { onClose(); }
            dispatchEvent('close');
        }

        function toggle() {
            if (isOpenState) { close(); } else { open(); }
        }

        function onTriggerClick(e) {
            e.preventDefault();
            e.stopPropagation();
            toggle();
        }

        function onOutsideClick(e) {
            var t = e.target;
            // Inside trigger? Let triggerClick handle it (already toggling).
            if (trigger.contains(t)) { return; }
            // Click on a flagged item inside the menu → close after the click.
            if (closeSel && menu.contains(t) && t.closest(closeSel)) {
                // Let the click default action run, then close.
                setTimeout(close, 0);
                return;
            }
            // Click strictly inside the menu but not on a close-item → stay open.
            if (menu.contains(t)) { return; }
            close();
        }

        function onKeyDown(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                e.stopPropagation();
                close();
                // Return focus to the trigger for keyboard users.
                if (trigger && typeof trigger.focus === 'function') {
                    trigger.focus();
                }
            }
        }

        trigger.addEventListener('click', onTriggerClick);

        function destroy() {
            if (destroyed) { return; }
            destroyed = true;
            close();
            trigger.removeEventListener('click', onTriggerClick);
        }

        return {
            open: open,
            close: close,
            toggle: toggle,
            isOpen: function () { return isOpenState; },
            destroy: destroy,
            trigger: trigger,
            menu: menu,
        };
    }

    /**
     * Auto-wire any `[data-wwu-ui-popover]` element on the page. The element
     * is the TRIGGER. The menu is resolved via `data-wwu-ui-popover-menu`
     * which can be a CSS selector OR an ID (with or without `#`).
     *
     *   <button data-wwu-ui-popover data-wwu-ui-popover-menu="#my-menu">…</button>
     *   <div id="my-menu" class="my-menu" hidden>…</div>
     *
     * Optional attrs:
     *   data-wwu-ui-popover-close-on="<selector>"  — close after click on
     *                                                items matching selector
     *
     * Idempotent — already-bound triggers are flagged with
     * `data-wwu-ui-popover-bound="1"` and skipped on subsequent calls.
     *
     * @returns {number} How many controllers were bound this call.
     */
    function autoBind() {
        var nodes = document.querySelectorAll('[data-wwu-ui-popover]:not([data-wwu-ui-popover-bound="1"])');
        var count = 0;
        Array.prototype.forEach.call(nodes, function (trigger) {
            var menuRef = trigger.getAttribute('data-wwu-ui-popover-menu');
            if (!menuRef) { return; }
            if (menuRef.charAt(0) !== '#' && menuRef.charAt(0) !== '.') {
                // bare id
                menuRef = '#' + menuRef;
            }
            var menu = document.querySelector(menuRef);
            if (!menu) { return; }
            bind({
                trigger: trigger,
                menu: menu,
                closeOnSelect: trigger.getAttribute('data-wwu-ui-popover-close-on') || null,
            });
            trigger.setAttribute('data-wwu-ui-popover-bound', '1');
            count++;
        });
        return count;
    }

    window.wwuUIKit.popover = {
        bind: bind,
        autoBind: autoBind,
    };
}());
