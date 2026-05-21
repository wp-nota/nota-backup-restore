<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is always $wpdb->prefix.'wpbn_backups', never user input; caching backup records is inappropriate
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log -- error_log used intentionally for backup diagnostics
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink -- wp_delete_file() used where possible; remaining unlink() calls are in error paths where WP may not be fully loaded
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WPBN_Backup
 *
 * Splits the backup into stages. Each stage is called via a separate AJAX request.
 * This avoids hosting timeout limits.
 *
 * Stages:
 *  init      → Create backup folder, name, DB export, write manifest/installer
 *  zip_files → Add files to ZIP in chunks (can be called multiple times)
 *  finalize  → Add DB + installer + manifest to ZIP, save to DB, upload to Drive
 */
class WPBN_Backup {

    const STATE_OPTION = 'wpbn_backup_state';

    // ─────────────────────────────────────────────────────────────────────────
    // STAGED API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Adım 1 — init
     * DB dump alır, manifest/installer'ı geçici dosyaya yazar, state kaydeder.
     */
    public static function step_init( $args = array() ) {
        @set_time_limit( 60 );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- inside AJAX handler, extends per-request limit for large backup init
        @ini_set( 'memory_limit', '512M' );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- inside AJAX handler, raises limit only for this backup step

        $defaults = array(
            'notes'       => '',
            'backup_type' => 'manual',
        );
        $args = wp_parse_args( $args, $defaults );

        if ( ! file_exists( WPBN_BACKUP_DIR ) ) {
            wp_mkdir_p( WPBN_BACKUP_DIR );
        }

        $timestamp     = gmdate( 'Y-m-d_H-i-s' );
        $site_slug     = sanitize_title( wp_parse_url( get_site_url(), PHP_URL_HOST ) );
        $backup_name   = "wpbn_{$site_slug}_{$timestamp}";
        $tmp_dir       = WPBN_BACKUP_DIR . '/tmp_' . $backup_name;
        wp_mkdir_p( $tmp_dir );

        // DB export
        WPBN_Database::export_to_file( $tmp_dir . '/database.sql' );

        // Manifest
        $manifest = array(
            'backup_name'    => $backup_name,
            'site_url'       => get_site_url(),
            'home_url'       => get_home_url(),
            'wp_version'     => get_bloginfo( 'version' ),
            'db_prefix'      => $GLOBALS['wpdb']->prefix,
            'created'        => gmdate( 'Y-m-d H:i:s' ),
            'db_file'        => 'database.sql',
            'old_paths'      => array( rtrim( ABSPATH, '/' ) ), // ABSPATH is the WordPress installation root — no WP API returns this path
            'encrypted'      => false,
        );
        if ( file_put_contents( $tmp_dir . '/wpbn-manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT ) ) === false ) {
            return array( 'success' => false, 'error' => 'Failed to write manifest file. Check backup directory permissions.' );
        }

        // Encryption — handle before ZIP creation so finalize never needs to open the ZIP
        $enc_password = self::resolve_encryption_password();
        if ( $enc_password !== '' && extension_loaded( 'openssl' ) ) {
            $sql_plain = file_get_contents( $tmp_dir . '/database.sql' );
            $key = hash( 'sha256', $enc_password, true );
            $iv  = random_bytes( 16 );
            $enc = openssl_encrypt( $sql_plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
            if ( file_put_contents( $tmp_dir . '/database.sql.enc', $iv . $enc ) === false ) {
                return array( 'success' => false, 'error' => 'Failed to write encrypted database file. Check backup directory permissions.' );
            }
            wp_delete_file( $tmp_dir . '/database.sql' );
            unset( $sql_plain );
            $manifest['encrypted']       = true;
            $manifest['db_file']         = 'database.sql.enc';
            $manifest['encrypted_files'] = array( 'wp-config.php' );
            if ( file_put_contents( $tmp_dir . '/wpbn-manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT ) ) === false ) {
                return array( 'success' => false, 'error' => 'Failed to update manifest after encryption. Check backup directory permissions.' );
            }
        }

        // Create ZIP now (empty) and add meta files immediately — ZIP is tiny so close() is instant.
        // This means step_finalize() never needs to open the large site ZIP.
        $zip_path = WPBN_BACKUP_DIR . '/' . $backup_name . '.zip';
        if ( extension_loaded( 'zip' ) ) {
            $zip = new ZipArchive();
            if ( $zip->open( $zip_path, ZipArchive::CREATE ) === true ) {
                foreach ( array( 'database.sql.enc', 'database.sql', 'wpbn-manifest.json' ) as $f ) {
                    $fp = $tmp_dir . '/' . $f;
                    if ( file_exists( $fp ) ) {
                        $zip->addFile( $fp, $f );
                        if ( $f === 'database.sql' || $f === 'database.sql.enc' ) {
                            $zip->setCompressionName( $f, ZipArchive::CM_STORE );
                        }
                    }
                }
                $zip->addFile( $tmp_dir . '/wpbn-manifest.json', 'config.json' );
                $zip->close();
            }
            unset( $zip );
        }

        $exclude = array_merge(
            (array) WPBN_Settings::get( 'exclude_paths' ),
            array( WPBN_BACKUP_DIR )
        );
        $file_list = self::build_file_list( ABSPATH, $exclude ); // ABSPATH is the WordPress installation root — backing up all WP files requires it
        if ( file_put_contents( $tmp_dir . '/file_list.json', json_encode( $file_list ) ) === false ) {
            return array( 'success' => false, 'error' => 'Failed to write file list. Check backup directory permissions.' );
        }

        $pending_db_logs = array(
            array( 'msg' => 'Backup started — ' . $backup_name, 'level' => 'info' ),
            array( 'msg' => 'DB export complete', 'level' => 'info' ),
            array( 'msg' => 'File list: ' . number_format( count( $file_list ) ) . ' files', 'level' => 'info' ),
        );

        $chunk_mb        = (int) WPBN_Settings::get( 'chunk_size_mb' );
        $chunk_mb        = max( 2, min( $chunk_mb, 50 ) );
        $fpc_override    = (int) WPBN_Settings::get( 'files_per_chunk_override' );
        $files_per_chunk = $fpc_override > 0
            ? max( 10, min( $fpc_override, 2000 ) )
            : max( 50, min( $chunk_mb * 40, 2000 ) );

        // Save state
        $state = array(
            'backup_name'      => $backup_name,
            'zip_path'         => $zip_path,
            'tmp_dir'          => $tmp_dir,
            'file_list'        => $file_list,
            'file_count'       => count( $file_list ),
            'offset'           => 0,
            'chunk_bytes'      => $chunk_mb * 1024 * 1024,
            'files_per_chunk'  => $files_per_chunk,
            'exclude_paths'    => $exclude,
            'notes'       => $args['notes'],
            'backup_type'         => $args['backup_type'],
            'start_time'       => microtime( true ),
            'pending_db_logs'  => $pending_db_logs,
            'last_heartbeat'   => time(),
            'current_step'     => 'zip_files',
        );
        update_option( self::STATE_OPTION, $state, false );

        return array(
            'success'      => true,
            'step'         => 'init',
            'next'         => 'zip_files',
            'file_count'   => $state['file_count'],
            'offset'       => 0,
        );
    }

    /**
     * Adım 2 — zip_files
     *
     *  - ZipArchive is NEVER put through a close()+open() loop.
     *    (close+open re-parses the entire central directory each time → exponential slowdown)
     *    (ZipArchive leaves temp files after close → leftover junk files on server)
     *  - ZIP is opened once, batch completes, closed once.
     *  - Small files (<512KB) added via addFromString() → ~3x faster than addFile()
     *  - Large files added via addFile() (lazy, written to disk at close())
     *  - Each request processes 500 files
     */
    public static function step_zip_files() {
        @set_time_limit( 60 );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- inside AJAX handler, extends per-request limit for ZIP chunk processing
        @ini_set( 'memory_limit', '512M' );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- inside AJAX handler, raises limit only for this backup step

        $state = get_option( self::STATE_OPTION );
        if ( ! $state ) {
            return array( 'success' => false, 'error' => 'Backup state not found. Please restart.' );
        }

        $zip_path      = $state['zip_path'];
        $file_list     = $state['file_list'];
        $offset        = (int) $state['offset'];
        $base_path     = rtrim( ABSPATH, '/' ); // WordPress installation root — used to build relative paths inside the ZIP
        $total         = count( $file_list );
        $str_limit     = 512 * 1024; // 512KB — below this, use addFromString
        $files_per_req = (int) ( $state['files_per_chunk'] ?? 200 );
        $chunk_bytes   = (int) ( $state['chunk_bytes']    ?? 5 * 1024 * 1024 );

        // Calculate memory ceiling: 80% of available memory limit
        $mem_limit_bytes = self::get_memory_limit_bytes();
        $mem_ceiling     = (int) ( $mem_limit_bytes * 0.80 );

        if ( $offset >= $total ) {
            return array(
                'success' => true,
                'step'    => 'zip_files',
                'next'    => 'finalize',
                'offset'  => $offset,
                'total'   => $total,
                'percent' => 100,
            );
        }

        if ( ! extension_loaded( 'zip' ) ) {
            return array( 'success' => false, 'error' => 'ZipArchive PHP extension not found.' );
        }

        // Extensions that are already compressed — storing without re-compression saves CPU
        static $store_exts = array(
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg',
            'mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'm4v',
            'mp3', 'ogg', 'aac', 'wav', 'flac',
            'zip', 'gz', 'bz2', 'xz', 'rar', '7z',
            'pdf', 'woff', 'woff2', 'ttf', 'otf',
        );

        $zip  = new ZipArchive();
        $open_result = $zip->open( $zip_path, ZipArchive::CREATE );
        if ( $open_result !== true ) {
            // Retry once after a brief delay (filesystem flush / antivirus release)
            usleep( 500000 );
            clearstatcache( true, $zip_path );
            $open_result = $zip->open( $zip_path, ZipArchive::CREATE );
            if ( $open_result !== true ) {
                error_log( 'WP Backup Nota: ZIP open failed after retry, code: ' . $open_result );
                return array( 'success' => false, 'error' => 'ZIP open failed after retry (code: ' . $open_result . ')' );
            }
        }

        $added      = 0;
        $skipped    = 0;
        $batch_end  = min( $offset + $files_per_req, $total );
        $batch_bytes = 0; // Track bytes added in this batch for memory safety

        while ( $offset < $batch_end ) {
            // Memory safety: break early if approaching memory limit
            if ( $mem_ceiling > 0 && memory_get_usage( true ) >= $mem_ceiling ) {
                error_log( 'WP Backup Nota: Memory ceiling reached at offset ' . $offset . ', closing batch early. Usage: ' . round( memory_get_usage( true ) / 1048576, 1 ) . 'MB' );
                break;
            }

            $real_path = $file_list[ $offset ];
            $offset++;

            if ( ! is_readable( $real_path ) || ! is_file( $real_path ) ) {
                $skipped++;
                continue;
            }

            $relative  = ltrim( str_replace( $base_path, '', $real_path ), '/' );
            $file_size = @filesize( $real_path );
            $ext       = strtolower( pathinfo( $real_path, PATHINFO_EXTENSION ) );
            $use_store = in_array( $ext, $store_exts, true );

            // Encrypt wp-config.php if encryption is active for this backup
            if ( $relative === 'wp-config.php' && extension_loaded( 'openssl' ) ) {
                $enc_pwd = self::resolve_encryption_password();
                if ( $enc_pwd !== '' ) {
                    $plain = @file_get_contents( $real_path );
                    if ( $plain !== false ) {
                        $key = hash( 'sha256', $enc_pwd, true );
                        $iv  = random_bytes( 16 );
                        $enc = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
                        unset( $plain );
                        if ( $enc !== false ) {
                            $zip->addFromString( 'wp-config.php.enc', $iv . $enc );
                            $added++;
                            $batch_bytes += 16 + strlen( $enc );
                        } else {
                            $skipped++;
                        }
                    } else {
                        $skipped++;
                    }
                    if ( $added >= 1 && $batch_bytes >= $chunk_bytes ) break;
                    continue;
                }
            }

            if ( $file_size !== false && $file_size > 0 && $file_size < $str_limit ) {
                $content = @file_get_contents( $real_path );
                if ( $content !== false ) {
                    $zip->addFromString( $relative, $content );
                    if ( $use_store ) {
                        $zip->setCompressionName( $relative, ZipArchive::CM_STORE );
                    }
                    $added++;
                    $batch_bytes += strlen( $content );
                    unset( $content );
                } else {
                    $skipped++;
                }
            } else {
                if ( $zip->addFile( $real_path, $relative ) ) {
                    if ( $use_store ) {
                        $zip->setCompressionName( $relative, ZipArchive::CM_STORE );
                    }
                    $added++;
                    $batch_bytes += (int) $file_size;
                } else {
                    $skipped++;
                }
            }

            // Stop when byte limit reached (always process at least 1 file)
            if ( $added >= 1 && $batch_bytes >= $chunk_bytes ) {
                break;
            }
        }

        // Close ZIP and verify
        $close_ok = $zip->close();
        unset( $zip );
        if ( ! $close_ok ) {
            error_log( 'WP Backup Nota: ZIP close() failed at offset ' . $offset . ' — rolling back offset' );
            $verify = new ZipArchive();
            if ( $verify->open( $zip_path ) === true ) {
                $verify->close();
                unset( $verify );
                // ZIP is readable — treat as success but keep previous offset to re-add this chunk
                $offset = $state['offset'];
            } else {
                unset( $verify );
                return array( 'success' => false, 'error' => 'ZIP close failed, file corrupt at offset ' . $offset );
            }
        }
        @set_time_limit( 60 );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- resets limit per chunk iteration inside step_zip_files()
        gc_collect_cycles();

        $state['offset']         = $offset;
        $state['last_heartbeat'] = time();
        update_option( self::STATE_OPTION, $state, false );

        $done = $offset >= $total;
        if ( $done ) {
            $state['pending_db_logs'][] = array( 'msg' => 'ZIP complete: ' . number_format( $offset ) . ' / ' . number_format( $total ) . ' files', 'level' => 'info' );
            update_option( self::STATE_OPTION, $state, false );
        }
        return array(
            'success' => true,
            'step'    => 'zip_files',
            'next'    => $done ? 'finalize' : 'zip_files',
            'offset'  => $offset,
            'total'   => $total,
            'added'   => $added,
            'skipped' => $skipped,
            'percent' => $total > 0 ? round( $offset / $total * 100 ) : 100,
        );
    }


    /**
     * Adım 3 — finalize
     * DB + installer + manifest'i ZIP'e ekler, DB kaydı oluşturur, Drive'a yükler.
     */
    public static function step_finalize() {
        @set_time_limit( 120 );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- inside AJAX handler, extends limit for ZIP close + DB record creation
        @ini_set( 'memory_limit', '512M' );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- inside AJAX handler, raises limit only for this backup step

        $state = get_option( self::STATE_OPTION );

        // Update heartbeat and current step at finalize start
        if ( $state ) {
            $state['last_heartbeat'] = time();
            $state['current_step']   = 'finalize';
            update_option( self::STATE_OPTION, $state, false );
        }

        // If state is missing but ZIP exists: finalize was called again after connection drop.
        // Check if DB record already exists for this backup_name.
        if ( ! $state ) {
            return array( 'success' => false, 'error' => 'Backup state not found.' );
        }

        $zip_path    = $state['zip_path'];
        $tmp_dir     = $state['tmp_dir'];
        $backup_name = $state['backup_name'];

        // ── Duplicate guard: check if a DB record with the same backup_name already exists ──
        global $wpdb;
        $table = $wpdb->prefix . 'wpbn_backups';
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE filename = %s LIMIT 1",
            $backup_name . '.zip'
        ) );
        if ( $existing ) {
            // Finalize already completed (called again due to connection drop)
            delete_option( self::STATE_OPTION );
            return array(
                'success'     => true,
                'step'        => 'finalize',
                'next'        => 'done',
                'backup_id'   => $existing->id,
                'filename'    => $existing->filename,
                'zip_path'    => $zip_path,
                'filesize'    => (int) $existing->filesize,
                'filesize_hr' => wpbn_size_format( $existing->filesize ),
                'duration'    => $existing->duration ? ( $existing->duration < 60 ? $existing->duration.'s' : floor($existing->duration/60).'m '.($existing->duration%60).'s' ) : '—',
                '_resumed'    => true,
            );
        }

        // Clean up tmp folder
        self::rmdir_recursive( $tmp_dir );

        // ── Clean up ZipArchive temporary files ──────────────────────────────
        $backup_dir = WPBN_BACKUP_DIR;
        $dir_handle = @opendir( $backup_dir );
        if ( $dir_handle ) {
            while ( ( $entry = readdir( $dir_handle ) ) !== false ) {
                if ( $entry === '.' || $entry === '..' ) continue;
                $full = $backup_dir . '/' . $entry;
                // tmp_ prefixed folders — delete all except our own tmp_dir
                if ( strpos( $entry, 'tmp_' ) === 0 && is_dir( $full ) ) {
                    if ( rtrim( $full, '/' ) !== rtrim( $tmp_dir, '/' ) ) {
                        self::rmdir_recursive( $full );
                    }
                    continue;
                }
                // ZipArchive temp files (5-8 chars, no extension)
                if ( is_file( $full ) && preg_match( '/^[a-z0-9]{5,8}$/', $entry ) ) {
                    wp_delete_file( $full );
                }
            }
            closedir( $dir_handle );
        }

        if ( ! file_exists( $zip_path ) ) {
            delete_option( self::STATE_OPTION );
            return array( 'success' => false, 'error' => 'ZIP file could not be created.' );
        }

        $filesize = filesize( $zip_path );
        $duration = isset( $state['start_time'] ) ? (int) ( microtime(true) - $state['start_time'] ) : null;
        $dur_hr   = $duration !== null ? ( $duration < 60 ? "{$duration}s" : floor($duration/60) . 'm ' . ($duration%60) . 's' ) : '—';

        global $wpdb;
        $table = $wpdb->prefix . 'wpbn_backups';
        $notes = $state['notes'] ?? '';
        $wpdb->insert( $table, array(
            'filename'    => $backup_name . '.zip',
            'filesize'    => $filesize,
            'backup_type' => $state['backup_type'] ?? 'manual',
            'status'      => 'complete',
            'duration'    => $duration,
            'notes'       => $notes,
            'created_at'  => gmdate( 'Y-m-d H:i:s' ),
        ), array( '%s', '%d', '%s', '%s', '%d', '%s', '%s' ) );
        $backup_id = $wpdb->insert_id;

        // Flush accumulated logs now that backup_id is known
        if ( $backup_id && class_exists( 'WPBN_Logger' ) ) {
            foreach ( $state['pending_db_logs'] ?? array() as $entry ) {
                WPBN_Logger::log( $entry['msg'], $entry['level'] ?? 'info', $backup_id );
            }
            WPBN_Logger::log( 'Backup complete — ' . wpbn_size_format( $filesize ) . ' in ' . $dur_hr, 'info', $backup_id );
            WPBN_Logger::prune( (int) WPBN_Settings::get( 'log_retention_backups' ) ?: 20 );
        }

        self::prune_old_backups();
        delete_option( self::STATE_OPTION );

        return array(
            'success'     => true,
            'step'        => 'finalize',
            'next'        => 'done',
            'backup_id'   => $backup_id,
            'filename'    => $backup_name . '.zip',
            'zip_path'    => $zip_path,
            'filesize'    => $filesize,
            'filesize_hr' => wpbn_size_format( $filesize ),
            'duration'    => $dur_hr,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEGACY SINGLE-CALL API (backwards compatibility)
    // ─────────────────────────────────────────────────────────────────────────

    public static function run( $args = array() ) {
        // Kept for backwards compatibility — use step API instead
        $r = self::step_init( $args );
        if ( ! $r['success'] ) return $r;

        do {
            $r = self::step_zip_files();
        } while ( $r['success'] && $r['next'] === 'zip_files' );

        if ( ! $r['success'] ) return $r;
        return self::step_finalize();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private static function build_file_list( $base_path, array $exclude_paths = array() ) {
        $base_path     = rtrim( $base_path, '/' );
        $exclude_paths = array_map( function( $p ) { return rtrim( $p, '/' ); }, $exclude_paths );

        $enabled_presets = (array) WPBN_Settings::get( 'excluded_cache_presets' );
        $wc = rtrim( WP_CONTENT_DIR, '/' ); // WP_CONTENT_DIR is the wp-content folder path — used to build absolute paths for cache exclusions
        $cache_dirs = array();
        foreach ( $enabled_presets as $rel ) {
            if ( isset( self::known_cache_dirs()[ $rel ] ) ) {
                $cache_dirs[] = $wc . '/' . ltrim( $rel, '/' );
            }
        }
        $exclude_paths = array_merge( $exclude_paths, $cache_dirs );

        $exclude_root_ini = WPBN_Settings::get( 'exclude_root_ini' ) === '1';
        $root_norm        = rtrim( str_replace( '\\', '/', $base_path ), '/' );

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $base_path, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );
        } catch ( Exception $e ) {
            return array();
        }

        $list = array();
        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) continue;
            $real_path = $file->getRealPath();
            if ( ! $real_path ) continue;

            $skip = false;
            foreach ( $exclude_paths as $excl ) {
                if ( strpos( $real_path, $excl ) === 0 ) { $skip = true; break; }
            }
            if ( $skip ) continue;

            // Skip .ini files (php.ini, .user.ini, etc.) that sit directly in WordPress root
            if ( $exclude_root_ini && strtolower( pathinfo( $real_path, PATHINFO_EXTENSION ) ) === 'ini' ) {
                $dir_norm = rtrim( str_replace( '\\', '/', dirname( $real_path ) ), '/' );
                if ( $dir_norm === $root_norm ) continue;
            }

            $list[] = $real_path;
        }
        return $list;
    }

