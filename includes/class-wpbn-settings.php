<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPBN_Settings {

    private static $option_key = 'wpbn_settings';

    // ── Default values ─────────────────────────────────────────────────────────

    private static function defaults(): array {
        return array(
            'max_backups'              => 5,
            'exclude_paths'            => array(),
            'excluded_cache_presets'   => array( 'cache', 'uploads/cache', 'et-cache', 'wpo-cache', 'breeze-cache' ),
            'exclude_root_ini'         => '1',
            'chunk_size_mb'            => 5,
            'chunk_size_mb_auto'       => 0,
            'files_per_chunk_override' => 0,
            'encryption_enabled'       => '0',
            'encryption_password'      => '',
        );
    }

    private static function get_raw(): array {
        $stored = get_option( self::$option_key, array() );
        return wp_parse_args( $stored, self::defaults() );
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    public static function get( $key = null ) {
        $settings = self::get_raw();

        if ( $key !== null ) {
            return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
        }

        return $settings;
    }

    public static function update( $data ) {
        $current = self::get_raw();
        $updated = wp_parse_args( $data, $current );
        return update_option( self::$option_key, $updated );
    }

    public static function set( $key, $value ) {
        $current         = self::get_raw();
        $current[ $key ] = $value;
        return update_option( self::$option_key, $current );
    }
}
