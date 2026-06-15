/**
 * WWU UI Kit — Main JS entry point
 *
 * Exposes the single global `window.wwuUIKit` namespace.
 * Sub-modules (ajax, toast, accordion) attach themselves to this object
 * in their own files, loaded independently by the PHP enqueue helper.
 *
 * This file MUST load before all sub-modules. The PHP loader enforces this
 * via wp_enqueue_script dependencies.
 *
 * @since 0.1.0
 */
(function () {
    'use strict';

    if (window.wwuUIKit) {
        // Kit already initialised by another plugin — do not overwrite.
        // This is the idempotency guarantee: multiple plugins can enqueue
        // the kit; only the first init wins.
        return;
    }

    window.wwuUIKit = {
        /**
         * Semantic version of the kit currently loaded.
         * Sub-modules can read this for compatibility checks.
         *
         * @type {string}
         */
        version: '0.9.2',

        /**
         * Shared configuration injected by consumer plugins.
         * The kit never reads `wwuAmpData` / `wwuAmlData` / `wwuPmData` directly —
         * each consumer must call wwuUIKit.ajax.configure() (and future
         * module configure() methods) with its own ajaxUrl + nonce.
         *
         * @type {object}
         */
        config: {},

        /**
         * Placeholder sub-namespaces. Actual methods are attached by each
         * module file (ajax.js, toast.js, accordion.js). Keeping the
         * placeholders here documents the public surface at a glance.
         */
        ajax: null,
        toast: null,
        accordion: null,
        // 0.2.0
        saveBar: null,
        drawer: null,
        tabs: null,
        tips: null,
        // 0.3.0
        modal: null,
        dropzone: null,
        filterPill: null,
        markdown: null,
        clipboard: null,
        // 0.4.0
        focusTrap: null,
        rovingTabindex: null,
        // 0.5.0
        pagination: null,
        ruleCard: null,
        // 0.6.0
        debugBar: null,
        stepper: null,
        // 0.7.0
        repeater: null,
        // 0.8.4
        aria: null,
        popover: null,
        saveState: null,
        // 0.9.0
        segmented: null,
        overflow: null,
    };

    /**
     * Shared helper — HTML escape for safe insertion into innerHTML.
     * Used internally by modules. Public-facing for consumer plugins too.
     *
     * @param {string} str
     * @returns {string}
     */
    window.wwuUIKit.escHtml = function (str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str == null ? '' : String(str)));
        return div.innerHTML;
    };

    /**
     * Shared helper — attribute escape (safer than escHtml for attr values).
     *
     * @param {string} str
     * @returns {string}
     */
    window.wwuUIKit.escAttr = function (str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    };
}());
