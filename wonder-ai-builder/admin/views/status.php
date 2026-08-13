<?php
/**
 * System status — answers "why is nothing happening?" without guesswork.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$wab_title = __( 'System status', 'wonder-ai-builder' );
$wab_sub   = __( 'Everything that has to be true for generation to work, checked live.', 'wonder-ai-builder' );
include WAB_PLUGIN_DIR . 'admin/views/partials/header.php';

$checks  = WAB_Diagnostics::run();
$overall = WAB_Diagnostics::overall( $checks );
$cron    = WAB_Runner::cron_instructions();
$counts  = WAB_Queue::counts();

$banner = array(
    WAB_Diagnostics::PASS => array( 'notice-success', __( 'Everything checks out. Generation will run.', 'wonder-ai-builder' ) ),
    WAB_Diagnostics::WARN => array( 'notice-warning', __( 'Generation will run, but something needs attention.', 'wonder-ai-builder' ) ),
    WAB_Diagnostics::FAIL => array( 'notice-error',   __( 'Generation cannot run until the failures below are fixed.', 'wonder-ai-builder' ) ),
);
?>

  <div class="notice <?php echo esc_attr( $banner[ $overall ][0] ); ?> inline wab-banner">
    <p><strong><?php echo esc_html( $banner[ $overall ][1] ); ?></strong></p>
  </div>

  <?php $wab_missing = WAB_Activator::find_missing(); ?>
  <?php if ( ! empty( $wab_missing ) ) : ?>
    <section class="wab-card wab-card-alarm">
      <h2><?php esc_html_e( 'Database needs repair', 'wonder-ai-builder' ); ?></h2>
      <p><?php esc_html_e( 'These database objects are missing. Until they exist, rows cannot be queued and the Queue screen will look empty even when it reports activity:', 'wonder-ai-builder' ); ?></p>
      <ul class="wab-missing">
        <?php foreach ( $wab_missing as $wab_m ) : ?>
          <li><code><?php echo esc_html( $wab_m ); ?></code></li>
        <?php endforeach; ?>
      </ul>
      <button class="button button-primary" id="wab-repair"><?php esc_html_e( 'Repair database now', 'wonder-ai-builder' ); ?></button>
      <div id="wab-repair-out" class="wab-status" aria-live="polite"></div>
    </section>
  <?php endif; ?>

  <div class="wab-split wab-split-wide">

    <section class="wab-card">
      <h2><?php esc_html_e( 'Checks', 'wonder-ai-builder' ); ?></h2>
      <ul class="wab-checks">
        <?php foreach ( $checks as $c ) : ?>
          <li class="wab-check-<?php echo esc_attr( $c['status'] ); ?>">
            <span class="wab-check-icon" aria-hidden="true"><?php
              echo $c['status'] === WAB_Diagnostics::PASS ? '&#10003;' : ( $c['status'] === WAB_Diagnostics::WARN ? '!' : '&times;' );
            ?></span>
            <div>
              <strong><?php echo esc_html( $c['label'] ); ?></strong>
              <p><?php echo esc_html( $c['detail'] ); ?></p>
              <?php if ( ! empty( $c['fix'] ) ) : ?>
                <p class="wab-check-fix"><?php echo esc_html( $c['fix'] ); ?></p>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <aside>
      <section class="wab-card">
        <h2><?php esc_html_e( 'Test it right now', 'wonder-ai-builder' ); ?></h2>
        <p class="wab-hint"><?php esc_html_e( 'Runs the worker immediately and reports exactly what it did — including why it declined, if it declined.', 'wonder-ai-builder' ); ?></p>
        <button class="button button-primary wab-full" id="wab-selftest">
          <?php esc_html_e( 'Run one job now', 'wonder-ai-builder' ); ?>
        </button>
        <div id="wab-selftest-out" class="wab-status" aria-live="polite"></div>
        <p class="wab-hint">
          <?php printf(
            esc_html__( 'Queue right now: %1$d waiting, %2$d running, %3$d created, %4$d failed.', 'wonder-ai-builder' ),
            (int) $counts['queued'], (int) $counts['processing'], (int) $counts['done'], (int) $counts['failed']
          ); ?>
        </p>
      </section>

      <section class="wab-card">
        <h2><?php esc_html_e( 'Server cron', 'wonder-ai-builder' ); ?></h2>
        <p class="wab-hint"><?php esc_html_e( 'WordPress only runs scheduled work when someone visits the site. On a quiet site the queue stalls. Add one of these to your crontab and it runs unattended.', 'wonder-ai-builder' ); ?></p>

        <p><strong><?php esc_html_e( 'Recommended (WP-CLI)', 'wonder-ai-builder' ); ?></strong></p>
        <pre class="wab-copy"><code><?php echo esc_html( $cron['recommended'] ); ?></code></pre>

        <p><strong><?php esc_html_e( 'Alternative (curl)', 'wonder-ai-builder' ); ?></strong></p>
        <pre class="wab-copy"><code><?php echo esc_html( $cron['fallback'] ); ?></code></pre>

        <p><strong><?php esc_html_e( 'Then in wp-config.php', 'wonder-ai-builder' ); ?></strong></p>
        <pre class="wab-copy"><code><?php echo esc_html( $cron['wp_config'] ); ?></code></pre>

        <p class="wab-hint"><?php esc_html_e( 'Safe to run every minute: the worker throttles itself, skips when idle, and refuses to start when the server is busy.', 'wonder-ai-builder' ); ?></p>
      </section>

      <section class="wab-card">
        <h2><?php esc_html_e( 'Recent log', 'wonder-ai-builder' ); ?></h2>
        <div class="wab-log">
          <?php $entries = WAB_Logger::get_entries( 25 ); ?>
          <?php if ( empty( $entries ) ) : ?>
            <p class="wab-muted"><?php esc_html_e( 'Nothing logged yet.', 'wonder-ai-builder' ); ?></p>
          <?php else : ?>
            <?php foreach ( $entries as $e ) : ?>
              <div class="wab-log-row wab-log-<?php echo esc_attr( $e['level'] ); ?>">
                <code><?php echo esc_html( $e['time'] ); ?></code>
                <span><?php echo esc_html( $e['message'] ); ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>
    </aside>
  </div>

</div>
