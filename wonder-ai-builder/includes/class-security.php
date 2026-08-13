<?php
/**
 * Centralised request authorisation.
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * In v1, every AJAX handler hand-rolled its own checks. The result was that 12 of
 * ~26 endpoints verified the nonce but forgot `current_user_can()`:
 *
 *   aipb/apbp_get_imports, _get_import_rows, _get_jobs,
 *   _get_queue_counts, _get_scan, _test_api
 *
 * A nonce proves "this request originated from a page on this site". It does NOT
 * prove "this user is allowed to do this". Conflating the two is how read
 * endpoints ended up exposing client CSV data and burning API quota.
 *
 * Every endpoint in v2 must call WAB_Security::guard() as its first statement.
 * There is no code path that reaches business logic without it.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Security {

    const NONCE_ACTION = 'wab_nonce';

    /** Manage settings, imports, queue — full control. */
    const CAP_MANAGE = 'manage_options';

    /** Generate content — can create posts/pages. */
    const CAP_GENERATE = 'edit_others_posts';

    /**
     * Verify nonce + capability, or terminate the request.
     *
     * @param string $capability Capability required. Defaults to full management.
     */
    public static function guard( $capability = self::CAP_MANAGE ) {
        /**
         * Stop wpdb from printing errors into an AJAX response body.
         *
         * This is a correctness fix, not cosmetics. With show_errors enabled — WP_DEBUG,
         * or the default on many managed hosts — any database warning is echoed straight
         * into the output stream. In an AJAX handler that lands *inside* the JSON, making
         * it unparseable, so the browser sees no `success` flag and the screen silently
         * renders nothing. A single missing table was enough to make the entire Queue
         * page look permanently empty while the header still claimed everything was fine.
         *
         * Errors are still recorded in $wpdb->last_error and surfaced through
         * System status, so nothing is hidden from diagnosis — only from the wire.
         */
        global $wpdb;
        if ( isset( $wpdb ) && is_object( $wpdb ) ) {
            $wpdb->hide_errors();
        }

        // check_ajax_referer( $action, $query_arg, $die = true ) — dies on failure.
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( $capability ) ) {
            wp_send_json_error(
                array(
                    'code'    => 'forbidden',
                    'message' => __( 'You do not have permission to perform this action.', 'wonder-ai-builder' ),
                ),
                403
            );
        }
    }

    /**
     * Timing-safe secret comparison.
     *
     * v1 compared the background-worker secret with `===`
     * (class-post-generator.php:116), which is not constant-time.
     */
    public static function secret_equals( $provided, $expected ) {
        if ( ! is_string( $provided ) || ! is_string( $expected ) ) return false;
        if ( $expected === '' || $provided === '' )                 return false;
        return hash_equals( $expected, $provided );
    }

    /**
     * Reject URLs that resolve to private, loopback, or link-local addresses.
     *
     * Fixes the SSRF in v1 class-image-handler.php:145, where `esc_url_raw()`
     * was mistaken for a safety check and any `upload_files` user (Author role)
     * could make the server fetch http://169.254.169.254/ (cloud IAM metadata)
     * or probe internal services.
     *
     * @return true|WP_Error
     */
    public static function validate_outbound_url( $url ) {
        $url = trim( (string) $url );
        if ( $url === '' ) {
            return new WP_Error( 'empty_url', __( 'No URL provided.', 'wonder-ai-builder' ) );
        }

        $parts = wp_parse_url( $url );
        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return new WP_Error( 'malformed_url', __( 'Malformed URL.', 'wonder-ai-builder' ) );
        }

        if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return new WP_Error( 'bad_scheme', __( 'Only http and https URLs are permitted.', 'wonder-ai-builder' ) );
        }

        $host = $parts['host'];

        // Reject credentials embedded in the URL.
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return new WP_Error( 'credentials_in_url', __( 'URLs containing credentials are not permitted.', 'wonder-ai-builder' ) );
        }

        // Resolve to IPs and check every one. A hostname can resolve to a private
        // address (DNS rebinding), so checking the literal string is not enough.
        $ips = self::resolve_host( $host );
        if ( empty( $ips ) ) {
            return new WP_Error( 'unresolvable_host', __( 'Could not resolve host.', 'wonder-ai-builder' ) );
        }

        foreach ( $ips as $ip ) {
            if ( ! self::is_public_ip( $ip ) ) {
                return new WP_Error(
                    'blocked_host',
                    __( 'That URL resolves to a private or reserved network address and has been blocked.', 'wonder-ai-builder' )
                );
            }
        }

        return true;
    }

    private static function resolve_host( $host ) {
        // Bare IP literal (strip IPv6 brackets).
        $literal = trim( $host, '[]' );
        if ( filter_var( $literal, FILTER_VALIDATE_IP ) ) {
            return array( $literal );
        }

        $ips = array();

        if ( function_exists( 'dns_get_record' ) ) {
            $records = @dns_get_record( $host, DNS_A | DNS_AAAA );
            if ( is_array( $records ) ) {
                foreach ( $records as $r ) {
                    if ( ! empty( $r['ip'] ) )   $ips[] = $r['ip'];
                    if ( ! empty( $r['ipv6'] ) ) $ips[] = $r['ipv6'];
                }
            }
        }

        if ( empty( $ips ) ) {
            $v4 = @gethostbyname( $host );
            if ( $v4 && $v4 !== $host && filter_var( $v4, FILTER_VALIDATE_IP ) ) {
                $ips[] = $v4;
            }
        }

        return array_unique( $ips );
    }

    /**
     * Is this address safe to fetch?
     *
     * filter_var()'s NO_PRIV_RANGE|NO_RES_RANGE alone is NOT sufficient. Two gaps
     * that matter for cloud-hosted WordPress:
     *
     *   1. IPv4-mapped IPv6. '::ffff:169.254.169.254' passes as a valid PUBLIC
     *      IPv6 address, yet routes to the link-local metadata endpoint that hands
     *      out cloud IAM credentials. The mapped form must be unwrapped and
     *      re-checked as IPv4.
     *
     *   2. CGNAT 100.64.0.0/10 is neither "private" nor "reserved" by filter_var's
     *      definition, but is routable inside many hosting networks.
     *
     * Also blocked explicitly: 0.0.0.0/8 (this-network), IPv6 unique-local fc00::/7,
     * and IPv6 link-local fe80::/10, since coverage of those varies by PHP build.
     */
    private static function is_public_ip( $ip ) {
        $ip = trim( (string) $ip );
        if ( $ip === '' ) return false;

        // --- Unwrap IPv4-mapped / IPv4-compatible IPv6 -----------------
        // Forms: ::ffff:1.2.3.4  ::ffff:0102:0304  ::1.2.3.4
        if ( strpos( $ip, ':' ) !== false ) {
            $lower = strtolower( $ip );

            if ( preg_match( '/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/', $lower, $m ) ) {
                $ip = $m[1]; // Re-check as IPv4 below.
            } elseif ( preg_match( '/^::ffff:([0-9a-f]{1,4}):([0-9a-f]{1,4})$/', $lower, $m ) ) {
                // Hex-encoded mapped form.
                $a = hexdec( $m[1] );
                $b = hexdec( $m[2] );
                $ip = sprintf( '%d.%d.%d.%d', ( $a >> 8 ) & 0xFF, $a & 0xFF, ( $b >> 8 ) & 0xFF, $b & 0xFF );
            } elseif ( preg_match( '/^::(\d{1,3}(?:\.\d{1,3}){3})$/', $lower, $m ) ) {
                $ip = $m[1];
            }
        }

        // --- Baseline filter -------------------------------------------
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return false;
        }

        // --- Explicit blocks the baseline misses -----------------------
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $long = ip2long( $ip );
            if ( $long === false ) return false;

            $blocked = array(
                array( '100.64.0.0',  10 ), // CGNAT — routable inside many hosts.
                array( '0.0.0.0',      8 ), // "This network".
                array( '169.254.0.0', 16 ), // Link-local / cloud metadata (belt and braces).
                array( '192.0.0.0',   24 ), // IETF protocol assignments.
            );

            foreach ( $blocked as $range ) {
                $base = ip2long( $range[0] );
                $mask = -1 << ( 32 - $range[1] );
                if ( ( $long & $mask ) === ( $base & $mask ) ) {
                    return false;
                }
            }

            return true;
        }

        // --- IPv6 -------------------------------------------------------
        $packed = @inet_pton( $ip );
        if ( $packed === false ) return false;

        $first = ord( $packed[0] );

        // fc00::/7 unique-local
        if ( ( $first & 0xFE ) === 0xFC ) return false;

        // fe80::/10 link-local
        if ( $first === 0xFE && ( ord( $packed[1] ) & 0xC0 ) === 0x80 ) return false;

        return true;
    }

    /**
     * Mask a secret for display. Never echo a stored key back into the DOM.
     *
     * v1 rendered the full Gemini key into the settings page HTML
     * (admin/views/settings.php:20) inside a type="password" input, which masks
     * it visually but leaves it readable in page source and via
     * document.getElementById(...).value.
     */
    public static function mask_secret( $secret ) {
        $secret = (string) $secret;
        $len    = strlen( $secret );
        if ( $len === 0 )  return '';
        if ( $len <= 8 )   return str_repeat( '•', $len );
        return substr( $secret, 0, 4 ) . str_repeat( '•', 12 ) . substr( $secret, -4 );
    }

    /**
     * True when a submitted key field is the masked placeholder echoed back
     * unchanged, meaning "keep the stored value".
     */
    public static function is_masked_placeholder( $value ) {
        return is_string( $value ) && strpos( $value, '•' ) !== false;
    }
}
