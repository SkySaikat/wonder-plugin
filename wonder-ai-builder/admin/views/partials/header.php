<?php
/**
 * Shared page header: title, run-state, and the compact stat strip.
 *
 * Included by every screen so the two questions an operator always has — "is it
 * running?" and "what is this costing?" — are answered identically everywhere,
 * without each view re-implementing it.
 *
 * Expects: $wab_title, and optionally $wab_sub and $wab_back (array: url, label).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$wab_spend  = WAB_Cost_Guard::summary();
$wab_counts = WAB_Queue::counts();
$wab_health = WAB_Runner::health();
?>
<div class="wrap wab">

  <header class="wab-head">
    <div class="wab-head-title">
      <?php if ( ! empty( $wab_back ) ) : ?>
        <a class="wab-back" href="<?php echo esc_url( $wab_back['url'] ); ?>">&larr; <?php echo esc_html( $wab_back['label'] ); ?></a>
      <?php endif; ?>
      <h1><?php echo esc_html( $wab_title ); ?></h1>
      <?php if ( ! empty( $wab_sub ) ) : ?>
        <p class="wab-sub"><?php echo esc_html( $wab_sub ); ?></p>
      <?php endif; ?>
    </div>

    <div class="wab-head-actions">
      <span class="wab-runstate <?php echo $wab_health['paused'] ? 'is-paused' : 'is-live'; ?>">
        <span class="wab-dot"></span>
        <?php echo $wab_health['paused'] ? esc_html__( 'Paused', 'wonder-ai-builder' ) : esc_html__( 'Running', 'wonder-ai-builder' ); ?>
      </span>
      <?php if ( $wab_health['paused'] ) : ?>
        <button class="button button-primary" id="wab-resume"><?php esc_html_e( 'Resume', 'wonder-ai-builder' ); ?></button>
      <?php else : ?>
        <button class="button" id="wab-pause"><?php esc_html_e( 'Pause', 'wonder-ai-builder' ); ?></button>
      <?php endif; ?>
    </div>
  </header>

  <!-- Page nav. Real links, so each screen is bookmarkable and the browser
       back button behaves the way people expect. -->
  <nav class="wab-nav">
    <?php
    $wab_current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    $wab_busy    = (int) $wab_counts['queued'] + (int) $wab_counts['processing'];

    $wab_links = array(
      array( WAB_Core::MENU_SLUG,   __( 'Dashboard', 'wonder-ai-builder' ), null ),
      array( WAB_Core::SHEETS_SLUG, __( 'Sheets', 'wonder-ai-builder' ),    null ),
      array( WAB_Core::QUEUE_SLUG,  __( 'Queue', 'wonder-ai-builder' ),     $wab_busy ?: null ),
      array( WAB_Core::IMPORT_SLUG, __( 'Import a sheet', 'wonder-ai-builder' ), null ),
    );

    if ( current_user_can( WAB_Security::CAP_MANAGE ) ) {
      $wab_links[] = array( WAB_Core::SETTINGS_SLUG, __( 'Settings', 'wonder-ai-builder' ), null );
    }

    foreach ( $wab_links as $l ) :
      $is_on = ( $wab_current === $l[0] );
      ?>
      <a class="wab-navlink<?php echo $is_on ? ' is-active' : ''; ?>" href="<?php echo esc_url( WAB_Core::url( $l[0] ) ); ?>">
        <?php echo esc_html( $l[1] ); ?>
        <?php if ( $l[2] ) : ?><span class="wab-navcount"><?php echo (int) $l[2]; ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if ( ! empty( $wab_health['issues'] ) && $wab_current === WAB_Core::MENU_SLUG ) : ?>
    <div class="wab-alert wab-alert-warn">
      <strong><?php esc_html_e( 'The queue is not running reliably', 'wonder-ai-builder' ); ?></strong>
      <ul><?php foreach ( $wab_health['issues'] as $wab_i ) : ?><li><?php echo esc_html( $wab_i ); ?></li><?php endforeach; ?></ul>
      <details>
        <summary><?php esc_html_e( 'Show the cron setup for this site', 'wonder-ai-builder' ); ?></summary>
        <?php $wab_cron = WAB_Runner::cron_instructions(); ?>
        <pre><code><?php echo esc_html( $wab_cron['recommended'] ); ?></code></pre>
        <p><?php esc_html_e( 'No WP-CLI? Use:', 'wonder-ai-builder' ); ?></p>
        <pre><code><?php echo esc_html( $wab_cron['fallback'] ); ?></code></pre>
        <p><?php esc_html_e( 'Then in wp-config.php:', 'wonder-ai-builder' ); ?> <code><?php echo esc_html( $wab_cron['wp_config'] ); ?></code></p>
      </details>
    </div>
  <?php endif; ?>
