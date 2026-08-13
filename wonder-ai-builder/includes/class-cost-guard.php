<?php
/**
 * Spend tracking and hard budget enforcement.
 *
 * THE v1 BUG THIS EXISTS TO KILL
 * ------------------------------
 * ai-page-builder/includes/class-page-generator.php:154
 *
 *     UPDATE {prefix}aipb_jobs SET status = 'queued'
 *     WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
 *
 * No retry ceiling. A row that can never succeed — content-policy refusal,
 * malformed input, a permanently failing image prompt — was requeued every five
 * minutes forever. Each cycle re-billed a full text call AND a full image call:
 * roughly 12 regenerations/hour at ~$0.047 each ≈ $0.56/hour, per stuck row,
 * indefinitely. Ten stuck rows is about $135/month burning invisibly.
 *
 * The post builder had already fixed this (class-post-generator.php:131-136 caps at
 * retry_count >= 3) but the fix was never ported back to the page builder. Unifying
 * the two plugins removes that entire class of drift.
 *
 * Compounding it: v1 generated the image BEFORE wp_insert_post
 * (class-page-generator.php:253-263), so a failure at the post-insert stage
 * re-billed the image on every retry. v2 persists the attachment ID against the
 * row, so a retry never pays for the same image twice.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Cost_Guard {

    /** Absolute retry ceiling. Beyond this a job is failed permanently. */
    const MAX_RETRIES = 3;

    const OPT_SPEND_TODAY   = 'wab_spend_today';
    const OPT_SPEND_DATE    = 'wab_spend_date';
    const OPT_SPEND_TOTAL   = 'wab_spend_total';
    const OPT_DAILY_BUDGET  = 'wab_daily_budget_usd';

    /**
     * Record spend from a single API call.
     *
     * @param float  $usd
     * @param string $kind 'text' | 'image'
     */
    public static function record( $usd, $kind = 'text' ) {
        $usd = (float) $usd;
        if ( $usd <= 0 ) return;

        self::roll_day();

        $today = (float) get_option( self::OPT_SPEND_TODAY, 0 );
        $total = (float) get_option( self::OPT_SPEND_TOTAL, 0 );

        update_option( self::OPT_SPEND_TODAY, round( $today + $usd, 6 ), false );
        update_option( self::OPT_SPEND_TOTAL, round( $total + $usd, 6 ), false );

        $by_kind = get_option( 'wab_spend_by_kind', array() );
        if ( ! is_array( $by_kind ) ) $by_kind = array();
        $by_kind[ $kind ] = round( ( $by_kind[ $kind ] ?? 0 ) + $usd, 6 );
        update_option( 'wab_spend_by_kind', $by_kind, false );
    }

    /** Reset the daily counter when the date changes (site timezone). */
    private static function roll_day() {
        $today = current_time( 'Y-m-d' );
        if ( get_option( self::OPT_SPEND_DATE ) !== $today ) {
            update_option( self::OPT_SPEND_DATE, $today, false );
            update_option( self::OPT_SPEND_TODAY, 0, false );
        }
    }

    public static function spend_today() {
        self::roll_day();
        return (float) get_option( self::OPT_SPEND_TODAY, 0 );
    }

    public static function spend_total() {
        return (float) get_option( self::OPT_SPEND_TOTAL, 0 );
    }

    public static function daily_budget() {
        return (float) get_option( self::OPT_DAILY_BUDGET, 0 );
    }

    /**
     * Check the budget BEFORE spending. Called at the top of every job.
     *
     * @param float $projected Estimated cost of the job about to run.
     * @return true|WP_Error
     */
    public static function can_spend( $projected = 0.0 ) {
        $budget = self::daily_budget();
        if ( $budget <= 0 ) {
            return true; // Unlimited — explicitly configured.
        }

        $spent = self::spend_today();
        if ( ( $spent + (float) $projected ) > $budget ) {
            return new WP_Error(
                'wab_budget_exceeded',
                sprintf(
                    /* translators: 1: spent, 2: budget */
                    __( 'Daily AI budget reached ($%1$.2f of $%2$.2f). Queue paused until tomorrow, or raise the budget in Settings.', 'wonder-ai-builder' ),
                    $spent,
                    $budget
                )
            );
        }

        return true;
    }

    /**
     * Should this job be retried, or failed permanently?
     *
     * Distinguishes transient failures (worth another attempt) from deterministic
     * ones. Re-running a content-policy refusal or a malformed-input error will
     * fail identically every time and simply bills again — v1's core mistake.
     *
     * @param WP_Error $error
     * @param int      $retry_count Attempts already made.
     */
    public static function should_retry( $error, $retry_count ) {
        if ( $retry_count >= self::MAX_RETRIES ) {
            return false;
        }

        if ( ! is_wp_error( $error ) ) {
            return false;
        }

        $code = $error->get_error_code();

        // Deterministic failures — never retry, they cost money and cannot succeed.
        $permanent = array(
            'wab_budget_exceeded',
            'wab_fal_no_key',
            'wab_fal_nsfw',
            'wab_no_key',
            'wab_fal_no_prompt',
            'wab_encode_failed',
            'wab_http_400',
            'wab_http_401',
            'wab_http_402', // Insufficient balance — retrying cannot help.
            'wab_http_403',
            'wab_http_404',
            'wab_http_405',
            'wab_http_413', // Payload too large — deterministic.
            'wab_http_422',
            'wab_http_451',
            'wab_content_blocked',
            'wab_empty_content',
            // Deterministic response-shape failure: the model/endpoint returned no
            // image, e.g. an unsupported parameter for that model. Retrying re-bills
            // the text call for a guaranteed-identical outcome.
            'wab_fal_no_image',
            // Truncation repeats identically under the same settings, so a
            // retry only re-bills. The operator must lower Content depth.
            'wab_truncated',
            'wab_bad_request',
        );
        if ( in_array( $code, $permanent, true ) ) {
            return false;
        }

        // Transient: rate limits, upstream 5xx, transport errors, parse hiccups.
        return true;
    }

    /**
     * Human-readable spend summary for the dashboard.
     */
    public static function summary() {
        $by_kind = get_option( 'wab_spend_by_kind', array() );
        if ( ! is_array( $by_kind ) ) $by_kind = array();

        return array(
            'today'   => round( self::spend_today(), 4 ),
            'total'   => round( self::spend_total(), 4 ),
            'budget'  => round( self::daily_budget(), 2 ),
            'text'    => round( (float) ( $by_kind['text'] ?? 0 ), 4 ),
            'image'   => round( (float) ( $by_kind['image'] ?? 0 ), 4 ),
            'blocked' => is_wp_error( self::can_spend( 0 ) ),
        );
    }
}
