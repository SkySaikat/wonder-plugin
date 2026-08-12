<?php
/**
 * Dashboard — overview only. No controls that do real work.
 *
 * Deliberately thin: it answers "what is happening and what did it cost", then
 * points at the page where each job is actually done.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$wab_title = __( 'Dashboard', 'wonder-ai-builder' );
$wab_sub   = __( 'Overview of generation activity and spend.', 'wonder-ai-builder' );
include WAB_PLUGIN_DIR . 'admin/views/partials/header.php';

$spend  = WAB_Cost_Guard::summary();
$counts = WAB_Queue::counts();
$site   = WAB_Scanner::site_summary();
$batch  = WAB_Batch::summary();
$per    = WAB_Provider_Registry::estimate_item_cost();
$text   = WAB_Provider_Registry::text();
$image  = WAB_Provider_Registry::image();
?>

  <section class="wab-stats">
    <div class="wab-stat">
      <span class="wab-stat-label"><?php esc_html_e( 'Spent today', 'wonder-ai-builder' ); ?></span>
      <span class="wab-stat-value">$<?php echo esc_html( number_format( $spend['today'], 3 ) ); ?></span>
      <span class="wab-stat-note"><?php
        echo $spend['budget'] > 0
          ? esc_html( sprintf( __( 'of $%s daily cap', 'wonder-ai-builder' ), number_format( $spend['budget'], 2 ) ) )
          : esc_html__( 'no cap set', 'wonder-ai-builder' );
      ?></span>
    </div>
    <div class="wab-stat">
      <span class="wab-stat-label"><?php esc_html_e( 'Spent all time', 'wonder-ai-builder' ); ?></span>
      <span class="wab-stat-value">$<?php echo esc_html( number_format( $spend['total'], 2 ) ); ?></span>
      <span class="wab-stat-note"><?php printf(
        esc_html__( 'text $%1$s · images $%2$s', 'wonder-ai-builder' ),
        esc_html( number_format( $spend['text'], 2 ) ),
        esc_html( number_format( $spend['image'], 2 ) )
      ); ?></span>
    </div>
    <div class="wab-stat">
      <span class="wab-stat-label"><?php esc_html_e( 'Cost per page', 'wonder-ai-builder' ); ?></span>
      <span class="wab-stat-value">$<?php echo esc_html( number_format( $per, 4 ) ); ?></span>
      <span class="wab-stat-note"><?php esc_html_e( 'at current settings', 'wonder-ai-builder' ); ?></span>
    </div>
    <div class="wab-stat">
      <span class="wab-stat-label"><?php esc_html_e( 'Pages created', 'wonder-ai-builder' ); ?></span>
      <span class="wab-stat-value"><?php echo (int) $counts['done']; ?></span>
      <span class="wab-stat-note"><?php printf( esc_html__( '%d failed', 'wonder-ai-builder' ), (int) $counts['failed'] ); ?></span>
    </div>
    <div class="wab-stat">
      <span class="wab-stat-label"><?php esc_html_e( 'Free images', 'wonder-ai-builder' ); ?></span>
      <span class="wab-stat-value"><?php echo (int) ( $site['library_size'] ?? 0 ); ?></span>
      <span class="wab-stat-note"><?php esc_html_e( 'reusable at no cost', 'wonder-ai-builder' ); ?></span>
    </div>
  </section>

  <div class="wab-split">

    <section class="wab-card">
      <h2><?php esc_html_e( 'What now?', 'wonder-ai-builder' ); ?></h2>
      <div class="wab-dolist">
        <a class="wab-do" href="<?php echo esc_url( WAB_Core::url( WAB_Core::IMPORT_SLUG ) ); ?>">
          <span class="wab-do-n">1</span>
          <span><strong><?php esc_html_e( 'Import a spreadsheet', 'wonder-ai-builder' ); ?></strong>
          <small><?php esc_html_e( 'Store the rows. Costs nothing.', 'wonder-ai-builder' ); ?></small></span>
        </a>
        <a class="wab-do" href="<?php echo esc_url( WAB_Core::url( WAB_Core::SHEETS_SLUG ) ); ?>">
          <span class="wab-do-n">2</span>
          <span><strong><?php esc_html_e( 'Open a sheet and pick rows', 'wonder-ai-builder' ); ?></strong>
          <small><?php esc_html_e( 'Tick only what you want. Choose page or blog post.', 'wonder-ai-builder' ); ?></small></span>
        </a>
        <a class="wab-do" href="<?php echo esc_url( WAB_Core::url( WAB_Core::QUEUE_SLUG ) ); ?>">
          <span class="wab-do-n">3</span>
          <span><strong><?php esc_html_e( 'Watch the queue', 'wonder-ai-builder' ); ?></strong>
          <small><?php esc_html_e( 'Runs on the server. Close the tab whenever you like.', 'wonder-ai-builder' ); ?></small></span>
        </a>
      </div>
    </section>

    <section class="wab-card">
      <h2><?php esc_html_e( 'Current setup', 'wonder-ai-builder' ); ?></h2>
      <table class="wab-kv">
        <tr>
          <th><?php esc_html_e( 'Writer', 'wonder-ai-builder' ); ?></th>
          <td><?php echo esc_html( $text->get_label() ); ?>
            <?php echo $text->is_configured()
              ? '<span class="wab-badge wab-badge-ok">ready</span>'
              : '<span class="wab-badge wab-badge-off">no key</span>'; ?>
          </td>
        </tr>
        <tr>
          <th><?php esc_html_e( 'Images', 'wonder-ai-builder' ); ?></th>
          <td>
            <?php
            $srcs = array(
              'library_only'    => __( 'My library only (free)', 'wonder-ai-builder' ),
              'library_then_ai' => __( 'Library first, then generate', 'wonder-ai-builder' ),
              'ai_only'         => __( 'Always generate', 'wonder-ai-builder' ),
              'none'            => __( 'No images', 'wonder-ai-builder' ),
            );
            $cur = get_option( 'wab_image_source', 'library_then_ai' );
            echo esc_html( $srcs[ $cur ] ?? $cur );
            ?>
          </td>
        </tr>
        <tr>
          <th><?php esc_html_e( 'Depth', 'wonder-ai-builder' ); ?></th>
          <td><?php
            $modes = WAB_Prompt_Builder::modes();
            $cm    = get_option( 'wab_content_mode', WAB_Prompt_Builder::MODE_HYBRID );
            echo esc_html( $modes[ $cm ]['label'] ?? $cm );
          ?></td>
        </tr>
        <tr>
          <th><?php esc_html_e( 'Speed vs cost', 'wonder-ai-builder' ); ?></th>
          <td><?php echo get_option( 'wab_generation_mode', 'standard' ) === 'economy'
            ? esc_html__( 'Economy — 50% off text', 'wonder-ai-builder' )
            : esc_html__( 'Standard — live', 'wonder-ai-builder' ); ?></td>
        </tr>
        <tr>
          <th><?php esc_html_e( 'New posts saved as', 'wonder-ai-builder' ); ?></th>
          <td><?php echo esc_html( ucfirst( (string) get_option( 'wab_default_status', 'draft' ) ) ); ?></td>
        </tr>
      </table>
      <?php if ( current_user_can( WAB_Security::CAP_MANAGE ) ) : ?>
        <a class="button wab-full" href="<?php echo esc_url( WAB_Core::url( WAB_Core::SETTINGS_SLUG ) ); ?>">
          <?php esc_html_e( 'Change settings', 'wonder-ai-builder' ); ?>
        </a>
      <?php endif; ?>
    </section>
  </div>

  <?php if ( $batch['in_flight'] > 0 ) : ?>
    <div class="wab-alert wab-alert-info">
      <strong><?php esc_html_e( 'Economy batch in flight', 'wonder-ai-builder' ); ?></strong>
      <p><?php printf(
        esc_html__( '%1$d row(s) are with the provider at half price; %2$d have come back and are queued locally. Collected automatically — nothing for you to do.', 'wonder-ai-builder' ),
        (int) $batch['in_flight'], (int) $batch['ready_local']
      ); ?></p>
    </div>
  <?php endif; ?>

</div>
