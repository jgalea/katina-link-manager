<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Link_Fixer {

    const MAX_URLS_PER_REQUEST = 100;
    const RATE_LIMIT_WINDOW    = 60;   // seconds
    const RATE_LIMIT_MAX       = 30;   // requests per window per IP

    public static function init() {
        if ( get_option( 'katina_enable_link_fixer', 'yes' ) !== 'yes' ) {
            return;
        }

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_katina_link_fixer', array( __CLASS__, 'ajax_handler' ) );
        add_action( 'wp_ajax_nopriv_katina_link_fixer', array( __CLASS__, 'ajax_handler' ) );
    }

    public static function enqueue_scripts() {
        if ( is_admin() ) {
            return;
        }

        wp_enqueue_script(
            'katina-link-fixer',
            KATINA_PLUGIN_URL . 'assets/js/link-fixer.js',
            array(),
            KATINA_VERSION,
            true
        );

        $prefix = get_option( 'katina_link_prefix', 'go' );

        wp_localize_script( 'katina-link-fixer', 'katinaLinkFixer', array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'katina_link_fixer' ),
            'linkPrefix' => home_url( $prefix . '/' ),
            'homeUrl'    => home_url( '/' ),
        ) );
    }

    public static function ajax_handler() {
        check_ajax_referer( 'katina_link_fixer', 'nonce' );

        // Per-IP rate limit (window-based transient counter).
        $ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), FILTER_VALIDATE_IP ) : '';
        if ( ! $ip ) {
            $ip = '0.0.0.0';
        }
        $key  = 'katina_lf_rl_' . md5( $ip );
        $hits = (int) get_transient( $key );
        if ( $hits >= self::RATE_LIMIT_MAX ) {
            wp_send_json_error( 'rate_limited', 429 );
        }
        set_transient( $key, $hits + 1, self::RATE_LIMIT_WINDOW );

        $raw_urls = isset( $_POST['urls'] ) ? (array) wp_unslash( $_POST['urls'] ) : array();

        // Cap input array size — defends against unbounded query amplification.
        if ( count( $raw_urls ) > self::MAX_URLS_PER_REQUEST ) {
            $raw_urls = array_slice( $raw_urls, 0, self::MAX_URLS_PER_REQUEST );
        }

        $urls = array_map( 'esc_url_raw', $raw_urls );

        if ( empty( $urls ) ) {
            wp_send_json_success( array() );
        }

        $results = array();
        foreach ( $urls as $url ) {
            $slug = self::extract_slug_from_url( $url );
            if ( ! $slug ) {
                continue;
            }

            $link = KATINA_Link::find_by_slug( $slug );
            if ( ! $link ) {
                continue;
            }

            $results[ $url ] = array(
                'href'   => $link->get_cloaked_url(),
                'rel'    => $link->get_rel_tags(),
                'target' => $link->opens_new_window() ? '_blank' : '',
                'class'  => $link->get_css_classes(),
            );
        }

        wp_send_json_success( $results );
    }

    private static function extract_slug_from_url( $url ) {
        $parsed = wp_parse_url( $url );
        $path   = isset( $parsed['path'] ) ? trim( $parsed['path'], '/' ) : '';
        $prefix = get_option( 'katina_link_prefix', 'go' );

        $home_path = trim( wp_parse_url( home_url(), PHP_URL_PATH ) ?: '', '/' );
        if ( $home_path ) {
            $path = preg_replace( '#^' . preg_quote( $home_path, '#' ) . '/#', '', $path );
        }

        if ( preg_match( '#^' . preg_quote( $prefix, '#' ) . '/(.+)$#', $path, $matches ) ) {
            return sanitize_title( $matches[1] );
        }

        return null;
    }
}
