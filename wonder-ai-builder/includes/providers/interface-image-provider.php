<?php
/**
 * Contract every image provider implements.
 *
 * v1 hardwired `gemini-2.5-flash-image` as a private static property
 * (class-gemini.php:7) with the key in the URL. Swapping models meant editing
 * plugin source. This interface makes the model a runtime choice, and — because
 * images are ~82% of the per-item bill — makes cost the primary selection axis.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

interface WAB_Image_Provider_Interface {

    /** Machine key, e.g. 'fal'. */
    public function get_id();

    /** Human label for the settings dropdown. */
    public function get_label();

    /**
     * Selectable models.
     *
     * @return array<string, array{label:string, cost_per_image:float, notes:string}>
     */
    public function get_models();

    /** Name of the option holding this provider's API key. */
    public function get_key_option();

    /** True when an API key is stored. */
    public function is_configured();

    /**
     * Generate one image.
     *
     * @param string $prompt
     * @param array  $args  model, width, height, output_format.
     * @return array|WP_Error {
     *     @type string $source  'url' or 'base64'
     *     @type string $data    URL or raw base64 payload
     *     @type string $mime
     *     @type float  $cost    Estimated USD spend for this call
     * }
     */
    public function generate( $prompt, array $args = array() );

    /** Estimated USD cost for one image at the given model/size. */
    public function estimate_cost( $model, $width, $height );
}
