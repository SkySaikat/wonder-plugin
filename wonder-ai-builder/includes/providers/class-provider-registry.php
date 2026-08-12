<?php
/**
 * Provider lookup. Single place that maps option values to objects.
 *
 * Also the fix for the budget-estimate defect: cost estimation must go through the
 * ACTIVE provider/model rather than a hardcoded unit price, otherwise selecting a
 * premium image model makes the pre-flight estimate 10x low and a batch can start
 * that overshoots the daily cap.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Provider_Registry {

    private static $text  = null;
    private static $image = null;

    public static function text_providers() {
        if ( self::$text === null ) {
            self::$text = array(
                'gemini'    => new WAB_Text_Gemini(),
                'openai'    => new WAB_Text_OpenAI(),
                'anthropic' => new WAB_Text_Anthropic(),
            );
        }
        return self::$text;
    }

    public static function image_providers() {
        if ( self::$image === null ) {
            self::$image = array(
                'fal'    => new WAB_Image_Fal(),
                'gemini' => new WAB_Image_Gemini(),
                'none'   => new WAB_Image_None(),
            );
        }
        return self::$image;
    }

    /** @return WAB_Text_Provider_Interface */
    public static function text( $id = null ) {
        $id  = $id ?: get_option( 'wab_text_provider', 'gemini' );
        $all = self::text_providers();
        return $all[ $id ] ?? $all['gemini'];
    }

    /** @return WAB_Image_Provider_Interface */
    public static function image( $id = null ) {
        $id  = $id ?: get_option( 'wab_image_provider', 'fal' );
        $all = self::image_providers();
        return $all[ $id ] ?? $all['none'];
    }

    /**
     * Authoritative per-item cost estimate for the CURRENT configuration.
     * Used by the budget gate before a batch starts.
     */
    public static function estimate_item_cost() {
        $mode   = get_option( 'wab_content_mode', WAB_Prompt_Builder::MODE_HYBRID );
        $tokens = WAB_Prompt_Builder::estimate_output_tokens( $mode, (bool) get_option( 'wab_enable_faq', 1 ) );

        $text     = self::text();
        $t_model  = get_option( 'wab_text_model', '' );
        $pricing  = $text->get_pricing( $t_model );

        // Input is dominated by the cached prefix; assume a modest uncached delta.
        $text_cost = ( ( $tokens / 1000000 ) * (float) $pricing['out'] )
                   + ( ( 400 / 1000000 ) * (float) $pricing['in'] );

        $image_cost = 0.0;
        $source     = get_option( 'wab_image_source', 'library_then_ai' );

        if ( $source !== 'library_only' && $source !== 'none' ) {
            $img   = self::image();
            $model = get_option( 'wab_' . $img->get_id() . '_model', '' );
            $image_cost = (float) $img->estimate_cost(
                $model,
                (int) get_option( 'wab_image_width', 1280 ),
                (int) get_option( 'wab_image_height', 720 )
            );

            // library_then_ai only pays when the library misses. Historical hit
            // rate is the honest multiplier; default assumes a cold library.
            if ( $source === 'library_then_ai' ) {
                $hit = min( 0.95, max( 0.0, (float) get_option( 'wab_library_hit_rate', 0 ) ) );
                $image_cost *= ( 1 - $hit );
            }
        }

        return round( $text_cost + $image_cost, 6 );
    }
}
