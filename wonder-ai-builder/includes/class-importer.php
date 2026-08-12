<?php
/**
 * CSV / XLSX import.
 *
 * FIXES vs v1:
 *  - is_uploaded_file() is verified; v1 used $_FILES['file']['tmp_name'] directly.
 *  - Extension is corroborated with wp_check_filetype(), not trusted from the
 *    client-supplied filename.
 *  - The Schema column is MAPPED AND STORED and actually used (v1 imported it and
 *    silently discarded it).
 *  - Unmapped columns are NO LONGER dumped into the prompt. v1 JSON-encoded every
 *    unmapped column into extra_data and injected it as "Extra Details:", so a
 *    JSON-LD blob in an unmapped column was billed as input on every row. extra_data
 *    is retained for reference but never sent to a model.
 *  - row_index is dense and unique per import, which UNIQUE(import_id,row_index)
 *    requires and WAB_Queue::enqueue() depends on.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WAB_Importer {

    /** Canonical fields. Keys match wab_rows columns. */
    public static function fields() {
        return array(
            'topic'          => __( 'Topic', 'wonder-ai-builder' ),
            'title'          => __( 'Title (override)', 'wonder-ai-builder' ),
            'services'       => __( 'Service', 'wonder-ai-builder' ),
            'location'       => __( 'Location', 'wonder-ai-builder' ),
            'keywords'       => __( 'Keywords', 'wonder-ai-builder' ),
            'company'        => __( 'Company', 'wonder-ai-builder' ),
            'phone'          => __( 'Phone', 'wonder-ai-builder' ),
            'street'         => __( 'Street address', 'wonder-ai-builder' ),
            'region'         => __( 'Region / Emirate', 'wonder-ai-builder' ),
            'postcode'       => __( 'Postcode', 'wonder-ai-builder' ),
            'price'          => __( 'Price', 'wonder-ai-builder' ),
            'post_type'      => __( 'Post type (page/post)', 'wonder-ai-builder' ),
            'category'       => __( 'Category', 'wonder-ai-builder' ),
            'internal_link'  => __( 'Internal link hint', 'wonder-ai-builder' ),
            'scheduled_date' => __( 'Scheduled date', 'wonder-ai-builder' ),
            'description'    => __( 'Brief / description', 'wonder-ai-builder' ),
            'image_rules'    => __( 'Image rules', 'wonder-ai-builder' ),
            'schema_markup'  => __( 'Schema (raw JSON-LD)', 'wonder-ai-builder' ),
            'schema_type'    => __( 'Schema type', 'wonder-ai-builder' ),
        );
    }

    // ---------------------------------------------------------------
    // AJAX: upload + preview
    // ---------------------------------------------------------------

    public static function ajax_upload() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );

        if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'wonder-ai-builder' ) ) );
        }

        $file = $_FILES['file'];

        if ( ! empty( $file['error'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Upload failed. The file may exceed the server limit.', 'wonder-ai-builder' ) ) );
        }

        // v1 never verified this. Without it, a crafted request can point tmp_name at
        // an arbitrary server path and have it parsed.
        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid upload.', 'wonder-ai-builder' ) ) );
        }

        $checked = wp_check_filetype( $file['name'], array(
            'csv'  => 'text/csv',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ) );

        $ext = strtolower( (string) $checked['ext'] );
        if ( ! in_array( $ext, array( 'csv', 'xlsx' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Only .csv and .xlsx files are supported.', 'wonder-ai-builder' ) ) );
        }

        $parsed = ( $ext === 'csv' )
            ? self::parse_csv( $file['tmp_name'] )
            : self::parse_xlsx( $file['tmp_name'] );

        if ( is_wp_error( $parsed ) ) {
            wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
        }

        if ( empty( $parsed['headers'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not read a header row.', 'wonder-ai-builder' ) ) );
        }

        $key = 'wab_up_' . wp_generate_password( 12, false );
        set_transient( $key, $parsed, HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'key'        => $key,
            'headers'    => $parsed['headers'],
            'total_rows' => count( $parsed['rows'] ),
            'preview'    => array_slice( $parsed['rows'], 0, 5 ),
            'auto_map'   => self::auto_map( $parsed['headers'] ),
            'fields'     => self::fields(),
        ) );
    }

    // ---------------------------------------------------------------
    // AJAX: commit
    // ---------------------------------------------------------------

    public static function ajax_commit() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );

        global $wpdb;

        $key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        if ( $key === '' || strpos( $key, 'wab_up_' ) !== 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid upload reference.', 'wonder-ai-builder' ) ) );
        }

        $parsed = get_transient( $key );
        if ( ! is_array( $parsed ) ) {
            wp_send_json_error( array( 'message' => __( 'Upload expired. Please re-upload the file.', 'wonder-ai-builder' ) ) );
        }

        // Sanitize the map to KNOWN field keys only, so nothing arbitrary can reach
        // a column name. v1 accepted $_POST['column_map'] as a raw array.
        $raw_map = isset( $_POST['column_map'] ) ? (array) wp_unslash( $_POST['column_map'] ) : array();
        $allowed = self::fields();
        $map     = array();

        foreach ( $raw_map as $field => $column ) {
            $field = sanitize_key( $field );
            if ( ! isset( $allowed[ $field ] ) ) continue;
            $map[ $field ] = sanitize_text_field( (string) $column );
        }

        $post_type    = ( isset( $_POST['post_type'] ) && $_POST['post_type'] === 'post' ) ? 'post' : 'page';
        $content_mode = isset( $_POST['content_mode'] ) ? sanitize_key( wp_unslash( $_POST['content_mode'] ) ) : 'hybrid';
        if ( ! array_key_exists( $content_mode, WAB_Prompt_Builder::modes() ) ) {
            $content_mode = WAB_Prompt_Builder::MODE_HYBRID;
        }

        // Generation mode is a global setting, but the operator picks it per run, so
        // persist their choice here rather than making them visit Settings.
        if ( isset( $_POST['generation_mode'] ) ) {
            $gm = sanitize_key( wp_unslash( $_POST['generation_mode'] ) );
            if ( in_array( $gm, array( 'standard', 'economy' ), true ) ) {
                update_option( 'wab_generation_mode', $gm );
            }
        }

        $image_source = isset( $_POST['image_source'] ) ? sanitize_key( wp_unslash( $_POST['image_source'] ) ) : 'library_then_ai';
        if ( ! in_array( $image_source, array( 'library_only', 'library_then_ai', 'ai_only', 'none' ), true ) ) {
            $image_source = 'library_then_ai';
        }

        $import_id = 'imp_' . wp_generate_password( 16, false );

        $wpdb->insert( $wpdb->prefix . 'wab_imports', array(
            'import_id'    => $import_id,
            'filename'     => sanitize_file_name( (string) ( $parsed['filename'] ?? 'import' ) ),
            'total_rows'   => count( $parsed['rows'] ),
            'column_map'   => wp_json_encode( $map ),
            'post_type'    => $post_type,
            'content_mode' => $content_mode,
            'image_source' => $image_source,
            'created_by'   => get_current_user_id(),
            'created_at'   => current_time( 'mysql' ),
        ) );

        $inserted = 0;
        $index    = 0; // Dense and gap-free — required by UNIQUE(import_id,row_index).

        foreach ( $parsed['rows'] as $csv_row ) {
            $data = array(
                'import_id'  => $import_id,
                'row_index'  => $index,
                'created_at' => current_time( 'mysql' ),
            );

            foreach ( $allowed as $field => $label ) {
                $column = $map[ $field ] ?? '';
                $value  = ( $column !== '' && isset( $csv_row[ $column ] ) ) ? (string) $csv_row[ $column ] : '';

                // schema_markup holds raw JSON-LD and must NOT be run through
                // sanitize_textarea_field, which would mangle quotes and braces.
                $data[ $field ] = ( $field === 'schema_markup' )
                    ? trim( $value )
                    : sanitize_textarea_field( $value );
            }

            // Derive a title when none was supplied.
            if ( $data['title'] === '' ) {
                if ( $data['services'] !== '' && $data['location'] !== '' ) {
                    $data['title'] = $data['services'] . ' in ' . $data['location'];
                } elseif ( $data['services'] !== '' ) {
                    $data['title'] = $data['services'];
                } else {
                    $data['title'] = $data['topic'];
                }
            }

            if ( trim( $data['title'] ) === '' ) continue; // Unusable row.

            // Unmapped columns kept for reference ONLY. Never sent to a model.
            $extra  = array();
            $mapped = array_filter( array_values( $map ) );
            foreach ( $csv_row as $col => $val ) {
                if ( ! in_array( $col, $mapped, true ) && $val !== '' ) {
                    $extra[ $col ] = mb_substr( (string) $val, 0, 500 );
                }
            }
            $data['extra_data'] = $extra ? wp_json_encode( $extra ) : '';

            if ( $wpdb->insert( $wpdb->prefix . 'wab_rows', $data ) !== false ) {
                $inserted++;
                $index++;
            } else {
                WAB_Logger::warn( 'Row insert failed at index ' . $index . ': ' . $wpdb->last_error );
            }
        }

        delete_transient( $key );
        update_option( 'wab_last_import_id', $import_id, false );

        wp_send_json_success( array(
            'import_id' => $import_id,
            'inserted'  => $inserted,
            'estimate'  => round( WAB_Provider_Registry::estimate_item_cost() * $inserted, 4 ),
            'message'   => sprintf(
                /* translators: %d: rows */
                _n( '%d row imported.', '%d rows imported.', $inserted, 'wonder-ai-builder' ),
                $inserted
            ),
        ) );
    }

    // ---------------------------------------------------------------
    // AJAX: queue
    // ---------------------------------------------------------------

    public static function ajax_queue() {
        WAB_Security::guard( WAB_Security::CAP_GENERATE );

        global $wpdb;

        $import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) ) : '';
        if ( $import_id === '' ) {
            wp_send_json_error( array( 'message' => __( 'Missing import.', 'wonder-ai-builder' ) ) );
        }

        // Optional: generate only SPECIFIC rows.
        //
        // This is what makes "I imported 5 sheets and want 2 blog posts out of one of
        // them" possible. Without it the only choice was all-or-nothing per import.
        $row_ids = isset( $_POST['row_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['row_ids'] ) ) : array();
        $row_ids = array_values( array_filter( array_unique( $row_ids ) ) );

        // Optional per-generation post type override, so the same sheet can produce
        // pages for some rows and posts for others.
        $override = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
        if ( ! in_array( $override, array( 'page', 'post' ), true ) ) {
            $override = '';
        }

        if ( ! empty( $row_ids ) ) {
            $ph   = implode( ',', array_fill( 0, count( $row_ids ), '%d' ) );
            $args = array_merge( array( $import_id ), $row_ids );

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, row_index FROM {$wpdb->prefix}wab_rows
                  WHERE import_id = %s AND id IN ({$ph}) ORDER BY row_index ASC",
                $args
            ) );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, row_index FROM {$wpdb->prefix}wab_rows WHERE import_id = %s ORDER BY row_index ASC",
                $import_id
            ) );
        }

        if ( empty( $rows ) ) {
            wp_send_json_error( array( 'message' => __( 'No matching rows found.', 'wonder-ai-builder' ) ) );
        }

        // Apply the override to just these rows. The generator reads row->post_type
        // first, so this takes precedence over the import and global defaults.
        if ( $override !== '' ) {
            $ids  = wp_list_pluck( $rows, 'id' );
            $ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $args = array_merge( array( $override ), array_map( 'intval', $ids ) );

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}wab_rows SET post_type = %s WHERE id IN ({$ph})",
                $args
            ) );
        }

        // Pre-flight budget check so a 100-row run is refused up front rather than
        // stalling at row 63.
        $per_item  = WAB_Provider_Registry::estimate_item_cost();
        $projected = $per_item * count( $rows );
        $budget    = WAB_Cost_Guard::daily_budget();

        if ( $budget > 0 && ( WAB_Cost_Guard::spend_today() + $projected ) > $budget ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    __( 'This run is estimated at $%1$.2f, which would exceed today\'s remaining budget. Raise the daily budget or queue fewer rows.', 'wonder-ai-builder' ),
                    $projected
                ),
            ) );
        }

        $queued = WAB_Queue::enqueue( $import_id, $rows );

        wp_send_json_success( array(
            'queued'    => $queued,
            'estimate'  => round( $projected, 4 ),
            'per_item'  => round( $per_item, 6 ),
            'message'   => sprintf(
                __( '%1$d job(s) queued. Estimated cost $%2$.2f. Generation runs on the server — you can close this tab.', 'wonder-ai-builder' ),
                $queued,
                $projected
            ),
        ) );
    }

    // ---------------------------------------------------------------
    // Parsers
    // ---------------------------------------------------------------

    private static function parse_csv( $path ) {
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) return new WP_Error( 'wab_read_failed', __( 'Could not open the file.', 'wonder-ai-builder' ) );

        // Strip a UTF-8 BOM, which otherwise corrupts the first header name.
        $bom = fread( $handle, 3 );
        if ( $bom !== "\xEF\xBB\xBF" ) rewind( $handle );

        $headers = array();
        $rows    = array();
        $line_no = 0;
        $limit   = (int) apply_filters( 'wab_max_import_rows', 5000 );

        while ( ( $line = fgetcsv( $handle, 0, ',', '"' ) ) !== false ) {
            if ( $line_no === 0 ) {
                $headers = array_map( static function ( $h ) {
                    return trim( (string) $h );
                }, (array) $line );
                $line_no++;
                continue;
            }

            if ( count( $rows ) >= $limit ) break;

            $row   = array();
            $empty = true;
            foreach ( $headers as $i => $h ) {
                if ( $h === '' ) continue;
                $v = isset( $line[ $i ] ) ? trim( (string) $line[ $i ] ) : '';
                if ( $v !== '' ) $empty = false;
                $row[ $h ] = $v;
            }

            if ( ! $empty ) $rows[] = $row;
            $line_no++;
        }

        fclose( $handle );

        return array(
            'headers'  => array_values( array_filter( $headers, static function ( $h ) { return $h !== ''; } ) ),
            'rows'     => $rows,
            'filename' => wp_basename( $path ),
        );
    }

    /**
     * XLSX without PhpSpreadsheet.
     *
     * XLSX is a ZIP of XML. Reading it with ZipArchive avoids a ~40MB Composer
     * dependency on 200 sites, and — importantly — avoids handing an untrusted
     * upload to PhpSpreadsheet's loader, historically an XXE surface. XML entity
     * loading is explicitly disabled below.
     */
    private static function parse_xlsx( $path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'wab_no_zip', __( 'XLSX support needs the PHP zip extension. Please export as CSV instead.', 'wonder-ai-builder' ) );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $path ) !== true ) {
            return new WP_Error( 'wab_bad_xlsx', __( 'Could not read the XLSX file.', 'wonder-ai-builder' ) );
        }

        $shared = array();
        $ss_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( $ss_xml !== false ) {
            $sx = self::load_xml( $ss_xml );
            if ( $sx ) {
                foreach ( $sx->si as $si ) {
                    // Runs (<r>) must be concatenated or styled cells lose text.
                    $text = '';
                    if ( isset( $si->t ) ) {
                        $text = (string) $si->t;
                    } elseif ( isset( $si->r ) ) {
                        foreach ( $si->r as $r ) $text .= (string) $r->t;
                    }
                    $shared[] = $text;
                }
            }
        }

        $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        $zip->close();

        if ( $sheet_xml === false ) {
            return new WP_Error( 'wab_bad_xlsx', __( 'No worksheet found in the XLSX file.', 'wonder-ai-builder' ) );
        }

        $sx = self::load_xml( $sheet_xml );
        if ( ! $sx ) {
            return new WP_Error( 'wab_bad_xlsx', __( 'Could not parse the worksheet XML.', 'wonder-ai-builder' ) );
        }

        $matrix = array();
        foreach ( $sx->sheetData->row as $row ) {
            $cells = array();
            foreach ( $row->c as $c ) {
                $ref  = (string) $c['r'];
                $col  = self::col_index( preg_replace( '/\d/', '', $ref ) );
                $type = (string) $c['t'];
                $val  = '';

                if ( $type === 's' ) {
                    $idx = (int) $c->v;
                    $val = $shared[ $idx ] ?? '';
                } elseif ( $type === 'inlineStr' ) {
                    $val = isset( $c->is->t ) ? (string) $c->is->t : '';
                } elseif ( isset( $c->v ) ) {
                    $val = (string) $c->v;
                }

                $cells[ $col ] = trim( $val );
            }
            $matrix[] = $cells;
        }

        if ( empty( $matrix ) ) return array( 'headers' => array(), 'rows' => array(), 'filename' => wp_basename( $path ) );

        $header_cells = array_shift( $matrix );
        ksort( $header_cells );
        $headers = array_values( array_filter( array_map( 'trim', $header_cells ), static function ( $h ) { return $h !== ''; } ) );

        $rows  = array();
        $limit = (int) apply_filters( 'wab_max_import_rows', 5000 );

        foreach ( $matrix as $cells ) {
            if ( count( $rows ) >= $limit ) break;
            ksort( $cells );
            $values = array_values( $cells );

            $row   = array();
            $empty = true;
            foreach ( $headers as $i => $h ) {
                $v = $values[ $i ] ?? '';
                if ( $v !== '' ) $empty = false;
                $row[ $h ] = $v;
            }
            if ( ! $empty ) $rows[] = $row;
        }

        return array( 'headers' => $headers, 'rows' => $rows, 'filename' => wp_basename( $path ) );
    }

    /**
     * Parse XML with entity loading disabled (XXE mitigation).
     */
    private static function load_xml( $xml ) {
        $prev = libxml_use_internal_errors( true );

        // LIBXML_NONET blocks network fetches; no DTD loading is requested.
        $doc = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT );

        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        return $doc ?: null;
    }

    /** 'A' => 0, 'AB' => 27 */
    private static function col_index( $letters ) {
        $letters = strtoupper( (string) $letters );
        $n = 0;
        $len = strlen( $letters );
        for ( $i = 0; $i < $len; $i++ ) {
            $n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
        }
        return max( 0, $n - 1 );
    }

    private static function auto_map( array $headers ) {
        $patterns = array(
            'topic'          => array( 'topic', 'subject', 'theme' ),
            'title'          => array( 'title', 'headline', 'page title', 'post title' ),
            'services'       => array( 'service', 'services', 'offering' ),
            'location'       => array( 'location', 'city', 'area', 'suburb', 'emirate' ),
            'keywords'       => array( 'keyword', 'keywords', 'kw' ),
            'company'        => array( 'company', 'business', 'brand', 'client' ),
            'phone'          => array( 'phone', 'tel', 'mobile', 'contact number' ),
            'street'         => array( 'street', 'address', 'address line' ),
            'region'         => array( 'region', 'state', 'province' ),
            'postcode'       => array( 'postcode', 'zip', 'postal' ),
            'price'          => array( 'price', 'cost', 'rate' ),
            'post_type'      => array( 'post type', 'type', 'format' ),
            'category'       => array( 'category', 'cat', 'taxonomy' ),
            'internal_link'  => array( 'internal link', 'link hint' ),
            'scheduled_date' => array( 'scheduled', 'publish date', 'date' ),
            'description'    => array( 'description', 'brief', 'summary', 'desc' ),
            'image_rules'    => array( 'image', 'image rules', 'image brief' ),
            'schema_markup'  => array( 'schema', 'json-ld', 'jsonld', 'structured data', 'schema markup' ),
            'schema_type'    => array( 'schema type', 'type of schema' ),
        );

        $map = array();
        foreach ( array_keys( self::fields() ) as $f ) $map[ $f ] = '';

        foreach ( $headers as $header ) {
            $lower = strtolower( trim( (string) $header ) );

            foreach ( $patterns as $field => $aliases ) {
                if ( $map[ $field ] !== '' ) continue;

                foreach ( $aliases as $alias ) {
                    // Exact match wins; substring only when unambiguous.
                    if ( $lower === $alias || ( strlen( $alias ) > 4 && strpos( $lower, $alias ) !== false ) ) {
                        $map[ $field ] = (string) $header;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }
}
