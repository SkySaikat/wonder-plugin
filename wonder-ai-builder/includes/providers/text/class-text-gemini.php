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

    /**
     * Selectable models.
     *
     * VERIFIED LIVE. Every Gemini 2.5 model now returns
     *   404 "This model is no longer available to new users"
     * for newly-issued API keys — including gemini-2.5-flash, -flash-lite and -pro.
     * Shipping those as the default meant a brand-new install could not generate at
     * all. Note the models list endpoint still ADVERTISES them; the restriction is
     * only enforced at call time, so listing is not a safe capability check.
     *
     * 'gemini-flash-latest' is the default deliberately: it is an alias Google keeps
     * pointed at the current Flash generation, so it cannot rot the way a pinned
     * version just did.
     *
     * Prices are USD per 1M tokens and are configurable — treat them as estimates and
     * confirm against your own billing.
     */
    public function get_models() {
        return array(
            'gemini-flash-latest' => array(
                'label' => 'Gemini Flash (latest) — recommended',
                'in'    => 0.30,
                'out'   => 2.50,
                'notes' => 'Alias that always points at the current Flash model, so it will not be retired under you. Verified working.',
            ),
            'gemini-3.6-flash' => array(
                'label' => 'Gemini 3.6 Flash',
                'in'    => 0.30,
                'out'   => 2.50,
                'notes' => 'Pinned version. Use when you need output to stay identical over time. Verified working.',
            ),
            'gemini-3.5-flash' => array(
                'label' => 'Gemini 3.5 Flash',
                'in'    => 0.30,
                'out'   => 2.50,
                'notes' => 'Previous generation, pinned. Verified working.',
            ),
            'gemini-3.1-flash-lite' => array(
                'label' => 'Gemini 3.1 Flash-Lite — cheapest, no thinking',
                'in'    => 0.10,
                'out'   => 0.40,
                'notes' => 'Performs no thinking, so the whole token budget goes to output and cost is far lower. Thinner prose — good for Template depth. Verified working.',
            ),
            'gemini-3-flash-preview' => array(
                'label' => 'Gemini 3 Flash (preview)',
                'in'    => 0.30,
                'out'   => 2.50,
                'notes' => 'Preview channel; pricing and behaviour may change. Verified working.',
            ),
        );
    }

    public function get_pricing( $model = '' ) {
        $models = $this->get_models();
        $model  = $model ?: get_option( 'wab_text_model', 'gemini-flash-latest' );
        if ( isset( $models[ $model ] ) ) {
            return array( 'in' => $models[ $model ]['in'], 'out' => $models[ $model ]['out'] );
        }
        return array( 'in' => 0.30, 'out' => 2.50 ); // gemini-flash-latest defaults
    }

    public function generate( $prefix, $delta, array $schema, array $args = array() ) {
        $key = $this->api_key();
        if ( $key === '' ) {
            return new WP_Error( 'wab_no_key', __( 'Gemini API key is not configured.', 'wonder-ai-builder' ) );
        }

        $models = $this->get_models();
        $model  = $args['model'] ?? get_option( 'wab_text_model', 'gemini-flash-latest' );
        if ( ! isset( $models[ $model ] ) ) $model = 'gemini-flash-latest';

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

                /**
                 * BE GENEROUS HERE. Two reasons this must not be tight:
                 *
                 * 1. Gemini 2.5 models emit THINKING tokens, and those count against
                 *    maxOutputTokens. A budget sized only for the visible answer gets
                 *    partly consumed by reasoning, so the JSON is cut off mid-string
                 *    and json_decode fails. That produced the misleading
                 *    "unparseable JSON despite the enforced schema" error while the
                 *    request itself was perfectly valid.
                 * 2. Billing is per token ACTUALLY produced, so a high ceiling costs
                 *    nothing. There is no reason to economise on it.
                 */
                'maxOutputTokens'  => min( 65536, max( 16384, (int) ( $args['max_tokens'] ?? 16384 ) ) ),

                /**
                 * NO thinkingConfig. Do not add one back.
                 *
                 * VERIFIED AGAINST THE LIVE API: sending
                 * thinkingConfig => array( 'thinkingBudget' => 0 ) returns
                 * HTTP 400 "Request contains an invalid argument" on
                 * gemini-flash-latest and gemini-3.6-flash. Gemini 3.x flash models
                 * do not permit thinking to be disabled. Only
                 * gemini-3.1-flash-lite accepts the parameter, and it performs no
                 * thinking anyway, so there is nothing to gain.
                 *
                 * Thinking is therefore unavoidable and must simply be BUDGETED FOR —
                 * which is what the generous maxOutputTokens above does.
                 */

                'responseMimeType' => 'application/json',
                'responseSchema'   => self::to_gemini_schema( $schema ),
            ),
        );

        $response = WAB_Http_Client::post_json(
            self::BASE . $model . ':generateContent',
            $body,
            array( 'x-goog-api-key' => $key ),
            array(
                // Scale with the token budget. A 2,400-word page measured 34.5s, so
                // 180s is ample for normal work — but a 4,000-word request asks for
                // ~3x the tokens, and a flat timeout would abandon a generation that
                // has already been billed. 60s per 8k tokens, capped at 300s.
                'timeout'      => min( 300, max( 180, (int) ceil( ( $body['generationConfig']['maxOutputTokens'] / 8192 ) * 60 ) + 120 ) ),
                'max_attempts' => 3,
                'label'        => 'Gemini (' . $model . ')',
            )
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

        $usage = $response['usageMetadata'] ?? array();

        // Truncation must be reported as truncation.
        //
        // MAX_TOKENS previously fell through to the generic parse failure, so a reply
        // that was simply cut off looked like a malformed-request problem. Retrying is
        // pointless without more headroom, so this is surfaced distinctly and with the
        // thinking-token count, which is usually where the budget went.
        if ( $reason === 'MAX_TOKENS' ) {
            return new WP_Error( 'wab_truncated', sprintf(
                /* translators: 1: output tokens 2: thinking tokens */
                __( 'Gemini hit its output limit and the reply was cut off mid-JSON (%1$d output tokens, %2$d of them spent thinking). Reduce Content depth, or raise the token ceiling.', 'wonder-ai-builder' ),
                (int) ( $usage['candidatesTokenCount'] ?? 0 ),
                (int) ( $usage['thoughtsTokenCount'] ?? 0 )
            ) );
        }

        if ( trim( $text ) === '' ) {
            return new WP_Error( 'wab_empty_content', sprintf(
                __( 'Gemini returned no text (finish reason: %s).', 'wonder-ai-builder' ),
                $reason !== '' ? $reason : 'none given'
            ) );
        }

        $data = self::decode_payload( $text );

        if ( ! is_array( $data ) ) {
            /**
             * Include what Gemini actually sent.
             *
             * The previous message — "unparseable JSON despite the enforced schema" —
             * threw away the single piece of evidence needed to diagnose the problem,
             * and its wording was read as referring to the sheet's JSON-LD Schema
             * column, which is unrelated. Log the head of the raw reply and name the
             * decoder error.
             */
            $snippet = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) ), 0, 220 );

            WAB_Logger::error( 'Gemini reply could not be decoded. Raw head: ' . $snippet );

            return new WP_Error( 'wab_bad_json', sprintf(
                /* translators: 1: json error 2: snippet */
                __( 'Gemini\'s reply was not valid JSON (%1$s). This is the reply format, not your sheet\'s Schema column. Reply began: %2$s', 'wonder-ai-builder' ),
                json_last_error_msg(),
                $snippet
            ) );
        }

        $tokens_in  = (int) ( $usage['promptTokenCount'] ?? 0 );
        $cached_in  = (int) ( $usage['cachedContentTokenCount'] ?? 0 );

        /**
         * Thinking tokens are BILLED AT THE OUTPUT RATE and must be counted.
         *
         * Measured on a real hybrid-depth row: 739 visible output tokens against 2081
         * thinking tokens. Charging only candidatesTokenCount under-reported the true
         * cost of that request by roughly 3.8x, which would have made every budget
         * cap and every estimate on the dashboard meaningless.
         */
        $visible_out  = (int) ( $usage['candidatesTokenCount'] ?? 0 );
        $thinking_out = (int) ( $usage['thoughtsTokenCount'] ?? 0 );
        $tokens_out   = $visible_out + $thinking_out;

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
        $model  = $args['model'] ?? get_option( 'wab_text_model', 'gemini-flash-latest' );
        if ( ! isset( $models[ $model ] ) ) $model = 'gemini-flash-latest';

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

                        // Same clamp as generate(). Batched rows must not get a tighter
                        // ceiling than interactive ones or they truncate where the live
                        // path succeeds — thinking tokens are drawn from this budget and
                        // measured at 938 against 103 visible on a trivial request, so a
                        // bare estimate leaves no headroom.
                        'maxOutputTokens'  => min( 65536, max( 16384, (int) ( $req['max_tokens'] ?? 16384 ) ) ),
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
     * Normalise Gemini's batch-state enum.
     *
     * Parsed defensively: the state has appeared at both the top level and under
     * `metadata` across API revisions, and a `done` flag may arrive without any
     * explicit state.
     *
     * THE PREFIX IS NOT FIXED. Verified live against a real batch on
     * models/gemini-flash-latest:batchGenerateContent, which reported
     * BATCH_STATE_PENDING -> BATCH_STATE_RUNNING -> BATCH_STATE_SUCCEEDED. Matching
     * only JOB_STATE_* (the older/other-endpoint spelling) meant success was reached
     * solely via the `done` fallback below, and — the damaging case — a FAILED,
     * CANCELLED or EXPIRED batch also carries done=true, so it was read as
     * 'succeeded'. That sent it into fetch_batch_results(), which found nothing,
     * logged an error, and left the batch open to be re-polled until the 48h
     * deadline instead of releasing its jobs immediately. So compare the verb and
     * ignore whichever prefix the API happens to use.
     */
    private static function map_state( array $response ) {
        $state = '';
        foreach ( array( $response['metadata']['state'] ?? null, $response['state'] ?? null ) as $candidate ) {
            if ( is_string( $candidate ) && $candidate !== '' ) { $state = $candidate; break; }
        }

        $verb = preg_replace( '/^(BATCH|JOB)_STATE_/', '', strtoupper( $state ) );

        switch ( $verb ) {
            case 'PENDING':    return 'pending';
            case 'RUNNING':    return 'running';
            case 'SUCCEEDED':  return 'succeeded';
            case 'FAILED':     return 'failed';
            case 'CANCELLING':
            case 'CANCELLED':  return 'cancelled';
            case 'EXPIRED':    return 'expired';
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

        /**
         * Inline results live at response.inlinedResponses.inlinedResponses[].
         *
         * VERIFIED LIVE against a real two-request batch. Every path this code used to
         * try went through a `dest` key, and THE REST API DOES NOT RETURN ONE — `dest`
         * belongs to the Python SDK's BatchJob object, not the wire format. So all four
         * lookups missed on every batch, ingest never happened, and economy mode paid
         * for text it then discarded: jobs sat in 'batched' until the 48h expiry
         * released them, at which point they were regenerated at FULL price. The
         * plugin's own "never lose work silently" rule, broken by one wrong key.
         *
         * The dest-shaped paths are retained last, purely as tolerated fallbacks in
         * case a future revision adopts the SDK shape.
         */
        $list = $response['response']['inlinedResponses']['inlinedResponses']
             ?? $response['response']['inlinedResponses']
             ?? $response['inlinedResponses']['inlinedResponses']
             ?? $response['inlinedResponses']
             ?? $response['response']['dest']['inlinedResponses']['inlinedResponses']
             ?? $response['response']['dest']['inlinedResponses']
             ?? $response['dest']['inlinedResponses']['inlinedResponses']
             ?? $response['dest']['inlinedResponses']
             ?? null;

        // Unwrap one more level if we landed on the container rather than the list.
        if ( is_array( $list ) && isset( $list['inlinedResponses'] ) && is_array( $list['inlinedResponses'] ) ) {
            $list = $list['inlinedResponses'];
        }

        if ( ! is_array( $list ) || empty( $list ) ) {
            // Log the actual keys returned. Guessing at this shape is what caused the
            // original failure; next time the evidence will be in the log.
            WAB_Logger::error( sprintf(
                'Batch %s reported success but no inline responses were found. Top-level keys: %s. response keys: %s.',
                $batch_id,
                implode( ',', array_keys( $response ) ),
                is_array( $response['response'] ?? null ) ? implode( ',', array_keys( $response['response'] ) ) : 'none'
            ) );

            return new WP_Error( 'wab_batch_no_results', __( 'Batch reported success but contained no inline responses.', 'wonder-ai-builder' ) );
        }

        $pricing = $this->get_pricing( get_option( 'wab_text_model', 'gemini-flash-latest' ) );
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
            $usage  = $resp['usageMetadata'] ?? array();

            if ( in_array( $reason, array( 'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'RECITATION' ), true ) ) {
                $out[ $job_key ] = array( 'error' => 'Blocked by Gemini safety filters (' . $reason . ').' );
                continue;
            }

            $text = '';
            foreach ( (array) ( $resp['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
                if ( isset( $part['text'] ) ) $text .= $part['text'];
            }

            // Report truncation AS truncation, exactly as generate() does. Left generic,
            // it reads as a malformed-request problem and sends the operator hunting
            // through their sheet's Schema column instead of the word count.
            if ( $reason === 'MAX_TOKENS' ) {
                $out[ $job_key ] = array( 'error' => sprintf(
                    'Reply hit the output ceiling and was cut off mid-JSON (%d output tokens, %d spent thinking). Reduce the word count for this row.',
                    (int) ( $usage['candidatesTokenCount'] ?? 0 ),
                    (int) ( $usage['thoughtsTokenCount'] ?? 0 )
                ) );
                continue;
            }

            if ( trim( $text ) === '' ) {
                $out[ $job_key ] = array( 'error' => 'Empty response (finish reason: ' . ( $reason !== '' ? $reason : 'none given' ) . ').' );
                continue;
            }

            // The SAME tolerant decoder the interactive path uses. A bare json_decode
            // here meant a reply the live path would have recovered from — fenced,
            // prose-wrapped, or repairably truncated — failed only when batched, which
            // is the hardest place to notice it.
            $data = self::decode_payload( $text );
            if ( ! is_array( $data ) ) {
                $snippet = mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) ), 0, 180 );
                $out[ $job_key ] = array( 'error' => 'Unparseable JSON in batch response. Reply began: ' . $snippet );
                continue;
            }

            $in = (int) ( $usage['promptTokenCount'] ?? 0 );

            // Thinking tokens BILL AT THE OUTPUT RATE, so they are counted here for the
            // same reason generate() counts them. The measured sample returned 103
            // visible output tokens against 938 thinking tokens — charging only the
            // visible ones under-reported that row by roughly 9x, which would quietly
            // defeat the daily budget cap on every economy run.
            $o = (int) ( $usage['candidatesTokenCount'] ?? 0 )
               + (int) ( $usage['thoughtsTokenCount'] ?? 0 );

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

    /** Shared tolerant decoder — handles fences, prose wrappers, and truncation. */
    private static function decode_payload( $text ) {
        return WAB_Content_Sanitizer::decode_json( $text );
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
