<?php
/**
 * WP-CLI commands for Nota Backup & Restore.
 *
 * Registered under `wp nota`. Loaded only when WP-CLI is running.
 *
 * @package Nota_Backup_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_CLI' ) ) {
    return;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is always $wpdb->prefix.'wpbn_backups', never user input; IDs are bound via $wpdb->prepare(). Caching is not meaningful for one-off CLI reads.

/**
 * Manage Nota backups from the command line.
 */
class WPBN_CLI {

    /**
     * Create a backup of the site.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : What to back up.
     * ---
     * default: full
     * options:
     *   - full
     *   - db
     *   - files
     * ---
     *
     * [--tables=<tables>]
     * : Comma-separated list of database tables to include (default: all tables).
     *
     * [--exclude=<paths>]
     * : Comma-separated file/folder paths to exclude. Relative paths are resolved
     *   against the WordPress root.
     *
     * [--notes=<text>]
     * : Optional note stored with the backup.
     *
     * [--encrypt]
     * : Encrypt the backup with the saved AES-256 password (Settings -> Encryption).
     *
     * [--porcelain]
     * : Output only the resulting backup filename (useful for scripts).
     *
     * ## EXAMPLES
     *
     *     wp nota backup
     *     wp nota backup --type=db --notes="before migration"
     *     wp nota backup --exclude=wp-content/uploads/large,wp-content/cache
     *     wp nota backup --encrypt --porcelain
     *
     * @when after_wp_load
     */
    public function cmd_backup( $args, $assoc ) {
        @set_time_limit( 0 );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- CLI backups may run for several minutes; lift the execution time cap
        @ini_set( 'memory_limit', '512M' );  // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- raise the memory limit only for this backup run

        // Refuse to start if another backup is already running (web or CLI).
        if ( get_option( WPBN_Backup::STATE_OPTION ) ) {
            WP_CLI::error( 'A backup is already in progress. Run `wp nota status` to check, or wait for it to finish.' );
        }

        // Map the --type flag to the plugin's internal backup_type values.
        $type_map = array( 'full' => 'full', 'db' => 'db_only', 'files' => 'files_only' );
        $type     = isset( $assoc['type'] ) ? $assoc['type'] : 'full';
        if ( ! isset( $type_map[ $type ] ) ) {
            WP_CLI::error( "Invalid --type '{$type}'. Allowed values: full, db, files." );
        }
        $backup_type = $type_map[ $type ];

        // --encrypt only has an effect when a password is actually configured.
        if ( isset( $assoc['encrypt'] ) && WPBN_Settings::get( 'encryption_enabled' ) !== '1' ) {
            WP_CLI::warning( 'No encryption password is configured; the backup will not be encrypted. Set one under Settings -> Encryption.' );
        }

        $porcelain = isset( $assoc['porcelain'] );

        // Parse --tables (DB tables to include).
        $selected_tables = array();
        if ( isset( $assoc['tables'] ) && $assoc['tables'] !== '' ) {
            $selected_tables = array_filter( array_map( 'trim', explode( ',', $assoc['tables'] ) ) );
        }

        // Parse --exclude (resolve relative paths against the WordPress root).
        $exclude_paths = array();
        if ( isset( $assoc['exclude'] ) && $assoc['exclude'] !== '' ) {
            $base = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
            foreach ( array_filter( array_map( 'trim', explode( ',', $assoc['exclude'] ) ) ) as $p ) {
                $p          = str_replace( '\\', '/', $p );
                $is_absolute = ( isset( $p[0] ) && $p[0] === '/' ) || preg_match( '#^[A-Za-z]:/#', $p );
                $exclude_paths[] = $is_absolute ? rtrim( $p, '/' ) : $base . '/' . ltrim( $p, '/' );
            }
        }

        // 1) Initialise: database export, manifest, file list, saved state.
        $init = WPBN_Backup::step_init( array(
            'backup_type'     => $backup_type,
            'notes'           => isset( $assoc['notes'] ) ? sanitize_textarea_field( $assoc['notes'] ) : '',
            'selected_tables' => $selected_tables,
            'exclude_paths'   => $exclude_paths,
        ) );
        if ( empty( $init['success'] ) ) {
            WP_CLI::error( 'Could not initialise the backup: ' . ( isset( $init['error'] ) ? $init['error'] : 'unknown error' ) );
        }

        $total = isset( $init['file_count'] ) ? (int) $init['file_count'] : 0;
        if ( ! $porcelain ) {
            WP_CLI::log( sprintf( 'Starting %s backup (%s files)...', $backup_type, number_format( $total ) ) );
        }

        // 2) Add files to the archive in chunks, with a progress bar.
        if ( isset( $init['next'] ) && $init['next'] === 'zip_files' ) {
            $bar  = ( ! $porcelain && $total > 0 )
                ? \WP_CLI\Utils\make_progress_bar( 'Archiving files', $total )
                : null;
            $prev = 0;

            do {
                $r = WPBN_Backup::step_zip_files();
                if ( empty( $r['success'] ) ) {
                    self::cleanup_state();
                    WP_CLI::error( 'Backup failed while archiving files: ' . ( isset( $r['error'] ) ? $r['error'] : 'unknown error' ) );
                }
                if ( $bar ) {
                    $offset = isset( $r['offset'] ) ? (int) $r['offset'] : 0;
                    $bar->tick( max( 0, $offset - $prev ) );
                    $prev = $offset;
                }
            } while ( isset( $r['next'] ) && $r['next'] === 'zip_files' );

            if ( $bar ) {
                $bar->finish();
            }
        }

        // 3) Finalise: assemble archive, write the history record, prune old backups.
        $final = WPBN_Backup::step_finalize();
        if ( empty( $final['success'] ) ) {
            self::cleanup_state();
            WP_CLI::error( 'Backup failed during finalisation: ' . ( isset( $final['error'] ) ? $final['error'] : 'unknown error' ) );
        }

        // 4) Report the result.
        if ( $porcelain ) {
            WP_CLI::line( $final['filename'] );
            return;
        }

        WP_CLI::success( sprintf(
            'Backup complete: %s (%s, took %s).',
            $final['filename'],
            isset( $final['filesize_hr'] ) ? $final['filesize_hr'] : '-',
            isset( $final['duration'] ) ? $final['duration'] : '-'
        ) );
    }

