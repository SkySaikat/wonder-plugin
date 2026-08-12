<?php
/**
 * Reuse existing media library images via PHP metadata matching. ZERO API cost.
 *
 * ============================================================================
 * WHY THIS BEATS EVERY GENERATION OPTION
 * ============================================================================
 * Images were ~82% of the v1 per-item bill. The cost ladder:
 *
 *   gemini-2.5-flash-image (v1)   ~$0.039 / image
 *   fal-ai/flux-2-pro              $0.030 / image
 *   fal-ai/flux/schnell            $0.003 / image
 *   THIS CLASS                     $0.000  <-- and instant, and no rate limits
 *
 * Sites that have already published hundreds of pages own a library of on-brand,
 * already-optimised, already-CDN-cached images. Matching against it is strictly
 * better than generating a new one: free, faster, and visually consistent with
 * everything already on the site.
 *
 * ============================================================================
 * NO API, BY DESIGN
 * ============================================================================
 * Sending hundreds of image metadata records to a model to pick one would cost
 * more in input tokens than generating a fresh image, and would be slower. So
 * matching is deterministic PHP: weighted token overlap over alt text, title,
 * caption, description, filename, and the terms of any post the image is
 * attached to.
 *
 * Candidate selection happens in SQL with a bounded LIMIT, so a library of 50,000
 * attachments never gets loaded into PHP memory — which matters given v1's history
 * of exhausting server RAM.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Image_Library {

    /** Scoring weights. Alt text is the strongest signal — it is written to describe. */
    private const W_ALT        = 5.0;
    private const W_TITLE      = 4.0;
    private const W_CAPTION    = 2.5;
    private const W_DESC       = 2.0;
    private const W_FILENAME   = 1.5;
    private const W_TERMS      = 3.0;

    /** Max attachments pulled from SQL per lookup. Bounded for memory safety. */
    private const CANDIDATE_LIMIT = 120;

    /** Below this score we do not consider it a real match. */
    private const MIN_SCORE = 6.0;

    const META_USE_COUNT = '_wab_reuse_count';

    /**
     * Find the best existing image for a row.
     *
     * @param object $row     Import row.
     * @param array  $content Generated content (title, kw) — improves matching.
     * @param array  $opts    min_width, exclude_ids, allow_reuse_within_import.
     * @return int|WP_Error Attachment ID, or WP_Error when nothing suitable exists.
     */
    public static function find_best( $row, array $content = array(), array $opts = array() ) {
        $keywords = self::extract_keywords( $row, $content );
        if ( empty( $keywords ) ) {
            return new WP_Error( 'wab_no_keywords', __( 'Not enough row data to match a local image.', 'wonder-ai-builder' ) );
        }

        $candidates = self::query_candidates( $keywords, $opts );
        if ( empty( $candidates ) ) {
            return new WP_Error( 'wab_no_candidates', __( 'No local images matched. Falling back to generation.', 'wonder-ai-builder' ) );
        }

        $exclude   = array_map( 'intval', (array) ( $opts['exclude_ids'] ?? array() ) );
        $min_width = (int) ( $opts['min_width'] ?? 600 );

        /**
         * Prime the object cache before scoring.
         *
         * Scoring touches wp_get_attachment_metadata(), post_parent and the parent's
         * terms for up to 120 candidates. Uncached that is ~240-480 individual
         * queries per row, i.e. tens of thousands per 100-row import — on the same
         * shared box this class promises not to flatten. Two bulk queries replace
         * them.
         */
        $candidate_ids = wp_list_pluck( $candidates, 'ID' );
        if ( ! empty( $candidate_ids ) ) {
            _prime_post_caches( $candidate_ids, false, true );
        }

        $scored = array();

        foreach ( $candidates as $att ) {
            $id = (int) $att->ID;
            if ( in_array( $id, $exclude, true ) ) continue;

            $meta = wp_get_attachment_metadata( $id );
            $w    = (int) ( $meta['width']  ?? 0 );
            $h    = (int) ( $meta['height'] ?? 0 );

            // Reject images too small to serve as a featured image.
            if ( $w > 0 && $w < $min_width ) continue;

            $score = self::score( $id, $att, $keywords, $w, $h );
            if ( $score < self::MIN_SCORE ) continue;

            $scored[ $id ] = $score;
        }

        if ( empty( $scored ) ) {
            return new WP_Error( 'wab_no_match', __( 'No local image scored high enough. Falling back to generation.', 'wonder-ai-builder' ) );
        }

        // Highest score wins; ties broken by LEAST previously reused, so a
        // 100-page import spreads across the library instead of stamping the
        // same photo onto every page.
        arsort( $scored );
        $top = array_slice( $scored, 0, 8, true );

        $best_id    = null;
        $best_score = -1.0;
        $best_uses  = PHP_INT_MAX;

        foreach ( $top as $id => $score ) {
            $uses = (int) get_post_meta( $id, self::META_USE_COUNT, true );

            // Only prefer a lower-used image when scores are genuinely comparable.
            $comparable = ( $best_score < 0 ) || ( ( $best_score - $score ) <= 1.5 );

            if ( $best_score < 0 || ( $comparable && $uses < $best_uses ) ) {
                $best_id    = $id;
                $best_score = ( $best_score < 0 ) ? $score : $best_score;
                $best_uses  = $uses;
            }
        }

        if ( ! $best_id ) return new WP_Error( 'wab_no_match', __( 'No local image selected.', 'wonder-ai-builder' ) );

        return $best_id;
    }

    /**
     * Record that an image was used, so ties rotate on subsequent rows.
     */
    public static function mark_used( $attachment_id ) {
        $attachment_id = (int) $attachment_id;
        if ( $attachment_id <= 0 ) return;
        $count = (int) get_post_meta( $attachment_id, self::META_USE_COUNT, true );
        update_post_meta( $attachment_id, self::META_USE_COUNT, $count + 1 );
    }

    // ---------------------------------------------------------------
    // Keyword extraction
    // ---------------------------------------------------------------

    private static function extract_keywords( $row, array $content ) {
        $sources = array();

        foreach ( array( 'services', 'topic', 'title', 'location', 'keywords', 'category', 'company' ) as $f ) {
            $v = self::row_field( $row, $f );
            if ( $v !== '' ) $sources[] = $v;
        }

        if ( ! empty( $content['kw'] ) )    $sources[] = (string) $content['kw'];
        if ( ! empty( $content['title'] ) ) $sources[] = (string) $content['title'];

        return self::tokenize( implode( ' ', $sources ) );
    }

    /**
     * Lowercase, split, drop stop words and short tokens, de-duplicate.
     */
    private static function tokenize( $text ) {
        $text = strtolower( wp_strip_all_tags( (string) $text ) );
        $text = str_replace( array( '-', '_', '/', '.', ',' ), ' ', $text );
        $text = preg_replace( '/[^a-z0-9\s]/', ' ', $text );

        $parts = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $parts ) return array();

        $stop = self::stop_words();
        $out  = array();

        foreach ( $parts as $p ) {
            if ( strlen( $p ) < 3 )                  continue;
            if ( isset( $stop[ $p ] ) )              continue;
            if ( is_numeric( $p ) )                  continue;
            $out[ $p ] = true;
        }

        return array_keys( $out );
    }

    private static function stop_words() {
        static $stop = null;
        if ( $stop !== null ) return $stop;

        $words = array(
            'the','and','for','with','from','that','this','are','was','were','has','have','had',
            'you','your','our','their','its','his','her','not','but','all','can','will','would',
            'about','into','over','under','more','most','best','top','new','how','why','what',
            'when','where','who','which','than','then','also','any','out','get','got','one','two',
            'services','service','company','business','near','area','areas','local','professional',
            'quality','affordable','cheap','free','online','website','page','post','blog',
        );

        $stop = array_fill_keys( $words, true );
        return $stop;
    }

    // ---------------------------------------------------------------
    // Candidate selection (bounded SQL)
    // ---------------------------------------------------------------

    /**
     * Pull a bounded candidate set matching ANY keyword.
     *
     * Deliberately does NOT use get_posts() with meta_query — that generates
     * unindexed LIKE joins across the whole postmeta table and is exactly the kind
     * of query that flattens a shared server.
     */
    private static function query_candidates( array $keywords, array $opts ) {
        global $wpdb;

        // Cap the number of OR branches; more than this stops discriminating anyway.
        $keywords = array_slice( $keywords, 0, 8 );
        if ( empty( $keywords ) ) return array();

        $where  = array();
        $params = array();

        foreach ( $keywords as $kw ) {
            $like = '%' . $wpdb->esc_like( $kw ) . '%';

            $where[]  = '(p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s OR alt.meta_value LIKE %s OR p.guid LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $mime_clause = "AND p.post_mime_type IN ('image/jpeg','image/png','image/webp','image/avif','image/gif')";

        $sql = "
            SELECT p.ID, p.post_title, p.post_excerpt, p.post_content, p.guid,
                   MAX(alt.meta_value) AS alt_text
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} alt
                   ON alt.post_id = p.ID
                  AND alt.meta_key = '_wp_attachment_image_alt'
            WHERE p.post_type = 'attachment'
              AND p.post_status = 'inherit'
              {$mime_clause}
              AND ( " . implode( ' OR ', $where ) . " )
            GROUP BY p.ID
            ORDER BY p.post_date DESC
            LIMIT %d
        ";

        $params[] = self::CANDIDATE_LIMIT;

        // Every interpolated value is a placeholder; the only non-placeholder
        // fragments are the hardcoded mime list and the generated placeholder set.
        $prepared = $wpdb->prepare( $sql, $params );

        $rows = $wpdb->get_results( $prepared );

        return is_array( $rows ) ? $rows : array();
    }

    // ---------------------------------------------------------------
    // Scoring
    // ---------------------------------------------------------------

    private static function score( $id, $att, array $keywords, $width, $height ) {
        $score = 0.0;

        /**
         * Weight/value TUPLES, not a weight-keyed map.
         *
         * Using the float weights as array keys silently broke scoring: PHP
         * truncates float keys to int, so 2.5 (caption) and 2.0 (description) both
         * became key 2 — the caption was overwritten and never scored at all — and
         * 1.5 (filename) became 1. It also emitted two "Implicit conversion from
         * float ... loses precision" deprecations per candidate, i.e. ~24,000 log
         * lines per 100-row import.
         */
        $fields = array(
            array( self::W_ALT,      (string) ( $att->alt_text ?? '' ) ),
            array( self::W_TITLE,    (string) ( $att->post_title ?? '' ) ),
            array( self::W_CAPTION,  (string) ( $att->post_excerpt ?? '' ) ),
            array( self::W_DESC,     (string) ( $att->post_content ?? '' ) ),
            array( self::W_FILENAME, wp_basename( (string) ( $att->guid ?? '' ) ) ),
        );

        foreach ( $fields as list( $weight, $value ) ) {
            if ( $value === '' ) continue;
            $tokens = self::tokenize( $value );
            if ( empty( $tokens ) ) continue;

            $hits = count( array_intersect( $keywords, $tokens ) );
            if ( $hits === 0 ) continue;

            // Diminishing returns: 1 strong hit matters far more than the 6th.
            $score += (float) $weight * sqrt( $hits );
        }

        // Terms of the post this image is attached to are a good topical signal.
        $parent = (int) get_post_field( 'post_parent', $id );
        if ( $parent > 0 ) {
            $term_tokens = self::parent_term_tokens( $parent );
            if ( ! empty( $term_tokens ) ) {
                $hits = count( array_intersect( $keywords, $term_tokens ) );
                if ( $hits > 0 ) $score += self::W_TERMS * sqrt( $hits );
            }
        }

        // Landscape suits featured images; heavy portrait crops badly in most themes.
        if ( $width > 0 && $height > 0 ) {
            $ratio = $width / max( 1, $height );
            if ( $ratio >= 1.3 && $ratio <= 2.1 ) {
                $score += 2.0;
            } elseif ( $ratio < 0.9 ) {
                $score -= 1.5;
            }
        }

        return round( $score, 3 );
    }

    private static function parent_term_tokens( $parent_id ) {
        $cache_key = 'wab_terms_' . $parent_id;
        $cached    = wp_cache_get( $cache_key, 'wab' );
        if ( is_array( $cached ) ) return $cached;

        $names = array();
        foreach ( array( 'category', 'post_tag' ) as $tax ) {
            $terms = get_the_terms( $parent_id, $tax );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $t ) $names[] = $t->name;
            }
        }

        $tokens = self::tokenize( implode( ' ', $names ) );
        wp_cache_set( $cache_key, $tokens, 'wab', 300 );

        return $tokens;
    }

    private static function row_field( $row, $key ) {
        if ( is_object( $row ) ) return isset( $row->$key ) ? trim( (string) $row->$key ) : '';
        if ( is_array( $row ) )  return isset( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
        return '';
    }

    /**
     * Dry-run preview for the admin UI: show which local image WOULD be picked,
     * and its score, before committing to a 100-row run.
     */
    public static function preview( $row, array $content = array() ) {
        $id = self::find_best( $row, $content );

        if ( is_wp_error( $id ) ) {
            return array(
                'matched' => false,
                'reason'  => $id->get_error_message(),
            );
        }

        return array(
            'matched'   => true,
            'id'        => $id,
            'url'       => wp_get_attachment_image_url( $id, 'medium' ),
            'alt'       => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'title'     => get_the_title( $id ),
            'uses'      => (int) get_post_meta( $id, self::META_USE_COUNT, true ),
            'cost_saved'=> 0.003,
        );
    }
}
