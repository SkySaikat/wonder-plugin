<?php
/**
 * Job orchestration.
 *
 * CONTRACT (relied upon by WAB_Queue — do not change casually):
 *   - process_job( $job ) MUST return int post ID or WP_Error. Nothing else.
 *     WAB_Queue casts the result with (int) and writes it to result_post_id, so
 *     returning true would store 1 and corrupt the idempotency gate.
 *   - MUST reuse a non-zero $job->attachment_id rather than generating (handled in
 *     WAB_Image_Handler::resolve_featured) — this is the anti-double-billing path.
 *   - MUST heartbeat between expensive steps so a slow job is not reclaimed
 *     mid-flight, which is what produced v1's duplicate posts.
 *   - Error codes MUST match WAB_Cost_Guard's permanent list, or deterministic
 *     failures get retried and re-billed.
 *
 * STEP ORDER IS DELIBERATE:
 *   text -> sanitize -> validate -> image -> post -> inline images -> schema -> meta
 *
 * v1 generated the image at step 2 and inserted the post at step 7, persisting
 * nothing between, so any failure after the image re-bought it on retry. Here the
 * post is created as early as possible and the image ID is persisted the moment it
 * is resolved.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Generator {

    /**
     * @param object $job Row from wab_jobs.
     * @return int|WP_Error
     */
    public static function process_job( $job ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wab_rows WHERE id = %d LIMIT 1",
            (int) $job->row_id
        ) );

        if ( ! $row ) {
            return new WP_Error( 'wab_bad_request', __( 'Source row no longer exists.', 'wonder-ai-builder' ) );
        }

        $import = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wab_imports WHERE import_id = %s LIMIT 1",
            $job->import_id
        ) );

        $mode      = $import->content_mode ?? get_option( 'wab_content_mode', WAB_Prompt_Builder::MODE_HYBRID );
        $post_type = self::resolve_post_type( $row, $import );

        // ---- 1. TEXT -------------------------------------------------
        $content = self::generate_content( $job, $row, $import, $mode );
        if ( is_wp_error( $content ) ) return $content;

        WAB_Queue::heartbeat( $job->job_id );

        // ---- 2. SANITIZE + VALIDATE ----------------------------------
        // Model output is untrusted input. See WAB_Content_Sanitizer's header for
        // the Author -> stored XSS chain this closes.
        $body = WAB_Content_Sanitizer::clean_post_html( $content['content'] ?? '' );

        $min_words = (int) get_option( 'wab_min_words', 120 );
        $valid     = WAB_Content_Sanitizer::validate( $body, $min_words );
        if ( is_wp_error( $valid ) ) return $valid; // Permanent — never retried.

        $title = WAB_Content_Sanitizer::clean_text( $content['title'] ?? $row->title, 160 );
        if ( $title === '' ) {
            return new WP_Error( 'wab_empty_content', __( 'No usable title was generated.', 'wonder-ai-builder' ) );
        }

        // ---- 3. FEATURED IMAGE ---------------------------------------
        // Resolved BEFORE insert so the attachment ID is persisted against the job
        // even if the insert later fails. A miss is non-fatal: a page without an
        // image is far better than a failed job that re-bills text on retry.
        $featured_id = 0;
        $image       = WAB_Image_Handler::resolve_featured( $job, $row, $content );
        if ( is_wp_error( $image ) ) {
            WAB_Logger::info( sprintf( 'Job %s has no featured image: %s', $job->job_id, $image->get_error_message() ) );
        } else {
            $featured_id = (int) $image;
        }

        WAB_Queue::heartbeat( $job->job_id );

        // ---- 4. INLINE IMAGES (library only, always $0) --------------
        $content['_featured_id'] = $featured_id;
        $body = WAB_Image_Handler::inject_inline_images( $body, $row, $content );

        // Re-sanitize: figure/img markup was inserted after the first kses pass.
        $body = WAB_Content_Sanitizer::clean_post_html( $body );

        // ---- 5. INSERT POST ------------------------------------------
        $post_id = self::insert_post( $job, $row, $import, $content, $title, $body, $post_type, $featured_id );
        if ( is_wp_error( $post_id ) ) return $post_id;

        WAB_Queue::heartbeat( $job->job_id );

        // ---- 6. SCHEMA (PHP, zero tokens) ----------------------------
        WAB_Schema_Builder::apply( $post_id, $row, $content );

        // ---- 7. SEO META ---------------------------------------------
        self::apply_seo_meta( $post_id, $row, $content );

        WAB_Logger::info( sprintf( 'Job %s created %s %d.', $job->job_id, $post_type, $post_id ) );

        return (int) $post_id;
    }

    // ---------------------------------------------------------------
    // Text generation
    // ---------------------------------------------------------------

    private static function generate_content( $job, $row, $import, $mode ) {

        /**
         * ECONOMY MODE: text already paid for.
         *
         * When a batch has returned, its payload is stored on the job. Consuming it
         * here means no text API call at all — and, importantly, a failure later in
         * the pipeline retries WITHOUT re-billing the text, exactly as persisted
         * attachment_id prevents re-billing the image.
         */
        if ( ! empty( $job->payload ) ) {
            $decoded = json_decode( (string) $job->payload, true );
            if ( is_array( $decoded ) && ! empty( $decoded['content'] ) ) {
                WAB_Logger::info( sprintf( 'Job %s using batched payload (no text call).', $job->job_id ) );
                return $decoded;
            }
            // Corrupt payload: fall through and generate normally rather than fail.
            WAB_Logger::warn( sprintf( 'Job %s had an unusable batched payload; regenerating.', $job->job_id ) );
        }

        $provider = WAB_Provider_Registry::text();
        if ( ! $provider->is_configured() ) {
            return new WP_Error( 'wab_no_key', __( 'Text provider is not configured.', 'wonder-ai-builder' ) );
        }

        // The cacheable prefix is built ONCE per import and stored, guaranteeing it
        // is byte-identical on every row. Provider prompt caches only hit on an
        // exact prefix match, so rebuilding it per row would silently forfeit the
        // discount — the whole point of the prefix/delta split.
        $prefix = self::get_or_build_prefix( $job->import_id, $row, $mode );

        $want_faq = (bool) get_option( 'wab_enable_faq', 1 );

        $delta = WAB_Prompt_Builder::build_delta( $row, array(
            'mode'              => $mode,
            'row_index'         => (int) $job->row_index,
            'sibling_locations' => self::sibling_locations( $job->import_id, $row ),
            'internal_links'    => WAB_Scanner::internal_link_candidates( $row ),
        ) );

        $result = $provider->generate(
            $prefix,
            $delta,
            WAB_Prompt_Builder::output_schema( $want_faq ),
            array( 'max_tokens' => WAB_Prompt_Builder::estimate_output_tokens( $mode, $want_faq ) + 1024 )
        );

        if ( is_wp_error( $result ) ) return $result;

        $cost = (float) ( $result['cost'] ?? 0 );
        if ( $cost > 0 ) {
            WAB_Cost_Guard::record( $cost, 'text' );
            WAB_Queue::record_cost( $job->job_id, $cost );
        }

        if ( ! empty( $result['cached_in'] ) ) {
            WAB_Logger::info( sprintf(
                'Job %s: %d input tokens served from cache.',
                $job->job_id,
                (int) $result['cached_in']
            ) );
        }

        $data = $result['data'];
        if ( ! is_array( $data ) || empty( $data['content'] ) ) {
            return new WP_Error( 'wab_empty_content', __( 'Provider returned no content field.', 'wonder-ai-builder' ) );
        }

        return $data;
    }

    /**
     * Fetch or create the shared prefix for an import.
     *
     * Stored in wab_concepts so all 100 rows send identical bytes.
     */
    /**
     * Public accessor so WAB_Batch builds byte-identical prefixes to the standard
     * path. If these ever diverged, batched and interactive pages would read
     * differently and the provider prompt cache would miss.
     */
    public static function prefix_for_import( $import_id, $row, $mode ) {
        return self::get_or_build_prefix( $import_id, $row, $mode );
    }

    private static function get_or_build_prefix( $import_id, $row, $mode ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wab_concepts';

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT prefix FROM {$table} WHERE import_id = %s LIMIT 1",
            $import_id
        ) );

        if ( $existing && ! empty( $existing->prefix ) ) {
            return (string) $existing->prefix;
        }

        $concept = array(
            'industry' => get_option( 'wab_concept_industry', '' ),
            'audience' => get_option( 'wab_concept_audience', '' ),
            'tone'     => get_option( 'wab_concept_tone', 'professional, direct, no filler' ),
            'usps'     => array_filter( array_map( 'trim', explode( "\n", (string) get_option( 'wab_concept_usps', '' ) ) ) ),
            'avoid'    => array_filter( array_map( 'trim', explode( "\n", (string) get_option( 'wab_concept_avoid', '' ) ) ) ),
        );

        // Fall back to the row's own company/service so a prefix is always useful
        // even when the operator left the concept fields blank.
        if ( $concept['industry'] === '' ) {
            $concept['industry'] = trim( (string) ( $row->services ?: $row->topic ) );
        }

        $prefix = WAB_Prompt_Builder::build_prefix(
            $concept,
            array(
                'name'        => get_bloginfo( 'name' ),
                'description' => get_bloginfo( 'description' ),
            ),
            array( 'mode' => $mode )
        );

        // INSERT IGNORE: two workers cannot race a duplicate concept row because
        // import_id is UNIQUE.
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (import_id, prefix_hash, concept, prefix, created_at)
             VALUES (%s, %s, %s, %s, %s)",
            $import_id,
            md5( $prefix ),
            wp_json_encode( $concept ),
            $prefix,
            current_time( 'mysql' )
        ) );

        return $prefix;
    }

    /**
     * Other locations in the same import — enables genuine internal linking without
     * shipping the whole site map on every row the way v1 did.
     */
    private static function sibling_locations( $import_id, $row ) {
        global $wpdb;

        $current = trim( (string) ( $row->location ?? '' ) );
        if ( $current === '' ) return array();

        $cache_key = 'wab_siblings_' . md5( $import_id );
        $all       = wp_cache_get( $cache_key, 'wab' );

        if ( ! is_array( $all ) ) {
            $all = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT location FROM {$wpdb->prefix}wab_rows
                  WHERE import_id = %s AND location <> '' LIMIT 200",
                $import_id
            ) );
            $all = is_array( $all ) ? $all : array();
            wp_cache_set( $cache_key, $all, 'wab', 600 );
        }

        $others = array_values( array_diff( $all, array( $current ) ) );
        shuffle( $others );

        return array_slice( $others, 0, 4 );
    }

    // ---------------------------------------------------------------
    // Post creation
    // ---------------------------------------------------------------

    private static function insert_post( $job, $row, $import, array $content, $title, $body, $post_type, $featured_id ) {
        $status = get_option( 'wab_default_status', 'draft' );
        $date   = '';

        // Honour a future scheduled_date from the sheet.
        if ( ! empty( $row->scheduled_date ) ) {
            $ts = strtotime( (string) $row->scheduled_date );
            if ( $ts && $ts > time() ) {
                $date   = gmdate( 'Y-m-d H:i:s', $ts );
                $status = 'future';
            }
        }

        $slug = sanitize_title( $content['slug'] ?? $title );

        $postarr = array(
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $body,
            'post_excerpt' => WAB_Content_Sanitizer::clean_text( $content['excerpt'] ?? '', 300 ),
            'post_status'  => $status,
            'post_type'    => $post_type,
            'post_author'  => (int) get_option( 'wab_default_author', get_current_user_id() ),
        );

        if ( $date !== '' ) {
            $postarr['post_date_gmt'] = $date;
            $postarr['post_date']     = get_date_from_gmt( $date );
        }

        $post_id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $post_id ) ) {
            return new WP_Error( 'wab_insert_failed', $post_id->get_error_message() );
        }

        // Categories only apply to posts.
        if ( $post_type === 'post' ) {
            self::assign_taxonomy( $post_id, $row, $import );
        }

        if ( ! empty( $row->keywords ) ) {
            $tags = array_slice( array_filter( array_map( 'trim', explode( ',', (string) $row->keywords ) ) ), 0, 12 );
            if ( $tags ) wp_set_post_tags( $post_id, $tags, false );
        }

        if ( $featured_id > 0 ) {
            set_post_thumbnail( $post_id, $featured_id );
        }

        $template = get_option( 'wab_page_template', '' );
        if ( $post_type === 'page' && $template && $template !== 'default' ) {
            update_post_meta( $post_id, '_wp_page_template', $template );
        }

        // Provenance, so generated content is always identifiable and revertible.
        update_post_meta( $post_id, '_wab_generated', 1 );
        update_post_meta( $post_id, '_wab_job_id', $job->job_id );
        update_post_meta( $post_id, '_wab_import_id', $job->import_id );

        return (int) $post_id;
    }

    private static function assign_taxonomy( $post_id, $row, $import ) {
        $name = trim( (string) ( $row->category ?? '' ) );

        if ( $name === '' ) {
            $default = (int) get_option( 'wab_default_category', 0 );
            if ( $default > 0 ) wp_set_post_categories( $post_id, array( $default ), false );
            return;
        }

        $term = get_term_by( 'name', $name, 'category' );
        if ( ! $term ) {
            $created = wp_insert_term( $name, 'category' );
            if ( is_wp_error( $created ) ) return;
            $term_id = (int) $created['term_id'];
        } else {
            $term_id = (int) $term->term_id;
        }

        wp_set_post_categories( $post_id, array( $term_id ), false );
    }

    private static function resolve_post_type( $row, $import ) {
        $candidates = array(
            $row->post_type ?? '',
            $import->post_type ?? '',
            get_option( 'wab_post_type', 'page' ),
        );

        foreach ( $candidates as $c ) {
            $c = strtolower( trim( (string) $c ) );
            if ( $c === 'post' || $c === 'blog' )  return 'post';
            if ( $c === 'page' )                   return 'page';
        }

        return 'page';
    }

    /**
     * Write meta description and focus keyword for whichever SEO plugin is active.
     *
     * v1 wrote meta_keywords to AIOSEO — Google has ignored that tag since 2009, so
     * the field is no longer requested from the model at all.
     */
    private static function apply_seo_meta( $post_id, $row, array $content ) {
        $meta = WAB_Content_Sanitizer::clean_text( $content['meta'] ?? $content['meta_description'] ?? '', 158 );
        $kw   = WAB_Content_Sanitizer::clean_text( $content['kw'] ?? '', 80 );

        if ( $meta !== '' ) {
            update_post_meta( $post_id, '_wab_meta_description', $meta );
            if ( defined( 'WPSEO_VERSION' ) || post_type_exists( 'wpseo' ) ) {
                update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta );
            } else {
                update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta );
            }
            update_post_meta( $post_id, 'rank_math_description', $meta );
            update_post_meta( $post_id, '_aioseo_description', $meta );
        }

        if ( $kw !== '' ) {
            update_post_meta( $post_id, '_wab_focus_keyword', $kw );
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', $kw );
            update_post_meta( $post_id, 'rank_math_focus_keyword', $kw );
        }

        foreach ( array( 'company', 'location', 'phone' ) as $f ) {
            if ( ! empty( $row->$f ) ) {
                update_post_meta( $post_id, '_wab_' . $f, sanitize_text_field( (string) $row->$f ) );
            }
        }
    }
}
