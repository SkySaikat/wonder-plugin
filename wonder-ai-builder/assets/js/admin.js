/* global WAB, jQuery */
/**
 * Wonder AI Builder admin.
 *
 * Each admin screen is a separate WordPress page now, so this file has no routing,
 * no tab state, and no shared wizard. It detects which page it is on by the presence
 * of a root element and binds only that behaviour.
 *
 * Rows and sheets are rendered server-side; JavaScript handles selection, the
 * Generate call, and live queue polling. Generation itself runs on the server, so
 * this UI is a viewer — closing the tab changes nothing.
 */
( function ( $ ) {
  'use strict';

  var App = {

    s: { selected: {}, jobStatus: 'all', jobPage: 1, uploadKey: '', timer: null },

    /** Escapes text and double-quoted attributes, single quote included. */
    esc: function ( v ) {
      if ( v === null || v === undefined ) { return ''; }
      return String( v )
        .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
        .replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
    },

    post: function ( action, data ) {
      return $.post( WAB.ajax, $.extend( { action: action, nonce: WAB.nonce }, data || {} ) );
    },

    money: function ( n ) {
      n = Number( n ) || 0;
      return '$' + ( n < 0.01 ? n.toFixed( 4 ) : n.toFixed( 2 ) );
    },

    say: function ( sel, html, kind ) {
      $( sel ).attr( 'class', 'wab-status wab-status-' + ( kind || 'info' ) ).html( html );
    },

    // ===========================================================
    init: function () {
      if ( ! $( '.wab' ).length ) { return; }

      this.bindRunState();          // header pause/resume — present on every page

      if ( $( '#wab-selbar' ).length )  { this.bindRowSelection(); }
      if ( $( '#wab-jobs' ).length )    { this.bindQueue(); this.loadJobs(); this.startPolling(); }
      if ( $( '#wab-file' ).length )    { this.bindImport(); }
      if ( $( '#wab-settings-state' ).length ) { this.bindSettings(); }
      if ( $( '#wab-selftest' ).length )       { this.bindSelfTest(); }

      $( document ).on( 'click', '#wab-repair', function () {
        var $b = $( this ).prop( 'disabled', true ).text( 'Repairing…' );
        App.say( '#wab-repair-out', 'Running database migration…', 'info' );
        App.post( 'wab_repair' ).done( function ( r ) {
          if ( ! r || ! r.success ) { App.say( '#wab-repair-out', WAB.i18n.genericError, 'error' ); return; }
          App.say( '#wab-repair-out', App.esc( r.data.message ), r.data.ok ? 'ok' : 'error' );
          if ( r.data.ok ) { window.setTimeout( function () { location.reload(); }, 1200 ); }
        } ).always( function () { $b.prop( 'disabled', false ).text( 'Repair database now' ); } );
      } );
    },

    startPolling: function () {
      var self = this;
      this.stopPolling();
      this.s.timer = window.setInterval( function () { self.loadJobs( true ); }, 8000 );

      document.addEventListener( 'visibilitychange', function () {
        if ( document.hidden ) { self.stopPolling(); }
        else { self.loadJobs( true ); self.startPolling(); }
      } );
    },

    stopPolling: function () {
      if ( this.s.timer ) { window.clearInterval( this.s.timer ); this.s.timer = null; }
    },

    // ===========================================================
    bindRunState: function () {
      $( document ).on( 'click', '#wab-pause',  function () { App.post( 'wab_pause'  ).done( function () { location.reload(); } ); } );
      $( document ).on( 'click', '#wab-resume', function () { App.post( 'wab_resume' ).done( function () { location.reload(); } ); } );
    },

    // ===========================================================
    // Sheet page: row selection + generate
    // ===========================================================
    bindRowSelection: function () {
      var importId = $( '#wab-selbar' ).data( 'import' );

      $( document ).on( 'change', '#wab-select-all', function () {
        var on = this.checked;
        $( '.wab-row-check:not(:disabled)' ).each( function () {
          this.checked = on;
          if ( on ) { App.s.selected[ this.value ] = true; } else { delete App.s.selected[ this.value ]; }
        } );
        App.refreshSelection();
      } );

      $( document ).on( 'change', '.wab-row-check', function () {
        if ( this.checked ) { App.s.selected[ this.value ] = true; } else { delete App.s.selected[ this.value ]; }
        App.refreshSelection();
      } );

      // Clicking anywhere on an unlocked row toggles it — much faster than
      // aiming at 14px checkboxes down a 50-row list.
      $( document ).on( 'click', '.wab-rowtable tbody tr:not(.wab-row-locked)', function ( e ) {
        if ( $( e.target ).is( 'input, a, button, label' ) ) { return; }
        var $c = $( this ).find( '.wab-row-check' );
        $c.prop( 'checked', ! $c.prop( 'checked' ) ).trigger( 'change' );
      } );

      $( document ).on( 'click', '#wab-generate', function () {
        var ids = Object.keys( App.s.selected );
        if ( ! ids.length ) { return; }

        var $btn = $( this ).prop( 'disabled', true ).text( 'Queueing…' );

        App.post( 'wab_queue', {
          import_id: importId,
          row_ids: ids,
          post_type: $( '#wab-gen-type' ).val()
        } ).done( function ( r ) {
          if ( ! r || ! r.success ) {
            App.say( '#wab-gen-result', App.esc( ( r && r.data && r.data.message ) || WAB.i18n.genericError ), 'error' );
            $btn.prop( 'disabled', false );
            App.refreshSelection();
            return;
          }

          App.say( '#wab-gen-result',
            '<strong>' + App.esc( r.data.message ) + '</strong> ' +
            '<a href="' + App.esc( WAB.queueUrl ) + '">' + App.esc( 'Open the queue →' ) + '</a>',
            'ok' );

          // Reload so row states reflect reality rather than a guess.
          window.setTimeout( function () { location.reload(); }, 1200 );
        } ).fail( function () {
          App.say( '#wab-gen-result', 'Request failed.', 'error' );
          $btn.prop( 'disabled', false );
        } );
      } );

      $( document ).on( 'click', '#wab-test-images', function () {
        App.say( '#wab-gen-result', 'Checking your media library…', 'info' );

        App.post( 'wab_preview_image', { import_id: importId, limit: 5 } ).done( function ( r ) {
          if ( ! r || ! r.success ) { App.say( '#wab-gen-result', WAB.i18n.genericError, 'error' ); return; }

          var h = '<strong>' + r.data.hit_rate + '% of sampled rows matched an existing image</strong> — ' +
                  'revised estimate ' + App.esc( App.money( r.data.estimate ) ) + ' per page.' +
                  '<ul class="wab-preview-list">';

          ( r.data.previews || [] ).forEach( function ( p ) {
            h += '<li>';
            h += p.matched
              ? '<img src="' + App.esc( p.url ) + '" alt="" width="44" height="44">'
              : '<span class="wab-miss">—</span>';
            h += '<span><strong>' + App.esc( p.row ) + '</strong><br>' +
                 App.esc( p.matched ? p.title : p.reason ) + '</span></li>';
          } );

          App.say( '#wab-gen-result', h + '</ul>', 'ok' );
          $( '#wab-estimate' ).data( 'per', r.data.estimate );
          App.refreshSelection();
        } );
      } );

      this.refreshSelection();
    },

    refreshSelection: function () {
      var n = Object.keys( this.s.selected ).length,
          avail = $( '.wab-row-check:not(:disabled)' ).length,
          per = Number( $( '#wab-estimate' ).data( 'per' ) ) || 0;

      $( '#wab-sel-count' ).text( n ? ( n + ' selected' ) : 'Select all on this page' );

      $( '#wab-generate' )
        .prop( 'disabled', n === 0 )
        .text( n ? ( 'Generate ' + n + ' selected' ) : 'Generate selected' );

      // Show the projected spend next to the button, before it is spent.
      $( '#wab-estimate' ).text( n ? ( '≈ ' + this.money( n * per ) ) : '' );

      var all = $( '#wab-select-all' )[ 0 ];
      if ( all ) {
        all.checked = ( n > 0 && n === avail );
        all.indeterminate = ( n > 0 && n < avail );
      }
    },

    // ===========================================================
    // Queue page
    // ===========================================================
    bindQueue: function () {
      $( document ).on( 'click', '.wab-chip[data-status]', function () {
        $( '.wab-chip[data-status]' ).removeClass( 'is-active' );
        $( this ).addClass( 'is-active' );
        App.s.jobStatus = $( this ).data( 'status' );
        App.s.jobPage = 1;
        App.loadJobs();
      } );

      $( document ).on( 'click', '#wab-run-now', function () {
        var $b = $( this ).prop( 'disabled', true ).text( 'Running…' );
        App.post( 'wab_run_now' ).always( function () {
          $b.prop( 'disabled', false ).text( 'Run now' );
          App.loadJobs();
        } );
      } );

      $( document ).on( 'click', '#wab-drain', function () {
        if ( ! window.confirm( WAB.i18n.confirmDrain ) ) { return; }
        App.post( 'wab_drain' ).done( function () { App.loadJobs(); } );
      } );

      $( document ).on( 'click', '.wab-retry',  function () { App.post( 'wab_retry',  { job_id: $( this ).data( 'job' ) } ).done( function () { App.loadJobs(); } ); } );
      $( document ).on( 'click', '.wab-cancel', function () { App.post( 'wab_cancel', { job_id: $( this ).data( 'job' ) } ).done( function () { App.loadJobs(); } ); } );

      $( document ).on( 'click', '.wab-job-page', function () {
        App.s.jobPage = parseInt( $( this ).data( 'page' ), 10 ) || 1;
        App.loadJobs();
      } );
    },

    loadJobs: function ( quiet ) {
      if ( ! quiet ) { $( '#wab-jobs' ).html( '<p class="wab-muted">Loading…</p>' ); }

      this.post( 'wab_jobs', { status: this.s.jobStatus, page: this.s.jobPage } ).done( function ( r ) {
        if ( ! r || ! r.success ) { return; }

        // Keep the header tiles honest while we are here.
        App.post( 'wab_status' ).done( function ( s ) {
          if ( ! s || ! s.success ) { return; }
          var c = s.data.counts;
          $( '#wab-queued' ).text( c.queued );
          $( '#wab-processing' ).text( c.processing );
          $( '#wab-done' ).text( c.done );
          $( '#wab-failed' ).text( c.failed );
        } );

        var jobs = r.data.jobs || [];
        if ( ! jobs.length ) {
          $( '#wab-jobs' ).html(
            '<p class="wab-empty">Nothing here. Open a sheet, tick some rows, and press ' +
            '<strong>Generate</strong>.<br><a href="' + App.esc( WAB.sheetsUrl ) + '">Go to Sheets →</a></p>'
          );
          return;
        }

        var h = '<table class="wab-table"><thead><tr>' +
                '<th>#</th><th>Result</th><th>State</th><th>Tries</th><th>Cost</th><th>Detail</th><th></th>' +
                '</tr></thead><tbody>';

        jobs.forEach( function ( j ) {
          h += '<tr><td class="wab-muted">' + App.esc( j.row_index ) + '</td><td>';
          if ( j.result_post_id && j.edit_url ) {
            h += '<a href="' + App.esc( j.edit_url ) + '"><strong>' +
                 App.esc( j.title || ( '#' + j.result_post_id ) ) + '</strong></a>';
            if ( j.view_url ) { h += ' <a href="' + App.esc( j.view_url ) + '" target="_blank" rel="noopener" title="View">↗</a>'; }
          } else { h += '<span class="wab-muted">—</span>'; }
          h += '</td>';

          h += '<td><span class="wab-pill wab-pill-' + App.esc( j.status ) + '">' + App.esc( j.status ) + '</span></td>';
          h += '<td class="wab-muted">' + App.esc( j.attempts ) + '</td>';
          h += '<td>' + App.esc( App.money( j.cost_usd ) ) + '</td>';
          h += '<td class="wab-detail">' + App.esc( j.error_message || '' ) + '</td><td>';

          if ( j.status === 'failed' || j.status === 'cancelled' ) {
            h += '<button class="button-link wab-retry" data-job="' + App.esc( j.job_id ) + '">Retry</button>';
          } else if ( j.status === 'queued' ) {
            h += '<button class="button-link wab-cancel" data-job="' + App.esc( j.job_id ) + '">Cancel</button>';
          }
          h += '</td></tr>';
        } );

        h += '</tbody></table>';

        var pages = Math.ceil( r.data.total / r.data.per_page );
        if ( pages > 1 ) {
          h += '<div class="wab-pager">';
          for ( var p = 1; p <= Math.min( pages, 30 ); p++ ) {
            h += '<button class="wab-page wab-job-page' + ( p === r.data.page ? ' is-active' : '' ) +
                 '" data-page="' + p + '">' + p + '</button>';
          }
          h += '</div>';
        }

        $( '#wab-jobs' ).html( h );
      } );
    },

    // ===========================================================
    // Import page
    // ===========================================================
    bindImport: function () {
      $( '#wab-mode' ).on( 'change', function () {
        var w = $( this ).find( ':selected' ).data( 'words' );
        $( '#wab-mode-note' ).text( w ? ( 'Target ' + w + ' words, with a hard minimum enforced in the prompt.' ) : '' );
      } ).trigger( 'change' );

      $( '#wab-file' ).on( 'change', function () {
        var file = this.files && this.files[ 0 ];
        if ( ! file ) { return; }

        var fd = new FormData();
        fd.append( 'action', 'wab_upload' );
        fd.append( 'nonce', WAB.nonce );
        fd.append( 'file', file );

        App.say( '#wab-upload-status', 'Reading ' + App.esc( file.name ) + '…', 'info' );
        $( '#wab-dropzone' ).addClass( 'is-busy' );

        $.ajax( { url: WAB.ajax, method: 'POST', data: fd, processData: false, contentType: false } )
          .done( function ( r ) {
            $( '#wab-dropzone' ).removeClass( 'is-busy' );
            if ( ! r || ! r.success ) {
              App.say( '#wab-upload-status', App.esc( ( r && r.data && r.data.message ) || WAB.i18n.genericError ), 'error' );
              return;
            }
            App.s.uploadKey = r.data.key;
            App.say( '#wab-upload-status',
              '<strong>' + r.data.total_rows + ' rows</strong> found in ' + App.esc( file.name ) + '.', 'ok' );
            App.renderMapper( r.data );
            $( '#wab-h-map' ).removeClass( 'wab-h-muted' );
            $( '#wab-dropzone' ).addClass( 'is-done' );
            $( '#wab-commit' ).prop( 'disabled', false );
          } )
          .fail( function () {
            $( '#wab-dropzone' ).removeClass( 'is-busy' );
            App.say( '#wab-upload-status', 'Upload failed. The file may be too large for this server.', 'error' );
          } );
      } );

      $( '#wab-commit' ).on( 'click', function () {
        var map = {};
        $( '#wab-mapper select' ).each( function () {
          var f = $( this ).data( 'field' );
          if ( f && this.value ) { map[ f ] = this.value; }
        } );

        var $btn = $( this ).prop( 'disabled', true ).text( 'Importing…' );

        App.post( 'wab_commit', {
          key: App.s.uploadKey,
          column_map: map,
          post_type: $( '#wab-post-type' ).val(),
          content_mode: $( '#wab-mode' ).val(),
          image_source: $( '#wab-image-source' ).val(),
          generation_mode: $( '#wab-generation-mode' ).val(),
          target_words: $( '#wab-target-words' ).val() || 0
        } ).done( function ( r ) {
          if ( ! r || ! r.success ) {
            App.say( '#wab-upload-status', App.esc( ( r && r.data && r.data.message ) || WAB.i18n.genericError ), 'error' );
            $btn.prop( 'disabled', false ).text( 'Import rows' );
            return;
          }

          // Straight to the sheet, which is where the next real decision happens.
          window.location = WAB.sheetsUrl + '&import_id=' + encodeURIComponent( r.data.import_id );
        } );
      } );
    },

    renderMapper: function ( d ) {
      var h = '<p class="wab-hint">Detected columns are pre-selected. Set anything you do not want to <em>skip</em>.</p>' +
              '<div class="wab-map-grid">';

      $.each( d.fields, function ( field, label ) {
        h += '<div class="wab-map-row"><label>' + App.esc( label ) + '</label>' +
             '<select data-field="' + App.esc( field ) + '"><option value="">— skip —</option>';
        d.headers.forEach( function ( head ) {
          h += '<option value="' + App.esc( head ) + '"' +
               ( d.auto_map[ field ] === head ? ' selected' : '' ) + '>' + App.esc( head ) + '</option>';
        } );
        h += '</select></div>';
      } );

      $( '#wab-mapper' ).html( h + '</div>' );
    },

    // ===========================================================
    // System status — run the worker and report verbatim
    // ===========================================================
    bindSelfTest: function () {
      $( '#wab-selftest' ).on( 'click', function () {
        var $b = $( this ).prop( 'disabled', true ).text( 'Running…' );
        App.say( '#wab-selftest-out', 'Asking the worker to run…', 'info' );

        App.post( 'wab_run_now' ).done( function ( r ) {
          if ( ! r || ! r.success ) { App.say( '#wab-selftest-out', WAB.i18n.genericError, 'error' ); return; }

          var rep = r.data.report || {}, c = r.data.counts || {}, msg;

          // Translate the runner's gate outcome into something actionable.
          switch ( rep.status ) {
            case 'ran':
              msg = '<strong>Worker ran.</strong> Processed ' + ( rep.processed || 0 ) +
                    ', succeeded ' + ( rep.succeeded || 0 ) + ', failed ' + ( rep.failed || 0 ) + '.';
              if ( ! rep.processed ) { msg += ' It found nothing to claim — the queue is empty.'; }
              break;
            case 'idle':       msg = '<strong>Nothing to do.</strong> No rows are queued. Open a sheet, tick rows, press Generate.'; break;
            case 'paused':     msg = '<strong>Queue is paused.</strong> Press Resume in the header.'; break;
            case 'budget':     msg = '<strong>Daily budget reached.</strong> ' + App.esc( rep.message || '' ); break;
            case 'locked':     msg = '<strong>Another worker is already running.</strong> That is expected if a run is in progress.'; break;
            case 'high_load':  msg = '<strong>Server load too high</strong>, so the worker deferred. ' + App.esc( rep.message || '' ); break;
            case 'low_memory': msg = '<strong>Not enough free PHP memory</strong> to start safely. ' + App.esc( rep.message || '' ); break;
            case 'throttled':  msg = '<strong>Throttled</strong> — it ran moments ago. Wait 20 seconds and try again.'; break;
            default:           msg = '<strong>' + App.esc( rep.status || 'unknown' ) + '</strong> ' + App.esc( rep.message || '' );
          }

          msg += '<br><span class="wab-muted">Queue now: ' + ( c.queued || 0 ) + ' waiting, ' +
                 ( c.processing || 0 ) + ' running, ' + ( c.done || 0 ) + ' created, ' + ( c.failed || 0 ) + ' failed.</span>';

          App.say( '#wab-selftest-out', msg, rep.status === 'ran' ? 'ok' : 'info' );
        } ).fail( function () {
          App.say( '#wab-selftest-out', 'The request itself failed — admin-ajax.php may be blocked.', 'error' );
        } ).always( function () {
          $b.prop( 'disabled', false ).text( 'Run one job now' );
        } );
      } );
    },

    // ===========================================================
    // Settings page
    // ===========================================================
    bindSettings: function () {
      var cfg;
      try { cfg = JSON.parse( $( '#wab-settings-state' ).text() ); } catch ( e ) { return; }

      function fill( $sel, models, chosen, $note ) {
        var h = '';
        $.each( models, function ( id, m ) {
          h += '<option value="' + App.esc( id ) + '"' + ( id === chosen ? ' selected' : '' ) + '>' +
               App.esc( m.label ) + '</option>';
        } );
        $sel.html( h );
        function note() { var m = models[ $sel.val() ]; $note.text( m ? m.notes : '' ); }
        $sel.off( 'change.n' ).on( 'change.n', note );
        note();
      }

      function syncText()  { fill( $( '#wab_text_model' ), cfg.text_models[ $( '#wab_text_provider' ).val() ] || {}, cfg.selected.text_model, $( '#wab-text-model-note' ) ); }
      function syncImage() { fill( $( '#wab_fal_model' ), cfg.image_models[ $( '#wab_image_provider' ).val() ] || {}, cfg.selected.fal_model, $( '#wab-image-model-note' ) ); }

      $( '#wab_text_provider' ).on( 'change', syncText );
      $( '#wab_image_provider' ).on( 'change', syncImage );
      syncText(); syncImage();

      $( '#wab-settings-form' ).on( 'submit', function ( e ) {
        e.preventDefault();
        var settings = {};
        $( this ).find( '[name]' ).each( function () {
          if ( this.disabled ) { return; }
          settings[ this.name ] = ( this.type === 'checkbox' ) ? ( this.checked ? '1' : '0' ) : this.value;
        } );

        $( '#wab-save-msg' ).text( 'Saving…' );
        App.post( 'wab_save_settings', { settings: settings } ).done( function ( r ) {
          $( '#wab-save-msg' ).text( ( r && r.data && r.data.message ) || WAB.i18n.genericError );
          $( 'input[type="password"]' ).val( '' );
        } );
      } );

      $( '#wab-export' ).on( 'click', function () {
        App.post( 'wab_export' ).done( function ( r ) {
          if ( ! r || ! r.success ) { return; }
          var b = new Blob( [ JSON.stringify( r.data.config, null, 2 ) ], { type: 'application/json' } ),
              a = document.createElement( 'a' );
          a.href = URL.createObjectURL( b );
          a.download = 'wonder-ai-config.json';
          document.body.appendChild( a ); a.click(); document.body.removeChild( a );
          URL.revokeObjectURL( a.href );
        } );
      } );

      $( '#wab-import' ).on( 'click', function () {
        var j = window.prompt( 'Paste exported configuration JSON:' );
        if ( ! j ) { return; }
        App.post( 'wab_import_config', { config: j } ).done( function ( r ) {
          window.alert( ( r && r.data && r.data.message ) || WAB.i18n.genericError );
          if ( r && r.success ) { location.reload(); }
        } );
      } );
    }
  };

  $( function () { App.init(); } );

} )( jQuery );
