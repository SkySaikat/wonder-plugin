<?php
/**
 * Import — its own page, and it does exactly one thing: store rows.
 *
 * Importing and generating used to share a button that silently changed meaning.
 * They are now separate pages with separate verbs. This page never spends money;
 * it says so, out loud, twice.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$wab_title = __( 'Import a sheet', 'wonder-ai-builder' );
$wab_sub   = __( 'Store rows from a CSV or XLSX. Nothing is generated here.', 'wonder-ai-builder' );
$wab_back  = array( 'url' => WAB_Core::url( WAB_Core::SHEETS_SLUG ), 'label' => __( 'All sheets', 'wonder-ai-builder' ) );
include WAB_PLUGIN_DIR . 'admin/views/partials/header.php';

$modes = WAB_Prompt_Builder::modes();
$batch = WAB_Batch::summary();
?>

  <div class="wab-split wab-split-wide">

    <section class="wab-card">
      <h2><?php esc_html_e( 'Step 1 — Choose the file', 'wonder-ai-builder' ); ?></h2>
      <div class="wab-dropzone" id="wab-dropzone">
        <input type="file" id="wab-file" accept=".csv,.xlsx">
        <label for="wab-file">
          <strong><?php esc_html_e( 'Choose a CSV or XLSX file', 'wonder-ai-builder' ); ?></strong>
          <small><?php esc_html_e( 'Column names are detected automatically.', 'wonder-ai-builder' ); ?></small>
        </label>
      </div>
      <div id="wab-upload-status" class="wab-status" aria-live="polite"></div>

      <h2 id="wab-h-map" class="wab-h-muted"><?php esc_html_e( 'Step 2 — Check the columns', 'wonder-ai-builder' ); ?></h2>
      <div id="wab-mapper"><p class="wab-muted"><?php esc_html_e( 'Appears once a file has been read.', 'wonder-ai-builder' ); ?></p></div>
    </section>

    <aside class="wab-card">
      <h2><?php esc_html_e( 'Step 3 — Defaults for this sheet', 'wonder-ai-builder' ); ?></h2>
      <p class="wab-hint"><?php esc_html_e( 'All of these can be overridden per row later.', 'wonder-ai-builder' ); ?></p>

      <div class="wab-field">
        <label class="wab-label" for="wab-post-type"><?php esc_html_e( 'Build these as', 'wonder-ai-builder' ); ?></label>
        <select id="wab-post-type" class="wab-input">
          <option value="page"><?php esc_html_e( 'Pages', 'wonder-ai-builder' ); ?></option>
          <option value="post"><?php esc_html_e( 'Blog posts', 'wonder-ai-builder' ); ?></option>
        </select>
      </div>

      <div class="wab-field">
        <label class="wab-label" for="wab-mode"><?php esc_html_e( 'Content depth', 'wonder-ai-builder' ); ?></label>
        <select id="wab-mode" class="wab-input">
          <?php $cm = get_option( 'wab_content_mode', WAB_Prompt_Builder::MODE_HYBRID );
          foreach ( $modes as $k => $m ) : ?>
            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cm, $k ); ?> data-words="<?php echo esc_attr( $m['words'] ); ?>">
              <?php echo esc_html( $m['label'] ); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="wab-hint" id="wab-mode-note"></p>
      </div>

      <div class="wab-field">
        <label class="wab-label" for="wab-image-source"><?php esc_html_e( 'Images', 'wonder-ai-builder' ); ?></label>
        <select id="wab-image-source" class="wab-input">
          <?php $src = get_option( 'wab_image_source', 'library_then_ai' ); ?>
          <option value="library_only"    <?php selected( $src, 'library_only' ); ?>><?php esc_html_e( 'My library only — free', 'wonder-ai-builder' ); ?></option>
          <option value="library_then_ai" <?php selected( $src, 'library_then_ai' ); ?>><?php esc_html_e( 'Library first, then generate', 'wonder-ai-builder' ); ?></option>
          <option value="ai_only"         <?php selected( $src, 'ai_only' ); ?>><?php esc_html_e( 'Always generate new', 'wonder-ai-builder' ); ?></option>
          <option value="none"            <?php selected( $src, 'none' ); ?>><?php esc_html_e( 'No images', 'wonder-ai-builder' ); ?></option>
        </select>
        <p class="wab-hint"><?php esc_html_e( 'In-content images always come from your library, never generated.', 'wonder-ai-builder' ); ?></p>
      </div>

      <div class="wab-field">
        <label class="wab-label" for="wab-generation-mode"><?php esc_html_e( 'Speed vs cost', 'wonder-ai-builder' ); ?></label>
        <select id="wab-generation-mode" class="wab-input">
          <?php $gm = get_option( 'wab_generation_mode', 'standard' ); ?>
          <option value="standard" <?php selected( $gm, 'standard' ); ?>><?php esc_html_e( 'Standard — live, ~30s a page', 'wonder-ai-builder' ); ?></option>
          <option value="economy"  <?php selected( $gm, 'economy' ); ?>><?php esc_html_e( 'Economy — 50% off, up to 24h', 'wonder-ai-builder' ); ?></option>
        </select>
        <?php if ( $batch['enabled'] && ! $batch['available'] ) : ?>
          <p class="wab-hint wab-warn"><?php echo esc_html( $batch['reason'] ); ?></p>
        <?php endif; ?>
      </div>

      <hr class="wab-hr">

      <button class="button button-primary button-hero wab-full" id="wab-commit" disabled>
        <?php esc_html_e( 'Import rows', 'wonder-ai-builder' ); ?>
      </button>
      <p class="wab-hint wab-center"><?php esc_html_e( 'Free. You choose what to build on the next screen.', 'wonder-ai-builder' ); ?></p>
    </aside>
  </div>

  <section class="wab-card wab-narrow">
    <h2><?php esc_html_e( 'Spreadsheet tips', 'wonder-ai-builder' ); ?></h2>
    <ul class="wab-tips">
      <li><strong><?php esc_html_e( 'Service + Location', 'wonder-ai-builder' ); ?></strong> — <?php esc_html_e( 'together these form the title automatically.', 'wonder-ai-builder' ); ?></li>
      <li><strong><?php esc_html_e( 'Description', 'wonder-ai-builder' ); ?></strong> — <?php esc_html_e( 'the highest-value column. A one-line brief sharply improves the copy.', 'wonder-ai-builder' ); ?></li>
      <li><strong><?php esc_html_e( 'Schema', 'wonder-ai-builder' ); ?></strong> — <?php esc_html_e( 'paste raw JSON-LD and it is used verbatim, at no cost. Use {{url}}, {{title}}, {{image}} as placeholders.', 'wonder-ai-builder' ); ?></li>
      <li><strong><?php esc_html_e( 'Post type', 'wonder-ai-builder' ); ?></strong> — <?php esc_html_e( 'put "page" or "post" per row to mix both in one sheet.', 'wonder-ai-builder' ); ?></li>
      <li><?php esc_html_e( 'Columns you leave unmapped are stored for reference and never sent to the AI.', 'wonder-ai-builder' ); ?></li>
    </ul>
  </section>

</div>
