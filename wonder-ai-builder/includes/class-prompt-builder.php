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

    /**
     * Length presets.
     *
     * `words` is the TARGET; `min` is enforced in the prompt as a hard floor.
     *
     * WHY A FLOOR AND NOT JUST A TARGET
     * ---------------------------------
     * Measured against the live API: asking for "~450 words" produced 303 — a 33%
     * undershoot. Language models treat an approximate target as an upper bound and
     * stop as soon as the topic feels covered. Stating an explicit minimum, plus a
     * per-section word budget, is what actually moves the output length.
     *
     * Any preset can be overridden per import, or per row via a Words column.
     */
    public static function modes() {
        return array(
            self::MODE_TEMPLATE => array(
                'label'    => __( 'Short — intro only (~250 words)', 'wonder-ai-builder' ),
                'words'    => 250,
                'min'      => 200,
                'sections' => 2,
                'notes'    => __( 'Brief local intro. For very large location sets where volume matters more than depth.', 'wonder-ai-builder' ),
            ),
            self::MODE_HYBRID => array(
                'label'    => __( 'Standard — balanced (~700 words)', 'wonder-ai-builder' ),
                'words'    => 700,
                'min'      => 550,
                'sections' => 4,
                'notes'    => __( 'Recommended default. Enough depth to rank, cheap enough for bulk runs.', 'wonder-ai-builder' ),
            ),
            self::MODE_FULL => array(
                'label'    => __( 'Long — in-depth (~1,300 words)', 'wonder-ai-builder' ),
                'words'    => 1300,
                'min'      => 1100,
                'sections' => 6,
                'notes'    => __( 'Competitive terms and money pages where you need to out-cover rivals.', 'wonder-ai-builder' ),
            ),
            self::MODE_PILLAR => array(
                'label'    => __( 'Pillar — comprehensive (~2,200 words)', 'wonder-ai-builder' ),
                'words'    => 2200,
                'min'      => 1900,
                'sections' => 9,
                'notes'    => __( 'Authority hub that thinner pages link up to. Slowest and dearest per page.', 'wonder-ai-builder' ),
            ),
        );
    }

    /**
     * Resolve the length for one row.
     *
     * Precedence, most specific first:
     *   1. The row's own Words column  — per-page control from the sheet
     *   2. The import's target         — chosen at import time
     *   3. The preset for the mode     — the fallback
     *
     * Clamped to 120-4000: below ~120 there is not enough to rank, and above ~4000 a
     * single reply risks the output ceiling even with a generous budget.
     */
    public static function resolve_length( $mode, $row_words = 0, $import_words = 0 ) {
        $modes  = self::modes();
        $preset = $modes[ $mode ] ?? $modes[ self::MODE_HYBRID ];

        $target = (int) $preset['words'];

        if ( (int) $import_words > 0 ) $target = (int) $import_words;
        if ( (int) $row_words > 0 )    $target = (int) $row_words;

        $target = max( 120, min( 4000, $target ) );

        // Sections scale with length: roughly one h2 per 165 words, 2-14.
        $sections = max( 2, min( 14, (int) round( $target / 165 ) ) );

        /**
         * LENGTH-DRIFT COMPENSATION, from measurement rather than guesswork.
         *
         * Live results with an enforced minimum:
         *     target   700 -> produced  703  (100%)
         *     target 1,500 -> produced 1,216 ( 81%)
         *
         * Short pieces land on target; long ones plateau, because the model runs out
         * of things it considers worth saying before it runs out of budget. The drift
         * scales with length, so the figure QUOTED IN THE PROMPT is inflated
         * proportionally — ask for more and the natural shortfall lands on target.
         *
         * Compensation is 1.0 up to 700 words, rising to a 1.30 cap by ~2,600. It is
         * applied ONLY to what the prompt requests. `min` stays honest, because that
         * is what validation and the UI use — inflating that would just move the lie.
         */
        $inflation = 1.0;
        if ( $target > 700 ) {
            $inflation = min( 1.30, 1.0 + ( ( $target - 700 ) / 700 ) * 0.16 );
        }

        $prompt_target = (int) round( $target * $inflation );
        $prompt_min    = (int) round( $target * $inflation * 0.92 );

        return array(
            'target'        => $target,
            'min'           => (int) round( $target * 0.85 ),   // honest floor: validation + UI
            'max'           => (int) round( $target * 1.30 ),
            'sections'      => $sections,
            'per_sec'       => max( 70, (int) round( $prompt_target / max( 1, $sections ) ) ),
            'prompt_target' => $prompt_target,                  // inflated: prompt only
            'prompt_min'    => $prompt_min,
        );
    }

    /**
     * THE one place that answers "how long should this page be?".
     *
     * Precedence, most specific first:
     *   1. The row's Words column       — per-page control from the sheet
     *   2. The sheet's exact word count — chosen on the Import screen
     *   3. Settings -> Default word count — site-wide
     *   4. 0, meaning "use the depth preset"
     *
     * WHY THIS IS A FUNCTION AND NOT `?? get_option(...)` AT EACH CALL SITE
     * --------------------------------------------------------------------
     * Because that idiom was silently broken. wab_imports.target_words is
     * `int(11) NOT NULL default 0`, so an unset value reads back as 0, NOT null —
     * and `??` only falls back on null. Every call site therefore resolved to 0 and
     * the site-wide "Default word count" setting was dead on arrival: it saved, it
     * displayed, and it changed nothing, while its own hint promised it "applies
     * site-wide unless a sheet or row overrides it".
     *
     * Worse, the three call sites disagreed. The prompt used the option as a fallback
     * (had it worked) but the TOKEN BUDGET never did — so a large value in Settings
     * would have asked for a 3,000-word page while sizing the reply ceiling for a
     * 700-word one, and truncated mid-JSON. That is the same class of mismatch that
     * truncated Pillar depth. One resolver, used everywhere, or the numbers drift.
     *
     * @param object|null $row    Row from wab_rows.
     * @param object|null $import Row from wab_imports.
     * @return int Target word count, or 0 to use the depth preset.
     */
    public static function target_words_for( $row = null, $import = null ) {
        $row_words = (int) ( is_object( $row ) && isset( $row->word_count ) ? $row->word_count : 0 );
        if ( $row_words > 0 ) return $row_words;

        $sheet_words = (int) ( is_object( $import ) && isset( $import->target_words ) ? $import->target_words : 0 );
        if ( $sheet_words > 0 ) return $sheet_words;

        return max( 0, (int) get_option( 'wab_target_words', 0 ) );
    }

    /**
     * Resolve the depth mode for a job.
     *
     * Same 0-versus-null trap as target_words_for(): content_mode is
     * `NOT NULL default 'hybrid'`, so test for a usable value rather than for null,
     * and reject anything that is not a real preset key.
     */
    public static function mode_for( $import = null ) {
        $mode = is_object( $import ) && ! empty( $import->content_mode )
            ? (string) $import->content_mode
            : (string) get_option( 'wab_content_mode', self::MODE_HYBRID );

        return isset( self::modes()[ $mode ] ) ? $mode : self::MODE_HYBRID;
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
        // No numbers here on purpose. Length is stated per row in the delta, because
        // it can differ row to row (Words column) and ANY variation in this prefix
        // would break the provider's prompt cache for the whole import.
        $lines[] = 'Lead with the reader\'s problem, not the company. Give every h2 section real substance: a concrete example, a number, a trade-off, or a step-by-step. One h3 subsection where it genuinely helps. Include one short ul of 3-5 specifics.';
        $lines[] = 'Never pad to reach a length. If a section needs more words, add a genuinely new point rather than restating an earlier one.';
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

        /**
         * LENGTH, stated as a floor with a per-section budget.
         *
         * "Write ~450 words" measurably produced 303 against the live API. Models read
         * an approximate figure as a ceiling and stop once the topic feels covered.
         * A hard minimum, a section count, and a per-section allowance give the model
         * an explicit budget to spend, which is what actually lands the length.
         */
        $len = self::resolve_length(
            $mode,
            (int) ( $opts['row_words'] ?? 0 ),
            (int) ( $opts['import_words'] ?? 0 )
        );

        // Quote the INFLATED figures — see the drift note in resolve_length().
        $parts[] = sprintf(
            'LENGTH: the content field must contain AT LEAST %1$d words of body copy (aim for %2$d). Write exactly %3$d h2 sections, each roughly %4$d words — no section may be a single short paragraph. Count only prose; HTML tags, headings and the FAQ do not count. Do not stop early: if you are short of %1$d words, add another substantive point with a concrete example, figure, or trade-off rather than restating anything.',
            (int) $len['prompt_min'],
            (int) $len['prompt_target'],
            (int) $len['sections'],
            (int) $len['per_sec']
        );

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
    /**
     * Token ceiling for one row.
     *
     * MUST be sized from the INFLATED prompt figure, not the honest target.
     * Getting this wrong produced a real failure: drift compensation asked Pillar for
     * 2,860 words while the budget was still computed from 2,200, so the reply hit
     * MAX_TOKENS and truncated mid-JSON. The number the model is told to write is the
     * number that has to be paid for.
     *
     * Thinking reserve scales too — it is not a flat constant. Longer, more complex
     * briefs think proportionally harder, and on Gemini 3.x thinking cannot be
     * disabled (verified: thinkingBudget 0 returns HTTP 400), so it must be budgeted.
     *
     * Being generous is free: providers bill tokens actually produced, not the
     * ceiling. Under-sizing costs a whole wasted generation; over-sizing costs zero.
     */
    public static function estimate_output_tokens( $mode, $want_faq = true, $target_words = 0 ) {
        $len = self::resolve_length( $mode, $target_words );

        // Upper bound of what the model was ASKED for, with headroom for overshoot.
        $words = (int) round( $len['prompt_target'] * 1.30 );

        // ~1.4 tokens per English word, plus HTML tag overhead.
        $visible = (int) ( $words * 1.4 * 1.25 );

        // JSON envelope, title/slug/meta/excerpt/kw.
        $visible += 260;
        if ( $want_faq ) $visible += 380;

        // Thinking is drawn from the same budget. Measured ~2,000 on a mid-length row
        // and it grows with the brief, so reserve the larger of a floor and 70%.
        $thinking = max( 2500, (int) round( $visible * 0.70 ) );

        return $visible + $thinking;
    }
}
