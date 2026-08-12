<?php
/**
 * Google Gemini text provider.
 *
 * Fixes vs v1: TLS verification on, key in the x-goog-api-key HEADER (never the
 * query string), structured output enforced via responseSchema, and real usage
 * accounting so spend is known per row instead of arriving with the bill.
 *
 * The cacheable prefix goes in systemInstruction and the per-row delta in contents.
 * Gemini applies implicit caching to repeated prefixes, so a 100-row import pays
 * for the shared context roughly once.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Text_Gemini implements WAB_Text_Provider_Interface, WAB_Batch_Provider_Interface {

    const BASE       = 'https://generativelanguage.googleapis.com/v1beta/models/';
    const KEY_OPTION = 'wab_gemini_api_key';

    public function get_id()         { return 'gemini'; }
    public function get_label()      { return 'Google Gemini'; }
    public function get_key_option() { return self::KEY_OPTION; }
    public function supports_batch() { return true; }

    public function is_configured() {
        return $this->api_key() !== '';
    }

    /**
     * Constants in wp-config.php take precedence over DB storage.
     *
     * With ~200 sites, keys in wp_options means 200 copies in 200 databases,
     * backups and staging clones. A constant keeps the secret out of the DB
     * entirely and lets one provisioning step configure every site.
     */
    private function api_key() {
        if ( defined( 'WAB_GEMINI_API_KEY' ) && WAB_GEMINI_API_KEY ) {
            return (string) WAB_GEMINI_API_KEY;
        }
        return (string) get_option( self::KEY_OPTION, '' );
    }

    public function get_models() {
        return array(
            'gemini-2.5-flash' => array(
                'label' => 'Gemini 2.5 Flash — best value',
                'in'    => 0.30,
                'out'   => 2.50,
                'notes' => 'Recommended. Strong long-form quality at low output cost.',
            ),
            'gemini-3-flash-preview' => array(
                'label' => 'Gemini 3 Flash (preview)',
                'in'    => 0.50,
                'out'   => 3.00,
                'notes' => 'Newer reasoning. Preview pricing may change.',
            ),
            'gemini-2.5-flash-lite' => array(
                'label' => 'Gemini 2.5 Flash-Lite — cheapest',
                'in'    => 0.10,
                'out'   => 0.40,
                'notes' => 'Lowest cost. Suitable for Template mode intros; thin for full bodies.',
            ),
        );
    }

    public function get_pricing( $model = '' ) {
        $models = $this->get_models();
        $model  = $model ?: get_option( 'wab_text_model', 'gemini-2.5-flash' );
        if ( isset( $models[ $model ] ) ) {
            return array( 'in' => $models[ $model ]['in'], 'out' => $models[ $model ]['out'] );
        }
        return array( 'in' => 0.30, 'out' => 2.50 );
    }

    public function generate( $prefix, $delta, array $schema, array $args = array() ) {
        $key = $this->api_key();
        if ( $key === '' ) {
            return new WP_Error( 'wab_no_key', __( 'Gemini API key is not configured.', 'wonder-ai-builder' ) );
        }

        $models = $this->get_models();
        $model  = $args['model'] ?? get_option( 'wab_text_model', 'gemini-2.5-flash' );
        if ( ! isset( $models[ $model ] ) ) $model = 'gemini-2.5-flash';

        $body = array(
            // Per-row delta only. Keeping the prefix out of contents is what makes
            // the cache prefix byte-stable.
            'contents' => array(
                array( 'role' => 'user', 'parts' => array( array( 'text' => (string) $delta ) ) ),
            ),
            'systemInstruction' => array(
                'parts' => array( array( 'text' => (string) $prefix ) ),
            ),
            'generationConfig' => array(
                'temperature'      => isset( $args['temperature'] ) ? (float) $args['temperature'] : 0.85,
                'topP'             => 0.95,
                'maxOutputTokens'  => (int) ( $args['max_tokens'] ?? 6144 ),
                'responseMimeType' => 'application/json',
                'responseSchema'   => self::to_gemini_schema( $schema ),
            ),
        );

        $response = WAB_Http_Client::post_json(
            self::BASE . $model . ':generateContent',
            $body,
            array( 'x-goog-api-key' => $key ),
            array( 'timeout' => 180, 'max_attempts' => 3, 'label' => 'Gemini (' . $model . ')' )
        );

        if ( is_wp_error( $response ) ) return $response;

        // Surface safety blocks as a PERMANENT error — retrying is deterministic.
        $reason = $response['candidates'][0]['finishReason'] ?? '';
        if ( in_array( $reason, array( 'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'RECITATION' ), true ) ) {
            return new WP_Error( 'wab_content_blocked', sprintf(
                __( 'Gemini blocked this generation (%s). Reword the row brief.', 'wonder-ai-builder' ),
                $reason
            ) );
        }

        $text = '';
        foreach ( (array) ( $response['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
            if ( isset( $part['text'] ) ) $text .= $part['text'];
        }

        if ( trim( $text ) === '' ) {
            return new WP_Error( 'wab_empty_content', __( 'Gemini returned no text.', 'wonder-ai-builder' ) );
        }

        $data = json_decode( WAB_Content_Sanitizer::strip_code_fences( $text ), true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'wab_bad_json', __( 'Gemini returned unparseable JSON despite the enforced schema.', 'wonder-ai-builder' ) );
        }

        $usage      = $response['usageMetadata'] ?? array();
        $tokens_in  = (int) ( $usage['promptTokenCount'] ?? 0 );
        $tokens_out = (int) ( $usage['candidatesTokenCount'] ?? 0 );
        $cached_in  = (int) ( $usage['cachedContentTokenCount'] ?? 0 );

        $pricing = $this->get_pricing( $model );

        // Cached input bills at roughly 25% on Gemini; charge it accordingly so the
        // reported spend reflects the benefit of the prefix/delta split.
        $billable_in = max( 0, $tokens_in - $cached_in );
        $cost = ( $billable_in / 1000000 ) * $pricing['in']
              + ( $cached_in   / 1000000 ) * $pricing['in'] * 0.25
              + ( $tokens_out  / 1000000 ) * $pricing['out'];

        return array(
            'data'       => $data,
            'cost'       => round( $cost, 6 ),
            'tokens_in'  => $tokens_in,
            'tokens_out' => $tokens_out,
            'cached_in'  => $cached_in,
            'model'      => $model,
        );
    }

    // ===============================================================
    // Batch API — ~50% of interactive cost, target turnaround 24h,
    // hard expiry at 48h.
    // ===============================================================

    /**
     * @inheritDoc
     */
    public function submit_batch( array $requests, array $args = array() ) {
        $key = $this->api_key();
        if ( $key === '' ) {
            return new WP_Error( 'wab_no_key', __( 'Gemini API key is not configured.', 'wonder-ai-builder' ) );
        }

        $models = $this->get_models();
        $model  = $args['model'] ?? get_option( 'wab_text_model', 'gemini-2.5-flash' );
        if ( ! isset( $models[ $model ] ) ) $model = 'gemini-2.5-flash';

        $inline = array();

        foreach ( $requests as $req ) {
            $inline[] = array(
                'request' => array(
                    'contents'          => array(
                        array( 'role' => 'user', 'parts' => array( array( 'text' => (string) $req['delta'] ) ) ),
                    ),
                    'systemInstruction' => array(
                        'parts' => array( array( 'text' => (string) $req['prefix'] ) ),
                    ),
                    'generationConfig'  => array(
                        'temperature'      => 0.85,
                        'topP'             => 0.95,
                        'maxOutputTokens'  => (int) ( $req['max_tokens'] ?? 6144 ),
                        'responseMimeType' => 'application/json',
                        'responseSchema'   => self::to_gemini_schema( (array) $req['schema'] ),
                    ),
                ),
                // The key correlates each response back to its job.
                'metadata' => array( 'key' => (string) $req['key'] ),
            );
        }

        $body = array(
            'batch' => array(
                'display_name' => 'wab-' . gmdate( 'YmdHis' ) . '-' . count( $inline ),
                'input_config' => array(
                    'requests' => array( 'requests' => $inline ),
                ),
            ),
        );

        $response = WAB_Http_Client::post_json(
            self::BASE . $model . ':batchGenerateContent',
            $body,
            array( 'x-goog-api-key' => $key ),
            // Submission is a single upload; do not retry aggressively, since a
            // retry after a partial success would double-submit and double-bill.
            array( 'timeout' => 180, 'max_attempts' => 1, 'label' => 'Gemini batch submit' )
        );

        if ( is_wp_error( $response ) ) return $response;

        // The create call returns a long-running operation; its `name` is the handle.
        $name = $response['name'] ?? ( $response['metadata']['name'] ?? '' );
        if ( ! is_string( $name ) || $name === '' ) {
            return new WP_Error( 'wab_batch_no_handle', __( 'Gemini did not return a batch name.', 'wonder-ai-builder' ) );
        }

        return array( 'batch_id' => $name, 'model' => $model );
    }

    /**
     * @inheritDoc
     */
    public function poll_batch( $batch_id ) {
        $key = $this->api_key();
        if ( $key === '' ) return new WP_Error( 'wab_no_key', 'missing key' );

        $response = WAB_Http_Client::get_json(
            'https://generativelanguage.googleapis.com/v1beta/' . ltrim( (string) $batch_id, '/' ),
            array( 'x-goog-api-key' => $key ),
            60
        );

        if ( is_wp_error( $response ) ) return $response;

        return array( 'state' => self::map_state( $response ), 'raw' => $response );
    }

    /**
     * Normalise Gemini's JOB_STATE_* enum.
     *
     * Parsed defensively: the state has appeared at both the top level and under
     * `metadata` across API revisions, and a `done` flag may arrive without any
     * explicit state.
     */
    private static function map_state( array $response ) {
        $state = '';
        foreach ( array( $response['metadata']['state'] ?? null, $response['state'] ?? null ) as $candidate ) {
            if ( is_string( $candidate ) && $candidate !== '' ) { $state = $candidate; break; }
        }

        switch ( strtoupper( $state ) ) {
            case 'JOB_STATE_PENDING':   return 'pending';
            case 'JOB_STATE_RUNNING':   return 'running';
            case 'JOB_STATE_SUCCEEDED': return 'succeeded';
            case 'JOB_STATE_FAILED':    return 'failed';
            case 'JOB_STATE_CANCELLED': return 'cancelled';
            case 'JOB_STATE_EXPIRED':   return 'expired';
        }

        // No usable state: infer from the operation's done flag.
        if ( ! empty( $response['error'] ) ) return 'failed';
        if ( ! empty( $response['done'] ) )   return 'succeeded';

        return 'running';
    }

    /**
     * @inheritDoc
     */
    public function fetch_batch_results( $batch_id ) {
        $key = $this->api_key();
        if ( $key === '' ) return new WP_Error( 'wab_no_key', 'missing key' );

        $response = WAB_Http_Client::get_json(
            'https://generativelanguage.googleapis.com/v1beta/' . ltrim( (string) $batch_id, '/' ),
            array( 'x-goog-api-key' => $key ),
            120
        );

        if ( is_wp_error( $response ) ) return $response;

        // Inline results live at response.dest.inlinedResponses[]; tolerate the
        // structure appearing without the `response` wrapper.
        $list = $response['response']['dest']['inlinedResponses']['inlinedResponses']
             ?? $response['response']['dest']['inlinedResponses']
             ?? $response['dest']['inlinedResponses']['inlinedResponses']
             ?? $response['dest']['inlinedResponses']
             ?? null;

        if ( ! is_array( $list ) ) {
            return new WP_Error( 'wab_batch_no_results', __( 'Batch reported success but contained no inline responses.', 'wonder-ai-builder' ) );
        }

        $pricing = $this->get_pricing( get_option( 'wab_text_model', 'gemini-2.5-flash' ) );
        $out     = array();

        foreach ( $list as $index => $item ) {

            $job_key = $item['metadata']['key']
                ?? $item['key']
                ?? null;

            if ( ! $job_key ) {
                // Without a key we cannot attribute the result; log and skip rather
                // than guessing by position, which would mis-assign content.
                WAB_Logger::warn( sprintf( 'Batch %s item %d had no metadata key; skipped.', $batch_id, (int) $index ) );
                continue;
            }

            if ( ! empty( $item['error'] ) ) {
                $msg = is_array( $item['error'] )
                    ? ( $item['error']['message'] ?? 'batch item error' )
                    : (string) $item['error'];
                $out[ $job_key ] = array( 'error' => $msg );
                continue;
            }

            $resp   = $item['response'] ?? array();
            $reason = $resp['candidates'][0]['finishReason'] ?? '';

            if ( in_array( $reason, array( 'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'RECITATION' ), true ) ) {
                $out[ $job_key ] = array( 'error' => 'Blocked by Gemini safety filters (' . $reason . ').' );
                continue;
            }

            $text = '';
            foreach ( (array) ( $resp['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
                if ( isset( $part['text'] ) ) $text .= $part['text'];
            }

            if ( trim( $text ) === '' ) {
                $out[ $job_key ] = array( 'error' => 'Empty response.' );
                continue;
            }

            $data = json_decode( WAB_Content_Sanitizer::strip_code_fences( $text ), true );
            if ( ! is_array( $data ) ) {
                $out[ $job_key ] = array( 'error' => 'Unparseable JSON in batch response.' );
                continue;
            }

            $usage = $resp['usageMetadata'] ?? array();
            $in    = (int) ( $usage['promptTokenCount'] ?? 0 );
            $o     = (int) ( $usage['candidatesTokenCount'] ?? 0 );

            // Batch bills at 50% of interactive rates.
            $cost = ( ( $in / 1000000 ) * $pricing['in'] + ( $o / 1000000 ) * $pricing['out'] ) * 0.5;

            $out[ $job_key ] = array(
                'data'       => $data,
                'cost'       => round( $cost, 6 ),
                'tokens_in'  => $in,
                'tokens_out' => $o,
            );
        }

        if ( empty( $out ) ) {
            return new WP_Error( 'wab_batch_no_results', __( 'No attributable results in the batch response.', 'wonder-ai-builder' ) );
        }

        return $out;
    }

    /**
     * Convert a JSON-Schema fragment to Gemini's uppercase type dialect.
     */
    public static function to_gemini_schema( array $schema ) {
        $map = array(
            'object' => 'OBJECT', 'array' => 'ARRAY', 'string' => 'STRING',
            'number' => 'NUMBER', 'integer' => 'INTEGER', 'boolean' => 'BOOLEAN',
        );

        $out = array();
        foreach ( $schema as $k => $v ) {
            if ( $k === 'type' && is_string( $v ) ) {
                $out['type'] = $map[ strtolower( $v ) ] ?? 'STRING';
            } elseif ( $k === 'properties' && is_array( $v ) ) {
                $out['properties'] = array();
                foreach ( $v as $pk => $pv ) {
                    $out['properties'][ $pk ] = self::to_gemini_schema( (array) $pv );
                }
            } elseif ( $k === 'items' && is_array( $v ) ) {
                $out['items'] = self::to_gemini_schema( $v );
            } else {
                $out[ $k ] = $v;
            }
        }
        return $out;
    }
}
