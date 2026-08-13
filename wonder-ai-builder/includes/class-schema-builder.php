<?php
/**
 * Deterministic JSON-LD schema generation — ZERO API cost.
 *
 * THE v1 PROBLEM
 * --------------
 * 1. The `schema_markup` CSV column was mapped (class-excel-importer.php:21) and
 *    given a DB column (class-activator.php:39), then NEVER READ AGAIN. Grep the
 *    v1 tree: it appears in the importer and the schema definition only. Customers'
 *    schema was imported and silently discarded — it never reached a single post.
 *
 * 2. The AI was asked to return a `schema_type` field, marked *required*
 *    (class-gemini.php:250,254) — billed output tokens on every row — and
 *    `process_row()` discarded that too.
 *
 * 3. Any CSV column that failed to map got dumped into `extra_data`
 *    (class-excel-importer.php:122-130) and JSON-encoded straight into the prompt
 *    as `Extra Details:` (class-gemini.php:181,211). A JSON-LD blob in an unmapped
 *    column was therefore injected into every single prompt as billed input.
 *
 * THE v2 APPROACH
 * ---------------
 * Schema is structured data. Structured data does not need a language model.
 * We build it in PHP from fields we already have, AFTER the post exists (so we can
 * use the real permalink, post date, and attachment URL — none of which the model
 * could know). The model is never asked about schema, and schema text is never
 * sent to it.
 *
 * Cost: 0 input tokens, 0 output tokens, 0 API calls.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Schema_Builder {

    const META_KEY   = '_wab_schema';
    const META_SOURCE = '_wab_schema_source';

    /**
     * Schema types we can build without any AI involvement.
     */
    public static function supported_types() {
        return array(
            // Default. Matches how operators actually work: schema is authored in
            // the sheet, and a row without one simply does not need structured data.
            'csv_only'      => __( 'Only what the sheet provides (recommended)', 'wonder-ai-builder' ),
            'auto'          => __( 'Auto-build from page type', 'wonder-ai-builder' ),
            'LocalBusiness' => 'LocalBusiness',
            'Service'       => 'Service',
            'Product'       => 'Product',
            'Article'       => 'Article',
            'BlogPosting'   => 'BlogPosting',
            'WebPage'       => 'WebPage',
            'FAQPage'       => 'FAQPage',
            'none'          => __( 'No schema', 'wonder-ai-builder' ),
        );
    }

    /**
     * Build and persist schema for a freshly created post.
     *
     * @param int      $post_id
     * @param object   $row      Import row (topic, location, company, phone, schema_markup...).
     * @param array    $content  Generated content payload (excerpt, meta_description, faq...).
     * @return array|null The graph that was stored, or null when disabled.
     */
    public static function apply( $post_id, $row, array $content = array() ) {
        $post = get_post( $post_id );
        if ( ! $post ) return null;

        // ---------------------------------------------------------------
        // Path A: the sheet supplied raw JSON-LD. Honour it verbatim.
        // This is the feature v1 advertised via the "Schema" column but never
        // implemented. No API call, no tokens — just validation.
        // ---------------------------------------------------------------
        $raw = isset( $row->schema_markup ) ? trim( (string) $row->schema_markup ) : '';
        if ( $raw !== '' ) {
            $custom = self::parse_custom_schema( $raw, $post_id );
            if ( ! is_wp_error( $custom ) ) {
                self::store( $post_id, $custom, 'csv' );
                return $custom;
            }
            // Malformed JSON in the sheet: log and fall through to auto-build
            // rather than shipping broken markup to Google.
            WAB_Logger::warn(
                sprintf( 'Row schema_markup for post %d was not valid JSON-LD (%s). Falling back to auto-built schema.',
                    $post_id, $custom->get_error_message() )
            );
        }

        // ---------------------------------------------------------------
        // Path B: build deterministically from data we already hold.
        // ---------------------------------------------------------------
        //
        // Unless the operator asked for sheet-only. In csv_only mode a row with no
        // Schema column gets NO structured data at all — which is the correct
        // outcome: absent schema means that page did not need any. Nothing is
        // invented, and either way the API is never involved.
        if ( get_option( 'wab_schema_type', 'csv_only' ) === 'csv_only' ) {
            delete_post_meta( $post_id, self::META_KEY );
            return null;
        }

        $type = self::resolve_type( $row, $post );
        if ( $type === 'none' ) {
            delete_post_meta( $post_id, self::META_KEY );
            return null;
        }

        $graph = self::build( $type, $post, $row, $content );
        self::store( $post_id, $graph, 'auto' );
        return $graph;
    }

    /**
     * Validate and normalise sheet-supplied JSON-LD.
     *
     * @return array|WP_Error
     */
    private static function parse_custom_schema( $raw, $post_id ) {
        // Tolerate a <script type="application/ld+json"> wrapper, which is how
        // people usually copy schema out of a generator tool.
        if ( stripos( $raw, '<script' ) !== false ) {
            if ( preg_match( '#<script[^>]*>(.*?)</script>#is', $raw, $m ) ) {
                $raw = $m[1];
            }
        }

        $raw = trim( $raw );
        if ( $raw === '' ) {
            return new WP_Error( 'empty', 'empty after unwrapping' );
        }

        $decoded = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
            return new WP_Error( 'invalid_json', json_last_error_msg() );
        }

        // Guarantee @context so the block is valid standalone.
        if ( ! isset( $decoded['@context'] ) && ! isset( $decoded['@graph'] ) ) {
            $decoded = array_merge( array( '@context' => 'https://schema.org' ), $decoded );
        }

        // Substitute placeholders the sheet author could not know at authoring time.
        $decoded = self::interpolate( $decoded, array(
            '{{url}}'     => get_permalink( $post_id ),
            '{{title}}'   => get_the_title( $post_id ),
            '{{site}}'    => get_bloginfo( 'name' ),
            '{{date}}'    => get_the_date( 'c', $post_id ),
            '{{image}}'   => (string) get_the_post_thumbnail_url( $post_id, 'full' ),
        ) );

        return self::sanitize_graph( $decoded );
    }

    private static function interpolate( $node, array $map ) {
        if ( is_string( $node ) ) {
            return strtr( $node, $map );
        }
        if ( is_array( $node ) ) {
            foreach ( $node as $k => $v ) {
                $node[ $k ] = self::interpolate( $v, $map );
            }
        }
        return $node;
    }

    /**
     * Decide the schema type with no AI call.
     *
     * Precedence: explicit sheet column > global setting > inferred from signals.
     */
    private static function resolve_type( $row, $post ) {
        $supported = array_keys( self::supported_types() );

        // 1. Explicit per-row override.
        $row_type = isset( $row->schema_type ) ? trim( (string) $row->schema_type ) : '';
        if ( $row_type !== '' ) {
            foreach ( $supported as $s ) {
                // 'auto' and 'csv_only' are UI sentinels, not schema.org types.
                // Matching either would emit '"@type":"auto"' — invalid structured
                // data on every page — because build() has no case for them and falls
                // through to default. 'none' is handled earlier by the caller.
                if ( $s === 'auto' || $s === 'csv_only' ) continue;
                if ( strcasecmp( $s, $row_type ) === 0 ) return $s;
            }
        }

        // 2. Global default.
        $setting = get_option( 'wab_schema_type', 'auto' );
        if ( $setting !== 'auto' && in_array( $setting, $supported, true ) ) {
            return $setting;
        }

        // 3. Infer. Posts are editorial; pages with business signals are commercial.
        if ( $post->post_type === 'post' ) {
            return 'BlogPosting';
        }

        $has_phone    = ! empty( $row->phone );
        $has_location = ! empty( $row->location );
        $has_company  = ! empty( $row->company );

        if ( $has_phone && ( $has_location || $has_company ) ) return 'LocalBusiness';
        if ( $has_company || $has_location )                   return 'Service';

        return 'WebPage';
    }

    /**
     * Assemble the JSON-LD graph.
     */
    private static function build( $type, $post, $row, array $content ) {
        $url       = get_permalink( $post->ID );
        $title     = get_the_title( $post->ID );
        $image     = get_the_post_thumbnail_url( $post->ID, 'full' );
        $site_name = get_bloginfo( 'name' );

        // 'meta' is the model's short output key (see WAB_Prompt_Builder); the
        // longer aliases cover normalised payloads and single-page generation.
        $description = '';
        foreach ( array( 'meta', 'meta_description', 'excerpt' ) as $k ) {
            if ( ! empty( $content[ $k ] ) ) { $description = $content[ $k ]; break; }
        }
        if ( $description === '' ) {
            $description = get_the_excerpt( $post->ID );
        }

        $node = array(
            '@context' => 'https://schema.org',
            '@type'    => $type,
            '@id'      => $url . '#' . strtolower( $type ),
            'url'      => $url,
            'name'     => $title,
        );

        if ( $description !== '' ) {
            $node['description'] = wp_strip_all_tags( $description );
        }
        if ( $image ) {
            $node['image'] = array(
                '@type' => 'ImageObject',
                'url'   => $image,
            );
        }

        switch ( $type ) {
            case 'LocalBusiness':
                $node = self::decorate_local_business( $node, $row );
                break;

            case 'Service':
                $node['serviceType'] = $row->services ?? $row->topic ?? $title;
                $provider = array( '@type' => 'Organization', 'name' => $row->company ?: $site_name );
                if ( ! empty( $row->phone ) ) $provider['telephone'] = self::clean_phone( $row->phone );
                $node['provider'] = $provider;
                if ( ! empty( $row->location ) ) {
                    $node['areaServed'] = array( '@type' => 'Place', 'name' => $row->location );
                }
                break;

            case 'Product':
                $node['brand'] = array( '@type' => 'Brand', 'name' => $row->company ?: $site_name );
                if ( ! empty( $row->price ) ) {
                    $node['offers'] = array(
                        '@type'         => 'Offer',
                        'price'         => (string) preg_replace( '/[^0-9.]/', '', $row->price ),
                        'priceCurrency' => get_option( 'wab_schema_currency', 'AED' ),
                        'availability'  => 'https://schema.org/InStock',
                        'url'           => $url,
                    );
                }
                break;

            case 'Article':
            case 'BlogPosting':
                $node['headline']      = mb_substr( $title, 0, 110 ); // Google truncates >110
                $node['datePublished'] = get_the_date( 'c', $post->ID );
                $node['dateModified']  = get_the_modified_date( 'c', $post->ID );
                $node['author']        = array(
                    '@type' => 'Person',
                    'name'  => get_the_author_meta( 'display_name', $post->post_author ) ?: $site_name,
                );
                $node['publisher']     = self::publisher_node();
                $node['mainEntityOfPage'] = array( '@type' => 'WebPage', '@id' => $url );
                break;

            case 'FAQPage':
                $faqs = self::extract_faqs( $content, $post );
                if ( empty( $faqs ) ) {
                    // No Q&A pairs found — FAQPage without mainEntity is invalid markup.
                    $node['@type'] = 'WebPage';
                    break;
                }
                $node['mainEntity'] = $faqs;
                break;

            case 'WebPage':
            default:
                $node['isPartOf'] = array(
                    '@type' => 'WebSite',
                    'name'  => $site_name,
                    'url'   => home_url( '/' ),
                );
                break;
        }

        // Breadcrumbs are free and improve SERP presentation.
        $graph = array( $node );
        $crumbs = self::breadcrumb_node( $post );
        if ( $crumbs ) $graph[] = $crumbs;

        $result = count( $graph ) > 1
            ? array( '@context' => 'https://schema.org', '@graph' => $graph )
            : $node;

        return self::sanitize_graph( $result );
    }

    private static function decorate_local_business( array $node, $row ) {
        $node['name'] = $row->company ?: $node['name'];

        if ( ! empty( $row->phone ) ) {
            $node['telephone'] = self::clean_phone( $row->phone );
        }

        $address = array( '@type' => 'PostalAddress' );
        if ( ! empty( $row->location ) )  $address['addressLocality'] = $row->location;
        if ( ! empty( $row->region ) )    $address['addressRegion']   = $row->region;
        if ( ! empty( $row->postcode ) )  $address['postalCode']      = $row->postcode;
        if ( ! empty( $row->street ) )    $address['streetAddress']   = $row->street;

        $country = get_option( 'wab_schema_country', '' );
        if ( $country !== '' ) $address['addressCountry'] = $country;

        if ( count( $address ) > 1 ) $node['address'] = $address;

        if ( ! empty( $row->location ) ) {
            $node['areaServed'] = array( '@type' => 'Place', 'name' => $row->location );
        }

        $hours = get_option( 'wab_schema_opening_hours', '' );
        if ( $hours !== '' ) {
            $node['openingHours'] = array_values( array_filter( array_map( 'trim', explode( ',', $hours ) ) ) );
        }

        return $node;
    }

    private static function publisher_node() {
        $publisher = array(
            '@type' => 'Organization',
            'name'  => get_bloginfo( 'name' ),
            'url'   => home_url( '/' ),
        );

        $logo_id = (int) get_option( 'site_logo', 0 );
        if ( ! $logo_id && function_exists( 'get_theme_mod' ) ) {
            $logo_id = (int) get_theme_mod( 'custom_logo', 0 );
        }
        if ( $logo_id ) {
            $logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
            if ( $logo_url ) {
                $publisher['logo'] = array( '@type' => 'ImageObject', 'url' => $logo_url );
            }
        }

        return $publisher;
    }

    private static function breadcrumb_node( $post ) {
        $items = array(
            array(
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => __( 'Home', 'wonder-ai-builder' ),
                'item'     => home_url( '/' ),
            ),
        );

        $position = 2;

        // Walk real page ancestors rather than guessing.
        $ancestors = array_reverse( get_post_ancestors( $post->ID ) );
        foreach ( $ancestors as $ancestor_id ) {
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => get_the_title( $ancestor_id ),
                'item'     => get_permalink( $ancestor_id ),
            );
        }

        if ( $post->post_type === 'post' ) {
            $cats = get_the_category( $post->ID );
            if ( ! empty( $cats ) ) {
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $position++,
                    'name'     => $cats[0]->name,
                    'item'     => get_category_link( $cats[0]->term_id ),
                );
            }
        }

        $items[] = array(
            '@type'    => 'ListItem',
            'position' => $position,
            'name'     => get_the_title( $post->ID ),
            'item'     => get_permalink( $post->ID ),
        );

        if ( count( $items ) < 2 ) return null;

        return array(
            '@type'           => 'BreadcrumbList',
            '@id'             => get_permalink( $post->ID ) . '#breadcrumb',
            'itemListElement' => $items,
        );
    }

    /**
     * Pull Q&A pairs out of the generated payload, or out of the rendered HTML
     * as a fallback. Still no API call.
     */
    private static function extract_faqs( array $content, $post ) {
        $out = array();

        // Preferred: the generator returned a structured faq array.
        if ( ! empty( $content['faq'] ) && is_array( $content['faq'] ) ) {
            foreach ( $content['faq'] as $pair ) {
                if ( ! is_array( $pair ) ) continue;

                // The model's output schema uses short keys (q/a) to save output
                // tokens — see WAB_Prompt_Builder::output_schema(). Reading only
                // question/answer meant every pair was rejected, the FAQ array was
                // billed and then discarded, and @type silently downgraded to
                // WebPage. Accept both spellings.
                $q = trim( (string) ( $pair['q'] ?? $pair['question'] ?? '' ) );
                $a = trim( (string) ( $pair['a'] ?? $pair['answer'] ?? '' ) );

                if ( $q === '' || $a === '' ) continue;
                $out[] = self::faq_item( $q, $a );
            }
        }

        // Fallback: parse <h3>Question?</h3><p>Answer</p> out of the body.
        if ( empty( $out ) ) {
            $html = $post->post_content;
            if ( preg_match_all( '#<h[23][^>]*>(.{5,200}\?)</h[23]>\s*(.*?)(?=<h[23]|$)#is', $html, $m, PREG_SET_ORDER ) ) {
                foreach ( $m as $set ) {
                    $q = wp_strip_all_tags( $set[1] );
                    $a = wp_strip_all_tags( $set[2] );
                    if ( $q === '' || strlen( $a ) < 20 ) continue;
                    $out[] = self::faq_item( $q, $a );
                    if ( count( $out ) >= 10 ) break;
                }
            }
        }

        return $out;
    }

    private static function faq_item( $question, $answer ) {
        return array(
            '@type'          => 'Question',
            'name'           => wp_strip_all_tags( $question ),
            'acceptedAnswer' => array(
                '@type' => 'Answer',
                'text'  => wp_strip_all_tags( $answer ),
            ),
        );
    }

    private static function clean_phone( $phone ) {
        // Keep digits and a leading +, which is what schema.org expects.
        $phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
        return $phone;
    }

    /**
     * Strip anything that could break out of the <script> block.
     *
     * JSON-LD is injected inside <script type="application/ld+json">. A literal
     * "</script>" in any string value would terminate the block early and turn
     * structured data into an XSS vector, so every string is scrubbed.
     */
    private static function sanitize_graph( $node ) {
        if ( is_string( $node ) ) {
            $node = str_ireplace( array( '</script', '<script', '<!--', '-->' ), '', $node );
            return wp_strip_all_tags( $node );
        }
        if ( is_array( $node ) ) {
            $clean = array();
            foreach ( $node as $k => $v ) {
                $key = is_string( $k )
                    ? preg_replace( '/[^A-Za-z0-9@_:.\-]/', '', $k )
                    : $k;
                $clean[ $key ] = self::sanitize_graph( $v );
            }
            return $clean;
        }
        if ( is_bool( $node ) || is_int( $node ) || is_float( $node ) || is_null( $node ) ) {
            return $node;
        }
        return '';
    }

    private static function store( $post_id, array $graph, $source ) {
        // wp_slash because update_post_meta runs stripslashes internally, and
        // JSON escaping uses backslashes we must preserve.
        update_post_meta( $post_id, self::META_KEY, wp_slash( wp_json_encode( $graph ) ) );
        update_post_meta( $post_id, self::META_SOURCE, $source );
    }

    // -------------------------------------------------------------------
    // Front-end output
    // -------------------------------------------------------------------

    public static function register_output() {
        add_action( 'wp_head', array( __CLASS__, 'print_schema' ), 20 );
    }

    public static function print_schema() {
        if ( ! is_singular() ) return;

        $post_id = get_queried_object_id();
        if ( ! $post_id ) return;

        $json = get_post_meta( $post_id, self::META_KEY, true );
        if ( empty( $json ) ) return;

        // Re-decode and re-encode so malformed stored data can never reach the page.
        $decoded = json_decode( $json, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) return;

        $safe = wp_json_encode(
            self::sanitize_graph( $decoded ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ( $safe === false ) return;

        echo "\n<!-- Wonder AI Builder: schema generated in PHP, no API cost -->\n";
        echo '<script type="application/ld+json">' . $safe . "</script>\n";
    }
}
