/**
 * WWU UI Kit — Roving tabindex
 *
 * WAI-ARIA roving-tabindex pattern for groups of related items (toolbar,
 * menubar, tablist, sidebar nav). Only ONE item has `tabindex=0` at a time;
 * others are `-1`. Arrow keys move the "0" around — which means the user
 * tabs into the group once and can then navigate internally without
 * stopping on every item.
 *
 * Standard keyboard model:
 *   ArrowDown / ArrowRight  → next item (wraps)
 *   ArrowUp / ArrowLeft     → previous item (wraps)
 *   Home                    → first item
 *   End                     → last item
 *   (Enter / Space already handled natively by buttons/links)
 *
 * Usage:
 *   wwuUIKit.rovingTabindex.bind('.my-sidebar', {
 *       itemSelector: '.wwu-ui-tab',
 *       orientation: 'vertical',   // 'vertical' | 'horizontal' | 'both'
 *   });
 *
 * Used internally by wwuUIKit.tabs when `sidebarNav: true` option is set.
 *
 * @since 0.4.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] roving-tabindex.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Bind roving tabindex behaviour inside `container`.
     *
     * @param {string|HTMLElement} container
     * @param {object} [opts]
     * @param {string} [opts.itemSelector='[role="menuitem"], .wwu-ui-tab']
     * @param {string} [opts.orientation='vertical']  'vertical' | 'horizontal' | 'both'
     * @param {boolean} [opts.wrap=true]  Wrap at start/end
     * @returns {object} { refresh(), destroy() }
     */
    function bind(container, opts) {
        opts = opts || {};
        var el = typeof container === 'string' ? document.querySelector(container) : container;
        if (!el) { return { refresh: function () {}, destroy: function () {} }; }
        if (el.__wwuUiKitRoving) { return el.__wwuUiKitRoving; }

        var itemSelector = opts.itemSelector || '[role="menuitem"], .wwu-ui-tab';
        var orientation = opts.orientation || 'vertical';
        var wrap = opts.wrap !== false;

        function getItems() {
            var nodes = el.querySelectorAll(itemSelector);
            var result = [];
            for (var i = 0; i < nodes.length; i++) {
                if (!nodes[i].disabled && nodes[i].offsetParent !== null) {
                    result.push(nodes[i]);
                }
            }
            return result;
        }

        /**
         * Ensure exactly one item has tabindex="0" — prefer the .active one
         * if present, otherwise the first.
         */
        function refresh() {
            var items = getItems();
            if (!items.length) { return; }
            var activeIdx = -1;
            for (var i = 0; i < items.length; i++) {
                if (items[i].classList.contains('active')) { activeIdx = i; break; }
            }
            if (activeIdx === -1) { activeIdx = 0; }
            for (var j = 0; j < items.length; j++) {
                items[j].setAttribute('tabindex', j === activeIdx ? '0' : '-1');
            }
        }
        refresh();

        function focusAt(index) {
            var items = getItems();
            if (!items.length) { return; }
            if (wrap) {
                index = ((index % items.length) + items.length) % items.length;
            } else {
                index = Math.max(0, Math.min(items.length - 1, index));
            }
            for (var i = 0; i < items.length; i++) {
                items[i].setAttribute('tabindex', i === index ? '0' : '-1');
            }
            try { items[index].focus(); } catch (_) { /* no-op */ }
        }

        function isNextKey(key) {
            if (orientation === 'vertical') { return key === 'ArrowDown'; }
            if (orientation === 'horizontal') { return key === 'ArrowRight'; }
            return key === 'ArrowDown' || key === 'ArrowRight';
        }

        function isPrevKey(key) {
            if (orientation === 'vertical') { return key === 'ArrowUp'; }
            if (orientation === 'horizontal') { return key === 'ArrowLeft'; }
            return key === 'ArrowUp' || key === 'ArrowLeft';
        }

        function onKeyDown(e) {
            var items = getItems();
            if (!items.length) { return; }
            var current = items.indexOf(document.activeElement);
            if (current === -1) { return; }

            if (isNextKey(e.key)) {
                e.preventDefault();
                focusAt(current + 1);
            } else if (isPrevKey(e.key)) {
                e.preventDefault();
                focusAt(current - 1);
            } else if (e.key === 'Home') {
                e.preventDefault();
                focusAt(0);
            } else if (e.key === 'End') {
                e.preventDefault();
                focusAt(items.length - 1);
            }
        }

        /**
         * Click on an item: update the rover to the clicked item so keyboard
         * navigation resumes from there.
         */
        function onClick(e) {
            var hit = e.target.closest(itemSelector);
            if (!hit || !el.contains(hit)) { return; }
            var items = getItems();
            var idx = items.indexOf(hit);
            if (idx === -1) { return; }
            for (var i = 0; i < items.length; i++) {
                items[i].setAttribute('tabindex', i === idx ? '0' : '-1');
            }
        }

        el.addEventListener('keydown', onKeyDown);
        el.addEventListener('click', onClick);

        var controller = {
            refresh: refresh,
            destroy: function () {
                el.removeEventListener('keydown', onKeyDown);
                el.removeEventListener('click', onClick);
                el.__wwuUiKitRoving = null;
            },
        };

        el.__wwuUiKitRoving = controller;
        return controller;
    }

    window.wwuUIKit.rovingTabindex = {
        bind: bind,
    };
}());
