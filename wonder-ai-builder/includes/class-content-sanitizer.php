<?php
/**
 * Treat model output as untrusted input.
 *
 * THE v1 VULNERABILITY
 * --------------------
 * ai-page-builder/includes/class-page-generator.php:295
 * ai-post-builder/includes/class-post-generator.php:344
 *
 *     'post_content' => $content_html,   // raw model output, no filtering
 *
 * Grepping the v1 tree for `kses` returns nothing. Every other field was
 * sanitized — wp_strip_all_tags on the title, sanitize_text_field on excerpt and
 * meta — but content, the one field rendered to the public, was not.
 *
 * WHY THAT IS REACHABLE, NOT THEORETICAL
 * -------------------------------------
 * wp_insert_post only applies kses filters for users LACKING `unfiltered_html`.
 * Administrators have it. So when an admin clicked Generate, whatever the model
 * returned was stored verbatim.
 *
 * Exploit chain (Author -> stored XSS -> admin session):
 *   1. Scanner feeds published post TITLES into the prompt
 *      (v1 class-scanner.php:94-101).
 *   2. An Author publishes a post whose title carries a prompt-injection payload.
 *      Authors can publish their own posts; the scanner filters only on
 *      post_status = 'publish'.
 *   3. Admin generates pages; poisoned context steers the model into emitting
 *      <script>.
 *   4. Payload stored raw in post_content and served to every visitor.
 *
 * Notably the v1 *front-end* code got this right — admin.js escaped the model's
 * error text through App.esc() before rendering it. The PHP side did not apply the
 * same distrust to the model's content output.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Content_Sanitizer {

    /**
     * Clean generated HTML for storage in post_content.
     *
     * @param string $html Raw model output.
     * @return string
     */
    public static function clean_post_html( $html ) {
        $html = (string) $html;
        if ( trim( $html ) === '' ) return '';

        // 1. Strip markdown fences. Models emit ```html wrappers even when told
        //    not to, and v1 only stripped them from the JSON envelope
        //    (class-gemini.php:261-262), not from the content field itself.
        $html = self::strip_code_fences( $html );

        // 2. Remove executable and layout-hijacking constructs outright, before
        //    kses, so nothing survives via encoding tricks.
        $html = self::strip_dangerous_blocks( $html );

        // 3. Run kses with an explicit allowlist. Applied unconditionally —
        //    never dependent on the current user's unfiltered_html capability.
        $html = wp_kses( $html, self::allowed_html() );

        // 4. Tidy whitespace so the block editor does not show ragged markup.
        $html = preg_replace( '/\n{3,}/', "\n\n", $html );

        return trim( $html );
    }

    /**
     * Close a truncated JSON document by walking it once and tracking open
     * delimiters on a stack.
     *
     * Counting braces and brackets independently is NOT sufficient: appending all
     * the ']' then all the '}' produces the wrong nesting whenever an object is open
     * inside an array. A reply cut off inside a FAQ entry —
     *
     *     {"title":"A","faq":[{"q":"Why?","a":"Because
     *
     * needs "  }  ]  }  in that exact order. Counting yields " ] }} and stays invalid.
     * A stack closes in reverse order of opening, which is the only correct rule.
     */
    private static function close_truncated_json( $s ) {
        $stack     = array();
        $in_string = false;
        $escaped   = false;
        $len       = strlen( $s );

        for ( $i = 0; $i < $len; $i++ ) {
            $c = $s[ $i ];

            if ( $escaped ) { $escaped = false; continue; }
            if ( $c === '\\' ) { $escaped = true; continue; }

            if ( $c === '"' ) { $in_string = ! $in_string; continue; }
            if ( $in_string )  { continue; }

            if ( $c === '{' || $c === '[' ) {
                $stack[] = $c;
            } elseif ( $c === '}' || $c === ']' ) {
                array_pop( $stack );
            }
        }

        $out = rtrim( $s );

        // Close an unterminated string first.
        if ( $in_string ) $out .= '"';

        // Drop a dangling separator or a key with no value, e.g. '..., "a":'
        $out = preg_replace( '/,\s*$/', '', $out );
        $out = preg_replace( '/,?\s*"[^"]*"\s*:\s*$/', '', $out );

        // Then close every open container, innermost first.
        while ( ! empty( $stack ) ) {
            $out .= ( array_pop( $stack ) === '{' ) ? '}' : ']';
        }

        return $out;
    }

    /**
     * Decode a model reply into an array, repairing the failure modes that actually
     * occur in practice.
     *
     * Structured-output modes are strict but not infallible: replies still arrive
     * wrapped in prose, fenced in markdown, or truncated mid-object when the token
     * budget runs out. A plain json_decode() throws all of that away and reports only
     * "syntax error", which is the least useful thing it could say.
     *
     * Four passes, cheapest first. Nothing here invents content — a repair either
     * yields the fields the model already produced, or fails.
     *
     * @return array|null
     */
    public static function decode_json( $text ) {
        $text = trim( (string) $text );
        if ( $text === '' ) return null;

        // 1. As-is.
        $data = json_decode( $text, true );
        if ( is_array( $data ) ) return $data;

        // 2. Strip markdown fences.
        $stripped = self::strip_code_fences( $text );
        $data = json_decode( $stripped, true );
        if ( is_array( $data ) ) return $data;

        // 3. Extract the outermost {...}, discarding any prose either side.
        $first = strpos( $stripped, '{' );
        $last  = strrpos( $stripped, '}' );
        if ( $first !== false && $last !== false && $last > $first ) {
            $candidate = substr( $stripped, $first, $last - $first + 1 );
            $data = json_decode( $candidate, true );
            if ( is_array( $data ) ) return $data;
        }

        // 4. Truncated reply: close what is open.
        //
        // A cut-off object is the common case when the output ceiling is hit. If the
        // text ends inside a string, close it, then close every unbalanced brace and
        // bracket. Whatever fields completed are recovered; the trailing partial one is
        // dropped. Better than discarding a whole billed generation.
        if ( $first !== false ) {
            $candidate = self::close_truncated_json( substr( $stripped, $first ) );

            $data = json_decode( $candidate, true );
            if ( is_array( $data ) ) {
                WAB_Logger::warn( 'Recovered a truncated model reply by closing unbalanced JSON. Consider a lower Content depth.' );
                return $data;
            }
        }

        return null;
    }

    /**
     * Remove ```html ... ``` and ``` ... ``` wrappers.
     */
    public static function strip_code_fences( $text ) {
        $text = trim( (string) $text );

        // Leading fence with optional language hint.
        $text = preg_replace( '/^\s*```[a-zA-Z0-9]*\s*\r?\n?/', '', $text );
        // Trailing fence.
        $text = preg_replace( '/\r?\n?\s*```\s*$/', '', $text );

        return trim( $text );
    }

    /**
     * Excise constructs kses would allow through in attribute values or that
     * break page layout.
     */
    private static function strip_dangerous_blocks( $html ) {
        $patterns = array(
            // Script / style / iframe / object / embed / form blocks entirely.
            '#<\s*(script|style|iframe|object|embed|applet|form|noscript|template|svg|math)\b[^>]*>.*?<\s*/\s*\1\s*>#is',
            // Self-closing or unclosed variants of the same tags.
            '#<\s*/?\s*(script|style|iframe|object|embed|applet|form|noscript|template|svg|math)\b[^>]*>#i',
            // <base>, <meta>, <link> can hijack relative URLs or inject CSP bypasses.
            '#<\s*/?\s*(base|meta|link)\b[^>]*>#i',
            // HTML comments — can hide conditional-comment payloads.
            '#<!--.*?-->#s',
            // PHP or ASP tags if a model ever emits them.
            '#<\?(?:php)?.*?\?>#is',
        );

        foreach ( $patterns as $p ) {
            $html = preg_replace( $p, '', $html );
        }

        // Neutralise javascript:/vbscript:/data: URLs before kses sees them.
        $html = preg_replace( '#(href|src|action|formaction)\s*=\s*(["\']?)\s*(?:javascript|vbscript|livescript|data|file)\s*:#i', '$1=$2#', $html );

        // Strip every inline event handler (on*=), quoted or bare.
        $html = preg_replace( '#\son[a-z]+\s*=\s*"[^"]*"#i', '', $html );
        $html = preg_replace( "#\son[a-z]+\s*=\s*'[^']*'#i", '', $html );
        $html = preg_replace( '#\son[a-z]+\s*=\s*[^\s>]+#i', '', $html );

        return $html;
    }

    /**
     * Allowlist for generated article bodies.
     *
     * Deliberately narrower than wp_kses_post(): generated content has no
     * legitimate need for form controls, media embeds, or style attributes.
     */
    public static function allowed_html() {
        $inline = array(
            'class' => true,
            'id'    => true,
        );

        return array(
            'p'          => $inline,
            'br'         => array(),
            'hr'         => array(),
            'strong'     => $inline,
            'b'          => $inline,
            'em'         => $inline,
            'i'          => $inline,
            'u'          => $inline,
            's'          => $inline,
            'mark'       => $inline,
            'small'      => $inline,
            'sub'        => $inline,
            'sup'        => $inline,
            // h1 is deliberately NOT allowlisted. The theme renders the post title
            // as the page's h1, and the prompt forbids the model from emitting one.
            // Allowing it through would let a stray h1 create a duplicate-H1 SEO
            // problem on any page where the model ignored the instruction.
            'h2'         => $inline,
            'h3'         => $inline,
            'h4'         => $inline,
            'h5'         => $inline,
            'h6'         => $inline,
            'ul'         => $inline,
            'ol'         => array_merge( $inline, array( 'start' => true, 'type' => true ) ),
            'li'         => $inline,
            'dl'         => $inline,
            'dt'         => $inline,
            'dd'         => $inline,
            'blockquote' => array_merge( $inline, array( 'cite' => true ) ),
            'cite'       => $inline,
            'q'          => array_merge( $inline, array( 'cite' => true ) ),
            'code'       => $inline,
            'pre'        => $inline,
            'a'          => array_merge( $inline, array(
                'href'   => true,
                'title'  => true,
                'rel'    => true,
                'target' => true,
            ) ),
            'img'        => array_merge( $inline, array(
                'src'      => true,
                'alt'      => true,
                'width'    => true,
                'height'   => true,
                'loading'  => true,
                'decoding' => true,
            ) ),
            'figure'     => $inline,
            'figcaption' => $inline,
            'table'      => $inline,
            'thead'      => $inline,
            'tbody'      => $inline,
            'tfoot'      => $inline,
            'tr'         => $inline,
            'th'         => array_merge( $inline, array( 'scope' => true, 'colspan' => true, 'rowspan' => true ) ),
            'td'         => array_merge( $inline, array( 'colspan' => true, 'rowspan' => true ) ),
            'caption'    => $inline,
            'span'       => $inline,
            'div'        => $inline,
            'section'    => $inline,
            'article'    => $inline,
            'aside'      => $inline,
            'header'     => $inline,
            'footer'     => $inline,
            'nav'        => $inline,
            'abbr'       => array_merge( $inline, array( 'title' => true ) ),
            'time'       => array_merge( $inline, array( 'datetime' => true ) ),
        );
    }

    /**
     * Clean a plain-text field (title, excerpt, meta description).
     */
    public static function clean_text( $text, $max_length = 0 ) {
        $text = self::strip_code_fences( (string) $text );

        // ORDER MATTERS. Decoding entities AFTER stripping tags re-materialises
        // markup: a model emitting "&lt;script&gt;alert(1)&lt;/script&gt;" contains
        // no literal tags, so wp_strip_all_tags() passes it through untouched, and
        // decoding then turns it into a live <script> tag. wp_insert_post() only
        // kses-filters post_title for users LACKING unfiltered_html — which
        // administrators have — and the_title() echoes unescaped.
        // Decode first, then strip, then strip again to catch nested encodings.
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = wp_strip_all_tags( $text );
        $text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

        $text = preg_replace( '/\s+/u', ' ', $text );
        $text = trim( $text );

        if ( $max_length > 0 && mb_strlen( $text ) > $max_length ) {
            $text = rtrim( mb_substr( $text, 0, $max_length ) );
        }

        return $text;
    }

    /**
     * Enforce that generated content is substantive, so we do not publish
     * near-empty pages and do not retry things that produced nothing usable.
     *
     * @return true|WP_Error
     */
    public static function validate( $html, $min_words = 120 ) {
        $words = self::count_words( $html );
        if ( $words < $min_words ) {
            return new WP_Error(
                'wab_empty_content',
                sprintf(
                    /* translators: 1: word count found, 2: minimum required */
                    __( 'Generated content too short (%1$d words, minimum %2$d). Not retried — rewrite the row brief instead.', 'wonder-ai-builder' ),
                    $words,
                    $min_words
                )
            );
        }
        return true;
    }

    /**
     * Unicode-aware word count.
     *
     * str_word_count() is byte- and locale-based and cannot see non-Latin scripts.
     * Measured on PHP 8.4:
     *
     *   str_word_count('خدمات تنظيف المنازل في دبي بأفضل الأسعار')  => 33  (6 real words)
     *   str_word_count('专业清洁服务')                              => 6   (~2 real words)
     *   str_word_count('the quick brown fox jumps')                => 5   (correct)
     *
     * With this plugin defaulting to AED currency and Gulf deployments, an Arabic
     * page of ~25 real words would clear a 120-word floor and publish as
     * near-empty content. The behaviour is also locale-dependent, so on another
     * host it could under-count instead and permanently fail every job —
     * wab_empty_content is in the never-retry list.
     *
     * Counts runs of Unicode letters, allowing internal apostrophes so "don't"
     * counts once. CJK is approximated by character count / 2, since it has no
     * spaces and each glyph is roughly half a word.
     */
    public static function count_words( $html ) {
        $text = wp_strip_all_tags( (string) $html );
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) );
        if ( $text === '' ) return 0;

        // CJK / Hiragana / Katakana / Hangul run separately — no word delimiters.
        $cjk = 0;
        if ( preg_match_all( '/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text, $m ) ) {
            $cjk = (int) ceil( count( $m[0] ) / 2 );
            $text = preg_replace( '/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', ' ', $text );
        }

        $alpha = 0;
        if ( preg_match_all( "/\p{L}[\p{L}\p{M}\x{2019}']*/u", (string) $text, $m2 ) ) {
            $alpha = count( $m2[0] );
        }

        return $alpha + $cjk;
    }
}
