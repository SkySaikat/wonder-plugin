<?php
/**
 * One sheet — rows, selection, and Generate.
 *
 * Everything that matters here is a real <form method="post"> to admin-post.php.
 * No JavaScript is required to select rows, choose a type, or generate. That is
 * deliberate: this action costs money and creates content, and it previously broke
 * completely when a cached admin.js left the button disabled.
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
    echo '<div class="notice notice-error"><p>' . esc_html__( 'That sheet no longer exists.', 'wonder-ai-builder' ) . '</p></div></div>';
    return;
}

$wab_title = $import->filename;
$wab_sub   = __( 'Tick rows and press Generate. Or use Generate everything unbuilt to do the whole sheet.', 'wonder-ai-builder' );
$wab_back  = array( 'url' => WAB_Core::url( WAB_Core::SHEETS_SLUG ), 'label' => __( 'All sheets', 'wonder-ai-builder' ) );
include WAB_PLUGIN_DIR . 'admin/views/partials/header.php';

$per_page = 50;
$page     = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
$offset   = ( $page - 1 ) * $per_page;

$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT r.id, r.row_index, r.title, r.services, r.location, r.post_type,
            ( r.schema_markup <> '' ) AS has_schema,
            j.status AS job_status, j.result_post_id, j.error_message
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

// How many rows have never been built (or failed) — the count for the bulk button.
$unbuilt = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*)
       FROM {$wpdb->prefix}wab_rows r
  LEFT JOIN {$wpdb->prefix}wab_jobs j
         ON j.import_id = r.import_id AND j.row_index = r.row_index
      WHERE r.import_id = %s
        AND ( j.status IS NULL OR j.status IN ('failed','cancelled') )",
    $import_id
) );

$counts   = WAB_Queue::counts( $import_id );
$per_item = WAB_Provider_Registry::estimate_item_cost();
$pages    = (int) ceil( $total / $per_page );

$locked_states = array( 'done', 'processing', 'queued', 'batched' );
?>

  <!-- Summary -->
  <div class="wab-summary">
    <span><strong><?php echo (int) $total; ?></strong> <?php esc_html_e( 'rows', 'wonder-ai-builder' ); ?></span>
    <span><strong><?php echo (int) $counts['done']; ?></strong> <?php esc_html_e( 'created', 'wonder-ai-builder' ); ?></span>
    <?php if ( (int) $counts['failed'] ) : ?>
      <span class="wab-err"><strong><?php echo (int) $counts['failed']; ?></strong> <?php esc_html_e( 'failed', 'wonder-ai-builder' ); ?></span>
    <?php endif; ?>
    <span><strong><?php echo (int) $unbuilt; ?></strong> <?php esc_html_e( 'not built yet', 'wonder-ai-builder' ); ?></span>
    <span class="wab-summary-cost"><?php
      printf( esc_html__( 'about $%s per page', 'wonder-ai-builder' ), esc_html( number_format( $per_item, 4 ) ) );
    ?></span>
  </div>

  <?php if ( $unbuilt > 0 ) : ?>
    <!-- The one-click path. Most sheets just need "build all of it". -->
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wab-bulkbar">
      <?php wp_nonce_field( 'wab_generate' ); ?>
      <input type="hidden" name="action" value="wab_generate">
      <input type="hidden" name="import_id" value="<?php echo esc_attr( $import_id ); ?>">
      <input type="hidden" name="scope" value="all_unbuilt">

      <div class="wab-bulkbar-text">
        <strong><?php printf(
          esc_html( _n( 'Build the %d row that is not created yet', 'Build all %d rows that are not created yet', $unbuilt, 'wonder-ai-builder' ) ),
          $unbuilt
        ); ?></strong>
        <span class="wab-muted"><?php printf(
          esc_html__( 'Estimated total $%s', 'wonder-ai-builder' ),
          esc_html( number_format( $per_item * $unbuilt, 2 ) )
        ); ?></span>
      </div>

      <label class="wab-inline-label" for="wab-bulk-type"><?php esc_html_e( 'as', 'wonder-ai-builder' ); ?></label>
      <select id="wab-bulk-type" name="post_type" class="wab-input wab-input-sm">
        <option value=""><?php esc_html_e( 'Type set in sheet', 'wonder-ai-builder' ); ?></option>
        <option value="post" <?php selected( $import->post_type, 'post' ); ?>><?php esc_html_e( 'Blog posts', 'wonder-ai-builder' ); ?></option>
        <option value="page" <?php selected( $import->post_type, 'page' ); ?>><?php esc_html_e( 'Pages', 'wonder-ai-builder' ); ?></option>
      </select>

      <button type="submit" class="button button-primary button-hero">
        <?php esc_html_e( 'Generate everything unbuilt', 'wonder-ai-builder' ); ?>
      </button>
    </form>
  <?php endif; ?>

  <!-- Per-row selection form -->
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wab-rowform">
    <?php wp_nonce_field( 'wab_generate' ); ?>
    <input type="hidden" name="action" value="wab_generate">
    <input type="hidden" name="import_id" value="<?php echo esc_attr( $import_id ); ?>">
    <input type="hidden" name="scope" value="selected">
    <input type="hidden" name="paged" value="<?php echo (int) $page; ?>">

    <div class="wab-selbar" id="wab-selbar" data-import="<?php echo esc_attr( $import_id ); ?>">
      <label class="wab-check">
        <input type="checkbox" id="wab-select-all">
        <span id="wab-sel-count"><?php esc_html_e( 'Select all on this page', 'wonder-ai-builder' ); ?></span>
      </label>

      <span class="wab-selbar-sep"></span>

      <label class="wab-inline-label" for="wab-gen-type"><?php esc_html_e( 'Create as', 'wonder-ai-builder' ); ?></label>
      <select id="wab-gen-type" name="post_type" class="wab-input wab-input-sm">
        <option value=""><?php esc_html_e( 'Type set in sheet', 'wonder-ai-builder' ); ?></option>
        <option value="post"><?php esc_html_e( 'Blog posts', 'wonder-ai-builder' ); ?></option>
        <option value="page"><?php esc_html_e( 'Pages', 'wonder-ai-builder' ); ?></option>
      </select>

      <span class="wab-spacer"></span>
      <span class="wab-estimate" id="wab-estimate" data-per="<?php echo esc_attr( $per_item ); ?>"></span>

      <!-- Never disabled. The server decides whether the request is valid and says
           so in a notice; a disabled button that cannot explain itself is worse. -->
      <button type="submit" class="button button-primary">
        <?php esc_html_e( 'Generate ticked rows', 'wonder-ai-builder' ); ?>
      </button>
    </div>

    <?php if ( empty( $rows ) ) : ?>
      <div class="wab-card"><p class="wab-empty"><?php esc_html_e( 'This sheet has no usable rows.', 'wonder-ai-builder' ); ?></p></div>
    <?php else : ?>
      <table class="wp-list-table widefat fixed striped wab-rowtable">
        <thead>
          <tr>
            <td class="manage-column check-column"></td>
            <th scope="col" class="manage-column" style="width:52px">#</th>
            <th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Title', 'wonder-ai-builder' ); ?></th>
            <th scope="col" class="manage-column"><?php esc_html_e( 'Location', 'wonder-ai-builder' ); ?></th>
            <th scope="col" class="manage-column" style="width:80px"><?php esc_html_e( 'Type', 'wonder-ai-builder' ); ?></th>
            <th scope="col" class="manage-column" style="width:90px"><?php esc_html_e( 'Schema', 'wonder-ai-builder' ); ?></th>
            <th scope="col" class="manage-column" style="width:110px"><?php esc_html_e( 'State', 'wonder-ai-builder' ); ?></th>
            <th scope="col" class="manage-column"><?php esc_html_e( 'Result', 'wonder-ai-builder' ); ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ( $rows as $r ) :
          $state  = $r->job_status ?: 'new';
          $locked = in_array( $state, $locked_states, true );
          ?>
          <tr class="<?php echo $locked ? 'wab-row-locked' : ''; ?>">
            <th scope="row" class="check-column">
              <?php if ( $locked ) : ?>
                <span class="dashicons dashicons-lock" title="<?php esc_attr_e( 'Already built or queued', 'wonder-ai-builder' ); ?>"></span>
              <?php else : ?>
                <input type="checkbox" class="wab-row-check" name="row_ids[]" value="<?php echo (int) $r->id; ?>">
              <?php endif; ?>
            </th>
            <td><?php echo (int) $r->row_index; ?></td>
            <td class="column-primary">
              <strong><?php echo esc_html( $r->title ); ?></strong>
              <?php if ( $r->services && $r->services !== $r->title ) : ?>
                <br><span class="wab-muted"><?php echo esc_html( $r->services ); ?></span>
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
                  <?php echo esc_html( mb_strimwidth( $r->error_message, 0, 60, '…' ) ); ?>
                </span>
              <?php else : ?>
                <span class="wab-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </form>

  <?php if ( $pages > 1 ) : ?>
    <div class="tablenav"><div class="tablenav-pages">
      <?php
      echo paginate_links( array(
        'base'      => WAB_Core::url( WAB_Core::SHEETS_SLUG, array( 'import_id' => $import_id, 'paged' => '%#%' ) ),
        'format'    => '',
        'current'   => $page,
        'total'     => $pages,
        'prev_text' => '&laquo;',
        'next_text' => '&raquo;',
      ) );
      ?>
    </div></div>
    <p class="description"><?php esc_html_e( 'Ticking applies to this page only. To do the whole sheet in one go, use “Generate everything unbuilt” above.', 'wonder-ai-builder' ); ?></p>
  <?php endif; ?>

  <!-- Danger zone -->
  <hr class="wab-hr">
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
        onsubmit="return confirm('<?php echo esc_js( __( 'Delete this sheet and its rows? Pages already created will be kept.', 'wonder-ai-builder' ) ); ?>');">
    <?php wp_nonce_field( 'wab_delete_sheet' ); ?>
    <input type="hidden" name="action" value="wab_delete_sheet">
    <input type="hidden" name="import_id" value="<?php echo esc_attr( $import_id ); ?>">
    <button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete this sheet', 'wonder-ai-builder' ); ?></button>
    <span class="description"><?php esc_html_e( 'Removes the rows and job history. Generated pages are never deleted.', 'wonder-ai-builder' ); ?></span>
  </form>

</div>