    /**
     * List existing backups.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - ids
     * ---
     *
     * [--fields=<fields>]
     * : Comma-separated columns to display.
     * Default: id,filename,filesize,backup_type,status,created_at
     *
     * ## EXAMPLES
     *
     *     wp nota list
     *     wp nota list --format=json
     *
     * @when after_wp_load
     */
    public function cmd_list( $args, $assoc ) {
        $rows   = WPBN_Backup::get_backups( 1000 );
        $fields = isset( $assoc['fields'] )
            ? array_map( 'trim', explode( ',', $assoc['fields'] ) )
            : array( 'id', 'filename', 'filesize', 'backup_type', 'status', 'created_at' );

        $items = array();
        foreach ( (array) $rows as $row ) {
            $items[] = array(
                'id'          => (int) $row->id,
                'filename'    => $row->filename,
                'filesize'    => wpbn_size_format( $row->filesize ),
                'backup_type' => $row->backup_type,
                'status'      => $row->status,
                'created_at'  => $row->created_at,
            );
        }

        if ( empty( $items ) ) {
            WP_CLI::log( 'No backups found.' );
            return;
        }

        $format = isset( $assoc['format'] ) ? $assoc['format'] : 'table';
        \WP_CLI\Utils\format_items( $format, $items, $fields );
    }

