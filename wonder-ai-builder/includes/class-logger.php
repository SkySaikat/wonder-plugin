<?php
/**
 * Lightweight logger.
 *
 * v1 wrote directly to error_log() from a dozen places, including
 * `error_log("AIPB: get_import_rows returned 0 rows ... Query: $where")` which put
 * SQL fragments into the PHP log on a normal empty result. With this plugin due to
 * run on ~200 sites, logging needs to be off by default, bounded, and never contain
 * secrets or query text.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Logger {

    const OPTION      = 'wab_log';
    const MAX_ENTRIES = 200;

    const LEVEL_ERROR = 'error';
    const LEVEL_WARN  = 'warn';
    const LEVEL_INFO  = 'info';

    public static function error( $message, array $context = array() ) {
        self::write( self::LEVEL_ERROR, $message, $context );
    }

    public static function warn( $message, array $context = array() ) {
        self::write( self::LEVEL_WARN, $message, $context );
    }

    public static function info( $message, array $context = array() ) {
        if ( ! self::verbose() ) return;
        self::write( self::LEVEL_INFO, $message, $context );
    }

    private static function verbose() {
        return (bool) get_option( 'wab_verbose_logging', false );
    }

    private static function write( $level, $message, array $context ) {
        $entry = array(
            'time'    => current_time( 'mysql' ),
            'level'   => $level,
            'message' => self::redact( (string) $message ),
        );

        if ( ! empty( $context ) ) {
            $entry['context'] = array_map(
                static function ( $v ) {
                    return is_scalar( $v ) ? self::redact( (string) $v ) : '[complex]';
                },
                array_slice( $context, 0, 8, true )
            );
        }

        $log = get_option( self::OPTION, array() );
        if ( ! is_array( $log ) ) $log = array();

        $log[] = $entry;
        if ( count( $log ) > self::MAX_ENTRIES ) {
            $log = array_slice( $log, -self::MAX_ENTRIES );
        }

        // autoload = false so the log never loads on every page request.
        update_option( self::OPTION, $log, false );

        if ( $level === self::LEVEL_ERROR && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Wonder AI Builder] ' . $entry['message'] );
        }
    }

    /**
     * Strip anything that looks like a credential before it reaches storage.
     */
    private static function redact( $text ) {
        $patterns = array(
            '/\b(sk-[A-Za-z0-9_\-]{8,})/',          // OpenAI / Anthropic
            '/\bAIza[0-9A-Za-z_\-]{20,}/',           // Google
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}:[A-Za-z0-9]{8,}/i', // fal key id:secret
            '/([?&](?:key|api_key|token|access_token)=)[^&\s]+/i',
        );

        foreach ( $patterns as $p ) {
            $text = preg_replace( $p, '[redacted]', $text );
        }

        return mb_substr( $text, 0, 500 );
    }

    public static function get_entries( $limit = 100 ) {
        $log = get_option( self::OPTION, array() );
        if ( ! is_array( $log ) ) return array();
        return array_slice( array_reverse( $log ), 0, max( 1, (int) $limit ) );
    }

    public static function clear() {
        delete_option( self::OPTION );
    }
}
