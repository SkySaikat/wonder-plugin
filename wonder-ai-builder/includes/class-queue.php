<?php
/**
 * Bounded, lease-based job queue. Processes work; NEVER triggers work.
 *
 * ============================================================================
 * THE THREE v1 FAILURES THIS FIXES
 * ============================================================================
 *
 * (1) EXPONENTIAL FAN-OUT -> 200 sites down, 20-30GB RAM exhausted.
 *     v1's process_queue_batch() ended by spawning another worker
 *     (class-page-generator.php:196-201) via a non-blocking loopback POST. Parent
 *     did not wait or exit, so parent and child both ran and both spawned.
 *     FIX: there is no spawn primitive in this file. Grep it — nothing calls
 *     wp_remote_post, wp_schedule_single_event, or WAB_Runner. Work advances only
 *     when WAB_Runner ticks, and WAB_Lock permits exactly one worker site-wide.
 *
 * (2) DUPLICATE POSTS.
 *     v1 reset any job stuck in 'processing' for 5 minutes back to 'queued'
 *     (class-page-generator.php:154) with no heartbeat. A job legitimately taking
 *     longer than 5 minutes — trivial with two 120s API timeouts plus retries —
 *     was reclaimed WHILE STILL RUNNING. Two workers, two wp_insert_post() calls.
 *     FIX: lease + heartbeat. locked_until is pushed forward as the worker makes
 *     progress, so a slow job is never mistaken for a dead one. Plus
 *     UNIQUE(import_id, row_index) makes a second job for the same row impossible
 *     at the storage layer, and result_post_id is checked before any insert.
 *
 * (3) 5-10 IMAGES PER POST.
 *     v1 generated the image at step 2 and inserted the post at step 7
 *     (class-page-generator.php:253-309), persisting nothing in between. Every
 *     duplicate claim and every retry generated a fresh BILLED image.
 *     FIX: attachment_id is written to the job row the moment an image is
 *     resolved. Any retry reuses it. An image is paid for at most once per row.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Queue {

    const STATUS_QUEUED     = 'queued';
    const STATUS_PROCESSING = 'processing';
    const STATUS_DONE       = 'done';
    const STATUS_FAILED     = 'failed';
    const STATUS_CANCELLED  = 'cancelled';

    /** Lease duration. Must comfortably exceed the slowest realistic job. */
    const LEASE_SECONDS = 600;

    /** Hard ceiling on jobs per tick, whatever the setting says. */
    const MAX_JOBS_PER_TICK = 25;

    /** Wall-clock budget per tick, leaving headroom under a 60s cron interval. */
    const MAX_TICK_SECONDS = 45;

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wab_jobs';
    }

    // ---------------------------------------------------------------
    // Read helpers
    // ---------------------------------------------------------------

    /**
     * Is there work to do?
     *
     * MUST also match processing rows with an EXPIRED lease, not just queued ones.
     *
     * An earlier version tested `status = 'queued'` alone, which created a silent
     * work-loss path: WAB_Runner's idle gate returns before process_batch(), and
     * process_batch() is the only caller of reclaim_expired(). So if the worker was
     * OOM-killed while running the LAST remaining job, the table held 0 queued and
     * 1 processing-with-expired-lease — every later tick exited at the idle gate,
     * reclaim_expired() never ran, and that row was never generated or failed. It
     * simply showed "1 processing" forever.
     */
    public static function has_pending() {
        global $wpdb;
        $t = self::table();
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$t}
              WHERE status = %s
                 OR ( status = %s AND locked_until IS NOT NULL AND locked_until < UTC_TIMESTAMP() )
              LIMIT 1",
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING
        ) );
    }

    public static function counts( $import_id = '' ) {
        global $wpdb;
        $t = self::table();

        if ( $import_id !== '' ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT
                    SUM(status = %s) AS queued,
                    SUM(status = %s) AS processing,
                    SUM(status = %s) AS done,
                    SUM(status = %s) AS failed,
                    SUM(status = %s) AS cancelled,
                    COUNT(*)         AS total
                 FROM {$t} WHERE import_id = %s",
                self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_DONE,
                self::STATUS_FAILED, self::STATUS_CANCELLED, $import_id
            ), ARRAY_A );
        } else {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT
                    SUM(status = %s) AS queued,
                    SUM(status = %s) AS processing,
                    SUM(status = %s) AS done,
                    SUM(status = %s) AS failed,
                    SUM(status = %s) AS cancelled,
                    COUNT(*)         AS total
                 FROM {$t}",
                self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_DONE,
                self::STATUS_FAILED, self::STATUS_CANCELLED
            ), ARRAY_A );
        }

        $out = array();
        foreach ( array( 'queued', 'processing', 'done', 'failed', 'cancelled', 'total' ) as $k ) {
            $out[ $k ] = (int) ( $row[ $k ] ?? 0 );
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Enqueue
    // ---------------------------------------------------------------

    /**
     * Put rows into the queue.
     *
     * ============================================================================
     * THIS IS AN UPSERT, NOT AN INSERT. IT USED TO BE "INSERT IGNORE" AND THAT WAS
     * THE BUG THAT MADE THE WHOLE QUEUE APPEAR DEAD.
     * ============================================================================
     * wab_jobs carries UNIQUE(import_id,row_index) so a row can never produce two
     * posts. With a blind INSERT IGNORE, that same index silently swallowed every
     * re-queue attempt:
     *
     *   1. Row 0 is generated and fails (no API key, bad brief, rate limit...).
     *      A job row now exists with status='failed'.
     *   2. The operator fixes the cause, re-selects row 0, presses Generate.
     *   3. INSERT IGNORE collides with the unique index, affects 0 rows.
     *   4. enqueue() returns 0. The UI honestly reports "0 queued".
     *   5. Nothing ever happens again for that row. Permanently.
     *
     * The UI compounded it by leaving 'failed' and 'cancelled' rows selectable — as
     * it should, since retrying is the point — so the Generate button looked broken
     * rather than skipped.
     *
     * Correct behaviour, in two explicit statements rather than one clever one:
     *
     *   A. RE-OPEN existing jobs that are in a terminal state (failed/cancelled).
     *      Attempts reset to 0 so the operator gets a fresh retry budget.
     *      attachment_id and payload are DELIBERATELY PRESERVED — those represent
     *      money already spent, and reusing them is the anti-double-billing rule.
     *   B. INSERT rows that have no job at all.
     *
     * In-flight jobs (queued/processing/batched) and completed ones (done) are never
     * touched, so this cannot resurrect a running job or duplicate a post.
     *
     * ON DUPLICATE KEY UPDATE was rejected: within that clause MySQL exposes the
     * pre-update value for the first assignment but already-updated values for later
     * ones, so a conditional guard like IF(status IN (...)) silently changes meaning
     * depending on column order. Two statements are slower and obviously correct.
     *
     * @param string $import_id
     * @param array  $rows      Row objects with ->id and ->row_index.
     * @return int Number of rows now queued (newly inserted + re-opened).
     */
    public static function enqueue( $import_id, array $rows ) {
        global $wpdb;
        if ( empty( $rows ) ) return 0;

        $t   = self::table();
        $now = current_time( 'mysql' );

        // ---- Step A: re-open terminal jobs for the requested rows -------
        $indexes = array();
        foreach ( $rows as $r ) {
            $idx = is_object( $r )
                ? ( isset( $r->row_index ) ? $r->row_index : null )
                : ( $r['row_index'] ?? null );
            if ( $idx !== null ) $indexes[] = (int) $idx;
        }
        $indexes = array_values( array_unique( $indexes ) );

        $reopened = 0;

        if ( ! empty( $indexes ) ) {
            $ph     = implode( ',', array_fill( 0, count( $indexes ), '%d' ) );
            $params = array_merge(
                array( self::STATUS_QUEUED, $now, $import_id ),
                $indexes,
                array( self::STATUS_FAILED, self::STATUS_CANCELLED )
            );

            $reopened = (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$t}
                    SET status = %s,
                        attempts = 0,
                        locked_by = NULL,
                        locked_until = NULL,
                        error_code = NULL,
                        error_message = NULL,
                        updated_at = %s
                  WHERE import_id = %s
                    AND row_index IN ({$ph})
                    AND status IN (%s, %s)",
                $params
            ) );

            if ( $reopened > 0 ) {
                WAB_Logger::info( sprintf( 'Re-opened %d previously failed/cancelled job(s) for %s.', $reopened, $import_id ) );
            }
        }

        $values = array();
        $params = array();

        $skipped = 0;

        foreach ( $rows as $row ) {
            $row_id = (int) ( is_object( $row )
                ? ( isset( $row->id ) ? $row->id : 0 )
                : ( $row['id'] ?? 0 ) );

            // row_index must be PRESENT, never defaulted.
            //
            // Defaulting a missing row_index to 0 was silently destructive: with
            // UNIQUE(import_id,row_index), a caller passing partial rows (e.g.
            // array('id'=>12)) gave all 100 tuples row_index 0, INSERT IGNORE kept
            // exactly one, and enqueue() cheerfully returned 1 while the UI
            // reported "1 queued" with no error. Skip and count instead.
            $has_index = is_object( $row )
                ? isset( $row->row_index )
                : array_key_exists( 'row_index', (array) $row );

            if ( $row_id <= 0 || ! $has_index ) {
                $skipped++;
                continue;
            }

            $row_index = (int) ( is_object( $row ) ? $row->row_index : $row['row_index'] );

            $values[] = '(%s, %s, %d, %d, %s, 0, %s, %s)';
            array_push(
                $params,
                'job_' . wp_generate_uuid4(),
                $import_id,
                $row_id,
                $row_index,
                self::STATUS_QUEUED,
                $now,
                $now
            );
        }

        // Nothing new to insert, but re-opened jobs still count as queued work.
        if ( empty( $values ) ) return $reopened;

        $sql = "INSERT IGNORE INTO {$t}
                    (job_id, import_id, row_id, row_index, status, attempts, created_at, updated_at)
                VALUES " . implode( ', ', $values );

        $wpdb->query( $wpdb->prepare( $sql, $params ) );

        $inserted = (int) $wpdb->rows_affected;
        $total    = $inserted + $reopened;

        // Surface the delta. A silent shortfall here is how 100 requested pages
        // become 1 generated page with no visible error.
        $requested = count( $rows );
        if ( $skipped > 0 || $total < ( $requested - $skipped ) ) {
            WAB_Logger::warn( sprintf(
                'enqueue(): requested %d, malformed %d, newly inserted %d, re-opened %d. The remainder were already in flight or already done.',
                $requested,
                $skipped,
                $inserted,
                $reopened
            ) );
        }

        return $total;
    }

    /**
     * Why did a set of rows not get queued?
     *
     * enqueue() returning 0 is legitimate — every row may already be done or in
     * flight — but "0 queued" with no explanation is indistinguishable from a broken
     * button, which is exactly how the old INSERT IGNORE bug presented. This turns a
     * zero into a sentence the operator can act on.
     *
     * @param string $import_id
     * @param int[]  $row_indexes
     * @return string Human-readable explanation, or '' when rows were queued.
     */
    public static function explain_no_op( $import_id, array $row_indexes ) {
        global $wpdb;
        if ( empty( $row_indexes ) ) {
            return __( 'No rows were selected.', 'wonder-ai-builder' );
        }

        $t  = self::table();
        $ph = implode( ',', array_fill( 0, count( $row_indexes ), '%d' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) AS n FROM {$t}
              WHERE import_id = %s AND row_index IN ({$ph})
           GROUP BY status",
            array_merge( array( $import_id ), array_map( 'intval', $row_indexes ) )
        ) );

        if ( empty( $rows ) ) return '';

        $by = array();
        foreach ( $rows as $r ) $by[ $r->status ] = (int) $r->n;

        $parts = array();
        if ( ! empty( $by[ self::STATUS_DONE ] ) ) {
            $parts[] = sprintf(
                /* translators: %d: count */
                _n( '%d row has already been created', '%d rows have already been created', $by[ self::STATUS_DONE ], 'wonder-ai-builder' ),
                $by[ self::STATUS_DONE ]
            );
        }
        if ( ! empty( $by[ self::STATUS_PROCESSING ] ) ) {
            $parts[] = sprintf( __( '%d is being generated right now', 'wonder-ai-builder' ), $by[ self::STATUS_PROCESSING ] );
        }
        if ( ! empty( $by['batched'] ) ) {
            $parts[] = sprintf( __( '%d is waiting on an economy batch', 'wonder-ai-builder' ), $by['batched'] );
        }
        if ( ! empty( $by[ self::STATUS_QUEUED ] ) ) {
            $parts[] = sprintf( __( '%d is already in the queue', 'wonder-ai-builder' ), $by[ self::STATUS_QUEUED ] );
        }

        return empty( $parts ) ? '' : ( implode( ', ', $parts ) . '.' );
    }

    // ---------------------------------------------------------------
    // The worker
    // ---------------------------------------------------------------

    /**
     * Process a bounded batch.
     *
     * PRECONDITION: caller holds WAB_Lock::WORKER. This method does not acquire
     * the lock itself — that is WAB_Runner's job — but it refuses to run without it
     * so it can never be invoked directly and re-create the v1 concurrency bug.
     */
    public static function process_batch( array $opts = array() ) {
        if ( ! WAB_Lock::is_held_by_us( WAB_Lock::WORKER ) ) {
            WAB_Logger::error( 'process_batch() called without the worker lock. Refusing to run.' );
            return array( 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'error' => 'no_lock' );
        }

        self::reclaim_expired();

        $max_jobs = $opts['max_jobs'] ?? (int) get_option( 'wab_jobs_per_tick', 5 );
        $max_jobs = max( 1, min( self::MAX_JOBS_PER_TICK, (int) $max_jobs ) );

        // Economy mode, immediately after a batch submission: only touch jobs whose
        // text has already been paid for. Set by WAB_Runner — see the note on
        // WAB_Batch::maybe_submit()'s defer_unbatched flag.
        $payload_only = ! empty( $opts['payload_only'] );

        $started   = microtime( true );
        $processed = 0;
        $succeeded = 0;
        $failed    = 0;
        $attempted = array(); // job_ids already tried this tick.

        for ( $i = 0; $i < $max_jobs; $i++ ) {

            // --- Stop conditions checked BEFORE claiming, so we never claim a
            // --- job we cannot finish and leave it leased.
            if ( ( microtime( true ) - $started ) > self::MAX_TICK_SECONDS ) {
                WAB_Logger::info( 'Tick wall-clock budget reached; yielding.' );
                break;
            }

            if ( self::memory_pressure() ) {
                WAB_Logger::warn( 'Memory pressure; yielding mid-batch.' );
                break;
            }

            if ( WAB_Runner::is_paused() ) {
                break;
            }

            $budget = WAB_Cost_Guard::can_spend( self::estimated_job_cost() );
            if ( is_wp_error( $budget ) ) {
                WAB_Logger::warn( $budget->get_error_message() );
                break;
            }

            // Never re-claim a job already attempted in THIS tick.
            //
            // requeue() returns a job to 'queued' with no not-before time, and
            // claim_next() orders by row_index — so a row that failed retryably was
            // immediately the next thing claimed. A single 429 therefore burned all
            // 3 attempts back-to-back inside one tick (9 upstream calls after the
            // HTTP client's own internal retries) and consumed the whole max_jobs
            // budget on one row. Skipping it here defers the retry to the next tick,
            // which gives a natural ~60s backoff for free.
            $job = self::claim_next( $attempted, $payload_only );
            if ( ! $job ) break; // Nothing left to claim.

            $attempted[] = $job->job_id;
            $processed++;

            $result = self::run_job( $job );

            if ( is_wp_error( $result ) ) {
                $failed++;
            } else {
                $succeeded++;
            }

            // Keep the fallback lock fresh on long batches.
            WAB_Lock::touch( WAB_Lock::WORKER, 600 );
        }

        return array(
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'elapsed'   => round( microtime( true ) - $started, 2 ),
            'remaining' => self::counts()['queued'],
        );
    }

    /**
     * Atomically claim exactly one queued job.
     *
     * Two-step token pattern: stamp a unique token onto one row with a single
     * UPDATE (atomic under MySQL's row locking), then read back the row bearing
     * that token. This is race-free without an explicit transaction and works on
     * every MySQL/MariaDB version WordPress supports.
     *
     * @param array $skip         job_ids already attempted this tick.
     * @param bool  $payload_only Claim only jobs whose batched text is already paid
     *                            for. Economy mode only; see process_batch().
     */
    private static function claim_next( array $skip = array(), $payload_only = false ) {
        global $wpdb;
        $t = self::table();

        $token = wp_generate_uuid4();
        $now   = current_time( 'mysql' );
        $lease = gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS );

        $params = array( self::STATUS_PROCESSING, $token, $lease, $now, self::STATUS_QUEUED );

        // Note this deliberately does NOT filter on status 'batched' — those rows are
        // already invisible here because only 'queued' is selected. What it adds is the
        // narrower case: queued rows still awaiting a batch, which must not be
        // generated interactively while a batch is in flight for their siblings.
        $payload_clause = $payload_only ? " AND payload IS NOT NULL AND payload <> ''" : '';

        $skip_clause = '';
        if ( ! empty( $skip ) ) {
            $skip        = array_slice( array_values( array_unique( $skip ) ), 0, self::MAX_JOBS_PER_TICK );
            $skip_clause = ' AND job_id NOT IN ( ' . implode( ', ', array_fill( 0, count( $skip ), '%s' ) ) . ' )';
            $params      = array_merge( $params, $skip );
        }

        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$t}
                SET status = %s,
                    locked_by = %s,
                    locked_until = %s,
                    attempts = attempts + 1,
                    updated_at = %s
              WHERE status = %s
              {$payload_clause}
              {$skip_clause}
              ORDER BY row_index ASC, id ASC
              LIMIT 1",
            $params
        ) );

        // Distinguish "nothing to claim" (0) from a genuine SQL failure (false),
        // otherwise a broken table silently reports processed => 0 with no log.
        if ( $updated === false ) {
            WAB_Logger::error( 'claim_next() UPDATE failed: ' . $wpdb->last_error );
            return null;
        }

        if ( ! $updated ) return null;

        $job = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE locked_by = %s LIMIT 1",
            $token
        ) );

        return $job ?: null;
    }

    /**
     * Extend the lease. Called by the generator between expensive steps so a slow
     * job is never mistaken for a dead one — the precise failure that produced
     * v1's duplicate posts.
     */
    public static function heartbeat( $job_id ) {
        global $wpdb;
        $wpdb->update(
            self::table(),
            array(
                // UTC — compared against UTC_TIMESTAMP() in reclaim_expired().
                'locked_until' => gmdate( 'Y-m-d H:i:s', time() + self::LEASE_SECONDS ),
                'updated_at'   => current_time( 'mysql' ),
            ),
            array( 'job_id' => $job_id )
        );

        // ALSO refresh the WORKER lock, not just the job lease.
        //
        // On the wp_options fallback path the worker lock carries a TTL, and it was
        // previously only touched BETWEEN jobs. A single job can exceed it: the fal
        // client alone allows 180s x 3 attempts plus backoff (~560s) before the text
        // call even starts. Once the lock row expired, the next tick would acquire
        // it and a second process_batch() loop would run concurrently — duplicate
        // posts are still blocked by the atomic claim and UNIQUE(import_id,row_index),
        // but the single-worker memory/load guarantee (the actual fix for the
        // 200-site outage) would be gone, and WAB_Cost_Guard's read-modify-write on
        // daily spend would start losing increments and under-report cost.
        WAB_Lock::touch( WAB_Lock::WORKER, self::LEASE_SECONDS + 120 );
    }

    /**
     * Persist the resolved image against the job BEFORE the post is created, so a
     * retry can never re-bill it.
     */
    public static function record_attachment( $job_id, $attachment_id ) {
        global $wpdb;
        $wpdb->update(
            self::table(),
            array( 'attachment_id' => (int) $attachment_id, 'updated_at' => current_time( 'mysql' ) ),
            array( 'job_id' => $job_id )
        );
    }

    public static function record_cost( $job_id, $usd ) {
        global $wpdb;
        $t = self::table();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t} SET cost_usd = cost_usd + %f, updated_at = %s WHERE job_id = %s",
            (float) $usd,
            current_time( 'mysql' ),
            $job_id
        ) );
    }

    /**
     * Execute one job, translating the outcome into a terminal state.
     */
    private static function run_job( $job ) {
        // IDEMPOTENCY GATE: if this job already produced a post, never make another.
        if ( ! empty( $job->result_post_id ) && get_post_status( (int) $job->result_post_id ) !== false ) {
            self::mark_done( $job->job_id, (int) $job->result_post_id );
            WAB_Logger::info( sprintf( 'Job %s already produced post %d; skipping.', $job->job_id, $job->result_post_id ) );
            return (int) $job->result_post_id;
        }

        $result = WAB_Generator::process_job( $job );

        if ( is_wp_error( $result ) ) {
            $attempts = (int) $job->attempts;

            if ( WAB_Cost_Guard::should_retry( $result, $attempts ) ) {
                self::requeue( $job->job_id, $result );
                return $result;
            }

            self::mark_failed( $job->job_id, $result );
            return $result;
        }

        self::mark_done( $job->job_id, (int) $result );
        return (int) $result;
    }

    // ---------------------------------------------------------------
    // Terminal states
    // ---------------------------------------------------------------

    private static function mark_done( $job_id, $post_id ) {
        global $wpdb;
        $wpdb->update(
            self::table(),
            array(
                'status'         => self::STATUS_DONE,
                'result_post_id' => (int) $post_id,
                'locked_by'      => null,
                'locked_until'   => null,
                'error_code'     => null,
                'error_message'  => null,
                'updated_at'     => current_time( 'mysql' ),
            ),
            array( 'job_id' => $job_id )
        );
    }

    private static function mark_failed( $job_id, $error ) {
        global $wpdb;
        $wpdb->update(
            self::table(),
            array(
                'status'        => self::STATUS_FAILED,
                'locked_by'     => null,
                'locked_until'  => null,
                'error_code'    => is_wp_error( $error ) ? mb_substr( $error->get_error_code(), 0, 60 ) : 'unknown',
                'error_message' => is_wp_error( $error ) ? mb_substr( $error->get_error_message(), 0, 500 ) : 'Unknown error',
                'updated_at'    => current_time( 'mysql' ),
            ),
            array( 'job_id' => $job_id )
        );
    }

    /**
     * Return a job to the queue after a retryable failure.
     *
     * Note attempts was already incremented at claim time, so the ceiling in
     * WAB_Cost_Guard::should_retry() is enforced without a separate counter.
     */
    private static function requeue( $job_id, $error ) {
        global $wpdb;
        $wpdb->update(
            self::table(),
            array(
                'status'        => self::STATUS_QUEUED,
                'locked_by'     => null,
                'locked_until'  => null,
                'error_code'    => is_wp_error( $error ) ? mb_substr( $error->get_error_code(), 0, 60 ) : 'retry',
                'error_message' => is_wp_error( $error ) ? mb_substr( $error->get_error_message(), 0, 500 ) : '',
                'updated_at'    => current_time( 'mysql' ),
            ),
            array( 'job_id' => $job_id )
        );
    }

    /**
     * Recover jobs whose lease genuinely expired — i.e. the worker died.
     *
     * Contrast with v1, which reset on a fixed 5-minute wall clock regardless of
     * whether the worker was still alive. Here the lease is heartbeat-extended, so
     * expiry really does mean the process is gone. Jobs already at the retry
     * ceiling are failed rather than looped forever.
     */
    private static function reclaim_expired() {
        global $wpdb;
        $t   = self::table();
        $now = current_time( 'mysql' );

        // Past the retry ceiling: terminal failure, never billed again.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t}
                SET status = %s, locked_by = NULL, locked_until = NULL,
                    error_code = 'wab_lease_expired',
                    error_message = 'Worker died and the retry ceiling was reached.',
                    updated_at = %s
              WHERE status = %s
                AND locked_until IS NOT NULL
                AND locked_until < UTC_TIMESTAMP()
                AND attempts >= %d",
            self::STATUS_FAILED,
            $now,
            self::STATUS_PROCESSING,
            WAB_Cost_Guard::MAX_RETRIES
        ) );

        // Still has attempts left: return to the queue.
        $reclaimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$t}
                SET status = %s, locked_by = NULL, locked_until = NULL, updated_at = %s
              WHERE status = %s
                AND locked_until IS NOT NULL
                AND locked_until < UTC_TIMESTAMP()
                AND attempts < %d",
            self::STATUS_QUEUED,
            $now,
            self::STATUS_PROCESSING,
            WAB_Cost_Guard::MAX_RETRIES
        ) );

        if ( $reclaimed > 0 ) {
            WAB_Logger::warn( sprintf( 'Reclaimed %d job(s) with expired leases.', $reclaimed ) );
        }
    }

    // ---------------------------------------------------------------
    // Operator actions
    // ---------------------------------------------------------------

    public static function cancel_job( $job_id ) {
        global $wpdb;
        return (bool) $wpdb->update(
            self::table(),
            array(
                'status'       => self::STATUS_CANCELLED,
                'locked_by'    => null,
                'locked_until' => null,
                'updated_at'   => current_time( 'mysql' ),
            ),
            array( 'job_id' => $job_id )
        );
    }

    public static function retry_job( $job_id ) {
        global $wpdb;
        return (bool) $wpdb->update(
            self::table(),
            array(
                'status'        => self::STATUS_QUEUED,
                'attempts'      => 0,
                'locked_by'     => null,
                'locked_until'  => null,
                'error_code'    => null,
                'error_message' => null,
                'updated_at'    => current_time( 'mysql' ),
            ),
            array( 'job_id' => $job_id )
        );
    }

    /**
     * Cancel everything queued. The kill switch's teeth.
     */
    public static function drain() {
        global $wpdb;
        $t = self::table();
        $n = $wpdb->query( $wpdb->prepare(
            "UPDATE {$t} SET status = %s, locked_by = NULL, locked_until = NULL, updated_at = %s WHERE status = %s",
            self::STATUS_CANCELLED,
            current_time( 'mysql' ),
            self::STATUS_QUEUED
        ) );
        WAB_Logger::warn( sprintf( 'Queue drained: %d job(s) cancelled.', (int) $n ) );
        return (int) $n;
    }

    public static function get_jobs( array $args = array() ) {
        global $wpdb;
        $t = self::table();

        $per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 25 ) ) );
        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where  = array( '1=1' );
        $params = array();

        if ( ! empty( $args['import_id'] ) ) {
            $where[]  = 'import_id = %s';
            $params[] = $args['import_id'];
        }
        if ( ! empty( $args['status'] ) && $args['status'] !== 'all' ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        $clause = implode( ' AND ', $where );

        $sql      = "SELECT * FROM {$t} WHERE {$clause} ORDER BY row_index ASC, id ASC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $jobs = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $count_params = array_slice( $params, 0, count( $params ) - 2 );
        $total = empty( $count_params )
            ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$clause}" )
            : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$clause}", $count_params ) );

        return array(
            'jobs'     => is_array( $jobs ) ? $jobs : array(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        );
    }

    // ---------------------------------------------------------------
    // Guards
    // ---------------------------------------------------------------

    private static function memory_pressure() {
        $limit = WAB_Runner::memory_limit_bytes();
        if ( $limit <= 0 ) return false;
        return memory_get_usage( true ) > ( $limit * 0.75 );
    }

    /**
     * Pre-flight cost estimate, routed through the ACTIVE provider and model.
     *
     * An earlier version hardcoded the schnell unit price ($0.003) regardless of the
     * selected model. Choosing fal-ai/flux-2-pro ($0.030/MP) therefore made the
     * estimate 10x low, so the budget gate would happily start a batch that blew
     * straight past the daily cap. The registry asks each provider to price its own
     * model, and factors in the observed local-library hit rate.
     */
    private static function estimated_job_cost() {
        return WAB_Provider_Registry::estimate_item_cost();
    }
}
