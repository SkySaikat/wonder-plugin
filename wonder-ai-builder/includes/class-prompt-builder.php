<?php
/**
 * Prompt construction, split into a CACHED prefix and a per-row DELTA.
 *
 * THE COST MODEL
 * --------------
 * v1 rebuilt one monolithic prompt per row (class-gemini.php:197-237). Every row
 * re-sent: the full persona, the site name/description/admin email, the entire
 * site-context page list (up to 50 titles + URLs from class-scanner.php:98-101),
 * and the complete instruction block. For a 100-row import that is the same
 * ~1,200 tokens of identical preamble billed 100 times.
 *
 * v2 splits the prompt in two:
 *
 *   PREFIX (stable) — persona + site facts + shared concept + structural rules.
 *                     Identical for every row in an import, so providers can
 *                     serve it from their prompt cache at a steep discount, and
 *                     it is byte-identical which is what cache hits require.
 *
 *   DELTA (per row) — only the fields that actually differ: location, service,
 *                     brief, word target. Roughly 40 tokens instead of 300.
 *
 * Cache hits require an EXACT prefix match, so the prefix must be built
 * deterministically: no timestamps, no random ordering, no per-row values. Any
 * variation silently drops the discount, which is why this is a separate class
 * with the prefix isolated from row data.
 *
 * ALSO REMOVED FROM v1's OUTPUT CONTRACT
 * --------------------------------------
 *   meta_keywords — Google has ignored the meta keywords tag since 2009. v1
 *                   required it (class-gemini.php:254) and wrote it to AIOSEO.
 *   schema_type   — required output, then discarded. Computed in PHP now.
 *   page_type     — already known from the sheet.
 *   category      — already known from the sheet.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Prompt_Builder {

    // Service-area generation strategies, cheapest first.
    const MODE_TEMPLATE = 'template';   // A: intro only, ~150 words
    const MODE_HYBRID   = 'hybrid';     // B: sectional, ~450 words  (default)
    const MODE_FULL     = 'full';       // C: entire body, ~900 words
    const MODE_PILLAR   = 'pillar';     // D: long-form parent, ~1800 words

    public static function modes() {
        return array(
            self::MODE_TEMPLATE => array(
                'label'      => __( 'A — Template + local intro (cheapest)', 'wonder-ai-builder' ),
                'words'      => 150,
                'sections'   => 1,
                'notes'      => __( 'PHP template supplies structure; AI writes only the opening and one local paragraph. For 500+ low-competition locations.', 'wonder-ai-builder' ),
            ),
            self::MODE_HYBRID => array(
                'label'      => __( 'B — Hybrid sectional (recommended)', 'wonder-ai-builder' ),
                'words'      => 450,
                'sections'   => 3,
                'notes'      => __( 'AI writes the genuinely location-specific sections; shared boilerplate is reused from PHP. Best cost-to-quality balance.', 'wonder-ai-builder' ),
            ),
            self::MODE_FULL => array(
                'label'      => __( 'C — Full unique body', 'wonder-ai-builder' ),
                'words'      => 900,
                'sections'   => 6,
                'notes'      => __( 'Entire body written per page. Use for competitive metros and money pages.', 'wonder-ai-builder' ),
            ),
            self::MODE_PILLAR => array(
                'label'      => __( 'D — Pillar / cluster parent', 'wonder-ai-builder' ),
                'words'      => 1800,
                'sections'   => 9,
                'notes'      => __( 'Long-form authority page intended as the hub that thinner location pages link up to.', 'wonder-ai-builder' ),
            ),
        );
    }

    /**
     * Strict output contract. Enforced by the provider's structured-output
     * feature, not merely requested in prose.
     *
     * Field names are deliberately short: every key is billed as output tokens on
     * every row, so `meta` beats `meta_description` 20,000 times over. They stay
     * readable enough to debug.
     *
     * @param bool $want_faq Include the faq array (buys FAQPage rich results).
     */
    public static function output_schema( $want_faq = true ) {
        $props = array(
            'title'   => array(
                'type'        => 'string',
                'description' => 'SEO title, 50-60 chars. Front-load the primary keyword. No brand suffix.',
            ),
            'slug'    => array(
                'type'        => 'string',
                'description' => 'lowercase-hyphenated-slug, max 5 words, no stop words.',
            ),
            'content' => array(
                'type'        => 'string',
                'description' => 'Body HTML. h2/h3/p/ul/li/strong only. No h1, no inline styles, no images, no placeholders.',
            ),
            'meta'    => array(
                'type'        => 'string',
                'description' => 'Meta description, 140-158 chars, active voice, ends with a benefit or CTA.',
            ),
            'excerpt' => array(
                'type'        => 'string',
                'description' => 'Two-sentence summary for archive listings.',
            ),
            'kw'      => array(
                'type'        => 'string',
                'description' => 'Single focus keyword phrase.',
            ),
        );

        if ( $want_faq ) {
            $props['faq'] = array(
                'type'        => 'array',
                'description' => '3-4 questions a real customer would ask. Answers 40-60 words.',
                'items'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'q' => array( 'type' => 'string' ),
                        'a' => array( 'type' => 'string' ),
                    ),
                    'required'   => array( 'q', 'a' ),
                ),
            );
        }

        $required = array( 'title', 'slug', 'content', 'meta', 'excerpt', 'kw' );
        if ( $want_faq ) $required[] = 'faq';

        return array(
            'type'       => 'object',
            'properties' => $props,
            'required'   => $required,
        );
    }

    /**
     * The CACHED PREFIX — identical for every row in an import.
     *
     * Must be byte-stable. Do not interpolate row data, timestamps, or anything
     * that varies, or the provider cache will miss and the discount evaporates.
     *
     * @param array $concept Shared concept for this import (see WAB_Concept).
     * @param array $site    Stable site facts.
     * @param array $opts    mode, want_faq.
     */
    public static function build_prefix( array $concept, array $site, array $opts = array() ) {
        $mode  = isset( $opts['mode'] ) ? $opts['mode'] : self::MODE_HYBRID;
        $modes = self::modes();
        $spec  = $modes[ $mode ] ?? $modes[ self::MODE_HYBRID ];

        $site_name = $site['name'] ?? '';
        $site_desc = $site['description'] ?? '';
        $industry  = $concept['industry'] ?? '';
        $audience  = $concept['audience'] ?? '';
        $tone      = $concept['tone'] ?? 'professional, direct, no filler';
        $usps      = ! empty( $concept['usps'] ) ? implode( '; ', (array) $concept['usps'] ) : '';
        $avoid     = ! empty( $concept['avoid'] ) ? implode( '; ', (array) $concept['avoid'] ) : '';

        // Terse on purpose. Every token here is billed once per import when cached,
        // but on a cache miss it is billed per row — so brevity is insurance.
        $lines = array(
            'You write conversion-focused web copy for a real business. Output only the JSON fields defined by the schema.',
            '',
            'BUSINESS',
            "Site: {$site_name}" . ( $site_desc !== '' ? " — {$site_desc}" : '' ),
        );

        if ( $industry !== '' ) $lines[] = "Industry: {$industry}";
        if ( $audience !== '' ) $lines[] = "Audience: {$audience}";
        if ( $usps !== '' )     $lines[] = "Differentiators: {$usps}";

        $lines[] = '';
        $lines[] = 'VOICE';
        $lines[] = $tone;
        $lines[] = 'Write like a knowledgeable human. No AI throat-clearing ("In today\'s world", "When it comes to"). No em-dash-heavy padding. Never invent prices, awards, certifications, review counts, or years in business.';

        $lines[] = '';
        $lines[] = 'STRUCTURE';
        $lines[] = sprintf(
            'Target %d words across roughly %d h2 sections. Lead with the reader\'s problem, not the company. One h3 subsection where it genuinely helps. Include one short ul of 3-5 concrete specifics.',
            (int) $spec['words'],
            (int) $spec['sections']
        );
        $lines[] = 'Never output an h1 — the theme renders the title. No inline style attributes. No image tags or placeholders.';

        $lines[] = '';
        $lines[] = 'SEO';
        $lines[] = 'Use the focus keyword in the title, first 100 words, and one h2 — naturally, never forced. Vary phrasing with related terms rather than repeating the exact keyword. Write for the searcher\'s intent, not keyword density.';

        if ( $avoid !== '' ) {
            $lines[] = '';
            $lines[] = "AVOID: {$avoid}";
        }

        return implode( "\n", $lines );
    }

    /**
     * The PER-ROW DELTA — only what differs. Keep this tiny.
     *
     * @param object $row      Import row.
     * @param array  $opts     mode, rotation_seed, sibling_locations.
     */
    public static function build_delta( $row, array $opts = array() ) {
        $mode  = isset( $opts['mode'] ) ? $opts['mode'] : self::MODE_HYBRID;
        $modes = self::modes();
        $spec  = $modes[ $mode ] ?? $modes[ self::MODE_HYBRID ];

        $parts = array();

        $service  = self::field( $row, 'services' ) ?: self::field( $row, 'topic' );
        $location = self::field( $row, 'location' );
        $title    = self::field( $row, 'title' );
        $brief    = self::field( $row, 'description' );
        $company  = self::field( $row, 'company' );
        $phone    = self::field( $row, 'phone' );

        if ( $service !== '' )  $parts[] = "Service: {$service}";
        if ( $location !== '' ) $parts[] = "Location: {$location}";
        if ( $title !== '' )    $parts[] = "Working title: {$title}";
        if ( $company !== '' )  $parts[] = "Business name: {$company}";
        if ( $phone !== '' )    $parts[] = "Phone (include once as a CTA): {$phone}";
        if ( $brief !== '' )    $parts[] = 'Brief: ' . mb_substr( $brief, 0, 400 );

        // Structural rotation. This is what stops 100 location pages fingerprinting
        // as a doorway farm — and it costs nothing because it is computed here,
        // not asked of the model.
        if ( $location !== '' && $mode !== self::MODE_TEMPLATE ) {
            $angle = self::rotation_angle(
                $opts['row_index'] ?? null,
                $opts['rotation_seed'] ?? $location
            );
            $parts[] = "Open on this angle: {$angle}";
        }

        // Sibling locations enable genuine internal linking without sending the
        // entire site map on every row the way v1 did.
        if ( ! empty( $opts['sibling_locations'] ) ) {
            $siblings = array_slice( (array) $opts['sibling_locations'], 0, 4 );
            $parts[]  = 'Nearby areas you may reference once: ' . implode( ', ', $siblings );
        }

        if ( ! empty( $opts['internal_links'] ) ) {
            $links = array();
            foreach ( array_slice( (array) $opts['internal_links'], 0, 3 ) as $l ) {
                // Guarded: an element missing either key would otherwise emit two
                // E_WARNINGs and interpolate an empty string into the prompt.
                $l_title = is_array( $l ) ? trim( (string) ( $l['title'] ?? '' ) ) : '';
                $l_url   = is_array( $l ) ? trim( (string) ( $l['url'] ?? '' ) )   : '';
                if ( $l_title === '' || $l_url === '' ) continue;
                $links[] = sprintf( '%s (%s)', $l_title, $l_url );
            }
            if ( ! empty( $links ) ) {
                $parts[] = 'Link naturally to 1-2 of: ' . implode( ' | ', $links );
            }
        }

        $parts[] = sprintf( 'Write ~%d words.', (int) $spec['words'] );

        return implode( "\n", $parts );
    }

    /**
     * Deterministic opening-angle rotation.
     *
     * Uses the row index for strict round-robin, which guarantees even coverage
     * across an import while staying idempotent (row_index never changes, so
     * regenerating a row reproduces its angle).
     *
     * A hash of the location was tried first and rejected: crc32() modulo a small
     * bucket count clusters badly at realistic sample sizes. On an 8-suburb Dubai
     * set, four locations collided on the same angle — which would have produced
     * exactly the near-duplicate openings this rotation exists to prevent. The
     * hash path is retained only as a fallback for rows generated outside an
     * import (single-page "Generate now"), where no index exists.
     *
     * @param int|null   $index Row index within the import, if known.
     * @param string     $seed  Fallback seed.
     */
    private static function rotation_angle( $index = null, $seed = '' ) {
        $angles = array(
            'the specific problem people in this area search for',
            'what makes this area different to serve, practically',
            'the cost/timeline question customers ask first',
            'a common mistake people make when choosing this service',
            'what happens if the problem is left too long',
            'how the process actually works, step by step',
            'how to tell a good provider from a bad one here',
        );

        $count = count( $angles );

        if ( $index !== null && $index !== '' && is_numeric( $index ) ) {
            return $angles[ ( (int) $index ) % $count ];
        }

        return $angles[ abs( crc32( (string) $seed ) ) % $count ];
    }

    private static function field( $row, $key ) {
        if ( is_object( $row ) ) {
            return isset( $row->$key ) ? trim( (string) $row->$key ) : '';
        }
        if ( is_array( $row ) ) {
            return isset( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
        }
        return '';
    }

    /**
     * Estimated output tokens, used for pre-flight budget checks so we can refuse
     * a 100-row run that would blow the daily cap instead of discovering it at row 63.
     */
    public static function estimate_output_tokens( $mode, $want_faq = true ) {
        $modes = self::modes();
        $spec  = $modes[ $mode ] ?? $modes[ self::MODE_HYBRID ];

        // ~1.4 tokens per English word, plus HTML tag overhead, plus JSON envelope.
        $tokens = (int) ( $spec['words'] * 1.4 * 1.25 ) + 120;
        if ( $want_faq ) $tokens += 260;

        return $tokens;
    }
}
