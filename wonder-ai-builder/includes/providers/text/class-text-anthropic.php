<?php
/**
 * Anthropic Claude text provider.
 *
 * Structured output is obtained via a forced tool call, which is Anthropic's
 * reliable equivalent of a response schema. Explicit cache_control marks the shared
 * prefix as cacheable — the largest single lever for 100-row imports, since cached
 * reads bill at ~10% of normal input.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Text_Anthropic implements WAB_Text_Provider_Interface {

    const ENDPOINT   = 'https://api.anthropic.com/v1/messages';
    const VERSION    = '2023-06-01';
    const KEY_OPTION = 'wab_anthropic_api_key';

    public function get_id()         { return 'anthropic'; }
    public function get_label()      { return 'Anthropic Claude'; }
    public function get_key_option() { return self::KEY_OPTION; }
    public function supports_batch() { return true; }

    public function is_configured() { return $this->api_key() !== ''; }

    private function api_key() {
        if ( defined( 'WAB_ANTHROPIC_API_KEY' ) && WAB_ANTHROPIC_API_KEY ) return (string) WAB_ANTHROPIC_API_KEY;
        return (string) get_option( self::KEY_OPTION, '' );
    }

    public function get_models() {
        return array(
            'claude-haiku-4-5-20251001' => array( 'label' => 'Claude Haiku 4.5 — best value', 'in' => 1.00, 'out' => 5.00, 'notes' => 'Fast and cheap; excellent for bulk service-area pages.' ),
            'claude-sonnet-5'           => array( 'label' => 'Claude Sonnet 5 — premium',     'in' => 3.00, 'out' => 15.00, 'notes' => 'Best prose quality. Reserve for pillar pages.' ),
        );
    }

    public function get_pricing( $model = '' ) {
        $models = $this->get_models();
        $model  = $model ?: get_option( 'wab_text_model', 'claude-haiku-4-5-20251001' );
        return isset( $models[ $model ] )
            ? array( 'in' => $models[ $model ]['in'], 'out' => $models[ $model ]['out'] )
            : array( 'in' => 1.00, 'out' => 5.00 );
    }

    public function generate( $prefix, $delta, array $schema, array $args = array() ) {
        $key = $this->api_key();
        if ( $key === '' ) return new WP_Error( 'wab_no_key', __( 'Anthropic API key is not configured.', 'wonder-ai-builder' ) );

        $models = $this->get_models();
        $model  = $args['model'] ?? get_option( 'wab_text_model', 'claude-haiku-4-5-20251001' );
        if ( ! isset( $models[ $model ] ) ) $model = 'claude-haiku-4-5-20251001';

        $body = array(
            'model'      => $model,
            'max_tokens' => (int) ( $args['max_tokens'] ?? 6144 ),
            // cache_control on the system block: the shared prefix is written once
            // per import and read at ~10% cost for every subsequent row.
            'system'     => array(
                array(
                    'type'          => 'text',
                    'text'          => (string) $prefix,
                    'cache_control' => array( 'type' => 'ephemeral' ),
                ),
            ),
            'messages'   => array(
                array( 'role' => 'user', 'content' => (string) $delta ),
            ),
            'tools'      => array(
                array(
                    'name'         => 'emit_page',
                    'description'  => 'Return the generated page content.',
                    'input_schema' => $schema,
                ),
            ),
            'tool_choice' => array( 'type' => 'tool', 'name' => 'emit_page' ),
        );

        $response = WAB_Http_Client::post_json(
            self::ENDPOINT,
            $body,
            array(
                'x-api-key'         => $key,
                'anthropic-version' => self::VERSION,
            ),
            array( 'timeout' => 180, 'max_attempts' => 3, 'label' => 'Anthropic (' . $model . ')' )
        );

        if ( is_wp_error( $response ) ) return $response;

        if ( ( $response['stop_reason'] ?? '' ) === 'refusal' ) {
            return new WP_Error( 'wab_content_blocked', __( 'Claude declined this generation. Reword the row brief.', 'wonder-ai-builder' ) );
        }

        $data = null;
        foreach ( (array) ( $response['content'] ?? array() ) as $block ) {
            if ( ( $block['type'] ?? '' ) === 'tool_use' && isset( $block['input'] ) && is_array( $block['input'] ) ) {
                $data = $block['input'];
                break;
            }
        }

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'wab_bad_json', __( 'Claude did not return the expected tool payload.', 'wonder-ai-builder' ) );
        }

        $usage      = $response['usage'] ?? array();
        $tokens_in  = (int) ( $usage['input_tokens'] ?? 0 );
        $tokens_out = (int) ( $usage['output_tokens'] ?? 0 );
        $cache_read = (int) ( $usage['cache_read_input_tokens'] ?? 0 );
        $cache_writ = (int) ( $usage['cache_creation_input_tokens'] ?? 0 );

        $p = $this->get_pricing( $model );
        // Cache writes bill at 1.25x, reads at 0.1x.
        $cost = ( $tokens_in  / 1000000 ) * $p['in']
              + ( $cache_writ / 1000000 ) * $p['in'] * 1.25
              + ( $cache_read / 1000000 ) * $p['in'] * 0.10
              + ( $tokens_out / 1000000 ) * $p['out'];

        return array(
            'data'       => $data,
            'cost'       => round( $cost, 6 ),
            'tokens_in'  => $tokens_in + $cache_read + $cache_writ,
            'tokens_out' => $tokens_out,
            'cached_in'  => $cache_read,
            'model'      => $model,
        );
    }
}
