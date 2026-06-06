<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is always $wpdb->prefix.'wpbn_backups', never user input
if ( ! defined( 'ABSPATH' ) ) exit;

class WPBN_Admin {

    public function __construct() {
        add_action( 'admin_menu',             array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_assets' ) );
        add_action( 'wp_dashboard_setup',     array( $this, 'register_dashboard_widget' ) );
        add_action( 'admin_notices',          array( $this, 'render_review_notice' ) );
    }

    public function register_menus() {
        add_menu_page(
            __( 'Nota Backup & Restore', 'nota-backup-restore' ),
            __( 'Nota Backup', 'nota-backup-restore' ),
            'manage_options',
            'wp-backup-nota',
            array( $this, 'render_main_page' ),
            'dashicons-backup', 75
        );
        add_submenu_page( 'wp-backup-nota', __( 'Backups',  'nota-backup-restore' ), __( 'Backups',  'nota-backup-restore' ), 'manage_options', 'wp-backup-nota',          array( $this, 'render_main_page' ) );
        add_submenu_page( 'wp-backup-nota', __( 'History',  'nota-backup-restore' ), __( 'History',  'nota-backup-restore' ), 'manage_options', 'wp-backup-nota-history',  array( $this, 'render_history_page' ) );
        add_submenu_page( 'wp-backup-nota', __( 'Logs',     'nota-backup-restore' ), __( 'Logs',     'nota-backup-restore' ), 'manage_options', 'wp-backup-nota-logs',     array( $this, 'render_logs_page' ) );
        add_submenu_page( 'wp-backup-nota', __( 'Settings', 'nota-backup-restore' ), __( 'Settings', 'nota-backup-restore' ), 'manage_options', 'wp-backup-nota-settings', array( $this, 'render_settings_page' ) );
    }

    public function enqueue_assets( $hook ) {
        // Dashboard widget assets
        if ( $hook === 'index.php' ) {
            $nonce    = wp_create_nonce( 'wpbn_nonce' );
            $ajax_url = admin_url( 'admin-ajax.php' );
            $css = '#wpbn_dashboard_widget .wpbn-dw-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f0f0f1;font-size:.88rem}'
                 . '#wpbn_dashboard_widget .wpbn-dw-row:last-child{border:none}'
                 . '#wpbn_dashboard_widget .wpbn-dw-label{color:#646970}'
                 . '#wpbn_dashboard_widget .wpbn-dw-val{font-weight:600;text-align:right}'
                 . '#wpbn_dashboard_widget .wpbn-dw-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.78rem;font-weight:600;color:#fff}'
                 . '#wpbn_dashboard_widget .wpbn-dw-actions{margin-top:12px;display:flex;gap:8px;}';
            wp_add_inline_style( 'dashboard', $css );

        }

        if ( strpos( $hook, 'wp-backup-nota' ) === false ) return;

        // Bootstrap 5.3 CSS — bundled locally, scoped under .wpbn-wrap
        wp_enqueue_style( 'wpbn-bootstrap', WPBN_PLUGIN_URL . 'assets/css/bootstrap.min.css', array(), '5.3.3' );

        // History page: register orphan backup handler
        if ( $hook === 'backup-nota_page_wp-backup-nota-history' || strpos( $hook, 'history' ) !== false ) {
            $history_js = '(function(){'
                . 'document.querySelectorAll(".wpbn-register-btn").forEach(function(btn){'
                . 'btn.addEventListener("click",function(){'
                . 'var filename=btn.dataset.filename,filesize=btn.dataset.filesize,created=btn.dataset.created;'
                . 'btn.disabled=true;btn.textContent=wpbn.i18n.loading;'
                . 'var fd=new FormData();'
                . 'fd.append("action","wpbn_register_backup");fd.append("nonce",wpbn.nonce);'
                . 'fd.append("filename",filename);fd.append("filesize",filesize);fd.append("created",created);'
                . 'fetch(wpbn.ajax_url,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(res){'
                . 'if(res.success){'
                . 'var row=btn.closest("tr");row.className="";row.style.opacity="";'
                . 'var dlUrl=wpbn.ajax_url+"?action=wpbn_download_backup&backup_id="+res.data.id+"&nonce="+wpbn.nonce;'
                . 'var insUrl=wpbn.ajax_url+"?action=wpbn_download_installer&backup_id="+res.data.id+"&nonce="+wpbn.nonce;'
                . 'btn.closest("td").innerHTML=\'<div class="wpbn-btn-dropdown"><button type="button" class="btn btn-sm btn-outline-secondary wpbn-dropdown-toggle">\'+wpbn.i18n.download+\' <span style="font-size:.7em;opacity:.7;">▾</span></button><div class="wpbn-dropdown-menu"><a href="\'+dlUrl+\'" class="wpbn-dropdown-item wpbn-download-btn">\'+wpbn.i18n.download_zip+\'</a><a href="\'+insUrl+\'" class="wpbn-dropdown-item wpbn-installer-btn">\'+wpbn.i18n.installer_php+\'</a></div></div>\';'
                . 'row.querySelector("td:first-child").textContent=res.data.id;'
                . 'var statusTd=row.querySelectorAll("td")[5];if(statusTd)statusTd.innerHTML=\'<span class="badge wpbn-badge-success">\'+wpbn.i18n.status_completed+\'</span>\';'
                . '}else{btn.disabled=false;btn.innerHTML=\'<span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom;"></span> \'+wpbn.i18n.register;alert(wpbn.i18n.error_prefix+(res.data&&res.data.message?res.data.message:wpbn.i18n.unknown_error));}'
                . '}).catch(function(){btn.disabled=false;btn.innerHTML=\'<span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom;"></span> \'+wpbn.i18n.register;});'
                . '});});'
                . '})();';
            wp_add_inline_script( 'wpbn-admin', $history_js );
        }

        wp_enqueue_style(  'wpbn-admin', WPBN_PLUGIN_URL . 'assets/css/admin.css', array( 'wpbn-bootstrap' ), WPBN_VERSION );
        wp_enqueue_script( 'wpbn-admin', WPBN_PLUGIN_URL . 'assets/js/admin.js',   array( 'jquery' ), WPBN_VERSION, true );

        global $wpdb;
        wp_localize_script( 'wpbn-admin', 'wpbn', array(
            'ajax_url'          => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'wpbn_nonce' ),
            'backup_dir_url'    => WPBN_BACKUP_URL,
            'upgrade_url'       => wpbn_upgrade_url(),
            'db_tables'         => $wpdb->get_col( 'SHOW TABLES' ),
            'review_url'        => 'https://wordpress.org/support/plugin/nota-backup-restore/reviews/#new-post',
            'show_review_prompt' => get_option( 'wpbn_review_dismissed' ) ? false : true,
            'i18n'           => array(
                'calculating'        => __( 'Calculating…', 'nota-backup-restore' ),
                'could_not_calc'     => __( 'Could not calculate', 'nota-backup-restore' ),
                'settings_saved'     => __( '✅ Settings saved!', 'nota-backup-restore' ),
                'saving'             => __( 'Saving…', 'nota-backup-restore' ),
                'save_settings'      => __( 'Save Settings', 'nota-backup-restore' ),
                'save_failed'        => __( 'Save failed', 'nota-backup-restore' ),
                'cleaning'           => __( '⏳ Cleaning…', 'nota-backup-restore' ),
                'clean_temp'         => __( '🧹 Clean Temp Files', 'nota-backup-restore' ),
                'no_temp_found'      => __( 'No temp files found to clean.', 'nota-backup-restore' ),
                'request_failed'     => __( '❌ Request failed.', 'nota-backup-restore' ),
                'no_backups'         => __( 'No backups yet.', 'nota-backup-restore' ),
                'status_complete'    => __( '✅ Complete', 'nota-backup-restore' ),
                'status_failed'      => __( '❌ Failed', 'nota-backup-restore' ),
                'status_pending'     => __( '⏳ Pending', 'nota-backup-restore' ),
                'status_completed'   => __( 'Completed', 'nota-backup-restore' ),
                'recalculate'        => __( '↺ Recalculate', 'nota-backup-restore' ),
                'loading'            => __( 'Loading…', 'nota-backup-restore' ),
                'failed_to_load'     => __( 'Failed to load.', 'nota-backup-restore' ),
                'error_prefix'       => __( 'Error: ', 'nota-backup-restore' ),
                'register'           => __( 'Register', 'nota-backup-restore' ),
                'download'           => __( '⬇ Download', 'nota-backup-restore' ),
                'installer'          => __( 'Installer', 'nota-backup-restore' ),
                'download_zip'       => __( '📦 ZIP File', 'nota-backup-restore' ),
                'installer_php'      => __( '🔧 Installer PHP', 'nota-backup-restore' ),
                'btn_actions'        => __( '↩ Actions', 'nota-backup-restore' ),
                'restore_pro_label'  => __( '↩ Restore', 'nota-backup-restore' ),
                'unknown_error'      => __( 'Unknown error', 'nota-backup-restore' ),
                'status_label'       => __( 'Status', 'nota-backup-restore' ),
                'size_label'         => __( 'Size', 'nota-backup-restore' ),
                'duration_label'     => __( 'Duration', 'nota-backup-restore' ),
                'new_backup'         => __( '+ New Backup', 'nota-backup-restore' ),
                'history'            => __( 'History', 'nota-backup-restore' ),
                'preparing_backup'   => __( '⏳ Exporting database and building file list…', 'nota-backup-restore' ),
                'adding_files'       => __( '⏳ Adding files to ZIP…', 'nota-backup-restore' ),
                'connection_lost'    => __( '⏳ Connection lost — backup continues in background. You can close this page safely.', 'nota-backup-restore' ),
                'server_conn_lost'   => __( 'Server connection lost (init).', 'nota-backup-restore' ),
                'backup_complete'    => __( '✅ Backup complete!', 'nota-backup-restore' ),
                'backup_completed'   => __( '✅ Backup completed.', 'nota-backup-restore' ),
                'confirm_delete'     => __( 'Are you sure you want to delete this backup? The file will be removed from the server but the record will remain in history.', 'nota-backup-restore' ),
                'deleted_badge'      => __( '🗑 Deleted', 'nota-backup-restore' ),
                'col_filename'       => __( 'Filename', 'nota-backup-restore' ),
                'col_size'           => __( 'Size', 'nota-backup-restore' ),
                'col_status'         => __( 'Status', 'nota-backup-restore' ),
                'col_created'        => __( 'Created', 'nota-backup-restore' ),
                'col_actions'        => __( 'Actions', 'nota-backup-restore' ),
                'stale_msg'          => __( '⚠️ Previous backup stopped unexpectedly.', 'nota-backup-restore' ),
                'stale_reason'       => __( 'Reason:', 'nota-backup-restore' ),
                'stale_can_start'    => __( 'You can start a new backup.', 'nota-backup-restore' ),
                'backup_failed'            => __( 'Backup failed.', 'nota-backup-restore' ),
                'confirm_remove_encryption' => __( 'Are you sure you want to remove encryption? The saved password will be deleted.', 'nota-backup-restore' ),
                'logs_saving'        => __( 'Saving…', 'nota-backup-restore' ),
                'logs_saved'         => __( '✅ Saved', 'nota-backup-restore' ),
                'sc_since'           => __( 'Last backup:', 'nota-backup-restore' ),
                'sc_today'           => __( 'today', 'nota-backup-restore' ),
                /* translators: %d: number of days */
                'sc_day_ago'         => __( '%d day ago', 'nota-backup-restore' ),
                /* translators: %d: number of days */
                'sc_days_ago'        => __( '%d days ago', 'nota-backup-restore' ),
                'sc_plugins'         => __( 'Plugins changed', 'nota-backup-restore' ),
                'sc_themes'          => __( 'Themes changed', 'nota-backup-restore' ),
                'sc_uploads'         => __( 'New uploads', 'nota-backup-restore' ),
                'review_prompt'      => __( 'Enjoying the plugin? Leave a quick ★★★★★ review — it really helps!', 'nota-backup-restore' ),
                'review_btn'         => __( 'Leave a Review', 'nota-backup-restore' ),
                'no_subdirs'         => __( '📂 No subdirectories in this folder.', 'nota-backup-restore' ),
                'picker_hint_empty'  => __( 'No items selected — all files will be backed up and exclusion rules apply.', 'nota-backup-restore' ),
                'picker_hint_sel'    => __( 'Only selected items will be backed up. Exclusion rules are ignored.', 'nota-backup-restore' ),
                'table_hint_all'     => __( 'All tables will be backed up.', 'nota-backup-restore' ),
                'table_hint_sel'     => __( 'Only selected tables will be backed up.', 'nota-backup-restore' ),
                /* translators: 1: files processed, 2: total files, 3: percentage */
                'log_zip_progress'   => __( '{offset} / {total} files ({percent}%)', 'nota-backup-restore' ),
            ),
        ) );
    }

