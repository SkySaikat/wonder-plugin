<?php
/**
 * Sheets — the list. One card per imported spreadsheet.
 *
 * Rendered server-side rather than by AJAX so the page is useful the instant it
 * loads, and so it still works if JavaScript fails. Clicking a sheet navigates to
 * its own page; nothing expands inline.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$wab_title = __( 'Sheets', 'wonder-ai-builder' );
$wab_sub   = __( 'Every spreadsheet you have imported. Open one to choose which rows to build.', 'wonder-ai-builder' );
include WAB_PLUGIN_DIR . 'admin/views/partials/header.php';

global $wpdb;
$imports = $wpdb->get_results(
    "SELECT import_id, filename, total_rows, post_type, content_mode, image_source, created_at
       FROM {$wpdb->prefix}wab_imports
      ORDER BY created_at DESC LIMIT 100"
);
?>

  <div class="wab-toolbar">
    <a class="button button-primary" href="<?php echo esc_url( WAB_Core::url( WAB_Core::IMPORT_SLUG ) ); ?>">
      <?php esc_html_e( '+ Import a sheet', 'wonder-ai-builder' ); ?>
    </a>
    <span class="wab-hint"><?php
      printf( esc_html( _n( '%d sheet', '%d sheets', count( (array) $imports ), 'wonder-ai-builder' ) ), count( (array) $imports ) );
    ?></span>
  </div>

  <?php if ( empty( $imports ) ) : ?>
    <section class="wab-card wab-blank">
      <h2><?php esc_html_e( 'No sheets yet', 'wonder-ai-builder' ); ?></h2>
      <p><?php esc_html_e( 'Import a CSV or XLSX to get started. Importing only stores the rows — nothing is generated and nothing is spent until you choose rows and press Generate.', 'wonder-ai-builder' ); ?></p>
      <a class="button button-primary button-hero" href="<?php echo esc_url( WAB_Core::url( WAB_Core::IMPORT_SLUG ) ); ?>">
        <?php esc_html_e( 'Import your first sheet', 'wonder-ai-builder' ); ?>
      </a>
    </section>
  <?php else : ?>
    <div class="wab-sheetgrid">
      <?php foreach ( $imports as $imp ) :
        $c     = WAB_Queue::counts( $imp->import_id );
        $total = (int) ( $c['total'] ?: $imp->total_rows );
        $done  = (int) $c['done'];
        $pct   = $total ? (int) round( ( $done / $total ) * 100 ) : 0;
        $url   = WAB_Core::url( WAB_Core::SHEETS_SLUG, array( 'import_id' => $imp->import_id ) );
        $left  = max( 0, $total - $done - (int) $c['failed'] );
        ?>
        <article class="wab-sheetcard">
          <header>
            <h3><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $imp->filename ); ?></a></h3>
            <span class="wab-badge"><?php echo esc_html( $imp->post_type === 'post' ? __( 'Blog posts', 'wonder-ai-builder' ) : __( 'Pages', 'wonder-ai-builder' ) ); ?></span>
          </header>

          <div class="wab-bar"><span style="width:<?php echo (int) $pct; ?>%"></span></div>

          <ul class="wab-sheetstats">
            <li><strong><?php echo (int) $total; ?></strong> <?php esc_html_e( 'rows', 'wonder-ai-builder' ); ?></li>
            <li><strong><?php echo (int) $done; ?></strong> <?php esc_html_e( 'created', 'wonder-ai-builder' ); ?></li>
            <?php if ( (int) $c['failed'] ) : ?>
              <li class="wab-err"><strong><?php echo (int) $c['failed']; ?></strong> <?php esc_html_e( 'failed', 'wonder-ai-builder' ); ?></li>
            <?php endif; ?>
            <?php if ( $left ) : ?>
              <li><strong><?php echo (int) $left; ?></strong> <?php esc_html_e( 'left to build', 'wonder-ai-builder' ); ?></li>
            <?php endif; ?>
          </ul>

          <footer>
            <span class="wab-muted"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $imp->created_at ) ); ?></span>
            <span class="wab-cardactions">
              <a class="button button-small button-primary" href="<?php echo esc_url( $url ); ?>">
                <?php esc_html_e( 'Open rows', 'wonder-ai-builder' ); ?>
              </a>
              <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                    class="wab-inline-form"
                    onsubmit="return confirm('<?php echo esc_js( __( 'Delete this sheet? Pages already created will be kept.', 'wonder-ai-builder' ) ); ?>');">
                <?php wp_nonce_field( 'wab_delete_sheet' ); ?>
                <input type="hidden" name="action" value="wab_delete_sheet">
                <input type="hidden" name="import_id" value="<?php echo esc_attr( $imp->import_id ); ?>">
                <button type="submit" class="button-link wab-del" title="<?php esc_attr_e( 'Delete sheet', 'wonder-ai-builder' ); ?>">
                  <?php esc_html_e( 'Delete', 'wonder-ai-builder' ); ?>
                </button>
              </form>
            </span>
          </footer>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
