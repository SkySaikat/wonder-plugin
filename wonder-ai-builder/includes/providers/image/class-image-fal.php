<?php
/**
 * fal.ai image provider.
 *
 * IMPORTANT — SDK NOTE
 * --------------------
 * fal's documented `npm install --save @fal-ai/client` is the *Node.js* client and
 * cannot be used from a WordPress PHP plugin. There is no build step here and no
 * JS runtime on the server. We call fal's REST API directly:
 *
 *   Sync   : POST https://fal.run/<model-id>
 *   Queued : POST https://queue.fal.run/<model-id>          (then poll)
 *   Auth   : Authorization: Key <FAL_KEY>        (header, never the query string)
 *   Result : { "images": [ { "url": "...", "content_type": "image/jpeg" } ] }
 *
 * COST CONTEXT
 * ------------
 * Images were ~82% of the v1 per-item bill (gemini-2.5-flash-image ≈ $0.039).
 * FLUX.2 [pro] at $0.03/MP is only ~23% cheaper — a rounding error at volume.
 * FLUX.1 [schnell] at $0.003/MP is ~13x cheaper and is the default here, with
 * flux-2-pro one dropdown away for pages where quality genuinely matters.
 *
 * Prices are USD and taken from fal's public model pages. They are surfaced in
 * the admin UI and used for pre-flight budget estimates, so they are declared as
 * data rather than buried in comments. Override via the `wab_fal_model_costs` filter
 * if your account is on custom pricing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Image_Fal implements WAB_Image_Provider_Interface {

    const SYNC_BASE  = 'https://fal.run/';
    const QUEUE_BASE = 'https://queue.fal.run/';
    const KEY_OPTION = 'wab_fal_api_key';

    public function get_id()         { return 'fal'; }
    public function get_label()      { return 'fal.ai (FLUX)'; }
    public function get_key_option() { return self::KEY_OPTION; }

    public function is_configured() {
        return ! empty( get_option( self::KEY_OPTION, '' ) );
    }

    /**
     * cost_per_mp — USD per megapixel of output, rounded up to whole megapixels
     * by fal's billing.
     */
    public function get_models() {
        $models = array(
            'fal-ai/flux/schnell' => array(
                'label'       => 'FLUX.1 [schnell] — cheapest, recommended',
                'cost_per_mp' => 0.003,
                'notes'       => 'Best value. 4-step distilled model, ~1-2s. Ideal for bulk featured images.',
                'size_style'  => 'enum',
                'steps'       => 4,
            ),
            'fal-ai/flux/dev' => array(
                'label'       => 'FLUX.1 [dev] — balanced',
                'cost_per_mp' => 0.025,
                'notes'       => 'Better prompt adherence and detail than schnell at ~8x the price.',
                'size_style'  => 'enum',
                'steps'       => 28,
            ),
            'fal-ai/flux-2-pro' => array(
                'label'       => 'FLUX.2 [pro] — premium',
                'cost_per_mp' => 0.030,
                'notes'       => '$0.03 first megapixel, +$0.015 per extra. Highest fidelity; use for hero pages.',
                'size_style'  => 'preset',
                'steps'       => null,
            ),
            'fal-ai/flux-pro/v1.1' => array(
                'label'       => 'FLUX1.1 [pro]',
                'cost_per_mp' => 0.040,
                'notes'       => 'Previous-generation pro tier. Kept for output consistency with existing libraries.',
                'size_style'  => 'enum',
                'steps'       => null,
            ),
            'fal-ai/recraft-v3' => array(
                'label'       => 'Recraft V3 — best for text in images',
                'cost_per_mp' => 0.040,
                'notes'       => 'Use only when the image must contain readable words. FLUX renders text poorly.',
                'size_style'  => 'enum',
                'steps'       => null,
            ),
            'fal-ai/fast-sdxl' => array(
                'label'       => 'Fast SDXL — budget fallback',
                'cost_per_mp' => 0.003,
                'notes'       => 'Older architecture, very cheap. Useful as a fallback when FLUX is saturated.',
                'size_style'  => 'enum',
                'steps'       => 25,
            ),
        );

        return apply_filters( 'wab_fal_model_costs', $models );
    }

    public function estimate_cost( $model, $width, $height ) {
        $models = $this->get_models();
        if ( ! isset( $models[ $model ] ) ) return 0.0;

        $per_mp = (float) $models[ $model ]['cost_per_mp'];

        // fal rounds up to the nearest megapixel.
        $megapixels = max( 1, (int) ceil( ( (int) $width * (int) $height ) / 1000000 ) );

        // flux-2-pro: first MP at full rate, extras at half.
        if ( $model === 'fal-ai/flux-2-pro' && $megapixels > 1 ) {
            return round( $per_mp + ( ( $megapixels - 1 ) * 0.015 ), 5 );
        }

        return round( $per_mp * $megapixels, 5 );
    }

    /**
     * @inheritDoc
     */
    public function generate( $prompt, array $args = array() ) {
        $api_key = (string) get_option( self::KEY_OPTION, '' );
        if ( $api_key === '' ) {
            return new WP_Error( 'wab_fal_no_key', __( 'fal.ai API key is not configured.', 'wonder-ai-builder' ) );
        }

        $prompt = trim( wp_strip_all_tags( (string) $prompt ) );
        if ( $prompt === '' ) {
            return new WP_Error( 'wab_fal_no_prompt', __( 'Empty image prompt.', 'wonder-ai-builder' ) );
        }
        // Long prompts cost nothing extra here but hurt adherence; keep them tight.
        $prompt = mb_substr( $prompt, 0, 1500 );

        $models = $this->get_models();
        $model  = isset( $args['model'] ) ? (string) $args['model'] : 'fal-ai/flux/schnell';
        if ( ! isset( $models[ $model ] ) ) {
            $model = 'fal-ai/flux/schnell';
        }
        $spec = $models[ $model ];

        $width  = isset( $args['width'] )  ? (int) $args['width']  : 1280;
        $height = isset( $args['height'] ) ? (int) $args['height'] : 720;
        $format = isset( $args['output_format'] ) && $args['output_format'] === 'png' ? 'png' : 'jpeg';

        $body = array(
            'prompt'                => $prompt,
            'num_images'            => 1,
            'output_format'         => $format,
            'enable_safety_checker' => true,
        );

        // Size parameter shape differs per model family.
        if ( $spec['size_style'] === 'preset' ) {
            // flux-2-pro accepts 1K / 2K / 2.3K.
            $body['image_size'] = ( max( $width, $height ) > 1600 ) ? '2K' : '1K';
        } else {
            $body['image_size'] = array( 'width' => $width, 'height' => $height );
        }

        if ( ! empty( $spec['steps'] ) ) {
            $body['num_inference_steps'] = (int) $spec['steps'];
        }

        if ( ! empty( $args['seed'] ) ) {
            $body['seed'] = (int) $args['seed'];
        }

        // Negative prompts are unsupported on schnell/flux-2 but harmless elsewhere.
        if ( ! empty( $args['negative_prompt'] ) && $spec['size_style'] === 'enum' && $model === 'fal-ai/fast-sdxl' ) {
            $body['negative_prompt'] = mb_substr( (string) $args['negative_prompt'], 0, 500 );
        }

        $headers = array(
            // Header auth. v1 put the key in the URL where it leaks into logs.
            'Authorization' => 'Key ' . $api_key,
        );

        $response = WAB_Http_Client::post_json(
            self::SYNC_BASE . $model,
            $body,
            $headers,
            array(
                'timeout'      => 180,
                'max_attempts' => 3,
                'label'        => 'fal.ai (' . $model . ')',
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $url = $this->extract_image_url( $response );
        if ( is_wp_error( $url ) ) {
            return $url;
        }

        return array(
            'source' => 'url',
            'data'   => $url,
            'mime'   => $format === 'png' ? 'image/png' : 'image/jpeg',
            'cost'   => $this->estimate_cost( $model, $width, $height ),
            'model'  => $model,
        );
    }

    /**
     * fal returns { images: [ { url, content_type, width, height } ] }.
     * Some models nest under `image` instead, so both are handled.
     */
    private function extract_image_url( array $response ) {
        if ( ! empty( $response['images'][0]['url'] ) ) {
            return (string) $response['images'][0]['url'];
        }
        if ( ! empty( $response['image']['url'] ) ) {
            return (string) $response['image']['url'];
        }

        // Safety checker tripped — surface that clearly, it is not a transport bug.
        if ( ! empty( $response['has_nsfw_concepts'] ) && ! empty( array_filter( (array) $response['has_nsfw_concepts'] ) ) ) {
            return new WP_Error(
                'wab_fal_nsfw',
                __( 'fal.ai safety checker rejected the generated image. Try rewording the image prompt.', 'wonder-ai-builder' )
            );
        }

        return new WP_Error(
            'wab_fal_no_image',
            __( 'fal.ai response contained no image URL.', 'wonder-ai-builder' )
        );
    }
}
