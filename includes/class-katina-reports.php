<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Reports {

    const PAGE_SLUG = 'katina-reports';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 15 );
        add_action( 'admin_post_katina_export_clicks', array( __CLASS__, 'handle_export' ) );
    }

    public static function add_menu() {
        add_submenu_page(
            'edit.php?post_type=katina_link',
            __( 'Katina Link Manager Reports', 'katina-link-manager' ),
            __( 'Reports', 'katina-link-manager' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function get_presets() {
        return array(
            '7'          => __( 'Last 7 days', 'katina-link-manager' ),
            '30'         => __( 'Last 30 days', 'katina-link-manager' ),
            '90'         => __( 'Last 90 days', 'katina-link-manager' ),
            '365'        => __( 'Last 365 days', 'katina-link-manager' ),
            'this_month' => __( 'This month', 'katina-link-manager' ),
            'last_month' => __( 'Last month', 'katina-link-manager' ),
            'ytd'        => __( 'Year to date', 'katina-link-manager' ),
        );
    }

    /**
     * Resolve the active filters from the query string.
     *
     * Dates are handled in the site's timezone so the ranges line up with what
     * the user sees elsewhere in wp-admin, then converted to UTC for the query
     * because clicked_at is stored as UTC.
     */
    public static function get_filters() {
        // Read from $_REQUEST so both the GET page load and the POST-driven CSV
        // export resolve the same filters. The page render is read-only; the
        // export path verifies its nonce in handle_export() before calling this.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $preset = isset( $_REQUEST['preset'] ) ? sanitize_key( wp_unslash( $_REQUEST['preset'] ) ) : '30';
        $presets = self::get_presets();
        if ( ! isset( $presets[ $preset ] ) ) {
            $preset = 'custom';
        }

        $today = current_time( 'Y-m-d' );

        if ( isset( $_REQUEST['from'], $_REQUEST['to'] ) && $_REQUEST['from'] !== '' && $_REQUEST['to'] !== '' ) {
            $from   = self::sanitize_date( wp_unslash( $_REQUEST['from'] ), $today );
            $to     = self::sanitize_date( wp_unslash( $_REQUEST['to'] ), $today );
            $preset = isset( $_REQUEST['preset'] ) && isset( $presets[ $preset ] ) ? $preset : 'custom';
        } else {
            list( $from, $to ) = self::preset_to_range( $preset, $today );
        }

        if ( strtotime( $from ) > strtotime( $to ) ) {
            $swap = $from;
            $from = $to;
            $to   = $swap;
        }

        $link_id = isset( $_REQUEST['link_id'] ) ? absint( $_REQUEST['link_id'] ) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return array(
            'preset'  => $preset,
            'from'    => $from,
            'to'      => $to,
            'link_id' => $link_id,
        );
    }

    private static function sanitize_date( $value, $fallback ) {
        $value = substr( trim( (string) $value ), 0, 10 );
        $d     = DateTime::createFromFormat( 'Y-m-d', $value );
        if ( ! $d || $d->format( 'Y-m-d' ) !== $value ) {
            return $fallback;
        }
        return $value;
    }

    private static function preset_to_range( $preset, $today ) {
        switch ( $preset ) {
            case '7':
            case '30':
            case '90':
            case '365':
                $days = (int) $preset;
                return array( gmdate( 'Y-m-d', strtotime( $today . ' -' . ( $days - 1 ) . ' days' ) ), $today );
            case 'this_month':
                return array( gmdate( 'Y-m-01', strtotime( $today ) ), $today );
            case 'last_month':
                $first = gmdate( 'Y-m-01', strtotime( $today . ' first day of last month' ) );
                $last  = gmdate( 'Y-m-t', strtotime( $first ) );
                return array( $first, $last );
            case 'ytd':
                return array( gmdate( 'Y-01-01', strtotime( $today ) ), $today );
        }
        return array( gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) ), $today );
    }

    /**
     * Convert the inclusive local date range to the [from, to) UTC window the
     * queries expect. The end bound is the start of the day after $to.
     */
    public static function range_to_utc( $from, $to ) {
        $tz    = wp_timezone();
        $start = new DateTime( $from . ' 00:00:00', $tz );
        $end   = new DateTime( $to . ' 00:00:00', $tz );
        $end->modify( '+1 day' );

        $start->setTimezone( new DateTimeZone( 'UTC' ) );
        $end->setTimezone( new DateTimeZone( 'UTC' ) );

        return array( $start->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ) );
    }

    public static function get_page_url( array $args = array() ) {
        return add_query_arg(
            array_merge(
                array(
                    'post_type' => 'katina_link',
                    'page'      => self::PAGE_SLUG,
                ),
                $args
            ),
            admin_url( 'edit.php' )
        );
    }

    public static function get_links_for_select() {
        return get_posts( array(
            'post_type'      => 'katina_link',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ) );
    }

    /**
     * Turn per-UTC-hour counts into a continuous local-day series. Each UTC hour
     * is shifted into the site timezone and its clicks added to that local day,
     * so the bars align with the local dates on the axis and the series sums to
     * the same total the header shows. Days with no clicks are filled with zero.
     */
    public static function build_series( array $rows, $from, $to ) {
        $tz  = wp_timezone();
        $utc = new DateTimeZone( 'UTC' );

        $counts = array();
        foreach ( $rows as $row ) {
            // $row->utc_hour is "Y-m-d H"; treat it as the start of that UTC hour.
            $dt = DateTime::createFromFormat( 'Y-m-d H', $row->utc_hour, $utc );
            if ( ! $dt ) {
                continue;
            }
            $dt->setTimezone( $tz );
            $local_day = $dt->format( 'Y-m-d' );
            if ( ! isset( $counts[ $local_day ] ) ) {
                $counts[ $local_day ] = 0;
            }
            $counts[ $local_day ] += (int) $row->clicks;
        }

        $series = array();
        $cursor = new DateTime( $from );
        $end    = new DateTime( $to );
        while ( $cursor <= $end ) {
            $key      = $cursor->format( 'Y-m-d' );
            $series[] = array(
                'date'   => $key,
                'clicks' => isset( $counts[ $key ] ) ? $counts[ $key ] : 0,
            );
            $cursor->modify( '+1 day' );
        }

        return $series;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view reports.', 'katina-link-manager' ) );
        }

        $filters = self::get_filters();
        list( $utc_from, $utc_to ) = self::range_to_utc( $filters['from'], $filters['to'] );

        $link_id = $filters['link_id'];

        $data = array(
            'filters'   => $filters,
            'total'     => KATINA_Clicks_DB::get_clicks_in_range( $utc_from, $utc_to, $link_id ),
            'unique'    => KATINA_Clicks_DB::get_unique_clicks_in_range( $utc_from, $utc_to, $link_id ),
            'series'    => self::build_series(
                KATINA_Clicks_DB::get_clicks_by_hour( $utc_from, $utc_to, $link_id ),
                $filters['from'],
                $filters['to']
            ),
            'referrers' => KATINA_Clicks_DB::get_top_referrers( $utc_from, $utc_to, 10, $link_id ),
            'countries' => KATINA_Clicks_DB::get_top_countries( $utc_from, $utc_to, 15, $link_id ),
            'top_links' => $link_id ? array() : KATINA_Clicks_DB::get_top_links( $utc_from, $utc_to, 10 ),
            'links'     => self::get_links_for_select(),
        );

        include KATINA_PLUGIN_DIR . 'views/admin-reports.php';
    }

    public static function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to export clicks.', 'katina-link-manager' ) );
        }
        check_admin_referer( 'katina_export_clicks' );

        $filters = self::get_filters();
        list( $utc_from, $utc_to ) = self::range_to_utc( $filters['from'], $filters['to'] );

        $rows = KATINA_Clicks_DB::get_clicks_for_export( $utc_from, $utc_to, $filters['link_id'] );

        $filename = sprintf( 'katina-clicks-%s-to-%s.csv', $filters['from'], $filters['to'] );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        // Pass an explicit (empty) $escape: PHP 8.1+ deprecates the default and
        // PHP 9 makes omitting it a fatal. Empty disables backslash-escaping,
        // which is what a plain CSV wants.
        // Streaming CSV directly to the response; WP_Filesystem has no streaming API.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'clicked_at_utc', 'link_id', 'link', 'referrer', 'country_code', 'is_bot' ), ',', '"', '' );
        foreach ( $rows as $row ) {
            fputcsv( $out, array(
                $row->clicked_at,
                $row->link_id,
                $row->post_title,
                $row->referrer,
                $row->country_code,
                $row->is_bot,
            ), ',', '"', '' );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $out );
        exit;
    }
}
