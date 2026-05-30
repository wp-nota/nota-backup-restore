<?php
/**
 * Plugin Name: Nota Backup & Restore
 * Plugin URI:  https://www.wp-nota.com
 * Description: Full site backup plugin. Backup files + database with one click. Cloud storage and restore features available in the premium version.
 * Version:     2.1.5
 * Author:      wp-nota.com
 * Author URI:  https://wp-nota.com
 * License:     GPL-2.0+
 * Text Domain: nota-backup-restore
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.SchemaChange -- $table is always $wpdb->prefix.'wpbn_backups', never user input; schema migrations cannot use prepare()
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log -- error_log used intentionally for backup operation diagnostics
if ( ! defined( 'WPBN_VERSION' ) )     define( 'WPBN_VERSION',     '2.1.5' );
if ( ! defined( 'WPBN_PLUGIN_DIR' ) )  define( 'WPBN_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
if ( ! defined( 'WPBN_PLUGIN_URL' ) )  define( 'WPBN_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
if ( ! defined( 'WPBN_PLUGIN_FILE' ) ) define( 'WPBN_PLUGIN_FILE', __FILE__ );
if ( ! defined( 'WPBN_BACKUP_DIR' ) ) {
    $wpbn_upload = wp_upload_dir();
    define( 'WPBN_BACKUP_DIR', $wpbn_upload['basedir'] . '/nota-backup-restore' );
    define( 'WPBN_BACKUP_URL', $wpbn_upload['baseurl'] . '/nota-backup-restore' );
    unset( $wpbn_upload );
}
if ( ! defined( 'WPBN_CHUNK_SIZE' ) )  define( 'WPBN_CHUNK_SIZE',  5 * 1024 * 1024 );

function wpbn_upgrade_url(): string {
    return 'https://www.wp-nota.com/pricing/';
}

require_once WPBN_PLUGIN_DIR . 'includes/class-wpbn-activator.php';
require_once WPBN_PLUGIN_DIR . 'includes/class-wpbn-logger.php';
require_once WPBN_PLUGIN_DIR . 'includes/class-wpbn-settings.php';
require_once WPBN_PLUGIN_DIR . 'includes/class-wpbn-backup.php';
require_once WPBN_PLUGIN_DIR . 'includes/class-wpbn-database.php';
require_once WPBN_PLUGIN_DIR . 'includes/class-wpbn-ajax.php';
require_once WPBN_PLUGIN_DIR . 'admin/class-wpbn-admin.php';

register_activation_hook( __FILE__,   array( 'WPBN_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPBN_Activator', 'deactivate' ) );

// ── Size formatting helper ────────────────────────────────────────────────────
if ( ! function_exists( 'wpbn_size_format' ) ) :
function wpbn_size_format( $bytes ) {
    $bytes = (float) $bytes;
    if ( $bytes >= GB_IN_BYTES ) {
        return number_format( $bytes / GB_IN_BYTES, 2 ) . ' GB';
    }
    return size_format( $bytes );
}
endif;

// ── Smart chunk size calculation ─────────────────────────────────────────────
if ( ! function_exists( 'wpbn_calculate_optimal_chunk_mb' ) ) :
function wpbn_calculate_optimal_chunk_mb(): int {
    $limit_str = ini_get( 'memory_limit' );
    $limit_bytes = ( $limit_str === '-1' )
        ? 256 * 1024 * 1024
        : wp_convert_hr_to_bytes( $limit_str );
    $free_bytes  = max( 0, $limit_bytes - memory_get_usage( true ) );
    $chunk_mb    = (int) ( $free_bytes * 0.20 / ( 1024 * 1024 ) );
    return max( 2, min( 50, $chunk_mb ) );
}
endif;

add_action( 'admin_init', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $settings = WPBN_Settings::get();
    if ( empty( $settings['chunk_size_mb_auto'] ) ) {
        WPBN_Settings::update( array( 'chunk_size_mb_auto' => wpbn_calculate_optimal_chunk_mb() ) );
    }
} );

// ── Cron hooks ───────────────────────────────────────────────────────────────
add_action( 'wpbn_process_next_chunk', 'wpbn_cron_process_chunk' );

function wpbn_run_timed_zip_loop( $max_seconds = 20 ) {
    $start     = microtime( true );
    $iteration = 0;

    do {
        $r = WPBN_Backup::step_zip_files();
        if ( ! $r['success'] ) {
            error_log( 'WP Backup Nota: ZIP failed at iteration ' . $iteration . ' — ' . ( $r['error'] ?? 'unknown' ) );
            wpbn_cleanup_failed_state( "ZIP error at step 'zip_files': " . ( $r['error'] ?? 'unknown' ) );
            return 'failed';
        }
        $iteration++;
        if ( $r['next'] === 'zip_files' ) {
            gc_collect_cycles();
            @set_time_limit( 60 );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- resets per-request limit per chunk iteration inside wpbn_run_timed_zip_loop()
        }
    } while ( $r['next'] === 'zip_files' && ( microtime( true ) - $start ) < $max_seconds );

    return $r['next'] === 'zip_files' ? 'more' : 'done';
}

function wpbn_cron_process_chunk() {
    ignore_user_abort( true );
    @set_time_limit( 60 );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- inside cron callback, extends per-execution limit for ZIP chunk processing
    @ini_set( 'memory_limit', '512M' );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- inside cron callback, raises limit only for this backup step

    $state = get_option( WPBN_Backup::STATE_OPTION );
    if ( ! $state ) return;

    $hb       = isset( $state['last_heartbeat'] ) ? (int) $state['last_heartbeat'] : 0;
    if ( $hb > 0 && ( time() - $hb ) < 30 ) {
        wp_schedule_single_event( time() + 30, 'wpbn_process_next_chunk' );
        return;
    }

    $state['last_heartbeat'] = time();
    update_option( WPBN_Backup::STATE_OPTION, $state, false );

    $step = $state['current_step'] ?? 'zip_files';

    if ( $step === 'zip_files' ) {
        $result = wpbn_run_timed_zip_loop( 20 );
        if ( $result === 'failed' ) return;

        if ( $result === 'more' ) {
            wp_schedule_single_event( time(), 'wpbn_process_next_chunk' );
            spawn_cron();
            return;
        }
        $step = 'finalize';
    }

    if ( $step === 'finalize' ) {
        error_log( 'WP Backup Nota: Cron chain — ZipArchive complete. Finalizing.' );
        $r = WPBN_Backup::step_finalize();
        if ( empty( $r['success'] ) ) {
            error_log( 'WP Backup Nota: Finalize failed — ' . ( $r['error'] ?? 'unknown' ) );
            wpbn_cleanup_failed_state( "Finalize error: " . ( $r['error'] ?? 'unknown' ) );
            return;
        }
        set_transient( 'wpbn_last_backup_result', $r, 300 );
        error_log( 'WP Backup Nota: Backup completed successfully via cron chain.' );
    }
}

function wpbn_cleanup_failed_state( string $error_msg = '' ) {
    $fail_state = get_option( WPBN_Backup::STATE_OPTION );
    if ( ! $fail_state ) return;

    if ( ! empty( $fail_state['tmp_dir'] ) && is_dir( $fail_state['tmp_dir'] ) ) {
        WPBN_Backup::rmdir_recursive_public( $fail_state['tmp_dir'] );
    }
    if ( ! empty( $fail_state['zip_path'] ) && file_exists( $fail_state['zip_path'] ) ) {
        wp_delete_file( $fail_state['zip_path'] );
    }

    global $wpdb;
    $table       = $wpdb->prefix . 'wpbn_backups';
    $backup_name = $fail_state['backup_name'] ?? ( 'wpbn_failed_' . gmdate( 'Y-m-d_H-i-s' ) );
    $step        = $fail_state['current_step'] ?? 'unknown';
    $msg         = $error_msg ?: "Backup failed at step '{$step}'.";
    $wpdb->insert( $table, array(
        'filename'    => $backup_name . '.zip',
        'filesize'    => 0,
        'backup_type' => $fail_state['backup_type'] ?? 'manual',
        'status'      => 'failed',
        'duration'    => isset( $fail_state['start_time'] ) ? (int) ( microtime( true ) - $fail_state['start_time'] ) : null,
        'notes'       => $fail_state['notes'] ?? '',
        'error_msg'   => $msg,
        'created_at'  => gmdate( 'Y-m-d H:i:s', (int) ( $fail_state['start_time'] ?? time() ) ),
    ), array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ) );

    if ( class_exists( 'WPBN_Logger' ) ) {
        $failed_backup_id = $wpdb->insert_id ?: null;
        if ( $failed_backup_id ) {
            foreach ( $fail_state['pending_db_logs'] ?? array() as $entry ) {
                $ts = isset( $entry['time'] ) ? gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ) : null;
                WPBN_Logger::log( $entry['msg'], $entry['level'] ?? 'info', (int) $failed_backup_id, $ts );
            }
            WPBN_Logger::log( $msg, 'error', (int) $failed_backup_id );
            WPBN_Logger::prune( (int) WPBN_Settings::get( 'log_retention_backups' ) ?: 20 );
        }
    }

    wp_clear_scheduled_hook( 'wpbn_process_next_chunk' );
    delete_option( WPBN_Backup::STATE_OPTION );
    error_log( 'WP Backup Nota: Cleaned up failed backup state. Reason: ' . $msg );
}

function wpbn_init() {
    if ( is_admin() ) {
        new WPBN_Admin();
    }
    new WPBN_Ajax();
    wpbn_maybe_create_logs_table();
}
add_action( 'plugins_loaded', 'wpbn_init' );

function wpbn_maybe_create_logs_table() {
    global $wpdb;
    $logs_table = $wpdb->prefix . 'wpbn_logs';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$logs_table}'" ) !== $logs_table ) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql_logs = "CREATE TABLE IF NOT EXISTS {$logs_table} (
            id        BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            backup_id BIGINT(20) UNSIGNED          DEFAULT NULL,
            level     VARCHAR(20)        NOT NULL  DEFAULT 'info',
            message   TEXT               NOT NULL,
            created_at DATETIME          NOT NULL,
            PRIMARY KEY (id),
            KEY backup_id (backup_id),
            KEY created_at (created_at)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_logs );
    }
}
