<?php
// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in check_permissions() via check_ajax_referer() before any $_POST access
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is always $wpdb->prefix.'wpbn_backups', never user input
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log -- error_log used intentionally for backup diagnostics
if ( ! defined( 'ABSPATH' ) ) exit;

class WPBN_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_wpbn_start_backup',    array( $this, 'start_backup' ) );
        add_action( 'wp_ajax_wpbn_run_backup_bg',   array( $this, 'run_backup_bg' ) );
        add_action( 'wp_ajax_wpbn_backup_status',   array( $this, 'backup_status' ) );
        add_action( 'wp_ajax_wpbn_delete_backup',   array( $this, 'delete_backup' ) );
        add_action( 'wp_ajax_wpbn_save_settings',   array( $this, 'save_settings' ) );
        add_action( 'wp_ajax_wpbn_get_backups',     array( $this, 'get_backups' ) );
        add_action( 'wp_ajax_wpbn_download_backup',     array( $this, 'download_backup' ) );
        add_action( 'wp_ajax_wpbn_download_installer', array( $this, 'download_installer' ) );
        add_action( 'wp_ajax_wpbn_list_dir',        array( $this, 'list_dir' ) );
        add_action( 'wp_ajax_wpbn_cleanup_orphans', array( $this, 'cleanup_orphans' ) );
        add_action( 'wp_ajax_wpbn_register_backup', array( $this, 'register_backup' ) );
        add_action( 'wp_ajax_wpbn_size_estimate',   array( $this, 'size_estimate' ) );
        add_action( 'wp_ajax_wpbn_db_health',       array( $this, 'db_health' ) );
        add_action( 'wp_ajax_wpbn_chunk_recommend',      array( $this, 'chunk_recommend' ) );
        add_action( 'wp_ajax_wpbn_save_log_retention',   array( $this, 'save_log_retention' ) );
        add_action( 'wp_ajax_wpbn_remove_encryption',    array( $this, 'remove_encryption' ) );
        add_action( 'wp_ajax_wpbn_site_changes',         array( $this, 'site_changes' ) );
    }

    private function check_permissions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nota-backup-restore' ) ) );
        }
        if ( ! check_ajax_referer( 'wpbn_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'nota-backup-restore' ) ) );
        }
    }

    public function start_backup() {
        $this->check_permissions();

        $existing = get_option( WPBN_Backup::STATE_OPTION );
        if ( $existing ) {
            $hb = isset( $existing['last_heartbeat'] ) ? (int) $existing['last_heartbeat'] : 0;
            if ( $hb > 0 && ( time() - $hb ) < 600 ) {
                wp_send_json_error( array( 'error' => 'A backup is already in progress. Please wait for it to finish.' ) );
                return;
            }
            delete_option( WPBN_Backup::STATE_OPTION );
        }

        $result = WPBN_Backup::step_init( array(
            'notes'       => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
            'backup_type' => 'manual',
        ) );

        if ( $result['success'] ) {
            wp_schedule_single_event( time() + 5, 'wpbn_process_next_chunk' );
            spawn_cron();
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function run_backup_bg() {
        $this->check_permissions();

        @set_time_limit( 60 );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- inside AJAX handler, extends per-request limit for background backup launch
        @ini_set( 'memory_limit', '512M' );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- inside AJAX handler, raises limit only for this backup step

        register_shutdown_function( function() {
            $err = error_get_last();
            if ( ! $err ) return;
            $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
            if ( ! in_array( $err['type'], $fatal_types, true ) ) return;
            $state = get_option( WPBN_Backup::STATE_OPTION );
            if ( ! $state ) return;
            $step = $state['current_step'] ?? 'unknown';
            $msg  = "PHP fatal error at step '{$step}': {$err['message']} in {$err['file']} on line {$err['line']}";
            $state['last_error'] = $msg;
            update_option( WPBN_Backup::STATE_OPTION, $state, false );
            error_log( 'WP Backup Nota: ' . $msg );
        } );

        $state = get_option( WPBN_Backup::STATE_OPTION );
        if ( ! $state ) {
            wp_send_json_error( array( 'error' => 'No backup in progress.' ) );
            return;
        }

        $step = $state['current_step'] ?? 'zip_files';

        if ( $step === 'zip_files' ) {
            $start_time  = microtime( true );
            $max_seconds = 20;
            $iterations  = 0;

            do {
                $r = WPBN_Backup::step_zip_files();
                if ( ! $r['success'] ) {
                    error_log( 'WP Backup Nota: ZIP failed at iteration ' . $iterations . ' — ' . ( $r['error'] ?? 'unknown' ) );
                    wpbn_cleanup_failed_state( "ZIP error at step 'zip_files': " . ( $r['error'] ?? 'unknown' ) );
                    wp_send_json_error( array( 'error' => $r['error'] ?? 'ZIP failed' ) );
                    return;
                }
                $iterations++;
                gc_collect_cycles();
                $elapsed = microtime( true ) - $start_time;
            } while ( $r['next'] === 'zip_files' && $elapsed < $max_seconds );

            if ( $r['next'] === 'zip_files' ) {
                wp_clear_scheduled_hook( 'wpbn_process_next_chunk' );
                wp_schedule_single_event( time() + 30, 'wpbn_process_next_chunk' );
                wp_send_json_success( array(
                    'status'  => 'zipping',
                    'offset'  => $r['offset']  ?? 0,
                    'total'   => $r['total']   ?? 0,
                    'percent' => $r['percent'] ?? 0,
                ) );
            } else {
                wp_clear_scheduled_hook( 'wpbn_process_next_chunk' );
                $r = WPBN_Backup::step_finalize();
                if ( ! empty( $r['success'] ) ) {
                    set_transient( 'wpbn_last_backup_result', $r, 300 );
                    error_log( 'WP Backup Nota: Backup completed successfully.' );
                    wp_send_json_success( array( 'status' => 'done', 'result' => $r ) );
                } else {
                    error_log( 'WP Backup Nota: Finalize failed — ' . ( $r['error'] ?? 'unknown' ) );
                    wpbn_cleanup_failed_state( "Finalize error: " . ( $r['error'] ?? 'unknown' ) );
                    wp_send_json_error( array( 'error' => $r['error'] ?? 'Finalize failed' ) );
                }
            }
        } else {
            wp_clear_scheduled_hook( 'wpbn_process_next_chunk' );
            $r = WPBN_Backup::step_finalize();
            if ( ! empty( $r['success'] ) ) {
                set_transient( 'wpbn_last_backup_result', $r, 300 );
                wp_send_json_success( array( 'status' => 'done', 'result' => $r ) );
            } else {
                wpbn_cleanup_failed_state( "Finalize error: " . ( $r['error'] ?? 'unknown' ) );
                wp_send_json_error( array( 'error' => $r['error'] ?? 'Finalize failed' ) );
            }
        }
    }

    public function backup_status() {
        $this->check_permissions();

        $state = get_option( WPBN_Backup::STATE_OPTION );
        if ( $state ) {
            $hb              = isset( $state['last_heartbeat'] ) ? (int) $state['last_heartbeat'] : 0;
            $stale_threshold = 600;
            $stale           = $hb > 0 && ( time() - $hb ) > $stale_threshold;

            if ( $stale ) {
                $step      = $state['current_step'] ?? 'unknown';
                $last_seen = $hb ? human_time_diff( $hb ) . ' ago' : 'unknown';
                $error_msg = ! empty( $state['last_error'] )
                    ? $state['last_error']
                    : "Backup stopped unexpectedly at step '{$step}'. Last heartbeat: {$last_seen}.";
                wpbn_cleanup_failed_state( $error_msg );
                wp_send_json_success( array( 'running' => false, 'finished' => false, 'stale' => true, 'error' => $error_msg ) );
                return;
            }

            $total   = isset( $state['file_count'] ) ? (int) $state['file_count'] : 0;
            $offset  = (int) ( $state['offset'] ?? 0 );
            $percent = $total > 0 ? round( $offset / $total * 100, 1 ) : 0;
            wp_send_json_success( array(
                'running'  => true,
                'offset'   => $offset,
                'total'    => $total,
                'percent'  => $percent,
                'step'     => $state['current_step'] ?? 'zip_files',
            ) );
        }

        $last_result = get_transient( 'wpbn_last_backup_result' );
        if ( $last_result ) {
            delete_transient( 'wpbn_last_backup_result' );
            wp_send_json_success( array( 'running' => false, 'finished' => true, 'result' => $last_result ) );
        }

        wp_send_json_success( array( 'running' => false, 'finished' => false ) );
    }

    public function delete_backup() {
        $this->check_permissions();
        $id = absint( wp_unslash( $_POST['backup_id'] ?? 0 ) );
        if ( WPBN_Backup::delete_backup( $id ) ) {
            wp_send_json_success( array( 'message' => 'Backup deleted.' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Could not delete backup.' ) );
        }
    }

    public function save_settings() {
        $this->check_permissions();

        $allowed = array(
            'max_backups',
            'chunk_size_mb',
            'files_per_chunk_override',
        );

        $data = array();
        foreach ( $allowed as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                $data[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
            }
        }

        if ( isset( $_POST['exclude_paths'] ) ) {
            $lines = explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['exclude_paths'] ) ) );
            $data['exclude_paths'] = array_filter( array_map( 'trim', $lines ) );
        }

        $known_presets = array_keys( WPBN_Backup::known_cache_dirs() );
        $raw_presets   = isset( $_POST['excluded_cache_presets'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['excluded_cache_presets'] ) ) : array();
        $data['excluded_cache_presets'] = array_values( array_intersect( $raw_presets, $known_presets ) );

        $data['exclude_root_ini'] = isset( $_POST['exclude_root_ini'] ) && $_POST['exclude_root_ini'] === '1' ? '1' : '0';

        // Encryption
        $data['encryption_enabled'] = isset( $_POST['encryption_enabled'] ) && $_POST['encryption_enabled'] === '1' ? '1' : '0';
        if ( isset( $_POST['encryption_password'] ) && $_POST['encryption_password'] !== '' ) {
            $plain = sanitize_text_field( wp_unslash( $_POST['encryption_password'] ) );
            if ( extension_loaded( 'openssl' ) ) {
                $key = substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true ), 0, 32 );
                $iv  = random_bytes( 16 );
                $enc = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
                $data['encryption_password'] = base64_encode( $iv . $enc );
            }
        }

        if ( isset( $data['chunk_size_mb'] ) ) {
            $data['chunk_size_mb'] = max( 2, min( 50, (int) $data['chunk_size_mb'] ) );
        }
        if ( isset( $data['files_per_chunk_override'] ) ) {
            $val = (int) $data['files_per_chunk_override'];
            $data['files_per_chunk_override'] = ( $val > 0 ) ? max( 10, min( 2000, $val ) ) : 0;
        }

        WPBN_Settings::update( $data );
        wp_send_json_success( array( 'message' => 'Settings saved.' ) );
    }

    public function remove_encryption() {
        $this->check_permissions();
        WPBN_Settings::update( array(
            'encryption_enabled'  => '0',
            'encryption_password' => '',
        ) );
        wp_send_json_success( array( 'message' => 'Encryption removed.' ) );
    }

    public function download_backup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'nota-backup-restore' ), 403 );
        }
        if ( ! check_ajax_referer( 'wpbn_nonce', 'nonce', false ) ) {
            wp_die( esc_html__( 'Security check failed.', 'nota-backup-restore' ), 403 );
        }

        $id = absint( wp_unslash( $_GET['backup_id'] ?? 0 ) );
        if ( ! $id ) wp_die( esc_html__( 'Invalid backup ID.', 'nota-backup-restore' ), 400 );

        global $wpdb;
        $table = $wpdb->prefix . 'wpbn_backups';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

        if ( ! $row ) wp_die( esc_html__( 'Backup not found.', 'nota-backup-restore' ), 404 );

        $file = WPBN_BACKUP_DIR . '/' . basename( $row->filename );
        if ( ! file_exists( $file ) ) wp_die( esc_html__( 'Backup file not found on disk.', 'nota-backup-restore' ), 404 );

        $filename = basename( $file );
        $filesize = filesize( $file );

        while ( ob_get_level() ) ob_end_clean();

        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Content-Length: ' . $filesize );
        header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
        header( 'Pragma: public' );
        header( 'Expires: 0' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming large ZIP; WP_Filesystem has no streaming API
        readfile( $file );
        exit;
    }

    public function download_installer() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'nota-backup-restore' ), 403 );
        }
        if ( ! check_ajax_referer( 'wpbn_nonce', 'nonce', false ) ) {
            wp_die( esc_html__( 'Security check failed.', 'nota-backup-restore' ), 403 );
        }

        $id = absint( wp_unslash( $_GET['backup_id'] ?? 0 ) );
        if ( ! $id ) wp_die( esc_html__( 'Invalid backup ID.', 'nota-backup-restore' ), 400 );

        global $wpdb;
        $table = $wpdb->prefix . 'wpbn_backups';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        if ( ! $row ) wp_die( esc_html__( 'Backup not found.', 'nota-backup-restore' ), 404 );

        $tpl = WPBN_PLUGIN_DIR . 'includes/installer-template.tpl';
        if ( ! file_exists( $tpl ) ) wp_die( esc_html__( 'Installer template not found.', 'nota-backup-restore' ), 404 );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local plugin file
        $content = file_get_contents( $tpl );
        $content = str_replace( '@@ZIP_FILE@@', $row->filename, $content );

        $name = 'installer_' . pathinfo( $row->filename, PATHINFO_FILENAME ) . '.php';

        while ( ob_get_level() ) ob_end_clean();
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        header( 'Content-Length: ' . strlen( $content ) );
        header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
        header( 'Pragma: public' );
        header( 'Expires: 0' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw PHP file output for download
        echo $content;
        exit;
    }

    public function get_backups() {
        $this->check_permissions();
        $backups = WPBN_Backup::get_backups();
        foreach ( $backups as $b ) {
            $b->local_exists = file_exists( WPBN_BACKUP_DIR . '/' . basename( $b->filename ) );
        }
        wp_send_json_success( $backups );
    }

    public function cleanup_orphans() {
        $this->check_permissions();

        $dir     = WPBN_BACKUP_DIR;
        $removed = array();
        $errors  = array();

        if ( ! is_dir( $dir ) ) {
            wp_send_json_error( array( 'message' => 'Backup folder not found.' ) );
        }

        $active_state   = get_option( WPBN_Backup::STATE_OPTION );
        $active_tmp_dir = $active_state ? ( $active_state['tmp_dir'] ?? '' ) : '';

        $handle = @opendir( $dir );
        if ( ! $handle ) {
            wp_send_json_error( array( 'message' => 'Could not open folder.' ) );
        }

        while ( ( $entry = readdir( $handle ) ) !== false ) {
            if ( $entry === '.' || $entry === '..' ) continue;
            $full = $dir . '/' . $entry;

            if ( strpos( $entry, 'tmp_' ) === 0 && is_dir( $full ) ) {
                if ( $active_tmp_dir && rtrim( $full, '/' ) === rtrim( $active_tmp_dir, '/' ) ) {
                    continue;
                }
                WPBN_Backup::rmdir_recursive_public( $full );
                $removed[] = $entry;
                continue;
            }

            if ( is_file( $full ) && preg_match( '/^[a-zA-Z0-9]{5,8}$/', $entry ) ) {
                wp_delete_file( $full );
                if ( ! file_exists( $full ) ) {
                    $removed[] = $entry;
                } else {
                    $errors[] = $entry;
                }
            }
        }
        closedir( $handle );

        wp_send_json_success( array(
            'removed' => $removed,
            'count'   => count( $removed ),
            'errors'  => $errors,
        ) );
    }

    public function list_dir() {
        $this->check_permissions();

        $requested = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
        $base      = rtrim( ABSPATH, '/' ); // Security boundary — prevents path traversal outside the WordPress installation root

        if ( $requested === '' || $requested === '/' ) {
            $dir = $base;
        } else {
            $real = realpath( $requested );
            if ( ! $real || strpos( $real, $base ) !== 0 ) {
                wp_send_json_error( array( 'message' => 'Invalid path.' ) );
                return;
            }
            $dir = $real;
        }

        if ( ! is_dir( $dir ) ) {
            wp_send_json_error( array( 'message' => 'Directory not found.' ) );
            return;
        }

        $items = array();
        $scan  = @scandir( $dir );
        if ( ! $scan ) {
            wp_send_json_success( array( 'items' => array(), 'path' => $dir ) );
            return;
        }

        foreach ( $scan as $entry ) {
            if ( $entry === '.' || $entry === '..' ) continue;
            $full = $dir . '/' . $entry;
            if ( ! is_dir( $full ) ) continue;
            if ( strpos( $entry, '.' ) === 0 ) continue;

            $items[] = array(
                'name'         => $entry,
                'path'         => $full,
                'rel'          => ltrim( str_replace( $base, '', $full ), '/' ),
                'has_children' => self::dir_has_subdirs( $full ),
            );
        }

        usort( $items, function( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
        wp_send_json_success( array( 'items' => $items, 'path' => $dir, 'base' => $base ) );
    }

    private static function dir_has_subdirs( $dir ) {
        $scan = @scandir( $dir );
        if ( ! $scan ) return false;
        foreach ( $scan as $entry ) {
            if ( $entry === '.' || $entry === '..' ) continue;
            if ( strpos( $entry, '.' ) === 0 ) continue;
            if ( is_dir( $dir . '/' . $entry ) ) return true;
        }
        return false;
    }


    public function register_backup() {
        $this->check_permissions();
        check_ajax_referer( 'wpbn_nonce', 'nonce' );

        $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
        $filesize = absint( wp_unslash( $_POST['filesize'] ?? 0 ) );
        $created  = sanitize_text_field( wp_unslash( $_POST['created'] ?? '' ) );

        if ( ! $filename || ! preg_match( '/\.zip$/i', $filename ) ) {
            wp_send_json_error( array( 'message' => 'Invalid filename.' ) );
        }

        $full_path = WPBN_BACKUP_DIR . '/' . basename( $filename );
        if ( ! file_exists( $full_path ) ) {
            wp_send_json_error( array( 'message' => 'File not found on server.' ) );
        }

        $real_size = filesize( $full_path );
        if ( $real_size > 0 ) $filesize = $real_size;

        if ( ! $created || strtotime( $created ) === false ) {
            $created = gmdate( 'Y-m-d H:i:s', filemtime( $full_path ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpbn_backups';

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE filename = %s LIMIT 1", $filename ) );
        if ( $existing ) {
            wp_send_json_success( array( 'id' => (int) $existing, 'already' => true ) );
        }

        $wpdb->insert( $table, array(
            'filename'    => $filename,
            'filesize'    => $filesize,
            'backup_type' => 'full',
            'status'      => 'complete',
            'duration'    => null,
            'notes'       => 'Manual registration',
            'created_at'  => $created,
        ), array( '%s', '%d', '%s', '%s', '%d', '%s', '%s' ) );

        if ( ! $wpdb->insert_id ) {
            wp_send_json_error( array( 'message' => 'Failed to save to database.' ) );
        }

        wp_send_json_success( array( 'id' => (int) $wpdb->insert_id ) );
    }

    public function size_estimate() {
        $this->check_permissions();

        global $wpdb;

        // DB size from information_schema
        $db_size = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = %s',
                DB_NAME
            )
        );

        // Build exclusion list (user exclusions + backup dir + plugin vendor)
        $user_excludes = array_filter( array_map( 'trim', (array) WPBN_Settings::get( 'exclude_paths' ) ) );
        $exclude = array_filter( array_map( 'realpath', array_merge(
            $user_excludes,
            array( WPBN_BACKUP_DIR, WPBN_PLUGIN_DIR . 'vendor' )
        ) ) );

        $file_size = 0;
        $start     = microtime( true );

        try {
            $dir_iter = new RecursiveDirectoryIterator( ABSPATH, RecursiveDirectoryIterator::SKIP_DOTS ); // ABSPATH is the WordPress root — scanning all WP files to estimate backup size
            $filtered = new RecursiveCallbackFilterIterator(
                $dir_iter,
                function ( $item ) use ( $exclude ) {
                    if ( $item->isDir() ) {
                        $real = realpath( $item->getPathname() );
                        foreach ( $exclude as $ex ) {
                            if ( $real === $ex || strpos( $real . DIRECTORY_SEPARATOR, $ex . DIRECTORY_SEPARATOR ) === 0 ) {
                                return false;
                            }
                        }
                    }
                    return true;
                }
            );
            $iter = new RecursiveIteratorIterator( $filtered, RecursiveIteratorIterator::LEAVES_ONLY );
            foreach ( $iter as $item ) {
                if ( microtime( true ) - $start > 20 ) {
                    break;
                }
                if ( $item->isFile() ) {
                    $file_size += $item->getSize();
                }
            }
        } catch ( \Exception $e ) {
            // partial result is acceptable
        }

        $raw_total     = $db_size + $file_size;
        $estimated_zip = (int) ( $db_size * 0.4 + $file_size * 0.65 );

        wp_send_json_success( array(
            'db_size'          => $db_size,
            'file_size'        => $file_size,
            'raw_total'        => $raw_total,
            'estimated_zip'    => $estimated_zip,
            'db_size_hr'       => wpbn_size_format( $db_size ),
            'file_size_hr'     => wpbn_size_format( $file_size ),
            'raw_total_hr'     => wpbn_size_format( $raw_total ),
            'estimated_zip_hr' => wpbn_size_format( $estimated_zip ),
        ) );
    }

    public function db_health() {
        $this->check_permissions();

        global $wpdb;
        $tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );
        $broken = array();

        foreach ( $tables as $table ) {
            $rows = $wpdb->get_results( "CHECK TABLE `{$table}`" );
            foreach ( $rows as $row ) {
                if ( isset( $row->Msg_type ) && ! in_array( $row->Msg_type, array( 'status', 'note' ), true ) ) {
                    $repaired = false;
                    $wpdb->query( "REPAIR TABLE `{$table}`" );
                    $recheck = $wpdb->get_results( "CHECK TABLE `{$table}`" );
                    foreach ( $recheck as $rc ) {
                        if ( isset( $rc->Msg_type ) && $rc->Msg_type === 'status' && $rc->Msg_text === 'OK' ) {
                            $repaired = true;
                        }
                    }
                    $broken[] = array(
                        'table'    => $table,
                        'message'  => $row->Msg_text ?? 'Unknown error',
                        'repaired' => $repaired,
                    );
                    break;
                }
            }
        }

        wp_send_json_success( array(
            'ok'     => empty( $broken ),
            'broken' => $broken,
        ) );
    }

    public function chunk_recommend() {
        $this->check_permissions();
        $recommended = wpbn_calculate_optimal_chunk_mb();
        WPBN_Settings::update( array( 'chunk_size_mb_auto' => $recommended ) );
        wp_send_json_success( array( 'recommended' => $recommended ) );
    }

    public function save_log_retention() {
        $this->check_permissions();
        $keep = max( 1, min( 100, absint( wp_unslash( $_POST['keep'] ?? 20 ) ) ) );
        WPBN_Settings::update( array( 'log_retention_backups' => $keep ) );
        WPBN_Logger::prune( $keep );
        wp_send_json_success( array( 'message' => __( 'Saved.', 'nota-backup-restore' ) ) );
    }

    public function site_changes() {
        $this->check_permissions();

        global $wpdb;
        $table = $wpdb->prefix . 'wpbn_backups';

        $last = $wpdb->get_row(
            "SELECT created_at FROM {$table} WHERE status NOT IN ('failed','pending','uploading') ORDER BY id DESC LIMIT 1"
        );

        if ( ! $last ) {
            wp_send_json_success( array( 'no_backup' => true ) );
            return;
        }

        $last_time = strtotime( $last->created_at . ' +0000' );
        $ago_days  = (int) floor( ( time() - $last_time ) / DAY_IN_SECONDS );

        $changes = array();

        // WordPress core update
        $wp_current = get_bloginfo( 'version' );
        $wp_at_backup = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT wp_version FROM {$table} WHERE status NOT IN ('failed','pending','uploading') ORDER BY id DESC LIMIT 1"
            )
        );
        if ( $wp_at_backup && version_compare( $wp_current, $wp_at_backup, '>' ) ) {
            $changes[] = array( 'type' => 'wp', 'detail' => $wp_at_backup . ' → ' . $wp_current );
        }

        // Plugin changes (mtime on plugin dirs)
        $plugin_changed = false;
        if ( is_dir( WP_PLUGIN_DIR ) ) {
            $scan = @scandir( WP_PLUGIN_DIR );
            foreach ( (array) $scan as $entry ) {
                if ( $entry === '.' || $entry === '..' ) continue;
                $path = WP_PLUGIN_DIR . '/' . $entry;
                if ( is_dir( $path ) && @filemtime( $path ) > $last_time ) {
                    $plugin_changed = true;
                    break;
                }
            }
        }
        if ( $plugin_changed ) {
            $changes[] = array( 'type' => 'plugins' );
        }

        // Theme changes
        $theme_changed = false;
        $theme_root = get_theme_root();
        if ( is_dir( $theme_root ) ) {
            $scan = @scandir( $theme_root );
            foreach ( (array) $scan as $entry ) {
                if ( $entry === '.' || $entry === '..' ) continue;
                $path = $theme_root . '/' . $entry;
                if ( is_dir( $path ) && @filemtime( $path ) > $last_time ) {
                    $theme_changed = true;
                    break;
                }
            }
        }
        if ( $theme_changed ) {
            $changes[] = array( 'type' => 'themes' );
        }

        // Upload changes — scan 2-level deep (year/month dirs)
        $uploads     = wp_upload_dir();
        $up_base     = $uploads['basedir'];
        $up_changed  = false;
        if ( is_dir( $up_base ) ) {
            foreach ( (array) @scandir( $up_base ) as $year ) {
                if ( ! is_numeric( $year ) ) continue;
                $year_dir = $up_base . '/' . $year;
                if ( ! is_dir( $year_dir ) ) continue;
                foreach ( (array) @scandir( $year_dir ) as $month ) {
                    if ( $month === '.' || $month === '..' ) continue;
                    $month_dir = $year_dir . '/' . $month;
                    if ( is_dir( $month_dir ) && @filemtime( $month_dir ) > $last_time ) {
                        $up_changed = true;
                        break 2;
                    }
                }
            }
        }
        if ( $up_changed ) {
            $changes[] = array( 'type' => 'uploads' );
        }

        wp_send_json_success( array(
            'no_backup'  => false,
            'last_date'  => get_date_from_gmt( $last->created_at, 'Y-m-d H:i' ),
            'ago_days'   => $ago_days,
            'changes'    => $changes,
            'has_changes' => ! empty( $changes ),
        ) );
    }
}
