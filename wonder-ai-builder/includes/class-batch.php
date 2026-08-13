<?php
/**
 * Economy batch mode — asynchronous text generation at ~50% of interactive cost.
 *
 * ============================================================================
 * WHAT IS AND IS NOT BATCHED
 * ============================================================================
 * ONLY the text call is batched, because that is the only part with a batch
 * discount. Images and post creation stay local and synchronous — they are either
 * free (library match) or already cheap, and they need the WordPress runtime.
 *
 * So a job in economy mode runs in TWO phases:
 *
 *   Phase 1 (batched)  queued --> batched --> queued, with `payload` filled in
 *   Phase 2 (local)    normal worker consumes `payload`, skips the API call
 *                      entirely, then does image + insert + schema
 *
 * Storing the returned text on the job has a second benefit beyond cost: once
 * phase 1 is paid for, any failure in phase 2 retries WITHOUT re-billing the text.
 * Same principle as persisting attachment_id.
 *
 * ============================================================================
 * SAFETY PROPERTIES
 * ============================================================================
 *  - Submission and polling both run INSIDE the worker lock, so two workers can
 *    never submit the same jobs twice or double-ingest a result.
 *  - Jobs move to status 'batched', which claim_next() does not select. They are
 *    invisible to the normal worker until results land.
 *  - Gemini batches EXPIRE after 48h. A stale batch returns its jobs to 'queued'
 *    for standard processing rather than stranding them — the silent-work-loss
 *    failure mode this plugin exists to avoid.
 *  - Batch submission is capped per tick, so a 5,000-row import cannot build one
 *    unbounded HTTP request.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Implemented by text providers that expose an async batch endpoint.
 */
interface WAB_Batch_Provider_Interface {

    /**
     * Submit requests.
     *
     * @param array $requests  [ [ 'key' => job_id, 'prefix' =>, 'delta' =>, 'schema' =>, 'max_tokens' => ], ... ]
     * @return array|WP_Error  [ 'batch_id' => provider handle, 'model' => ... ]
     */
    public function submit_batch( array $requests, array $args = array() );

    /**
     * @return array|WP_Error [ 'state' => pending|running|succeeded|failed|expired, 'raw' => string ]
     */
    public function poll_batch( $batch_id );

    /**
     * @return array|WP_Error [ job_id => [ 'data' => array, 'cost' => float, 'error' => string ] ]
     */
    public function fetch_batch_results( $batch_id );
}

class WAB_Batch {

    /** Max jobs per submitted batch. Keeps the request body sane. */
    const MAX_PER_BATCH = 200;

    /** Don't bother batching fewer than this — the wait is not worth the saving. */
    const MIN_PER_BATCH = 10;

    /** Give up on a batch after this long and fall back to standard processing. */
    const MAX_WAIT_SECONDS = 172800; // 48h, matching Gemini's expiry.

    const STATUS_BATCHED = 'batched';

    private static function jobs_table() {
        global $wpdb;
        return $wpdb->prefix . 'wab_jobs';
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wab_batches';
    }

    /**
     * Does the batch table actually exist?
     *
     * Guarding every read here is not paranoia — it fixed a real, badly-disguised
     * failure. On an install whose schema had not been migrated to DB_VERSION 3,
     * wp_wab_batches was absent, so summary() raised a wpdb error. With
     * show_errors on (WP_DEBUG, or many managed hosts) that error text was printed
     * INTO the ajax_status response, making the JSON unparseable. The JS then saw
     * no `success` flag and returned silently, so the Queue screen sat empty while
     * the header still reported "Running". The visible symptom — "no data in the
     * queue" — was three layers away from the actual cause.
     *
     * Cached per request; the transient is cleared whenever the schema is repaired.
     */
    private static function table_exists() {
        static $exists = null;
        if ( $exists !== null ) return $exists;

        if ( get_transient( 'wab_batches_ok' ) === 'yes' ) {
            $exists = true;
            return true;
        }

        global $wpdb;
        $t      = self::table();
        $exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t );

        if ( $exists ) set_transient( 'wab_batches_ok', 'yes', HOUR_IN_SECONDS );

