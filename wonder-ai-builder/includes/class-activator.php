<?php
/**
 * Schema creation and lifecycle.
 *
 * ============================================================================
 * KEY SCHEMA DECISIONS AND WHY
 * ============================================================================
 *
 * UNIQUE KEY import_row (import_id, row_index) on wab_jobs
 *   This is the structural fix for v1's duplicate posts. Even if application
 *   logic were to fail, the database physically refuses a second job for the same
 *   row of the same import. It also makes enqueue() idempotent for free via
 *   INSERT IGNORE, so an admin double-clicking "Generate 100" cannot double-queue.
 *
 * locked_until  datetime DEFAULT NULL   (stored in UTC)
 *   Nullable because a job not being processed genuinely has no lease. v1 used a
 *   fixed updated_at wall-clock comparison, which reclaimed jobs that were still
 *   running. Stored as UTC and compared against UTC_TIMESTAMP() so the value is
 *   immune to the site timezone setting being changed mid-run.
 *
 * attachment_id bigint
 *   Persists the resolved image against the JOB, not just the post. v1 generated
 *   images before creating the post and stored nothing between, so every retry
 *   re-billed the image. Here a retry reuses it.
 *
 * attempts / cost_usd on the job row
 *   Per-job attribution. v1 had retry_count but no cost record, which is why the
 *   runaway spend was invisible until the bill arrived.
 *
 * wab_concepts
 *   The shared per-import brief, generated ONCE and reused across all rows. This
 *   is what makes 100 same-concept pages cheap: one expensive reasoning call plus
 *   100 cheap ones, instead of 100 expensive ones.
 *
 * NO MIGRATION FROM v1
 *   Confirmed with the operator: fresh imports only. v1 tables (wp_aipb_*,
 *   wp_apbp_*) are left completely untouched, so the old plugins keep working and
 *   rollback is trivial. Nothing here reads or writes them.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Activator {

    /** Bump to trigger dbDelta on upgrade. v3 adds economy batch mode. */
    const DB_VERSION = '3';

    public static function activate() {
        self::create_tables();
        self::seed_options();

        if ( class_exists( 'WAB_Runner' ) ) {
            WAB_Runner::schedule();
        }

        update_option( 'wab_db_version', self::DB_VERSION );
        update_option( 'wab_version', WAB_VERSION );

        if ( class_exists( 'WAB_Logger' ) ) {
            WAB_Logger::info( 'Plugin activated; schema at version ' . self::DB_VERSION );
        }
    }

    public static function deactivate() {
        if ( class_exists( 'WAB_Runner' ) ) {
            WAB_Runner::unschedule();
        }

        /**
         * Clear stale fallback locks directly in SQL.
         *
         * WAB_Lock::release_all() cannot help here: it iterates per-PROCESS state
         * ($held), which is always empty in the deactivation request, so a lock row
         * left behind by a crashed worker would survive. GET_LOCK locks need no
         * cleanup — they die with their connection.
         */
        global $wpdb;
        $like = $wpdb->esc_like( '_wab_lock_' ) . '%';
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
        wp_cache_delete( 'alloptions', 'options' );
    }

    /**
     * Run dbDelta when the stored version is behind.
     *
     * Deliberately NOT hooked to admin_init the way v1 did. v1's maybe_upgrade()
     * ran with no capability check and called activate(), which re-ran a full
     * 500-post site scan — meaning any logged-in Subscriber loading any admin page
     * could trigger heavy work, and concurrent requests raced each other.
     */
    public static function maybe_upgrade() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Self-heal a half-migrated install.
        //
        // Gating purely on the version option was wrong: if wab_db_version already
        // said '3' but dbDelta had never actually run — plugin files updated in place,
        // a failed migration, an interrupted activation — this returned immediately
        // and NEVER repaired anything. The symptoms were brutal and looked unrelated:
        // a missing wp_wab_batches table made WAB_Batch::summary() emit a DB error
        // straight into the ajax_status JSON, which made the response unparseable, so
        // the queue table rendered empty forever while the UI still claimed "Running".
        // Meanwhile a missing `payload` column failed every job outright.
        //
        // So verify the actual schema, not the bookkeeping. Cheap: one cached probe.
        if ( get_option( 'wab_db_version' ) === self::DB_VERSION && self::schema_is_intact() ) {
            return;
        }

        // Single-flight so two admins loading wp-admin together cannot both migrate.
        if ( ! WAB_Lock::acquire( 'wab_upgrade', 120 ) ) return;

        try {
            self::create_tables();
            self::seed_options();
            WAB_Runner::schedule();
            update_option( 'wab_db_version', self::DB_VERSION );
            update_option( 'wab_version', WAB_VERSION );
            WAB_Logger::info( 'Schema upgraded to version ' . self::DB_VERSION );
        } finally {
            WAB_Lock::release( 'wab_upgrade' );
        }
    }

    /**
     * Is the real schema present — every table AND every column the code needs?
     *
     * Result cached for an hour so this costs nothing on normal admin loads. The
     * cache is cleared whenever a repair runs.
     *
     * @return bool
     */
    public static function schema_is_intact() {
        $cached = get_transient( 'wab_schema_ok' );
        if ( $cached === 'yes' ) return true;

        $missing = self::find_missing();

        if ( empty( $missing ) ) {
            set_transient( 'wab_schema_ok', 'yes', HOUR_IN_SECONDS );
            delete_option( 'wab_schema_error' );
            return true;
        }

        update_option( 'wab_schema_error', $missing, false );
        return false;
    }

    /**
     * Enumerate missing tables and missing columns.
     *
     * Columns matter as much as tables: `payload` and `batch_id` were added in
     * DB_VERSION 3, and code written against them fails hard on an older table even
     * though the table itself exists.
     *
     * @return string[] Human-readable list of what is absent.
     */
    public static function find_missing() {
        global $wpdb;

        $missing = array();

        $tables = array( 'wab_imports', 'wab_rows', 'wab_jobs', 'wab_concepts', 'wab_scan', 'wab_batches' );
        $present = array();

        foreach ( $tables as $t ) {
            $full = $wpdb->prefix . $t;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full ) {
                $present[] = $t;
            } else {
                $missing[] = $t . ' (table)';
            }
        }

        // Columns the runtime depends on, per table.
        $required_columns = array(
            'wab_jobs' => array( 'payload', 'batch_id', 'attachment_id', 'locked_until', 'attempts', 'cost_usd' ),
            'wab_rows' => array( 'schema_markup', 'schema_type', 'post_type', 'price' ),
        );

        foreach ( $required_columns as $table => $cols ) {
            if ( ! in_array( $table, $present, true ) ) continue; // Table itself already reported.

            $have = $wpdb->get_col( 'DESC ' . $wpdb->prefix . $table );
            if ( ! is_array( $have ) ) continue;

            foreach ( $cols as $c ) {
                if ( ! in_array( $c, $have, true ) ) {
                    $missing[] = $table . '.' . $c . ' (column)';
                }
            }
        }

        return $missing;
    }

    // ---------------------------------------------------------------
    // Tables
    // ---------------------------------------------------------------

    private static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $collate = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        // dbDelta is fussy: lowercase types, two spaces after PRIMARY KEY,
        // one field per line, KEY rather than INDEX.

        dbDelta( "CREATE TABLE {$p}wab_imports (
            id bigint(20) unsigned NOT NULL auto_increment,
            import_id varchar(64) NOT NULL,
            filename varchar(255) NOT NULL default '',
            total_rows int(11) NOT NULL default 0,
            column_map longtext,
            post_type varchar(20) NOT NULL default 'page',
            content_mode varchar(20) NOT NULL default 'hybrid',
            image_source varchar(30) NOT NULL default 'library_then_ai',
            created_by bigint(20) unsigned NOT NULL default 0,
            created_at datetime NOT NULL default '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY import_id (import_id),
            KEY created_at (created_at)
        ) {$collate};" );

        dbDelta( "CREATE TABLE {$p}wab_rows (
            id bigint(20) unsigned NOT NULL auto_increment,
            import_id varchar(64) NOT NULL,
            row_index int(11) NOT NULL default 0,
            topic varchar(500) NOT NULL default '',
            title varchar(500) NOT NULL default '',
            services varchar(500) NOT NULL default '',
            location varchar(255) NOT NULL default '',
            keywords text,
            company varchar(255) NOT NULL default '',
            phone varchar(100) NOT NULL default '',
            street varchar(255) NOT NULL default '',
            region varchar(255) NOT NULL default '',
            postcode varchar(50) NOT NULL default '',
            price varchar(50) NOT NULL default '',
            post_type varchar(20) NOT NULL default '',
            category varchar(255) NOT NULL default '',
            internal_link varchar(500) NOT NULL default '',
            scheduled_date varchar(50) NOT NULL default '',
            description text,
            image_rules text,
            schema_markup longtext,
            schema_type varchar(50) NOT NULL default '',
            extra_data longtext,
            created_at datetime NOT NULL default '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY import_row (import_id,row_index),
            KEY import_id (import_id)
        ) {$collate};" );

        dbDelta( "CREATE TABLE {$p}wab_jobs (
            id bigint(20) unsigned NOT NULL auto_increment,
            job_id varchar(64) NOT NULL,
            import_id varchar(64) NOT NULL default '',
            row_id bigint(20) unsigned NOT NULL default 0,
            row_index int(11) NOT NULL default 0,
            status varchar(20) NOT NULL default 'queued',
            attempts tinyint(3) unsigned NOT NULL default 0,
            locked_by varchar(64) default NULL,
            locked_until datetime default NULL,
            result_post_id bigint(20) unsigned NOT NULL default 0,
            attachment_id bigint(20) unsigned NOT NULL default 0,
            cost_usd decimal(10,6) NOT NULL default 0.000000,
            batch_id varchar(191) default NULL,
            payload longtext,
            error_code varchar(60) default NULL,
            error_message text,
            created_at datetime NOT NULL default '0000-00-00 00:00:00',
            updated_at datetime NOT NULL default '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY job_id (job_id),
            UNIQUE KEY import_row (import_id,row_index),
            KEY status_claim (status,row_index),
            KEY locked_until (locked_until),
            KEY locked_by (locked_by),
            KEY batch_id (batch_id)
        ) {$collate};" );

        // Batch tracking for economy mode. batch_id is the provider's own handle
        // (Gemini returns "batches/xyz"), so it needs room and a 191-char index
        // limit for utf8mb4 compatibility.
        dbDelta( "CREATE TABLE {$p}wab_batches (
            id bigint(20) unsigned NOT NULL auto_increment,
            batch_id varchar(191) NOT NULL,
            provider varchar(30) NOT NULL default '',
            model varchar(80) NOT NULL default '',
            status varchar(20) NOT NULL default 'pending',
            job_count int(11) NOT NULL default 0,
            cost_usd decimal(10,6) NOT NULL default 0.000000,
            error text,
            submitted_at datetime NOT NULL default '0000-00-00 00:00:00',
            completed_at datetime default NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY batch_id (batch_id),
            KEY status (status)
        ) {$collate};" );

        dbDelta( "CREATE TABLE {$p}wab_concepts (
            id bigint(20) unsigned NOT NULL auto_increment,
            import_id varchar(64) NOT NULL,
            prefix_hash varchar(32) NOT NULL default '',
            concept longtext,
            prefix longtext,
            tokens_in int(11) NOT NULL default 0,
            cost_usd decimal(10,6) NOT NULL default 0.000000,
            created_at datetime NOT NULL default '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY import_id (import_id),
            KEY prefix_hash (prefix_hash)
        ) {$collate};" );

        dbDelta( "CREATE TABLE {$p}wab_scan (
            id bigint(20) unsigned NOT NULL auto_increment,
            scan_key varchar(64) NOT NULL,
            data longtext,
            updated_at datetime NOT NULL default '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY scan_key (scan_key)
        ) {$collate};" );

        self::verify_tables();
    }

    /**
     * dbDelta fails silently on some managed hosts. Surface that immediately
     * rather than letting the plugin appear to work and drop every row.
     */
    private static function verify_tables() {
        global $wpdb;

        $missing = array();
        foreach ( array( 'wab_imports', 'wab_rows', 'wab_jobs', 'wab_concepts', 'wab_scan', 'wab_batches' ) as $t ) {
            $full  = $wpdb->prefix . $t;
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
            if ( $found !== $full ) $missing[] = $full;
        }

        // Invalidate the cached "schema is fine" probe so the next check re-tests.
        delete_transient( 'wab_schema_ok' );

        if ( ! empty( $missing ) ) {
            update_option( 'wab_schema_error', $missing, false );
            if ( class_exists( 'WAB_Logger' ) ) {
                WAB_Logger::error( 'Table creation failed for: ' . implode( ', ', $missing ) );
            }
        } else {
            delete_option( 'wab_schema_error' );
            if ( class_exists( 'WAB_Logger' ) ) {
                WAB_Logger::info( 'Schema verified at version ' . self::DB_VERSION );
            }
        }
    }

    // ---------------------------------------------------------------
    // Defaults
    // ---------------------------------------------------------------

    /**
     * add_option() is used deliberately: it does not overwrite an existing value,
     * so re-running on upgrade never clobbers operator settings.
     *
     * Defaults are chosen for the operator's actual situation: ~200 sites, bulk
     * runs of 100+, cost-sensitive. Hence library-first images, hybrid content
     * mode, and a conservative jobs-per-tick.
     */
    private static function seed_options() {
        $defaults = array(
            // Providers
            'wab_text_provider'    => 'gemini',
            'wab_image_provider'   => 'fal',
            'wab_fal_model'        => 'fal-ai/flux/schnell',

            // Cost controls.
            // 'standard' by default on purpose: economy halves the text cost but
            // defers results by minutes-to-hours, and a new operator should see
            // output immediately before trusting a 100-page async run.
            'wab_generation_mode'  => 'standard',
            'wab_image_source'     => 'library_then_ai',
            'wab_content_mode'     => 'hybrid',
            'wab_daily_budget_usd' => 0,
            'wab_image_unit_cost'  => 0.003,
            'wab_text_out_price'   => 2.50,

            // Queue behaviour. 5 per tick with a 60s interval is ~300/hour —
            // ample for 100-page runs, and gentle enough that a shared server
            // hosting many of these sites is never overwhelmed.
            'wab_jobs_per_tick'    => 5,
            'wab_load_threshold'   => 0,
            'wab_queue_paused'     => 0,

            // Content defaults
            'wab_default_status'   => 'draft',
            'wab_post_type'        => 'page',
            'wab_schema_type'      => 'auto',
            'wab_enable_faq'       => 1,

            // Ops
            'wab_verbose_logging'  => 0,
        );

        foreach ( $defaults as $key => $value ) {
            add_option( $key, $value );
        }

        if ( get_option( 'wab_default_author' ) === false ) {
            add_option( 'wab_default_author', get_current_user_id() );
        }
    }

    /**
     * Full teardown, invoked from uninstall.php only.
     */
    public static function uninstall() {
        global $wpdb;

        foreach ( array( 'wab_jobs', 'wab_rows', 'wab_imports', 'wab_concepts', 'wab_scan', 'wab_batches' ) as $t ) {
            $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $t );
        }

        $like = $wpdb->esc_like( 'wab_' ) . '%';
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

        $lock_like = $wpdb->esc_like( '_wab_lock_' ) . '%';
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $lock_like ) );
    }
}
