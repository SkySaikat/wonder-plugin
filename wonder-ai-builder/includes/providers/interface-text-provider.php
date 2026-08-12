<?php
/**
 * Contract for text providers.
 *
 * The prefix/delta split is part of the interface, not an implementation detail:
 * cost at 100-rows-per-import depends on the stable prefix being sent byte-identical
 * every time so the provider can serve it from its prompt cache.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

interface WAB_Text_Provider_Interface {

    public function get_id();
    public function get_label();
    public function get_models();
    public function get_key_option();
    public function is_configured();

    /**
     * Generate structured content.
     *
     * @param string $prefix  Cacheable system prefix (identical across the import).
     * @param string $delta   Per-row instruction.
     * @param array  $schema  JSON schema for enforced structured output.
     * @param array  $args    model, max_tokens, temperature.
     * @return array|WP_Error {
     *     @type array $data       Decoded payload matching $schema.
     *     @type float $cost       Estimated USD.
     *     @type int   $tokens_in
     *     @type int   $tokens_out
     *     @type int   $cached_in  Input tokens served from cache, when reported.
     * }
     */
    public function generate( $prefix, $delta, array $schema, array $args = array() );

    /** USD per 1M input / output tokens for a model. */
    public function get_pricing( $model );

    /** True when the provider exposes an async batch endpoint at reduced cost. */
    public function supports_batch();
}
