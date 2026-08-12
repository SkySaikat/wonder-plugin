<?php
/**
 * Shared outbound HTTP client for every AI provider.
 *
 * FIXES FROM v1
 * -------------
 * 1. `'sslverify' => false` was hardcoded on all six AI calls
 *    (ai-page-builder/includes/class-gemini.php:27,97,142 and the post-builder
 *    equivalents). That accepts any certificate, so anyone on the network path
 *    could MITM the request.
 *
 * 2. The API key was passed in the query string (`?key=$api_key`). Combined with
 *    (1) that is straightforward key theft; even over good TLS, URLs leak into
 *    proxy logs, CDN logs and error_log output. Keys now go in headers only.
 *
 * 3. Retries were handled by requeueing the whole job, which re-billed the text
 *    AND image calls. Transient HTTP failures are retried here instead, with
 *    exponential backoff, so a 429 costs one call rather than a full regeneration.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Http_Client {

    /** Status codes worth retrying. 429 = rate limit, 5xx = upstream trouble. */
    private const RETRYABLE = array( 408, 425, 429, 500, 502, 503, 504 );

    /**
     * POST JSON and decode the response.
     *
     * @param string $url
     * @param array  $body     Encoded as JSON.
     * @param array  $headers  Auth headers. Content-Type is added automatically.
     * @param array  $opts     timeout, max_attempts, label.
     * @return array|WP_Error  Decoded response body on success.
     */
    public static function post_json( $url, array $body, array $headers = array(), array $opts = array() ) {
        $timeout      = isset( $opts['timeout'] )      ? (int) $opts['timeout']      : 120;
        $max_attempts = isset( $opts['max_attempts'] ) ? (int) $opts['max_attempts'] : 3;
        $label        = isset( $opts['label'] )        ? (string) $opts['label']     : 'api';

        $encoded = wp_json_encode( $body );
        if ( $encoded === false ) {
            return new WP_Error( 'wab_encode_failed', __( 'Could not encode request body as JSON.', 'wonder-ai-builder' ) );
        }

        $args = array(
            'headers' => array_merge(
                array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ),
                $headers
            ),
            'body'      => $encoded,
            'timeout'   => $timeout,
            // TLS verification stays ON. Never disable this.
            'sslverify' => true,
            'redirection' => 3,
        );

        $last_error = null;

        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {

            $response = wp_remote_post( $url, $args );

            if ( is_wp_error( $response ) ) {
                $last_error = new WP_Error(
                    'wab_http_error',
                    sprintf( '%s transport error: %s', $label, $response->get_error_message() )
                );
                if ( $attempt < $max_attempts ) { self::backoff( $attempt ); continue; }
                return $last_error;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $raw  = wp_remote_retrieve_body( $response );

            if ( $code >= 200 && $code < 300 ) {
                $decoded = json_decode( $raw, true );
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    return new WP_Error(
                        'wab_bad_json',
                        sprintf( '%s returned malformed JSON: %s', $label, json_last_error_msg() )
                    );
                }
                return is_array( $decoded ) ? $decoded : array();
            }

            $message = self::extract_error_message( $raw, $code );

            // Honour Retry-After when the provider tells us how long to wait.
            if ( in_array( $code, self::RETRYABLE, true ) && $attempt < $max_attempts ) {
                $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
                if ( $retry_after > 0 && $retry_after <= 30 ) {
                    sleep( $retry_after );
                } else {
                    self::backoff( $attempt );
                }
                $last_error = new WP_Error( 'wab_http_' . $code, $message );
                continue;
            }

            // Non-retryable: fail immediately so we do not burn quota.
            return new WP_Error( 'wab_http_' . $code, $message, array( 'status' => $code ) );
        }

        return $last_error ?: new WP_Error( 'wab_http_failed', sprintf( '%s failed after %d attempts.', $label, $max_attempts ) );
    }

    /**
     * GET JSON — used for polling async job queues (fal.ai).
     */
    public static function get_json( $url, array $headers = array(), $timeout = 60 ) {
        $response = wp_remote_get( $url, array(
            'headers'   => array_merge( array( 'Accept' => 'application/json' ), $headers ),
            'timeout'   => (int) $timeout,
            'sslverify' => true,
        ) );

        if ( is_wp_error( $response ) ) return $response;

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'wab_http_' . $code, self::extract_error_message( $raw, $code ) );
        }

        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Upload a file as multipart/form-data.
     *
     * Needed because OpenAI's Batch API takes a JSONL FILE rather than inline
     * requests the way Gemini does. WordPress's HTTP API has no multipart helper, so
     * the body is assembled by hand.
     *
     * @param string $url
     * @param string $field     Form field name for the file.
     * @param string $filename
     * @param string $contents  Raw file bytes.
     * @param array  $fields    Additional scalar form fields.
     * @param array  $headers   Auth headers.
     * @return array|WP_Error
     */
    public static function post_multipart( $url, $field, $filename, $contents, array $fields = array(), array $headers = array(), $timeout = 180 ) {
        $boundary = 'wab' . wp_generate_password( 24, false );
        $eol      = "\r\n";
        $body     = '';

        foreach ( $fields as $name => $value ) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $body .= (string) $value . $eol;
        }

        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $filename . '"' . $eol;
        $body .= 'Content-Type: application/jsonl' . $eol . $eol;
        $body .= $contents . $eol;
        $body .= '--' . $boundary . '--' . $eol;

        $response = wp_remote_post( $url, array(
            'headers'   => array_merge(
                array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
                $headers
            ),
            'body'      => $body,
            'timeout'   => (int) $timeout,
            'sslverify' => true,
        ) );

        if ( is_wp_error( $response ) ) return $response;

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'wab_http_' . $code, self::extract_error_message( $raw, $code ) );
        }

        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * GET raw (non-JSON) body — used to download JSONL batch results.
     *
     * @return string|WP_Error
     */
    public static function get_raw( $url, array $headers = array(), $timeout = 180 ) {
        $response = wp_remote_get( $url, array(
            'headers'   => $headers,
            'timeout'   => (int) $timeout,
            'sslverify' => true,
        ) );

        if ( is_wp_error( $response ) ) return $response;

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'wab_http_' . $code, self::extract_error_message( $raw, $code ) );
        }

        return (string) $raw;
    }

    /**
     * Pull a human-readable message out of whatever shape the provider used,
     * without leaking the whole response body into logs.
     */
    private static function extract_error_message( $raw, $code ) {
        $decoded = json_decode( $raw, true );

        if ( is_array( $decoded ) ) {
            // Gemini: { error: { message } } — OpenAI/Anthropic: same shape.
            if ( ! empty( $decoded['error']['message'] ) ) {
                return sprintf( 'HTTP %d: %s', $code, (string) $decoded['error']['message'] );
            }
            // fal.ai: { detail: "..." } or { detail: [ { msg } ] }
            if ( isset( $decoded['detail'] ) ) {
                $d = $decoded['detail'];
                if ( is_string( $d ) ) return sprintf( 'HTTP %d: %s', $code, $d );
                if ( is_array( $d ) && ! empty( $d[0]['msg'] ) ) {
                    return sprintf( 'HTTP %d: %s', $code, (string) $d[0]['msg'] );
                }
            }
            if ( ! empty( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
                return sprintf( 'HTTP %d: %s', $code, $decoded['message'] );
            }
        }

        return sprintf( 'HTTP %d: %s', $code, mb_substr( wp_strip_all_tags( (string) $raw ), 0, 200 ) );
    }

    private static function backoff( $attempt ) {
        // 1s, 2s, 4s … capped. Plus jitter so parallel workers do not sync up.
        $delay = min( 8, (int) pow( 2, $attempt - 1 ) );
        usleep( ( $delay * 1000000 ) + random_int( 0, 400000 ) );
    }
}
