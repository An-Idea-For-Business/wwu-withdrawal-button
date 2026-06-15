/**
 * WWU UI Kit — Feature Tip toggles
 *
 * Wires up `.wwu-ui-tip-toggle` buttons to show/hide their associated
 * `.wwu-ui-tip-box` (identified via `data-tip` attribute).
 *
 * Markup:
 *   <button class="wwu-ui-tip-toggle" data-tip="my-tip">?</button>
 *   <div id="my-tip" class="wwu-ui-tip-box">
 *       <strong>Lorem ipsum</strong>
 *       Text content explaining the feature.
 *   </div>
 *
 * Behaviour:
 * - Clicking a toggle opens its tip (and closes any other open tip).
 * - Clicking again on the same toggle closes it.
 * - Tip can be programmatically dismissed via wwuUIKit.tips.closeAll().
 *
 * Extracted from AM Pro admin.js initTips() (Pro 1.1.0+).
 *
 * @since 0.2.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] tips.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    var initialized = false;

    /**
     * Install the document-level click listener. Idempotent.
     */
    function init() {
        if (initialized) { return; }
        initialized = true;

        document.addEventListener('click', function (e) {
            var toggle = e.target.closest('.wwu-ui-tip-toggle');
            if (!toggle) {
                // Click outside any tip — close all.
                if (!e.target.closest('.wwu-ui-tip-box')) {
                    closeAll();
                }
                return;
            }

            var tipId = toggle.getAttribute('data-tip');
            if (!tipId) { return; }

            e.preventDefault();
            e.stopPropagation();

            var tipBox = document.getElementById(tipId);
            if (!tipBox) { return; }

            var isVisible = tipBox.classList.contains('visible');

            // Close all other tips first.
            closeAll();

            if (!isVisible) {
                tipBox.classList.add('visible');
                toggle.classList.add('active');
            }
        });
    }

    /**
     * Close all open tips.
     */
    function closeAll() {
        var boxes = document.querySelectorAll('.wwu-ui-tip-box.visible');
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].classList.remove('visible');
        }
        var toggles = document.querySelectorAll('.wwu-ui-tip-toggle.active');
        for (var j = 0; j < toggles.length; j++) {
            toggles[j].classList.remove('active');
        }
    }

    window.wwuUIKit.tips = {
        init: init,
        closeAll: closeAll,
    };

    // Auto-init on DOMContentLoaded — zero-config for most consumers.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
