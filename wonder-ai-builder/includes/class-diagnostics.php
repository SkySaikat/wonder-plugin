<?php
/**
 * Self-diagnosis.
 *
 * WHY THIS EXISTS
 * ---------------
 * "The queue does nothing" is the single most expensive class of bug in a plugin
 * like this, because the visible symptom is identical across a dozen unrelated
 * causes: no cron entry, cron entry failing, DISABLE_WP_CRON with nothing replacing
 * it, no API key, exhausted budget, paused queue, missing tables, or a job already
 * completed. Guessing wastes everyone's time.
 *
 * Every check returns a status, a plain-language explanation, and — where the
 * operator can act — the exact thing to do. This is a support tool that removes the
 * need for support.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Diagnostics {

    const PASS = 'pass';
    const WARN = 'warn';
    const FAIL = 'fail';

    /**
     * @return array<int, array{status:string, label:string, detail:string, fix:string}>
     */
    public static function run() {
        $checks = array();

        $checks[] = self::check_tables();
        $checks[] = self::check_cron_scheduled();
        $checks[] = self::check_cron_firing();
        $checks[] = self::check_paused();
        $checks[] = self::check_lock();
        $checks[] = self::check_text_provider();
        $checks[] = self::check_image_source();
        $checks[] = self::check_budget();
        $checks[] = self::check_load_gate();
        $checks[] = self::check_stuck_work();
        $checks[] = self::check_php();

        return $checks;
    }

    /**
     * The optional load gate, reported explicitly.
     *
     * This check exists because the gate previously blocked every tick on shared
     * hosting — comparing host-wide sys_getloadavg() against container CPU count —
     * and nothing in the UI said so. A guard that can halt all work must be visible.
     */
    private static function check_load_gate() {
        $threshold = (float) get_option( 'wab_load_threshold', 0 );
        $load      = function_exists( 'sys_getloadavg' ) ? @sys_getloadavg() : null;
        $current   = ( is_array( $load ) && isset( $load[0] ) ) ? (float) $load[0] : null;

        if ( $threshold <= 0 ) {
            return self::row( self::PASS, __( 'Server load gate', 'wonder-ai-builder' ),
                $current !== null
                    ? sprintf( __( 'Disabled (recommended). Current host load is %.2f, which is ignored.', 'wonder-ai-builder' ), $current )
                    : __( 'Disabled (recommended).', 'wonder-ai-builder' )
            );
        }

        if ( $current !== null && $current > $threshold ) {
            return self::row(
                self::FAIL,
                __( 'Server load gate', 'wonder-ai-builder' ),
                sprintf(
                    __( 'Blocking work: host load %1$.2f exceeds your threshold of %2$.2f.', 'wonder-ai-builder' ),
                    $current, $threshold
                ),
                __( 'On shared hosting this reading covers the whole physical server, not your site, so it will almost always be high. Set "Pause above server load" to 0 in Settings to disable the gate.', 'wonder-ai-builder' )
            );
        }

        return self::row( self::PASS, __( 'Server load gate', 'wonder-ai-builder' ),
            sprintf( __( 'Threshold %1$.2f, current load %2$.2f. Not blocking.', 'wonder-ai-builder' ),
                $threshold, $current === null ? 0 : $current ) );
    }

    /** Worst status across all checks, for the page-level banner. */
    public static function overall( array $checks ) {
        foreach ( array( self::FAIL, self::WARN ) as $level ) {
            foreach ( $checks as $c ) {
                if ( $c['status'] === $level ) return $level;
            }
        }
        return self::PASS;
    }

    private static function row( $status, $label, $detail, $fix = '' ) {
        return compact( 'status', 'label', 'detail', 'fix' );
    }

    // -----------------------------------------------------------------

    private static function check_tables() {
        global $wpdb;

        $needed  = array( 'wab_imports', 'wab_rows', 'wab_jobs', 'wab_concepts', 'wab_scan', 'wab_batches' );
        $missing = array();

        foreach ( $needed as $t ) {
            $full = $wpdb->prefix . $t;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
                $missing[] = $t;
            }
        }

        if ( $missing ) {
            return self::row(
                self::FAIL,
                __( 'Database tables', 'wonder-ai-builder' ),
                sprintf( __( 'Missing: %s. Nothing can be imported or queued.', 'wonder-ai-builder' ), implode( ', ', $missing ) ),
                __( 'Deactivate and reactivate the plugin. If they are still missing, the database user lacks CREATE TABLE permission.', 'wonder-ai-builder' )
            );
        }

        return self::row( self::PASS, __( 'Database tables', 'wonder-ai-builder' ),
            sprintf( __( 'All %d tables present.', 'wonder-ai-builder' ), count( $needed ) ) );
    }

    private static function check_cron_scheduled() {
        $next = wp_next_scheduled( WAB_Runner::CRON_HOOK );

        if ( ! $next ) {
            return self::row(
                self::FAIL,
                __( 'Queue schedule', 'wonder-ai-builder' ),
                __( 'The recurring queue event is not registered, so nothing will ever run.', 'wonder-ai-builder' ),
                __( 'Reload any admin page — the plugin re-schedules itself automatically. If this persists, another plugin is clearing cron events.', 'wonder-ai-builder' )
            );
        }

        $in = $next - time();

        return self::row( self::PASS, __( 'Queue schedule', 'wonder-ai-builder' ),
            $in > 0
                ? sprintf( __( 'Registered. Next check in %d second(s).', 'wonder-ai-builder' ), (int) $in )
                : __( 'Registered and due now.', 'wonder-ai-builder' )
        );
    }

    /**
     * The check that matters most: is the worker actually being invoked?
     */
    private static function check_cron_firing() {
        $last = (int) get_option( WAB_Runner::OPT_LAST_TICK, 0 );
        $age  = $last ? ( time() - $last ) : null;
        $off  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

        if ( $age === null ) {
            return self::row(
                self::FAIL,
                __( 'Background processing', 'wonder-ai-builder' ),
                __( 'The worker has never run. Queued rows will sit untouched.', 'wonder-ai-builder' ),
                $off
                    ? __( 'DISABLE_WP_CRON is set, so WordPress will not self-trigger. A server cron entry is required — see the command below.', 'wonder-ai-builder' )
                    : __( 'Press "Run one job now" below. If that works, WP-Cron is not firing on its own and you should add a server cron entry.', 'wonder-ai-builder' )
            );
        }

        if ( $age > 900 ) {
            return self::row(
                self::FAIL,
                __( 'Background processing', 'wonder-ai-builder' ),
                sprintf( __( 'Last run was %d minute(s) ago. The queue has effectively stopped.', 'wonder-ai-builder' ), (int) round( $age / 60 ) ),
                $off
                    ? __( 'DISABLE_WP_CRON is set but nothing is replacing it. Add the server cron entry below.', 'wonder-ai-builder' )
                    : __( 'WP-Cron only fires when someone visits the site. Add the server cron entry below so it runs unattended.', 'wonder-ai-builder' )
            );
        }

        if ( $age > 180 ) {
            return self::row(
                self::WARN,
                __( 'Background processing', 'wonder-ai-builder' ),
                sprintf( __( 'Last run %d second(s) ago — slower than the 60-second target.', 'wonder-ai-builder' ), (int) $age ),
                __( 'Usually means WP-Cron is traffic-dependent. A server cron entry makes it reliable.', 'wonder-ai-builder' )
            );
        }

        return self::row( self::PASS, __( 'Background processing', 'wonder-ai-builder' ),
            sprintf( __( 'Running. Last checked %d second(s) ago.', 'wonder-ai-builder' ), (int) $age ) );
    }

    private static function check_paused() {
        if ( WAB_Runner::is_paused() ) {
            return self::row(
                self::WARN,
                __( 'Queue state', 'wonder-ai-builder' ),
                __( 'The queue is paused. Rows will be accepted but never processed.', 'wonder-ai-builder' ),
                __( 'Press Resume in the page header.', 'wonder-ai-builder' )
            );
        }
        return self::row( self::PASS, __( 'Queue state', 'wonder-ai-builder' ), __( 'Active.', 'wonder-ai-builder' ) );
    }

    private static function check_lock() {
        $s = WAB_Lock::status();

        if ( $s['strategy'] === 'mysql_get_lock' ) {
            return self::row( self::PASS, __( 'Concurrency guard', 'wonder-ai-builder' ),
                __( 'MySQL named locks. Only one worker can run, and the lock frees itself if PHP is killed.', 'wonder-ai-builder' ) );
        }

        return self::row( self::WARN, __( 'Concurrency guard', 'wonder-ai-builder' ),
            __( 'Using the database-option fallback because GET_LOCK is unavailable on this host. Still single-worker, but a crashed worker can hold the lock for up to 30 minutes.', 'wonder-ai-builder' ),
            __( 'No action needed. Ask your host to grant GET_LOCK if you want the stronger guarantee.', 'wonder-ai-builder' ) );
    }

    private static function check_text_provider() {
        $p = WAB_Provider_Registry::text();

        if ( ! $p->is_configured() ) {
            return self::row(
                self::FAIL,
                __( 'Writer', 'wonder-ai-builder' ),
                sprintf( __( '%s has no API key. Every job will fail immediately.', 'wonder-ai-builder' ), $p->get_label() ),
                __( 'Add the key in Settings, or define the matching constant in wp-config.php.', 'wonder-ai-builder' )
            );
        }

        return self::row( self::PASS, __( 'Writer', 'wonder-ai-builder' ),
            sprintf( __( '%s is configured.', 'wonder-ai-builder' ), $p->get_label() ) );
    }

    private static function check_image_source() {
        $src = get_option( 'wab_image_source', 'library_then_ai' );

        if ( $src === 'none' ) {
            return self::row( self::PASS, __( 'Images', 'wonder-ai-builder' ), __( 'Disabled. Pages will be created without a featured image.', 'wonder-ai-builder' ) );
        }

        if ( $src === 'library_only' ) {
            $n = (int) ( WAB_Scanner::site_summary()['library_size'] ?? 0 );
            return $n > 0
                ? self::row( self::PASS, __( 'Images', 'wonder-ai-builder' ), sprintf( __( 'Library only — %d images available at no cost.', 'wonder-ai-builder' ), $n ) )
                : self::row( self::WARN, __( 'Images', 'wonder-ai-builder' ),
                    __( 'Library-only is selected but the media library has no images, so pages will have none.', 'wonder-ai-builder' ),
                    __( 'Upload images, or switch to "Library first, then generate".', 'wonder-ai-builder' ) );
        }

        $img = WAB_Provider_Registry::image();

        if ( ! $img->is_configured() ) {
            return self::row(
                self::WARN,
                __( 'Images', 'wonder-ai-builder' ),
                sprintf( __( '%s has no API key, so generation will be skipped.', 'wonder-ai-builder' ), $img->get_label() ),
                __( 'Add the key, or switch Images to "My library only" to remove the warning.', 'wonder-ai-builder' )
            );
        }

        return self::row( self::PASS, __( 'Images', 'wonder-ai-builder' ),
            sprintf( __( '%s is configured.', 'wonder-ai-builder' ), $img->get_label() ) );
    }

    private static function check_budget() {
        $can = WAB_Cost_Guard::can_spend( 0 );

        if ( is_wp_error( $can ) ) {
            return self::row(
                self::FAIL,
                __( 'Daily budget', 'wonder-ai-builder' ),
                $can->get_error_message(),
                __( 'Raise the daily budget in Settings, or wait for the date to roll over.', 'wonder-ai-builder' )
            );
        }

        $b = WAB_Cost_Guard::daily_budget();

        return self::row( self::PASS, __( 'Daily budget', 'wonder-ai-builder' ),
            $b > 0
                ? sprintf( __( '$%1$s spent of $%2$s.', 'wonder-ai-builder' ), number_format( WAB_Cost_Guard::spend_today(), 3 ), number_format( $b, 2 ) )
                : __( 'No cap set. Consider setting one while testing.', 'wonder-ai-builder' )
        );
    }

    /**
     * Work exists but is not moving — the exact shape of the reported complaint.
     */
    private static function check_stuck_work() {
        $c    = WAB_Queue::counts();
        $last = (int) get_option( WAB_Runner::OPT_LAST_TICK, 0 );
        $age  = $last ? ( time() - $last ) : null;

        if ( $c['queued'] > 0 && ( $age === null || $age > 300 ) ) {
            return self::row(
                self::FAIL,
                __( 'Pending work', 'wonder-ai-builder' ),
                sprintf( __( '%d row(s) are queued but the worker is not running.', 'wonder-ai-builder' ), (int) $c['queued'] ),
                __( 'This is the "nothing happens" symptom. Fix Background processing above, then press "Run one job now".', 'wonder-ai-builder' )
            );
        }

        if ( $c['queued'] > 0 ) {
            return self::row( self::PASS, __( 'Pending work', 'wonder-ai-builder' ),
                sprintf( __( '%1$d waiting, %2$d running. Being worked through now.', 'wonder-ai-builder' ), (int) $c['queued'], (int) $c['processing'] ) );
        }

        if ( $c['total'] === 0 ) {
            return self::row( self::PASS, __( 'Pending work', 'wonder-ai-builder' ),
                __( 'Nothing queued yet. Import a sheet, open it, tick rows, press Generate.', 'wonder-ai-builder' ) );
        }

        return self::row( self::PASS, __( 'Pending work', 'wonder-ai-builder' ),
            sprintf( __( 'Queue empty. %1$d created, %2$d failed.', 'wonder-ai-builder' ), (int) $c['done'], (int) $c['failed'] ) );
    }

    private static function check_php() {
        $issues = array();

        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            $issues[] = sprintf( __( 'PHP %s is below the 7.4 minimum.', 'wonder-ai-builder' ), PHP_VERSION );
        }
        if ( ! function_exists( 'mb_substr' ) ) {
            $issues[] = __( 'The mbstring extension is missing; multi-byte titles will break.', 'wonder-ai-builder' );
        }
        if ( ! class_exists( 'ZipArchive' ) ) {
            $issues[] = __( 'The zip extension is missing; XLSX import is unavailable (CSV still works).', 'wonder-ai-builder' );
        }

        $limit = WAB_Runner::memory_limit_bytes();
        if ( $limit > 0 && $limit < 128 * 1024 * 1024 ) {
            $issues[] = sprintf( __( 'PHP memory limit is %dMB; 256MB is recommended.', 'wonder-ai-builder' ), (int) ( $limit / 1048576 ) );
        }

        if ( $issues ) {
            return self::row( self::WARN, __( 'Server', 'wonder-ai-builder' ), implode( ' ', $issues ),
                __( 'Ask your host to adjust these. None of them stop CSV-based generation.', 'wonder-ai-builder' ) );
        }

        return self::row( self::PASS, __( 'Server', 'wonder-ai-builder' ),
            sprintf( __( 'PHP %1$s, memory %2$dMB. Ready.', 'wonder-ai-builder' ), PHP_VERSION, (int) ( $limit / 1048576 ) ) );
    }
}
