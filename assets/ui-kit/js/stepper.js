/**
 * WWU UI Kit — Stepper controller
 *
 * Minimal helper for managing step state (active/complete) on a
 * `.wwu-ui-stepper`. Pure CSS works for static indicators — this JS
 * adds programmatic navigation for wizard flows.
 *
 * Usage:
 *   var stepper = wwuUIKit.stepper.create('#my-stepper', {
 *       initialStep: 1,
 *       clickable: true,          // allow clicking completed steps
 *       onChange: function (current, previous) {
 *           showStepPanel(current);
 *       },
 *   });
 *
 *   stepper.next();
 *   stepper.prev();
 *   stepper.goTo(3);
 *   stepper.complete();           // marks current as complete and advances
 *
 * @since 0.6.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] stepper.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Create a stepper controller.
     *
     * @param {string|HTMLElement} target
     * @param {object} [opts]
     * @param {number} [opts.initialStep=1]     1-indexed
     * @param {boolean} [opts.clickable=false]  Click on completed/active to jump
     * @param {Function} [opts.onChange]        (currentStep, previousStep) => void
     * @returns {object}
     */
    function create(target, opts) {
        opts = opts || {};
        var root = typeof target === 'string' ? document.querySelector(target) : target;
        if (!root) { return createNoop(); }

        var steps = root.querySelectorAll('.wwu-ui-stepper-step');
        if (!steps.length) { return createNoop(); }

        var current = Math.max(1, Math.min(steps.length, Number(opts.initialStep) || 1));

        function applyState() {
            for (var i = 0; i < steps.length; i++) {
                var step = steps[i];
                var stepNum = i + 1;
                step.classList.remove('is-active', 'is-complete', 'is-upcoming');
                if (stepNum < current) {
                    step.classList.add('is-complete');
                } else if (stepNum === current) {
                    step.classList.add('is-active');
                } else {
                    step.classList.add('is-upcoming');
                }
                if (opts.clickable) {
                    step.classList.add('is-clickable');
                } else {
                    step.classList.remove('is-clickable');
                }
                step.setAttribute('aria-current', stepNum === current ? 'step' : 'false');
            }
        }

        function fireChange(prev) {
            if (typeof opts.onChange === 'function') {
                try { opts.onChange(current, prev); } catch (err) {
                    if (console && console.warn) {
                        console.warn('[wwu-ui-kit] stepper onChange threw:', err);
                    }
                }
            }
        }

        function goTo(n) {
            n = Math.max(1, Math.min(steps.length, Number(n)));
            if (n === current) { return; }
            var prev = current;
            current = n;
            applyState();
            fireChange(prev);
        }

        function next() { goTo(current + 1); }
        function prev() { goTo(current - 1); }

        /**
         * Mark current step complete and advance to next.
         * If already on last step, stays there but keeps it complete.
         */
        function complete() {
            if (current >= steps.length) {
                // Already on last step — mark it complete without advancing.
                steps[current - 1].classList.remove('is-active');
                steps[current - 1].classList.add('is-complete');
                return;
            }
            next();
        }

        if (opts.clickable) {
            root.addEventListener('click', function (e) {
                var hit = e.target.closest('.wwu-ui-stepper-step');
                if (!hit || !root.contains(hit)) { return; }
                var idx = Array.prototype.indexOf.call(steps, hit);
                if (idx < 0) { return; }
                var stepNum = idx + 1;
                // Only allow clicking active or complete steps (never future).
                if (stepNum > current) { return; }
                goTo(stepNum);
            });
        }

        applyState();

        return {
            goTo: goTo,
            next: next,
            prev: prev,
            complete: complete,
            current: function () { return current; },
            total: function () { return steps.length; },
            element: root,
        };
    }

    function createNoop() {
        return {
            goTo: function () {},
            next: function () {},
            prev: function () {},
            complete: function () {},
            current: function () { return 0; },
            total: function () { return 0; },
            element: null,
        };
    }

    window.wwuUIKit.stepper = {
        create: create,
    };
}());
