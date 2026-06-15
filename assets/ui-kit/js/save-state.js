/**
 * WWU UI Kit — Save state inline indicator controller
 *
 * Pairs with `css/save-state.css`. Manages the state transitions:
 *
 *   idle → dirty → saving → saved → idle
 *                          ↘ error
 *
 * Every WWU plugin has its own variant of this (AM Pro fires a toast,
 * CVB has a sticky bar, PM uses inline microcopy with timers). This
 * module standardises the visual idiom: a small dot + microcopy that
 * never shifts layout.
 *
 * Usage (basic):
 *
 *   var saveState = wwuUIKit.saveState.attach('.my-save-state', {
 *       labels: {
 *           idle:   'No changes',
 *           dirty:  'Unsaved changes',
 *           saving: 'Saving…',
 *           saved:  'Saved just now',
 *           error:  'Save failed',
 *       },
 *   });
 *
 *   saveState.set('dirty');          // mark dirty
 *   saveState.set('saving');         // mark saving
 *   saveState.set('saved');          // mark saved (auto-revert to idle in N ms)
 *   saveState.set('error', 'Network error'); // override text
 *
 * Usage (cycle helper — wraps an async save fn):
 *
 *   saveState.cycle(function () {
 *       return fetch('/save', { method: 'POST', body: ... }).then(function (r) {
 *           if (!r.ok) { throw new Error('HTTP ' + r.status); }
 *           return r.json();
 *       });
 *   });
 *   // Automatically: saving → saved | error
 *
 * The DOM is auto-built if missing:
 *
 *   <span class="wwu-ui-save-state">…</span>  → controller adds __dot + __text
 *
 * @since 0.8.4
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (window.console && console.error) {
            console.error('[wwu-ui-kit] save-state.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    var STATES = ['idle', 'dirty', 'saving', 'saved', 'error'];
    var DEFAULT_LABELS = {
        idle:   '',
        dirty:  'Unsaved changes',
        saving: 'Saving…',
        saved:  'Saved',
        error:  'Save failed',
    };

    /**
     * Resolve selector | element.
     *
     * @param {string|HTMLElement|null} ref
     * @returns {HTMLElement|null}
     */
    function resolve(ref) {
        if (!ref) { return null; }
        if (typeof ref === 'string') {
            return document.querySelector(ref);
        }
        return ref.nodeType === 1 ? ref : null;
    }

    /**
     * Ensure the container has the kit's expected children (`__dot` + `__text`).
     * Idempotent.
     *
     * @param {HTMLElement} root
     * @returns {{dot:HTMLElement, text:HTMLElement}}
     */
    function ensureChildren(root) {
        var dot  = root.querySelector('.wwu-ui-save-state__dot');
        var text = root.querySelector('.wwu-ui-save-state__text');

        if (!dot) {
            dot = document.createElement('span');
            dot.className = 'wwu-ui-save-state__dot';
            dot.setAttribute('aria-hidden', 'true');
            root.appendChild(dot);
        }
        if (!text) {
            text = document.createElement('span');
            text.className = 'wwu-ui-save-state__text';
            root.appendChild(text);
        }
        // Live region so screen readers announce state changes.
        if (!root.hasAttribute('aria-live')) {
            root.setAttribute('aria-live', 'polite');
        }
        if (!root.hasAttribute('aria-atomic')) {
            root.setAttribute('aria-atomic', 'true');
        }
        return { dot: dot, text: text };
    }

    /**
     * Attach a controller to a `.wwu-ui-save-state` element.
     *
     * @param {string|HTMLElement} target
     * @param {object} [opts]
     * @param {object} [opts.labels]            Overrides for each state's text.
     * @param {number} [opts.savedRevertMs=2400]  Auto-revert from `saved` → `idle`.
     * @param {Function} [opts.onStateChange]   Fired after state mutation with
     *                                          `(newState, prevState)`.
     * @returns {{set:Function, get:Function, cycle:Function, element:HTMLElement}|null}
     */
    function attach(target, opts) {
        var root = resolve(target);
        if (!root) { return null; }

        opts = opts || {};
        var labels = Object.assign({}, DEFAULT_LABELS, opts.labels || {});
        var savedRevertMs = typeof opts.savedRevertMs === 'number' ? opts.savedRevertMs : 2400;
        var onStateChange = typeof opts.onStateChange === 'function' ? opts.onStateChange : null;

        var refs = ensureChildren(root);
        var state = 'idle';
        var revertTimer = null;

        /**
         * Strip prior state modifier and apply the new one.
         *
         * @param {string} newState
         */
        function applyClass(newState) {
            for (var i = 0; i < STATES.length; i++) {
                root.classList.remove('wwu-ui-save-state--' + STATES[i]);
            }
            root.classList.add('wwu-ui-save-state--' + newState);
        }

        /**
         * Public setter. Accepts an optional `text` override that wins over
         * the default labels for that single call.
         *
         * @param {string} newState
         * @param {string} [text]
         */
        function set(newState, text) {
            if (STATES.indexOf(newState) === -1) {
                if (window.console && console.warn) {
                    console.warn('[wwu-ui-kit] save-state: unknown state', newState);
                }
                return;
            }
            if (revertTimer) {
                clearTimeout(revertTimer);
                revertTimer = null;
            }
            var prev = state;
            state = newState;
            applyClass(newState);
            refs.text.textContent = (typeof text === 'string') ? text : labels[newState];

            if (newState === 'saved' && savedRevertMs > 0) {
                revertTimer = setTimeout(function () {
                    revertTimer = null;
                    // Only auto-revert if we're still in `saved` — a new save
                    // operation may have changed state in the meantime.
                    if (state === 'saved') {
                        set('idle');
                    }
                }, savedRevertMs);
            }

            if (onStateChange) { onStateChange(newState, prev); }
        }

        /**
         * Read current state.
         *
         * @returns {string}
         */
        function get() {
            return state;
        }

        /**
         * Run an async function, cycling state automatically:
         *   saving → saved (on resolve)
         *   saving → error (on reject)
         *
         * Returns the SAME promise the saver returns, so callers can still
         * `.then()/.catch()` on the result.
         *
         * @param {Function} asyncFn  Must return a thenable.
         * @returns {Promise<*>}
         */
        function cycle(asyncFn) {
            set('saving');
            var result;
            try {
                result = asyncFn();
            } catch (sync) {
                set('error', sync && sync.message ? sync.message : null);
                return Promise.reject(sync);
            }
            if (!result || typeof result.then !== 'function') {
                // Synchronous truthy return = success.
                set('saved');
                return Promise.resolve(result);
            }
            return result.then(function (ok) {
                set('saved');
                return ok;
            }, function (err) {
                set('error', err && err.message ? err.message : null);
                throw err;
            });
        }

        // Initial paint.
        applyClass('idle');
        refs.text.textContent = labels.idle;

        return {
            set: set,
            get: get,
            cycle: cycle,
            element: root,
        };
    }

    window.wwuUIKit.saveState = {
        attach: attach,
        STATES: STATES.slice(),
    };
}());
