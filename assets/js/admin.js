/* WP Backup Nota – Admin JS */

jQuery(function($){

    var nonce   = wpbn.nonce;
    var ajaxUrl = wpbn.ajax_url;

    /* ── Backup UI shared refs ────────────────────────── */
    var $backupBtn = $('#wpbn-start-backup');
    var $progress  = $('#wpbn-progress');
    var $bar       = $('#wpbn-progress-inner');
    var $progMsg   = $('#wpbn-progress-msg');

    function setProgress(pct, msg) {
        $bar.css('width', Math.min(pct, 100) + '%');
        if (msg) $progMsg.html(msg);
    }

    function fail(msg) {
        setProgress(100, '❌ ' + msg);
        $bar.css('background', '#d63638');
        $backupBtn.prop('disabled', false);
        $('#wpbn-cleanup-orphans').prop('disabled', false);
    }

    function showDoneMsg(data) {
        var doneMsg = wpbn.i18n.backup_complete + ' <strong>' + escHtml(data.filename) + '</strong>'
                    + ' (' + escHtml(data.filesize_hr || '—') + ')';
        if (data.duration) doneMsg += ' — ⏱ ' + escHtml(data.duration);
        setProgress(100, doneMsg);
        $backupBtn.prop('disabled', false);
        $('#wpbn-cleanup-orphans').prop('disabled', false);
        refreshBackupList();
        maybeShowReviewPrompt();
    }

    function maybeShowReviewPrompt() {
        if (!wpbn.show_review_prompt) return;
        if ($('#wpbn-review-prompt').length) return;
        var html = '<div id="wpbn-review-prompt" style="margin-top:10px;padding:10px 14px;background:#f0f6fc;border:1px solid #c3daf5;border-radius:6px;display:flex;align-items:center;gap:10px;font-size:.88rem;">'
            + '<span>⭐</span>'
            + '<span style="flex:1;">' + escHtml(wpbn.i18n.review_prompt) + '</span>'
            + '<a href="' + wpbn.review_url + '" target="_blank" rel="noopener" class="button button-small" style="flex-shrink:0;" id="wpbn-review-go">' + escHtml(wpbn.i18n.review_btn) + '</a>'
            + '<button type="button" id="wpbn-review-dismiss" style="background:none;border:none;cursor:pointer;padding:2px 6px;font-size:1rem;line-height:1;color:#646970;" title="Dismiss">&times;</button>'
            + '</div>';
        $progress.after(html);

        $(document).on('click', '#wpbn-review-go', function() {
            sendReviewAction('dismiss');
        });
        $(document).on('click', '#wpbn-review-dismiss', function() {
            $('#wpbn-review-prompt').remove();
            sendReviewAction('dismiss');
        });
    }

    function sendReviewAction(action) {
        wpbn.show_review_prompt = false;
        var fd = new FormData();
        fd.append('action', action === 'remind' ? 'wpbn_remind_review' : 'wpbn_dismiss_review');
        fd.append('nonce', nonce);
        fetch(ajaxUrl, {method:'POST', body:fd});
    }

    /* ── Tabs ─────────────────────────────────────────── */
    /* ── Tab switching ───────────────────────────────── */
    function activateTab(tab) {
        var $link = $('.nav-link[data-tab="' + tab + '"]');
        if (!$link.length) return;
        $link.closest('.nav').find('.nav-link').removeClass('active');
        $link.addClass('active');
        $('.wpbn-tab-content').removeClass('active');
        $('#tab-' + tab).addClass('active');
    }

    $(document).on('click', '.wpbn-tab, .nav-link[data-tab]', function(e){
        e.preventDefault();
        activateTab($(this).data('tab'));
    });

    // Activate tab from URL param on page load
    (function(){
        var params = new URLSearchParams(window.location.search);
        var tab = params.get('tab');
        if (tab) activateTab(tab);

        // Show saved notice if redirected back after save
        if (params.get('saved') === '1') {
            var $msg = $('#wpbn-save-msg');
            $msg.text(wpbn.i18n.settings_saved);
            setTimeout(function(){ $msg.fadeOut(600, function(){ $msg.text('').show(); }); }, 3000);
        }
    })();

    /* ── Size Estimate ───────────────────────────────── */
    function loadSizeEstimate() {
        $('#wpbn-size-est-val').text(wpbn.i18n.calculating).css('color','#646970');
        $('#wpbn-size-est-detail').text('');
        $.post(ajaxUrl, { action:'wpbn_size_estimate', nonce:nonce }, function(res){
            if (!res.success) { $('#wpbn-size-est-val').text('—'); return; }
            var d = res.data;
            $('#wpbn-size-est-val').text(d.estimated_zip_hr).css('color','#2271b1');
            $('#wpbn-size-est-detail').text('(Raw: ' + d.raw_total_hr + ' | DB: ' + d.db_size_hr + ' | Files: ' + d.file_size_hr + ')');
        }).fail(function(){
            $('#wpbn-size-est-val').text(wpbn.i18n.could_not_calc);
        });
    }

    if ($('#wpbn-size-estimate-bar').length) {
        loadSizeEstimate();
    }
    $('#wpbn-size-refresh').on('click', function(){
        loadSizeEstimate();
    });

    /* ── DB Health Check ─────────────────────────────── */
    function loadDbHealth() {
        $.post(ajaxUrl, { action:'wpbn_db_health', nonce:nonce }, function(res){
            if (!res.success) return;
            var d   = res.data;
            var bar = $('#wpbn-db-health-bar');
            if (d.ok) {
                bar.hide();
                return;
            }
            var html = '<div style="background:#fff8e5;border:1px solid #dba617;border-left:4px solid #dba617;border-radius:6px;padding:10px 14px;font-size:.86rem;">'
                     + '<strong>⚠️ Database table warnings:</strong><ul style="margin:6px 0 0 16px;padding:0;">';
            $.each(d.broken, function(_i, b){
                html += '<li>' + escHtml(b.table) + ': ' + escHtml(b.message);
                if (b.repaired) html += ' <span style="color:#00a32a;font-weight:600;">(✅ Auto-repaired)</span>';
                html += '</li>';
            });
            html += '</ul></div>';
            bar.html(html).show();
        });
    }

    if ($('#wpbn-db-health-bar').length) {
        loadDbHealth();
    }

    /* ── Start Backup ────────────────────────────────── */
    $backupBtn.on('click', function(){
        $backupBtn.prop('disabled', true);
        $('#wpbn-cleanup-orphans').prop('disabled', true);
        $progress.show();
        $bar.css('background', '#2271b1');

        var selectiveType = $('#wpbn-backup-type').val() || 'full';
        var notes         = $('#wpbn-notes').val();
        var selPaths      = [];
        if (selectiveType === 'files_only') {
            $('#wpbn-file-picker-tree .wpbn-picker-cb:checked:not(:disabled)').each(function() {
                selPaths.push($(this).val());
            });
        }
        var selTables = [];
        if (selectiveType === 'db_only') {
            $('#wpbn-table-picker-list .wpbn-table-cb:checked').each(function() {
                selTables.push($(this).val());
            });
        }

        // ── Step 1: init (DB export, file list) — triggers background process ──
        setProgress(2, wpbn.i18n.preparing_backup);

        $.post(ajaxUrl, {
            action          : 'wpbn_start_backup',
            nonce           : nonce,
            notes           : notes,
            backup_type     : selectiveType,
            selected_paths  : JSON.stringify(selPaths),
            selected_tables : JSON.stringify(selTables),
        }, function(res) {
            if (!res.success) {
                // Premium gate: redirect to upgrade page
                if (res.data && res.data.premium) {
                    resetUI();
                    if (confirm('⭐ ' + (res.data.error || 'This feature requires a Pro license.') + '\n\nWould you like to see pricing?')) {
                        window.open(res.data.upgrade_url || wpbn.upgrade_url, '_blank');
                    }
                    return;
                }
                fail(res.data.error || 'Init failed.');
                return;
            }

            var totalFiles = res.data.file_count || 0;
            setProgress(5, wpbn.i18n.adding_files + ' (0 / ' + totalFiles + ')');

            // ── Step 2: Chunk-based backup loop ──
            // Each call to run_backup_bg processes ~20 seconds of work, then returns.
            // We call it again if not finished. Works on ALL hosting.
            var chunkErrors = 0;
            function runChunk() {
                $.post(ajaxUrl, {
                    action : 'wpbn_run_backup_bg',
                    nonce  : nonce,
                }, function(res) {
                    chunkErrors = 0;

                    if (!res.success) {
                        fail(res.data && res.data.error ? res.data.error : wpbn.i18n.backup_failed);
                        return;
                    }

                    var d = res.data;

                    if (d.status === 'done') {
                        // Backup completed
                        if (d.result) {
                            showDoneMsg(d.result);
                        } else {
                            setProgress(100, wpbn.i18n.backup_completed);
                            $backupBtn.prop('disabled', false);
                            refreshBackupList();
                        }
                    } else if (d.status === 'zipping') {
                        // More files to process — update progress, call again
                        if (d.total) { totalFiles = d.total; }
                        var pct = 5 + (d.percent || 0) * 0.85;
                        setProgress(pct, wpbn.i18n.adding_files + ' (' + (d.offset || 0) + ' / ' + totalFiles + ')');
                        setTimeout(runChunk, 500);
                    } else {
                        // Unknown status — try again
                        setTimeout(runChunk, 2000);
                    }
                }).fail(function() {
                    chunkErrors++;
                    if (chunkErrors < 5) {
                        setTimeout(runChunk, 3000);
                    } else {
                        // Backup continues via WP-Cron in the background
                        setProgress($bar.width() / $bar.parent().width() * 100 || 50,
                            wpbn.i18n.connection_lost);
                        $bar.css('background', '#dba617');
                        pollBackupStatus();
                    }
                });
            }

            runChunk();

        }).fail(function() { fail(wpbn.i18n.server_conn_lost); });
    });

    /* ── Delete Backup ───────────────────────────────── */
    $(document).on('click', '.wpbn-delete-backup', function(){
        if (!confirm(wpbn.i18n.confirm_delete)) return;
        var id   = $(this).data('id');
        var $row = $(this).closest('tr');
        $.post(ajaxUrl, {
            action    : 'wpbn_delete_backup',
            nonce     : nonce,
            backup_id : id,
        }, function(res){
            if (res.success) {
                // Do not remove row — switch to deleted appearance
                $row.css('opacity', '0.55');
                $row.find('.wpbn-delete-backup').remove();
                $row.find('.wpbn-btn-dropdown').remove();
                // Update status badge
                var $statusCell = $row.find('td').eq(3);
                $statusCell.html('<span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:.78rem;font-weight:600;background:#646970;color:#fff;">' + wpbn.i18n.deleted_badge + '</span>');
            } else {
                alert(wpbn.i18n.error_prefix + (res.data ? res.data.message : wpbn.i18n.unknown_error));
            }
        });
    });

    /* ── Cleanup Orphan Files ────────────────────────── */
    $('#wpbn-cleanup-orphans').on('click', function(){
        var $btn = $(this).prop('disabled', true).text(wpbn.i18n.cleaning);
        var $msg = $('#wpbn-cleanup-msg').text('');
        $.post(ajaxUrl, { action:'wpbn_cleanup_orphans', nonce:nonce }, function(res){
            if (res.success) {
                if (res.data.count > 0) {
                    $msg.text('✅ ' + res.data.count + ' temp file(s)/folder(s) deleted.').css('color','#00a32a');
                } else {
                    $msg.text(wpbn.i18n.no_temp_found).css('color','#646970');
                }
            } else {
                $msg.text('❌ ' + wpbn.i18n.error_prefix + (res.data.message || wpbn.i18n.unknown_error)).css('color','#d63638');
            }
        }).fail(function(){
            $msg.text(wpbn.i18n.request_failed).css('color','#d63638');
        }).always(function(){
            $btn.prop('disabled', false).text(wpbn.i18n.clean_temp);
        });
    });

    /* ── Refresh Backup List ─────────────────────────── */
    $('#wpbn-refresh-list').on('click', refreshBackupList);

    function refreshBackupList() {
        $.post(ajaxUrl, { action:'wpbn_get_backups', nonce:nonce }, function(res){
            if (!res.success) return;
            var backups = res.data;
            var $container = $('#wpbn-backup-list');

            if (!backups || !backups.length) {
                $container.html('<p class="wpbn-empty">' + wpbn.i18n.no_backups + '</p>');
                return;
            }

            var html = '<table class="widefat wpbn-table"><thead><tr>'
                + '<th>' + wpbn.i18n.col_filename + '</th><th>' + wpbn.i18n.col_size + '</th><th>' + wpbn.i18n.col_status + '</th><th>' + wpbn.i18n.col_created + '</th><th>' + wpbn.i18n.col_actions + '</th>'
                + '</tr></thead><tbody>';

            $.each(backups, function(_i, b){
                var dlUrl  = wpbn.ajax_url + '?action=wpbn_download_backup&backup_id='    + b.id + '&nonce=' + wpbn.nonce;
                var insUrl = wpbn.ajax_url + '?action=wpbn_download_installer&backup_id=' + b.id + '&nonce=' + wpbn.nonce;
                var statusBadge = {
                    'complete': '<span class="badge wpbn-badge-success">' + wpbn.i18n.status_complete + '</span>',
                    'failed':   '<span class="badge wpbn-badge-failed">'  + wpbn.i18n.status_failed   + '</span>',
                    'pending':  '<span class="badge wpbn-badge-pending">' + wpbn.i18n.status_pending   + '</span>',
                }[b.status] || '<span class="badge bg-secondary">' + escHtml(b.status) + '</span>';

                var actionsHtml = '<div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">';
                if (b.local_exists) {
                    actionsHtml += '<div class="wpbn-btn-dropdown">'
                        + '<button type="button" class="btn btn-outline-secondary btn-sm wpbn-dropdown-toggle">' + wpbn.i18n.download + ' <span style="font-size:.7em;opacity:.7;">▾</span></button>'
                        + '<div class="wpbn-dropdown-menu">'
                        + '<a href="' + dlUrl  + '" class="wpbn-dropdown-item wpbn-download-btn">' + wpbn.i18n.download_zip + '</a>'
                        + '<a href="' + insUrl + '" class="wpbn-dropdown-item wpbn-installer-btn">' + wpbn.i18n.installer_php + '</a>'
                        + '</div></div>';
                    actionsHtml += '<div class="wpbn-btn-dropdown">'
                        + '<button type="button" class="btn btn-outline-warning btn-sm wpbn-dropdown-toggle">' + wpbn.i18n.btn_actions + ' <span style="font-size:.7em;opacity:.7;">▾</span></button>'
                        + '<div class="wpbn-dropdown-menu">'
                        + '<a href="' + escHtml(wpbn.upgrade_url) + '" target="_blank" rel="noopener" class="wpbn-dropdown-item">' + wpbn.i18n.restore_pro_label + ' <span class="wpbn-pro-badge">PRO</span></a>'
                        + '</div></div>';
                } else {
                    actionsHtml += '<span class="wpbn-muted">&mdash;</span>';
                }
                actionsHtml += '<button class="btn btn-outline-danger btn-sm wpbn-delete-backup" data-id="' + b.id + '">🗑</button>';
                actionsHtml += '</div>';

                html += '<tr data-id="' + b.id + '">'
                    + '<td><span class="dashicons dashicons-archive" style="font-size:.9rem;color:#64748b;vertical-align:text-bottom;"></span> ' + escHtml(b.filename) + '</td>'
                    + '<td>' + formatBytes(b.filesize) + '</td>'
                    + '<td>' + statusBadge + '</td>'
                    + '<td style="white-space:nowrap;">' + escHtml(b.created_at) + '</td>'
                    + '<td style="text-align:right;">' + actionsHtml + '</td>'
                    + '</tr>';
            });

            html += '</tbody></table>';
            $container.html(html);
        });
    }

    /* ── Action Button Dropdowns ────────────────────── */
    $(document).on('click', '.wpbn-dropdown-toggle', function(e) {
        e.stopPropagation();
        var $menu = $(this).next('.wpbn-dropdown-menu');
        $('.wpbn-dropdown-menu.wpbn-dd-open').not($menu).removeClass('wpbn-dd-open');
        $menu.toggleClass('wpbn-dd-open');
    });
    $(document).on('click', function() {
        $('.wpbn-dropdown-menu.wpbn-dd-open').removeClass('wpbn-dd-open');
    });

    /* ── Settings ────────────────────────────────────── */
    $('#wpbn-save-settings').on('click', function(){
        var $btn = $(this).prop('disabled', true).text(wpbn.i18n.saving);
        var $msg = $('#wpbn-save-msg').removeClass('error').text('');

        var data = {
            action              : 'wpbn_save_settings',
            nonce               : nonce,
            max_backups         : $('#max_backups').val(),
            chunk_size_mb            : $('#chunk_size_mb').val(),
            files_per_chunk_override : $('#wpbn-fpc-override-toggle').is(':checked') ? $('#files_per_chunk_override').val() : '0',
            encryption_enabled  : $('#encryption_enabled').is(':checked') ? '1' : '0',
            encryption_password : $('#encryption_password').val(),
            exclude_paths       : $('#exclude_paths').val(),
            excluded_cache_presets : $('input[name="excluded_cache_presets[]"]:checked').map(function(){ return this.value; }).get(),
            exclude_root_ini       : $('#exclude_root_ini').is(':checked') ? '1' : '0',
        };

        $.post(ajaxUrl, data, function(res){
            if (res.success) {
                // Reload the page preserving the active tab so server-side
                // status indicators (connected/not connected, etc.) update.
                var activeTab = $('.nav-link[data-tab].active').data('tab') || '';
                var url = new URL(window.location.href);
                url.searchParams.set('saved', '1');
                if (activeTab) url.searchParams.set('tab', activeTab);
                window.location.href = url.toString();
            } else {
                $msg.addClass('error').text('❌ ' + (res.data.message || wpbn.i18n.save_failed));
                $btn.prop('disabled', false).text(wpbn.i18n.save_settings);
            }
        }).fail(function(){
            $msg.addClass('error').text(wpbn.i18n.request_failed);
            $btn.prop('disabled', false).text(wpbn.i18n.save_settings);
        });
    });

    /* ── Exclusions Directory Tree ───────────────────── */
    var excludedPaths = {};  // path → name

    // Parse saved exclusions
    (function initExcluded() {
        var raw = $('#exclude_paths').val() || '';
        raw.split('\n').forEach(function(p) {
            p = p.trim();
            if (p) {
                var name = p.split('/').filter(Boolean).pop() || p;
                excludedPaths[p] = name;
            }
        });
    })();

    // Load tree when exclusions tab is opened
    $(document).on('click', '.wpbn-tab[data-tab="exclude"], .nav-link[data-tab="exclude"]', function() {
        if ($('#wpbn-dir-tree .wpbn-tree-node').length === 0) {
            loadDirTree('', $('#wpbn-dir-tree'));
        }
        renderTags();
    });

    function loadDirTree(path, $container) {
        $container.html('<div style="padding:14px;color:#999;font-size:.85rem;">⏳ ' + wpbn.i18n.loading + '</div>');
        $.post(ajaxUrl, { action: 'wpbn_list_dir', nonce: nonce, path: path }, function(res) {
            if (!res.success || !res.data.items.length) {
                $container.html('<div style="padding:14px;color:#999;font-size:.85rem;">📂 No subdirectories in this folder.</div>');
                return;
            }
            var $ul = $('<ul class="wpbn-tree-node">');
            $.each(res.data.items, function(_i, item) {
                $ul.append(buildTreeRow(item));
            });
            $container.html($ul);
        }).fail(function() {
            $container.html('<div style="padding:14px;color:#d63638;font-size:.85rem;">' + wpbn.i18n.failed_to_load + '</div>');
        });
    }

    function buildTreeRow(item) {
        var isExcluded = !!excludedPaths[item.path];
        var $li = $('<li>');

        var $row = $('<div class="wpbn-tree-row' + (isExcluded ? ' excluded' : '') + '">');
        $row.attr('data-path', item.path).attr('data-name', item.name);

        // Toggle (▶ / ▼) — empty if no children
        var $toggle = $('<span class="wpbn-toggle' + (item.has_children ? '' : ' empty') + '">');
        $toggle.html(item.has_children ? '▶' : '');
        $row.append($toggle);

        // Checkbox
        var $cb = $('<input type="checkbox" class="wpbn-tree-cb">');
        $cb.prop('checked', isExcluded);
        $row.append($cb);

        // Icon + name + path
        $row.append('<span class="wpbn-tree-icon">📁</span>');
        $row.append('<span class="wpbn-tree-name">' + escHtml(item.name) + '</span>');
        $row.append('<span class="wpbn-tree-path">' + escHtml(item.rel) + '</span>');

        $li.append($row);

        // Child folder container (lazy loaded)
        if (item.has_children) {
            var $children = $('<div class="wpbn-children">');
            $li.append($children);

            $toggle.on('click', function(e) {
                e.stopPropagation();
                var $ch = $li.children('.wpbn-children');
                if ($ch.hasClass('open')) {
                    $ch.removeClass('open');
                    $toggle.html('▶');
                } else {
                    $ch.addClass('open');
                    $toggle.html('▼');
                    if ($ch.children().length === 0) {
                        loadDirTree(item.path, $ch);
                    }
                }
            });
        }

        // On checkbox change
        $cb.on('change', function() {
            toggleExclude(item.path, item.name, $(this).is(':checked'), $row);
        });

        // Row click (excluding checkbox and toggle)
        $row.on('click', function(e) {
            if ($(e.target).is('.wpbn-tree-cb') || $(e.target).is('.wpbn-toggle')) return;
            $cb.prop('checked', !$cb.prop('checked')).trigger('change');
        });

        return $li;
    }

    function toggleExclude(path, name, add, $row) {
        if (add) {
            excludedPaths[path] = name;
            $row.addClass('excluded');
        } else {
            delete excludedPaths[path];
            $row.removeClass('excluded');
        }
        syncHiddenInput();
        renderTags();
    }

    function syncHiddenInput() {
        $('#exclude_paths').val(Object.keys(excludedPaths).join('\n'));
    }

    function renderTags() {
        var $box = $('#wpbn-selected-paths-box');
        var $none = $('#wpbn-no-selection');
        $box.find('.wpbn-tag').remove();

        var paths = Object.keys(excludedPaths);
        if (paths.length === 0) {
            $none.show();
        } else {
            $none.hide();
            paths.forEach(function(path) {
                var name = excludedPaths[path];
                var $tag = $('<span class="wpbn-tag">'
                    + escHtml(name)
                    + ' <button type="button" title="Remove" data-path="' + escHtml(path) + '">×</button>'
                    + '</span>');
                $box.append($tag);
            });
        }
    }

    // Remove tag on × button click
    $(document).on('click', '.wpbn-tag button', function() {
        var path = $(this).data('path');
        delete excludedPaths[path];
        syncHiddenInput();
        renderTags();
        // Also uncheck tree checkbox
        $('[data-path="' + path + '"] .wpbn-tree-cb').prop('checked', false);
        $('[data-path="' + path + '"]').removeClass('excluded');
    });

    // Load if exclusions tab is active on page load
    if ($('.wpbn-tab[data-tab="exclude"], .nav-link[data-tab="exclude"]').hasClass('active')) {
        loadDirTree('', $('#wpbn-dir-tree'));
        renderTags();
    }

    /* ── Helpers ─────────────────────────────────────── */
    function escHtml(str) {
        return $('<div>').text(str).html();
    }
    function formatBytes(bytes) {
        if (!bytes) return '0 B';
        var k = 1024, sizes = ['B','KB','MB','GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        var val = bytes / Math.pow(k, i);
        return (i === 3 ? val.toFixed(2) : Math.round(val)) + ' ' + sizes[i];
    }

    /* ── Page-load: detect ongoing backup ────────────── */
    function checkOngoingBackup() {
        if (!$backupBtn.length) return;
        $.post(ajaxUrl, { action: 'wpbn_backup_status', nonce: nonce }, function(res) {
            if (!res.success) return;
            var d = res.data;
            if (d.stale) {
                $progress.show();
                $bar.css({ width: '100%', background: '#dba617' });
                var staleMsg = wpbn.i18n.stale_msg;
                if (d.error) staleMsg += ' ' + wpbn.i18n.stale_reason + ' ' + escHtml(d.error);
                staleMsg += ' ' + wpbn.i18n.stale_can_start;
                $progMsg.html(staleMsg);
                $backupBtn.prop('disabled', false);
                $('#wpbn-cleanup-orphans').prop('disabled', false);
                refreshBackupList();
            } else if (d.running) {
                $backupBtn.prop('disabled', true);
                $('#wpbn-cleanup-orphans').prop('disabled', true);
                $progress.show();
                $bar.css('background', '#2271b1');
                setProgress(
                    5 + (d.percent || 0) * 0.85,
                    wpbn.i18n.adding_files + ' (' + (d.offset||0) + ' / ' + (d.total||0) + ')'
                );
                pollBackupStatus();
            } else if (d.finished && d.result) {
                if (!$backupBtn.prop('disabled')) {
                    $progress.show();
                    $bar.css('background', '#2271b1');
                    showDoneMsg(d.result);
                }
            }
        });
    }

    var pollRetries = 0;
    var MAX_POLL_RETRIES = 20;

    function pollBackupStatus() {
        if (++pollRetries > MAX_POLL_RETRIES) {
            $backupBtn.prop('disabled', false);
            $('#wpbn-cleanup-orphans').prop('disabled', false);
            $progress.hide();
            return;
        }
        setTimeout(function() {
            $.post(ajaxUrl, { action: 'wpbn_backup_status', nonce: nonce }, function(res) {
                if (!res.success) { pollBackupStatus(); return; }
                var d = res.data;
                if (d.running) {
                    pollRetries = 0;
                    setProgress(
                        5 + (d.percent || 0) * 0.85,
                        wpbn.i18n.adding_files + ' (' + (d.offset||0) + ' / ' + (d.total||0) + ')'
                    );
                    pollBackupStatus();
                } else if (d.finished && d.result) {
                    showDoneMsg(d.result);
                } else {
                    $backupBtn.prop('disabled', false);
                    $('#wpbn-cleanup-orphans').prop('disabled', false);
                    $progress.hide();
                }
            }).fail(function() { pollBackupStatus(); });
        }, 3000);
    }

    checkOngoingBackup();

    /* ── Site Changes Bar ───────────────────────────── */
    (function loadSiteChanges() {
        var $bar = $('#wpbn-site-changes-bar');
        if (!$bar.length) return;

        function fmtN(tpl, n) { return tpl.replace('%d', n); }

        $.post(ajaxUrl, { action: 'wpbn_site_changes', nonce: nonce }, function(res) {
            if (!res.success) return;
            var d = res.data;
            if (d.no_backup) return;

            var ago = d.ago_days === 0 ? wpbn.i18n.sc_today
                    : d.ago_days === 1 ? fmtN(wpbn.i18n.sc_day_ago, 1)
                    : fmtN(wpbn.i18n.sc_days_ago, d.ago_days);

            var html = '<strong>' + wpbn.i18n.sc_since + '</strong> ' + escHtml(d.last_date) + ' (' + escHtml(ago) + ')';

            if (d.has_changes) {
                var tags = [];
                $.each(d.changes, function(_i, c) {
                    if (c.type === 'wp')      tags.push('⬆️ WordPress ' + escHtml(c.detail));
                    else if (c.type === 'plugins') tags.push('🧩 ' + wpbn.i18n.sc_plugins);
                    else if (c.type === 'themes')  tags.push('🎨 ' + wpbn.i18n.sc_themes);
                    else if (c.type === 'uploads') tags.push('🖼️ ' + wpbn.i18n.sc_uploads);
                });
                if (tags.length) {
                    html += ' &mdash; ' + tags.join(', ');
                }
            }

            $('#wpbn-site-changes-content').html(html);
            $bar.css('display', 'flex');
        });
    })();

    $('#wpbn-site-changes-dismiss').on('click', function() {
        $('#wpbn-site-changes-bar').hide();
    });

    /* ── Settings: encryption checkbox toggle ───────── */
    $('#encryption_enabled').on('change', function() {
        $('#wpbn-enc-pass-row').toggle(this.checked);
        if (!this.checked) $('#encryption_password').val('');
    });

    /* ── Settings: remove encryption ───────────────── */
    $(document).on('click', '#wpbn-remove-encryption', function() {
        if (!confirm(wpbn.i18n.confirm_remove_encryption)) return;
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.post(ajaxUrl, { action: 'wpbn_remove_encryption', nonce: nonce }, function(res) {
            if (res.success) {
                $('#encryption_enabled').prop('checked', false).trigger('change');
                $btn.closest('#wpbn-enc-pass-row').hide();
            }
        }).always(function() {
            $btn.prop('disabled', false);
        });
    });

    /* ── Backup Type cards ───────────────────────────── */
    $(document).on('click', '.wpbn-btype-card:not([disabled])', function() {
        $('.wpbn-btype-card').removeClass('wpbn-btype-card--active');
        $(this).addClass('wpbn-btype-card--active');
        $('#wpbn-backup-type').val($(this).data('btype')).trigger('change');
    });

    var filePickerLoaded  = false;
    var tablePickerLoaded = false;

    function updatePickerHint() {
        var hasSelected = $('#wpbn-file-picker-tree .wpbn-picker-cb:checked:not(:disabled)').length > 0;
        $('#wpbn-picker-hint').text(hasSelected ? wpbn.i18n.picker_hint_sel : wpbn.i18n.picker_hint_empty);
        var $notice = $('#wpbn-exclusion-notice');
        if (hasSelected) { $notice.hide(); } else { $notice.show(); }
    }

    function updateTablePickerHint() {
        var total    = $('#wpbn-table-picker-list .wpbn-table-cb').length;
        var selected = $('#wpbn-table-picker-list .wpbn-table-cb:checked').length;
        $('#wpbn-table-picker-hint').text(
            selected === total ? wpbn.i18n.table_hint_all : wpbn.i18n.table_hint_sel
        );
    }

    function buildTablePickerList() {
        var $list = $('#wpbn-table-picker-list');
        if (!wpbn.db_tables || !wpbn.db_tables.length) {
            $list.html('<div style="padding:6px 10px;color:#94a3b8;font-size:.82rem;">No tables found.</div>');
            return;
        }
        var $ul = $('<ul class="wpbn-tree-node">');
        $.each(wpbn.db_tables, function(_, tbl) {
            var $li  = $('<li>');
            var $row = $('<div class="wpbn-tree-row">');
            var $cb  = $('<input type="checkbox" class="wpbn-table-cb wpbn-tree-cb">')
                .val(tbl).prop('checked', true);
            $cb.on('change', updateTablePickerHint);
            $row.append($('<span class="wpbn-toggle empty">'));
            $row.append($cb);
            $row.append($('<span class="wpbn-tree-icon">').text('🗃️'));
            $row.append($('<span class="wpbn-tree-name">').html(
                '<code style="font-size:.78rem;">' + escHtml(tbl) + '</code>'
            ));
            $li.append($row);
            $ul.append($li);
        });
        $list.html($ul);
        updateTablePickerHint();
    }

    function loadBrowseDirLevel(path, $container, $parentCb) {
        $container.html('<div style="padding:6px 10px;color:#94a3b8;font-size:.82rem;">' + wpbn.i18n.loading + '</div>');
        $.post(ajaxUrl, { action: 'wpbn_browse_dir', nonce: nonce, path: path }, function(res) {
            if (!res.success || !res.data.items || !res.data.items.length) {
                $container.html('<div style="padding:6px 10px;color:#94a3b8;font-size:.82rem;">' + wpbn.i18n.no_subdirs + '</div>');
                return;
            }
            var isParentChecked = $parentCb ? $parentCb.prop('checked') : false;
            var $ul = $('<ul class="wpbn-tree-node">');
            $.each(res.data.items, function(_, item) {
                $ul.append(buildPickerRow(item, isParentChecked, isParentChecked));
            });
            $container.html($ul);
        }).fail(function() {
            $container.html('<div style="padding:6px 10px;color:#d63638;font-size:.82rem;">' + wpbn.i18n.failed_to_load + '</div>');
        });
    }

    function buildPickerRow(item, initialChecked, initialDisabled) {
        var icon = item.is_file ? '📄' : '📁';
        var $li  = $('<li>');
        var $row = $('<div class="wpbn-tree-row">');

        var $toggle = $('<span>').addClass('wpbn-toggle' + (item.has_children ? '' : ' empty'))
                                 .html(item.has_children ? '▶' : '');
        $row.append($toggle);

        var $cb = $('<input type="checkbox" class="wpbn-picker-cb wpbn-tree-cb">')
            .val(item.path)
            .prop('checked', !!initialChecked)
            .prop('disabled', !!initialDisabled);
        $row.append($cb);

        $row.append($('<span class="wpbn-tree-icon">').text(icon));
        $row.append($('<span class="wpbn-tree-name">').html(
            '<code style="font-size:.78rem;">' + escHtml(item.name) + (item.is_file ? '' : '/') + '</code>'
        ));

        $li.append($row);

        if (item.has_children) {
            var $kids = $('<div class="wpbn-children">');
            $li.append($kids);

            $toggle.on('click', function(e) {
                e.stopPropagation();
                var isOpen = $kids.hasClass('open');
                if (!isOpen) {
                    $kids.addClass('open');
                    $toggle.html('▼');
                    if ($kids.children().length === 0) {
                        loadBrowseDirLevel(item.path, $kids, $cb);
                    }
                } else {
                    $kids.removeClass('open');
                    $toggle.html('▶');
                }
            });
        }

        $cb.on('change', function() {
            var checked = $(this).prop('checked');
            if (checked) {
                $li.find('.wpbn-children .wpbn-picker-cb').prop('disabled', true).prop('checked', true);
            } else {
                $li.children('.wpbn-children').find('> ul > li > .wpbn-tree-row .wpbn-picker-cb').prop('disabled', false);
            }
            updatePickerHint();
        });

        return $li;
    }

    $('#wpbn-picker-select-all').on('click', function() {
        $('#wpbn-file-picker-tree > ul > li > .wpbn-tree-row .wpbn-picker-cb').each(function() {
            $(this).prop('checked', true).trigger('change');
        });
    });
    $('#wpbn-picker-select-none').on('click', function() {
        $('#wpbn-file-picker-tree > ul > li > .wpbn-tree-row .wpbn-picker-cb').each(function() {
            $(this).prop('checked', false).trigger('change');
        });
    });

    $('#wpbn-table-picker-all').on('click', function() {
        $('#wpbn-table-picker-list .wpbn-table-cb').prop('checked', true);
        updateTablePickerHint();
    });
    $('#wpbn-table-picker-none').on('click', function() {
        $('#wpbn-table-picker-list .wpbn-table-cb').prop('checked', false);
        updateTablePickerHint();
    });

    function updateExclusionNotice() {
        var val      = $('#wpbn-backup-type').val();
        var $notice  = $('#wpbn-exclusion-notice');
        var $picker  = $('#wpbn-file-picker');
        var $tpicker = $('#wpbn-table-picker');
        if (val === 'files_only') {
            $picker.show();
            $tpicker.hide();
            if (!filePickerLoaded) {
                loadBrowseDirLevel('', $('#wpbn-file-picker-tree'), null);
                filePickerLoaded = true;
            }
            updatePickerHint();
        } else if (val === 'db_only') {
            $picker.hide();
            $tpicker.show();
            $notice.hide();
            if (!tablePickerLoaded) {
                buildTablePickerList();
                tablePickerLoaded = true;
            }
            updateTablePickerHint();
        } else {
            $picker.hide();
            $tpicker.hide();
            if (val === 'full') { $notice.show(); } else { $notice.hide(); }
        }
    }
    $('#wpbn-backup-type').on('change', updateExclusionNotice);
    updateExclusionNotice(); // run on page load

    /* ── Chunk size: recalculate + reset ────────────── */
    $('#wpbn-chunk-recalculate').on('click', function() {
        var $btn = $(this).prop('disabled', true).text(wpbn.i18n.calculating);
        $.post(ajaxUrl, { action: 'wpbn_chunk_recommend', nonce: nonce }, function(res) {
            if (res.success) {
                var val = res.data.recommended;
                $('#chunk_size_mb').val(val).trigger('change');
                var $badge = $('#wpbn-chunk-auto-badge');
                $badge.html('Auto-calculated: <strong>' + val + ' MB</strong> — <em style="color:#00a32a;">Updated!</em>');
                setTimeout(function() {
                    $badge.html('Auto-calculated: <strong>' + val + ' MB</strong>');
                }, 2500);
            }
        }).always(function() {
            $btn.prop('disabled', false).text(wpbn.i18n.recalculate);
        });
    });
    $(document).on('click', '#wpbn-chunk-reset-auto', function(e) {
        e.preventDefault();
        $('#chunk_size_mb').val($(this).data('val')).trigger('change');
        $(this).closest('span').find('a').remove();
    });
    $('#chunk_size_mb').on('input change', function() {
        var mb = Math.max(2, Math.min(50, parseInt($(this).val()) || 5));
        var auto = Math.max(50, Math.min(mb * 40, 2000));
        $('#wpbn-fpc-auto-val').text(auto);
        if (!$('#wpbn-fpc-override-toggle').is(':checked')) {
            $('#files_per_chunk_override').val(auto);
        }
    });
    $('#wpbn-fpc-override-toggle').on('change', function() {
        var $input = $('#files_per_chunk_override');
        if ($(this).is(':checked')) {
            $input.show().focus();
        } else {
            var mb = Math.max(2, Math.min(50, parseInt($('#chunk_size_mb').val()) || 5));
            $input.val(Math.max(50, Math.min(mb * 40, 2000))).hide();
        }
    });

    // ── Logs page: collapsible backup log rows ────────────────────────────────
    $(document).on('click', '.wpbn-log-toggle-row', function() {
        var targetId = $(this).data('target');
        var $target  = $('#' + targetId);
        var $arrow   = $(this).find('.wpbn-log-arrow');
        var isOpen   = $target.is(':visible');
        $target.toggle(!isOpen);
        $arrow.toggleClass('dashicons-arrow-right-alt2', isOpen)
              .toggleClass('dashicons-arrow-down-alt2',  !isOpen);
    });

    // ── Review notice buttons ─────────────────────────────────────────────────
    $(document).on('click', '.wpbn-review-notice [data-wpbn-review]', function() {
        var action = $(this).data('wpbn-review');
        var $notice = $(this).closest('.wpbn-review-notice');
        $notice.fadeOut(200, function() { $notice.remove(); });
        if (action === 'dismiss' || action === 'review') {
            sendReviewAction('dismiss');
        } else if (action === 'remind') {
            sendReviewAction('remind');
        }
    });

    // ── Logs page: save retention setting ────────────────────────────────────
    $('#wpbn-save-log-retention').on('click', function() {
        var $btn    = $(this);
        var $status = $('#wpbn-log-retention-status');
        var keep    = parseInt( $('#wpbn-log-retention').val(), 10 );
        if ( isNaN(keep) || keep < 1 ) return;

        $btn.prop('disabled', true).text(wpbn.i18n.logs_saving || 'Saving…');
        $status.hide();

        $.post(wpbn.ajax_url, {
            action: 'wpbn_save_log_retention',
            nonce:  wpbn.nonce,
            keep:   keep
        }, function(res) {
            $btn.prop('disabled', false).text('Save');
            if (res.success) {
                $status.text(wpbn.i18n.logs_saved || '✅ Saved').show();
                setTimeout(function(){ $status.fadeOut(); }, 3000);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Save');
        });
    });

});