    private static function prune_old_backups() {
        global $wpdb;
        $max   = max( 1, (int) WPBN_Settings::get( 'max_backups' ) );
        $table = $wpdb->prefix . 'wpbn_backups';

        // Count only active records (excluding deleted)
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status != 'deleted'" );
        if ( $count <= $max ) return;

        $old = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, filename FROM {$table} WHERE status != 'deleted' ORDER BY created_at ASC LIMIT %d",
            $count - $max
        ) );
        foreach ( $old as $row ) {
            $f = WPBN_BACKUP_DIR . '/' . basename( $row->filename );
            if ( file_exists( $f ) ) wp_delete_file( $f );
            $wpdb->update( $table, array( 'status' => 'deleted' ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) );
        }
    }

    public static function rmdir_recursive_public( $dir ) {
        self::rmdir_recursive( $dir );
    }

    private static function rmdir_recursive( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        $files = array_diff( scandir( $dir ), array( '.', '..' ) );
        foreach ( $files as $f ) {
            $path = $dir . '/' . $f;
            is_dir( $path ) ? self::rmdir_recursive( $path ) : wp_delete_file( $path );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        @rmdir( $dir );
    }

    /**
     * Parse PHP memory_limit into bytes.
     */
    public static function get_memory_limit_bytes() {
        $limit = ini_get( 'memory_limit' );
        if ( ! $limit || $limit === '-1' ) {
            return 0; // Unlimited
        }
        $limit = trim( $limit );
        $last  = strtolower( $limit[ strlen( $limit ) - 1 ] );
        $val   = (int) $limit;
        switch ( $last ) {
            case 'g': $val *= 1024;
            // fall through
            case 'm': $val *= 1024;
            // fall through
            case 'k': $val *= 1024;
        }
        return $val;
    }

    public static function get_backups( $limit = 20 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpbn_backups';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status != 'deleted' ORDER BY created_at DESC LIMIT %d", $limit
        ) );
    }

    public static function delete_backup( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpbn_backups';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        if ( ! $row ) return false;

        $f = WPBN_BACKUP_DIR . '/' . basename( $row->filename );
        if ( file_exists( $f ) ) wp_delete_file( $f );

        // Mark as deleted in DB — update status only, keep the record
        $wpdb->update(
            $table,
            array( 'status' => 'deleted' ),
            array( 'id'     => $id ),
            array( '%s' ),
            array( '%d' )
        );

        return true;
    }

    private static function resolve_encryption_password(): string {
        if ( WPBN_Settings::get( 'encryption_enabled' ) !== '1' ) {
            return '';
        }
        $stored = WPBN_Settings::get( 'encryption_password' );
        if ( ! $stored || ! extension_loaded( 'openssl' ) ) {
            return '';
        }
        $raw = base64_decode( $stored, true );
        if ( $raw === false || strlen( $raw ) <= 16 ) return '';
        $iv  = substr( $raw, 0, 16 );
        $enc = substr( $raw, 16 );
        $key = substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true ), 0, 32 );
        $dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return ( $dec !== false ) ? $dec : '';
    }

    public static function known_cache_dirs(): array {
        return array(
            'cache'         => __( 'General cache — W3TC, WP Super Cache, WP Rocket', 'nota-backup-restore' ),
            'uploads/cache' => __( 'Uploads cache — various plugins', 'nota-backup-restore' ),
            'et-cache'      => __( 'Divi cache', 'nota-backup-restore' ),
            'wpo-cache'     => __( 'WP-Optimize cache', 'nota-backup-restore' ),
            'breeze-cache'  => __( 'Breeze cache', 'nota-backup-restore' ),
        );
    }
}
