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

    /**
     * One real WordPress admin page per task.
     *
     * The first build put everything on a single screen, then a second attempt used
     * client-side tabs. Both were rejected as confusing: too much on one page, and
     * no clear "I am looking at exactly one thing" state. These are genuine submenu
     * pages, so each has its own URL, its own back button, and its own bookmark.
     */
    const MENU_SLUG     = 'wonder-ai-builder';            // Dashboard
    const IMPORT_SLUG   = 'wonder-ai-import';             // Upload a sheet
    const SHEETS_SLUG   = 'wonder-ai-sheets';             // Sheet list + single-sheet rows
    const QUEUE_SLUG    = 'wonder-ai-queue';              // Job queue
    const STATUS_SLUG   = 'wonder-ai-status';             // Self-diagnosis
    const SETTINGS_SLUG = 'wonder-ai-builder-settings';   // Settings

    public function run() {
        // --- Queue infrastructure. Must run on every load. ------------
        WAB_Runner::register();
        WAB_Runner::schedule();

        // --- Front-end schema output. -------------------------------
        WAB_Schema_Builder::register_output();

        // Form-POST handlers. The primary actions (generate, delete, queue
        // control) are server-side forms so they cannot be broken by a stale
        // or failed admin.js.
        WAB_Actions::register();

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
            'wab_rows'          => array( $this, 'ajax_rows' ),
            'wab_delete_import' => array( $this, 'ajax_delete_import' ),
            'wab_retry'         => array( $this, 'ajax_retry' ),
            'wab_cancel'        => array( $this, 'ajax_cancel' ),
            'wab_pause'         => array( $this, 'ajax_pause' ),
            'wab_resume'        => array( $this, 'ajax_resume' ),
            'wab_drain'         => array( $this, 'ajax_drain' ),
            'wab_run_now'       => array( $this, 'ajax_run_now' ),
            'wab_repair'        => array( $this, 'ajax_repair' ),
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
        $gen    = WAB_Security::CAP_GENERATE; // Editors and up.
        $manage = WAB_Security::CAP_MANAGE;   // Admins only.

        add_menu_page(
            __( 'Wonder AI Builder', 'wonder-ai-builder' ),
            __( 'Wonder AI', 'wonder-ai-builder' ),
            $gen,
            self::MENU_SLUG,
            array( $this, 'render_dashboard' ),
            'dashicons-superhero-alt',
            30
        );

        $pages = array(
            array( self::MENU_SLUG,   __( 'Dashboard', 'wonder-ai-builder' ),    $gen,    'render_dashboard' ),
            array( self::SHEETS_SLUG, __( 'Sheets', 'wonder-ai-builder' ),       $gen,    'render_sheets' ),
            array( self::QUEUE_SLUG,  __( 'Queue', 'wonder-ai-builder' ),        $gen,    'render_queue' ),
            array( self::IMPORT_SLUG, __( 'Import a sheet', 'wonder-ai-builder' ), $gen,  'render_import' ),
            array( self::STATUS_SLUG, __( 'System status', 'wonder-ai-builder' ), $gen,  'render_status' ),
            array( self::SETTINGS_SLUG, __( 'Settings', 'wonder-ai-builder' ),   $manage, 'render_settings' ),
        );

        foreach ( $pages as $p ) {
            add_submenu_page( self::MENU_SLUG, $p[1], $p[1], $p[2], $p[0], array( $this, $p[3] ) );
        }
    }

    /** Shared page URLs, so no view has to hand-build one. */
    public static function url( $slug, array $args = array() ) {
        return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
    }

    public function assets( $hook ) {
        // Match the shared 'wonder-ai' prefix, NOT MENU_SLUG. The page slugs are
        // wonder-ai-sheets / -queue / -import, none of which contain
        // 'wonder-ai-builder', so testing against MENU_SLUG loaded the CSS and JS on
        // the Dashboard and Settings only — every other screen would have rendered
        // unstyled with no working buttons.
        if ( strpos( (string) $hook, 'wonder-ai' ) === false ) return;

        /**
         * Version assets by FILE MODIFICATION TIME, not the plugin version.
         *
         * This was a serious bug. Passing the static WAB_VERSION constant meant the
         * URL never changed when the CSS or JS did, so browsers and host-level page
         * caches (SiteGround, Cloudflare, WP Rocket...) served stale assets
         * indefinitely. The visible result was brutal and misleading: unstyled
         * navigation, panels rendered with an old dark-mode rule, and — worst —
         * cached JavaScript whose selectors no longer matched the DOM, which left the
         * Generate button permanently disabled. The plugin looked broken when only
         * the asset URLs were.
         *
         * filemtime() changes on every edit, so a deploy always invalidates.
         */
        $css_path = WAB_PLUGIN_DIR . 'assets/css/admin.css';
        $js_path  = WAB_PLUGIN_DIR . 'assets/js/admin.js';

        $css_ver = file_exists( $css_path ) ? (string) filemtime( $css_path ) : WAB_VERSION;
        $js_ver  = file_exists( $js_path )  ? (string) filemtime( $js_path )  : WAB_VERSION;

        wp_enqueue_style( 'wab-admin', WAB_PLUGIN_URL . 'assets/css/admin.css', array(), $css_ver );
        wp_enqueue_script( 'wab-admin', WAB_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), $js_ver, true );

        wp_localize_script( 'wab-admin', 'WAB', array(
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( WAB_Security::NONCE_ACTION ),
            // Page URLs, so JS never hand-builds an admin link.
            'sheetsUrl' => self::url( self::SHEETS_SLUG ),
            'queueUrl'  => self::url( self::QUEUE_SLUG ),
            'importUrl' => self::url( self::IMPORT_SLUG ),
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

    /**
     * List the rows of one import, so the operator can pick specific ones.
     *
     * Each row carries its current job state, which is what makes selective
     * generation intelligible: you can see at a glance which rows are already done
     * and tick only the ones you still want.
     */
    public function ajax_rows() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );

        global $wpdb;

        $import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) ) : '';
        if ( $import_id === '' ) {
            wp_send_json_error( array( 'message' => __( 'Missing import.', 'wonder-ai-builder' ) ) );
        }

        $per_page = 50;
        $page     = max( 1, isset( $_POST['page'] ) ? (int) $_POST['page'] : 1 );
        $offset   = ( $page - 1 ) * $per_page;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id, r.row_index, r.title, r.services, r.location, r.post_type,
                    r.category, r.schema_markup <> '' AS has_schema,
                    j.status AS job_status, j.result_post_id, j.error_message, j.cost_usd
               FROM {$wpdb->prefix}wab_rows r
          LEFT JOIN {$wpdb->prefix}wab_jobs j
                 ON j.import_id = r.import_id AND j.row_index = r.row_index
              WHERE r.import_id = %s
           ORDER BY r.row_index ASC
              LIMIT %d OFFSET %d",
            $import_id,
            $per_page,
            $offset
        ) );

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wab_rows WHERE import_id = %s",
            $import_id
        ) );

        foreach ( (array) $rows as $r ) {
            $r->edit_url = $r->result_post_id ? get_edit_post_link( (int) $r->result_post_id, 'url' ) : '';
            // A row with no job record has never been queued.
            $r->job_status = $r->job_status ?: 'new';
        }

        wp_send_json_success( array(
            'rows'     => $rows ?: array(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ) );
    }

    /**
     * Remove an import and its rows. Jobs and generated posts are left alone —
     * deleting an import must never delete published content.
     */
    public function ajax_delete_import() {
        WAB_Security::guard( WAB_Security::CAP_MANAGE );

        global $wpdb;
        $import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) ) : '';
        if ( $import_id === '' ) {
            wp_send_json_error( array( 'message' => __( 'Missing import.', 'wonder-ai-builder' ) ) );
        }

        $queued = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wab_jobs WHERE import_id = %s AND status IN ('queued','processing','batched')",
            $import_id
        ) );

        if ( $queued > 0 ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    __( 'This import still has %d job(s) in flight. Cancel them first.', 'wonder-ai-builder' ),
                    $queued
                ),
            ) );
        }

        $wpdb->delete( $wpdb->prefix . 'wab_rows', array( 'import_id' => $import_id ) );
        $wpdb->delete( $wpdb->prefix . 'wab_imports', array( 'import_id' => $import_id ) );

        wp_send_json_success( array( 'deleted' => true ) );
    }

    /**
     * Force a schema re-check and repair. Safe to run repeatedly: dbDelta only
     * applies differences, and existing rows are untouched.
     */
    public function ajax_repair() {
        WAB_Security::guard( WAB_Security::CAP_MANAGE );

        $before = WAB_Activator::find_missing();

        delete_transient( 'wab_schema_ok' );
        delete_transient( 'wab_batches_ok' );

        WAB_Activator::activate();   // dbDelta + option seeding + re-schedule cron.

        $after = WAB_Activator::find_missing();

        wp_send_json_success( array(
            'repaired' => array_values( array_diff( $before, $after ) ),
            'missing'  => $after,
            'ok'       => empty( $after ),
            'message'  => empty( $after )
                ? __( 'Database verified. Everything the plugin needs is present.', 'wonder-ai-builder' )
                : sprintf(
                    /* translators: %s: list */
                    __( 'Still missing after repair: %s. The database user may lack ALTER/CREATE permission.', 'wonder-ai-builder' ),
                    implode( ', ', $after )
                ),
        ) );
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

        /**
         * force => true. A human pressing "Run one job now" is an explicit
         * instruction, so it must not be silently refused by the throttle or the
         * load gate. Without this the button reported "Server load too high, so the
         * worker deferred" and did nothing — which reads exactly like a broken button.
         *
         * The LOCK is still respected (tick() cannot be forced past it), so this can
         * never create a second concurrent worker.
         */
        $report = WAB_Runner::tick( array( 'source' => 'manual', 'force' => true, 'max_jobs' => 3 ) );

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

    private function view( $file, $capability ) {
        if ( ! current_user_can( $capability ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'wonder-ai-builder' ) );
        }
        include WAB_PLUGIN_DIR . 'admin/views/' . $file . '.php';
    }

    public function render_dashboard() { $this->view( 'dashboard', WAB_Security::CAP_GENERATE ); }
    public function render_import()    { $this->view( 'import',    WAB_Security::CAP_GENERATE ); }
    public function render_queue()     { $this->view( 'queue',     WAB_Security::CAP_GENERATE ); }
    public function render_status()    { $this->view( 'status',    WAB_Security::CAP_GENERATE ); }
    public function render_settings()  { $this->view( 'settings',  WAB_Security::CAP_MANAGE ); }

    /**
     * Sheets is two views behind one menu item: the list, or one sheet's rows.
     * Viewing a single sheet is the "one thing at a time" case, so it gets its own
     * URL and its own screen rather than expanding inline.
     */
    public function render_sheets() {
        if ( ! current_user_can( WAB_Security::CAP_GENERATE ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'wonder-ai-builder' ) );
        }

        $import_id = isset( $_GET['import_id'] ) ? sanitize_text_field( wp_unslash( $_GET['import_id'] ) ) : '';

        if ( $import_id !== '' ) {
            include WAB_PLUGIN_DIR . 'admin/views/sheet-single.php';
            return;
        }

        include WAB_PLUGIN_DIR . 'admin/views/sheets.php';
    }
}
