<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.SchemaChange -- direct DB queries required for database export/import; $table values are always hardcoded or from wpdb->prefix, never user input
if ( ! defined( 'ABSPATH' ) ) exit;

class WPBN_Database {

    /**
     * Export entire database directly to a file.
     * Each batch is appended to disk immediately — no full SQL string held in memory.
     * Safe for very large databases (1 GB+).
     */
    public static function export_to_file( string $path ): void {
        global $wpdb;

        $header  = "-- WP Backup Nota | Database Export\n";
        $header .= "-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
        $header .= "-- WordPress: " . get_bloginfo( 'version' ) . "\n";
        $header .= "-- Site URL:  " . get_site_url() . "\n\n";
        $header .= "SET SQL_MODE   = 'NO_AUTO_VALUE_ON_ZERO';\n";
        $header .= "SET time_zone  = '+00:00';\n";
        $header .= "SET NAMES utf8mb4;\n";
        $header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        file_put_contents( $path, $header );

        $tables = $wpdb->get_col( 'SHOW TABLES' );
        foreach ( $tables as $table ) {
            self::dump_table_to_file( $path, $table );
        }

        file_put_contents( $path, "\nSET FOREIGN_KEY_CHECKS = 1;\n", FILE_APPEND );
    }

    private static function dump_table_to_file( string $path, string $table ): void {
        global $wpdb;

        // Bilinen sorunlu collation'lar → evrensel alternatifleri
        $collation_map = array(
            'utf8mb4_unicode_520_ci' => 'utf8mb4_unicode_ci',
            'utf8mb4_0900_ai_ci'     => 'utf8mb4_unicode_ci',
            'utf8mb4_0900_as_cs'     => 'utf8mb4_unicode_ci',
            'utf8_unicode_520_ci'    => 'utf8_unicode_ci',
            'utf8mb4_uca1400_ai_ci'  => 'utf8mb4_unicode_ci',
        );

        $header  = "\n-- Table: `{$table}`\n";
        $header .= "DROP TABLE IF EXISTS `{$table}`;\n";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name comes from SHOW TABLES, cannot use placeholders
        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( $create ) {
            $create_sql = str_replace(
                array_keys( $collation_map ),
                array_values( $collation_map ),
                $create[1]
            );
            $header .= $create_sql . ";\n\n";
        }

        file_put_contents( $path, $header, FILE_APPEND );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name comes from SHOW TABLES, cannot use placeholders
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
        if ( $count === 0 ) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name comes from SHOW TABLES, cannot use placeholders
        $columns_info = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
        $columns      = array_column( $columns_info, 'Field' );
        $col_list     = '`' . implode( '`, `', $columns ) . '`';

        // Fetch rows in batches and write each batch directly to file
        $batch_size = 500;
        $offset     = 0;

        while ( $offset < $count ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                "SELECT * FROM `{$table}` LIMIT {$batch_size} OFFSET {$offset}",
                ARRAY_A
            );

            if ( empty( $rows ) ) break;

            $values_list = array();
            foreach ( $rows as $row ) {
                $row_values = array();
                foreach ( $row as $val ) {
                    if ( $val === null ) {
                        $row_values[] = 'NULL';
                    } else {
                        $row_values[] = "'" . self::escape_string( $val ) . "'";
                    }
                }
                $values_list[] = '(' . implode( ', ', $row_values ) . ')';
            }

            $chunk = "INSERT INTO `{$table}` ({$col_list}) VALUES\n"
                   . implode( ",\n", $values_list ) . ";\n";

            file_put_contents( $path, $chunk, FILE_APPEND );
            $offset += $batch_size;
        }

        file_put_contents( $path, "\n", FILE_APPEND );
    }

    /**
     * Escape string for SQL – preserves multibyte UTF-8.
     * wpdb->real_escape_string varsa onu kullan (daha güvenli).
     */
    private static function escape_string( $str ) {
        global $wpdb;
        if ( $wpdb && method_exists( $wpdb, 'real_escape_string' ) ) {
            // real_escape_string handles \n, \r, \0 and all special chars correctly
            return $wpdb->_real_escape( $str );
        }
        // Fallback: manuel escape
        return str_replace(
            array( '\\',   "\x00", "\n",   "\r",   "'",    '"',    "\x1a" ),
            array( '\\\\', '\\0',  '\\n',  '\\r',  "\\'",  '\\"',  '\\Z'  ),
            $str
        );
    }

}
