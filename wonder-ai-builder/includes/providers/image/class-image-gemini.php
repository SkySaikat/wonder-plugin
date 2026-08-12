<?php
/**
 * Gemini image provider — retained as a fallback, NOT the default.
 *
 * At roughly $0.039/image this is the most expensive option in the plugin: ~13x
 * fal-ai/flux/schnell and infinitely more than a local library match. It exists so
 * sites already standardised on Gemini output can stay visually consistent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Image_Gemini implements WAB_Image_Provider_Interface {

    const BASE       = 'https://generativelanguage.googleapis.com/v1beta/models/';
    const KEY_OPTION = 'wab_gemini_api_key';

    public function get_id()         { return 'gemini'; }
    public function get_label()      { return 'Google Gemini (image)'; }
    public function get_key_option() { return self::KEY_OPTION; }

    public function is_configured() {
        return ( defined( 'WAB_GEMINI_API_KEY' ) && WAB_GEMINI_API_KEY )
            || ! empty( get_option( self::KEY_OPTION, '' ) );
    }

    private function api_key() {
        if ( defined( 'WAB_GEMINI_API_KEY' ) && WAB_GEMINI_API_KEY ) return (string) WAB_GEMINI_API_KEY;
        return (string) get_option( self::KEY_OPTION, '' );
    }

    public function get_models() {
        return array(
            'gemini-2.5-flash-image' => array(
                'label'          => 'Gemini 2.5 Flash Image',
                'cost_per_image' => 0.039,
                'notes'          => 'Expensive for bulk. Prefer fal-ai/flux/schnell or the local library.',
            ),
            'gemini-3-pro-image' => array(
                'label'          => 'Gemini 3 Pro Image (Nano Banana Pro)',
                'cost_per_image' => 0.134,
                'notes'          => 'Highest quality, highest cost. Single hero images only.',
            ),
        );
    }

    public function estimate_cost( $model, $width = 0, $height = 0 ) {
        $models = $this->get_models();
        // Gemini bills per image, not per megapixel, so dimensions do not change cost.
        return isset( $models[ $model ] ) ? (float) $models[ $model ]['cost_per_image'] : 0.039;
    }

    public function generate( $prompt, array $args = array() ) {
        $key = $this->api_key();
        if ( $key === '' ) {
            return new WP_Error( 'wab_no_key', __( 'Gemini API key is not configured.', 'wonder-ai-builder' ) );
        }

        $models = $this->get_models();
        $model  = $args['model'] ?? get_option( 'wab_gemini_image_model', 'gemini-2.5-flash-image' );
        if ( ! isset( $models[ $model ] ) ) $model = 'gemini-2.5-flash-image';

        $prompt = mb_substr( trim( wp_strip_all_tags( (string) $prompt ) ), 0, 1500 );
        if ( $prompt === '' ) {
            return new WP_Error( 'wab_fal_no_prompt', __( 'Empty image prompt.', 'wonder-ai-builder' ) );
        }

        $response = WAB_Http_Client::post_json(
            self::BASE . $model . ':generateContent',
            array(
                'contents'         => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
                'generationConfig' => array( 'responseModalities' => array( 'TEXT', 'IMAGE' ) ),
            ),
            array( 'x-goog-api-key' => $key ),
            array( 'timeout' => 180, 'max_attempts' => 2, 'label' => 'Gemini image (' . $model . ')' )
        );

        if ( is_wp_error( $response ) ) return $response;

        foreach ( (array) ( $response['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
            if ( ! empty( $part['inlineData']['data'] ) ) {
                return array(
                    'source' => 'base64',
                    'data'   => $part['inlineData']['data'],
                    'mime'   => $part['inlineData']['mimeType'] ?? 'image/png',
                    'cost'   => $this->estimate_cost( $model ),
                    'model'  => $model,
                );
            }
        }

        return new WP_Error( 'wab_fal_no_image', __( 'Gemini returned no image data.', 'wonder-ai-builder' ) );
    }
}
