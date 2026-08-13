<?php
/**
 * Settings.
 *
 * API keys are rendered as MASKS ONLY. The stored value never reaches the browser —
 * v1 echoed the full key into a type="password" input, which masks it visually while
 * leaving it in page source and readable via JS.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$state   = WAB_Settings::get_state();
$opt     = $state['options'];
$secrets = $state['secrets'];
?>
<div class="wrap wab">

  <header class="wab-head">
    <div>
      <h1><?php esc_html_e( 'Wonder AI Builder — Settings', 'wonder-ai-builder' ); ?></h1>
      <p class="wab-sub"><?php esc_html_e( 'Providers, cost controls, and server behaviour.', 'wonder-ai-builder' ); ?></p>
    </div>
    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . WAB_Core::MENU_SLUG ) ); ?>">
      &larr; <?php esc_html_e( 'Dashboard', 'wonder-ai-builder' ); ?>
    </a>
  </header>

  <div class="wab-alert wab-alert-info">
    <strong><?php esc_html_e( 'Deploying across many sites?', 'wonder-ai-builder' ); ?></strong>
    <p><?php esc_html_e( 'Define keys in wp-config.php instead of saving them here. They then never touch the database, backups, or staging clones — and one provisioning step configures every site:', 'wonder-ai-builder' ); ?></p>
    <pre><code>define( 'WAB_FAL_API_KEY', '…' );
define( 'WAB_GEMINI_API_KEY', '…' );
define( 'WAB_ANTHROPIC_API_KEY', '…' );</code></pre>
    <p><?php esc_html_e( 'Use Export / Import below to copy all non-secret settings between sites.', 'wonder-ai-builder' ); ?></p>
  </div>

  <form id="wab-settings-form">

    <section class="wab-card">
      <h2><?php esc_html_e( 'Text generation', 'wonder-ai-builder' ); ?></h2>

      <div class="wab-field-row">
        <div class="wab-field">
          <label class="wab-label" for="wab_text_provider"><?php esc_html_e( 'Provider', 'wonder-ai-builder' ); ?></label>
          <select id="wab_text_provider" name="wab_text_provider" class="wab-input">
            <?php foreach ( WAB_Provider_Registry::text_providers() as $id => $p ) : ?>
              <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $opt['wab_text_provider'], $id ); ?>>
                <?php echo esc_html( $p->get_label() ); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="wab-field">
          <label class="wab-label" for="wab_text_model"><?php esc_html_e( 'Model', 'wonder-ai-builder' ); ?></label>
          <select id="wab_text_model" name="wab_text_model" class="wab-input"></select>
          <p class="wab-hint" id="wab-text-model-note"></p>
        </div>
      </div>
    </section>

    <section class="wab-card">
      <h2><?php esc_html_e( 'Images', 'wonder-ai-builder' ); ?></h2>
      <p class="wab-hint">
        <?php esc_html_e( 'Images are normally the largest share of the bill. Matching your existing library costs nothing and is usually the better visual result.', 'wonder-ai-builder' ); ?>
      </p>

      <div class="wab-field-row">
        <div class="wab-field">
          <label class="wab-label" for="wab_image_source"><?php esc_html_e( 'Source', 'wonder-ai-builder' ); ?></label>
          <select id="wab_image_source" name="wab_image_source" class="wab-input">
            <option value="library_only"    <?php selected( $opt['wab_image_source'], 'library_only' ); ?>><?php esc_html_e( 'Existing library only — $0', 'wonder-ai-builder' ); ?></option>
            <option value="library_then_ai" <?php selected( $opt['wab_image_source'], 'library_then_ai' ); ?>><?php esc_html_e( 'Library first, then generate', 'wonder-ai-builder' ); ?></option>
            <option value="ai_only"         <?php selected( $opt['wab_image_source'], 'ai_only' ); ?>><?php esc_html_e( 'Always generate', 'wonder-ai-builder' ); ?></option>
            <option value="none"            <?php selected( $opt['wab_image_source'], 'none' ); ?>><?php esc_html_e( 'No images', 'wonder-ai-builder' ); ?></option>
          </select>
        </div>
        <div class="wab-field">
          <label class="wab-label" for="wab_image_provider"><?php esc_html_e( 'Generator', 'wonder-ai-builder' ); ?></label>
          <select id="wab_image_provider" name="wab_image_provider" class="wab-input">
            <?php foreach ( WAB_Provider_Registry::image_providers() as $id => $p ) : ?>
              <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $opt['wab_image_provider'], $id ); ?>>
                <?php echo esc_html( $p->get_label() ); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="wab-field">
          <label class="wab-label" for="wab_fal_model"><?php esc_html_e( 'Image model', 'wonder-ai-builder' ); ?></label>
          <select id="wab_fal_model" name="wab_fal_model" class="wab-input"></select>
          <p class="wab-hint" id="wab-image-model-note"></p>
        </div>
      </div>

      <div class="wab-field-row">
        <div class="wab-field">
          <label class="wab-label" for="wab_inline_images"><?php esc_html_e( 'In-content images per page', 'wonder-ai-builder' ); ?></label>
          <input type="number" min="0" max="6" id="wab_inline_images" name="wab_inline_images" class="wab-input"
                 value="<?php echo esc_attr( (int) $opt['wab_inline_images'] ); ?>">
          <p class="wab-hint"><?php esc_html_e( 'Always taken from your existing library — never generated, never billed.', 'wonder-ai-builder' ); ?></p>
        </div>
        <div class="wab-field">
          <label class="wab-label" for="wab_image_width"><?php esc_html_e( 'Width', 'wonder-ai-builder' ); ?></label>
          <input type="number" id="wab_image_width" name="wab_image_width" class="wab-input" value="<?php echo esc_attr( (int) $opt['wab_image_width'] ); ?>">
        </div>
        <div class="wab-field">
          <label class="wab-label" for="wab_image_height"><?php esc_html_e( 'Height', 'wonder-ai-builder' ); ?></label>
          <input type="number" id="wab_image_height" name="wab_image_height" class="wab-input" value="<?php echo esc_attr( (int) $opt['wab_image_height'] ); ?>">
        </div>
      </div>
    </section>

    <section class="wab-card">
      <h2><?php esc_html_e( 'API keys', 'wonder-ai-builder' ); ?></h2>
      <?php
      $labels = array(
        'wab_fal_api_key'       => 'fal.ai',
        'wab_gemini_api_key'    => 'Google Gemini',
        'wab_openai_api_key'    => 'OpenAI',
        'wab_anthropic_api_key' => 'Anthropic',
      );
      foreach ( $labels as $key => $label ) :
        $meta = $secrets[ $key ];
        ?>
        <div class="wab-field">
          <label class="wab-label" for="<?php echo esc_attr( $key ); ?>">
            <?php echo esc_html( $label ); ?>
            <?php if ( $meta['configured'] ) : ?>
              <span class="wab-badge wab-badge-ok"><?php esc_html_e( 'configured', 'wonder-ai-builder' ); ?></span>
            <?php else : ?>
              <span class="wab-badge wab-badge-off"><?php esc_html_e( 'not set', 'wonder-ai-builder' ); ?></span>
            <?php endif; ?>
          </label>
          <input type="password" autocomplete="off" spellcheck="false"
                 id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
                 class="wab-input" value=""
                 placeholder="<?php echo esc_attr( $meta['configured'] ? $meta['mask'] : __( 'Paste key to set', 'wonder-ai-builder' ) ); ?>"
                 <?php disabled( $meta['from_constant'] ); ?>>
          <p class="wab-hint">
            <?php if ( $meta['from_constant'] ) : ?>
              <?php esc_html_e( 'Defined in wp-config.php — cannot be changed here.', 'wonder-ai-builder' ); ?>
            <?php else : ?>
              <?php esc_html_e( 'Leave blank to keep the current key.', 'wonder-ai-builder' ); ?>
            <?php endif; ?>
          </p>
        </div>
      <?php endforeach; ?>
    </section>

    <section class="wab-card">
      <h2><?php esc_html_e( 'Cost &amp; server limits', 'wonder-ai-builder' ); ?></h2>
      <div class="wab-field-row">
        <div class="wab-field">
          <label class="wab-label" for="wab_daily_budget_usd"><?php esc_html_e( 'Daily budget (USD)', 'wonder-ai-builder' ); ?></label>
          <input type="number" step="0.01" min="0" id="wab_daily_budget_usd" name="wab_daily_budget_usd" class="wab-input"
                 value="<?php echo esc_attr( $opt['wab_daily_budget_usd'] ); ?>">
          <p class="wab-hint"><?php esc_html_e( '0 = unlimited. The queue pauses itself when reached.', 'wonder-ai-builder' ); ?></p>
        </div>
        <div class="wab-field">
          <label class="wab-label" for="wab_jobs_per_tick"><?php esc_html_e( 'Pages per minute', 'wonder-ai-builder' ); ?></label>
          <input type="number" min="1" max="25" id="wab_jobs_per_tick" name="wab_jobs_per_tick" class="wab-input"
                 value="<?php echo esc_attr( (int) $opt['wab_jobs_per_tick'] ); ?>">
          <p class="wab-hint"><?php esc_html_e( '5 ≈ 300/hour. Raise only if the server has headroom.', 'wonder-ai-builder' ); ?></p>
        </div>
        <div class="wab-field">
          <label class="wab-label" for="wab_load_threshold"><?php esc_html_e( 'Pause above server load', 'wonder-ai-builder' ); ?></label>
          <input type="number" step="0.5" min="0" id="wab_load_threshold" name="wab_load_threshold" class="wab-input"
                 value="<?php echo esc_attr( $opt['wab_load_threshold'] ); ?>">
          <p class="wab-hint">
            <strong><?php esc_html_e( '0 = off, and 0 is correct for shared hosting.', 'wonder-ai-builder' ); ?></strong>
            <?php esc_html_e( 'This reads the load of the whole physical server, not just your site, so on shared hosting it is normally high and would block generation permanently. Only set a number on dedicated hardware.', 'wonder-ai-builder' ); ?>
            <?php if ( function_exists( 'sys_getloadavg' ) ) {
              $wab_l = @sys_getloadavg();
              if ( is_array( $wab_l ) && isset( $wab_l[0] ) ) {
                printf( esc_html__( 'Current host load: %.2f.', 'wonder-ai-builder' ), (float) $wab_l[0] );
              }
            } ?>
          </p>
        </div>
      </div>
    </section>

    <section class="wab-card">
      <h2><?php esc_html_e( 'Brand voice', 'wonder-ai-builder' ); ?></h2>
      <p class="wab-hint"><?php esc_html_e( 'Sent once per import as a cached prefix, not per page — so detail here is effectively free.', 'wonder-ai-builder' ); ?></p>
      <div class="wab-field">
        <label class="wab-label" for="wab_concept_industry"><?php esc_html_e( 'Industry', 'wonder-ai-builder' ); ?></label>
        <input type="text" id="wab_concept_industry" name="wab_concept_industry" class="wab-input" value="<?php echo esc_attr( $opt['wab_concept_industry'] ); ?>">
      </div>
      <div class="wab-field">
        <label class="wab-label" for="wab_concept_audience"><?php esc_html_e( 'Audience', 'wonder-ai-builder' ); ?></label>
        <input type="text" id="wab_concept_audience" name="wab_concept_audience" class="wab-input" value="<?php echo esc_attr( $opt['wab_concept_audience'] ); ?>">
      </div>
      <div class="wab-field">
        <label class="wab-label" for="wab_concept_usps"><?php esc_html_e( 'Differentiators (one per line)', 'wonder-ai-builder' ); ?></label>
        <textarea id="wab_concept_usps" name="wab_concept_usps" class="wab-input" rows="3"><?php echo esc_textarea( $opt['wab_concept_usps'] ); ?></textarea>
      </div>
      <div class="wab-field">
        <label class="wab-label" for="wab_concept_avoid"><?php esc_html_e( 'Never mention (one per line)', 'wonder-ai-builder' ); ?></label>
        <textarea id="wab_concept_avoid" name="wab_concept_avoid" class="wab-input" rows="2"><?php echo esc_textarea( $opt['wab_concept_avoid'] ); ?></textarea>
      </div>
    </section>

    <div class="wab-sticky-save">
      <button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save settings', 'wonder-ai-builder' ); ?></button>
      <span id="wab-save-msg" aria-live="polite"></span>
      <span class="wab-spacer"></span>
      <button type="button" class="button" id="wab-export"><?php esc_html_e( 'Export config', 'wonder-ai-builder' ); ?></button>
      <button type="button" class="button" id="wab-import"><?php esc_html_e( 'Import config', 'wonder-ai-builder' ); ?></button>
    </div>
  </form>

  <section class="wab-card">
    <h2><?php esc_html_e( 'Recent log', 'wonder-ai-builder' ); ?></h2>
    <div class="wab-log">
      <?php $entries = WAB_Logger::get_entries( 40 ); ?>
      <?php if ( empty( $entries ) ) : ?>
        <p class="wab-muted"><?php esc_html_e( 'Nothing logged.', 'wonder-ai-builder' ); ?></p>
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

  <script type="application/json" id="wab-settings-state"><?php
    echo wp_json_encode( array(
      'text_models'  => $state['text_models'],
      'image_models' => $state['image_models'],
      'selected'     => array(
        'text_provider'  => $opt['wab_text_provider'],
        'text_model'     => $opt['wab_text_model'],
        'image_provider' => $opt['wab_image_provider'],
        'fal_model'      => $opt['wab_fal_model'],
      ),
    ) );
  ?></script>
</div>
