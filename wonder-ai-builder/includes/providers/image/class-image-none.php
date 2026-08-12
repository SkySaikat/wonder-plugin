<?php
/**
 * Null image provider — the zero-cost default when AI generation is switched off.
 *
 * Selecting this makes the local media library the ONLY image source, so an import
 * of 100 pages spends exactly $0 on images. Returns a permanent (never-retried)
 * error on miss so a job is not requeued chasing an image that will never exist.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Image_None implements WAB_Image_Provider_Interface {

    public function get_id()         { return 'none'; }
    public function get_label()      { return __( 'No AI images (library only)', 'wonder-ai-builder' ); }
    public function get_key_option() { return ''; }
    public function is_configured()  { return true; }

    public function get_models() {
        return array(
            'none' => array(
                'label'          => __( 'Disabled', 'wonder-ai-builder' ),
                'cost_per_image' => 0.0,
                'notes'          => __( 'Featured images come from the existing media library only.', 'wonder-ai-builder' ),
            ),
        );
    }

    public function estimate_cost( $model = '', $width = 0, $height = 0 ) {
        return 0.0;
    }

    public function generate( $prompt, array $args = array() ) {
        return new WP_Error(
            'wab_image_disabled',
            __( 'AI image generation is disabled; no local match was found.', 'wonder-ai-builder' )
        );
    }
}
