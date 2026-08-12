<?php
/**
 * OpenAI text provider. Uses json_schema strict mode for enforced structured output.
 *
 * Prompt caching is automatic on the system message when it exceeds the provider's
 * minimum prefix length, which the shared import prefix comfortably does.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Text_OpenAI implements WAB_Text_Provider_Interface, WAB_Batch_Provider_Interface {

    const ENDPOINT     = 'https://api.openai.com/v1/chat/completions';
    const FILES_URL    = 'https://api.openai.com/v1/files';
    const BATCHES_URL  = 'https://api.openai.com/v1/batches';
    const KEY_OPTION   = 'wab_openai_api_key';

    public function get_id()         { return 'openai'; }
    public function get_label()      { return 'OpenAI'; }
    public function get_key_option() { return self::KEY_OPTION; }
    public function supports_batch() { return true; }

    public function is_configured() { return $this->api_key() !== ''; }

    private function api_key() {
        if ( defined( 'WAB_OPENAI_API_KEY' ) && WAB_OPENAI_API_KEY ) return (string) WAB_OPENAI_API_KEY;
        return (string) get_option( self::KEY_OPTION, '' );
    }

    public function get_models() {
        return array(
            'gpt-5-mini' => array( 'label' => 'GPT-5 mini — best value', 'in' => 0.25, 'out' => 2.00, 'notes' => 'Strong instruction following at low cost.' ),
            'gpt-5'      => array( 'label' => 'GPT-5 — premium',        'in' => 1.25, 'out' => 10.00, 'notes' => 'Use for pillar pages only; ~5x the output cost.' ),
        );
    }

    public function get_pricing( $model = '' ) {
        $models = $this->get_models();
        $model  = $model ?: get_option( 'wab_text_model', 'gpt-5-mini' );
        return isset( $models[ $model ] )
            ? array( 'in' => $models[ $model ]['in'], 'out' => $models[ $model ]['out'] )
            : array( 'in' => 0.25, 'out' => 2.00 );
    }

    public function generate( $prefix, $delta, array $schema, array $args = array() ) {
        $key = $this->api_key();
        if ( $key === '' ) return new WP_Error( 'wab_no_key', __( 'OpenAI API key is not configured.', 'wonder-ai-builder' ) );

        $models = $this->get_models();
        $model  = $args['model'] ?? get_option( 'wab_text_model', 'gpt-5-mini' );
        if ( ! isset( $models[ $model ] ) ) $model = 'gpt-5-mini';

        // Same builder the batch path uses, so interactive and batched output cannot
        // drift apart. strict mode requires additionalProperties:false.
        $body = $this->build_body(
            $prefix,
            $delta,
            $schema,
            $model,
            (int) ( $args['max_tokens'] ?? 6144 )
        );

        $response = WAB_Http_Client::post_json(
            self::ENDPOINT,
            $body,
            array( 'Authorization' => 'Bearer ' . $key ),
            array( 'timeout' => 180, 'max_attempts' => 3, 'label' => 'OpenAI (' . $model . ')' )
        );

        if ( is_wp_error( $response ) ) return $response;

        $finish = $response['choices'][0]['finish_reason'] ?? '';
        if ( $finish === 'content_filter' ) {
            return new WP_Error( 'wab_content_blocked', __( 'OpenAI content filter blocked this generation.', 'wonder-ai-builder' ) );
        }

        $text = (string) ( $response['choices'][0]['message']['content'] ?? '' );
        if ( trim( $text ) === '' ) {
            return new WP_Error( 'wab_empty_content', __( 'OpenAI returned no content.', 'wonder-ai-builder' ) );
        }

        $data = json_decode( WAB_Content_Sanitizer::strip_code_fences( $text ), true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'wab_bad_json', __( 'OpenAI returned unparseable JSON.', 'wonder-ai-builder' ) );
        }

        $usage      = $response['usage'] ?? array();
        $tokens_in  = (int) ( $usage['prompt_tokens'] ?? 0 );
        $tokens_out = (int) ( $usage['completion_tokens'] ?? 0 );
        $cached_in  = (int) ( $usage['prompt_tokens_details']['cached_tokens'] ?? 0 );

        $p = $this->get_pricing( $model );
        // Cached input bills at 10% on OpenAI.
        $cost = ( max( 0, $tokens_in - $cached_in ) / 1000000 ) * $p['in']
              + ( $cached_in / 1000000 ) * $p['in'] * 0.10
              + ( $tokens_out / 1000000 ) * $p['out'];

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
    // Batch API — 50% of interactive cost, 24h completion window.
    //
    // Shape differs from Gemini in three ways that matter:
    //   1. Requests go up as a JSONL FILE, not inline JSON.
    //   2. Correlation uses `custom_id` (not metadata.key), max 64 chars.
    //   3. Results come back as a downloadable JSONL file, one object per line.
    // ===============================================================

    /**
     * Build the request body for one row. Shared by interactive and batch paths so
     * output cannot diverge between the two.
     */
    private function build_body( $prefix, $delta, array $schema, $model, $max_tokens ) {
        $strict = $schema;
        $strict['additionalProperties'] = false;

        return array(
            'model'    => $model,
            'messages' => array(
                array( 'role' => 'system', 'content' => (string) $prefix ),
                array( 'role' => 'user',   'content' => (string) $delta ),
            ),
            'max_completion_tokens' => (int) $max_tokens,
            'response_format' => array(
                'type'        => 'json_schema',
                'json_schema' => array( 'name' => 'wab_page', 'strict' => true, 'schema' => $strict ),
            ),
        );
    }

    /**
     * @inheritDoc
     */
    public function submit_batch( array $requests, array $args = array() ) {
        $key = $this->api_key();
        if ( $key === '' ) return new WP_Error( 'wab_no_key', __( 'OpenAI API key is not configured.', 'wonder-ai-builder' ) );

        $models = $this->get_models();
        $model  = $args['model'] ?? get_option( 'wab_text_model', 'gpt-5-mini' );
        if ( ! isset( $models[ $model ] ) ) $model = 'gpt-5-mini';

        // ---- 1. Build JSONL. One request per line. -------------------
        $lines = array();

        foreach ( $requests as $req ) {
            // custom_id is capped at 64 chars. Job IDs are 'job_' + a UUID (40),
            // so they fit — but truncate defensively rather than let the API 400.
            $custom_id = mb_substr( (string) $req['key'], 0, 64 );

            $line = array(
                'custom_id' => $custom_id,
                'method'    => 'POST',
                'url'       => '/v1/chat/completions',
                'body'      => $this->build_body(
                    $req['prefix'],
                    $req['delta'],
                    (array) $req['schema'],
                    $model,
                    (int) ( $req['max_tokens'] ?? 6144 )
                ),
            );

            $encoded = wp_json_encode( $line );
            if ( $encoded === false ) continue; // Skip un-encodable rows rather than corrupt the file.
            $lines[] = $encoded;
        }

        if ( empty( $lines ) ) {
            return new WP_Error( 'wab_batch_empty_payload', __( 'No encodable requests for the batch.', 'wonder-ai-builder' ) );
        }

        $jsonl = implode( "\n", $lines );

        // ---- 2. Upload the file. -------------------------------------
        $upload = WAB_Http_Client::post_multipart(
            self::FILES_URL,
            'file',
            'wab-batch-' . gmdate( 'YmdHis' ) . '.jsonl',
            $jsonl,
            array( 'purpose' => 'batch' ),
            array( 'Authorization' => 'Bearer ' . $key ),
            240
        );

        if ( is_wp_error( $upload ) ) return $upload;

        $file_id = $upload['id'] ?? '';
        if ( ! $file_id ) {
            return new WP_Error( 'wab_batch_no_file', __( 'OpenAI did not return a file ID for the batch upload.', 'wonder-ai-builder' ) );
        }

        // ---- 3. Create the batch. ------------------------------------
        $created = WAB_Http_Client::post_json(
            self::BATCHES_URL,
            array(
                'input_file_id'     => $file_id,
                'endpoint'          => '/v1/chat/completions',
                'completion_window' => '24h',
                'metadata'          => array( 'source' => 'wonder-ai-builder' ),
            ),
            array( 'Authorization' => 'Bearer ' . $key ),
            // Single attempt: a retry after partial success would double-bill.
            array( 'timeout' => 120, 'max_attempts' => 1, 'label' => 'OpenAI batch create' )
        );

        if ( is_wp_error( $created ) ) return $created;

        $batch_id = $created['id'] ?? '';
        if ( ! $batch_id ) {
            return new WP_Error( 'wab_batch_no_handle', __( 'OpenAI did not return a batch ID.', 'wonder-ai-builder' ) );
        }

        return array( 'batch_id' => $batch_id, 'model' => $model );
    }

    /**
     * @inheritDoc
     */
    public function poll_batch( $batch_id ) {
        $key = $this->api_key();
        if ( $key === '' ) return new WP_Error( 'wab_no_key', 'missing key' );

        $response = WAB_Http_Client::get_json(
            self::BATCHES_URL . '/' . rawurlencode( (string) $batch_id ),
            array( 'Authorization' => 'Bearer ' . $key ),
            60
        );

        if ( is_wp_error( $response ) ) return $response;

        return array( 'state' => self::map_state( (string) ( $response['status'] ?? '' ) ), 'raw' => $response );
    }

    /**
     * Normalise OpenAI's batch status vocabulary.
     *
     * 'finalizing' is deliberately mapped to running, not succeeded: the
     * output_file_id is not reliably present until the status reaches 'completed',
     * so treating it as done would fetch nothing and waste an ingest cycle.
     */
    private static function map_state( $status ) {
        switch ( strtolower( $status ) ) {
            case 'validating':
            case 'in_progress':
            case 'finalizing':
                return 'running';
            case 'completed':
                return 'succeeded';
            case 'failed':
                return 'failed';
            case 'expired':
                return 'expired';
            case 'cancelling':
            case 'cancelled':
                return 'cancelled';
        }
        return 'running';
    }

    /**
     * @inheritDoc
     */
    public function fetch_batch_results( $batch_id ) {
        $key = $this->api_key();
        if ( $key === '' ) return new WP_Error( 'wab_no_key', 'missing key' );

        $auth = array( 'Authorization' => 'Bearer ' . $key );

        $batch = WAB_Http_Client::get_json(
            self::BATCHES_URL . '/' . rawurlencode( (string) $batch_id ),
            $auth,
            60
        );

        if ( is_wp_error( $batch ) ) return $batch;

        $output_file = $batch['output_file_id'] ?? '';
        if ( ! $output_file ) {
            return new WP_Error( 'wab_batch_no_results', __( 'Batch completed but exposed no output file.', 'wonder-ai-builder' ) );
        }

        $jsonl = WAB_Http_Client::get_raw(
            self::FILES_URL . '/' . rawurlencode( $output_file ) . '/content',
            $auth,
            240
        );

        if ( is_wp_error( $jsonl ) ) return $jsonl;

        $pricing = $this->get_pricing( get_option( 'wab_text_model', 'gpt-5-mini' ) );
        $out     = array();

        foreach ( preg_split( '/\r?\n/', (string) $jsonl ) as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;

            $item = json_decode( $line, true );
            if ( ! is_array( $item ) ) continue;

            $job_key = $item['custom_id'] ?? '';
            if ( $job_key === '' ) continue;

            // Transport-level failure for this row.
            if ( ! empty( $item['error'] ) ) {
                $msg = is_array( $item['error'] ) ? ( $item['error']['message'] ?? 'batch item error' ) : (string) $item['error'];
                $out[ $job_key ] = array( 'error' => $msg );
                continue;
            }

            $status_code = (int) ( $item['response']['status_code'] ?? 0 );
            $body        = $item['response']['body'] ?? array();

            if ( $status_code < 200 || $status_code >= 300 ) {
                $out[ $job_key ] = array(
                    'error' => sprintf( 'HTTP %d: %s', $status_code, (string) ( $body['error']['message'] ?? 'request failed' ) ),
                );
                continue;
            }

            $finish = $body['choices'][0]['finish_reason'] ?? '';
            if ( $finish === 'content_filter' ) {
                $out[ $job_key ] = array( 'error' => 'Blocked by the OpenAI content filter.' );
                continue;
            }

            $text = (string) ( $body['choices'][0]['message']['content'] ?? '' );
            if ( trim( $text ) === '' ) {
                $out[ $job_key ] = array( 'error' => 'Empty response.' );
                continue;
            }

            $data = json_decode( WAB_Content_Sanitizer::strip_code_fences( $text ), true );
            if ( ! is_array( $data ) ) {
                $out[ $job_key ] = array( 'error' => 'Unparseable JSON in batch response.' );
                continue;
            }

            $usage = $body['usage'] ?? array();
            $in    = (int) ( $usage['prompt_tokens'] ?? 0 );
            $o     = (int) ( $usage['completion_tokens'] ?? 0 );

            // Batch bills at 50%.
            $cost = ( ( $in / 1000000 ) * $pricing['in'] + ( $o / 1000000 ) * $pricing['out'] ) * 0.5;

            $out[ $job_key ] = array(
                'data'       => $data,
                'cost'       => round( $cost, 6 ),
                'tokens_in'  => $in,
                'tokens_out' => $o,
            );
        }

        if ( empty( $out ) ) {
            return new WP_Error( 'wab_batch_no_results', __( 'Batch output file contained no attributable results.', 'wonder-ai-builder' ) );
        }

        return $out;
    }
}
