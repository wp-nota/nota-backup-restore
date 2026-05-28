<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.SchemaChange -- $table is always $wpdb->prefix.'wpbn_backups', never user input; schema migrations cannot use prepare()
if ( ! defined( 'ABSPATH' ) ) exit;

class WPBN_Activator {

    public static function activate() {
        global $wpdb;

        // Create backup directory
        if ( ! file_exists( WPBN_BACKUP_DIR ) ) {
            wp_mkdir_p( WPBN_BACKUP_DIR );
        }

        // Protect backup directory - block directory listing and PHP execution
        // but allow ZIP downloads (handled via WordPress AJAX for auth check)
        $htaccess = WPBN_BACKUP_DIR . '/.htaccess';
        $htaccess_content = "Options -Indexes\n"
            . "# Block direct PHP execution\n"
            . "<FilesMatch \"\\.php$\">\n"
            . "    Deny from all\n"
            . "</FilesMatch>\n"
            . "# Allow ZIP downloads only for logged-in admin (handled by WP)\n";
        file_put_contents( $htaccess, $htaccess_content );

        $index = WPBN_BACKUP_DIR . '/index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, '<?php // Silence is golden.' );
        }

        // Create DB table for backup logs
        $table = $wpdb->prefix . 'wpbn_backups';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            filename    VARCHAR(255)        NOT NULL,
            filesize    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            backup_type VARCHAR(20)         NOT NULL DEFAULT 'full',
            status      VARCHAR(20)         NOT NULL DEFAULT 'pending',
            duration    INT UNSIGNED                 DEFAULT NULL,
            notes       TEXT                         DEFAULT NULL,
            error_msg   TEXT                         DEFAULT NULL,
            created_at  DATETIME            NOT NULL,
            PRIMARY KEY (id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Add missing columns for existing installations
        $cols = $wpdb->get_col( "DESCRIBE {$table}", 0 );
        if ( ! in_array( 'duration',  $cols ) ) $wpdb->query( "ALTER TABLE {$table} ADD COLUMN duration  INT UNSIGNED DEFAULT NULL AFTER status" );
        if ( ! in_array( 'error_msg', $cols ) ) $wpdb->query( "ALTER TABLE {$table} ADD COLUMN error_msg TEXT         DEFAULT NULL AFTER notes" );

        // Create activity log table
        $logs_table = $wpdb->prefix . 'wpbn_logs';
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
        dbDelta( $sql_logs );

        // Record activation time for review notice (only on first activation)
        add_option( 'wpbn_activated_at', time() );

        // Default options
        $all_cache_presets = array_keys( WPBN_Backup::known_cache_dirs() );
        add_option( 'wpbn_settings', array(
            'max_backups'            => 5,
            'exclude_paths'          => array(),
            'excluded_cache_presets' => $all_cache_presets,
            'chunk_size_mb'          => 5,
        ) );

        // Ensure existing installations also have the cache presets key
        $existing = get_option( 'wpbn_settings', array() );
        if ( ! isset( $existing['excluded_cache_presets'] ) ) {
            $existing['excluded_cache_presets'] = $all_cache_presets;
            update_option( 'wpbn_settings', $existing );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'wpbn_process_next_chunk' );
    }
}
