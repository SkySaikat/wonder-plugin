<?php
/**
 * Dashboard.
 *
 * UX principle: the operator's two questions are "what will this cost?" and "is it
 * running?". Both are answered above the fold, before any control. The Page/Post
 * switch is the first decision because it changes everything downstream.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$spend  = WAB_Cost_Guard::summary();
$counts = WAB_Queue::counts();
$health = WAB_Runner::health();
$modes  = WAB_Prompt_Builder::modes();
$site   = WAB_Scanner::site_summary();
?>
<div class="wrap wab">

  <header class="wab-head">
    <div>
      <h1><?php esc_html_e( 'Wonder AI Builder', 'wonder-ai-builder' ); ?></h1>
      <p class="wab-sub"><?php esc_html_e( 'Bulk-generate pages and posts from a spreadsheet.', 'wonder-ai-builder' ); ?></p>
    </div>
    <div class="wab-head-actions">
      <?php if ( $health['paused'] ) : ?>
        <button class="button button-primary" id="wab-resume"><?php esc_html_e( 'Resume queue', 'wonder-ai-builder' ); ?></button>
      <?php else : ?>
        <button class="button" id="wab-pause"><?php esc_html_e( 'Pause queue', 'wonder-ai-builder' ); ?></button>
      <?php endif; ?>
      <button class="button" id="wab-run-now"><?php esc_html_e( 'Run now', 'wonder-ai-builder' ); ?></button>
    </div>
  </header>

  <?php if ( ! empty( $health['issues'] ) ) : ?>
    <div class="wab-alert wab-alert-warn">
      <strong><?php esc_html_e( 'Queue health', 'wonder-ai-builder' ); ?></strong>
      <ul>
        <?php foreach ( $health['issues'] as $issue ) : ?>
          <li><?php echo esc_html( $issue ); ?></li>
        <?php endforeach; ?>
      </ul>
      <details>
        <summary><?php esc_html_e( 'Recommended server cron setup', 'wonder-ai-builder' ); ?></summary>
        <p><?php esc_html_e( 'WP-Cron only fires when someone visits the site, so quiet sites stall. Add this to your crontab:', 'wonder-ai-builder' ); ?></p>
        <?php $cron = WAB_Runner::cron_instructions(); ?>
        <pre><code><?php echo esc_html( $cron['recommended'] ); ?></code></pre>
        <p><?php esc_html_e( 'No WP-CLI? Use:', 'wonder-ai-builder' ); ?></p>
        <pre><code><?php echo esc_html( $cron['fallback'] ); ?></code></pre>
        <p><?php esc_html_e( 'Then add to wp-config.php:', 'wonder-ai-builder' ); ?> <code><?php echo esc_html( $cron['wp_config'] ); ?></code></p>
      </details>
    </div>
  <?php endif; ?>

  <!-- Cost + queue, answered first -->
  <section class="wab-stats">
    <div class="wab-stat">
      <span class="wab-stat-label"><?php esc_html_e( 'Spent today', 'wonder-ai-builder' ); ?></span>
      <span class="wab-stat-value">$<?php echo esc_html( number_format( $spend['today'], 3 ) ); ?></span>
      <span class="wab-stat-note">
        <?php if ( $spend['budget'] > 0 ) : ?>
          <?php printf( esc_html__( 'of $%s budget', 'wonder-ai-builder' ), esc_html( number_format( $spend['budget'], 2 ) ) ); ?>
        <?php else : ?>
          <?php esc_html_e( 'no daily cap set', 'wonder-ai-builder' ); ?>
        <?php endif; ?>
      </span>
    </div>
    <div class="wab-stat">
      <span class="wab-stat-label"><?php esc_html_e( 'Per page (est.)', 'wonder-ai-builder' ); ?></span>
      <span class="wab-stat-value" id="wab-per-item">$<?php echo esc_html( number_format( WAB_Provider_Registry::estimate_item_cost(), 4 ) ); ?></span>
      <span class="wab-stat-note"><?php esc_html_e( 'text + image', 'wonder-ai-builder' ); ?></span>
    </div>
    <div class="wab-stat">
      <span class="wab-stat-label"><?php esc_html_e( 'Queued', 'wonder-ai-builder' ); ?></span>
      <span class="wab-stat-value" id="wab-queued"><?php echo (int) $counts['queued']; ?></span>
      <span class="wab-stat-note"><span id="wab-processing"><?php echo (int) $counts['processing']; ?></span> <?php esc_html_e( 'running', 'wonder-ai-builder' ); ?></span>
    </div>
    <div class="wab-stat">
      <span class="wab-stat-label"><?php esc_html_e( 'Done', 'wonder-ai-builder' ); ?></span>
      <span class="wab-stat-value" id="wab-done"><?php echo (int) $counts['done']; ?></span>
      <span class="wab-stat-note"><span id="wab-failed"><?php echo (int) $counts['failed']; ?></span> <?php esc_html_e( 'failed', 'wonder-ai-builder' ); ?></span>
    </div>
    <div class="wab-stat">
      <span class="wab-stat-label"><?php esc_html_e( 'Image library', 'wonder-ai-builder' ); ?></span>
      <span class="wab-stat-value"><?php echo (int) ( $site['library_size'] ?? 0 ); ?></span>
      <span class="wab-stat-note"><?php esc_html_e( 'reusable at $0', 'wonder-ai-builder' ); ?></span>
    </div>
  </section>

  <div class="wab-grid">

    <!-- New run -->
    <section class="wab-card">
      <h2><?php esc_html_e( 'New run', 'wonder-ai-builder' ); ?></h2>

      <!-- The Page/Post switch, first because it changes everything after it -->
      <div class="wab-field">
        <label class="wab-label"><?php esc_html_e( 'What are you creating?', 'wonder-ai-builder' ); ?></label>
        <div class="wab-switch" role="radiogroup">
          <button type="button" class="wab-switch-opt is-active" data-post-type="page" role="radio" aria-checked="true">
            <strong><?php esc_html_e( 'Pages', 'wonder-ai-builder' ); ?></strong>
            <small><?php esc_html_e( 'Service &amp; location pages', 'wonder-ai-builder' ); ?></small>
          </button>
          <button type="button" class="wab-switch-opt" data-post-type="post" role="radio" aria-checked="false">
            <strong><?php esc_html_e( 'Posts', 'wonder-ai-builder' ); ?></strong>
            <small><?php esc_html_e( 'Blog articles', 'wonder-ai-builder' ); ?></small>
          </button>
        </div>
      </div>

      <div class="wab-field">
        <label class="wab-label" for="wab-mode"><?php esc_html_e( 'Content depth', 'wonder-ai-builder' ); ?></label>
        <select id="wab-mode" class="wab-input">
          <?php
          $current_mode = get_option( 'wab_content_mode', WAB_Prompt_Builder::MODE_HYBRID );
          foreach ( $modes as $key => $m ) :
            ?>
            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_mode, $key ); ?>
                    data-words="<?php echo esc_attr( $m['words'] ); ?>">
              <?php echo esc_html( $m['label'] ); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="wab-hint" id="wab-mode-note"><?php echo esc_html( $modes[ $current_mode ]['notes'] ?? '' ); ?></p>
      </div>

      <div class="wab-field">
        <label class="wab-label" for="wab-image-source"><?php esc_html_e( 'Images', 'wonder-ai-builder' ); ?></label>
        <select id="wab-image-source" class="wab-input">
          <?php $src = get_option( 'wab_image_source', 'library_then_ai' ); ?>
          <option value="library_only"    <?php selected( $src, 'library_only' ); ?>><?php esc_html_e( 'Existing library only — $0', 'wonder-ai-builder' ); ?></option>
          <option value="library_then_ai" <?php selected( $src, 'library_then_ai' ); ?>><?php esc_html_e( 'Library first, generate if no match (recommended)', 'wonder-ai-builder' ); ?></option>
          <option value="ai_only"         <?php selected( $src, 'ai_only' ); ?>><?php esc_html_e( 'Always generate new', 'wonder-ai-builder' ); ?></option>
          <option value="none"            <?php selected( $src, 'none' ); ?>><?php esc_html_e( 'No images', 'wonder-ai-builder' ); ?></option>
        </select>
        <p class="wab-hint"><?php esc_html_e( 'In-content images always come from your existing library, never generated.', 'wonder-ai-builder' ); ?></p>
      </div>

      <?php $batch = WAB_Batch::summary(); ?>
      <div class="wab-field">
        <label class="wab-label" for="wab-generation-mode"><?php esc_html_e( 'Speed vs cost', 'wonder-ai-builder' ); ?></label>
        <select id="wab-generation-mode" class="wab-input">
          <?php $gm = get_option( 'wab_generation_mode', 'standard' ); ?>
          <option value="standard" <?php selected( $gm, 'standard' ); ?>>
            <?php esc_html_e( 'Standard — live, ~30s per page', 'wonder-ai-builder' ); ?>
          </option>
          <option value="economy" <?php selected( $gm, 'economy' ); ?>>
            <?php esc_html_e( 'Economy — ~50% cheaper text, results in minutes to 24h', 'wonder-ai-builder' ); ?>
          </option>
        </select>
        <p class="wab-hint">
          <?php esc_html_e( 'Economy submits the writing as one async batch at half price. Needs 10+ rows. Images and publishing still happen locally.', 'wonder-ai-builder' ); ?>
          <?php if ( ! $batch['available'] && $batch['enabled'] ) : ?>
            <br><strong><?php echo esc_html( $batch['reason'] ); ?></strong>
          <?php endif; ?>
        </p>
      </div>

      <?php if ( $batch['in_flight'] > 0 || ! empty( $batch['open'] ) ) : ?>
        <div class="wab-alert wab-alert-info">
          <strong><?php esc_html_e( 'Batch in flight', 'wonder-ai-builder' ); ?></strong>
          <p>
            <?php
            printf(
              /* translators: 1: jobs awaiting results, 2: jobs ready to publish */
              esc_html__( '%1$d row(s) awaiting results, %2$d ready to publish locally. Nothing to do — results are collected automatically, and you can close this tab.', 'wonder-ai-builder' ),
              (int) $batch['in_flight'],
              (int) $batch['ready_local']
            );
            ?>
          </p>
        </div>
      <?php endif; ?>

      <div class="wab-field">
        <label class="wab-label" for="wab-file"><?php esc_html_e( 'Spreadsheet', 'wonder-ai-builder' ); ?></label>
        <input type="file" id="wab-file" accept=".csv,.xlsx" class="wab-input">
        <p class="wab-hint"><?php esc_html_e( 'CSV or XLSX. Columns are auto-detected — including a Schema column containing raw JSON-LD.', 'wonder-ai-builder' ); ?></p>
      </div>

      <div id="wab-upload-status" class="wab-status" aria-live="polite"></div>
      <div id="wab-mapper" class="wab-mapper" hidden></div>

      <div class="wab-actions">
        <button class="button button-primary" id="wab-commit" disabled><?php esc_html_e( 'Import rows', 'wonder-ai-builder' ); ?></button>
        <button class="button" id="wab-preview-images" hidden><?php esc_html_e( 'Test image matching', 'wonder-ai-builder' ); ?></button>
      </div>

      <div id="wab-preview-result" class="wab-status" aria-live="polite"></div>
    </section>

    <!-- Imports -->
    <section class="wab-card">
      <h2><?php esc_html_e( 'Imports', 'wonder-ai-builder' ); ?></h2>
      <div id="wab-imports" class="wab-list"><p class="wab-muted"><?php esc_html_e( 'Loading…', 'wonder-ai-builder' ); ?></p></div>
    </section>
  </div>

  <!-- Jobs -->
  <section class="wab-card">
    <div class="wab-card-head">
      <h2><?php esc_html_e( 'Queue', 'wonder-ai-builder' ); ?></h2>
      <div class="wab-filters">
        <?php foreach ( array( 'all', 'queued', 'processing', 'done', 'failed' ) as $f ) : ?>
          <button class="wab-chip<?php echo $f === 'all' ? ' is-active' : ''; ?>" data-status="<?php echo esc_attr( $f ); ?>">
            <?php echo esc_html( ucfirst( $f ) ); ?>
          </button>
        <?php endforeach; ?>
        <?php if ( current_user_can( WAB_Security::CAP_MANAGE ) ) : ?>
          <button class="wab-chip wab-chip-danger" id="wab-drain"><?php esc_html_e( 'Cancel queued', 'wonder-ai-builder' ); ?></button>
        <?php endif; ?>
      </div>
    </div>
    <div id="wab-jobs" class="wab-table-wrap"><p class="wab-muted"><?php esc_html_e( 'Loading…', 'wonder-ai-builder' ); ?></p></div>
  </section>

</div>
