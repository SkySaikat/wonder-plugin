<?php
/**
 * One sheet — its rows, with per-row selection.
 *
 * This screen is the answer to "I imported 5 sheets and want 2 blog posts out of
 * one of them". Rows are ticked individually and the type is chosen at generation
 * time, so a single sheet can produce pages for some rows and blog posts for others.
 *
 * Rows are rendered server-side: the table is present on first paint, works without
 * JavaScript, and is paginated by a normal query arg. JS only handles selection and
 * the Generate call.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

$import_id = sanitize_text_field( wp_unslash( $_GET['import_id'] ) );

$import = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}wab_imports WHERE import_id = %s LIMIT 1",
    $import_id
) );

if ( ! $import ) {
    $wab_title = __( 'Sheet not found', 'wonder-ai-builder' );
    $wab_back  = array( 'url' => WAB_Core::url( WAB_Core::SHEETS_SLUG ), 'label' => __( 'All sheets', 'wonder-ai-builder' ) );
    include WAB_PLUGIN_DIR . 'admin/views/partials/header.php';
    echo '<section class="wab-card wab-blank"><h2>' . esc_html__( 'That sheet no longer exists.', 'wonder-ai-builder' ) . '</h2></section></div>';
    return;
}

$wab_title = $import->filename;
$wab_sub   = __( 'Tick the rows you want to build, choose the type, then press Generate.', 'wonder-ai-builder' );
$wab_back  = array( 'url' => WAB_Core::url( WAB_Core::SHEETS_SLUG ), 'label' => __( 'All sheets', 'wonder-ai-builder' ) );
include WAB_PLUGIN_DIR . 'admin/views/partials/header.php';

$per_page = 50;
$page     = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
$offset   = ( $page - 1 ) * $per_page;

$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT r.id, r.row_index, r.title, r.services, r.location, r.post_type, r.category,
            ( r.schema_markup <> '' ) AS has_schema,
            j.job_id, j.status AS job_status, j.result_post_id, j.error_message, j.cost_usd
       FROM {$wpdb->prefix}wab_rows r
  LEFT JOIN {$wpdb->prefix}wab_jobs j
         ON j.import_id = r.import_id AND j.row_index = r.row_index
      WHERE r.import_id = %s
   ORDER BY r.row_index ASC
      LIMIT %d OFFSET %d",
    $import_id, $per_page, $offset
) );

$total = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wab_rows WHERE import_id = %s", $import_id
) );

$counts   = WAB_Queue::counts( $import_id );
$per_item = WAB_Provider_Registry::estimate_item_cost();
$pages    = (int) ceil( $total / $per_page );

/** A row is locked when it already has, or is about to have, a post. */
$locked_states = array( 'done', 'processing', 'queued', 'batched' );
?>

  <section class="wab-card">
    <div class="wab-sheetmeta">
      <span><strong><?php echo (int) $total; ?></strong> <?php esc_html_e( 'rows', 'wonder-ai-builder' ); ?></span>
      <span><strong><?php echo (int) $counts['done']; ?></strong> <?php esc_html_e( 'created', 'wonder-ai-builder' ); ?></span>
      <?php if ( (int) $counts['failed'] ) : ?>
        <span class="wab-err"><strong><?php echo (int) $counts['failed']; ?></strong> <?php esc_html_e( 'failed', 'wonder-ai-builder' ); ?></span>
      <?php endif; ?>
      <span class="wab-muted">·</span>
      <span class="wab-muted"><?php echo esc_html( $import->content_mode ); ?></span>
      <span class="wab-muted"><?php echo esc_html( str_replace( '_', ' ', $import->image_source ) ); ?></span>
      <span class="wab-spacer"></span>
      <span class="wab-muted"><?php printf( esc_html__( 'about $%s per page', 'wonder-ai-builder' ), esc_html( number_format( $per_item, 4 ) ) ); ?></span>
    </div>
  </section>

  <!-- Sticky action bar. Selection is meaningless without a visible action, so it
       follows you down long row lists. -->
  <div class="wab-selbar" id="wab-selbar" data-import="<?php echo esc_attr( $import_id ); ?>">
    <label class="wab-check">
      <input type="checkbox" id="wab-select-all">
      <span id="wab-sel-count"><?php esc_html_e( 'Select all on this page', 'wonder-ai-builder' ); ?></span>
    </label>

    <span class="wab-selbar-sep"></span>

    <label class="wab-inline-label" for="wab-gen-type"><?php esc_html_e( 'Create as', 'wonder-ai-builder' ); ?></label>
    <select id="wab-gen-type" class="wab-input wab-input-sm">
      <option value=""><?php esc_html_e( 'As set in the sheet', 'wonder-ai-builder' ); ?></option>
      <option value="page"><?php esc_html_e( 'Pages', 'wonder-ai-builder' ); ?></option>
      <option value="post"><?php esc_html_e( 'Blog posts', 'wonder-ai-builder' ); ?></option>
    </select>

    <span class="wab-spacer"></span>

    <span class="wab-estimate" id="wab-estimate" data-per="<?php echo esc_attr( $per_item ); ?>"></span>
    <button class="button" id="wab-test-images"><?php esc_html_e( 'Test images', 'wonder-ai-builder' ); ?></button>
    <button class="button button-primary" id="wab-generate" disabled><?php esc_html_e( 'Generate selected', 'wonder-ai-builder' ); ?></button>
  </div>

  <div id="wab-gen-result" class="wab-status" aria-live="polite"></div>

  <section class="wab-card">
    <?php if ( empty( $rows ) ) : ?>
      <p class="wab-empty"><?php esc_html_e( 'This sheet has no usable rows.', 'wonder-ai-builder' ); ?></p>
    <?php else : ?>
      <div class="wab-table-wrap">
        <table class="wab-table wab-rowtable">
          <thead>
            <tr>
              <th class="wab-col-chk"></th>
              <th class="wab-col-n">#</th>
              <th><?php esc_html_e( 'Title', 'wonder-ai-builder' ); ?></th>
              <th><?php esc_html_e( 'Location', 'wonder-ai-builder' ); ?></th>
              <th><?php esc_html_e( 'Type', 'wonder-ai-builder' ); ?></th>
              <th><?php esc_html_e( 'Schema', 'wonder-ai-builder' ); ?></th>
              <th><?php esc_html_e( 'State', 'wonder-ai-builder' ); ?></th>
              <th><?php esc_html_e( 'Result', 'wonder-ai-builder' ); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ( $rows as $r ) :
            $state  = $r->job_status ?: 'new';
            $locked = in_array( $state, $locked_states, true );
            ?>
            <tr class="<?php echo $locked ? 'wab-row-locked' : ''; ?>">
              <td class="wab-col-chk">
                <input type="checkbox" class="wab-row-check" value="<?php echo (int) $r->id; ?>"
                  <?php disabled( $locked ); ?>
                  <?php echo $locked ? 'title="' . esc_attr__( 'Already built or queued', 'wonder-ai-builder' ) . '"' : ''; ?>>
              </td>
              <td class="wab-muted"><?php echo (int) $r->row_index; ?></td>
              <td>
                <strong><?php echo esc_html( $r->title ); ?></strong>
                <?php if ( $r->services && $r->services !== $r->title ) : ?>
                  <br><small class="wab-muted"><?php echo esc_html( $r->services ); ?></small>
                <?php endif; ?>
              </td>
              <td><?php echo esc_html( $r->location ?: '—' ); ?></td>
              <td><?php echo esc_html( $r->post_type ?: $import->post_type ); ?></td>
              <td><?php echo $r->has_schema
                ? '<span class="wab-badge wab-badge-ok">' . esc_html__( 'custom', 'wonder-ai-builder' ) . '</span>'
                : '<span class="wab-badge">' . esc_html__( 'auto', 'wonder-ai-builder' ) . '</span>'; ?></td>
              <td><span class="wab-pill wab-pill-<?php echo esc_attr( $state ); ?>"><?php
                echo esc_html( $state === 'new' ? __( 'not built', 'wonder-ai-builder' ) : $state );
              ?></span></td>
              <td>
                <?php if ( $r->result_post_id ) : ?>
                  <a href="<?php echo esc_url( (string) get_edit_post_link( (int) $r->result_post_id ) ); ?>"><?php esc_html_e( 'Edit', 'wonder-ai-builder' ); ?></a>
                  &middot;
                  <a href="<?php echo esc_url( (string) get_permalink( (int) $r->result_post_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'wonder-ai-builder' ); ?></a>
                <?php elseif ( $r->error_message ) : ?>
                  <span class="wab-err" title="<?php echo esc_attr( $r->error_message ); ?>">
                    <?php echo esc_html( mb_strimwidth( $r->error_message, 0, 70, '…' ) ); ?>
                  </span>
                <?php else : ?>
                  <span class="wab-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ( $pages > 1 ) : ?>
        <div class="wab-pager">
          <?php for ( $p = 1; $p <= min( $pages, 40 ); $p++ ) :
            $url = WAB_Core::url( WAB_Core::SHEETS_SLUG, array( 'import_id' => $import_id, 'paged' => $p ) ); ?>
            <a class="wab-page<?php echo $p === $page ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo (int) $p; ?></a>
          <?php endfor; ?>
        </div>
        <p class="wab-hint"><?php esc_html_e( 'Selection applies to this page only. Generate, then move to the next page.', 'wonder-ai-builder' ); ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

</div>
