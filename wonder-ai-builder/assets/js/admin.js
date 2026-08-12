/* global WAB, jQuery */
/**
 * Wonder AI Builder admin.
 *
 * Polling is deliberately light and stops when the tab is hidden: generation runs
 * server-side, so the UI is a viewer, not a driver. Closing the tab must not affect
 * the queue — and must not leave a timer hammering admin-ajax either.
 */
( function ( $ ) {
  'use strict';

  var App = {
    state: { importId: '', uploadKey: '', status: 'all', page: 1, timer: null },

    /**
     * Escape for HTML text and DOUBLE-quoted attributes.
     * Includes the single quote, which v1's helper omitted — harmless there only
     * because no call site used single-quoted attributes, which is a fragile
     * invariant to rely on.
     */
    esc: function ( s ) {
      if ( s === null || s === undefined ) { return ''; }
      return String( s )
        .replace( /&/g, '&amp;' )
        .replace( /</g, '&lt;' )
        .replace( />/g, '&gt;' )
        .replace( /"/g, '&quot;' )
        .replace( /'/g, '&#39;' );
    },

    post: function ( action, data ) {
      return $.post( WAB.ajax, $.extend( { action: action, nonce: WAB.nonce }, data || {} ) );
    },

    money: function ( n ) {
      return '$' + ( Number( n ) || 0 ).toFixed( 4 ).replace( /0+$/, '' ).replace( /\.$/, '' );
    },

    notify: function ( sel, msg, kind ) {
      $( sel ).attr( 'class', 'wab-status wab-status-' + ( kind || 'info' ) ).text( msg );
    },

    // -----------------------------------------------------------
    init: function () {
      this.bindRun();
      this.bindOps();
      this.bindSettings();

      if ( $( '#wab-jobs' ).length ) {
        this.refresh();
        this.startPolling();
        // Pause polling while the tab is hidden; resume on return.
        document.addEventListener( 'visibilitychange', function () {
          if ( document.hidden ) { App.stopPolling(); } else { App.refresh(); App.startPolling(); }
        } );
      }
    },

    startPolling: function () {
      this.stopPolling();
      this.state.timer = window.setInterval( function () { App.refresh(); }, 8000 );
    },

    stopPolling: function () {
      if ( this.state.timer ) { window.clearInterval( this.state.timer ); this.state.timer = null; }
    },

    // -----------------------------------------------------------
    // New run
    // -----------------------------------------------------------
    bindRun: function () {
      // Page / Post switch
      $( document ).on( 'click', '.wab-switch-opt', function () {
        $( '.wab-switch-opt' ).removeClass( 'is-active' ).attr( 'aria-checked', 'false' );
        $( this ).addClass( 'is-active' ).attr( 'aria-checked', 'true' );
      } );

      $( document ).on( 'change', '#wab-mode', function () {
        var note = $( this ).find( ':selected' ).data( 'words' );
        $( '#wab-mode-note' ).text( note ? ( '~' + note + ' words per page.' ) : '' );
      } );

      $( document ).on( 'change', '#wab-file', function () {
        var file = this.files && this.files[ 0 ];
        if ( ! file ) { return; }

        var fd = new FormData();
        fd.append( 'action', 'wab_upload' );
        fd.append( 'nonce', WAB.nonce );
        fd.append( 'file', file );

        App.notify( '#wab-upload-status', 'Reading ' + file.name + '…', 'info' );

        $.ajax( {
          url: WAB.ajax, method: 'POST', data: fd, processData: false, contentType: false
        } ).done( function ( r ) {
          if ( ! r || ! r.success ) {
            App.notify( '#wab-upload-status', ( r && r.data && r.data.message ) || WAB.i18n.genericError, 'error' );
            return;
          }
          App.state.uploadKey = r.data.key;
          App.notify( '#wab-upload-status', 'Found ' + r.data.total_rows + ' rows.', 'ok' );
          App.renderMapper( r.data );
          $( '#wab-commit' ).prop( 'disabled', false );
        } ).fail( function () {
          App.notify( '#wab-upload-status', 'Upload failed.', 'error' );
        } );
      } );

      $( document ).on( 'click', '#wab-commit', function () {
        var map = {};
        $( '#wab-mapper select' ).each( function () {
          var f = $( this ).data( 'field' );
          if ( f && this.value ) { map[ f ] = this.value; }
        } );

        $( this ).prop( 'disabled', true );

        App.post( 'wab_commit', {
          key: App.state.uploadKey,
          column_map: map,
          post_type: $( '.wab-switch-opt.is-active' ).data( 'post-type' ) || 'page',
          content_mode: $( '#wab-mode' ).val(),
          image_source: $( '#wab-image-source' ).val(),
          generation_mode: $( '#wab-generation-mode' ).val()
        } ).done( function ( r ) {
          if ( ! r || ! r.success ) {
            App.notify( '#wab-upload-status', ( r && r.data && r.data.message ) || WAB.i18n.genericError, 'error' );
            $( '#wab-commit' ).prop( 'disabled', false );
            return;
          }
          App.state.importId = r.data.import_id;
          App.notify( '#wab-upload-status', r.data.message + ' Estimated cost ' + App.money( r.data.estimate ) + '.', 'ok' );
          $( '#wab-preview-images' ).prop( 'hidden', false );
          $( '#wab-commit' ).text( 'Queue generation' ).prop( 'disabled', false ).data( 'stage', 'queue' );
          App.refresh();
        } );
      } );

      // Second press queues.
      $( document ).on( 'click', '#wab-commit[data-stage="queue"]', function ( e ) {
        e.stopImmediatePropagation();
        var $btn = $( this ).prop( 'disabled', true );

        App.post( 'wab_queue', { import_id: App.state.importId } ).done( function ( r ) {
          if ( ! r || ! r.success ) {
            App.notify( '#wab-upload-status', ( r && r.data && r.data.message ) || WAB.i18n.genericError, 'error' );
            $btn.prop( 'disabled', false );
            return;
          }
          App.notify( '#wab-upload-status', r.data.message, 'ok' );
          App.refresh();
        } );
      } );

      $( document ).on( 'click', '#wab-preview-images', function () {
        App.notify( '#wab-preview-result', 'Testing library matches…', 'info' );

        App.post( 'wab_preview_image', { import_id: App.state.importId, limit: 5 } ).done( function ( r ) {
          if ( ! r || ! r.success ) { App.notify( '#wab-preview-result', WAB.i18n.genericError, 'error' ); return; }

          var html = '<p><strong>' + r.data.hit_rate + '% matched</strong> from your existing library — ' +
                     'revised estimate ' + App.esc( App.money( r.data.estimate ) ) + ' per page.</p><ul class="wab-preview-list">';

          $.each( r.data.previews, function ( i, p ) {
            html += '<li>';
            if ( p.matched ) {
              html += '<img src="' + App.esc( p.url ) + '" alt="" width="48" height="48">';
              html += '<span><strong>' + App.esc( p.row ) + '</strong><br>' + App.esc( p.title ) + '</span>';
            } else {
              html += '<span class="wab-miss">✕</span><span><strong>' + App.esc( p.row ) + '</strong><br>' + App.esc( p.reason ) + '</span>';
            }
            html += '</li>';
          } );

          $( '#wab-preview-result' ).attr( 'class', 'wab-status wab-status-ok' ).html( html + '</ul>' );
        } );
      } );
    },

    renderMapper: function ( data ) {
      var html = '<p class="wab-hint">Confirm column mapping. Auto-detected values are pre-selected.</p><div class="wab-map-grid">';

      $.each( data.fields, function ( field, label ) {
        html += '<div class="wab-map-row"><label>' + App.esc( label ) + '</label><select data-field="' + App.esc( field ) + '">';
        html += '<option value="">— skip —</option>';

        $.each( data.headers, function ( i, h ) {
          var sel = ( data.auto_map[ field ] === h ) ? ' selected' : '';
          html += '<option value="' + App.esc( h ) + '"' + sel + '>' + App.esc( h ) + '</option>';
        } );

        html += '</select></div>';
      } );

      $( '#wab-mapper' ).html( html + '</div>' ).prop( 'hidden', false );
    },

    // -----------------------------------------------------------
    // Ops
    // -----------------------------------------------------------
    bindOps: function () {
      $( document ).on( 'click', '#wab-pause',  function () { App.post( 'wab_pause'  ).done( function () { location.reload(); } ); } );
      $( document ).on( 'click', '#wab-resume', function () { App.post( 'wab_resume' ).done( function () { location.reload(); } ); } );

      $( document ).on( 'click', '#wab-run-now', function () {
        var $b = $( this ).prop( 'disabled', true ).text( 'Running…' );
        App.post( 'wab_run_now' ).always( function () {
          $b.prop( 'disabled', false ).text( 'Run now' );
          App.refresh();
        } );
      } );

      $( document ).on( 'click', '#wab-drain', function () {
        if ( ! window.confirm( WAB.i18n.confirmDrain ) ) { return; }
        App.post( 'wab_drain' ).done( function () { App.refresh(); } );
      } );

      $( document ).on( 'click', '.wab-chip[data-status]', function () {
        $( '.wab-chip[data-status]' ).removeClass( 'is-active' );
        $( this ).addClass( 'is-active' );
        App.state.status = $( this ).data( 'status' );
        App.state.page = 1;
        App.loadJobs();
      } );

      $( document ).on( 'click', '.wab-retry', function () {
        App.post( 'wab_retry', { job_id: $( this ).data( 'job' ) } ).done( function () { App.loadJobs(); } );
      } );

      $( document ).on( 'click', '.wab-cancel', function () {
        App.post( 'wab_cancel', { job_id: $( this ).data( 'job' ) } ).done( function () { App.loadJobs(); } );
      } );

      $( document ).on( 'click', '.wab-import-pick', function () {
        App.state.importId = $( this ).data( 'import' );
        App.state.page = 1;
        App.loadJobs();
      } );
    },

    refresh: function () {
      this.post( 'wab_status', { import_id: this.state.importId } ).done( function ( r ) {
        if ( ! r || ! r.success ) { return; }
        var c = r.data.counts;
        $( '#wab-queued' ).text( c.queued );
        $( '#wab-processing' ).text( c.processing );
        $( '#wab-done' ).text( c.done );
        $( '#wab-failed' ).text( c.failed );
        $( '#wab-per-item' ).text( App.money( r.data.estimate ) );
      } );

      this.loadImports();
      this.loadJobs();
    },

    loadImports: function () {
      this.post( 'wab_imports' ).done( function ( r ) {
        if ( ! r || ! r.success ) { return; }
        if ( ! r.data.imports || ! r.data.imports.length ) {
          $( '#wab-imports' ).html( '<p class="wab-muted">No imports yet.</p>' );
          return;
        }

        var html = '';
        $.each( r.data.imports, function ( i, imp ) {
          var c = imp.counts, total = c.total || imp.total_rows;
          var pct = total ? Math.round( ( c.done / total ) * 100 ) : 0;

          html += '<div class="wab-import' + ( imp.import_id === App.state.importId ? ' is-active' : '' ) + '">';
          html += '<button class="wab-import-pick" data-import="' + App.esc( imp.import_id ) + '">';
          html += '<strong>' + App.esc( imp.filename ) + '</strong>';
          html += '<span class="wab-badge">' + App.esc( imp.post_type ) + '</span>';
          html += '<span class="wab-badge">' + App.esc( imp.content_mode ) + '</span>';
          html += '<div class="wab-bar"><span style="width:' + pct + '%"></span></div>';
          html += '<small>' + c.done + ' / ' + total + ' done';
          if ( c.failed ) { html += ' · ' + c.failed + ' failed'; }
          html += '</small></button></div>';
        } );

        $( '#wab-imports' ).html( html );
      } );
    },

    loadJobs: function () {
      this.post( 'wab_jobs', {
        import_id: this.state.importId, status: this.state.status, page: this.state.page
      } ).done( function ( r ) {
        if ( ! r || ! r.success ) { return; }

        if ( ! r.data.jobs.length ) {
          $( '#wab-jobs' ).html( '<p class="wab-muted">Nothing here.</p>' );
          return;
        }

        var html = '<table class="wab-table"><thead><tr>' +
          '<th>#</th><th>Result</th><th>Status</th><th>Tries</th><th>Cost</th><th>Detail</th><th></th>' +
          '</tr></thead><tbody>';

        $.each( r.data.jobs, function ( i, j ) {
          html += '<tr>';
          html += '<td>' + App.esc( j.row_index ) + '</td>';

          html += '<td>';
          if ( j.result_post_id && j.edit_url ) {
            html += '<a href="' + App.esc( j.edit_url ) + '">' + App.esc( j.title || ( '#' + j.result_post_id ) ) + '</a>';
            if ( j.view_url ) { html += ' <a href="' + App.esc( j.view_url ) + '" target="_blank" rel="noopener">↗</a>'; }
          } else {
            html += '<span class="wab-muted">—</span>';
          }
          html += '</td>';

          html += '<td><span class="wab-pill wab-pill-' + App.esc( j.status ) + '">' + App.esc( j.status ) + '</span></td>';
          html += '<td>' + App.esc( j.attempts ) + '</td>';
          html += '<td>' + App.esc( App.money( j.cost_usd ) ) + '</td>';
          html += '<td class="wab-detail">' + App.esc( j.error_message || '' ) + '</td>';

          html += '<td>';
          if ( j.status === 'failed' || j.status === 'cancelled' ) {
            html += '<button class="button-link wab-retry" data-job="' + App.esc( j.job_id ) + '">Retry</button>';
          } else if ( j.status === 'queued' ) {
            html += '<button class="button-link wab-cancel" data-job="' + App.esc( j.job_id ) + '">Cancel</button>';
          }
          html += '</td></tr>';
        } );

        html += '</tbody></table>';

        var pages = Math.ceil( r.data.total / r.data.per_page );
        if ( pages > 1 ) {
          html += '<div class="wab-pager">';
          for ( var p = 1; p <= Math.min( pages, 20 ); p++ ) {
            html += '<button class="wab-page' + ( p === r.data.page ? ' is-active' : '' ) + '" data-page="' + p + '">' + p + '</button>';
          }
          html += '</div>';
        }

        $( '#wab-jobs' ).html( html );
      } );
    },

    // -----------------------------------------------------------
    // Settings
    // -----------------------------------------------------------
    bindSettings: function () {
      var $state = $( '#wab-settings-state' );
      if ( ! $state.length ) { return; }

      var cfg;
      try { cfg = JSON.parse( $state.text() ); } catch ( e ) { return; }

      function fillModels( $select, models, selected, $note ) {
        var html = '';
        $.each( models, function ( id, m ) {
          html += '<option value="' + App.esc( id ) + '"' + ( id === selected ? ' selected' : '' ) + '>' +
                  App.esc( m.label ) + '</option>';
        } );
        $select.html( html );

        function note() {
          var m = models[ $select.val() ];
          $note.text( m ? m.notes : '' );
        }
        $select.off( 'change.note' ).on( 'change.note', note );
        note();
      }

      function syncText() {
        var p = $( '#wab_text_provider' ).val();
        fillModels( $( '#wab_text_model' ), cfg.text_models[ p ] || {}, cfg.selected.text_model, $( '#wab-text-model-note' ) );
      }

      function syncImage() {
        var p = $( '#wab_image_provider' ).val();
        fillModels( $( '#wab_fal_model' ), cfg.image_models[ p ] || {}, cfg.selected.fal_model, $( '#wab-image-model-note' ) );
      }

      $( '#wab_text_provider' ).on( 'change', syncText );
      $( '#wab_image_provider' ).on( 'change', syncImage );
      syncText();
      syncImage();

      $( '#wab-settings-form' ).on( 'submit', function ( e ) {
        e.preventDefault();

        var settings = {};
        $( this ).find( '[name]' ).each( function () {
          if ( this.disabled ) { return; } // Constant-backed keys.
          settings[ this.name ] = ( this.type === 'checkbox' ) ? ( this.checked ? '1' : '0' ) : this.value;
        } );

        $( '#wab-save-msg' ).text( 'Saving…' );

        App.post( 'wab_save_settings', { settings: settings } ).done( function ( r ) {
          if ( r && r.success ) {
            $( '#wab-save-msg' ).text( r.data.message );
            // Clear key fields so a mask is never resubmitted as a value.
            $( 'input[type="password"]' ).val( '' );
          } else {
            $( '#wab-save-msg' ).text( ( r && r.data && r.data.message ) || WAB.i18n.genericError );
          }
        } );
      } );

      $( '#wab-export' ).on( 'click', function () {
        App.post( 'wab_export' ).done( function ( r ) {
          if ( ! r || ! r.success ) { return; }
          var blob = new Blob( [ JSON.stringify( r.data.config, null, 2 ) ], { type: 'application/json' } );
          var a = document.createElement( 'a' );
          a.href = URL.createObjectURL( blob );
          a.download = 'wonder-ai-config.json';
          document.body.appendChild( a );
          a.click();
          document.body.removeChild( a );
          URL.revokeObjectURL( a.href );
        } );
      } );

      $( '#wab-import' ).on( 'click', function () {
        var json = window.prompt( 'Paste exported configuration JSON:' );
        if ( ! json ) { return; }
        App.post( 'wab_import_config', { config: json } ).done( function ( r ) {
          window.alert( ( r && r.data && r.data.message ) || WAB.i18n.genericError );
          if ( r && r.success ) { location.reload(); }
        } );
      } );
    }
  };

  $( document ).on( 'click', '.wab-page', function () {
    App.state.page = parseInt( $( this ).data( 'page' ), 10 ) || 1;
    App.loadJobs();
  } );

  $( function () { App.init(); } );

} )( jQuery );
