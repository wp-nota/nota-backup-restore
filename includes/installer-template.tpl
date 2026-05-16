<?php
/**
 * Nota Backup & Restore — Standalone Installer
 * Upload this file and the backup ZIP to the same directory on your new server.
 * Run this file in your browser, enter DB credentials, and the installer will
 * extract the ZIP and migrate the database automatically.
 * Runs independently of WordPress — do not add ABSPATH check.
 */
@ini_set( 'memory_limit', '1024M' );
@ini_set( 'max_execution_time', 3600 );
@ini_set( 'display_errors', 0 );

define( 'WPBN_ZIP_FILE', '@@ZIP_FILE@@' ); // embedded at download time
define( 'WPBN_ROOT',     __DIR__ );
define( 'WPBN_ZIP_PATH', WPBN_ROOT . '/' . WPBN_ZIP_FILE );
define( 'WPBN_LOG',      WPBN_ROOT . '/installer_log.txt' );
define( 'WPBN_MANIFEST', WPBN_ROOT . '/wpbn-manifest.json' );
define( 'WPBN_CONFIG',   WPBN_ROOT . '/config.json' );
define( 'WPBN_STATE',    WPBN_ROOT . '/.wpbn_state.json' );

$incomplete_class_count = 0;

function wpbn_log( $msg ) {
    file_put_contents( WPBN_LOG, date( 'Y-m-d H:i:s' ) . ' — ' . $msg . "\n", FILE_APPEND );
}

