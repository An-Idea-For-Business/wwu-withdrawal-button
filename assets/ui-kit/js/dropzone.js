/**
 * WWU UI Kit — Dropzone controller
 *
 * Wires up a `.wwu-ui-dropzone` element to accept files via:
 *  - click → native file picker (via the inner <input type="file">)
 *  - drag & drop
 *
 * The kit manages `.dragover` state + delegation. Consumer plugin
 * receives files via `onFile` callback; responsibility for upload
 * (AJAX, validation, server-side device routing, …) stays in consumer.
 *
 * Trap #20 reference (Lighthouse dual-slot): NEVER route by which
 * dropzone the file was dropped into — always route server-side based
 * on file content. The kit just labels the drop as a hint, consumer
 * must treat that as advisory.
 *
 * Usage:
 *   <div class="wwu-ui-dropzone" id="my-dropzone" data-slot="mobile">
 *       <div class="wwu-ui-dropzone-icon"><span class="dashicons dashicons-upload"></span></div>
 *       <span class="wwu-ui-dropzone-title">Trascina qui il report</span>
 *       <span class="wwu-ui-dropzone-hint">o clicca per selezionarlo</span>
 *       <input type="file" accept=".json">
 *   </div>
 *
 *   wwuUIKit.dropzone.bind('#my-dropzone', {
 *       multiple: false,                   // default
 *       onFile: function (file, dzEl) {
 *           var slotHint = dzEl.getAttribute('data-slot');
 *           uploadFile(file, slotHint);    // server validates + routes
 *       },
 *       onError: function (err) { wwuUIKit.toast(err, 'error'); },
 *   });
 *
 * @since 0.3.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] dropzone.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    /**
     * Bind dropzone behaviour to an element.
     *
     * @param {string|HTMLElement} target  Selector or element
     * @param {object} opts
     * @param {Function} opts.onFile       (file, dzEl) => void — called per file
     * @param {boolean} [opts.multiple=false]
     * @param {Function} [opts.onError]    (errMsg) => void
     * @returns {object}  { destroy(), reset(), setBusy(bool) }
     */
    function bind(target, opts) {
        opts = opts || {};
        var dz = typeof target === 'string' ? document.querySelector(target) : target;
        if (!dz) { return createNoop(); }

        // Prevent double-binding on the same element.
        if (dz.__wwuUiKitDropzone) { return dz.__wwuUiKitDropzone; }

        var input = dz.querySelector('input[type="file"]');

        function emit(file) {
            if (typeof opts.onFile === 'function') {
                try { opts.onFile(file, dz); } catch (err) {
                    if (console && console.warn) {
                        console.warn('[wwu-ui-kit] dropzone onFile threw:', err);
                    }
                }
            }
        }

        function emitError(msg) {
            if (typeof opts.onError === 'function') {
                try { opts.onError(msg); } catch (_) { /* no-op */ }
            }
        }

        function processFiles(fileList) {
            if (!fileList || !fileList.length) { return; }
            if (opts.multiple) {
                for (var i = 0; i < fileList.length; i++) {
                    emit(fileList[i]);
                }
            } else {
                emit(fileList[0]);
                if (fileList.length > 1) {
                    emitError('Solo il primo file è stato accettato.');
                }
            }
        }

        function onDragOver(e) {
            e.preventDefault();
            dz.classList.add('dragover');
        }

        function onDragLeave() {
            dz.classList.remove('dragover');
        }

        function onDrop(e) {
            e.preventDefault();
            dz.classList.remove('dragover');
            if (e.dataTransfer && e.dataTransfer.files) {
                processFiles(e.dataTransfer.files);
            }
        }

        function onInputChange(e) {
            processFiles(e.target.files);
            // Reset input so selecting the same file again fires change.
            e.target.value = '';
        }

        dz.addEventListener('dragover', onDragOver);
        dz.addEventListener('dragleave', onDragLeave);
        dz.addEventListener('drop', onDrop);
        if (input) {
            input.addEventListener('change', onInputChange);
        }

        var controller = {
            destroy: function () {
                dz.removeEventListener('dragover', onDragOver);
                dz.removeEventListener('dragleave', onDragLeave);
                dz.removeEventListener('drop', onDrop);
                if (input) { input.removeEventListener('change', onInputChange); }
                dz.__wwuUiKitDropzone = null;
            },
            reset: function () {
                dz.classList.remove('dragover', 'is-uploading');
                if (input) { input.value = ''; }
            },
            setBusy: function (busy) {
                dz.classList.toggle('is-uploading', !!busy);
                if (busy) {
                    dz.setAttribute('aria-busy', 'true');
                } else {
                    dz.removeAttribute('aria-busy');
                }
            },
            element: dz,
        };

        dz.__wwuUiKitDropzone = controller;
        return controller;
    }

    function createNoop() {
        return {
            destroy: function () {},
            reset: function () {},
            setBusy: function () {},
            element: null,
        };
    }

    window.wwuUIKit.dropzone = {
        bind: bind,
    };
}());
