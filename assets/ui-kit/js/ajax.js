/**
 * WWU UI Kit — AJAX helper
 *
 * FormData-based POST to admin-ajax.php with recursive bracket notation
 * support for nested objects/arrays. This is a 1-to-1 port of `proAjax()` +
 * `appendFD()` from AM Pro 1.3.1 — see trap #18 in the main CLAUDE.md.
 *
 * Without bracket notation, `FormData.append(k, obj)` serialises nested
 * objects as the literal string "[object Object]", which silently breaks
 * PHP receivers that expect `$_POST['foo']['bar']`.
 *
 * Usage:
 *   // Configure once per page (consumer plugin passes its own nonce).
 *   wwuUIKit.ajax.configure({ ajaxUrl: ajaxurl, nonce: myNonce });
 *
 *   // Then call anywhere.
 *   wwuUIKit.ajax('my_action', { pixel: { id: 42, tags: ['a','b'] } }, function (ok, data) {
 *       if (ok) { console.log('saved'); }
 *   });
 *
 * @since 0.1.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] ajax.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    var cfg = {
        ajaxUrl: '',
        nonce: '',
        /**
         * Form field name for the nonce. WordPress's `check_ajax_referer()`
         * accepts a custom field name as its second arg; plugins historically
         * picked different conventions (`nonce`, `_nonce`, `_ajax_nonce`,
         * `_wpnonce`). The kit default is `nonce` (matches AM Pro `proAjax`);
         * consumers using a different field name configure via `nonceField`.
         *
         * @since 0.8.2
         */
        nonceField: 'nonce',
        /**
         * Optional alias nonce field names.
         *
         * Useful during migrations where some endpoints still read legacy
         * field names. The helper appends the same nonce value to all
         * configured fields so the server can accept either one.
         *
         * @since 0.9.2
         */
        extraNonceFields: [],
        defaultErrorMsg: 'Error',
        /**
         * Default request timeout in ms. 30s matches `wp_remote_request` default.
         * Requests that take longer than this get aborted via AbortController
         * and the callback fires with `success=false, data={timeout:true}`.
         *
         * @since 0.8.0
         */
        timeoutMs: 30000,
    };

    /**
     * Append a value to FormData, supporting nested objects/arrays via
     * PHP-friendly bracket notation.
     *
     * Special cases:
     * - null/undefined       → empty string
     * - File/Blob            → passed through (multipart upload support)
     * - Array                → foo[0], foo[1], … recursive
     * - Object               → foo[key] recursive
     * - Boolean              → "1"/"0" (explicit for PHP truthiness clarity)
     * - number/string        → toString
     *
     * @param {FormData} fd
     * @param {string}   key
     * @param {*}        value
     */
    function appendFD(fd, key, value) {
        if (value === null || typeof value === 'undefined') {
            fd.append(key, '');
            return;
        }
        if (typeof File !== 'undefined' && value instanceof File) {
            fd.append(key, value);
            return;
        }
        if (typeof Blob !== 'undefined' && value instanceof Blob) {
            fd.append(key, value);
            return;
        }
        if (Array.isArray(value)) {
            for (var i = 0; i < value.length; i++) {
                appendFD(fd, key + '[' + i + ']', value[i]);
            }
            return;
        }
        if (typeof value === 'object') {
            for (var k in value) {
                if (Object.prototype.hasOwnProperty.call(value, k)) {
                    appendFD(fd, key + '[' + k + ']', value[k]);
                }
            }
            return;
        }
        if (typeof value === 'boolean') {
            fd.append(key, value ? '1' : '0');
            return;
        }
        fd.append(key, value);
    }

    /**
     * Perform the AJAX call.
     *
     * @param {string}   action    WP action name (matches wp_ajax_{action})
     * @param {object}   data      Payload, will be flattened via appendFD
     * @param {function} callback  (success: boolean, data: object) => void
     * @param {object}   [options] Optional overrides
     *                             { ajaxUrl, nonce, nonceField, extraNonceFields, timeoutMs }
     */
    function ajaxCall(action, data, callback, options) {
        options = options || {};
        var url = options.ajaxUrl || cfg.ajaxUrl;
        var nonce = options.nonce || cfg.nonce;
        var nonceField = options.nonceField || cfg.nonceField;
        var extraNonceFields = Array.isArray(options.extraNonceFields) ? options.extraNonceFields : cfg.extraNonceFields;
        var timeoutMs = typeof options.timeoutMs === 'number' ? options.timeoutMs : cfg.timeoutMs;

        if (!url) {
            if (console && console.error) {
                console.error('[wwu-ui-kit] ajax.call: missing ajaxUrl — call configure() first');
            }
            if (typeof callback === 'function') {
                callback(false, { message: cfg.defaultErrorMsg });
            }
            return;
        }

        var fd = new FormData();
        fd.append('action', action);
        // Append nonce to primary + alias fields (deduplicated).
        var nonceFields = [nonceField];
        if (Array.isArray(extraNonceFields)) {
            extraNonceFields.forEach(function (f) {
                if (typeof f === 'string' && f && nonceFields.indexOf(f) === -1) {
                    nonceFields.push(f);
                }
            });
        }
        nonceFields.forEach(function (f) {
            fd.append(f, nonce);
        });

        if (data && typeof data === 'object') {
            for (var k in data) {
                if (Object.prototype.hasOwnProperty.call(data, k)) {
                    appendFD(fd, k, data[k]);
                }
            }
        }

        // AbortController for timeout. Available in all supported browsers
        // (Chrome 66+, Firefox 57+, Safari 12.1+). Graceful fallback: if
        // AbortController is missing, timeout won't fire but the request
        // still runs normally.
        var controller = null;
        var timeoutTimer = null;
        var fetchOpts = {
            method: 'POST',
            credentials: 'same-origin',
            body: fd,
        };
        if (typeof AbortController !== 'undefined' && timeoutMs > 0) {
            controller = new AbortController();
            fetchOpts.signal = controller.signal;
            timeoutTimer = setTimeout(function () {
                try { controller.abort(); } catch (_) { /* no-op */ }
            }, timeoutMs);
        }

        fetch(url, fetchOpts)
            .then(function (r) {
                if (timeoutTimer) { clearTimeout(timeoutTimer); timeoutTimer = null; }
                return r.json();
            })
            .then(function (json) {
                if (typeof callback === 'function') {
                    callback(!!json.success, json.data || {});
                }
            })
            .catch(function (err) {
                if (timeoutTimer) { clearTimeout(timeoutTimer); timeoutTimer = null; }
                var isAbort = err && err.name === 'AbortError';
                if (console && console.warn) {
                    console.warn('[wwu-ui-kit] ajax ' + (isAbort ? 'timeout' : 'error') + ':', err);
                }
                if (typeof callback === 'function') {
                    callback(false, {
                        message: cfg.defaultErrorMsg,
                        timeout: isAbort,
                    });
                }
            });
    }

    /**
     * Attach configure() as a static on the ajax callable. Consumer plugin
     * sets ajaxUrl + nonce once; all subsequent calls reuse them.
     *
     * @param {object} newCfg { ajaxUrl, nonce, nonceField?, extraNonceFields?, defaultErrorMsg?, timeoutMs? }
     */
    ajaxCall.configure = function (newCfg) {
        if (newCfg && typeof newCfg === 'object') {
            if (newCfg.ajaxUrl) { cfg.ajaxUrl = newCfg.ajaxUrl; }
            if (newCfg.nonce) { cfg.nonce = newCfg.nonce; }
            if (newCfg.nonceField) { cfg.nonceField = newCfg.nonceField; }
            if (Array.isArray(newCfg.extraNonceFields)) { cfg.extraNonceFields = newCfg.extraNonceFields; }
            if (newCfg.defaultErrorMsg) { cfg.defaultErrorMsg = newCfg.defaultErrorMsg; }
            if (typeof newCfg.timeoutMs === 'number' && newCfg.timeoutMs >= 0) {
                cfg.timeoutMs = newCfg.timeoutMs;
            }
        }
    };

    /**
     * Read current config (for debugging / conditional logic).
     *
     * @returns {object}
     */
    ajaxCall.getConfig = function () {
        return {
            ajaxUrl: cfg.ajaxUrl,
            nonce: cfg.nonce ? '***' : '',
            defaultErrorMsg: cfg.defaultErrorMsg,
        };
    };

    // Expose the helper itself as `wwuUIKit.ajax`. The function is callable
    // directly (wwuUIKit.ajax(...)) AND has .configure() / .getConfig() statics.
    window.wwuUIKit.ajax = ajaxCall;
}());
