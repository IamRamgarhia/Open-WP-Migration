/**
 * Open Migration admin behaviour.
 *
 * The markup is rendered server-side by OWPM_Admin::render_admin_page() so that
 * every string is translatable and escaped. This file only attaches behaviour.
 *
 * The import runs as a chain of short AJAX steps (upload -> prepare -> extract ->
 * database -> rewrite URLs -> finalise). Restoring the database signs the browser
 * out part way through, so every step after "prepare" also sends the one-time
 * import_key that the server issued.
 */
(function () {
    'use strict';

    if (typeof owpm_ajax === 'undefined') {
        return;
    }

    var root = document.getElementById('owpm-app-root');
    if (!root) {
        return;
    }

    var S = owpm_ajax.i18n || {};

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    function esc(value) {
        return String(value === null || typeof value === 'undefined' ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function messageOf(data, fallback) {
        if (typeof data === 'string' && data) {
            return data;
        }
        if (data && typeof data.message === 'string' && data.message) {
            return data.message;
        }
        return fallback || S.unknownError || 'Unknown error';
    }

    function delay(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    /**
     * POST to admin-ajax, resolving with response.data and rejecting on error.
     *
     * Transient gateway failures are retried, because a long batch can briefly
     * trip a proxy while the server is still working.
     */
    function post(action, data, retries) {
        retries = (typeof retries === 'number') ? retries : 2;

        var body = new FormData();
        body.append('action', action);
        body.append('nonce', owpm_ajax.nonce);

        Object.keys(data || {}).forEach(function (key) {
            if (data[key] !== null && typeof data[key] !== 'undefined') {
                body.append(key, data[key]);
            }
        });

        return fetch(owpm_ajax.ajax_url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.text().then(function (text) {
                return { status: res.status, text: text };
            });
        }).then(function (res) {
            var json;

            try {
                json = JSON.parse(res.text);
            } catch (e) {
                var snippet = String(res.text).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
                throw new Error((S.serverError || 'Server error') + ' (HTTP ' + res.status + ')' +
                    (snippet ? ': ' + snippet : ''));
            }

            if (!json || json.success !== true) {
                throw new Error(messageOf(json ? json.data : null));
            }

            return json.data;
        }).catch(function (err) {
            var transient = err instanceof TypeError || /HTTP (429|50\d|408)/.test(err.message || '');

            if (retries > 0 && transient) {
                return delay(2000).then(function () {
                    return post(action, data, retries - 1);
                });
            }

            if (err instanceof TypeError) {
                throw new Error(S.networkError || 'Network error');
            }

            throw err;
        });
    }

    /* ------------------------------------------------------------------ */
    /* Dialogs                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * An in-page confirmation dialog.
     *
     * Replaces window.confirm(), which renders as a browser-chrome alert titled
     * with the raw hostname — on a temporary host that reads as
     * "aquamarine-chimpanzee-231969.hostingersite.com says", which is exactly the
     * wrong tone for the last thing shown before a site is overwritten.
     *
     * @param {object} opts title, body (\n\n separated), confirmLabel, danger, alert
     * @returns {Promise<boolean>} true when confirmed.
     */
    function dialog(opts) {
        return new Promise(function (resolve) {
            var previouslyFocused = document.activeElement;

            var overlay = document.createElement('div');
            overlay.className = 'owpm-modal-overlay';

            var paragraphs = String(opts.body || '')
                .split(/\n{2,}/)
                .filter(Boolean)
                .map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; })
                .join('');

            var buttons = opts.alert
                ? '<button type="button" class="owpm-btn owpm-btn-primary" data-owpm-act="ok">' +
                  esc(S.modalOk || 'OK') + '</button>'
                : '<button type="button" class="owpm-btn owpm-btn-secondary" data-owpm-act="cancel">' +
                  esc(S.modalCancel || 'Cancel') + '</button>' +
                  '<button type="button" class="owpm-btn ' +
                  (opts.danger ? 'owpm-btn-danger' : 'owpm-btn-primary') +
                  '" data-owpm-act="ok">' +
                  esc(opts.confirmLabel || S.modalContinue || 'Continue') + '</button>';

            overlay.innerHTML =
                '<div class="owpm-modal' + (opts.danger ? ' owpm-modal-danger' : '') + '" role="dialog" ' +
                'aria-modal="true" aria-labelledby="owpm-modal-title">' +
                '<h2 id="owpm-modal-title">' + esc(opts.title || S.modalTitle || 'Please confirm') + '</h2>' +
                '<div class="owpm-modal-body">' + paragraphs + '</div>' +
                '<div class="owpm-modal-actions">' + buttons + '</div>' +
                '</div>';

            document.body.appendChild(overlay);
            document.body.classList.add('owpm-modal-open');

            var focusable = overlay.querySelectorAll('button');

            function close(result) {
                document.removeEventListener('keydown', onKey, true);
                overlay.remove();
                document.body.classList.remove('owpm-modal-open');

                if (previouslyFocused && previouslyFocused.focus) {
                    previouslyFocused.focus();
                }

                resolve(result);
            }

            function onKey(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    close(false);
                    return;
                }

                // Keep focus inside the dialog.
                if (e.key === 'Tab' && focusable.length) {
                    var first = focusable[0];
                    var last = focusable[focusable.length - 1];

                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            }

            overlay.addEventListener('click', function (e) {
                var act = e.target.getAttribute && e.target.getAttribute('data-owpm-act');
                if (act) { close(act === 'ok'); return; }
                // Clicking the backdrop cancels, but never confirms.
                if (e.target === overlay) { close(false); }
            });

            document.addEventListener('keydown', onKey, true);

            // Focus Cancel by default so Enter never destroys anything by accident.
            var initial = opts.alert ? focusable[0] : focusable[0];
            if (initial) { initial.focus(); }
        });
    }

    /** A single-button informational dialog. */
    function notify(message, title) {
        return dialog({ title: title || S.modalNotice || 'Open Migration', body: message, alert: true });
    }

    /** Repeat a batched action until the server reports status "complete". */
    function runUntilComplete(action, payload, onProgress) {
        return new Promise(function (resolve, reject) {
            function tick() {
                post(action, payload).then(function (data) {
                    if (typeof onProgress === 'function') {
                        onProgress(data);
                    }

                    if (data && data.status === 'complete') {
                        resolve(data);
                    } else {
                        // Yield so the progress bar can repaint.
                        setTimeout(tick, 50);
                    }
                }).catch(reject);
            }

            tick();
        });
    }

    /** Progress bar + status line controller. */
    function ui(prefix) {
        var wrapper = document.getElementById(prefix + '-progress-wrapper');
        var bar = document.getElementById(prefix + '-progress-bar');
        var text = document.getElementById(prefix + '-status-text');

        return {
            show: function () {
                if (wrapper) { wrapper.style.display = 'block'; }
                if (text) { text.style.display = 'block'; }
            },
            set: function (percent, message, detail) {
                if (bar) { bar.style.width = Math.max(0, Math.min(100, percent)) + '%'; }
                if (!text) { return; }
                text.innerHTML = esc(message) +
                    (detail ? '<span class="owpm-detail">' + esc(detail) + '</span>' : '');
            },
            html: function (markup) {
                if (text) { text.innerHTML = markup; }
            },
            done: function () {
                if (bar) { bar.classList.add('owpm-progress-done'); bar.style.width = '100%'; }
            },
            fail: function (message) {
                if (bar) { bar.classList.add('owpm-progress-error'); }
                if (text) { text.innerHTML = '<span class="owpm-error">' + esc(message) + '</span>'; }
            }
        };
    }

    /* ------------------------------------------------------------------ */
    /* Tabs                                                               */
    /* ------------------------------------------------------------------ */

    var tabs = root.querySelectorAll('.owpm-tab');
    var panels = root.querySelectorAll('.owpm-panel');
    var backupsLoaded = false;

    function activateTab(target) {
        var tab = root.querySelector('.owpm-tab[data-target="' + target + '"]');
        if (tab) { tab.click(); }
    }

    // "Change Domain" links inside result cards jump to that tab.
    root.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('.owpm-goto-domain') : null;
        if (link) {
            e.preventDefault();
            activateTab('domain');
        }
    });

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) {
                t.classList.remove('active');
                t.setAttribute('aria-selected', 'false');
            });
            panels.forEach(function (p) { p.classList.remove('active'); });

            tab.classList.add('active');
            tab.setAttribute('aria-selected', 'true');

            var panel = document.getElementById('panel-' + tab.dataset.target);
            if (panel) { panel.classList.add('active'); }

            if (tab.dataset.target === 'backups') {
                loadBackups();
            }

            if (tab.dataset.target === 'export' && !backupsLoaded) {
                loadSystemStatus();
            }
        });
    });

    /* ------------------------------------------------------------------ */
    /* Server check                                                       */
    /* ------------------------------------------------------------------ */

    var blocked = false;

    function loadSystemStatus() {
        var grid = document.getElementById('owpm-status-grid');
        var summary = document.getElementById('owpm-status-summary');

        if (!grid) { return; }

        post('owpm_system_status', {}).then(function (data) {
            grid.innerHTML = data.checks.map(function (check) {
                return '<div class="owpm-status-item' + (check.state === 'error' ? ' owpm-is-error' : '') + '">' +
                    '<span class="owpm-status-item-label">' +
                    '<span class="owpm-dot owpm-dot-' + esc(check.state) + '"></span>' +
                    esc(check.label) +
                    '</span>' +
                    '<div class="owpm-status-value">' + esc(check.value) + '</div>' +
                    (check.hint ? '<p class="owpm-status-hint">' + esc(check.hint) + '</p>' : '') +
                    '</div>';
            }).join('');

            if (summary) {
                if (data.blocking > 0) {
                    summary.className = 'owpm-status-summary owpm-error';
                    summary.textContent = data.blocking + ' problem(s) found';
                    blocked = true;
                } else {
                    summary.className = 'owpm-status-summary owpm-ok';
                    summary.textContent = 'Ready to migrate';
                }
            }

            var exportBtn = document.getElementById('owpm-start-export');
            if (exportBtn && blocked) { exportBtn.disabled = true; }
        }).catch(function () {
            if (summary) { summary.textContent = ''; }
            if (grid) { grid.innerHTML = ''; }
        });
    }

    loadSystemStatus();

    /* ------------------------------------------------------------------ */
    /* Export                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Run a complete export and resolve with the finish_export payload.
     *
     * Both the Export tab and the Site-to-Site token flow use this, so a token can
     * never be issued before the archive is genuinely finished.
     */
    function runExport(view) {
        view.show();
        view.set(2, 'Scanning site…');

        return post('owpm_start_export', {}).then(function (start) {
            view.set(5, 'Exporting database…');

            return runUntilComplete('owpm_export_db', {}, function (data) {
                view.set(5 + (data.progress || 0) * 0.20,
                    'Exporting database… ' + (data.progress || 0) + '%', data.current);
            }).then(function () {
                view.set(25, 'Packaging ' + (start.total_files || 0) + ' files…');

                return runUntilComplete('owpm_export_files', {}, function (data) {
                    view.set(25 + (data.progress || 0) * 0.70,
                        'Packaging files… ' + (data.files_done || 0) + ' / ' + (data.total_files || 0),
                        data.current_file);
                });
            }).then(function () {
                view.set(97, 'Finalising backup…');
                return post('owpm_finish_export', {});
            }).then(function (finish) {
                finish.total_files = start.total_files;
                return finish;
            });
        });
    }

    var exportBtn = document.getElementById('owpm-start-export');

    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            if (blocked) { return; }

            var view = ui('export');
            exportBtn.disabled = true;

            runExport(view).then(function (result) {
                view.done();

                var warn = result.skipped_files > 0
                    ? '<p class="owpm-note">' + esc(result.skipped_files) +
                      ' file(s) could not be read and were skipped.</p>'
                    : '';

                view.html(
                    '<div class="owpm-done-card">' +
                    '<h3><span class="dashicons dashicons-yes-alt"></span>Backup ready</h3>' +
                    '<div class="owpm-stats">' +
                    '<div><span class="owpm-stat-n">' + esc(result.size) + '</span>' +
                    '<span class="owpm-stat-l">Archive size</span></div>' +
                    '<div><span class="owpm-stat-n">' + esc(result.total_files || 0) + '</span>' +
                    '<span class="owpm-stat-l">Files</span></div>' +
                    '</div>' + warn +
                    '<p><a href="' + esc(result.download_url) + '" class="owpm-btn owpm-btn-primary">' +
                    'Download Backup (.zip)</a></p>' +
                    '<p class="owpm-note">It is also kept on this server under the Backups tab.</p>' +
                    '</div>'
                );

                exportBtn.disabled = false;
                backupsLoaded = false;
            }).catch(function (err) {
                view.fail(err.message);
                exportBtn.disabled = false;
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Import pipeline (shared by upload and site-to-site)                */
    /* ------------------------------------------------------------------ */

    /**
     * Run every server-side import step for an archive already on disk.
     *
     * @param {string} fileId Identifier of the uploaded/downloaded archive.
     * @param {object} view   Progress view.
     * @param {number} from   Progress percentage this stage starts at.
     */
    function runImportSteps(fileId, view, from) {
        var span = 100 - from;
        var key = null;

        function at(fraction) { return from + span * fraction; }

        view.set(at(0.02), 'Inspecting backup…');

        return post('owpm_import_prepare', { file_id: fileId }).then(function (prepared) {
            key = prepared.import_key;
            var payload = { file_id: fileId, import_key: prepared.import_key };

            // Environment mismatches are worth raising before anything is written.
            var gate = (prepared.notices && prepared.notices.length)
                ? dialog({
                    title: S.compatTitle || 'Check before continuing',
                    body: prepared.notices.join('\n\n'),
                    confirmLabel: S.continueAnyway || 'Continue anyway',
                    danger: true
                })
                : Promise.resolve(true);

            return gate.then(function (proceed) {
                if (!proceed) {
                    throw new Error(S.importCancelled || 'Import cancelled.');
                }

                return runUntilComplete('owpm_import_extract', payload, function (data) {
                    view.set(at(0.05 + (data.progress || 0) / 100 * 0.45),
                        'Restoring files… ' + (data.progress || 0) + '%', data.current_file);
                });
            }).then(function () {
                view.set(at(0.52), 'Restoring database…');

                return runUntilComplete('owpm_import_db', payload, function (data) {
                    view.set(at(0.52 + (data.progress || 0) / 100 * 0.28),
                        'Restoring database… ' + (data.progress || 0) + '%');
                }).then(function (data) {
                    // Restoring the database replaced the users table, so the old
                    // login cookie stopped validating. The server signs us back in
                    // and returns a nonce bound to the new session; adopting it
                    // lets the remaining steps use the normal authenticated path
                    // instead of falling back to the one-time key.
                    if (data && data.nonce) {
                        owpm_ajax.nonce = data.nonce;
                    }
                    return data;
                });
            }).then(function () {
                view.set(at(0.82), 'Converting URLs and paths…');

                return runUntilComplete('owpm_import_replace', payload, function (data) {
                    view.set(at(0.82 + (data.progress || 0) / 100 * 0.15), data.current || 'Converting URLs…');
                });
            }).then(function () {
                view.set(at(0.98), 'Finishing up…');
                return post('owpm_import_finalize', payload);
            }).then(function (result) {
                result.source_url = prepared.source_url;
                return result;
            });
        }).catch(function (err) {
            // Release the half-finished import: without this the archive, the
            // working directory, the session file and the temporary mu-plugin
            // loader would all be left behind, and a retry would collide with them.
            if (key) {
                post('owpm_import_abort', { file_id: fileId, import_key: key }, 0)
                    .catch(function () { /* cleanup is best effort */ });
            }

            throw err;
        });
    }

    function renderImportResult(view, result) {
        view.done();

        var rewrote = (result.old_url && result.new_url && result.old_url !== result.new_url)
            ? '<p class="owpm-note">Rewrote <code>' + esc(result.old_url) + '</code> to <code>' +
              esc(result.new_url) + '</code>.</p>'
            : '';

        var errors = (result.errors && result.errors.length)
            ? '<details class="owpm-errors"><summary>' + esc(result.errors.length) +
              ' warning(s) during import</summary><ul><li>' +
              result.errors.map(esc).join('</li><li>') + '</li></ul></details>'
            : '';

        var loginStep = result.relogin
            ? esc(S.reloginDone || '')
            : esc(S.reloginNeeded || '');

        view.html(
            '<div class="owpm-done-card">' +
            '<h3><span class="dashicons dashicons-yes-alt"></span>' + esc(S.importDone || 'Import complete') + '</h3>' +
            rewrote +
            '<div class="owpm-stats">' +
            '<div><span class="owpm-stat-n">' + esc(result.files || 0) + '</span>' +
            '<span class="owpm-stat-l">Files restored</span></div>' +
            '<div><span class="owpm-stat-n">' + esc(result.statements || 0) + '</span>' +
            '<span class="owpm-stat-l">DB statements</span></div>' +
            '<div><span class="owpm-stat-n">' + esc(result.rows_replaced || 0) + '</span>' +
            '<span class="owpm-stat-l">Rows rewritten</span></div>' +
            '</div>' + errors +
            '<strong>What to do next</strong>' +
            '<ol class="owpm-checklist">' +
            '<li>' + loginStep + '</li>' +
            '<li>Open <a href="' + esc(owpm_ajax.site_url) + '/wp-admin/options-permalink.php">' +
            'Settings &rarr; Permalinks</a> and click Save Changes once, to rebuild your URL rules.</li>' +
            '<li>Visit the front page and check that images and links load correctly.</li>' +
            '<li><strong>Still on a temporary address?</strong> This site was rewritten to <code>' +
            esc(result.new_url) + '</code>. If that is your host\'s temporary domain and your real ' +
            'domain is not pointed here yet, come back to the <a href="#" class="owpm-goto-domain">' +
            'Change Domain</a> tab once DNS is switched — WordPress cannot detect that on its own.</li>' +
            '<li>Delete the leftover backup under the Backups tab once you are happy.</li>' +
            '</ol></div>'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Import: chunked upload                                             */
    /* ------------------------------------------------------------------ */

    var dropZone = document.getElementById('owpm-drop-zone');
    var fileInput = document.getElementById('owpm-import-file');
    var triggerFileBtn = document.getElementById('owpm-trigger-file');

    if (triggerFileBtn && fileInput) {
        triggerFileBtn.addEventListener('click', function () { fileInput.click(); });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function (e) {
            if (e.target.files && e.target.files.length) {
                handleChosenFile(e.target.files[0]);
            }
        });
    }

    // Drag & drop: described in the interface but previously never implemented.
    if (dropZone) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (name) {
            dropZone.addEventListener(name, function (e) {
                e.preventDefault();
                e.stopPropagation();
            });
        });

        ['dragenter', 'dragover'].forEach(function (name) {
            dropZone.addEventListener(name, function () {
                dropZone.classList.add('owpm-dragover');
            });
        });

        ['dragleave', 'drop'].forEach(function (name) {
            dropZone.addEventListener(name, function () {
                dropZone.classList.remove('owpm-dragover');
            });
        });

        dropZone.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files;
            if (files && files.length) {
                handleChosenFile(files[0]);
            }
        });
    }

    function handleChosenFile(file) {
        if (!/\.zip$/i.test(file.name)) {
            notify(S.notZip || 'Please choose a .zip file.');
            if (fileInput) { fileInput.value = ''; }
            return;
        }

        dialog({
            title: S.confirmImportTitle || 'Overwrite this site?',
            body: (S.confirmImport || '') + '\n\n' + file.name,
            confirmLabel: S.confirmImportBtn || 'Overwrite this site',
            danger: true
        }).then(function (ok) {
            if (!ok) {
                if (fileInput) { fileInput.value = ''; }
                return;
            }
            startUploadImport(file);
        });
    }

    function startUploadImport(file) {
        var view = ui('import');

        if (dropZone) { dropZone.style.display = 'none'; }
        view.show();

        var chunkSize = Number(owpm_ajax.chunk_size) || (2 * 1024 * 1024);
        var totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
        var fileId = 'import_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);

        uploadChunk(0);

        function uploadChunk(index) {
            var start = index * chunkSize;
            var chunk = file.slice(start, Math.min(start + chunkSize, file.size));

            var body = new FormData();
            body.append('action', 'owpm_upload_chunk');
            body.append('nonce', owpm_ajax.nonce);
            body.append('file_id', fileId);
            body.append('chunk_index', index);
            body.append('total_chunks', totalChunks);
            body.append('chunk', chunk, 'chunk.part');

            fetch(owpm_ajax.ajax_url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin'
            }).then(function (res) {
                return res.text().then(function (text) { return { status: res.status, text: text }; });
            }).then(function (res) {
                var json;
                try {
                    json = JSON.parse(res.text);
                } catch (e) {
                    throw new Error((S.serverError || 'Server error') + ' (HTTP ' + res.status + ')');
                }

                if (!json || json.success !== true) {
                    throw new Error(messageOf(json ? json.data : null));
                }

                var next = index + 1;
                view.set(Math.round((next / totalChunks) * 40),
                    'Uploading… ' + Math.round((next / totalChunks) * 100) + '%',
                    next + ' / ' + totalChunks + ' chunks');

                if (next < totalChunks) {
                    uploadChunk(next);
                } else {
                    runImportSteps(fileId, view, 40)
                        .then(function (result) { renderImportResult(view, result); })
                        .catch(function (err) { view.fail(err.message); });
                }
            }).catch(function (err) {
                view.fail(err instanceof TypeError ? (S.networkError || 'Network error') : err.message);
            });
        }
    }

    /* ------------------------------------------------------------------ */
    /* Site-to-site                                                       */
    /* ------------------------------------------------------------------ */

    var tokenBtn = document.getElementById('owpm-generate-token');

    if (tokenBtn) {
        tokenBtn.addEventListener('click', function () {
            if (blocked) { return; }

            var view = ui('token');
            var output = document.getElementById('owpm-token-output');

            tokenBtn.disabled = true;
            output.innerHTML = '';

            // The export must fully complete first, otherwise the destination
            // could pull a partially written archive.
            runExport(view).then(function (finished) {
                view.set(99, 'Generating token…');
                return post('owpm_generate_token', { backup_id: finished.backup_id });
            }).then(function (data) {
                view.done();
                view.set(100, 'Backup ready. Copy the token below.');

                output.innerHTML =
                    '<p><strong>' + esc(S.tokenTitle || 'Your Migration Token') +
                    '</strong> <span class="owpm-muted">(' + esc(data.size) + ')</span></p>' +
                    '<textarea id="owpm-token-value" readonly rows="4"></textarea>' +
                    '<p><button type="button" class="owpm-btn owpm-btn-secondary" id="owpm-copy-token">' +
                    esc(S.copy || 'Copy Token') + '</button></p>' +
                    '<p class="owpm-note">' + esc(S.tokenNote || '') + '</p>' +
                    (data.warning ? '<p class="owpm-note owpm-warn">' + esc(data.warning) + '</p>' : '');

                // Assigned via .value, never interpolated into markup.
                document.getElementById('owpm-token-value').value = data.token;

                document.getElementById('owpm-copy-token').addEventListener('click', function () {
                    var button = this;
                    var field = document.getElementById('owpm-token-value');

                    field.select();
                    field.setSelectionRange(0, field.value.length);

                    function done() { button.textContent = S.copied || 'Copied!'; }

                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(field.value).then(done, done);
                    } else {
                        document.execCommand('copy');
                        done();
                    }
                });

                tokenBtn.style.display = 'none';
            }).catch(function (err) {
                view.fail(err.message);
                tokenBtn.disabled = false;
            });
        });
    }

    var pullBtn = document.getElementById('owpm-start-pull');

    if (pullBtn) {
        pullBtn.addEventListener('click', function () {
            var input = document.getElementById('owpm-import-token');
            var token = (input.value || '').trim();

            if (!token) {
                notify(S.noToken || 'Please paste a migration token.');
                input.focus();
                return;
            }

            dialog({
                title: S.confirmImportTitle || 'Overwrite this site?',
                body: S.confirmImport || '',
                confirmLabel: S.confirmImportBtn || 'Overwrite this site',
                danger: true
            }).then(function (ok) {
            if (!ok) { return; }

            var view = ui('pull');
            pullBtn.disabled = true;
            view.show();
            view.set(3, 'Connecting to the source site…');

            return post('owpm_pull_site', { token: token }).then(function (data) {
                view.set(35, 'Downloaded ' + data.size + '. Restoring…');
                return runImportSteps(data.file_id, view, 35);
            }).then(function (result) {
                renderImportResult(view, result);
            }).catch(function (err) {
                view.fail(err.message);
                pullBtn.disabled = false;
            });
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Change domain                                                      */
    /* ------------------------------------------------------------------ */

    var domainInput = document.getElementById('owpm-new-domain');
    var domainPreview = document.getElementById('owpm-domain-preview');
    var dnsResult = document.getElementById('owpm-dns-result');
    var dnsState = null;

    /** Mirror what the user typed into the "New address" side of the swap. */
    function updateDomainPreview() {
        if (!domainPreview || !domainInput) { return; }

        var value = (domainInput.value || '').trim();

        if (!value) {
            domainPreview.textContent = '—';
            domainPreview.classList.add('owpm-empty-url');
            return;
        }

        // Show the same normalisation the server will apply, so there are no surprises.
        if (!/^https?:\/\//i.test(value)) {
            value = 'https://' + value.replace(/^\/+/, '');
        }

        domainPreview.textContent = value.replace(/\/+$/, '');
        domainPreview.classList.remove('owpm-empty-url');
    }

    function setDnsResult(state, message) {
        dnsState = state;
        if (!dnsResult) { return; }
        dnsResult.className = 'owpm-dns-result owpm-dns-' + state;
        dnsResult.textContent = message;
    }

    function clearDnsResult() {
        dnsState = null;
        if (dnsResult) {
            dnsResult.className = 'owpm-dns-result';
            dnsResult.textContent = '';
        }
    }

    if (domainInput) {
        domainInput.addEventListener('input', function () {
            updateDomainPreview();
            // Any edit invalidates a previous DNS result.
            clearDnsResult();
        });
        updateDomainPreview();
    }

    // Clicking a detected address fills the field with it.
    root.querySelectorAll('.owpm-use-domain').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!domainInput) { return; }

            root.querySelectorAll('.owpm-use-domain').forEach(function (other) {
                other.classList.remove('owpm-candidate-active');
            });
            btn.classList.add('owpm-candidate-active');

            domainInput.value = btn.dataset.url || '';
            updateDomainPreview();
            clearDnsResult();
            domainInput.focus();
        });
    });

    var checkDomainBtn = document.getElementById('owpm-check-domain');

    if (checkDomainBtn && domainInput) {
        checkDomainBtn.addEventListener('click', function () {
            var value = (domainInput.value || '').trim();

            if (!value) {
                notify(S.noDomain || 'Please enter the new site address.');
                domainInput.focus();
                return;
            }

            checkDomainBtn.disabled = true;
            setDnsResult('busy', S.dnsChecking || 'Checking…');

            post('owpm_domain_check', { new_url: value }).then(function (data) {
                setDnsResult(data.state, data.message);
            }).catch(function (err) {
                setDnsResult('unknown', err.message);
            }).then(function () {
                checkDomainBtn.disabled = false;
            });
        });
    }

    var domainBtn = document.getElementById('owpm-run-domain');

    if (domainBtn) {
        domainBtn.addEventListener('click', function () {
            var input = document.getElementById('owpm-new-domain');
            var value = (input.value || '').trim();

            if (!value) {
                notify(S.noDomain || 'Please enter the new site address.');
                input.focus();
                return;
            }

            // Spell out exactly what is about to change, so a typo is visible
            // before it is written rather than after the site goes offline.
            var from = document.getElementById('owpm-domain-current');
            var to = document.getElementById('owpm-domain-preview');

            var summary = (S.confirmDomainFrom || 'Change this site\'s address?') + '\n\n' +
                '    ' + (from ? from.textContent : '') + '\n' +
                ' -> ' + (to ? to.textContent : value) + '\n\n';

            if (dnsState === 'mismatch') {
                summary += (S.dnsWarnMismatch ||
                    'WARNING: the DNS check said that address does NOT point to this server. ' +
                    'Continuing will take this site offline.') + '\n\n';
            } else if (dnsState !== 'ok') {
                summary += (S.dnsWarnUnchecked ||
                    'You have not confirmed that this address points to this server. ' +
                    'Use "Check DNS" first if you are unsure.') + '\n\n';
            }

            summary += (S.confirmDomain || 'This cannot be undone. Continue?');

            dialog({
                title: S.confirmDomainTitle || 'Change the site address?',
                body: summary,
                confirmLabel: S.confirmDomainBtn || 'Change domain',
                danger: true
            }).then(function (ok) {
            if (!ok) { return; }

            var view = ui('domain');
            domainBtn.disabled = true;
            view.show();
            view.set(3, 'Preparing…');

            return post('owpm_domain_start', { new_url: value }).then(function (start) {
                view.set(6, 'Rewriting ' + start.passes + ' reference pattern(s)…');

                return runUntilComplete('owpm_domain_step', {}, function (data) {
                    view.set(6 + (data.progress || 0) * 0.92,
                        'Rewriting… ' + (data.progress || 0) + '%',
                        (data.rows || 0) + ' ' + (S.rowsUpdated || 'rows updated'));
                });
            }).then(function (result) {
                view.done();

                view.html(
                    '<div class="owpm-done-card">' +
                    '<h3><span class="dashicons dashicons-yes-alt"></span>' +
                    esc(S.domainDone || 'Domain changed.') + '</h3>' +
                    '<p class="owpm-note"><code>' + esc(result.old_url) + '</code> &rarr; <code>' +
                    esc(result.new_url) + '</code></p>' +
                    '<div class="owpm-stats"><div>' +
                    '<span class="owpm-stat-n">' + esc(result.rows || 0) + '</span>' +
                    '<span class="owpm-stat-l">' + esc(S.rowsUpdated || 'rows updated') + '</span>' +
                    '</div></div>' +
                    '<p class="owpm-note">' + esc(S.domainRelogin || '') + '</p>' +
                    '<p><a href="' + esc(result.admin_url) + '" class="owpm-btn owpm-btn-primary">' +
                    esc(S.domainGoTo || 'Go to the new address') + '</a></p>' +
                    '</div>'
                );
            }).catch(function (err) {
                view.fail(err.message);
                domainBtn.disabled = false;
            });
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Backups                                                            */
    /* ------------------------------------------------------------------ */

    function loadBackups() {
        var container = document.getElementById('owpm-backups-container');
        if (!container) { return; }

        backupsLoaded = true;
        container.innerHTML = '<p class="owpm-muted">' + esc(S.loadingBackups || 'Loading…') + '</p>';

        post('owpm_get_backups', {}).then(function (backups) {
            if (!backups || !backups.length) {
                container.innerHTML = '<div class="owpm-empty">' +
                    esc(S.noBackups || 'No backups found.') + '</div>';
                return;
            }

            var rows = backups.map(function (backup) {
                return '<tr>' +
                    '<td class="owpm-name">' + esc(backup.name) + '</td>' +
                    '<td>' + esc(backup.date) + '</td>' +
                    '<td>' + esc(backup.size) + '</td>' +
                    '<td>' +
                    '<a href="' + esc(backup.download_url) + '" class="button button-primary">' +
                    esc(S.download || 'Download') + '</a> ' +
                    '<button type="button" class="button owpm-delete-btn" data-file="' +
                    esc(backup.name) + '">' + esc(S.delete || 'Delete') + '</button>' +
                    '</td></tr>';
            }).join('');

            container.innerHTML = '<table class="owpm-table"><thead><tr>' +
                '<th>' + esc(S.colName || 'Name') + '</th>' +
                '<th>' + esc(S.colDate || 'Date') + '</th>' +
                '<th>' + esc(S.colSize || 'Size') + '</th>' +
                '<th>' + esc(S.colActions || 'Actions') + '</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table>';

            container.querySelectorAll('.owpm-delete-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    dialog({
                        title: S.confirmDeleteTitle || 'Delete this backup?',
                        body: (S.confirmDelete || '') + '\n\n' + btn.dataset.file,
                        confirmLabel: S.confirmDeleteBtn || 'Delete backup',
                        danger: true
                    }).then(function (ok) {
                        if (!ok) { return; }

                        btn.disabled = true;
                        btn.textContent = S.deleting || 'Deleting…';

                        post('owpm_delete_backup', { file: btn.dataset.file })
                            .then(loadBackups)
                            .catch(function (err) {
                                btn.disabled = false;
                                btn.textContent = S.delete || 'Delete';
                                notify(err.message);
                            });
                    });
                });
            });
        }).catch(function () {
            container.innerHTML = '<p class="owpm-error">' +
                esc(S.backupsFailed || 'Failed to load backups.') + '</p>';
        });
    }
})();
