<?php
/**
 * Site context and internal-link candidates.
 *
 * v1 shipped up to 50 page titles + URLs into EVERY row's prompt
 * (class-gemini.php:224 via class-scanner.php:98-101) — the same ~1,200 tokens
 * billed 100 times per import, and a prompt-injection surface, since published post
 * titles are attacker-influenceable by any Author.
 *
 * v2 sends at most 3 RELEVANT links in the per-row delta, chosen by keyword overlap
 * in SQL. Titles are still treated as untrusted text and sanitised before they enter
 * a prompt.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Scanner {

    const CACHE_TTL = 900;

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wab_scan';
    }

    /**
     * Up to 3 existing pages topically related to this row.
     *
     * @return array<int, array{title:string, url:string}>
     */
    public static function internal_link_candidates( $row ) {
        global $wpdb;

        $terms = self::keywords( $row );
        if ( empty( $terms ) ) return array();

        $cache_key = 'wab_links_' . md5( implode( '|', $terms ) );
        $cached    = wp_cache_get( $cache_key, 'wab' );
        if ( is_array( $cached ) ) return $cached;

        $where  = array();
        $params = array();

        foreach ( array_slice( $terms, 0, 4 ) as $t ) {
            $like     = '%' . $wpdb->esc_like( $t ) . '%';
            $where[]  = 'post_title LIKE %s';
            $params[] = $like;
        }

        $sql = "SELECT ID, post_title FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type IN ('post','page')
                   AND ( " . implode( ' OR ', $where ) . " )
                 ORDER BY post_modified DESC
                 LIMIT 5";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $out = array();
        foreach ( (array) $rows as $r ) {
            $url = get_permalink( (int) $r->ID );
            if ( ! $url ) continue;

            // Post titles are untrusted prompt input — strip anything that could
            // read as an instruction, and cap the length.
            $title = WAB_Content_Sanitizer::clean_text( $r->post_title, 90 );
            if ( $title === '' ) continue;

            $out[] = array( 'title' => $title, 'url' => $url );
            if ( count( $out ) >= 3 ) break;
        }

        wp_cache_set( $cache_key, $out, 'wab', 300 );

        return $out;
    }

    private static function keywords( $row ) {
        $bits = array();
        foreach ( array( 'services', 'topic', 'category', 'keywords' ) as $f ) {
            $v = is_object( $row ) ? ( $row->$f ?? '' ) : ( $row[ $f ] ?? '' );
            $v = trim( (string) $v );
            if ( $v !== '' ) $bits[] = $v;
        }

        $text  = strtolower( implode( ' ', $bits ) );
        $text  = preg_replace( '/[^a-z0-9\s]/', ' ', $text );
        $parts = preg_split( '/\s+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY );

        $stop = array( 'the','and','for','with','services','service','in','of','near','best','top' );
        $out  = array();

        foreach ( (array) $parts as $p ) {
            if ( strlen( $p ) < 4 || in_array( $p, $stop, true ) ) continue;
            $out[ $p ] = true;
        }

        return array_keys( $out );
    }

    /**
     * Cached site summary for the dashboard. Deliberately NOT injected into prompts.
     */
    public static function site_summary( $force = false ) {
        global $wpdb;
        $t = self::table();

        if ( ! $force ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT data, updated_at FROM {$t} WHERE scan_key = %s LIMIT 1",
                'summary'
            ) );

            if ( $row && strtotime( $row->updated_at ) > ( time() - self::CACHE_TTL ) ) {
                $decoded = json_decode( $row->data, true );
                if ( is_array( $decoded ) ) return $decoded;
            }
        }

        // Counts only — no full post list, so this is cheap even on large sites.
        $counts = array();
        foreach ( array( 'post', 'page' ) as $pt ) {
            $c = wp_count_posts( $pt );
            $counts[ $pt ] = (int) ( $c->publish ?? 0 );
        }

        $images = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
              WHERE post_type = 'attachment' AND post_status = 'inherit'
                AND post_mime_type LIKE 'image/%'"
        );

        $data = array(
            'site_name'     => get_bloginfo( 'name' ),
            'published'     => $counts,
            'library_size'  => $images,
            'theme'         => wp_get_theme()->get( 'Name' ),
            'scanned_at'    => current_time( 'mysql' ),
        );

        $wpdb->replace( $t, array(
            'scan_key'   => 'summary',
            'data'       => wp_json_encode( $data ),
            'updated_at' => current_time( 'mysql' ),
        ) );

        return $data;
    }
}
