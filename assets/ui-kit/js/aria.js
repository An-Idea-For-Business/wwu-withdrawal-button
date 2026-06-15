/**
 * WWU UI Kit — ARIA state helpers
 *
 * Tiny vanilla utilities for the most common ARIA attribute toggles.
 * Every WWU plugin had its own copy of `setPressed(btn, bool)` and
 * `activateInGroup(group, target)` reinvented inline — this module is
 * the single source of truth.
 *
 * Usage:
 *
 *   // Toggle aria-pressed on a single button.
 *   wwuUIKit.aria.setPressed(btn, true);
 *
 *   // Mutually-exclusive activation across a button group (segmented, tabs,
 *   // device tabs, etc.). The target gets aria-pressed=true, the rest false.
 *   wwuUIKit.aria.activateInGroup(buttons, target);
 *
 *   // aria-expanded for disclosure / dropdown triggers.
 *   wwuUIKit.aria.setExpanded(trigger, true);
 *
 *   // aria-selected for listbox / option-pattern UIs.
 *   wwuUIKit.aria.setSelected(option, true);
 *
 *   // aria-current for the "you are here" pattern (breadcrumb, history row,
 *   // tree row). Accepts boolean OR a value string ('step', 'page', 'date'...).
 *   wwuUIKit.aria.setCurrent(node, 'page');
 *   wwuUIKit.aria.setCurrent(node, false); // removes the attr entirely
 *
 *   // Read state.
 *   wwuUIKit.aria.isPressed(btn);  // boolean
 *
 * Design notes:
 *   - All setters accept HTMLElement OR null (no-op on null) so callers can
 *     pipe results of `querySelector` without guard. Documented per method.
 *   - Group activation accepts arrays, NodeLists, HTMLCollection — anything
 *     iterable. The `target` does NOT need to be inside the group; if it
 *     isn't, every member gets aria-pressed=false (deactivate-all pattern).
 *   - Zero DOM creation, zero side effects beyond attribute writes. Safe to
 *     call inside RAF callbacks.
 *
 * @since 0.8.4
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (window.console && console.error) {
            console.error('[wwu-ui-kit] aria.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Coerce truthy/falsy to the literal strings ARIA expects.
     *
     * @param {*} value
     * @returns {'true'|'false'}
     */
    function bool(value) {
        return value ? 'true' : 'false';
    }

    /**
     * Set `aria-pressed`. No-op when `el` is null.
     *
     * @param {HTMLElement|null} el
     * @param {boolean} pressed
     */
    function setPressed(el, pressed) {
        if (!el) { return; }
        el.setAttribute('aria-pressed', bool(pressed));
    }

    /**
     * Read `aria-pressed`. Returns false for null/missing attr.
     *
     * @param {HTMLElement|null} el
     * @returns {boolean}
     */
    function isPressed(el) {
        if (!el) { return false; }
        return el.getAttribute('aria-pressed') === 'true';
    }

    /**
     * Set `aria-expanded`. No-op when `el` is null.
     *
     * @param {HTMLElement|null} el
     * @param {boolean} expanded
     */
    function setExpanded(el, expanded) {
        if (!el) { return; }
        el.setAttribute('aria-expanded', bool(expanded));
    }

    /**
     * Read `aria-expanded`. Returns false for null/missing attr.
     *
     * @param {HTMLElement|null} el
     * @returns {boolean}
     */
    function isExpanded(el) {
        if (!el) { return false; }
        return el.getAttribute('aria-expanded') === 'true';
    }

    /**
     * Set `aria-selected`. No-op when `el` is null.
     *
     * @param {HTMLElement|null} el
     * @param {boolean} selected
     */
    function setSelected(el, selected) {
        if (!el) { return; }
        el.setAttribute('aria-selected', bool(selected));
    }

    /**
     * Set `aria-current`. Accepts boolean OR a value string per WAI-ARIA
     * spec (`'page' | 'step' | 'location' | 'date' | 'time' | 'true'`).
     * Passing `false` REMOVES the attribute entirely (no `aria-current=false`,
     * which is technically valid but confusing — the spec recommends absence).
     *
     * @param {HTMLElement|null} el
     * @param {boolean|string} value
     */
    function setCurrent(el, value) {
        if (!el) { return; }
        if (value === false || value === '' || value == null) {
            el.removeAttribute('aria-current');
            return;
        }
        if (value === true) {
            el.setAttribute('aria-current', 'true');
            return;
        }
        el.setAttribute('aria-current', String(value));
    }

    /**
     * Activate `target` inside the group via `aria-pressed`. All other
     * members get `aria-pressed=false`. If `target` is null, the whole
     * group is deactivated.
     *
     * The group can be:
     *   - HTMLElement[] (array of nodes)
     *   - NodeList / HTMLCollection (live or static)
     *   - any iterable yielding HTMLElement
     *
     * @param {Iterable<HTMLElement>} group
     * @param {HTMLElement|null} target
     */
    function activateInGroup(group, target) {
        if (!group) { return; }
        var iterable = (typeof group.forEach === 'function')
            ? group
            : Array.prototype.slice.call(group);
        iterable.forEach(function (el) {
            if (!el || !el.setAttribute) { return; }
            setPressed(el, el === target);
        });
    }

    /**
     * Cycle activation among a group (radio-like). Given a current target
     * and a direction (1 = next, -1 = prev), activate the adjacent item
     * with wrap-around. Returns the newly activated element, or null.
     *
     * Useful for keyboard nav inside segmented / tabs.
     *
     * @param {ArrayLike<HTMLElement>} group
     * @param {HTMLElement|null} current
     * @param {1|-1} dir
     * @returns {HTMLElement|null}
     */
    function activateAdjacent(group, current, dir) {
        if (!group || !group.length) { return null; }
        var items = Array.prototype.slice.call(group);
        var idx = current ? items.indexOf(current) : -1;
        if (idx === -1) {
            // No current — pick first if going forward, last if going back.
            idx = dir === 1 ? 0 : items.length - 1;
        } else {
            idx = (idx + dir + items.length) % items.length;
        }
        var next = items[idx] || null;
        activateInGroup(items, next);
        return next;
    }

    window.wwuUIKit.aria = {
        setPressed: setPressed,
        isPressed: isPressed,
        setExpanded: setExpanded,
        isExpanded: isExpanded,
        setSelected: setSelected,
        setCurrent: setCurrent,
        activateInGroup: activateInGroup,
        activateAdjacent: activateAdjacent,
    };
}());
