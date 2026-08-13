<?php
/**
 * Settings persistence.
 *
 * Key handling differs fundamentally from v1, which echoed the full Gemini key into
 * the settings page HTML inside a type="password" input — visible in page source and
 * readable via document.getElementById(...).value. Here the stored key is NEVER sent
 * to the browser: the UI receives only a mask, and submitting the mask unchanged
 * means "keep the existing value".
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Settings {

    /** Secret options, handled with the mask protocol. */
    private static function secret_keys() {
        return array(
            'wab_gemini_api_key'    => 'WAB_GEMINI_API_KEY',
            'wab_openai_api_key'    => 'WAB_OPENAI_API_KEY',
            'wab_anthropic_api_key' => 'WAB_ANTHROPIC_API_KEY',
            'wab_fal_api_key'       => 'WAB_FAL_API_KEY',
        );
    }

    /** Scalar options with their sanitizer. */
    private static function schema() {
        return array(
            'wab_text_provider'    => 'key',
            'wab_text_model'       => 'text',
            'wab_image_provider'   => 'key',
            'wab_fal_model'        => 'text',
            'wab_gemini_image_model' => 'text',

            'wab_generation_mode'  => 'key',
            'wab_image_source'     => 'key',
            'wab_content_mode'     => 'key',
            'wab_post_type'        => 'key',
            'wab_default_status'   => 'key',
            'wab_default_author'   => 'int',
            'wab_default_category' => 'int',
            'wab_page_template'    => 'text',

            'wab_daily_budget_usd' => 'float',
            'wab_jobs_per_tick'    => 'int',
            'wab_load_threshold'   => 'float',
            'wab_min_words'        => 'int',
            'wab_target_words'     => 'int',
            'wab_inline_images'    => 'int',
            'wab_image_width'      => 'int',
            'wab_image_height'     => 'int',
            'wab_image_quality'    => 'int',
            'wab_image_min_width'  => 'int',
            'wab_enable_faq'       => 'bool',
            'wab_verbose_logging'  => 'bool',

            'wab_schema_type'          => 'text',
            'wab_schema_country'       => 'text',
            'wab_schema_currency'      => 'text',
            'wab_schema_opening_hours' => 'text',

            'wab_concept_industry' => 'text',
            'wab_concept_audience' => 'text',
            'wab_concept_tone'     => 'text',
            'wab_concept_usps'     => 'textarea',
            'wab_concept_avoid'    => 'textarea',
        );
    }

    public static function ajax_save() {
        WAB_Security::guard( WAB_Security::CAP_MANAGE ); // Admins only — keys live here.

        $posted = isset( $_POST['settings'] ) ? (array) wp_unslash( $_POST['settings'] ) : array();
        $saved  = 0;

        foreach ( self::schema() as $key => $type ) {
            if ( ! array_key_exists( $key, $posted ) ) continue;
            update_option( $key, self::sanitize( $posted[ $key ], $type ) );
            $saved++;
        }

        // Secrets: only overwrite when a genuinely new value arrives.
        foreach ( array_keys( self::secret_keys() ) as $key ) {
            if ( ! array_key_exists( $key, $posted ) ) continue;

            $value = trim( (string) $posted[ $key ] );

            if ( $value === '' ) continue;                                  // Untouched.
            if ( WAB_Security::is_masked_placeholder( $value ) ) continue;   // Mask echoed back.

            update_option( $key, sanitize_text_field( $value ) );
            $saved++;
            WAB_Logger::warn( sprintf( 'API key updated: %s', $key ) ); // Audit trail, never the value.
        }

        // Numeric floors that protect the server regardless of what was submitted.
        update_option( 'wab_jobs_per_tick', max( 1, min( 25, (int) get_option( 'wab_jobs_per_tick', 5 ) ) ) );
        update_option( 'wab_inline_images', max( 0, min( 6, (int) get_option( 'wab_inline_images', 2 ) ) ) );

        wp_send_json_success( array(
            'saved'   => $saved,
            'state'   => self::get_state(),
            'message' => __( 'Settings saved.', 'wonder-ai-builder' ),
        ) );
    }

    private static function sanitize( $value, $type ) {
        switch ( $type ) {
            case 'int':      return (int) $value;
            case 'float':    return (float) $value;
            case 'bool':     return ( $value === '1' || $value === true || $value === 'true' ) ? 1 : 0;
            case 'key':      return sanitize_key( (string) $value );
            case 'textarea': return sanitize_textarea_field( (string) $value );
            default:         return sanitize_text_field( (string) $value );
        }
    }

    /**
     * Everything the admin UI needs. Contains NO secret values — only whether each
     * is configured, whether it came from a constant, and a display mask.
     */
    public static function get_state() {
        $state = array();

        foreach ( self::schema() as $key => $type ) {
            $state[ $key ] = get_option( $key, '' );
        }

        $secrets = array();
        foreach ( self::secret_keys() as $option => $constant ) {
            $from_constant = defined( $constant ) && constant( $constant );
            $stored        = (string) get_option( $option, '' );

            $secrets[ $option ] = array
            (
                'configured'    => $from_constant || $stored !== '',
                'from_constant' => (bool) $from_constant,
                'mask'          => $from_constant
                    ? __( 'Set in wp-config.php', 'wonder-ai-builder' )
                    : WAB_Security::mask_secret( $stored ),
            );
        }

        $text_provider  = WAB_Provider_Registry::text();
        $image_provider = WAB_Provider_Registry::image();

        $image_models = array();
        foreach ( WAB_Provider_Registry::image_providers() as $id => $p ) {
            $image_models[ $id ] = $p->get_models();
        }

        $text_models = array();
        foreach ( WAB_Provider_Registry::text_providers() as $id => $p ) {
            $text_models[ $id ] = $p->get_models();
        }

        return array(
            'options'        => $state,
            'secrets'        => $secrets,
            'text_models'    => $text_models,
            'image_models'   => $image_models,
            'content_modes'  => WAB_Prompt_Builder::modes(),
            'schema_types'   => WAB_Schema_Builder::supported_types(),
            'estimate'       => WAB_Provider_Registry::estimate_item_cost(),
            'text_ready'     => $text_provider->is_configured(),
            'image_ready'    => $image_provider->is_configured(),
            'batch'          => WAB_Batch::summary(),
        );
    }

    /**
     * Export every non-secret setting as JSON.
     *
     * Configuring ~200 sites by hand is not viable; this makes site 2 through 200 a
     * single paste. Secrets are deliberately excluded — those belong in wp-config.php
     * constants, not in a file that gets emailed around.
     */
    public static function ajax_export() {
        WAB_Security::guard( WAB_Security::CAP_MANAGE );

        $out = array( '_version' => WAB_VERSION, '_exported' => current_time( 'mysql' ) );
        foreach ( array_keys( self::schema() ) as $key ) {
            $out[ $key ] = get_option( $key, '' );
        }

        wp_send_json_success( array( 'config' => $out ) );
    }

    public static function ajax_import() {
        WAB_Security::guard( WAB_Security::CAP_MANAGE );

        $raw = isset( $_POST['config'] ) ? (string) wp_unslash( $_POST['config'] ) : '';
        $cfg = json_decode( $raw, true );

        if ( ! is_array( $cfg ) ) {
            wp_send_json_error( array( 'message' => __( 'That is not valid configuration JSON.', 'wonder-ai-builder' ) ) );
        }

        $applied = 0;
        foreach ( self::schema() as $key => $type ) {
            if ( ! array_key_exists( $key, $cfg ) ) continue;
            update_option( $key, self::sanitize( $cfg[ $key ], $type ) );
            $applied++;
        }

        wp_send_json_success( array(
            'applied' => $applied,
            'state'   => self::get_state(),
            'message' => sprintf(
                /* translators: %d: number of settings */
                __( '%d settings applied. API keys were not imported — set those per site.', 'wonder-ai-builder' ),
                $applied
            ),
        ) );
    }
}
