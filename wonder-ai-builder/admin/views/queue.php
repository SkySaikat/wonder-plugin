<?php
/**
 * Queue — its own page, so "where do I see the queue?" has one obvious answer.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$wab_title = __( 'Queue', 'wonder-ai-builder' );
$wab_sub   = __( 'Everything waiting, running, or finished.', 'wonder-ai-builder' );
include WAB_PLUGIN_DIR . 'admin/views/partials/header.php';

$counts = WAB_Queue::counts();
$batch  = WAB_Batch::summary();
$health = WAB_Runner::health();
?>

  <section class="wab-stats wab-stats-tight">
    <?php
    $tiles = array(
      'queued'     => array( __( 'Waiting', 'wonder-ai-builder' ),  'wab-queued' ),
      'processing' => array( __( 'Running', 'wonder-ai-builder' ),  'wab-processing' ),
      'done'       => array( __( 'Created', 'wonder-ai-builder' ),  'wab-done' ),
      'failed'     => array( __( 'Failed', 'wonder-ai-builder' ),   'wab-failed' ),
    );
    foreach ( $tiles as $k => $t ) : ?>
      <div class="wab-stat">
        <span class="wab-stat-label"><?php echo esc_html( $t[0] ); ?></span>
        <span class="wab-stat-value" id="<?php echo esc_attr( $t[1] ); ?>"><?php echo (int) $counts[ $k ]; ?></span>
      </div>
    <?php endforeach; ?>
    <?php if ( $batch['in_flight'] > 0 ) : ?>
      <div class="wab-stat">
        <span class="wab-stat-label"><?php esc_html_e( 'Batched', 'wonder-ai-builder' ); ?></span>
        <span class="wab-stat-value"><?php echo (int) $batch['in_flight']; ?></span>
        <span class="wab-stat-note"><?php esc_html_e( 'at half price', 'wonder-ai-builder' ); ?></span>
      </div>
    <?php endif; ?>
  </section>

  <div class="wab-note-inline">
    <?php esc_html_e( 'Generation runs on the server. You can close this tab or shut your laptop — it keeps going.', 'wonder-ai-builder' ); ?>
    <?php if ( $health['last_tick_age'] !== null ) : ?>
      <span class="wab-muted"><?php printf(
        esc_html__( 'Last checked %d second(s) ago.', 'wonder-ai-builder' ), (int) $health['last_tick_age']
      ); ?></span>
    <?php endif; ?>
  </div>

  <section class="wab-card">
    <div class="wab-card-head">
      <div class="wab-filters">
        <?php
        $filters = array(
          'all'        => __( 'All', 'wonder-ai-builder' ),
          'queued'     => __( 'Waiting', 'wonder-ai-builder' ),
          'batched'    => __( 'Batched', 'wonder-ai-builder' ),
          'processing' => __( 'Running', 'wonder-ai-builder' ),
          'done'       => __( 'Created', 'wonder-ai-builder' ),
          'failed'     => __( 'Failed', 'wonder-ai-builder' ),
        );
        foreach ( $filters as $k => $label ) : ?>
          <button class="wab-chip<?php echo $k === 'all' ? ' is-active' : ''; ?>" data-status="<?php echo esc_attr( $k ); ?>">
            <?php echo esc_html( $label ); ?>
          </button>
        <?php endforeach; ?>
      </div>
      <div class="wab-filters">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wab-inline-form">
          <?php wp_nonce_field( 'wab_queue_action' ); ?>
          <input type="hidden" name="action" value="wab_queue_action">
          <input type="hidden" name="back" value="<?php echo esc_attr( WAB_Core::QUEUE_SLUG ); ?>">
          <input type="hidden" name="do" value="run">
          <button type="submit" class="button button-primary"><?php esc_html_e( 'Run the queue now', 'wonder-ai-builder' ); ?></button>
        </form>
        <?php if ( current_user_can( WAB_Security::CAP_MANAGE ) ) : ?>
          <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wab-inline-form"
                onsubmit="return confirm('<?php echo esc_js( __( 'Cancel every waiting job? Completed work is kept.', 'wonder-ai-builder' ) ); ?>');">
            <?php wp_nonce_field( 'wab_queue_action' ); ?>
            <input type="hidden" name="action" value="wab_queue_action">
            <input type="hidden" name="back" value="<?php echo esc_attr( WAB_Core::QUEUE_SLUG ); ?>">
            <input type="hidden" name="do" value="clear">
            <button type="submit" class="button button-link-delete"><?php esc_html_e( 'Cancel all waiting', 'wonder-ai-builder' ); ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div id="wab-jobs" class="wab-table-wrap">
      <p class="wab-muted"><?php esc_html_e( 'Loading…', 'wonder-ai-builder' ); ?></p>
    </div>
  </section>

</div>
