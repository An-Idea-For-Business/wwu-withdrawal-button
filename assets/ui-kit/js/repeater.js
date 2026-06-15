/**
 * WWU UI Kit — Repeater controller
 *
 * Dynamic row list with add/remove buttons and a `<template>` holding
 * the empty-row markup. Uses `__INDEX__` placeholder token in `name=""`
 * attributes so each new row gets a unique index.
 *
 * PHP receiver can reindex on save (consumer responsibility) — indexes
 * do NOT need to be contiguous. Empty rows can be dropped server-side.
 *
 * Markup contract:
 *   <div class="wwu-ui-repeater" data-wwu-ui-repeater>
 *       <div class="wwu-ui-repeater-header">
 *           <span>Label</span>
 *           <span>Value</span>
 *           <span></span>  <!-- empty slot for remove button column -->
 *       </div>
 *       <div data-wwu-ui-repeater-list>
 *           <div class="wwu-ui-repeater-row" data-wwu-ui-repeater-row>
 *               <input type="text" name="rows[0][label]" value="Existing">
 *               <input type="text" name="rows[0][value]" value="...">
 *               <button type="button" class="wwu-ui-repeater-remove" data-wwu-ui-repeater-remove aria-label="Rimuovi">
 *                   <span class="dashicons dashicons-no-alt"></span>
 *               </button>
 *           </div>
 *       </div>
 *       <template data-wwu-ui-repeater-template>
 *           <div class="wwu-ui-repeater-row" data-wwu-ui-repeater-row>
 *               <input type="text" name="rows[__INDEX__][label]" value="">
 *               <input type="text" name="rows[__INDEX__][value]" value="">
 *               <button type="button" class="wwu-ui-repeater-remove" data-wwu-ui-repeater-remove aria-label="Rimuovi">
 *                   <span class="dashicons dashicons-no-alt"></span>
 *               </button>
 *           </div>
 *       </template>
 *       <button type="button" class="button wwu-ui-repeater-add"
 *               data-wwu-ui-repeater-add data-wwu-ui-repeater-add-idx="0">
 *           + Aggiungi riga
 *       </button>
 *   </div>
 *
 * Usage:
 *   wwuUIKit.repeater.bind('[data-wwu-ui-repeater]', {
 *       minRows: 1,                    // keep at least N rows visible (clear fields instead of remove)
 *       onAdd: function (rowEl) { ... },
 *       onRemove: function (rowEl) { ... },
 *   });
 *
 * Or bind a specific instance:
 *   wwuUIKit.repeater.bind(document.querySelector('#my-repeater'), opts);
 *
 * @since 0.7.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] repeater.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Bind repeater behaviour to one container or all matches of a selector.
     *
     * @param {string|HTMLElement|NodeList} target
     * @param {object} [opts]
     * @param {number} [opts.minRows=1]  Keep at least N rows; remove on minimum clears fields instead
     * @param {Function} [opts.onAdd]     (rowEl, containerEl) => void
     * @param {Function} [opts.onRemove]  (rowEl, containerEl) => void
     * @returns {object[]}  Array of controllers, one per container
     */
    function bind(target, opts) {
        opts = opts || {};
        var containers = [];
        if (typeof target === 'string') {
            containers = Array.prototype.slice.call(document.querySelectorAll(target));
        } else if (target && typeof target.length === 'number' && !(target instanceof HTMLElement)) {
            containers = Array.prototype.slice.call(target);
        } else if (target instanceof HTMLElement) {
            containers = [target];
        }

        return containers.map(function (root) {
            return bindOne(root, opts);
        });
    }

    /**
     * Bind a single container. Idempotent.
     *
     * @param {HTMLElement} root
     * @param {object} opts
     * @returns {object}
     */
    function bindOne(root, opts) {
        if (!root) { return createNoop(); }
        if (root.__wwuUiKitRepeater) { return root.__wwuUiKitRepeater; }

        var minRows = typeof opts.minRows === 'number' ? Math.max(0, opts.minRows) : 1;

        /**
         * Clear text/number/email/url inputs in a row — preserves existing
         * `defaultValue` (e.g. currency "EUR" prefilled). Used when
         * remove is clicked on the last remaining row at minRows threshold.
         */
        function clearRow(row) {
            var inputs = row.querySelectorAll('input[type="text"], input[type="number"], input[type="email"], input[type="url"], input[type="password"], textarea, select');
            for (var i = 0; i < inputs.length; i++) {
                var el = inputs[i];
                if (el.tagName === 'SELECT') {
                    el.selectedIndex = 0;
                } else {
                    el.value = el.defaultValue || '';
                }
            }
            var checks = row.querySelectorAll('input[type="checkbox"], input[type="radio"]');
            for (var j = 0; j < checks.length; j++) {
                checks[j].checked = checks[j].defaultChecked;
            }
        }

        function onClick(e) {
            // Remove button.
            var removeBtn = e.target.closest('[data-wwu-ui-repeater-remove]');
            if (removeBtn && root.contains(removeBtn)) {
                e.preventDefault();
                var row = removeBtn.closest('[data-wwu-ui-repeater-row]');
                if (!row || !row.parentNode) { return; }
                var siblings = row.parentNode.querySelectorAll('[data-wwu-ui-repeater-row]');
                if (siblings.length <= minRows) {
                    clearRow(row);
                    if (typeof opts.onRemove === 'function') {
                        try { opts.onRemove(row, root); } catch (_) { /* no-op */ }
                    }
                    return;
                }
                if (typeof opts.onRemove === 'function') {
                    try { opts.onRemove(row, root); } catch (_) { /* no-op */ }
                }
                row.parentNode.removeChild(row);
                return;
            }

            // Add button.
            var addBtn = e.target.closest('[data-wwu-ui-repeater-add]');
            if (!addBtn || !root.contains(addBtn)) { return; }
            e.preventDefault();

            var targetSel = addBtn.getAttribute('data-wwu-ui-repeater-target') || '[data-wwu-ui-repeater-list]';
            var list = root.querySelector(targetSel);
            if (!list) { return; }

            var tpl = root.querySelector('[data-wwu-ui-repeater-template]');
            if (!tpl || !tpl.content) { return; }

            // Increment index stored on the add button so each new row has a unique one.
            var nextIdx = parseInt(addBtn.getAttribute('data-wwu-ui-repeater-add-idx') || '0', 10) + 1;
            addBtn.setAttribute('data-wwu-ui-repeater-add-idx', String(nextIdx));

            var clone = tpl.content.cloneNode(true);
            // Replace __INDEX__ placeholder in every name/id attribute.
            var named = clone.querySelectorAll('[name]');
            for (var i = 0; i < named.length; i++) {
                var nm = named[i].getAttribute('name');
                if (nm) { named[i].setAttribute('name', nm.replace(/__INDEX__/g, String(nextIdx))); }
            }
            var ided = clone.querySelectorAll('[id]');
            for (var j = 0; j < ided.length; j++) {
                var id = ided[j].getAttribute('id');
                if (id) { ided[j].setAttribute('id', id.replace(/__INDEX__/g, String(nextIdx))); }
            }

            // Grab a reference to the new row BEFORE appending (after append the fragment is empty).
            var newRow = clone.querySelector('[data-wwu-ui-repeater-row]');
            list.appendChild(clone);

            if (newRow && typeof opts.onAdd === 'function') {
                try { opts.onAdd(newRow, root); } catch (_) { /* no-op */ }
            }
        }

        root.addEventListener('click', onClick);

        /**
         * Return current row elements (live snapshot).
         */
        function getRows() {
            return Array.prototype.slice.call(root.querySelectorAll('[data-wwu-ui-repeater-row]'));
        }

        /**
         * Programmatically add a new row (same as clicking the add button).
         */
        function addRow() {
            var addBtn = root.querySelector('[data-wwu-ui-repeater-add]');
            if (addBtn) { addBtn.click(); }
        }

        var controller = {
            addRow: addRow,
            getRows: getRows,
            destroy: function () {
                root.removeEventListener('click', onClick);
                root.__wwuUiKitRepeater = null;
            },
            element: root,
        };

        root.__wwuUiKitRepeater = controller;
        return controller;
    }

    function createNoop() {
        return {
            addRow: function () {},
            getRows: function () { return []; },
            destroy: function () {},
            element: null,
        };
    }

    window.wwuUIKit.repeater = {
        bind: bind,
    };
}());
