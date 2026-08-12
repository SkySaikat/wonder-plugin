<?php
/**
 * Trigger layer: decides WHEN the worker may run. Never runs work itself.
 *
 * ============================================================================
 * DESIGN PRINCIPLE
 * ============================================================================
 * v1's fatal mistake was letting the code that DOES work also decide to SPAWN
 * more work (class-page-generator.php:196-201). Those two responsibilities are
 * split here permanently:
 *
 *   WAB_Runner  — decides whether a tick is allowed. Never processes a job.
 *   WAB_Queue   — processes a bounded batch. Never triggers anything.
 *
 * There is no code path from WAB_Queue back into WAB_Runner. Recursion is
 * structurally impossible rather than merely guarded against.
 *
 * ============================================================================
 * WHY MULTIPLE TRIGGERS ARE SAFE
 * ============================================================================
 * Because WAB_Lock guarantees single execution site-wide, extra triggers cannot
 * cause concurrency — a second arrival simply exits. That means we can layer
 * triggers for reliability instead of choosing one and hoping:
 *
 *   1. System cron -> WP-CLI `wp wab run`   (best: no HTTP, no PHP-FPM timeout,
 *                                            own memory limit, runs on a quiet site)
 *   2. System cron -> wp-cron.php           (reliable, no CLI needed)
 *   3. WP-Cron on visits                    (fallback; fires only on traffic)
 *
 * All three funnel into the same locked worker. The user can close their laptop
 * because nothing depends on a browser being open.
 *
 * ============================================================================
 * PROTECTING A SHARED SERVER RUNNING ~200 SITES
 * ============================================================================
 *   - STAGGER: every site derives a deterministic offset from its own URL, so
 *     200 installs do not all wake at :00 and stampede the same MySQL box.
 *   - LOAD GUARD: if 1-minute load average already exceeds the threshold, the
 *     tick is skipped. Generation is never urgent enough to justify tipping a
 *     server over.
 *   - MEMORY GUARD: refuses to start a batch without real headroom.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Runner {

    const CRON_HOOK      = 'wab_run_queue';
    const CRON_SCHEDULE  = 'wab_every_minute';

    const OPT_LAST_TICK  = 'wab_last_tick';
    const OPT_LAST_RUN   = 'wab_last_run';
    const OPT_PAUSED     = 'wab_queue_paused';
    const OPT_TICK_COUNT = 'wab_tick_count';

    /** Minimum seconds between actual worker runs, regardless of trigger count. */
    const MIN_INTERVAL = 20;

    public static function register() {
        add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            self::register_cli();
        }
    }

    public static function add_schedule( $schedules ) {
        if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
            $schedules[ self::CRON_SCHEDULE ] = array(
                'interval' => 60,
                'display'  => __( 'Every minute (Wonder AI Builder)', 'wonder-ai-builder' ),
            );
        }
        return $schedules;
    }

    /**
     * Schedule the recurring event with a per-site stagger.
     */
    public static function schedule() {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) return;

        // Deterministic 0-59s offset per site so 200 installs spread their load.
        $offset = self::stagger_offset();
        wp_schedule_event( time() + $offset, self::CRON_SCHEDULE, self::CRON_HOOK );
    }

    public static function unschedule() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Stable per-site offset within the cron interval.
     */
    public static function stagger_offset() {
        $seed = get_site_url() . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' );
        return abs( crc32( $seed ) ) % 60;
    }

    // ---------------------------------------------------------------
    // The tick
    // ---------------------------------------------------------------

    /**
     * Entry point for every trigger source. Decides go / no-go, then hands off.
     *
     * @param array $args force (bypass throttle), max_jobs.
     * @return array Outcome report.
     */
    public static function tick( $args = array() ) {
        $args = wp_parse_args( (array) $args, array(
            'force'    => false,
            'max_jobs' => 0,
            'source'   => 'cron',
        ) );

        update_option( self::OPT_LAST_TICK, time(), false );

        // --- Gate 1: explicitly paused (kill switch) -------------------
        if ( self::is_paused() && ! $args['force'] ) {
            return self::outcome( 'paused', __( 'Queue is paused.', 'wonder-ai-builder' ) );
        }

        // --- Gate 2: throttle -----------------------------------------
        // Several triggers may fire within the same minute. The lock would make
        // that safe anyway, but skipping early avoids pointless DB work.
        $last = (int) get_option( self::OPT_LAST_RUN, 0 );
        if ( ! $args['force'] && ( time() - $last ) < self::MIN_INTERVAL ) {
            return self::outcome( 'throttled', sprintf(
                __( 'Last run %ds ago; minimum interval is %ds.', 'wonder-ai-builder' ),
                time() - $last,
                self::MIN_INTERVAL
            ) );
        }

        // --- Gate 3: is there anything to do? -------------------------
        // Open batches count as work even with nothing queued: their results still
        // need collecting, and poll_all() runs only after the lock is taken.
        if ( ! WAB_Queue::has_pending() && ! WAB_Batch::has_open() ) {
            return self::outcome( 'idle', __( 'Nothing queued and no batches in flight.', 'wonder-ai-builder' ) );
        }

        // --- Gate 4: budget -------------------------------------------
        $budget = WAB_Cost_Guard::can_spend( 0 );
        if ( is_wp_error( $budget ) ) {
            return self::outcome( 'budget', $budget->get_error_message() );
        }

        // --- Gate 5: server load --------------------------------------
        $load = self::load_check();
        if ( is_wp_error( $load ) && ! $args['force'] ) {
            WAB_Logger::info( 'Tick skipped: ' . $load->get_error_message() );
            return self::outcome( 'high_load', $load->get_error_message() );
        }

        // --- Gate 6: memory headroom ----------------------------------
        $mem = self::memory_check();
        if ( is_wp_error( $mem ) ) {
            WAB_Logger::warn( 'Tick skipped: ' . $mem->get_error_message() );
            return self::outcome( 'low_memory', $mem->get_error_message() );
        }

        // --- Gate 7: THE LOCK -----------------------------------------
        // Everything above is optimisation. This is the actual safety guarantee.
        // TTL must exceed the worst realistic single job on the fallback path: the
        // image client alone allows 180s x 3 attempts plus backoff before the text
        // call starts. WAB_Queue::heartbeat() refreshes it during long jobs.
        if ( ! WAB_Lock::acquire( WAB_Lock::WORKER, 1800 ) ) {
            return self::outcome( 'locked', __( 'Another worker is already running.', 'wonder-ai-builder' ) );
        }

        update_option( self::OPT_LAST_RUN, time(), false );
        update_option( self::OPT_TICK_COUNT, ( (int) get_option( self::OPT_TICK_COUNT, 0 ) ) + 1, false );

        try {
            $report = array();

            // ---- Economy mode, inside the lock so submission and ingestion
            // ---- can never be duplicated by a second worker. ----------------
            //
            // Order matters: POLL FIRST so completed text lands as job payloads and
            // can be consumed by process_batch() in this same tick. Submitting first
            // would delay every ingest by a full cycle.
            if ( WAB_Batch::enabled() ) {
                $report['batch_poll']   = WAB_Batch::poll_all();
                $report['batch_submit'] = WAB_Batch::maybe_submit();
            }

            $report = array_merge( $report, WAB_Queue::process_batch( array(
                'max_jobs' => $args['max_jobs'] > 0 ? (int) $args['max_jobs'] : null,
            ) ) );
        } catch ( \Throwable $e ) {
            // A fatal inside one job must not leave the lock held or the queue wedged.
            WAB_Logger::error( 'Worker threw: ' . $e->getMessage(), array(
                'file' => basename( $e->getFile() ),
                'line' => $e->getLine(),
            ) );
            $report = array( 'error' => $e->getMessage(), 'processed' => 0 );
        } finally {
            WAB_Lock::release( WAB_Lock::WORKER );
        }

        return self::outcome( 'ran', '', $report );
    }

    private static function outcome( $status, $message = '', array $extra = array() ) {
        return array_merge( array(
            'status'  => $status,
            'message' => $message,
            'time'    => current_time( 'mysql' ),
        ), $extra );
    }

    // ---------------------------------------------------------------
    // Resource guards
    // ---------------------------------------------------------------

    /**
     * Skip the tick when the machine is already under pressure.
     *
     * Threshold defaults to CPU count, which is the conventional "fully busy"
     * mark for 1-minute load average. Hosts without sys_getloadavg (Windows, some
     * containers) simply pass.
     */
    private static function load_check() {
        if ( ! function_exists( 'sys_getloadavg' ) ) return true;

        $load = @sys_getloadavg();
        if ( ! is_array( $load ) || ! isset( $load[0] ) ) return true;

        $threshold = (float) get_option( 'wab_load_threshold', 0 );
        if ( $threshold <= 0 ) {
            $threshold = max( 2.0, (float) self::cpu_count() );
        }

        if ( (float) $load[0] > $threshold ) {
            return new WP_Error( 'wab_high_load', sprintf(
                __( 'Server load %.2f exceeds threshold %.2f — deferring to the next tick.', 'wonder-ai-builder' ),
                $load[0],
                $threshold
            ) );
        }

        return true;
    }

    public static function cpu_count() {
        static $count = null;
        if ( $count !== null ) return $count;

        $count = 1;

        if ( is_readable( '/proc/cpuinfo' ) ) {
            $data = @file_get_contents( '/proc/cpuinfo' );
            if ( $data ) {
                $n = preg_match_all( '/^processor\s*:/mi', $data );
                if ( $n > 0 ) $count = $n;
            }
        }

        return $count;
    }

    /**
     * Require genuine headroom before starting a batch.
     *
     * v1 checked memory only BETWEEN jobs (class-page-generator.php:173) and at
     * 90% of the limit — far too late, since a single image payload can add tens
     * of megabytes. This gate runs before any work starts and demands 35% free.
     */
    private static function memory_check() {
        $limit = self::memory_limit_bytes();
        if ( $limit <= 0 ) return true; // Unlimited.

        $used = memory_get_usage( true );
        $free_ratio = 1 - ( $used / $limit );

        if ( $free_ratio < 0.35 ) {
            return new WP_Error( 'wab_low_memory', sprintf(
                __( 'Only %d%% of the PHP memory limit is free — deferring.', 'wonder-ai-builder' ),
                (int) round( $free_ratio * 100 )
            ) );
        }

        return true;
    }

    public static function memory_limit_bytes() {
        $raw = ini_get( 'memory_limit' );
        if ( $raw === false || $raw === '' ) return 0;
        if ( (string) $raw === '-1' )        return 0;

        $raw  = trim( $raw );
        $unit = strtolower( substr( $raw, -1 ) );
        $val  = (int) $raw;

        switch ( $unit ) {
            case 'g': $val *= 1024 * 1024 * 1024; break;
            case 'm': $val *= 1024 * 1024;        break;
            case 'k': $val *= 1024;               break;
        }

        return $val;
    }

    // ---------------------------------------------------------------
    // Kill switch
    // ---------------------------------------------------------------

    public static function is_paused() {
        return (bool) get_option( self::OPT_PAUSED, false );
    }

    public static function pause() {
        update_option( self::OPT_PAUSED, 1, false );
        WAB_Logger::warn( 'Queue paused by operator.' );
    }

    public static function resume() {
        update_option( self::OPT_PAUSED, 0, false );
        WAB_Logger::info( 'Queue resumed by operator.' );
    }

    // ---------------------------------------------------------------
    // Health reporting
    // ---------------------------------------------------------------

    /**
     * Detect a silently broken cron setup — the most common cause of
     * "I queued 100 pages and nothing happened".
     */
    public static function health() {
        $last_tick = (int) get_option( self::OPT_LAST_TICK, 0 );
        $age       = $last_tick ? ( time() - $last_tick ) : null;

        $wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $scheduled        = (bool) wp_next_scheduled( self::CRON_HOOK );

        $issues = array();

        if ( ! $scheduled ) {
            $issues[] = __( 'The recurring queue event is not scheduled. Deactivate and reactivate the plugin.', 'wonder-ai-builder' );
        }

        if ( $age === null ) {
            $issues[] = __( 'The queue worker has never ticked. If this site gets little traffic, configure a system cron (see below).', 'wonder-ai-builder' );
        } elseif ( $age > 900 ) {
            $issues[] = sprintf(
                __( 'Last tick was %d minutes ago. WP-Cron only fires on page visits, so quiet sites stall — configure a system cron.', 'wonder-ai-builder' ),
                (int) round( $age / 60 )
            );
        }

        if ( $wp_cron_disabled && $age !== null && $age > 300 ) {
            $issues[] = __( 'DISABLE_WP_CRON is set but ticks are not arriving — the system cron entry may be missing or failing.', 'wonder-ai-builder' );
        }

        return array(
            'scheduled'        => $scheduled,
            'next_run'         => $scheduled ? wp_next_scheduled( self::CRON_HOOK ) : null,
            'last_tick_age'    => $age,
            'wp_cron_disabled' => $wp_cron_disabled,
            'paused'           => self::is_paused(),
            'lock'             => WAB_Lock::status(),
            'stagger_offset'   => self::stagger_offset(),
            'cli_available'    => self::cli_path() !== null,
            'issues'           => $issues,
            'load'             => function_exists( 'sys_getloadavg' ) ? @sys_getloadavg() : null,
            'memory_limit'     => self::memory_limit_bytes(),
        );
    }

    private static function cli_path() {
        // Best-effort detection so the setup instructions can be concrete.
        foreach ( array( '/usr/local/bin/wp', '/usr/bin/wp' ) as $p ) {
            if ( @is_executable( $p ) ) return $p;
        }
        return null;
    }

    /**
     * Copy-paste crontab lines for the admin health panel.
     *
     * Interval is 1 minute; the worker itself is throttled and load-guarded, so a
     * frequent trigger is cheap and simply means less latency before a queued job
     * starts.
     */
    public static function cron_instructions() {
        $path = ABSPATH;
        $cli  = self::cli_path() ?: 'wp';
        $url  = site_url( 'wp-cron.php?doing_wp_cron' );

        return array(
            'recommended' => sprintf( '* * * * * cd %s && %s wab run --quiet >/dev/null 2>&1', $path, $cli ),
            'fallback'    => sprintf( '* * * * * curl -fsS -m 55 "%s" >/dev/null 2>&1', $url ),
            'wp_config'   => "define( 'DISABLE_WP_CRON', true );",
        );
    }

    // ---------------------------------------------------------------
    // WP-CLI
    // ---------------------------------------------------------------

    private static function register_cli() {
        \WP_CLI::add_command( 'wab run', function ( $args, $assoc ) {
            $report = self::tick( array(
                'force'    => isset( $assoc['force'] ),
                'max_jobs' => isset( $assoc['max-jobs'] ) ? (int) $assoc['max-jobs'] : 0,
                'source'   => 'cli',
            ) );

            if ( isset( $assoc['quiet'] ) ) return;

            if ( $report['status'] === 'ran' ) {
                \WP_CLI::success( sprintf(
                    'Processed %d job(s). %d succeeded, %d failed.',
                    $report['processed'] ?? 0,
                    $report['succeeded'] ?? 0,
                    $report['failed'] ?? 0
                ) );
            } else {
                \WP_CLI::log( sprintf( '[%s] %s', $report['status'], $report['message'] ) );
            }
        }, array(
            'shortdesc' => 'Process one bounded batch of the Wonder AI Builder queue.',
            'synopsis'  => array(
                array( 'type' => 'flag', 'name' => 'force',    'optional' => true, 'description' => 'Bypass throttle and load guards.' ),
                array( 'type' => 'flag', 'name' => 'quiet',    'optional' => true, 'description' => 'Suppress output (for crontab).' ),
                array( 'type' => 'assoc', 'name' => 'max-jobs', 'optional' => true, 'description' => 'Override jobs per batch.' ),
            ),
        ) );

        \WP_CLI::add_command( 'wab status', function () {
            $health = self::health();
            $counts = WAB_Queue::counts();
            $spend  = WAB_Cost_Guard::summary();

            \WP_CLI::log( '--- Queue ---' );
            foreach ( $counts as $k => $v ) {
                \WP_CLI::log( sprintf( '  %-12s %d', $k, $v ) );
            }
            \WP_CLI::log( '--- Spend ---' );
            \WP_CLI::log( sprintf( '  today $%.4f / budget $%.2f', $spend['today'], $spend['budget'] ) );
            \WP_CLI::log( sprintf( '  total $%.4f (text $%.4f, image $%.4f)', $spend['total'], $spend['text'], $spend['image'] ) );
            \WP_CLI::log( '--- Health ---' );
            \WP_CLI::log( sprintf( '  lock strategy : %s', $health['lock']['strategy'] ) );
            \WP_CLI::log( sprintf( '  paused        : %s', $health['paused'] ? 'yes' : 'no' ) );
            \WP_CLI::log( sprintf( '  last tick     : %s', $health['last_tick_age'] === null ? 'never' : $health['last_tick_age'] . 's ago' ) );

            if ( ! empty( $health['issues'] ) ) {
                foreach ( $health['issues'] as $issue ) \WP_CLI::warning( $issue );
            } else {
                \WP_CLI::success( 'No issues detected.' );
            }
        }, array( 'shortdesc' => 'Show queue, spend, and health status.' ) );

        \WP_CLI::add_command( 'wab pause',  function () { self::pause();  \WP_CLI::success( 'Queue paused.' ); } );
        \WP_CLI::add_command( 'wab resume', function () { self::resume(); \WP_CLI::success( 'Queue resumed.' ); } );
    }
}
