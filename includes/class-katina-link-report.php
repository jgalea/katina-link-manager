<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The Link Index admin screen: per-post link counts, orphan filtering,
 * batched scanning and export.
 */
class KATINA_Link_Report {

    const PAGE_SLUG = 'katina-link-index';
    const PER_PAGE  = 50;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'wp_ajax_katina_scan_batch', array( __CLASS__, 'ajax_scan_batch' ) );
        add_action( 'admin_post_katina_export_index', array( __CLASS__, 'handle_export' ) );
    }

    public static function register_menu() {
        add_submenu_page(
            'edit.php?post_type=katina_link',
            __( 'Link Index', 'katina-link-manager' ),
            __( 'Link Index', 'katina-link-manager' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function enqueue( $hook ) {
        if ( ! isset( $_GET['page'] ) ) {
            return;
        }
        $page = sanitize_key( wp_unslash( $_GET['page'] ) );
        if ( $page !== self::PAGE_SLUG ) {
            return;
        }

        wp_enqueue_script(
            'katina-link-index',
            KATINA_PLUGIN_URL . 'assets/js/link-index.js',
            array(),
            KATINA_VERSION,
            true
        );

        wp_localize_script( 'katina-link-index', 'katinaLinkIndex', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'katina_scan_batch' ),
            'strings' => array(
                /* translators: 1: number of posts scanned so far, 2: total posts to scan */
                'scanning' => __( 'Scanning %1$d of %2$d posts…', 'katina-link-manager' ),
                'done'     => __( 'Scan complete. Reloading…', 'katina-link-manager' ),
                'failed'   => __( 'Scan failed. Check the browser console and try again.', 'katina-link-manager' ),
            ),
        ) );
    }

    // ── Report screen ─────────────────────────────────────────────────────────

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'katina-link-manager' ) );
        }

        $args = self::request_args();

        $report     = KATINA_Link_Index::get_report( $args );
        $rows       = $report['rows'];
        $total      = $report['total'];
        $totals     = KATINA_Link_Index::totals();
        $last_run   = KATINA_Link_Index::last_run();
        $has_index  = KATINA_Link_Index::has_index();
        $post_types = KATINA_Link_Index::scannable_post_types();
        $scannable  = KATINA_Link_Index::count_scannable();

        include KATINA_PLUGIN_DIR . 'views/admin-link-index.php';
    }

    /**
     * Read and validate the report filters off the query string.
     */
    public static function request_args() {
        $post_type = isset( $_GET['post_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['post_type_filter'] ) ) : '';
        if ( $post_type !== '' && ! in_array( $post_type, KATINA_Link_Index::scannable_post_types(), true ) ) {
            $post_type = '';
        }

        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'incoming';
        if ( ! in_array( $orderby, array( 'title', 'incoming', 'outgoing', 'external', 'managed', 'date' ), true ) ) {
            $orderby = 'incoming';
        }

        return array(
            'post_type'    => $post_type,
            'orphans_only' => ! empty( $_GET['orphans_only'] ),
            'search'       => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
            'orderby'      => $orderby,
            'order'        => ( isset( $_GET['order'] ) && strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) === 'DESC' ) ? 'DESC' : 'ASC',
            'per_page'     => self::PER_PAGE,
            'page'         => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
        );
    }

    // ── Scanning ──────────────────────────────────────────────────────────────

    public static function ajax_scan_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'katina-link-manager' ) ), 403 );
        }
        check_ajax_referer( 'katina_scan_batch', 'nonce' );

        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

        // A fresh run clears the old index so deleted links don't linger.
        if ( $offset === 0 ) {
            KATINA_Link_Index::truncate();
        }

        $state = KATINA_Link_Index::scan_batch( $offset );

        wp_send_json_success( $state );
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public static function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'katina-link-manager' ) );
        }
        check_admin_referer( 'katina_export_index' );

        $format = isset( $_GET['format'] ) && sanitize_key( wp_unslash( $_GET['format'] ) ) === 'json' ? 'json' : 'csv';

        $args             = self::request_args();
        $args['per_page'] = 10000;
        $args['page']     = 1;

        $report      = KATINA_Link_Index::get_report( $args );
        $include_seo = ! empty( $_GET['include_seo'] );

        $data = array();
        foreach ( $report['rows'] as $row ) {
            $entry = array(
                'id'                => (int) $row['ID'],
                'title'             => $row['post_title'],
                'url'               => get_permalink( (int) $row['ID'] ),
                'post_type'         => $row['post_type'],
                'status'            => $row['post_status'],
                'incoming_internal' => (int) $row['incoming'],
                'outgoing_internal' => (int) $row['outgoing_internal'],
                'outgoing_external' => (int) $row['outgoing_external'],
                'managed_links'     => (int) $row['outgoing_managed'],
                'orphan'            => ( (int) $row['incoming'] === 0 ) ? 'yes' : 'no',
            );

            if ( $include_seo ) {
                $seo                    = KATINA_Link_Index::get_seo_meta( (int) $row['ID'] );
                $entry['seo_title']       = $seo['title'];
                $entry['seo_description'] = $seo['description'];
            }

            $data[] = $entry;
        }

        $filename = 'katina-link-index-' . gmdate( 'Y-m-d' ) . '.' . $format;

        nocache_headers();

        if ( $format === 'json' ) {
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=' . $filename );
            echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            exit;
        }

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        // Streaming CSV directly to the response; WP_Filesystem has no streaming API.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen( 'php://output', 'w' );
        if ( ! empty( $data ) ) {
            fputcsv( $out, array_keys( $data[0] ) );
            foreach ( $data as $entry ) {
                // Post titles land in cells, so defang anything a spreadsheet
                // would evaluate as a formula.
                fputcsv( $out, array_map( array( __CLASS__, 'defang_csv_cell' ), $entry ) );
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $out );
        exit;
    }

    private static function defang_csv_cell( $value ) {
        $str = (string) $value;
        if ( $str !== '' && in_array( $str[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            return "'" . $str;
        }
        return $str;
    }

    // ── View helpers ──────────────────────────────────────────────────────────

    public static function report_url( array $overrides = array() ) {
        $args = array_merge(
            array(
                'post_type' => 'katina_link',
                'page'      => self::PAGE_SLUG,
            ),
            $overrides
        );
        return add_query_arg( array_filter( $args, function ( $v ) { return $v !== '' && $v !== null; } ), admin_url( 'edit.php' ) );
    }

    public static function sort_link( $column, $label, array $args ) {
        $is_current = $args['orderby'] === $column;
        $next_order = ( $is_current && $args['order'] === 'ASC' ) ? 'DESC' : 'ASC';
        $indicator  = $is_current ? ( $args['order'] === 'ASC' ? ' ▲' : ' ▼' ) : '';

        $url = self::report_url( array(
            'post_type_filter' => $args['post_type'],
            'orphans_only'     => $args['orphans_only'] ? 1 : '',
            's'                => $args['search'],
            'orderby'          => $column,
            'order'            => $next_order,
        ) );

        return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . $indicator . '</a>';
    }
}
