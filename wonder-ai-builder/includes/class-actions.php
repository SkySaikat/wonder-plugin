<?php
/**
 * Server-side form handlers (admin-post.php).
 *
 * ============================================================================
 * WHY THIS EXISTS — A CORRECTED ARCHITECTURAL MISTAKE
 * ============================================================================
 * Row selection and Generate were originally implemented as AJAX driven by
 * admin.js. That put the plugin's single most important function behind a
 * JavaScript dependency, and it failed in production exactly the way such designs
 * fail: the asset URL was versioned with a static constant, so a host-level page
 * cache served a stale admin.js whose selectors no longer matched the DOM. The
 * Generate button stayed disabled forever. Nothing entered the queue. Nothing was
 * created. The plugin appeared totally broken while every line of PHP was fine.
 *
 * The lesson is not "bust the cache harder". It is that a destructive-or-billable
 * primary action must not depend on client-side state at all.
 *
 * So selection is now plain <input type="checkbox" name="row_ids[]"> inside a real
 * <form method="post">, submitted to admin-post.php, handled here, and finished with
 * a redirect carrying a result notice. It works with JavaScript disabled, with a
 * stale cache, with a JS error on the page — always. JS remains only as garnish
 * (row-click convenience, live cost estimate).
 *
 * Every handler follows the same contract: verify nonce, verify capability, act,
 * then redirect. Never render.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Actions {

    public static function register() {
        add_action( 'admin_post_wab_generate',     array( __CLASS__, 'generate' ) );
        add_action( 'admin_post_wab_delete_sheet', array( __CLASS__, 'delete_sheet' ) );
        add_action( 'admin_post_wab_queue_action', array( __CLASS__, 'queue_action' ) );
    }

    /**
     * Redirect back with a status message. Messages are keyed, never passed as free
     * text, so nothing user-supplied ends up in a URL.
     */
    private static function back( $slug, array $args, $code, $detail = '' ) {
        $args['wab_msg'] = $code;
        if ( $detail !== '' ) {
            // Short, sanitized, length-capped. Rendered with esc_html on the far side.
            $args['wab_detail'] = rawurlencode( mb_substr( wp_strip_all_tags( $detail ), 0, 200 ) );
        }
        wp_safe_redirect( WAB_Core::url( $slug, $args ) );
        exit;
    }

    // ---------------------------------------------------------------
    // Generate selected rows
    // ---------------------------------------------------------------

    public static function generate() {
        if ( ! current_user_can( WAB_Security::CAP_GENERATE ) ) {
            wp_die( esc_html__( 'You do not have permission to generate content.', 'wonder-ai-builder' ), '', array( 'response' => 403 ) );
        }

        check_admin_referer( 'wab_generate' );

        global $wpdb;

        $import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) ) : '';
        if ( $import_id === '' ) {
            self::back( WAB_Core::SHEETS_SLUG, array(), 'no_import' );
        }

        $redirect = array( 'import_id' => $import_id );
        if ( ! empty( $_POST['paged'] ) ) {
            $redirect['paged'] = max( 1, (int) $_POST['paged'] );
        }

        // ---- Which rows? ------------------------------------------------
        $scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'selected';

        if ( $scope === 'all_unbuilt' ) {
            // "Everything in this sheet that has not been built yet" — the option that
            // was missing entirely, and the one people actually want for a 20-row sheet.
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT r.id, r.row_index
                   FROM {$wpdb->prefix}wab_rows r
              LEFT JOIN {$wpdb->prefix}wab_jobs j
                     ON j.import_id = r.import_id AND j.row_index = r.row_index
                  WHERE r.import_id = %s
                    AND ( j.status IS NULL OR j.status IN ('failed','cancelled') )
               ORDER BY r.row_index ASC",
                $import_id
            ) );
        } else {
            $ids = isset( $_POST['row_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['row_ids'] ) ) : array();
            $ids = array_values( array_filter( array_unique( $ids ) ) );

            if ( empty( $ids ) ) {
                self::back( WAB_Core::SHEETS_SLUG, $redirect, 'nothing_selected' );
            }

            $ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $args = array_merge( array( $import_id ), $ids );

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, row_index FROM {$wpdb->prefix}wab_rows
                  WHERE import_id = %s AND id IN ({$ph}) ORDER BY row_index ASC",
                $args
            ) );
        }

        if ( empty( $rows ) ) {
            self::back( WAB_Core::SHEETS_SLUG, $redirect, 'no_rows' );
        }

        // ---- Optional post-type override -------------------------------
        $override = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
        if ( in_array( $override, array( 'page', 'post' ), true ) ) {
            $ids  = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
            $ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $args = array_merge( array( $override ), $ids );

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}wab_rows SET post_type = %s WHERE id IN ({$ph})",
                $args
            ) );
        }

        // ---- Budget pre-flight -----------------------------------------
        $per       = WAB_Provider_Registry::estimate_item_cost();
        $projected = $per * count( $rows );
        $budget    = WAB_Cost_Guard::daily_budget();

        if ( $budget > 0 && ( WAB_Cost_Guard::spend_today() + $projected ) > $budget ) {
            self::back( WAB_Core::SHEETS_SLUG, $redirect, 'over_budget',
                sprintf( '%.2f', $projected ) );
        }

        // ---- Enqueue ----------------------------------------------------
        $queued = WAB_Queue::enqueue( $import_id, $rows );

        if ( $queued === 0 ) {
            $why = WAB_Queue::explain_no_op( $import_id, wp_list_pluck( $rows, 'row_index' ) );
            self::back( WAB_Core::SHEETS_SLUG, $redirect, 'nothing_new', $why );
        }

        /**
         * Auto-resume.
         *
         * The header control was mistaken for a status label and clicked, leaving the
         * queue paused; rows then sat forever with no obvious reason. Explicitly
         * asking to generate is an unambiguous instruction to run, so honour it and
         * say so in the notice rather than silently ignoring the request.
         */
        $was_paused = WAB_Runner::is_paused();
        if ( $was_paused ) {
            WAB_Runner::resume();
        }

        // Start immediately instead of waiting for the next cron tick. Runs inline
        // through the same lock and gates — this is not a spawn.
        WAB_Runner::tick( array( 'source' => 'generate', 'max_jobs' => 1 ) );

        self::back(
            WAB_Core::SHEETS_SLUG,
            $redirect,
            $was_paused ? 'queued_resumed' : 'queued',
            (string) $queued
        );
    }

    // ---------------------------------------------------------------
    // Delete a sheet
    // ---------------------------------------------------------------

    public static function delete_sheet() {
        if ( ! current_user_can( WAB_Security::CAP_GENERATE ) ) {
            wp_die( esc_html__( 'You do not have permission to delete sheets.', 'wonder-ai-builder' ), '', array( 'response' => 403 ) );
        }

        check_admin_referer( 'wab_delete_sheet' );

        global $wpdb;

        $import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) ) : '';
        if ( $import_id === '' ) {
            self::back( WAB_Core::SHEETS_SLUG, array(), 'no_import' );
        }

        // Refuse while work is in flight, otherwise a running job would lose its row
        // mid-generation.
        $busy = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wab_jobs
              WHERE import_id = %s AND status IN ('queued','processing','batched')",
            $import_id
        ) );

        if ( $busy > 0 ) {
            self::back( WAB_Core::SHEETS_SLUG, array(), 'delete_busy', (string) $busy );
        }

        // Generated posts are NEVER touched — they are the customer's content and
        // outlive the sheet that produced them.
        $wpdb->delete( $wpdb->prefix . 'wab_jobs',    array( 'import_id' => $import_id ) );
        $wpdb->delete( $wpdb->prefix . 'wab_rows',    array( 'import_id' => $import_id ) );
        $wpdb->delete( $wpdb->prefix . 'wab_concepts', array( 'import_id' => $import_id ) );
        $wpdb->delete( $wpdb->prefix . 'wab_imports', array( 'import_id' => $import_id ) );

        WAB_Logger::warn( 'Sheet deleted: ' . $import_id );

        self::back( WAB_Core::SHEETS_SLUG, array(), 'sheet_deleted' );
    }

    // ---------------------------------------------------------------
    // Queue controls (pause / resume / run / clear) — also form-based
    // ---------------------------------------------------------------

    public static function queue_action() {
        if ( ! current_user_can( WAB_Security::CAP_GENERATE ) ) {
            wp_die( esc_html__( 'You do not have permission to control the queue.', 'wonder-ai-builder' ), '', array( 'response' => 403 ) );
        }

        check_admin_referer( 'wab_queue_action' );

        $do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
        $back = isset( $_POST['back'] ) ? sanitize_key( wp_unslash( $_POST['back'] ) ) : WAB_Core::QUEUE_SLUG;

        $allowed_back = array( WAB_Core::QUEUE_SLUG, WAB_Core::SHEETS_SLUG, WAB_Core::MENU_SLUG, WAB_Core::STATUS_SLUG );
        if ( ! in_array( $back, $allowed_back, true ) ) $back = WAB_Core::QUEUE_SLUG;

        switch ( $do ) {
            case 'pause':
                WAB_Runner::pause();
                self::back( $back, array(), 'paused' );
                break;

            case 'resume':
                WAB_Runner::resume();
                WAB_Runner::tick( array( 'source' => 'resume', 'max_jobs' => 1 ) );
                self::back( $back, array(), 'resumed' );
                break;

            case 'run':
                if ( WAB_Runner::is_paused() ) WAB_Runner::resume();
                $r = WAB_Runner::tick( array( 'source' => 'manual', 'force' => true, 'max_jobs' => 3 ) );
                self::back( $back, array(), 'ran', self::describe_tick( $r ) );
                break;

            case 'clear':
                if ( ! current_user_can( WAB_Security::CAP_MANAGE ) ) {
                    wp_die( esc_html__( 'Only administrators can clear the queue.', 'wonder-ai-builder' ), '', array( 'response' => 403 ) );
                }
                $n = WAB_Queue::drain();
                self::back( $back, array(), 'cleared', (string) $n );
                break;

            default:
                self::back( $back, array(), 'unknown_action' );
        }
    }

    /**
     * Turn a runner report into one plain sentence for the notice.
     */
    private static function describe_tick( array $r ) {
        $status = $r['status'] ?? 'unknown';

        switch ( $status ) {
            case 'ran':
                return sprintf(
                    /* translators: 1: processed 2: succeeded 3: failed */
                    __( 'Processed %1$d, created %2$d, failed %3$d.', 'wonder-ai-builder' ),
                    (int) ( $r['processed'] ?? 0 ),
                    (int) ( $r['succeeded'] ?? 0 ),
                    (int) ( $r['failed'] ?? 0 )
                );
            case 'idle':       return __( 'Nothing was queued, so there was nothing to do.', 'wonder-ai-builder' );
            case 'paused':     return __( 'The queue is paused.', 'wonder-ai-builder' );
            case 'budget':     return __( 'The daily budget has been reached.', 'wonder-ai-builder' );
            case 'locked':     return __( 'Another worker is already running.', 'wonder-ai-builder' );
            case 'high_load':  return __( 'Server load was too high, so it deferred.', 'wonder-ai-builder' );
            case 'low_memory': return __( 'Not enough free PHP memory to start safely.', 'wonder-ai-builder' );
            case 'throttled':  return __( 'It ran moments ago; try again in 20 seconds.', 'wonder-ai-builder' );
        }

        return (string) ( $r['message'] ?? $status );
    }

    // ---------------------------------------------------------------
    // Notices
    // ---------------------------------------------------------------

    /**
     * Render the redirect result as a native WordPress notice.
     * Called from the page header partial.
     */
    public static function render_notice() {
        if ( empty( $_GET['wab_msg'] ) ) return;

        $code   = sanitize_key( wp_unslash( $_GET['wab_msg'] ) );
        $detail = isset( $_GET['wab_detail'] )
            ? wp_strip_all_tags( rawurldecode( wp_unslash( $_GET['wab_detail'] ) ) )
            : '';

        $map = array(
            'queued'           => array( 'success', __( '%s row(s) queued. Generation is running on the server — you can leave this page.', 'wonder-ai-builder' ) ),
            'queued_resumed'   => array( 'success', __( '%s row(s) queued. The queue was paused, so it has been resumed automatically.', 'wonder-ai-builder' ) ),
            'nothing_selected' => array( 'warning', __( 'No rows were ticked, so nothing was queued.', 'wonder-ai-builder' ) ),
            'nothing_new'      => array( 'warning', __( 'Nothing new to queue. %s', 'wonder-ai-builder' ) ),
            'no_rows'          => array( 'warning', __( 'Those rows could not be found.', 'wonder-ai-builder' ) ),
            'no_import'        => array( 'error',   __( 'No sheet was specified.', 'wonder-ai-builder' ) ),
            'over_budget'      => array( 'error',   __( 'This run is estimated at $%s, which would exceed today\'s budget. Raise the budget in Settings or select fewer rows.', 'wonder-ai-builder' ) ),
            'sheet_deleted'    => array( 'success', __( 'Sheet deleted. Any pages it already created were kept.', 'wonder-ai-builder' ) ),
            'delete_busy'      => array( 'error',   __( 'That sheet still has %s job(s) in flight. Cancel them first.', 'wonder-ai-builder' ) ),
            'paused'           => array( 'info',    __( 'Queue paused. Nothing further will be generated until you resume.', 'wonder-ai-builder' ) ),
            'resumed'          => array( 'success', __( 'Queue resumed.', 'wonder-ai-builder' ) ),
            'ran'              => array( 'info',    __( 'Worker run complete. %s', 'wonder-ai-builder' ) ),
            'cleared'          => array( 'info',    __( '%s waiting job(s) cancelled.', 'wonder-ai-builder' ) ),
            'unknown_action'   => array( 'error',   __( 'Unrecognised action.', 'wonder-ai-builder' ) ),
        );

        if ( ! isset( $map[ $code ] ) ) return;

        list( $type, $template ) = $map[ $code ];

        $text = ( strpos( $template, '%s' ) !== false )
            ? sprintf( $template, $detail )
            : $template;

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr( $type ),
            esc_html( $text )
        );
    }
}
