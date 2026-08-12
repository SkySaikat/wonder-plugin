<?php
/**
 * Crash-safe mutual exclusion for queue workers.
 *
 * ============================================================================
 * THE INCIDENT THIS PREVENTS
 * ============================================================================
 * v1 took down ~200 production sites by exhausting 20-30GB of RAM. Mechanism,
 * from ai-page-builder/includes/class-page-generator.php:
 *
 *   1. process_queue_batch() ended by calling trigger_async_process() (:196-201)
 *      whenever queued > 0 and running < parallel.
 *
 *   2. trigger_async_process() (:112-129) fired a loopback POST with
 *      'blocking' => false and 'timeout' => 0.5, so the PARENT DID NOT WAIT and
 *      did not exit — it carried on through its own foreach loop.
 *
 *   3. The child ran process_queue_batch() and spawned again at the end.
 *
 *   Parent and child both alive, both spawning => 1, 2, 4, 8, 16 ... And WP-Cron
 *   added a brand new root process every 60 seconds ('every_minute',
 *   class-activator.php:93), each starting its own exponential tree.
 *
 *   ignore_user_abort(true) (:137) + set_time_limit(120) (:186) meant none of
 *   those processes could be killed.
 *
 *   The only brake was `running < parallel`, computed from COUNT(status='processing').
 *   Every worker finishing a batch lowered that count, so all live workers saw
 *   "room available" simultaneously and all spawned. A textbook thundering herd
 *   with no ceiling.
 *
 * ============================================================================
 * WHY GET_LOCK AND NOT A TRANSIENT
 * ============================================================================
 * A transient is the obvious choice and it is wrong here:
 *
 *   - With a non-persistent object cache, set_transient() is per-request memory.
 *     Every worker gets its own copy, so the lock never actually excludes anyone.
 *   - With Redis/Memcached, a worker that is OOM-killed leaves the transient set
 *     until its TTL expires, stalling the queue for minutes.
 *
 * MySQL's GET_LOCK() is session-scoped: when the PHP process dies — cleanly,
 * fatally, or via the OOM killer — the DB connection closes and the lock is
 * released automatically. That is precisely the semantics a crash-safe worker
 * lock needs, and it is why this is the primary strategy.
 *
 * Fallback for non-MySQL or restricted-privilege hosts is an atomic
 * INSERT IGNORE against wp_options (UNIQUE index on option_name), with an
 * explicit expiry timestamp so a crashed worker cannot deadlock the queue
 * permanently.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Lock {

    /** Only ever ONE queue worker runs site-wide. */
    const WORKER = 'wab_queue_worker';

    /** Namespaced so two sites sharing a MySQL server never collide. */
    private static $held = array();

    /** Cached capability probe. */
    private static $supports_get_lock = null;

    /**
     * Acquire a named lock without blocking.
     *
     * @param string $name    Logical lock name.
     * @param int    $ttl     Fallback-path expiry in seconds.
     * @return bool True if this process now owns the lock.
     */
    public static function acquire( $name, $ttl = 300 ) {
        if ( isset( self::$held[ $name ] ) ) {
            // Already ours. Never re-acquire — that is how reentrancy bugs start.
            return false;
        }

        $ok = self::supports_get_lock()
            ? self::acquire_mysql( $name )
            : self::acquire_option( $name, $ttl );

        if ( $ok ) {
            self::$held[ $name ] = time();
            // Release on shutdown even if the script exits unexpectedly.
            add_action( 'shutdown', array( __CLASS__, 'release_all' ), PHP_INT_MAX );
        }

        return $ok;
    }

    public static function release( $name ) {
        if ( ! isset( self::$held[ $name ] ) ) return;

        if ( self::supports_get_lock() ) {
            self::release_mysql( $name );
        } else {
            self::release_option( $name );
        }

        unset( self::$held[ $name ] );
    }

    public static function release_all() {
        foreach ( array_keys( self::$held ) as $name ) {
            self::release( $name );
        }
    }

    public static function is_held_by_us( $name ) {
        return isset( self::$held[ $name ] );
    }

    // ---------------------------------------------------------------
    // MySQL named locks (primary)
    // ---------------------------------------------------------------

    private static function acquire_mysql( $name ) {
        global $wpdb;

        // GET_LOCK with timeout 0 returns immediately: 1 = acquired, 0 = taken.
        $result = $wpdb->get_var(
            $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::qualified_name( $name ) )
        );

        return ( $result === '1' || $result === 1 );
    }

    private static function release_mysql( $name ) {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::qualified_name( $name ) )
        );
    }

    /**
     * Lock names are global to the MySQL *server*, not the database. Two WordPress
     * installs sharing one server would otherwise block each other — a real risk
     * when 200 sites are consolidated onto shared infrastructure. Namespace by
     * DB name + table prefix.
     *
     * MySQL caps lock names at 64 characters, so long prefixes are hashed.
     */
    private static function qualified_name( $name ) {
        global $wpdb;

        $raw = sprintf( '%s.%s.%s', DB_NAME, $wpdb->prefix, $name );

        if ( strlen( $raw ) <= 64 ) {
            return $raw;
        }

        return substr( $name, 0, 24 ) . '_' . md5( $raw );
    }

    private static function supports_get_lock() {
        if ( self::$supports_get_lock !== null ) {
            return self::$supports_get_lock;
        }

        global $wpdb;

        // Suppress errors: some managed hosts revoke GET_LOCK.
        $prev  = $wpdb->suppress_errors( true );

        // The probe name MUST be namespaced like every other lock. MySQL named
        // locks are server-scoped, so a shared, unqualified 'wab_probe' meant that
        // with ~200 installs on one MySQL box (each ticking on a 0-59s stagger,
        // roughly 3 sites/second) site A holding the probe would make site B's
        // probe return 0. B then concluded GET_LOCK was unavailable and fell back
        // to the wp_options mutex while A used GET_LOCK — two different mutexes,
        // so two workers could run. Exactly the concurrency this class prevents.
        $name  = self::qualified_name( 'probe' );
        $probe = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );

        if ( $probe === '1' || $probe === 1 ) {
            // Acquired: supported. Release immediately.
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
            self::$supports_get_lock = true;
        } elseif ( $probe === '0' || $probe === 0 ) {
            // Contended, not unavailable — GET_LOCK plainly works. Previously this
            // was conflated with "unsupported".
            self::$supports_get_lock = true;
        } else {
            // NULL or error: privilege revoked, or not MySQL. Use the fallback.
            self::$supports_get_lock = false;
        }

        $wpdb->suppress_errors( $prev );

        return self::$supports_get_lock;
    }

    // ---------------------------------------------------------------
    // Atomic option fallback
    // ---------------------------------------------------------------

    /**
     * Uses INSERT IGNORE against the UNIQUE index on option_name. This is a true
     * atomic test-and-set at the storage layer.
     *
     * add_option() is deliberately NOT used: it performs a SELECT before its
     * INSERT, leaving a race window that two simultaneous workers can both pass.
     */
    private static function acquire_option( $name, $ttl ) {
        global $wpdb;

        $key     = '_wab_lock_' . $name;
        $expires = time() + max( 30, (int) $ttl );

        // Clear an expired lock left behind by a crashed worker.
        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $key )
        );
        if ( $existing !== null && (int) $existing < time() ) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                    $key,
                    $existing
                )
            );
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $key,
                (string) $expires
            )
        );

        $acquired = ( (int) $wpdb->rows_affected === 1 );

        if ( $acquired ) {
            wp_cache_delete( $key, 'options' );
            wp_cache_delete( 'alloptions', 'options' );
        }

        return $acquired;
    }

    private static function release_option( $name ) {
        global $wpdb;
        $key = '_wab_lock_' . $name;
        $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $key )
        );
        wp_cache_delete( $key, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
    }

    /**
     * Extend the fallback lock's expiry. Called from the worker heartbeat so a
     * long-running batch is not treated as crashed.
     */
    public static function touch( $name, $ttl = 300 ) {
        if ( ! isset( self::$held[ $name ] ) ) return;
        if ( self::supports_get_lock() )      return; // Session locks never expire.

        global $wpdb;
        $wpdb->update(
            $wpdb->options,
            array( 'option_value' => (string) ( time() + max( 30, (int) $ttl ) ) ),
            array( 'option_name'  => '_wab_lock_' . $name )
        );
        wp_cache_delete( '_wab_lock_' . $name, 'options' );
    }

    /**
     * Diagnostics for the admin health panel.
     */
    public static function status() {
        return array(
            'strategy' => self::supports_get_lock() ? 'mysql_get_lock' : 'option_insert_ignore',
            'held'     => array_keys( self::$held ),
        );
    }
}
