<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Redirect {

    /**
     * Keep crawlers out of the cloaked redirect path. The redirects already send
     * X-Robots-Tag: noindex, but that only stops indexing, not crawling — search
     * engines still fetch every /go/ URL to be told noindex, which burns crawl
     * budget on a site with a lot of links. A Disallow stops the crawl outright.
     */
    public static function add_robots_disallow( $output, $public ) {
        // When the site discourages search engines, WordPress already emits
        // "Disallow: /", so there's nothing to add.
        if ( ! $public ) {
            return $output;
        }

        $prefix = trim( (string) get_option( 'katina_link_prefix', 'go' ), '/' );
        if ( '' === $prefix ) {
            return $output;
        }

        // robots.txt paths are from the domain root, so include the subdirectory
        // for installs that live under a path (e.g. example.com/blog/go/).
        $home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
        $home_path = '/' === $home_path ? '' : rtrim( $home_path, '/' );

        return $output . 'Disallow: ' . $home_path . '/' . $prefix . '/' . "\n";
    }

    public static function maybe_redirect_prefixed() {
        if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
            return;
        }

        $request_uri = self::get_request_path();
        $prefix      = get_option( 'katina_link_prefix', 'go' );

        if ( ! preg_match( '#^/' . preg_quote( $prefix, '#' ) . '/([^/\?]+)/?$#i', $request_uri, $matches ) ) {
            return;
        }

        $slug = sanitize_title( $matches[1] );
        $link = KATINA_Link::find_by_slug( $slug );

        if ( ! $link ) {
            return;
        }

        self::do_redirect( $link );
    }

    public static function maybe_redirect_fallback() {
        if ( ! is_404() ) {
            return;
        }

        $request_uri = self::get_request_path();

        if ( ! preg_match( '#^/([^/\?]+)/?$#', $request_uri, $matches ) ) {
            return;
        }

        $slug = sanitize_title( $matches[1] );
        $link = KATINA_Link::find_by_slug( $slug );

        if ( ! $link ) {
            return;
        }

        self::do_redirect( $link );
    }

    /**
     * A /<prefix>/<slug>/ request matches the rewrite rule and sets the
     * katina_link_slug query var. When a live link exists, maybe_redirect_prefixed()
     * has already redirected and exited on `init`. Reaching template_redirect with
     * the query var still set therefore means the slug has no live link (deleted,
     * trashed, or never existed), so return a real 404 instead of letting WordPress
     * fall through to the front page with a 200.
     */
    public static function maybe_404_unmatched_link() {
        $slug = get_query_var( 'katina_link_slug' );

        if ( ! is_string( $slug ) || '' === $slug ) {
            return;
        }

        global $wp_query;
        if ( $wp_query ) {
            $wp_query->set_404();
        }
        status_header( 404 );
        nocache_headers();
    }

    private static function do_redirect( KATINA_Link $link ) {
        // parse_str() in get_final_destination_url() does the parsing; sanitize_text_field() would strip %XX octets.
        $query_string = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $destination  = $link->get_final_destination_url( $query_string );

        if ( ! $destination || ! KATINA_Link::is_safe_destination( $destination ) ) {
            return;
        }

        header( 'X-Robots-Tag: noindex, nofollow', true );
        // Don't leak the originating page to the destination — small privacy win
        // and reduces fingerprinting surface for the destination operator.
        header( 'Referrer-Policy: no-referrer', true );
        nocache_headers();

        $redirect_type = $link->get_redirect_type();
        $track         = 'yes' === get_option( 'katina_enable_click_tracking', 'yes' ) && self::is_countable_request();

        // Send the visitor to the destination first, then record the click once
        // the response is flushed, so the DB write stays off the redirect's
        // critical path. On a cache-fronted site every /go/ hit is a full PHP
        // request, and under a burst (a newsletter landing on a big list) those
        // are the only uncacheable requests hitting origin — deferring the write
        // means the visitor never waits on it. fastcgi_finish_request() closes
        // the connection and lets the write drain behind it on PHP-FPM; other
        // SAPIs fall back to logging inline before exit.
        if ( $track && function_exists( 'fastcgi_finish_request' ) ) {
            wp_redirect( $destination, $redirect_type );
            fastcgi_finish_request();
            self::record_click( $link );
            exit;
        }

        if ( $track ) {
            self::record_click( $link );
        }
        wp_redirect( $destination, $redirect_type );
        exit;
    }

    // Only a GET that isn't a browser prefetch/prerender is a visitor clicking. HEAD checks and speculative loads still get the redirect.
    private static function is_countable_request() {
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
        if ( 'GET' !== $method ) {
            return false;
        }

        foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_MOZ' ) as $header ) {
            if ( empty( $_SERVER[ $header ] ) ) {
                continue;
            }
            $value = strtolower( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
            if ( preg_match( '/prefetch|prerender|preview/', $value ) ) {
                return false;
            }
        }

        return true;
    }

    private static function record_click( KATINA_Link $link ) {
        try {
            KATINA_Clicks::record( $link );
        } catch ( \Throwable $e ) {
            // The redirect has already been served; a logging failure must never surface.
        }
    }

    private static function get_request_path() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $parsed      = wp_parse_url( $request_uri );
        $path        = isset( $parsed['path'] ) ? $parsed['path'] : '/';

        $home_path = wp_parse_url( home_url(), PHP_URL_PATH );
        if ( $home_path && $home_path !== '/' ) {
            $path = substr( $path, strlen( $home_path ) );
            if ( $path === false ) {
                $path = '/';
            }
        }

        return $path;
    }
}
