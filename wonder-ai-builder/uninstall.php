<?php
/**
 * Runs when the plugin is DELETED (not merely deactivated).
 *
 * WAB_Activator::uninstall() previously existed but was unreachable — there was no
 * uninstall.php and register_uninstall_hook() was never called, so deleting the
 * plugin left 5 tables and ~20 options behind on every site. At ~200 installs that
 * is real database debt.
 *
 * Generated posts, pages and media are deliberately NOT removed: they are the
 * customer's content and outlive the tool that produced it. Only plugin
 * bookkeeping is dropped.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Respect an explicit opt-out for operators who want data retained across a
// reinstall: define( 'WAB_PRESERVE_DATA_ON_UNINSTALL', true ) in wp-config.php.
if ( defined( 'WAB_PRESERVE_DATA_ON_UNINSTALL' ) && WAB_PRESERVE_DATA_ON_UNINSTALL ) {
    return;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-activator.php';

if ( is_multisite() ) {
    // Tables are per-blog, so each site must be cleaned in its own context.
    $blog_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

    foreach ( $blog_ids as $blog_id ) {
        switch_to_blog( $blog_id );
        WAB_Activator::uninstall();
        restore_current_blog();
    }
} else {
    WAB_Activator::uninstall();
}

wp_cache_flush();
