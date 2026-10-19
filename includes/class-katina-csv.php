<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_CSV {

    const IMPORT_COLUMNS = array( 'title', 'slug', 'destination_url', 'redirect_type', 'nofollow', 'sponsored', 'new_window', 'pass_query_str', 'css_classes', 'rel_tags', 'categories', 'tags', 'status' );

    public static function init() {
        add_action( 'admin_post_katina_export_csv', array( __CLASS__, 'export' ) );
        add_action( 'admin_post_katina_import_csv', array( __CLASS__, 'import' ) );
        add_action( 'admin_post_katina_sample_csv', array( __CLASS__, 'sample' ) );
    }

    public static function render_tab() {
        $imported   = isset( $_GET['katina_imported'] ) ? (int) $_GET['katina_imported'] : 0;
        $skipped    = isset( $_GET['katina_skipped'] ) ? (int) $_GET['katina_skipped'] : 0;
        $missing    = isset( $_GET['katina_missing'] ) ? (int) $_GET['katina_missing'] : 0;
        $errors     = isset( $_GET['katina_errors'] ) ? (int) $_GET['katina_errors'] : 0;
        $sample_url = wp_nonce_url( admin_url( 'admin-post.php?action=katina_sample_csv' ), 'katina_sample_csv' );
        ?>
        <?php if ( $imported || $skipped || $missing || $errors ) : ?>
            <div class="notice notice-success"><p>
                <?php
                /* translators: %d: number of links imported */
                printf( esc_html( _n( '%d link imported.', '%d links imported.', (int) $imported, 'katina-link-manager' ) ), (int) $imported );
                ?>
                <?php if ( $skipped ) : ?>
                    <?php
                    /* translators: %d: number of rows skipped (slug existed) */
                    printf( esc_html( _n( ' %d row skipped (slug already exists).', ' %d rows skipped (slug already exists).', (int) $skipped, 'katina-link-manager' ) ), (int) $skipped );
                    ?>
                <?php endif; ?>
                <?php if ( $missing ) : ?>
                    <?php
                    /* translators: %d: number of rows skipped (missing fields) */
                    printf( esc_html( _n( ' %d row skipped (missing title or destination URL).', ' %d rows skipped (missing title or destination URL).', (int) $missing, 'katina-link-manager' ) ), (int) $missing );
                    ?>
                <?php endif; ?>
                <?php if ( $errors ) : ?>
                    <?php
                    /* translators: %d: number of rows that failed to import */
                    printf( esc_html( _n( ' %d row failed.', ' %d rows failed.', (int) $errors, 'katina-link-manager' ) ), (int) $errors );
                    ?>
                <?php endif; ?>
            </p></div>
        <?php endif; ?>

        <h2><?php esc_html_e( 'Export', 'katina-link-manager' ); ?></h2>
        <p><?php esc_html_e( 'Download every link as a CSV file. Excel-safe (UTF-8 with BOM).', 'katina-link-manager' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'katina_export_csv' ); ?>
            <input type="hidden" name="action" value="katina_export_csv" />
            <?php submit_button( __( 'Export CSV', 'katina-link-manager' ), 'secondary' ); ?>
        </form>

        <hr />

        <h2><?php esc_html_e( 'Import', 'katina-link-manager' ); ?></h2>
        <p>
            <?php
            printf(
                /* translators: %s: comma-separated column list */
                esc_html__( 'Upload a CSV with these columns: %s. Required: title, destination_url. Existing slugs are skipped.', 'katina-link-manager' ),
                '<code>' . esc_html( implode( ', ', self::IMPORT_COLUMNS ) ) . '</code>'
            );
            ?>
            <a href="<?php echo esc_url( $sample_url ); ?>"><?php esc_html_e( 'Download sample CSV', 'katina-link-manager' ); ?></a>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field( 'katina_import_csv' ); ?>
            <input type="hidden" name="action" value="katina_import_csv" />
            <input type="file" name="katina_csv_file" accept=".csv" required />
            <?php submit_button( __( 'Import CSV', 'katina-link-manager' ) ); ?>
        </form>
        <?php
    }

    public static function sample() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'katina_sample_csv' );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=katina-link-manager-sample.csv' );

        // Streaming CSV directly to the response; WP_Filesystem has no streaming API.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $output = fopen( 'php://output', 'w' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite( $output, "\xEF\xBB\xBF" );
        fputcsv( $output, self::IMPORT_COLUMNS );
        self::fputcsv_safe( $output, array( 'Example Affiliate', 'example-affiliate', 'https://partner.example.com/?ref=me', '307', 'yes', 'yes', 'yes', 'no', '', '', 'Finance|Investing', 'broker', 'publish' ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $output );
        exit;
    }

    public static function export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'katina_export_csv' );

        $links = get_posts( array(
            'post_type'      => 'katina_link',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=katina-link-manager-export-' . gmdate( 'Y-m-d' ) . '.csv' );

        // Streaming CSV directly to the response; WP_Filesystem has no streaming API.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $output = fopen( 'php://output', 'w' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite( $output, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel renders accents correctly
        fputcsv( $output, self::IMPORT_COLUMNS );

        foreach ( $links as $post ) {
            $link       = new KATINA_Link( $post->ID );
            $categories = wp_get_post_terms( $post->ID, 'katina_category', array( 'fields' => 'names' ) );
            $tags       = wp_get_post_terms( $post->ID, 'katina_tag', array( 'fields' => 'names' ) );

            self::fputcsv_safe( $output, array(
                $post->post_title,
                $post->post_name,
                $link->get_destination_url(),
                get_post_meta( $post->ID, '_katina_redirect_type', true ) ?: 'default',
                get_post_meta( $post->ID, '_katina_nofollow', true ) ?: 'default',
                get_post_meta( $post->ID, '_katina_sponsored', true ) ?: 'default',
                get_post_meta( $post->ID, '_katina_new_window', true ) ?: 'default',
                get_post_meta( $post->ID, '_katina_pass_query_str', true ) ?: 'default',
                $link->get_css_classes(),
                get_post_meta( $post->ID, '_katina_rel_tags', true ),
                implode( '|', $categories ),
                implode( '|', $tags ),
                $post->post_status,
            ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $output );
        exit;
    }

    const MAX_IMPORT_BYTES = 5242880;  // 5 MB
    const MAX_IMPORT_ROWS  = 10000;

    /**
     * Defang spreadsheet formula injection by prefixing dangerous-leading cells
     * with a single quote so Excel/Numbers/LibreOffice treat the cell as text.
     */
    private static function defang_csv_cell( $value ) {
        $str = (string) $value;
        if ( $str !== '' && in_array( $str[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            return "'" . $str;
        }
        return $str;
    }

    private static function fputcsv_safe( $handle, array $row ) {
        fputcsv( $handle, array_map( array( __CLASS__, 'defang_csv_cell' ), $row ) );
    }

    public static function import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'katina_import_csv' );

        if ( ! isset( $_FILES['katina_csv_file'] ) || $_FILES['katina_csv_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_die( 'File upload failed.' );
        }

        $tmp_name = sanitize_text_field( wp_unslash( $_FILES['katina_csv_file']['tmp_name'] ) );

        // Defense against tmp_name substitution attack
        if ( ! is_uploaded_file( $tmp_name ) ) {
            wp_die( 'Invalid upload.' );
        }

        // Size cap
        if ( filesize( $tmp_name ) > self::MAX_IMPORT_BYTES ) {
            wp_die( esc_html( sprintf( 'File too large. Maximum size is %d MB.', self::MAX_IMPORT_BYTES / 1048576 ) ) );
        }

        // MIME validation — wp_check_filetype_and_ext sniffs extension + mime
        $filename  = isset( $_FILES['katina_csv_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['katina_csv_file']['name'] ) ) : '';
        $filetype  = wp_check_filetype_and_ext( $tmp_name, $filename, array( 'csv' => 'text/csv' ) );
        $allowed   = array( 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' );
        $mime_ok   = $filetype['type'] && ( in_array( $filetype['type'], $allowed, true ) || ( $filetype['ext'] === 'csv' ) );
        if ( ! $mime_ok ) {
            wp_die( 'Only CSV files are allowed.' );
        }

        // Reading uploaded CSV row-by-row; WP_Filesystem has no streaming/iteration API.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $file = fopen( $tmp_name, 'r' );
        if ( ! $file ) {
            wp_die( 'Could not read file.' );
        }

        $header = fgetcsv( $file );
        // Strip UTF-8 BOM from the first cell of the header row if present
        if ( is_array( $header ) && isset( $header[0] ) ) {
            $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
        }
        $count   = 0;
        $skipped = 0;
        $missing = 0;
        $errors  = 0;
        $col_map = array_flip( $header );

        $row_num = 0;
        while ( ( $row = fgetcsv( $file ) ) !== false ) {
            // Row count cap
            if ( ++$row_num > self::MAX_IMPORT_ROWS ) {
                $errors++;
                break;
            }
            $title = isset( $col_map['title'] ) ? $row[ $col_map['title'] ] : '';
            $slug  = isset( $col_map['slug'] ) ? $row[ $col_map['slug'] ] : sanitize_title( $title );
            $url   = isset( $col_map['destination_url'] ) ? $row[ $col_map['destination_url'] ] : '';

            if ( ! $title || ! $url ) {
                $missing++;
                continue;
            }

            $existing = KATINA_Link::find_by_slug( $slug );
            if ( $existing ) {
                $skipped++;
                continue;
            }

            $post_id = wp_insert_post( array(
                'post_type'   => 'katina_link',
                'post_title'  => sanitize_text_field( $title ),
                'post_name'   => sanitize_title( $slug ),
                'post_status' => isset( $col_map['status'] ) ? sanitize_text_field( $row[ $col_map['status'] ] ) : 'publish',
            ) );

            if ( is_wp_error( $post_id ) ) {
                $errors++;
                continue;
            }

            $link = new KATINA_Link( $post_id );
            $link->save_meta( array(
                'destination_url' => esc_url_raw( $url ),
                'redirect_type'   => isset( $col_map['redirect_type'] ) ? sanitize_text_field( $row[ $col_map['redirect_type'] ] ) : 'default',
                'nofollow'        => isset( $col_map['nofollow'] ) ? sanitize_text_field( $row[ $col_map['nofollow'] ] ) : 'default',
                'sponsored'       => isset( $col_map['sponsored'] ) ? sanitize_text_field( $row[ $col_map['sponsored'] ] ) : 'default',
                'new_window'      => isset( $col_map['new_window'] ) ? sanitize_text_field( $row[ $col_map['new_window'] ] ) : 'default',
                'pass_query_str'  => isset( $col_map['pass_query_str'] ) ? sanitize_text_field( $row[ $col_map['pass_query_str'] ] ) : 'default',
                'css_classes'     => isset( $col_map['css_classes'] ) ? sanitize_text_field( $row[ $col_map['css_classes'] ] ) : '',
                'rel_tags'        => isset( $col_map['rel_tags'] ) ? sanitize_text_field( $row[ $col_map['rel_tags'] ] ) : '',
            ) );

            if ( isset( $col_map['categories'] ) && ! empty( $row[ $col_map['categories'] ] ) ) {
                $cats = array_map( 'trim', explode( '|', $row[ $col_map['categories'] ] ) );
                $term_ids = array();
                foreach ( $cats as $cat_name ) {
                    $term = get_term_by( 'name', $cat_name, 'katina_category' );
                    if ( ! $term ) {
                        $result = wp_insert_term( $cat_name, 'katina_category' );
                        if ( ! is_wp_error( $result ) ) {
                            $term_ids[] = $result['term_id'];
                        }
                    } else {
                        $term_ids[] = $term->term_id;
                    }
                }
                if ( $term_ids ) {
                    wp_set_post_terms( $post_id, $term_ids, 'katina_category' );
                }
            }

            if ( isset( $col_map['tags'] ) && ! empty( $row[ $col_map['tags'] ] ) ) {
                $tags = array_map( 'trim', explode( '|', $row[ $col_map['tags'] ] ) );
                wp_set_post_terms( $post_id, $tags, 'katina_tag' );
            }

            $count++;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $file );

        wp_redirect( add_query_arg(
            array(
                'post_type'   => 'katina_link',
                'page'        => KATINA_Tools::PAGE_SLUG,
                'tab'         => 'import-export',
                'katina_imported' => $count,
                'katina_skipped'  => $skipped,
                'katina_missing'  => $missing,
                'katina_errors'   => $errors,
            ),
            admin_url( 'edit.php' )
        ) );
        exit;
    }
}