    public function render_review_notice() {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'wp-backup-nota' ) === false ) return;
        if ( get_option( 'wpbn_review_dismissed' ) ) return;

        $activated_at = (int) get_option( 'wpbn_activated_at', 0 );
        if ( ! $activated_at || ( time() - $activated_at ) < 14 * DAY_IN_SECONDS ) return;

        $remind_after = (int) get_option( 'wpbn_review_remind_after', 0 );
        if ( $remind_after && time() < $remind_after ) return;

        $review_url = 'https://wordpress.org/support/plugin/nota-backup-restore/reviews/#new-post';
        ?>
        <div class="notice notice-info wpbn-review-notice" style="display:flex;align-items:center;gap:12px;padding:10px 16px;">
            <span style="font-size:1.3rem;line-height:1;">⭐</span>
            <p style="margin:0;flex:1;">
                <?php echo wp_kses_post( __( '<strong>Nota Backup & Restore</strong> — enjoying it? Please take a moment to leave a review on wp.org. It really helps!', 'nota-backup-restore' ) ); ?>
            </p>
            <a href="<?php echo esc_url( $review_url ); ?>" class="button button-primary" target="_blank" rel="noopener" data-wpbn-review="review">
                <?php esc_html_e( '★ Leave a Review', 'nota-backup-restore' ); ?>
            </a>
            <button type="button" class="button" data-wpbn-review="remind">
                <?php esc_html_e( 'Remind me later', 'nota-backup-restore' ); ?>
            </button>
            <button type="button" class="notice-dismiss" data-wpbn-review="dismiss" style="position:static;float:none;"></button>
        </div>
        <?php
    }

    public function render_main_page() {
        $backups  = WPBN_Backup::get_backups();
        $settings = WPBN_Settings::get();
        include WPBN_PLUGIN_DIR . 'admin/views/main.php';
    }

    public function render_history_page() {
        include WPBN_PLUGIN_DIR . 'admin/views/history.php';
    }

    public function render_logs_page() {
        include WPBN_PLUGIN_DIR . 'admin/views/logs.php';
    }

    public function render_settings_page() {
        $settings = WPBN_Settings::get();
        include WPBN_PLUGIN_DIR . 'admin/views/settings.php';
    }

    // ── Dashboard Widget ──────────────────────────────────────────────────────
    public function register_dashboard_widget() {
        wp_add_dashboard_widget(
            'wpbn_dashboard_widget',
            'Nota Backup &amp; Restore',
            array( $this, 'render_dashboard_widget' )
        );
    }

    public function render_dashboard_widget() {
        global $wpdb;
        $table  = $wpdb->prefix . 'wpbn_backups';
        $latest = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" );


        $status_colors = array(
            'complete' => '#00a32a',
            'failed'   => '#d63638',
            'pending'  => '#dba617',
        );

        if ( ! $latest ): ?>
        <p style="color:#646970;font-size:.88rem;"><?php esc_html_e( 'No backups yet.', 'nota-backup-restore' ); ?></p>
        <?php else:
            $status_color = $status_colors[ $latest->status ] ?? '#646970';
            $status_labels = array(
                'complete' => __( 'Completed', 'nota-backup-restore' ),
                'failed'   => __( 'Failed',    'nota-backup-restore' ),
                'pending'  => __( 'Pending',   'nota-backup-restore' ),
            );
            $status_text   = $status_labels[ $latest->status ] ?? esc_html( $latest->status );
        ?>
        <div class="wpbn-dw-row">
         <span class="wpbn-dw-label"><?php esc_html_e( 'Last backup', 'nota-backup-restore' ); ?></span>
         <span class="wpbn-dw-val"><?php echo esc_html( get_date_from_gmt( $latest->created_at, 'd.m.Y H:i' ) ); ?></span>
        </div>
        <div class="wpbn-dw-row">
         <span class="wpbn-dw-label"><?php esc_html_e( 'Status', 'nota-backup-restore' ); ?></span>
         <span class="wpbn-dw-badge" style="background:<?php echo esc_attr( $status_color ); ?>"><?php echo esc_html( $status_text ); ?></span>
        </div>
        <div class="wpbn-dw-row">
         <span class="wpbn-dw-label"><?php esc_html_e( 'Size', 'nota-backup-restore' ); ?></span>
         <span class="wpbn-dw-val"><?php echo $latest->filesize ? esc_html( wpbn_size_format( $latest->filesize ) ) : '&mdash;'; ?></span>
        </div>
        <?php if ( $latest->duration ): ?>
        <div class="wpbn-dw-row">
         <span class="wpbn-dw-label"><?php esc_html_e( 'Duration', 'nota-backup-restore' ); ?></span>
         <span class="wpbn-dw-val"><?php echo $latest->duration < 60 ? esc_html( $latest->duration . 's' ) : esc_html( floor( $latest->duration / 60 ) . 'm ' . ( $latest->duration % 60 ) . 's' ); ?></span>
        </div>
        <?php endif; ?>
        <?php endif; ?>


        <div class="wpbn-dw-actions">
         <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-backup-nota' ) ); ?>" class="button button-primary button-small"><?php esc_html_e( '+ New Backup', 'nota-backup-restore' ); ?></a>
         <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-backup-nota-history' ) ); ?>" class="button button-small"><?php esc_html_e( 'History', 'nota-backup-restore' ); ?></a>
        </div>
        <span id="wpbn-dw-run-msg" style="font-size:.8rem;display:block;margin-top:6px;"></span>
        <?php
    }
}