    /**
     * Show details for a single backup.
     *
     * ## OPTIONS
     *
     * <id>
     * : The backup ID (see `wp nota list`).
     *
     * @when after_wp_load
     */
    public function cmd_info( $args, $assoc ) {
        $id = isset( $args[0] ) ? (int) $args[0] : 0;
        if ( ! $id ) {
            WP_CLI::error( 'Please provide a backup ID. See `wp nota list`.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpbn_backups';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        if ( ! $row ) {
            WP_CLI::error( "Backup #{$id} not found." );
        }

        $local = WPBN_BACKUP_DIR . '/' . basename( $row->filename );
        $items = array(
            array( 'field' => 'ID',           'value' => $row->id ),
            array( 'field' => 'Filename',     'value' => $row->filename ),
            array( 'field' => 'Size',         'value' => wpbn_size_format( $row->filesize ) ),
            array( 'field' => 'Type',         'value' => $row->backup_type ),
            array( 'field' => 'Status',       'value' => $row->status ),
            array( 'field' => 'Created',      'value' => $row->created_at ),
            array( 'field' => 'Notes',        'value' => $row->notes ),
            array( 'field' => 'Local file',   'value' => file_exists( $local ) ? 'present' : 'missing' ),
        );
        \WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
    }

    /**
     * Delete one or more backups.
     *
     * ## OPTIONS
     *
     * [<id>]
     * : The backup ID to delete.
     *
     * [--all]
     * : Delete every backup.
     *
     * [--keep=<n>]
     * : Keep the newest <n> backups and delete the rest.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp nota delete 12
     *     wp nota delete --keep=5
     *     wp nota delete --all --yes
     *
     * @when after_wp_load
     */
    public function cmd_delete( $args, $assoc ) {
        $id   = isset( $args[0] ) ? (int) $args[0] : 0;
        $all  = isset( $assoc['all'] );
        $keep = isset( $assoc['keep'] ) ? max( 0, (int) $assoc['keep'] ) : null;

        if ( ! $id && ! $all && $keep === null ) {
            WP_CLI::error( 'Specify a backup ID, --all, or --keep=<n>.' );
        }

        // Build the list of IDs to delete.
        $targets = array();
        if ( $id ) {
            $targets[] = $id;
        } else {
            $rows = WPBN_Backup::get_backups( 1000 ); // newest first
            if ( $keep !== null ) {
                $rows = array_slice( (array) $rows, $keep );
            }
            foreach ( (array) $rows as $row ) {
                $targets[] = (int) $row->id;
            }
        }

        if ( empty( $targets ) ) {
            WP_CLI::success( 'Nothing to delete.' );
            return;
        }

        if ( ! isset( $assoc['yes'] ) ) {
            WP_CLI::confirm( sprintf( 'Delete %d backup(s)? This cannot be undone.', count( $targets ) ) );
        }

        $deleted = 0;
        foreach ( $targets as $tid ) {
            if ( WPBN_Backup::delete_backup( $tid ) ) {
                $deleted++;
            } else {
                WP_CLI::warning( "Could not delete backup #{$tid}." );
            }
        }
        WP_CLI::success( sprintf( 'Deleted %d backup(s).', $deleted ) );
    }

    /**
     * Generate the standalone migration installer for a backup.
     *
     * The installer is written to the path you choose (never into the public
     * backup directory). Move it, together with the backup file, to the target
     * server and run it in a browser to migrate the site.
     *
     * ## OPTIONS
     *
     * <id>
     * : The backup ID to build an installer for.
     *
     * [--output=<path>]
     * : Destination file or directory. Defaults to the current directory.
     *
     * ## EXAMPLES
     *
     *     wp nota installer 12
     *     wp nota installer 12 --output=/home/user/migrate/
     *
     * @when after_wp_load
     */
    public function cmd_installer( $args, $assoc ) {
        $id = isset( $args[0] ) ? (int) $args[0] : 0;
        if ( ! $id ) {
            WP_CLI::error( 'Please provide a backup ID. See `wp nota list`.' );
        }

        $built = WPBN_Backup::build_installer_content( $id );
        if ( empty( $built['success'] ) ) {
            WP_CLI::error( isset( $built['error'] ) ? $built['error'] : 'Could not build the installer.' );
        }

        // Resolve the destination path.
        $dest = isset( $assoc['output'] ) ? $assoc['output'] : getcwd();
        if ( is_dir( $dest ) ) {
            $dest = rtrim( $dest, '/\\' ) . '/' . $built['filename'];
        }

        if ( false === file_put_contents( $dest, $built['content'] ) ) {
            WP_CLI::error( "Could not write the installer to: {$dest}" );
        }

        WP_CLI::success( "Installer written to: {$dest}" );
    }

    /**
     * Show the current backup engine status.
     *
     * @when after_wp_load
     */
    public function cmd_status( $args, $assoc ) {
        $state = get_option( WPBN_Backup::STATE_OPTION );
        if ( ! $state ) {
            WP_CLI::success( 'Idle. No backup is currently running.' );
            return;
        }

        $hb       = isset( $state['last_heartbeat'] ) ? (int) $state['last_heartbeat'] : 0;
        $age      = $hb ? ( time() - $hb ) . 's ago' : 'unknown';
        $step     = isset( $state['current_step'] ) ? $state['current_step'] : 'unknown';
        $offset   = isset( $state['offset'] ) ? (int) $state['offset'] : 0;
        $count    = isset( $state['file_count'] ) ? (int) $state['file_count'] : 0;

        WP_CLI::log( 'A backup is in progress:' );
        WP_CLI::log( '  Step:           ' . $step );
        WP_CLI::log( '  Files archived: ' . number_format( $offset ) . ' / ' . number_format( $count ) );
        WP_CLI::log( '  Last heartbeat: ' . $age );
    }

    /**
     * Verify that a backup file exists and is a readable archive.
     *
     * ## OPTIONS
     *
     * <id>
     * : The backup ID to verify.
     *
     * @when after_wp_load
     */
    public function cmd_verify( $args, $assoc ) {
        $id = isset( $args[0] ) ? (int) $args[0] : 0;
        if ( ! $id ) {
            WP_CLI::error( 'Please provide a backup ID. See `wp nota list`.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpbn_backups';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        if ( ! $row ) {
            WP_CLI::error( "Backup #{$id} not found." );
        }

        $path = WPBN_BACKUP_DIR . '/' . basename( $row->filename );
        if ( ! file_exists( $path ) ) {
            WP_CLI::error( "Local file is missing: {$row->filename}" );
        }
        if ( (int) filesize( $path ) === 0 ) {
            WP_CLI::error( "Backup file is empty: {$row->filename}" );
        }

        if ( ! class_exists( 'ZipArchive' ) ) {
            WP_CLI::warning( 'ZipArchive is not available; verified file existence and size only.' );
            WP_CLI::success( "Backup #{$id} file is present ({$row->filename})." );
            return;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $path ) !== true ) {
            WP_CLI::error( "Backup archive is corrupt or unreadable: {$row->filename}" );
        }
        $entries = $zip->numFiles;
        $zip->close();

        WP_CLI::success( sprintf( 'Backup #%d is valid: %s (%s, %s entries).', $id, $row->filename, wpbn_size_format( $row->filesize ), number_format( $entries ) ) );
    }

    /**
     * Copy a backup file to a chosen location.
     *
     * ## OPTIONS
     *
     * <id>
     * : The backup ID to download.
     *
     * [--output=<path>]
     * : Destination file or directory. Defaults to the current directory.
     *
     * ## EXAMPLES
     *
     *     wp nota download 12
     *     wp nota download 12 --output=/home/user/backups/
     *
     * @when after_wp_load
     */
    public function cmd_download( $args, $assoc ) {
        $id = isset( $args[0] ) ? (int) $args[0] : 0;
        if ( ! $id ) {
            WP_CLI::error( 'Please provide a backup ID. See `wp nota list`.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpbn_backups';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        if ( ! $row ) {
            WP_CLI::error( "Backup #{$id} not found." );
        }

        $src = WPBN_BACKUP_DIR . '/' . basename( $row->filename );
        if ( ! file_exists( $src ) ) {
            WP_CLI::error( "Local backup file is missing: {$row->filename}" );
        }

        $dest = isset( $assoc['output'] ) ? $assoc['output'] : getcwd();
        if ( is_dir( $dest ) ) {
            $dest = rtrim( $dest, '/\\' ) . '/' . basename( $row->filename );
        }

        if ( ! copy( $src, $dest ) ) {
            WP_CLI::error( "Could not copy the backup to: {$dest}" );
        }

        WP_CLI::success( "Backup copied to: {$dest}" );
    }

    /**
     * Clean up an interrupted backup.
     *
     * If a backup was stopped midway (timeout or a killed process), its leftover
     * state can block new backups from starting. This clears that state and any
     * temporary files so you can run a fresh backup.
     *
     * @when after_wp_load
     */
    public function cmd_cleanup( $args, $assoc ) {
        $state = get_option( WPBN_Backup::STATE_OPTION );
        if ( ! $state ) {
            WP_CLI::success( 'Nothing to clean up; no backup is in progress.' );
            return;
        }

        $step = isset( $state['current_step'] ) ? $state['current_step'] : 'unknown';
        if ( function_exists( 'wpbn_cleanup_failed_state' ) ) {
            wpbn_cleanup_failed_state( 'Cleaned up via WP-CLI (wp nota cleanup).' );
        } else {
            self::cleanup_state();
        }

        WP_CLI::success( "Cleared an interrupted backup state (was at step '{$step}')." );
    }

    /**
     * Restore a backup (Pro only on the free version).
     *
     * @when after_wp_load
     */
    public function cmd_restore( $args, $assoc ) {
        WP_CLI::error(
            "In-dashboard restore requires Nota Backup & Restore Pro.\n" .
            "On the free version, run `wp nota installer <id>` to migrate using the standalone installer.\n" .
            "Learn more: https://www.wp-nota.com/pricing"
        );
    }

    /**
     * Remove a partial backup's temp directory and state after a failure.
     */
    private static function cleanup_state() {
        $state = get_option( WPBN_Backup::STATE_OPTION );
        if ( $state && ! empty( $state['tmp_dir'] ) && is_dir( $state['tmp_dir'] ) ) {
            WPBN_Backup::rmdir_recursive_public( $state['tmp_dir'] );
        }
        delete_option( WPBN_Backup::STATE_OPTION );
    }
}