        return $exists;
    }

    public static function enabled() {
        return get_option( 'wab_generation_mode', 'standard' ) === 'economy';
    }

    /**
     * Is batching actually available right now? Reports WHY not, so the UI can
     * explain rather than silently falling back.
     *
     * @return true|WP_Error
     */
    public static function availability() {
        if ( ! self::enabled() ) {
            return new WP_Error( 'wab_batch_off', __( 'Economy mode is not enabled.', 'wonder-ai-builder' ) );
        }

        $provider = WAB_Provider_Registry::text();

        if ( ! $provider instanceof WAB_Batch_Provider_Interface ) {
            return new WP_Error( 'wab_batch_unsupported', sprintf(
                /* translators: %s: provider name */
                __( '%s does not support batch submission in this version. Jobs will run in Standard mode.', 'wonder-ai-builder' ),
                $provider->get_label()
            ) );
        }

        if ( ! $provider->is_configured() ) {
            return new WP_Error( 'wab_no_key', __( 'Text provider is not configured.', 'wonder-ai-builder' ) );
        }

        return true;
    }

    // ---------------------------------------------------------------
    // Phase 1a: submit
    // ---------------------------------------------------------------

    /**
     * Collect queued jobs that have no payload yet and submit them as one batch.
     *
     * PRECONDITION: caller holds the worker lock.
     *
     * @return array Report.
     */
    public static function maybe_submit() {
        global $wpdb;

        if ( ! WAB_Lock::is_held_by_us( WAB_Lock::WORKER ) ) {
            return array( 'submitted' => 0, 'skipped' => 'no_lock' );
        }

        $available = self::availability();
        if ( is_wp_error( $available ) ) {
            return array( 'submitted' => 0, 'skipped' => $available->get_error_code() );
        }

        $jobs_t = self::jobs_table();

        // Only jobs with NO payload need a text call.
        $jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$jobs_t}
              WHERE status = %s
                AND ( payload IS NULL OR payload = '' )
                AND attempts < %d
              ORDER BY row_index ASC, id ASC
              LIMIT %d",
            WAB_Queue::STATUS_QUEUED,
            WAB_Cost_Guard::MAX_RETRIES,
            self::MAX_PER_BATCH
        ) );

        if ( count( (array) $jobs ) < self::MIN_PER_BATCH ) {
            // Too few to be worth a 24h wait; let the standard path handle them.
            return array( 'submitted' => 0, 'skipped' => 'below_threshold', 'available' => count( (array) $jobs ) );
        }

        // Budget check against the DISCOUNTED cost.
        $per_item  = WAB_Provider_Registry::estimate_item_cost() * 0.5;
        $projected = $per_item * count( $jobs );

        $budget = WAB_Cost_Guard::can_spend( $projected );
        if ( is_wp_error( $budget ) ) {
            return array( 'submitted' => 0, 'skipped' => 'budget' );
        }

        $requests = array();
        $job_ids  = array();

        foreach ( $jobs as $job ) {
            $built = self::build_request( $job );
            if ( is_wp_error( $built ) ) {
                // Deterministic problem with this row — fail it now rather than
                // dragging it through a 24h batch to fail anyway.
                $wpdb->update( $jobs_t, array(
                    'status'        => WAB_Queue::STATUS_FAILED,
                    'error_code'    => $built->get_error_code(),
                    'error_message' => mb_substr( $built->get_error_message(), 0, 500 ),
                    'updated_at'    => current_time( 'mysql' ),
                ), array( 'job_id' => $job->job_id ) );
                continue;
            }

            $requests[] = $built;
            $job_ids[]  = $job->job_id;
        }

        if ( count( $requests ) < self::MIN_PER_BATCH ) {
            return array( 'submitted' => 0, 'skipped' => 'below_threshold_after_validation' );
        }

        $provider = WAB_Provider_Registry::text();
        $result   = $provider->submit_batch( $requests );

        if ( is_wp_error( $result ) ) {
            WAB_Logger::error( 'Batch submission failed: ' . $result->get_error_message() );
            // Jobs stay 'queued' — the standard path will pick them up, so a batch
            // outage degrades to full-price generation rather than to a stall.
            return array( 'submitted' => 0, 'error' => $result->get_error_message() );
        }

        $batch_id = (string) $result['batch_id'];

        $wpdb->insert( self::table(), array(
            'batch_id'     => $batch_id,
            'provider'     => $provider->get_id(),
            'model'        => (string) ( $result['model'] ?? '' ),
            'status'       => 'pending',
            'job_count'    => count( $job_ids ),
            'submitted_at' => current_time( 'mysql' ),
        ) );

        // Mark jobs batched so the normal worker ignores them.
        //
        // attempts is incremented HERE, not only in claim_next(). A batch submission is
        // a paid attempt like any other, and without counting it a row that fails
        // inside the batch is returned to 'queued' with attempts untouched — so it is
        // re-selected by the next maybe_submit(), fails again for the same deterministic
        // reason, and re-bills forever. Counting it means the MAX_RETRIES filter in the
        // SELECT above eventually stops batching that row and lets the standard path
        // fail it visibly instead.
        $placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%s' ) );
        $params       = array_merge(
            array( self::STATUS_BATCHED, $batch_id, current_time( 'mysql' ) ),
            $job_ids
        );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$jobs_t}
                SET status = %s, batch_id = %s, attempts = attempts + 1,
                    locked_by = NULL, locked_until = NULL, updated_at = %s
              WHERE job_id IN ({$placeholders})",
            $params
        ) );

        WAB_Logger::warn( sprintf(
            'Economy batch %s submitted with %d job(s). Estimated $%.4f (~50%% saving). Results usually arrive well inside 24h.',
            $batch_id,
            count( $job_ids ),
            $projected
        ) );

        return array(
            'submitted' => count( $job_ids ),
            'batch_id'  => $batch_id,
            'estimate'  => round( $projected, 5 ),

            /**
             * Tell the worker not to burn full price on rows that are about to be
             * batched. See WAB_Runner's use of this flag.
             *
             * THE LEAK THIS CLOSES: submission is capped at MAX_PER_BATCH, so a 250-row
             * import leaves 50 rows still 'queued'. process_batch() runs in this same
             * tick and cheerfully generated them INTERACTIVELY at full price — the exact
             * saving the operator switched to Economy for, spent on the rows that just
             * missed the cut, every tick.
             *
             * Only ever set on a SUCCESSFUL submission, which is what makes it
             * stall-proof: if batching is unavailable, below threshold, over budget or
             * erroring, submitted is 0, this key is absent, and the standard worker
             * processes everything as normal. Degrading to full price is fine; stalling
             * is not.
             */
            'defer_unbatched' => true,
        );
    }

    /**
     * Build one batch request from a job. Mirrors WAB_Generator's prompt assembly so
     * batched and standard output are identical.
     */
    private static function build_request( $job ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wab_rows WHERE id = %d LIMIT 1",
            (int) $job->row_id
        ) );

        if ( ! $row ) {
            return new WP_Error( 'wab_bad_request', __( 'Source row no longer exists.', 'wonder-ai-builder' ) );
        }

        $import = $wpdb->get_row( $wpdb->prepare(
            "SELECT content_mode, target_words FROM {$wpdb->prefix}wab_imports WHERE import_id = %s LIMIT 1",
            $job->import_id
        ) );

        $mode     = WAB_Prompt_Builder::mode_for( $import );
        $want_faq = (bool) get_option( 'wab_enable_faq', 1 );

        // Same resolver as the interactive path, so Settings -> Default word count and
        // the sheet's exact word count apply to batched rows too. They previously did
        // not: this method read target_words directly and never consulted the option,
        // so switching to Economy silently reverted every page to its depth preset.
        $target_words = WAB_Prompt_Builder::target_words_for( $row, $import );

        return array(
            'key'        => $job->job_id,
            'prefix'     => WAB_Generator::prefix_for_import( $job->import_id, $row, $mode ),
            'delta'      => WAB_Prompt_Builder::build_delta( $row, array(
                'mode'              => $mode,
                'row_index'         => (int) $job->row_index,
                'row_words'         => $target_words,
                // Parity with WAB_Generator: without this, batched location pages lose
                // their nearby-area internal links and read differently to live ones.
                'sibling_locations' => WAB_Generator::siblings_for( $job->import_id, $row ),
                'internal_links'    => WAB_Scanner::internal_link_candidates( $row ),
            ) ),
            'schema'     => WAB_Prompt_Builder::output_schema( $want_faq ),
            'max_tokens' => WAB_Prompt_Builder::estimate_output_tokens( $mode, $want_faq, $target_words ),
        );
    }

    // ---------------------------------------------------------------
    // Phase 1b: poll + ingest
    // ---------------------------------------------------------------

    /**
     * Poll every open batch and ingest anything finished.
     *
     * PRECONDITION: caller holds the worker lock.
     */
    public static function poll_all() {
        global $wpdb;

        if ( ! WAB_Lock::is_held_by_us( WAB_Lock::WORKER ) ) {
            return array( 'polled' => 0 );
        }

        $batches_t = self::table();

        $orphans = self::rescue_orphans();

        $open = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$batches_t} WHERE status IN (%s, %s) ORDER BY id ASC LIMIT 5",
            'pending',
            'running'
        ) );

        if ( empty( $open ) ) return array( 'polled' => 0, 'orphans' => $orphans );

        $provider = WAB_Provider_Registry::text();
        if ( ! $provider instanceof WAB_Batch_Provider_Interface ) {
            return array( 'polled' => 0 );
        }

        $ingested = 0;

        foreach ( $open as $batch ) {

            // Expiry / abandonment guard.
            $age = time() - strtotime( $batch->submitted_at );
            if ( $age > self::MAX_WAIT_SECONDS ) {
                self::release_batch( $batch, 'expired', __( 'Batch exceeded the maximum wait; jobs returned to standard processing.', 'wonder-ai-builder' ) );
                continue;
            }

            $status = $provider->poll_batch( $batch->batch_id );
            if ( is_wp_error( $status ) ) {
                WAB_Logger::warn( sprintf( 'Polling batch %s failed: %s', $batch->batch_id, $status->get_error_message() ) );
                continue;
            }

            $state = $status['state'];

            if ( $state === 'pending' || $state === 'running' ) {
                $wpdb->update( self::table(), array( 'status' => $state ), array( 'id' => (int) $batch->id ) );
                continue;
            }

            if ( $state === 'failed' || $state === 'expired' || $state === 'cancelled' ) {
                self::release_batch( $batch, $state, sprintf(
                    /* translators: %s: state */
                    __( 'Batch ended in state "%s"; jobs returned to standard processing.', 'wonder-ai-builder' ),
                    $state
                ) );
                continue;
            }

            // Succeeded.
            $results = $provider->fetch_batch_results( $batch->batch_id );
            if ( is_wp_error( $results ) ) {
                WAB_Logger::error( sprintf( 'Fetching batch %s results failed: %s', $batch->batch_id, $results->get_error_message() ) );
                continue;
            }

            $ingested += self::ingest( $batch, $results );
        }

        return array( 'polled' => count( $open ), 'ingested' => $ingested, 'orphans' => $orphans );
    }

    /**
     * Backstop: free any job marked 'batched' that no longer has a live batch to wait
     * for — because its batch row was cleared, or the table was rebuilt, or a code
     * path ended a batch without releasing its jobs.
     *
     * Deliberately broad, because the cost asymmetry is extreme. A false positive
     * regenerates one page at full price; a false negative leaves a row invisible to
     * claim_next() forever, since 'batched' is not a status the worker selects. The
     * only reason this is safe to run every tick is that jobs belonging to a genuinely
     * open batch are excluded by the sub-select.
     *
     * @return int Jobs released.
     */
    private static function rescue_orphans() {
        global $wpdb;

        if ( ! self::table_exists() ) return 0;

        $jobs_t    = self::jobs_table();
        $batches_t = self::table();

        $released = (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$jobs_t} j
                SET j.status = %s, j.batch_id = NULL, j.error_code = %s, j.error_message = %s, j.updated_at = %s
              WHERE j.status = %s
                AND ( j.batch_id IS NULL OR j.batch_id NOT IN (
                        SELECT b.batch_id FROM {$batches_t} b WHERE b.status IN (%s, %s)
                ) )",
            WAB_Queue::STATUS_QUEUED,
            'wab_batch_orphaned',
            'No open batch was left to deliver this row, so it returned to the standard queue.',
            current_time( 'mysql' ),
            self::STATUS_BATCHED,
            'pending',
            'running'
        ) );

        if ( $released > 0 ) {
            WAB_Logger::warn( sprintf( '%d job(s) were waiting on a batch that no longer exists; returned to the queue.', $released ) );
        }

        return $released;
    }

    /**
     * Write returned payloads onto their jobs and return them to the queue for
     * local phase-2 processing.
     */
    private static function ingest( $batch, array $results ) {
        global $wpdb;

        $jobs_t = self::jobs_table();
        $now    = current_time( 'mysql' );
        $ok     = 0;
        $failed = 0;
        $cost   = 0.0;

        foreach ( $results as $job_id => $r ) {

            if ( ! empty( $r['error'] ) ) {
                // Per-request failure inside a successful batch.
                $wpdb->update( $jobs_t, array(
                    'status'        => WAB_Queue::STATUS_QUEUED, // Retry via standard path.
                    'batch_id'      => null,
                    'error_code'    => 'wab_batch_item_failed',
                    'error_message' => mb_substr( (string) $r['error'], 0, 500 ),
                    'updated_at'    => $now,
                ), array( 'job_id' => $job_id ) );
                $failed++;
                continue;
            }

            if ( empty( $r['data'] ) || ! is_array( $r['data'] ) || empty( $r['data']['content'] ) ) {
                $wpdb->update( $jobs_t, array(
                    'status'        => WAB_Queue::STATUS_QUEUED,
                    'batch_id'      => null,
                    'error_code'    => 'wab_batch_empty',
                    'error_message' => 'Batch returned no usable content for this row.',
                    'updated_at'    => $now,
                ), array( 'job_id' => $job_id ) );
                $failed++;
                continue;
            }

            $item_cost = (float) ( $r['cost'] ?? 0 );
            $cost     += $item_cost;

            // payload holds the paid-for text. Phase 2 consumes it and never calls
            // the text API again, even across retries.
            $wpdb->update( $jobs_t, array(
                'status'        => WAB_Queue::STATUS_QUEUED,
                'payload'       => wp_json_encode( $r['data'] ),
                'error_code'    => null,
                'error_message' => null,
                'updated_at'    => $now,
            ), array( 'job_id' => $job_id ) );

            if ( $item_cost > 0 ) {
                WAB_Queue::record_cost( $job_id, $item_cost );
            }

            $ok++;
        }

        if ( $cost > 0 ) {
            WAB_Cost_Guard::record( $cost, 'text' );
        }

        /**
         * Rescue rows the batch simply did not answer for.
         *
         * ingest() can only update jobs that APPEAR in the results, and a result can
         * legitimately go missing: fetch_batch_results() skips any item it cannot
         * attribute (no metadata key) rather than guessing by position. Those jobs
         * would keep status 'batched' — which claim_next() never selects — while the
         * batch row was marked succeeded and stopped being polled. The row would then
         * sit "waiting on an economy batch" forever with nothing left to wait for.
         * Silent work loss, which is precisely what this plugin refuses to do.
         *
         * Returning them to 'queued' costs a full-price regeneration at worst.
         */
        $stranded = (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$jobs_t}
                SET status = %s, batch_id = NULL, error_code = %s, error_message = %s, updated_at = %s
              WHERE batch_id = %s AND status = %s",
            WAB_Queue::STATUS_QUEUED,
            'wab_batch_missing_result',
            'The batch completed without returning this row; it was re-queued for standard generation.',
            $now,
            $batch->batch_id,
            self::STATUS_BATCHED
        ) );

        if ( $stranded > 0 ) {
            WAB_Logger::warn( sprintf(
                'Batch %s returned no result for %d job(s); they were re-queued rather than left waiting.',
                $batch->batch_id,
                $stranded
            ) );
        }

        $wpdb->update( self::table(), array(
            'status'       => 'succeeded',
            'completed_at' => $now,
            'cost_usd'     => round( $cost, 6 ),
        ), array( 'id' => (int) $batch->id ) );

        WAB_Logger::warn( sprintf(
            'Batch %s ingested: %d ready for local processing, %d returned for retry. Text cost $%.4f.',
            $batch->batch_id,
            $ok,
            $failed,
            $cost
        ) );

        return $ok;
    }

    /**
     * Return a dead batch's jobs to the standard queue.
     *
     * Degrading to full price is always better than losing the work silently.
     */
    private static function release_batch( $batch, $state, $message ) {
        global $wpdb;

        $jobs_t = self::jobs_table();
        $now    = current_time( 'mysql' );

        $released = $wpdb->query( $wpdb->prepare(
            "UPDATE {$jobs_t}
                SET status = %s, batch_id = NULL, error_code = %s, error_message = %s, updated_at = %s
              WHERE batch_id = %s AND status = %s",
            WAB_Queue::STATUS_QUEUED,
            'wab_batch_' . $state,
            mb_substr( $message, 0, 500 ),
            $now,
            $batch->batch_id,
            self::STATUS_BATCHED
        ) );

        $wpdb->update( self::table(), array(
            'status'       => $state,
            'completed_at' => $now,
            'error'        => mb_substr( $message, 0, 500 ),
        ), array( 'id' => (int) $batch->id ) );

        WAB_Logger::warn( sprintf( 'Batch %s %s — %d job(s) returned to standard processing.', $batch->batch_id, $state, (int) $released ) );
    }

    // ---------------------------------------------------------------
    // Reporting
    // ---------------------------------------------------------------

    /**
     * Are there batches awaiting results?
     *
     * WAB_Runner's idle gate MUST consult this. Without it, an import whose every
     * job is 'batched' has nothing 'queued', so the tick would exit at Gate 3 —
     * and since poll_all() only runs after the lock is taken, results would never
     * be ingested and the whole import would stall silently forever. Exactly the
     * failure mode that made has_pending() consider expired leases.
     */
    public static function has_open() {
        if ( ! self::table_exists() ) return false;

        global $wpdb;
        $t = self::table();

        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$t} WHERE status IN (%s, %s) LIMIT 1",
            'pending',
            'running'
        ) );
    }

    public static function summary() {
        // Never query a table that may not exist — a wpdb error here corrupts the
        // JSON of whatever AJAX response is being built.
        if ( ! self::table_exists() ) {
            return array(
                'enabled'     => self::enabled(),
                'available'   => false,
                'reason'      => __( 'Batch table missing — visit System status to repair the database.', 'wonder-ai-builder' ),
                'open'        => array(),
                'in_flight'   => 0,
                'ready_local' => 0,
                'batch_spend' => 0.0,
            );
        }

        global $wpdb;

        $t = self::table();

        $open = $wpdb->get_results(
            "SELECT batch_id, status, job_count, submitted_at FROM {$t}
              WHERE status IN ('pending','running') ORDER BY id DESC LIMIT 5"
        );

        $batched = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::jobs_table() . " WHERE status = %s",
            self::STATUS_BATCHED
        ) );

        $ready = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::jobs_table() . "
              WHERE status = %s AND payload IS NOT NULL AND payload <> ''",
            WAB_Queue::STATUS_QUEUED
        ) );

        $saved = (float) $wpdb->get_var( "SELECT COALESCE(SUM(cost_usd),0) FROM {$t} WHERE status = 'succeeded'" );

        $availability = self::availability();

        return array(
            'enabled'     => self::enabled(),
            'available'   => ! is_wp_error( $availability ),
            'reason'      => is_wp_error( $availability ) ? $availability->get_error_message() : '',
            'open'        => $open ?: array(),
            'in_flight'   => $batched,
            'ready_local' => $ready,
            'batch_spend' => round( $saved, 4 ),
        );
    }
}
