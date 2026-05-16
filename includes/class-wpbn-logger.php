<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table/$backup_table always constructed from $wpdb->prefix, never user input; log entries are write-heavy and must not be cached
if ( ! defined( 'ABSPATH' ) ) exit;

class WPBN_Logger {

    const TABLE = 'wpbn_logs';

    /**
     * Write a log entry.
     *
     * @param string   $message
     * @param string   $level     'info' | 'warning' | 'error'
     * @param int|null $backup_id Null for system-level events.
     */
    public static function log( string $message, string $level = 'info', ?int $backup_id = null ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            array(
                'backup_id'  => $backup_id ?: null,
                'level'      => in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info',
                'message'    => $message,
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s' )
        );
    }

    /**
     * Return all log rows for a given backup, ordered oldest-first.
     */
    public static function get_by_backup( int $backup_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE backup_id = %d ORDER BY id ASC", $backup_id )
        );
    }

    /**
     * Return recent system-level log rows (backup_id IS NULL).
     */
    public static function get_system_logs( int $limit = 200 ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE backup_id IS NULL ORDER BY id DESC LIMIT %d", $limit )
        );
    }

    /**
     * Return a brief summary for a backup: total rows + count per level.
     */
    public static function get_summary( int $backup_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT level, COUNT(*) AS cnt FROM {$table} WHERE backup_id = %d GROUP BY level",
                $backup_id
            )
        );
        $summary = array( 'total' => 0, 'info' => 0, 'warning' => 0, 'error' => 0 );
        foreach ( $rows as $r ) {
            $summary[ $r->level ] = (int) $r->cnt;
            $summary['total']    += (int) $r->cnt;
        }
        return $summary;
    }

    /**
     * Delete log rows for backups older than the N most-recent backup IDs.
     * Keeps all system logs (backup_id IS NULL) up to a hard cap.
     *
     * @param int $keep_backups Number of recent backups whose logs to retain.
     */
    public static function prune( int $keep_backups = 10 ): void {
        global $wpdb;
        $table        = $wpdb->prefix . self::TABLE;
        $backup_table = $wpdb->prefix . 'wpbn_backups';

        // IDs of the N most recent backups
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$backup_table} ORDER BY id DESC LIMIT %d",
                $keep_backups
            )
        );

        if ( empty( $ids ) ) {
            $wpdb->query( "DELETE FROM {$table} WHERE backup_id IS NOT NULL" );
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE backup_id IS NOT NULL AND backup_id NOT IN ({$placeholders})",
                ...$ids
            )
        );

        // Hard cap on system logs — keep the 500 most recent
        $wpdb->query(
            "DELETE FROM {$table}
             WHERE backup_id IS NULL
               AND id NOT IN (
                   SELECT id FROM (
                       SELECT id FROM {$table} WHERE backup_id IS NULL ORDER BY id DESC LIMIT 500
                   ) tmp
               )"
        );
    }
}
