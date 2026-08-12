<?php
/**
 * Wiring. Registers every hook and AJAX endpoint.
 *
 * CRITICAL: run() must call WAB_Runner::register() AND ::schedule(). Nothing else
 * registers the cron action, the cron_schedules filter, or the WP-CLI commands, and
 * activation alone cannot be relied on (see the note in wonder-ai-builder.php).
 * schedule() is idempotent via wp_next_scheduled, so calling it every load is safe
 * and self-healing if the event is ever lost.
 *
 * Every AJAX handler opens with WAB_Security::guard(). Read endpoints are guarded
 * too — v1 left 12 endpoints nonce-only, which exposed client CSV data and let
 * anyone with a nonce burn API quota.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Core {

    const MENU_SLUG     = 'wonder-ai-builder';
    const SETTINGS_SLUG = 'wonder-ai-builder-settings';

    public function run() {
        // --- Queue infrastructure. Must run on every load. ------------
        WAB_Runner::register();
        WAB_Runner::schedule();

        // --- Front-end schema output. -------------------------------
        WAB_Schema_Builder::register_output();

        // --- Admin. -------------------------------------------------
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'admin_init', array( 'WAB_Activator', 'maybe_upgrade' ) );
        add_action( 'admin_notices', array( $this, 'notices' ) );

        $this->register_ajax();
    }

    private function register_ajax() {
        $map = array(
            // Import + generation — Editors allowed.
            'wab_upload'        => array( 'WAB_Importer', 'ajax_upload' ),
            'wab_commit'        => array( 'WAB_Importer', 'ajax_commit' ),
            'wab_queue'         => array( 'WAB_Importer', 'ajax_queue' ),

            // Read/ops.
            'wab_status'        => array( $this, 'ajax_status' ),
            'wab_jobs'          => array( $this, 'ajax_jobs' ),
            'wab_imports'       => array( $this, 'ajax_imports' ),
            'wab_retry'         => array( $this, 'ajax_retry' ),
            'wab_cancel'        => array( $this, 'ajax_cancel' ),
            'wab_pause'         => array( $this, 'ajax_pause' ),
            'wab_resume'        => array( $this, 'ajax_resume' ),
            'wab_drain'         => array( $this, 'ajax_drain' ),
            'wab_run_now'       => array( $this, 'ajax_run_now' ),
            'wab_preview_image' => array( $this, 'ajax_preview_image' ),

            // Admin-only.
            'wab_save_settings' => array( 'WAB_Settings', 'ajax_save' ),
            'wab_export'        => array( 'WAB_Settings', 'ajax_export' ),
            'wab_import_config' => array( 'WAB_Settings', 'ajax_import' ),
        );

        foreach ( $map as $action => $callback ) {
            add_action( 'wp_ajax_' . $action, $callback );
            // NOTE: no wp_ajax_nopriv_* anywhere. v1 registered a nopriv endpoint for
            // background processing; v2 advances work via cron/CLI only, so no
            // unauthenticated surface exists.
        }
    }

    // ---------------------------------------------------------------
    // Menu + assets
    // ---------------------------------------------------------------

    public function menu() {
        // Editors can generate; only admins reach Settings.
        add_menu_page(
            __( 'Wonder AI Builder', 'wonder-ai-builder' ),
            __( 'Wonder AI', 'wonder-ai-builder' ),
            WAB_Security::CAP_GENERATE,
            self::MENU_SLUG,
            array( $this, 'render_main' ),
            'dashicons-superhero-alt',
            30
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Dashboard', 'wonder-ai-builder' ),
            __( 'Dashboard', 'wonder-ai-builder' ),
            WAB_Security::CAP_GENERATE,
            self::MENU_SLUG,
            array( $this, 'render_main' )
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Settings', 'wonder-ai-builder' ),
            __( 'Settings', 'wonder-ai-builder' ),
            WAB_Security::CAP_MANAGE,
            self::SETTINGS_SLUG,
            array( $this, 'render_settings' )
        );
    }

    public function assets( $hook ) {
        if ( strpos( (string) $hook, self::MENU_SLUG ) === false ) return;

        wp_enqueue_style( 'wab-admin', WAB_PLUGIN_URL . 'assets/css/admin.css', array(), WAB_VERSION );
        wp_enqueue_script( 'wab-admin', WAB_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), WAB_VERSION, true );

        wp_localize_script( 'wab-admin', 'WAB', array(
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( WAB_Security::NONCE_ACTION ),
            // No API keys are ever passed to the browser — only readiness booleans,
            // which live inside the settings state payload.
            'canManage' => current_user_can( WAB_Security::CAP_MANAGE ),
            'i18n'  => array(
                'confirmDrain' => __( 'Cancel all queued jobs? Work already completed is kept.', 'wonder-ai-builder' ),
                'genericError' => __( 'Something went wrong. Check the log in Settings.', 'wonder-ai-builder' ),
            ),
        ) );
    }

    public function notices() {
        if ( ! current_user_can( WAB_Security::CAP_MANAGE ) ) return;

        $missing = get_option( 'wab_schema_error', array() );
        if ( ! empty( $missing ) && is_array( $missing ) ) {
            printf(
                '<div class="notice notice-error"><p><strong>Wonder AI Builder:</strong> %s <code>%s</code></p></div>',
                esc_html__( 'These database tables could not be created:', 'wonder-ai-builder' ),
                esc_html( implode( ', ', $missing ) )
            );
        }
    }

    // ---------------------------------------------------------------
    // AJAX
    // ---------------------------------------------------------------

    public function ajax_status() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );

        wp_send_json_success( array(
            'counts'   => WAB_Queue::counts( isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) ) : '' ),
            'spend'    => WAB_Cost_Guard::summary(),
            'health'   => WAB_Runner::health(),
            'cron'     => WAB_Runner::cron_instructions(),
            'site'     => WAB_Scanner::site_summary(),
            'estimate' => WAB_Provider_Registry::estimate_item_cost(),
            'batch'    => WAB_Batch::summary(),
        ) );
    }

    public function ajax_jobs() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );

        $result = WAB_Queue::get_jobs( array(
            'import_id' => isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) ) : '',
            'status'    => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'all',
            'page'      => isset( $_POST['page'] ) ? (int) $_POST['page'] : 1,
            'per_page'  => 25,
        ) );

        // Decorate with links; never expose raw internals beyond what the UI needs.
        foreach ( $result['jobs'] as $job ) {
            $job->edit_url = $job->result_post_id ? get_edit_post_link( (int) $job->result_post_id, 'url' ) : '';
            $job->view_url = $job->result_post_id ? get_permalink( (int) $job->result_post_id ) : '';
            $job->title    = $job->result_post_id ? get_the_title( (int) $job->result_post_id ) : '';
        }

        wp_send_json_success( $result );
    }

    public function ajax_imports() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );

        global $wpdb;
        $imports = $wpdb->get_results(
            "SELECT import_id, filename, total_rows, post_type, content_mode, image_source, created_at
               FROM {$wpdb->prefix}wab_imports
              ORDER BY created_at DESC LIMIT 25"
        );

        foreach ( (array) $imports as $imp ) {
            $imp->counts = WAB_Queue::counts( $imp->import_id );
        }

        wp_send_json_success( array( 'imports' => $imports ) );
    }

    public function ajax_retry() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );
        $id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        wp_send_json_success( array( 'ok' => WAB_Queue::retry_job( $id ) ) );
    }

    public function ajax_cancel() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );
        $id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        wp_send_json_success( array( 'ok' => WAB_Queue::cancel_job( $id ) ) );
    }

    public function ajax_pause() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );
        WAB_Runner::pause();
        wp_send_json_success( array( 'paused' => true ) );
    }

    public function ajax_resume() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );
        WAB_Runner::resume();
        wp_send_json_success( array( 'paused' => false ) );
    }

    public function ajax_drain() {
        WAB_Security::guard( WAB_Security::CAP_MANAGE ); // Destructive — admins only.
        wp_send_json_success( array( 'cancelled' => WAB_Queue::drain() ) );
    }

    /**
     * Manual "run now". Still goes through WAB_Runner::tick(), so it obeys the same
     * lock, load and budget gates as cron — a button press cannot create a second
     * concurrent worker.
     */
    public function ajax_run_now() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );

        $report = WAB_Runner::tick( array( 'source' => 'manual', 'max_jobs' => 3 ) );

        wp_send_json_success( array(
            'report' => $report,
            'counts' => WAB_Queue::counts(),
        ) );
    }

    /**
     * Dry-run the local image matcher for one row, so the operator can see whether
     * the library will cover an import BEFORE spending anything.
     */
    public function ajax_preview_image() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );

        global $wpdb;
        $import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) ) : '';
        $limit     = min( 10, max( 1, isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 5 ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wab_rows WHERE import_id = %s ORDER BY row_index ASC LIMIT %d",
            $import_id,
            $limit
        ) );

        $out  = array();
        $hits = 0;

        foreach ( (array) $rows as $row ) {
            $preview = WAB_Image_Library::preview( $row );
            $preview['row'] = $row->title;
            if ( ! empty( $preview['matched'] ) ) $hits++;
            $out[] = $preview;
        }

        $total = max( 1, count( $out ) );
        $rate  = $hits / $total;

        // Feed the observed rate back into cost estimation so the projection reflects
        // this site's actual library coverage rather than a guess.
        update_option( 'wab_library_hit_rate', round( $rate, 3 ), false );

        wp_send_json_success( array(
            'previews'  => $out,
            'hit_rate'  => round( $rate * 100 ),
            'estimate'  => WAB_Provider_Registry::estimate_item_cost(),
        ) );
    }

    // ---------------------------------------------------------------
    // Views
    // ---------------------------------------------------------------

    public function render_main() {
        if ( ! current_user_can( WAB_Security::CAP_GENERATE ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'wonder-ai-builder' ) );
        }
        include WAB_PLUGIN_DIR . 'admin/views/main.php';
    }

    public function render_settings() {
        if ( ! current_user_can( WAB_Security::CAP_MANAGE ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'wonder-ai-builder' ) );
        }
        include WAB_PLUGIN_DIR . 'admin/views/settings.php';
    }
}