function wpbn_esc( $s ) {
    return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

function wpbn_json( $data ) {
    header( 'Content-Type: application/json; charset=utf-8' );
    echo json_encode( $data );
    exit;
}

function wpbn_manifest() {
    foreach ( array( WPBN_MANIFEST, WPBN_CONFIG ) as $file ) {
        if ( file_exists( $file ) ) {
            $d = json_decode( file_get_contents( $file ), true );
            if ( $d && is_array( $d ) ) return $d;
        }
    }
    return null;
}

function wpbn_db_connect( $host, $user, $pass ) {
    if ( ! extension_loaded( 'mysqli' ) ) {
        wpbn_json( array( 'ok' => false, 'error' => 'The mysqli PHP extension is required but not enabled on this server. Please contact your host.' ) );
    }
    $c = @new mysqli( $host, $user, $pass ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- standalone installer, $wpdb unavailable
    if ( $c->connect_error ) return false;
    $c->set_charset( 'utf8mb4' );
    return $c;
}

function wpbn_state_save( $data ) {
    file_put_contents( WPBN_STATE, json_encode( $data ) );
}

function wpbn_state_load() {
    if ( ! file_exists( WPBN_STATE ) ) return null;
    $d = json_decode( file_get_contents( WPBN_STATE ), true );
    return is_array( $d ) ? $d : null;
}

// ── URL & PATH VARIATIONS ─────────────────────────────────────────────────────
function wpbn_url_variations( $url ) {
    if ( ! $url ) return array();
    $url  = rtrim( $url, '/' );
    $p    = parse_url( $url );
    $host = $p['host'] ?? '';
    $path = $p['path'] ?? '';
    $port = isset( $p['port'] ) ? ':' . $p['port'] : '';
    $hosts = array( $host );
    if ( strpos( $host, 'www.' ) === 0 ) $hosts[] = substr( $host, 4 );
    else $hosts[] = 'www.' . $host;
    $vars = array();
    foreach ( array( 'http', 'https' ) as $s ) {
        foreach ( $hosts as $h ) {
            foreach ( array( '', '/' ) as $sl ) {
                $base   = "{$s}://{$h}{$port}{$path}{$sl}";
                $vars[] = $base;
                $vars[] = str_replace( '://', ':\/\/', $base );
                $vars[] = '//' . $h . $port . $path . $sl;
                $vars[] = urlencode( $base );
                $vars[] = rawurlencode( $base );
                $ej = json_encode( $base ); if ( $ej ) $vars[] = trim( $ej, '"' );
                $vars[] = addcslashes( $base, '/:.' );
                $vars[] = strtolower( $base );
                $vars[] = strtoupper( $base );
            }
        }
    }
    return array_values( array_unique( $vars ) );
}

function wpbn_path_variations( $path ) {
    if ( ! $path ) return array();
    $path = rtrim( $path, '/\\' );
    $vars = array();
    foreach ( array( '', '/', '\\' ) as $sl ) {
        $base   = $path . $sl;
        $vars[] = $base;
        $vars[] = str_replace( '/', '\\/', $base );
        $vars[] = str_replace( '/', '\\\\', $base );
        $vars[] = addcslashes( $base, '/\\' );
        $vars[] = urlencode( $base );
        $vars[] = rawurlencode( $base );
        $ej = json_encode( $base ); if ( $ej ) $vars[] = trim( $ej, '"' );
        $vars[] = strtolower( $base );
        $vars[] = strtoupper( $base );
    }
    return array_values( array_unique( $vars ) );
}

// ── REPLACE ───────────────────────────────────────────────────────────────────
function wpbn_recursive_replace( $search, $replace, $data ) {
    global $incomplete_class_count;
    if ( is_array( $data ) ) {
        foreach ( $data as $k => $v ) $data[$k] = wpbn_recursive_replace( $search, $replace, $v );
    } elseif ( is_object( $data ) ) {
        if ( get_class( $data ) === '__PHP_Incomplete_Class' ) { $incomplete_class_count++; return $data; }
        foreach ( get_object_vars( $data ) as $k => $v ) $data->$k = wpbn_recursive_replace( $search, $replace, $v );
    } elseif ( is_string( $data ) ) {
        $data = str_replace( $search, $replace, $data );
    }
    return $data;
}

function wpbn_safe_unserialize_replace( $search, $replace, $data ) {
    global $incomplete_class_count;
    if ( is_string( $data ) && preg_match( '/^(a:\d+:{|O:\d+:"|s:\d+:")/', $data ) ) {
        $u = version_compare( PHP_VERSION, '7.0', '>=' )
            ? @unserialize( $data, array( 'allowed_classes' => false ) )
            : @unserialize( $data );
        if ( $u !== false ) {
            if ( is_object( $u ) && get_class( $u ) === '__PHP_Incomplete_Class' ) { $incomplete_class_count++; return $data; }
            return serialize( wpbn_recursive_replace( $search, $replace, $u ) );
        }
    }
    return str_replace( $search, $replace, $data );
}

// ── QUERY FIX SYSTEM ─────────────────────────────────────────────────────────
function wpbn_normalize_collations( $q ) {
    $legacy_map = array(
        'utf8mb4_unicode_520_ci'  => 'utf8mb4_unicode_ci',
        'utf8mb4_0900_ai_ci'      => 'utf8mb4_unicode_ci',
        'utf8mb4_0900_as_ci'      => 'utf8mb4_unicode_ci',
        'utf8mb4_0900_as_cs'      => 'utf8mb4_unicode_ci',
        'utf8mb4_uca1400_ai_ci'   => 'utf8mb4_unicode_ci',
        'utf8mb4_uca1400_as_ci'   => 'utf8mb4_unicode_ci',
        'utf8mb4_uca1400_as_cs'   => 'utf8mb4_unicode_ci',
        'utf8_unicode_520_ci'     => 'utf8_unicode_ci',
        'utf8mb3_general_ci'      => 'utf8_general_ci',
        'utf8mb3_unicode_ci'      => 'utf8_unicode_ci',
    );
    $q = str_replace( array_keys( $legacy_map ), array_values( $legacy_map ), $q );

    $invalid_charsets = 'utf16|utf16le|utf32|ucs2|geostd8|cp1256|latin[2-9]|ascii';
    $def_charset  = 'utf8mb4';
    $def_collate  = 'utf8mb4_unicode_ci';

    $q = preg_replace(
        '/((?:CHARSET|CHARACTER\s+SET)\s*=?\s*[`\'"]?)(' . $invalid_charsets . ')([`\'"]?\s+COLLATE\s*=?\s*[`\'"]?)([^`\'"\s;,]+)([`\'"]?)/im',
        '$1' . $def_charset . '$3' . $def_collate . '$5',
        $q
    );
    $q = preg_replace(
        '/((?:CHARSET|CHARACTER\s+SET)\s*=?\s*[`\'"]?)(' . $invalid_charsets . ')([`\'"]?(?:[\s;,]|$))/im',
        '$1' . $def_charset . '$3',
        $q
    );
    $q = preg_replace(
        '/^(\s*(?:\/\*!\d+\s)?\s*(?:CREATE.+)?)(DEFINER\s*=\s*\S+)(.*)$/m',
        '$1 $3',
        $q
    );

    return $q;
}

function wpbn_safe_collation( $conn ) {
    $pref  = array( 'utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8_unicode_ci', 'utf8_general_ci' );
    $res   = $conn->query( 'SHOW COLLATION' );
    $avail = array();
    if ( $res ) while ( $r = $res->fetch_row() ) $avail[] = $r[0];
    foreach ( $pref as $c ) if ( in_array( $c, $avail, true ) ) return $c;
    return 'utf8_general_ci';
}

// ── EXECUTE SQL QUERY (with fallbacks) ────────────────────────────────────────
function wpbn_exec_query( $conn, $query, $safe_collation, &$current_table ) {
    $qn = wpbn_normalize_collations( $query );
    $ok = $conn->query( $qn );

    if ( ! $ok && $conn->errno === 1227 ) {
        $qd = preg_replace( '/DEFINER\s*=\s*\S+/i', '', $qn );
        $ok = $conn->query( $qd );
        if ( $ok ) wpbn_log( "DEFINER removed (1227): {$current_table}" );
    }

    if ( ! $ok && $conn->errno === 1273 ) {
        $qs = preg_replace(
            array( '/\s+CHARACTER\s+SET\s+\S+/i', '/\s+COLLATE\s+\S+/i', '/DEFAULT\s+CHARSET\s*=\s*\S+/i', '/COLLATE\s*=\s*\S+/i' ),
            '',
            $qn
        );
        $qs = rtrim( $qs, " \t\n\r" ) . " DEFAULT CHARSET=utf8mb4 COLLATE={$safe_collation}";
        $ok = $conn->query( $qs );
        if ( $ok ) wpbn_log( "Collation fixed: {$current_table}" );
    }

    if ( ! $ok && $conn->errno === 1071 ) {
        @$conn->query( "SET GLOBAL innodb_large_prefix = ON" );
        @$conn->query( "SET GLOBAL innodb_file_format = Barracuda" );
        if ( stripos( $qn, 'ROW_FORMAT' ) !== false ) {
            $qd = preg_replace( '/ROW_FORMAT\s*=\s*\w+/i', 'ROW_FORMAT=DYNAMIC', $qn );
        } else {
            $qd = rtrim( $qn, " \t\n\r" ) . ' ROW_FORMAT=DYNAMIC';
        }
        $ok = $conn->query( $qd );
        if ( ! $ok ) {
            $qs2 = preg_replace_callback(
                '/varchar\s*\(\s*(\d+)\s*\)/i',
                function( $m ) { return ( (int) $m[1] > 191 ) ? 'varchar(191)' : $m[0]; },
                $qn
            );
            $ok = $conn->query( $qs2 );
            if ( $ok ) wpbn_log( "Key too long fixed (VARCHAR shortened): {$current_table}" );
        } else {
            wpbn_log( "Key too long fixed (ROW_FORMAT=DYNAMIC): {$current_table}" );
        }
    }

    return $ok;
}

// ── AJAX: extract_zip ─────────────────────────────────────────────────────────
function wpbn_ajax_extract_zip() {
    @set_time_limit( 3600 );
    @ini_set( 'memory_limit', '1024M' );

    if ( ! file_exists( WPBN_ZIP_PATH ) ) {
        wpbn_json( array( 'ok' => false, 'error' => 'ZIP file not found: ' . WPBN_ZIP_FILE . '. Make sure it is in the same directory as this installer.' ) );
    }
    if ( ! extension_loaded( 'zip' ) ) {
        wpbn_json( array( 'ok' => false, 'error' => 'ZipArchive PHP extension is required for extraction. Please enable it on your server or extract the ZIP manually.' ) );
    }
    $zip = new ZipArchive();
    $res = $zip->open( WPBN_ZIP_PATH );
    if ( $res !== true ) {
        wpbn_json( array( 'ok' => false, 'error' => 'Could not open ZIP file (code: ' . $res . '). The file may be corrupted.' ) );
    }
    $count = $zip->numFiles;
    $zip->extractTo( WPBN_ROOT );
    $zip->close();
    wpbn_log( 'ZIP extracted: ' . WPBN_ZIP_FILE . ' (' . $count . ' files)' );
    wpbn_json( array( 'ok' => true, 'files' => $count ) );
}

// ── AJAX: import_chunk ────────────────────────────────────────────────────────
function wpbn_ajax_import_chunk() {
    @set_time_limit( 55 );
    $state = wpbn_state_load();
    if ( ! $state ) { wpbn_json( array( 'ok' => false, 'error' => 'State not found. Refresh the page and try again.' ) ); }

    $sql_file          = $state['sql_file'];
    $offset            = (int) $state['offset'];
    $safe_collation    = $state['safe_collation'];
    $current_table     = $state['current_table'] ?? '';
    $query_count       = (int) $state['query_count'];
    $table_count       = (int) $state['table_count'];
    $errors            = $state['errors'] ?? array();
    $queries_per_chunk = 40;
    $old_prefix        = $state['old_prefix'] ?? '';
    $new_prefix        = $state['new_prefix'] ?? '';
    $do_prefix_replace = ( $old_prefix !== '' && $new_prefix !== '' && $old_prefix !== $new_prefix );

    if ( ! file_exists( $sql_file ) ) {
        wpbn_json( array( 'ok' => false, 'error' => 'SQL file not found: ' . $sql_file ) );
    }

    $filesize = filesize( $sql_file );
    if ( $offset >= $filesize ) {
        wpbn_json( array( 'ok' => true, 'done' => true, 'query_count' => $query_count, 'table_count' => $table_count, 'errors' => $errors ) );
    }

    $conn = wpbn_db_connect( $state['db_host'], $state['db_user'], $state['db_pass'] );
    if ( ! $conn ) wpbn_json( array( 'ok' => false, 'error' => 'Could not connect to database.' ) );
    $conn->select_db( $state['db_name'] );
    $conn->set_charset( 'utf8mb4' );
    $conn->query( "SET NAMES 'utf8mb4'" );
    $conn->query( "SET sql_mode = ''" );
    $conn->query( "SET FOREIGN_KEY_CHECKS = 0" );

    $handle = fopen( $sql_file, 'rb' );
    fseek( $handle, $offset );

    $buf      = $state['buf']      ?? '';
    $in_str   = (bool)( $state['in_str']  ?? false );
    $str_ch   = $state['str_ch']   ?? '';
    $escaped  = (bool)( $state['escaped'] ?? false );
    $in_comment_ml = (bool)( $state['in_comment_ml'] ?? false );

    $done_queries  = 0;
    $last_query_offset = $offset;

    while ( ! feof( $handle ) && $done_queries < $queries_per_chunk ) {
        $line = fgets( $handle, 4 * 1024 * 1024 );
        if ( $line === false ) break;
        $len = strlen( $line );

        for ( $i = 0; $i < $len; $i++ ) {
            $c = $line[$i];

            if ( $in_comment_ml ) {
                if ( $c === '*' && isset( $line[$i+1] ) && $line[$i+1] === '/' ) {
                    $i++;
                    $in_comment_ml = false;
                }
                continue;
            }

            if ( $escaped ) { $buf .= $c; $escaped = false; continue; }

            if ( $in_str ) {
                if ( $c === '\\' ) { $buf .= $c; $escaped = true; continue; }
                if ( $c === $str_ch ) { $in_str = false; }
                $buf .= $c;
                continue;
            }

            if ( $c === "'" || $c === '"' ) {
                $in_str = true; $str_ch = $c; $buf .= $c; continue;
            }
            if ( $c === '`' ) {
                $in_str = true; $str_ch = '`'; $buf .= $c; continue;
            }

            if ( $c === '-' && isset( $line[$i+1] ) && $line[$i+1] === '-' ) {
                break;
            }
            if ( $c === '#' ) {
                break;
            }

            if ( $c === '/' && isset( $line[$i+1] ) && $line[$i+1] === '*' ) {
                if ( isset( $line[$i+2] ) && $line[$i+2] === '!' ) {
                    $i += 3;
                    while ( $i < $len && $line[$i] !== ' ' ) $i++;
                    continue;
                }
                $i++;
                $in_comment_ml = true;
                $close = strpos( $line, '*/', $i + 1 );
                if ( $close !== false ) {
                    $i = $close + 1;
                    $in_comment_ml = false;
                }
                continue;
            }

            if ( $c === ';' ) {
                $query = trim( $buf );
                $buf   = '';

                if ( $query !== '' ) {
                    if ( $do_prefix_replace ) {
                        $query = str_replace(
                            array( "CREATE TABLE `{$old_prefix}", "INSERT INTO `{$old_prefix}", "DROP TABLE IF EXISTS `{$old_prefix}", "LOCK TABLES `{$old_prefix}", "ALTER TABLE `{$old_prefix}" ),
                            array( "CREATE TABLE `{$new_prefix}", "INSERT INTO `{$new_prefix}", "DROP TABLE IF EXISTS `{$new_prefix}", "LOCK TABLES `{$new_prefix}", "ALTER TABLE `{$new_prefix}" ),
                            $query
                        );
                    }

                    if ( preg_match( '/^\s*CREATE\s+TABLE\s+[`"\']?([^`"\'\s(]+)/i', $query, $m ) ) {
                        $current_table = trim( $m[1], '`"\'' );
                    }

                    $ok = wpbn_exec_query( $conn, $query, $safe_collation, $current_table );

                    if ( ! $ok ) {
                        $errno = $conn->errno;
                        if ( ! in_array( $errno, array( 1062, 1050 ), true ) ) {
                            $errors[] = "[{$errno}] {$conn->error} → " . substr( $query, 0, 120 );
                            if ( count( $errors ) % 10 === 0 ) wpbn_log( "Query error [{$errno}]: {$conn->error} | {$current_table}" );
                        }
                    } else {
                        $query_count++;
                        if ( preg_match( '/^\s*CREATE\s+TABLE/i', $query ) ) {
                            $table_count++;
                            wpbn_log( "Table ({$table_count}): {$current_table}" );
                        }
                    }

                    $done_queries++;
                    if ( $done_queries >= $queries_per_chunk ) break;
                }
                continue;
            }

            $buf .= $c;
        }

        if ( $done_queries >= $queries_per_chunk ) break;
        $last_query_offset = ftell( $handle );
    }

    $is_done = feof( $handle ) && trim( $buf ) === '';
    if ( feof( $handle ) && trim( $buf ) !== '' ) {
        $query = trim( $buf );
        $ok    = wpbn_exec_query( $conn, $query, $safe_collation, $current_table );
        if ( $ok ) $query_count++;
        $buf     = '';
        $is_done = true;
    }

    $new_offset = $is_done ? $filesize : ftell( $handle );
    if ( feof( $handle ) && ! $is_done ) {
        $is_done    = true;
        $new_offset = $filesize;
    }

    $conn->query( "SET FOREIGN_KEY_CHECKS = 1" );
    $conn->close();
    fclose( $handle );

    $state['offset']        = $new_offset;
    $state['buf']           = $buf;
    $state['in_str']        = $in_str;
    $state['str_ch']        = $str_ch;
    $state['escaped']       = $escaped;
    $state['in_comment_ml'] = $in_comment_ml;
    $state['current_table'] = $current_table;
    $state['query_count']   = $query_count;
    $state['table_count']   = $table_count;
    $state['errors']        = $errors;
    wpbn_state_save( $state );

    $percent = $filesize > 0 ? min( 99, round( $new_offset / $filesize * 100 ) ) : 0;
    if ( $is_done ) $percent = 100;

    if ( $query_count % 100 === 0 && $query_count > 0 ) wpbn_log( "{$query_count} queries, {$table_count} tables..." );

    wpbn_json( array(
        'ok'          => true,
        'done'        => $is_done,
        'percent'     => $percent,
        'query_count' => $query_count,
        'table_count' => $table_count,
        'errors'      => array_slice( $errors, -5 ),
    ) );
}

// ── AJAX: do_replace ─────────────────────────────────────────────────────────
// Cursor-based pagination: WHERE pk > last_pk ORDER BY pk LIMIT 500
// Processes one batch of 500 rows per AJAX call — constant RAM regardless of table size.
function wpbn_ajax_do_replace() {
    global $incomplete_class_count;
    @set_time_limit( 90 );

    $state = wpbn_state_load();
    if ( ! $state ) wpbn_json( array( 'ok' => false, 'error' => 'State not found.' ) );

    $conn = wpbn_db_connect( $state['db_host'], $state['db_user'], $state['db_pass'] );
    if ( ! $conn ) wpbn_json( array( 'ok' => false, 'error' => 'Could not connect to database.' ) );
    $conn->select_db( $state['db_name'] );
    $conn->set_charset( 'utf8mb4' );

    $new_prefix    = $state['new_prefix'];
    $new_url       = $state['new_url'];
    $old_url_vars  = $state['old_url_vars'];
    $new_url_vars  = $state['new_url_vars'];
    $old_path_vars = $state['old_path_vars'];
    $new_path_vars = $state['new_path_vars'];

    // ── First call: set siteurl/home and build table list ─────────────────────
    if ( ! isset( $state['replace_tables'] ) ) {
        $esc = $conn->real_escape_string( $new_url );
        $conn->query( "UPDATE `{$new_prefix}options` SET option_value='{$esc}' WHERE option_name IN ('siteurl','home')" );

        $tables_res = $conn->query( 'SHOW TABLES' );
        $tables = array();
        if ( $tables_res ) while ( $r = $tables_res->fetch_row() ) $tables[] = $r[0];

        $state['replace_tables']     = $tables;
        $state['replace_index']      = 0;
        $state['replace_last_pk']    = null; // cursor: last processed PK value
        $state['replace_table_info'] = null; // cached DESCRIBE result for current table
        $state['replace_updated']    = 0;
        $state['replace_errors']     = array();
        wpbn_state_save( $state );
    }

    $tables       = $state['replace_tables'];
    $table_index  = (int) $state['replace_index'];
    $total_tables = count( $tables );
    $batch_size   = 500;
    $errors       = array();
    $updated      = 0;
    $table        = $tables[ $table_index ] ?? '';

    if ( $table_index < $total_tables ) {

        // ── Get/cache column info for current table ───────────────────────────
        if ( empty( $state['replace_table_info'] ) ) {
            $cols_res  = $conn->query( "DESCRIBE `{$table}`" );
            $text_cols = array();
            $pk        = null;
            if ( $cols_res ) {
                while ( $col = $cols_res->fetch_assoc() ) {
                    if ( preg_match( '/text|varchar|blob|char|json/i', $col['Type'] ) ) {
                        $text_cols[] = $col['Field'];
                    }
                    if ( $col['Key'] === 'PRI' && $pk === null ) {
                        $pk = $col['Field'];
                    }
                }
            }
            $state['replace_table_info'] = array( 'pk' => $pk, 'text_cols' => $text_cols );
        } else {
            $pk        = $state['replace_table_info']['pk'];
            $text_cols = $state['replace_table_info']['text_cols'];
        }

        $last_pk   = $state['replace_last_pk'];
        $table_done = true; // assume done unless we find a full batch

        if ( ! empty( $text_cols ) && $pk ) {
            $sel      = '`' . implode( '`, `', array_merge( array( $pk ), $text_cols ) ) . '`';
            $last_esc = $conn->real_escape_string( $last_pk ?? '' );
            $where    = ( $last_pk !== null ) ? "WHERE `{$pk}` > '{$last_esc}'" : '';
            $rows     = $conn->query( "SELECT {$sel} FROM `{$table}` {$where} ORDER BY `{$pk}` LIMIT {$batch_size}" );

            $row_count = 0;
            if ( $rows ) {
                while ( $row = $rows->fetch_assoc() ) {
                    $pk_val  = $row[ $pk ];
                    $last_pk = $pk_val;
                    $row_count++;
                    $vals = array();
                    $chg  = false;
                    foreach ( $text_cols as $col ) {
                        $v = $row[ $col ] ?? '';
                        if ( ! empty( $old_path_vars ) ) {
                            foreach ( $old_path_vars as $i => $op ) {
                                $np = $new_path_vars[ $i ] ?? ( $new_path_vars[0] ?? '' );
                                if ( $np === '' || $op === $np ) continue;
                                $v = wpbn_safe_unserialize_replace( array( $op ), array( $np ), $v );
                            }
                        }
                        $v      = wpbn_safe_unserialize_replace( $old_url_vars, $new_url_vars, $v );
                        $vals[] = $v;
                        if ( $v !== $row[ $col ] ) $chg = true;
                    }
                    if ( $chg ) {
                        $sets = array();
                        foreach ( $text_cols as $col ) $sets[] = "`{$col}` = ?";
                        $stmt = $conn->prepare( "UPDATE `{$table}` SET " . implode( ', ', $sets ) . " WHERE `{$pk}` = ?" );
                        if ( $stmt ) {
                            $params = array_merge( $vals, array( $pk_val ) );
                            $types  = str_repeat( 's', count( $params ) );
                            $stmt->bind_param( $types, ...$params );
                            if ( $stmt->execute() ) $updated++;
                            else $errors[] = "UPDATE [{$table}#{$pk_val}]: " . $stmt->error;
                            $stmt->close();
                        }
                    }
                }
                // Full batch means there may be more rows
                if ( $row_count === $batch_size ) $table_done = false;
            }
        }

        $state['replace_last_pk'] = $last_pk;
        $state['replace_updated'] = (int) $state['replace_updated'] + $updated;
        if ( ! empty( $errors ) ) {
            $state['replace_errors'] = array_merge( $state['replace_errors'], array_slice( $errors, 0, 20 ) );
        }

        if ( $table_done ) {
            // Advance to next table, reset cursor
            $state['replace_index']      = $table_index + 1;
            $state['replace_last_pk']    = null;
            $state['replace_table_info'] = null;
        }
    }

    $done = ( (int) $state['replace_index'] >= $total_tables );

    if ( $done ) {
        $cfg = WPBN_ROOT . '/wp-config.php';
        if ( file_exists( $cfg ) ) {
            $c = file_get_contents( $cfg );
            $c = preg_replace( "/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/",     "define( 'DB_NAME', '"     . addslashes( $state['db_name'] ) . "' )", $c );
            $c = preg_replace( "/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/",     "define( 'DB_USER', '"     . addslashes( $state['db_user'] ) . "' )", $c );
            $c = preg_replace( "/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/", "define( 'DB_PASSWORD', '" . addslashes( $state['db_pass'] ) . "' )", $c );
            $c = preg_replace( "/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)/",     "define( 'DB_HOST', '"     . addslashes( $state['db_host'] ) . "' )", $c );
            $c = preg_replace( "/\\\$table_prefix\s*=\s*['\"][^'\"]*['\"];/",
                "\$table_prefix = '" . addslashes( $state['new_prefix'] ) . "';" , $c );
            file_put_contents( $cfg, $c );
        }

        $total_updated = (int) $state['replace_updated'];
        $all_errors    = $state['replace_errors'];

        unset( $state['replace_tables'], $state['replace_index'], $state['replace_last_pk'],
               $state['replace_table_info'], $state['replace_updated'], $state['replace_errors'] );
        wpbn_state_save( $state );

        $conn->close();
        wpbn_log( "Replace completed. {$total_updated} rows. {$incomplete_class_count} incomplete classes skipped." );

        wpbn_json( array(
            'ok'      => true,
            'done'    => true,
            'updated' => $total_updated,
            'errors'  => $all_errors,
            'skipped' => $incomplete_class_count,
            'percent' => 100,
        ) );
    } else {
        wpbn_state_save( $state );
        $conn->close();
        $percent = $total_tables > 0 ? round( (int) $state['replace_index'] / $total_tables * 100 ) : 0;
        wpbn_json( array(
            'ok'      => true,
            'done'    => false,
            'table'   => $table,
            'index'   => (int) $state['replace_index'],
            'total'   => $total_tables,
            'updated' => (int) $state['replace_updated'],
            'percent' => $percent,
        ) );
    }
}

function wpbn_path_diff_needed( $main_old, $main_new, $old, $new ) {
    $main_old = rtrim( str_replace( '\\', '/', $main_old ), '/' );
    $main_new = rtrim( str_replace( '\\', '/', $main_new ), '/' );
    $old      = rtrim( str_replace( '\\', '/', $old ), '/' );
    $new      = rtrim( str_replace( '\\', '/', $new ), '/' );
    $rel_old  = ( strpos( $old, $main_old ) === 0 ) ? substr( $old, strlen( $main_old ) ) : $old;
    $rel_new  = ( strpos( $new, $main_new ) === 0 ) ? substr( $new, strlen( $main_new ) ) : $new;
    return ( $rel_old !== $rel_new );
}

// ── AJAX: init_import ─────────────────────────────────────────────────────────
function wpbn_ajax_init_import() {
    $post = $_POST;

    $db_host    = trim( $post['db_host']   ?? '' );
    $db_user    = trim( $post['db_user']   ?? '' );
    $db_pass    =       $post['db_pass']   ?? '';
    $db_name    = trim( $post['db_name']   ?? '' );
    $new_prefix = trim( $post['db_prefix'] ?? 'wp_' );
    $new_url    = rtrim( trim( $post['new_url'] ?? '' ), '/' );

    $enc_password      = $post['enc_password'] ?? '';
    $manifest          = wpbn_manifest();
    $manifest_prefix   = $manifest['db_prefix']  ?? 'wp_';
    $manifest_db_file  = $manifest['db_file']    ?? 'database.sql';
    $is_encrypted      = ! empty( $manifest['encrypted'] );
    $db_sql_file       = WPBN_ROOT . '/' . $manifest_db_file;

    if ( ! $db_host || ! $db_user || ! $db_name || ! $new_url ) {
        wpbn_json( array( 'ok' => false, 'error' => 'Missing required fields.' ) );
    }

    // ── Streaming decrypt (AES-256-CBC chunk by chunk — no full-file RAM load) ──
    if ( $is_encrypted ) {
        if ( $enc_password === '' ) {
            wpbn_json( array( 'ok' => false, 'error' => 'This backup is encrypted. Please enter the encryption password.' ) );
        }
        if ( ! extension_loaded( 'openssl' ) ) {
            wpbn_json( array( 'ok' => false, 'error' => 'OpenSSL PHP extension is required to decrypt this backup.' ) );
        }
        if ( ! file_exists( $db_sql_file ) ) {
            wpbn_json( array( 'ok' => false, 'error' => 'Encrypted database file not found: ' . $manifest_db_file ) );
        }
        $key        = hash( 'sha256', $enc_password, true );
        $out_file   = WPBN_ROOT . '/database.sql';
        $fh_in      = fopen( $db_sql_file, 'rb' );
        $fh_out     = fopen( $out_file, 'wb' );
        if ( ! $fh_in || ! $fh_out ) {
            wpbn_json( array( 'ok' => false, 'error' => 'Could not open encrypted database file.' ) );
        }
        $iv         = fread( $fh_in, 16 );
        $chunk_size = 1024 * 1024; // 1 MB — must be multiple of 16
        $ok_dec     = true;
        while ( ! feof( $fh_in ) ) {
            $cipher_chunk = fread( $fh_in, $chunk_size );
            if ( $cipher_chunk === '' || $cipher_chunk === false ) break;
            $is_last = feof( $fh_in );
            $flags   = $is_last ? OPENSSL_RAW_DATA : OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING;
            $plain   = openssl_decrypt( $cipher_chunk, 'AES-256-CBC', $key, $flags, $iv );
            if ( $plain === false ) { $ok_dec = false; break; }
            $iv = substr( $cipher_chunk, -16 );
            fwrite( $fh_out, $plain );
        }
        fclose( $fh_in );
        fclose( $fh_out );
        if ( ! $ok_dec ) {
            @unlink( $out_file );
            wpbn_json( array( 'ok' => false, 'error' => 'Decryption failed. Wrong password or corrupted backup.' ) );
        }
        $db_sql_file = $out_file;
        wpbn_log( 'Encrypted backup decrypted successfully (streaming).' );
    }

    // ── Decrypt wp-config.php.enc if present ──────────────────────────────────
    $wpc_enc = WPBN_ROOT . '/wp-config.php.enc';
    if ( file_exists( $wpc_enc ) && $enc_password !== '' && extension_loaded( 'openssl' ) ) {
        $wpc_content = @file_get_contents( $wpc_enc );
        if ( $wpc_content !== false && strlen( $wpc_content ) > 16 ) {
            $wpc_key   = hash( 'sha256', $enc_password, true );
            $wpc_plain = openssl_decrypt( substr( $wpc_content, 16 ), 'AES-256-CBC', $wpc_key, OPENSSL_RAW_DATA, substr( $wpc_content, 0, 16 ) );
            unset( $wpc_content );
            if ( $wpc_plain !== false ) {
                file_put_contents( WPBN_ROOT . '/wp-config.php', $wpc_plain );
                unset( $wpc_plain );
                @unlink( $wpc_enc );
                wpbn_log( 'wp-config.php decrypted successfully.' );
            }
        }
    }

    if ( ! file_exists( $db_sql_file ) ) {
        wpbn_json( array( 'ok' => false, 'error' => 'database.sql not found.' ) );
    }

    // ── DB connect + graceful CREATE DATABASE ─────────────────────────────────
    $conn = wpbn_db_connect( $db_host, $db_user, $db_pass );
    if ( ! $conn ) wpbn_json( array( 'ok' => false, 'error' => 'Database connection failed. Check your credentials.' ) );

    // Try selecting the DB first (already exists on most shared hosts)
    if ( ! $conn->select_db( $db_name ) ) {
        // DB doesn't exist — try to create it
        $conn->query( "CREATE DATABASE `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
        if ( ! $conn->select_db( $db_name ) ) {
            $conn->close();
            wpbn_json( array( 'ok' => false, 'error' => "Database \"{$db_name}\" does not exist and could not be created automatically (insufficient privileges). Please create it first via cPanel / phpMyAdmin, then try again." ) );
        }
    }
    $conn->set_charset( 'utf8mb4' );

    $safe_collation = wpbn_safe_collation( $conn );
    wpbn_log( "Import started. Collation: {$safe_collation}" );
    $conn->close();

    $manifest_old_url   = rtrim( $manifest['site_url'] ?? $manifest['original_site_url'] ?? '', '/' );
    $manifest_old_paths = $manifest['old_paths'] ?? $manifest['old_file_paths'] ?? array();
    $new_path           = rtrim( WPBN_ROOT, '/\\' );
    $old_url_vars       = wpbn_url_variations( $manifest_old_url );
    $new_url_vars       = wpbn_url_variations( $new_url );

    $old_path_vars = array();
    foreach ( $manifest_old_paths as $p ) {
        $p_clean = rtrim( str_replace( '\\', '/', $p ), '/' );
        $first_old_path = rtrim( str_replace( '\\', '/', reset( $manifest_old_paths ) ?: '' ), '/' );
        if ( $p_clean !== $first_old_path && ! wpbn_path_diff_needed( $first_old_path, $new_path, $p_clean, $new_path ) ) {
            continue;
        }
        $old_path_vars = array_merge( $old_path_vars, wpbn_path_variations( rtrim( $p, '/\\' ) ) );
    }
    $new_path_vars = wpbn_path_variations( $new_path );

    $state = array(
        'db_host'        => $db_host,
        'db_user'        => $db_user,
        'db_pass'        => $db_pass,
        'db_name'        => $db_name,
        'old_prefix'     => $manifest_prefix,
        'new_prefix'     => $new_prefix,
        'new_url'        => $new_url,
        'sql_file'       => $db_sql_file,
        'offset'         => 0,
        'buf'            => '',
        'current_table'  => '',
        'query_count'    => 0,
        'table_count'    => 0,
        'errors'         => array(),
        'safe_collation' => $safe_collation,
        'old_url_vars'   => $old_url_vars,
        'new_url_vars'   => $new_url_vars,
        'old_path_vars'  => $old_path_vars,
        'new_path_vars'  => $new_path_vars,
        'filesize'       => filesize( $db_sql_file ),
        'manifest_old_url' => $manifest_old_url,
    );
    wpbn_state_save( $state );

    $url_same  = ( rtrim( $manifest_old_url, '/' ) === rtrim( $new_url, '/' ) );
    $first_old = rtrim( str_replace( '\\', '/', reset( $manifest_old_paths ) ?: '' ), '/' );
    $path_same = ( $first_old === '' || $first_old === rtrim( str_replace( '\\', '/', $new_path ), '/' ) );

    wpbn_json( array(
        'ok'             => true,
        'filesize'       => $state['filesize'],
        'filesize_hr'    => round( $state['filesize'] / 1048576, 1 ) . ' MB',
        'replace_needed' => ! ( $url_same && $path_same ),
    ) );
}

// ── AJAX: cleanup ─────────────────────────────────────────────────────────────
function wpbn_ajax_cleanup() {
    $state    = wpbn_state_load();
    $sql      = $state['sql_file'] ?? ( WPBN_ROOT . '/database.sql' );
    $del_zip  = ! empty( $_POST['del_zip'] );

    @unlink( WPBN_ROOT . '/' . basename( $_SERVER['SCRIPT_FILENAME'] ?? '' ) );
    @unlink( WPBN_MANIFEST );
    @unlink( WPBN_CONFIG );
    @unlink( $sql );
    @unlink( WPBN_ROOT . '/database.sql' );
    @unlink( WPBN_ROOT . '/database.sql.enc' );
    @unlink( WPBN_ROOT . '/wp-config.php.enc' );
    @unlink( WPBN_LOG );
    @unlink( WPBN_STATE );
    @unlink( WPBN_ROOT . '/.wpbn_csrf_token' );
    if ( $del_zip ) {
        @unlink( WPBN_ZIP_PATH );
    }
    wpbn_json( array( 'ok' => true ) );
}

// ── AJAX: test_db ─────────────────────────────────────────────────────────────
function wpbn_ajax_test_db() {
    $host = trim( $_POST['db_host'] ?? '' );
    $user = trim( $_POST['db_user'] ?? '' );
    $pass =       $_POST['db_pass'] ?? '';
    $name = trim( $_POST['db_name'] ?? '' );

    if ( ! $host || ! $user || ! $name ) {
        wpbn_json( array( 'ok' => false, 'error' => 'Fill in host, username and database name first.' ) );
    }
    $conn = wpbn_db_connect( $host, $user, $pass );
    if ( ! $conn ) {
        wpbn_json( array( 'ok' => false, 'error' => 'Connection failed. Check your credentials.' ) );
    }
    $can_select = $conn->select_db( $name );
    $ver = $conn->server_info;
    $conn->close();

    if ( $can_select ) {
        wpbn_json( array( 'ok' => true, 'message' => 'Connected to "' . $name . '" successfully. (MySQL ' . $ver . ')' ) );
    } else {
        wpbn_json( array( 'ok' => true, 'warning' => 'Connected to server (MySQL ' . $ver . '), but database "' . $name . '" does not exist yet — it will be created automatically during installation.' ) );
    }
}

// ── AJAX: view_log ─────────────────────────────────────────────────────────────
function wpbn_ajax_view_log() {
    if ( ! file_exists( WPBN_LOG ) ) {
        wpbn_json( array( 'ok' => true, 'content' => '(Log file not found or empty)' ) );
    }
    $content = file_get_contents( WPBN_LOG );
    wpbn_json( array( 'ok' => true, 'content' => $content ?: '(empty)' ) );
}

// ── CSRF TOKEN ────────────────────────────────────────────────────────────────
function wpbn_csrf_token() {
    $token_file = WPBN_ROOT . '/.wpbn_csrf_token';
    if ( file_exists( $token_file ) ) {
        return trim( file_get_contents( $token_file ) );
    }
    $token = bin2hex( random_bytes( 32 ) );
    file_put_contents( $token_file, $token );
    return $token;
}

function wpbn_verify_csrf() {
    $token  = $_POST['_csrf'] ?? '';
    $stored = wpbn_csrf_token();
    if ( ! $token || ! hash_equals( $stored, $token ) ) {
        wpbn_json( array( 'ok' => false, 'error' => 'Security check failed. Refresh the page.' ) );
    }
}

// ── AJAX ROUTING ──────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
if ( $action !== '' ) {
    header( 'X-Frame-Options: DENY' );
    header( 'X-Content-Type-Options: nosniff' );
    wpbn_verify_csrf();
}
if ( $action === 'extract_zip'  ) wpbn_ajax_extract_zip();
if ( $action === 'init_import'  ) wpbn_ajax_init_import();
if ( $action === 'import_chunk' ) wpbn_ajax_import_chunk();
if ( $action === 'do_replace'   ) wpbn_ajax_do_replace();
if ( $action === 'cleanup'      ) wpbn_ajax_cleanup();
if ( $action === 'test_db'      ) wpbn_ajax_test_db();
if ( $action === 'view_log'     ) wpbn_ajax_view_log();

// ── PAGE STATE DETECTION ──────────────────────────────────────────────────────
header( 'X-Frame-Options: DENY' );
header( 'X-Content-Type-Options: nosniff' );

$zip_exists      = file_exists( WPBN_ZIP_PATH );
$manifest_exists = file_exists( WPBN_MANIFEST ) || file_exists( WPBN_CONFIG );
$needs_extract   = ! $manifest_exists && $zip_exists;
$nothing_found   = ! $manifest_exists && ! $zip_exists;

$manifest        = wpbn_manifest();
$manifest_prefix = $manifest['db_prefix'] ?? 'wp_';
$manifest_db     = $manifest['db_file']   ?? 'database.sql';
$db_sql_file     = WPBN_ROOT . '/' . $manifest_db;
$manifest_old_url = rtrim( $manifest['site_url'] ?? $manifest['original_site_url'] ?? '', '/' );
$is_encrypted    = ! empty( $manifest['encrypted'] );
$manifest_db_f   = $manifest['db_file'] ?? 'database.sql';
$auto_proto      = ( ( ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ) || ( ( $_SERVER['SERVER_PORT'] ?? 80 ) == 443 ) ) ? 'https' : 'http';
$auto_new_url    = $auto_proto . '://' . preg_replace( '/[^a-zA-Z0-9\-\.\:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost' );
$db_sql_size_hr  = file_exists( $db_sql_file ) ? round( filesize( $db_sql_file ) / 1048576, 1 ) . ' MB' : '—';
$zip_size_hr     = $zip_exists ? round( filesize( WPBN_ZIP_PATH ) / 1048576, 1 ) . ' MB' : '—';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nota Backup — Installer</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f0f2f5;color:#1d2327;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:30px 16px}
.card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.1);width:100%;max-width:660px;margin-top:20px;overflow:hidden}
.card-header{background:linear-gradient(135deg,#1a6098 0%,#2271b1 100%);padding:22px 32px 18px}
.card-header h1{font-size:1.3rem;color:#fff;margin-bottom:2px;display:flex;align-items:center;gap:8px}
.card-header .sub{color:rgba(255,255,255,.78);font-size:.84rem}
.card-body{padding:28px 32px 32px}
.notice{padding:11px 15px;border-radius:7px;margin-bottom:14px;font-size:.87rem;line-height:1.6}
.notice.error{background:#fce8e8;border-left:4px solid #d63638;color:#6b0000}
.notice.success{background:#edfaef;border-left:4px solid #00a32a;color:#004708}
.notice.warning{background:#fff8e5;border-left:4px solid #dba617;color:#5c3d02}
.notice.info{background:#f0f6fc;border-left:4px solid #2271b1;color:#0a3d62}
table.info{width:100%;border-collapse:collapse;font-size:.85rem;margin-bottom:14px;border-radius:7px;overflow:hidden;border:1px solid #f0f0f1}
table.info td{padding:7px 12px;border-bottom:1px solid #f0f0f1}
table.info tr:last-child td{border-bottom:none}
table.info td:first-child{color:#646970;width:36%;font-weight:500;background:#fafafa}
label{display:block;font-size:.87rem;font-weight:600;margin-bottom:4px;margin-top:10px}
input[type=text],input[type=password],input[type=url]{width:100%;padding:9px 12px;border:1px solid #8c8f94;border-radius:6px;font-size:.93rem}
input:focus{outline:none;border-color:#2271b1;box-shadow:0 0 0 2px rgba(34,113,177,.15)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:10px}
hr{border:none;border-top:1px solid #f0f0f1;margin:16px 0}
.btn{display:inline-flex;align-items:center;gap:7px;padding:11px 22px;background:#2271b1;color:#fff;border:none;border-radius:7px;font-size:.93rem;font-weight:600;cursor:pointer;margin-top:14px}
.btn:hover{background:#135e96}
.btn:disabled{background:#a7aebc;cursor:not-allowed}
.btn.danger{background:#d63638} .btn.danger:hover{background:#b32d2e}
.btn.success{background:#00a32a} .btn.success:hover{background:#007a1f}
.wpbn-steps{display:flex;align-items:flex-start;margin-bottom:24px}
.wpbn-step{display:flex;flex-direction:column;align-items:center;gap:5px;flex:1;min-width:0}
.wpbn-step-num{width:30px;height:30px;border-radius:50%;background:#e2e5e9;color:#9ca3af;font-size:.8rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .3s,color .3s}
.wpbn-step.active .wpbn-step-num{background:#2271b1;color:#fff;box-shadow:0 0 0 3px rgba(34,113,177,.18)}
.wpbn-step.done .wpbn-step-num{background:#00a32a;color:#fff}
.wpbn-step-label{font-size:.71rem;color:#9ca3af;text-align:center;font-weight:500;white-space:nowrap}
.wpbn-step.active .wpbn-step-label{color:#2271b1;font-weight:700}
.wpbn-step.done .wpbn-step-label{color:#00a32a;font-weight:600}
.wpbn-step-line{flex:1;height:2px;background:#e2e5e9;margin-top:14px;flex-shrink:1;transition:background .3s}
.wpbn-step-line.done{background:#00a32a}
#progress-wrap{display:none;margin-top:20px}
.prog-phase-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px}
.prog-phase-label{font-size:.85rem;font-weight:600;color:#2271b1}
.prog-pct{font-size:.83rem;color:#646970;font-weight:600}
.prog-bar-outer{background:#e2e5e9;border-radius:8px;height:20px;overflow:hidden;margin-bottom:8px}
.prog-bar-inner{background:linear-gradient(90deg,#2271b1,#38a8d8);height:100%;border-radius:8px;transition:width .3s ease;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.73rem;font-weight:700;min-width:34px}
.prog-status{font-size:.83rem;color:#646970;min-height:20px}
.err-list{background:#fff8f8;border:1px solid #fcc;border-radius:6px;padding:10px;font-size:.78rem;font-family:monospace;max-height:160px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;margin-top:8px}
#result-box{display:none}
.next{background:#f8f8f8;border-radius:8px;padding:14px 18px;font-size:.86rem;line-height:2;margin:14px 0}
#extract-wrap{margin-top:8px}
.spinner{display:inline-block;width:16px;height:16px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle}
@keyframes spin{to{transform:rotate(360deg)}}
.wpbn-preflight{display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:10px 14px;background:#f8f9fa;border-radius:7px;margin-bottom:18px;font-size:.79rem;border:1px solid #e5e7eb}
.wpbn-pf-item{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:20px;font-weight:600;white-space:nowrap}
.wpbn-pf-item.ok{background:#edfaef;color:#004708}
.wpbn-pf-item.fail{background:#fce8e8;color:#6b0000;cursor:help}
.wpbn-pf-warn{color:#5c3d02;font-weight:600;font-size:.8rem}
.wpbn-preflight.has-error{background:#fff8e5;border-color:#dba617}
.btn-secondary{background:#475569} .btn-secondary:hover{background:#334155}
@media(max-width:480px){.card-body,.card-header{padding-left:18px;padding-right:18px}.row{grid-template-columns:1fr}.wpbn-step-label{font-size:.63rem}}
</style>
</head>
<body>
<div class="card">
<div class="card-header">
 <h1>&#x1F504; Nota Backup Installer</h1>
 <p class="sub">Migration wizard &mdash; upload installer + ZIP to new server, then run</p>
</div>
<div class="card-body">

<?php if ( ! $nothing_found ): ?>
<div class="wpbn-steps" id="wpbn-steps">
 <div class="wpbn-step <?php echo $needs_extract ? 'active' : 'done'; ?>" id="wpbn-step-1">
  <div class="wpbn-step-num"><?php echo $needs_extract ? '1' : '&#x2713;'; ?></div>
  <div class="wpbn-step-label">Extract</div>
 </div>
 <div class="wpbn-step-line <?php echo $needs_extract ? '' : 'done'; ?>" id="wpbn-stepline-1"></div>
 <div class="wpbn-step <?php echo ( ! $needs_extract ) ? 'active' : ''; ?>" id="wpbn-step-2">
  <div class="wpbn-step-num">2</div>
  <div class="wpbn-step-label">Configure</div>
 </div>
 <div class="wpbn-step-line" id="wpbn-stepline-2"></div>
 <div class="wpbn-step" id="wpbn-step-3">
  <div class="wpbn-step-num">3</div>
  <div class="wpbn-step-label">Migrate</div>
 </div>
 <div class="wpbn-step-line" id="wpbn-stepline-3"></div>
 <div class="wpbn-step" id="wpbn-step-4">
  <div class="wpbn-step-num">4</div>
  <div class="wpbn-step-label">Done</div>
 </div>
</div>
<?php endif; ?>

<?php
$pf_checks = array(
    array( 'PHP ' . PHP_VERSION,    version_compare( PHP_VERSION, '7.4', '>=' ),  'PHP >= 7.4 required' ),
    array( 'ZipArchive',            extension_loaded( 'zip' ),                    'Required for ZIP extraction' ),
    array( 'mysqli',                extension_loaded( 'mysqli' ),                 'Required for database import' ),
    array( 'OpenSSL',               extension_loaded( 'openssl' ),                'Required for encrypted backups' ),
    array( 'Write access',          is_writable( WPBN_ROOT ),                     'Installer needs write access to this directory' ),
);
$pf_has_error = (bool) array_filter( $pf_checks, function( $c ) { return ! $c[1]; } );
?>
<div class="wpbn-preflight<?php echo $pf_has_error ? ' has-error' : ''; ?>">
 <?php foreach ( $pf_checks as $c ): ?>
 <span class="wpbn-pf-item <?php echo $c[1] ? 'ok' : 'fail'; ?>"<?php echo ! $c[1] ? ' title="' . wpbn_esc( $c[2] ) . '"' : ''; ?>>
  <?php echo $c[1] ? '&#x2713;' : '&#x2717;'; ?> <?php echo wpbn_esc( $c[0] ); ?>
 </span>
 <?php endforeach; ?>
 <?php if ( $pf_has_error ): ?>
 <span class="wpbn-pf-warn">&#x26A0; Hover over red items for details</span>
 <?php endif; ?>
</div>

<?php if ( $nothing_found ): ?>
<div class="notice error">
 &#x274C; <strong>Files not found.</strong><br>
 Expected ZIP: <code><?php echo wpbn_esc( WPBN_ZIP_FILE ); ?></code><br>
 Make sure both <strong><?php echo wpbn_esc( basename( $_SERVER['SCRIPT_FILENAME'] ?? 'installer.php' ) ); ?></strong> and <strong><?php echo wpbn_esc( WPBN_ZIP_FILE ); ?></strong> are uploaded to the same directory.
</div>

<?php elseif ( $needs_extract ): ?>
<div class="notice info">
 &#x1F4E6; <strong>Backup ZIP found.</strong> Extract it to continue.<br>
 <small><?php echo wpbn_esc( WPBN_ZIP_FILE ); ?> &mdash; <?php echo wpbn_esc( $zip_size_hr ); ?></small>
</div>
<div class="notice warning">&#x26A0;&#xFE0F; This will extract all WordPress files to: <code><?php echo wpbn_esc( WPBN_ROOT ); ?></code></div>
<div id="extract-wrap">
 <button class="btn success" id="extract-btn">&#x1F4E6; Extract Backup ZIP</button>
 <div id="extract-progress" style="display:none;margin-top:14px">
  <div class="notice info"><span class="spinner"></span> &nbsp;Extracting, please wait&hellip; This may take a few minutes for large sites.</div>
 </div>
 <div id="extract-error" style="display:none"></div>
</div>

<?php else: ?>

<?php if ( $manifest ): ?>
<table class="info">
 <tr><td>Source Site</td><td><?php echo wpbn_esc( $manifest_old_url ?: '&mdash;' ); ?></td></tr>
 <tr><td>Backup Date</td><td><?php echo wpbn_esc( $manifest['created'] ?? '&mdash;' ); ?></td></tr>
 <tr><td>WP Version</td><td><?php echo wpbn_esc( $manifest['wp_version'] ?? '&mdash;' ); ?></td></tr>
 <tr><td>Table Prefix</td><td><code><?php echo wpbn_esc( $manifest_prefix ); ?></code></td></tr>
 <tr><td>Database Size</td><td><?php echo wpbn_esc( $db_sql_size_hr ); ?></td></tr>
</table>
<?php else: ?>
<div class="notice warning">&#x26A0;&#xFE0F; Manifest file not found. Enter table prefix manually.</div>
<?php endif; ?>

<div class="notice warning">&#x26A0;&#xFE0F; This operation will <strong>completely drop and recreate</strong> the specified database.</div>

<div id="form-wrap">
 <label>New Site URL <span style="color:#d63638">*</span></label>
 <input type="url" id="f_new_url" required placeholder="https://newsite.com" value="<?php echo wpbn_esc( $auto_new_url ); ?>">

 <hr>

 <label>Database Host <span style="color:#d63638">*</span></label>
 <input type="text" id="f_db_host" value="localhost" required>

 <div class="row">
  <div>
   <label>Username <span style="color:#d63638">*</span></label>
   <input type="text" id="f_db_user" required>
  </div>
  <div>
   <label>Password</label>
   <input type="password" id="f_db_pass">
  </div>
 </div>

 <div class="row">
  <div>
   <label>Database Name <span style="color:#d63638">*</span></label>
   <input type="text" id="f_db_name" required>
  </div>
  <div>
   <label>Table Prefix</label>
   <input type="text" id="f_db_prefix" value="<?php echo wpbn_esc( $manifest_prefix ); ?>">
  </div>
 </div>

 <div style="margin-top:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
  <button type="button" class="btn btn-secondary" id="test-db-btn" style="margin-top:0;padding:8px 16px;font-size:.83rem">&#x1F50C; Test Connection</button>
  <span id="test-db-result" style="font-size:.83rem"></span>
 </div>

 <?php if ( $is_encrypted ): ?>
 <hr>
 <div class="notice warning">&#x1F512; <strong>This backup is encrypted.</strong> Enter the password you used when creating this backup.</div>
 <label>Encryption Password <span style="color:#d63638">*</span></label>
 <input type="password" id="f_enc_password" autocomplete="off" placeholder="Enter backup encryption password">
 <div style="font-size:.8rem;color:#d63638;margin-top:4px;">&#x26A0;&#xFE0F; Without the correct password this backup cannot be restored.</div>
 <?php endif; ?>

 <button class="btn" id="start-btn" <?php echo file_exists( WPBN_ROOT . '/database.sql' ) || file_exists( WPBN_ROOT . '/' . $manifest_db_f ) ? '' : 'disabled'; ?>>&#x25B6; Start Installation</button>
</div>

<div id="progress-wrap">
 <div class="prog-phase-row">
  <span class="prog-phase-label" id="prog-phase-label">&#x23F3; Importing database&hellip;</span>
  <span class="prog-pct" id="prog-pct">0%</span>
 </div>
 <div class="prog-bar-outer"><div class="prog-bar-inner" id="prog-bar" style="width:0%">0%</div></div>
 <div class="prog-status" id="prog-status">Starting&hellip;</div>
</div>

<div id="result-box">
 <div id="result-notice" class="notice"></div>
 <div class="notice info" id="result-stats" style="font-size:.84rem"></div>
 <div id="result-errors"></div>
 <div class="next">
  <strong>Next steps:</strong><br>
  1. <a id="link-site" href="#" target="_blank">Visit your site</a><br>
  2. <a id="link-admin" href="#" target="_blank">Log into wp-admin</a><br>
  3. Settings &rarr; Permalinks &rarr; Save (refreshes .htaccess)<br>
  4. Delete installer files using the button below
 </div>
 <label style="font-weight:400;margin-top:10px">
  <input type="checkbox" id="del-zip-check" checked> Also delete the backup ZIP file (<?php echo wpbn_esc( WPBN_ZIP_FILE ); ?>)
 </label>
 <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px">
  <button class="btn danger" id="cleanup-btn" style="margin-top:0">&#x1F5D1; Delete Installer Files &amp; Finish</button>
  <button type="button" class="btn btn-secondary" id="view-log-btn" style="margin-top:0;padding:8px 16px;font-size:.83rem">&#x1F4CB; View Log</button>
 </div>
 <div id="log-content" class="err-list" style="display:none;margin-top:10px"></div>
</div>

<?php endif; ?>

<script>
(function(){
  var self_url = location.href.split('?')[0];
  var csrfToken = <?php echo json_encode( wpbn_csrf_token() ); ?>;

  function post(data, ok, fail) {
    data._csrf = csrfToken;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', self_url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
      try { ok(JSON.parse(xhr.responseText)); }
      catch(e) { fail('JSON parse error: ' + xhr.responseText.substring(0,200)); }
    };
    xhr.onerror = function() { fail('Network error.'); };
    var parts = [];
    for (var k in data) parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
    xhr.send(parts.join('&'));
  }

  // ── Extract ZIP ─────────────────────────────────────────────────────────────
  var extractBtn = document.getElementById('extract-btn');
  if (extractBtn) {
    extractBtn.addEventListener('click', function() {
      extractBtn.disabled = true;
      document.getElementById('extract-progress').style.display = 'block';
      document.getElementById('extract-error').style.display = 'none';
      post({ action: 'extract_zip' }, function(res) {
        if (res.ok) {
          location.reload();
        } else {
          document.getElementById('extract-progress').style.display = 'none';
          var el = document.getElementById('extract-error');
          el.className = 'notice error';
          el.innerHTML = '&#x274C; ' + res.error;
          el.style.display = 'block';
          extractBtn.disabled = false;
        }
      }, function(err) {
        document.getElementById('extract-progress').style.display = 'none';
        var el = document.getElementById('extract-error');
        el.className = 'notice error';
        el.innerHTML = '&#x274C; Connection error: ' + err;
        el.style.display = 'block';
        extractBtn.disabled = false;
      });
    });
  }

  // ── Migration form ──────────────────────────────────────────────────────────
  function setProgress(pct, status) {
    var bar = document.getElementById('prog-bar');
    if (!bar) return;
    bar.style.width = pct + '%';
    bar.textContent = pct + '%';
    var pctEl = document.getElementById('prog-pct');
    if (pctEl) pctEl.textContent = pct + '%';
    document.getElementById('prog-status').textContent = status;
  }

  function setStep(n) {
    for (var i = 1; i <= 4; i++) {
      var el = document.getElementById('wpbn-step-' + i);
      var line = document.getElementById('wpbn-stepline-' + i);
      if (!el) continue;
      el.classList.remove('active', 'done');
      var numEl = el.querySelector('.wpbn-step-num');
      if (i < n) {
        el.classList.add('done');
        if (numEl) numEl.innerHTML = '&#x2713;';
        if (line) line.classList.add('done');
      } else if (i === n) {
        el.classList.add('active');
        if (numEl) numEl.textContent = String(i);
        if (line) line.classList.remove('done');
      } else {
        if (numEl) numEl.textContent = String(i);
        if (line) line.classList.remove('done');
      }
    }
  }

  function showError(msg, highlightDb) {
    document.getElementById('progress-wrap').style.display = 'none';
    document.querySelectorAll('.wpbn-installer-error').forEach(function(el){ el.remove(); });
    var n = document.createElement('div');
    n.className = 'notice error wpbn-installer-error';
    n.innerHTML = '&#x274C; <strong>Error:</strong> ' + msg;
    document.getElementById('form-wrap').after(n);
    if (highlightDb) {
      document.getElementById('form-wrap').style.display = 'block';
      ['f_db_host','f_db_user','f_db_pass','f_db_name'].forEach(function(id){
        var el = document.getElementById(id);
        if (el) { el.style.borderColor = '#d63638'; el.addEventListener('input', function(){ this.style.borderColor = ''; }, { once: true }); }
      });
      var firstField = document.getElementById('f_db_host');
      if (firstField) firstField.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  var retries = 0;
  var allErrors = [];

  function doChunk(replaceNeeded) {
    post({ action: 'import_chunk' }, function(res) {
      if (!res.ok) { showError(res.error || 'Unknown error'); return; }
      retries = 0;
      if (res.errors && res.errors.length) allErrors = allErrors.concat(res.errors);
      setProgress(res.percent, '&#x23F3; Importing SQL — ' + res.query_count + ' queries, ' + res.table_count + ' tables');
      if (res.done) {
        if (!replaceNeeded) {
          document.getElementById('prog-phase-label').textContent = '✅ Import completed';
          setProgress(100, 'Replace step skipped because URL and path are unchanged.');
          showResult(res.query_count, res.table_count, { ok: true, updated: 0, skipped: 0, url_skipped: true });
        } else {
          doReplace(res.query_count, res.table_count);
        }
      } else {
        setTimeout(function(){ doChunk(replaceNeeded); }, 50);
      }
    }, function(err) {
      if (retries < 3) { retries++; setTimeout(function(){ doChunk(replaceNeeded); }, 2000); }
      else showError('Connection error (import_chunk): ' + err);
    });
  }

  function doReplace(qCount, tCount, replaceRetries) {
    replaceRetries = replaceRetries || 0;
    if (replaceRetries === 0) {
      document.getElementById('prog-phase-label').textContent = '⏳ Performing URL/path replacement…';
      setProgress(2, 'Database import complete. Starting table-by-table replacement…');
    }
    post({ action: 'do_replace' }, function(res) {
      if (!res.ok) { showError(res.error || 'Replace error'); return; }
      if (res.done) {
        showResult(qCount, tCount, res);
      } else {
        var pct = res.percent || 0;
        setProgress(Math.max(pct, 5), '⏳ Replace: ' + res.index + '/' + res.total + ' tables (' + (res.table || '') + ')');
        setTimeout(function(){ doReplace(qCount, tCount, 0); }, 50);
      }
    }, function(err) {
      if (replaceRetries < 3) {
        setTimeout(function(){ doReplace(qCount, tCount, replaceRetries + 1); }, 2000);
      } else {
        showError('Replace error: ' + err);
      }
    });
  }

  function showResult(qCount, tCount, replaceRes) {
    document.getElementById('progress-wrap').style.display = 'none';
    setStep(4);
    var box = document.getElementById('result-box');
    box.style.display = 'block';
    var newUrl = document.getElementById('f_new_url').value.replace(/\/$/, '');
    document.getElementById('link-site').href  = newUrl;
    document.getElementById('link-admin').href = newUrl + '/wp-admin/';
    var notice = document.getElementById('result-notice');
    if (allErrors.length === 0) {
      notice.className = 'notice success';
      notice.textContent = '✅ Installation completed successfully!';
    } else {
      notice.className = 'notice warning';
      notice.textContent = '⚠️ Installation completed with some SQL errors.';
    }
    document.getElementById('result-stats').innerHTML =
      '📊 <strong>' + tCount + '</strong> tables, <strong>' + qCount + '</strong> queries imported.'
      + ( replaceRes.url_skipped
          ? ' <span style="color:#646970;">— Replace skipped because URL and path are unchanged.</span>'
          : ' Replace: <strong>' + replaceRes.updated + '</strong> rows updated.'
            + ' (' + (replaceRes.skipped||0) + ' incomplete classes skipped)'
        );
    if (allErrors.length) {
      var el = document.createElement('div');
      el.innerHTML = '<p style="font-size:.85rem;color:#646970;margin:8px 0 4px">SQL errors (' + allErrors.length + ' total, last 20):</p>';
      var pre = document.createElement('div');
      pre.className = 'err-list';
      pre.textContent = allErrors.slice(-20).join('\n');
      el.appendChild(pre);
      document.getElementById('result-errors').appendChild(el);
    }
  }

  var startBtn = document.getElementById('start-btn');
  if (startBtn) {
    startBtn.addEventListener('click', function() {
      var url    = document.getElementById('f_new_url').value.trim();
      var dbhost = document.getElementById('f_db_host').value.trim();
      var dbuser = document.getElementById('f_db_user').value.trim();
      var dbpass = document.getElementById('f_db_pass').value;
      var dbname = document.getElementById('f_db_name').value.trim();
      var prefix = document.getElementById('f_db_prefix').value.trim();
      var encEl  = document.getElementById('f_enc_password');
      var encPass = encEl ? encEl.value : '';
      if (!url || !dbhost || !dbuser || !dbname) {
        alert('Please fill in all required fields.'); return;
      }
      if (encEl && !encPass) {
        alert('This backup is encrypted. Please enter the encryption password.'); return;
      }
      document.getElementById('form-wrap').style.display = 'none';
      document.getElementById('progress-wrap').style.display = 'block';
      setStep(3);
      setProgress(0, 'Starting…');
      post({ action:'init_import', new_url:url, db_host:dbhost, db_user:dbuser, db_pass:dbpass, db_name:dbname, db_prefix:prefix, enc_password:encPass },
        function(res) {
          if (!res.ok) {
            var isDbErr = /connect|database|credentials|host|access denied/i.test(res.error || '');
            showError(res.error, isDbErr);
            return;
          }
          var replaceNeeded = res.replace_needed;
          document.getElementById('prog-status').textContent = 'Database created (' + res.filesize_hr + '). Import starting…'
            + (!replaceNeeded ? ' (URL and path unchanged, replace will be skipped)' : '');
          setTimeout(function(){ doChunk(replaceNeeded); }, 100);
        },
        function(err) { showError('Initialization error: ' + err); }
      );
    });
  }

  var testDbBtn = document.getElementById('test-db-btn');
  if (testDbBtn) {
    testDbBtn.addEventListener('click', function() {
      var resultEl = document.getElementById('test-db-result');
      var dbhost = document.getElementById('f_db_host').value.trim();
      var dbuser = document.getElementById('f_db_user').value.trim();
      var dbpass = document.getElementById('f_db_pass').value;
      var dbname = document.getElementById('f_db_name').value.trim();
      testDbBtn.disabled = true;
      resultEl.textContent = 'Testing…';
      resultEl.style.color = '#646970';
      post({ action: 'test_db', db_host: dbhost, db_user: dbuser, db_pass: dbpass, db_name: dbname },
        function(res) {
          testDbBtn.disabled = false;
          if (res.ok) {
            resultEl.textContent = (res.warning ? '⚠ ' + res.warning : '✓ ' + res.message);
            resultEl.style.color = res.warning ? '#dba617' : '#00a32a';
          } else {
            resultEl.textContent = '✗ ' + res.error;
            resultEl.style.color = '#d63638';
          }
        },
        function(err) {
          testDbBtn.disabled = false;
          resultEl.textContent = '✗ ' + err;
          resultEl.style.color = '#d63638';
        }
      );
    });
  }

  var viewLogBtn = document.getElementById('view-log-btn');
  if (viewLogBtn) {
    viewLogBtn.addEventListener('click', function() {
      var logDiv = document.getElementById('log-content');
      if (logDiv.style.display !== 'none') {
        logDiv.style.display = 'none';
        viewLogBtn.textContent = '📋 View Log';
        return;
      }
      viewLogBtn.disabled = true;
      post({ action: 'view_log' }, function(res) {
        viewLogBtn.disabled = false;
        logDiv.textContent = res.content || '(empty)';
        logDiv.style.display = 'block';
        viewLogBtn.textContent = '📋 Hide Log';
      }, function(err) {
        viewLogBtn.disabled = false;
        logDiv.textContent = 'Error loading log: ' + err;
        logDiv.style.display = 'block';
      });
    });
  }

  var cleanupBtn = document.getElementById('cleanup-btn');
  if (cleanupBtn) {
    cleanupBtn.addEventListener('click', function() {
      if (!confirm('Delete installer and all installation files?')) return;
      var newUrl = document.getElementById('f_new_url') ? document.getElementById('f_new_url').value.replace(/\/$/, '') : location.origin;
      var delZip = document.getElementById('del-zip-check') ? (document.getElementById('del-zip-check').checked ? '1' : '0') : '0';
      post({ action: 'cleanup', del_zip: delZip }, function() {
        location.href = newUrl + '/wp-admin/';
      }, function() {
        alert('Error during cleanup. Delete files manually.');
      });
    });
  }
})();
</script>
</div>
</div>
</body>
</html>
