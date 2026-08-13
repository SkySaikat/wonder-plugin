<?php
/**
 * Plugin Name:       Wonder AI Builder
 * Plugin URI:        https://saikatchowdhury.com/
 * Description:       Bulk-generate SEO Pages AND Posts from a spreadsheet using pluggable AI text and image providers. Unified successor to Wonder Page + Wonder Blog.
 * Version:           2.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WonderWebStudio
 * Author URI:        https://saikatchowdhury.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       wonder-ai-builder
 *
 * Replaces:          ai-page-builder (Wonder Page), ai-post-builder (Wonder Blog)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WAB_VERSION',    '2.1.0' );
define( 'WAB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WAB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WAB_PLUGIN_FILE', __FILE__ );

/**
 * Explicit include list.
 *
 * The v1 plugins used a loop over a filename array with `require $file`, which is
 * a dynamic include sink. This is a flat, auditable, hardcoded list instead.
 */
final class WAB_Bootstrap {

    private const FILES = array(
        // Infrastructure
        'includes/class-security.php',
        'includes/class-logger.php',
        'includes/class-lock.php',
        'includes/class-runner.php',
        'includes/class-activator.php',
        'includes/class-settings.php',

        // Provider layer.
        // class-batch.php declares WAB_Batch_Provider_Interface, which
        // class-text-gemini.php implements — so it MUST load before the providers.
        'includes/providers/interface-text-provider.php',
        'includes/providers/interface-image-provider.php',
        'includes/class-batch.php',
        'includes/providers/class-provider-registry.php',
        'includes/providers/class-http-client.php',
        'includes/providers/text/class-text-gemini.php',
        'includes/providers/text/class-text-openai.php',
        'includes/providers/text/class-text-anthropic.php',
        'includes/providers/image/class-image-fal.php',
        'includes/providers/image/class-image-gemini.php',
        'includes/providers/image/class-image-none.php',

        // Domain logic
        'includes/class-content-sanitizer.php',
        'includes/class-prompt-builder.php',
        'includes/class-schema-builder.php',
        'includes/class-cost-guard.php',
        'includes/class-scanner.php',
        'includes/class-importer.php',
        'includes/class-queue.php',
        'includes/class-image-library.php',
        'includes/class-image-handler.php',
        'includes/class-generator.php',

        // Admin
        'includes/class-diagnostics.php',
        'includes/class-actions.php',
        'includes/class-core.php',
    );

    public static function boot() {
        foreach ( self::FILES as $rel ) {
            $path = WAB_PLUGIN_DIR . $rel;
            if ( ! file_exists( $path ) ) {
                add_action( 'admin_notices', static function () use ( $rel ) {
                    printf(
                        '<div class="notice notice-error"><p><strong>Wonder AI Builder:</strong> missing required file <code>%s</code>. Please reinstall the plugin.</p></div>',
                        esc_html( $rel )
                    );
                } );
                return;
            }
            require_once $path;
        }

        ( new WAB_Core() )->run();
    }
}

/**
 * Activation.
 *
 * CRITICAL ORDERING NOTE — do not "simplify" this by trimming the requires.
 *
 * activate_{plugin} fires on a request where `plugins_loaded` has ALREADY run and
 * this plugin was not yet in the active list, so WAB_Bootstrap::boot() has not
 * executed and NONE of the includes are loaded. An earlier version of this file
 * only required the activator and logger, then guarded the scheduling call with
 * class_exists('WAB_Runner') — which was always false. The cron event was
 * therefore never scheduled, wab_db_version was still written, and
 * maybe_upgrade() short-circuited forever: the queue could never tick, and
 * deactivate/reactivate reproduced the identical failure.
 *
 * The cron_schedules filter must also be registered BEFORE wp_schedule_event(),
 * because that function validates the recurrence against wp_get_schedules() and
 * silently returns false for an unknown interval.
 */
register_activation_hook( __FILE__, static function () {
    require_once WAB_PLUGIN_DIR . 'includes/class-logger.php';
    require_once WAB_PLUGIN_DIR . 'includes/class-lock.php';
    require_once WAB_PLUGIN_DIR . 'includes/class-runner.php';
    require_once WAB_PLUGIN_DIR . 'includes/class-activator.php';

    // Must precede WAB_Runner::schedule().
    add_filter( 'cron_schedules', array( 'WAB_Runner', 'add_schedule' ) );

    WAB_Activator::activate();
} );

register_deactivation_hook( __FILE__, static function () {
    require_once WAB_PLUGIN_DIR . 'includes/class-logger.php';
    require_once WAB_PLUGIN_DIR . 'includes/class-lock.php';
    require_once WAB_PLUGIN_DIR . 'includes/class-runner.php';
    require_once WAB_PLUGIN_DIR . 'includes/class-activator.php';
    WAB_Activator::deactivate();
} );

add_action( 'plugins_loaded', array( 'WAB_Bootstrap', 'boot' ) );