/**
 * Renders a premium feature gate card inside a settings tab.
 *
 * @param string $feature_name  Short feature name
 * @param string $description   One-line benefit description
 * @param string $upgrade_url   URL to the pricing/upgrade page
 */
function wpbn_render_premium_gate( string $feature_name, string $description, string $upgrade_url, string $bg_image = '' ): void {
    ?>
    <div class="card mt-0" style="position:relative;overflow:hidden;">
     <?php if ( $bg_image ) : ?>
     <img src="<?php echo esc_url( WPBN_PLUGIN_URL . 'assets/img/' . $bg_image ); ?>" alt="" style="display:block;width:100%;height:auto;opacity:.25;pointer-events:none;user-select:none;">
     <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 32px;text-align:center;">
     <?php else : ?>
     <div class="card-body" style="padding:40px 32px;text-align:center;">
     <?php endif; ?>
      <div style="font-size:2.5rem;margin-bottom:12px;">&#x1F512;</div>
      <h5 style="margin-bottom:8px;"><?php echo esc_html( $feature_name ); ?></h5>
      <p class="text-muted" style="font-size:.9rem;max-width:480px;margin:0 auto 24px;"><?php echo esc_html( $description ); ?></p>
      <a href="<?php echo esc_url( $upgrade_url ); ?>" class="button button-primary" target="_blank" rel="noopener" style="font-size:.95rem;padding:8px 28px;height:auto;line-height:1.6;">
       <?php esc_html_e( 'Upgrade to Pro', 'nota-backup-restore' ); ?> &#x2192;
      </a>
      <p style="margin-top:16px;font-size:.8rem;color:#1d2327;"><?php echo wp_kses_post( __( '14-day free trial &nbsp;&middot;&nbsp; No credit card required &nbsp;&mdash;&nbsp; All plans include all features', 'nota-backup-restore' ) ); ?></p>
     </div>
    </div>
    <?php
}
