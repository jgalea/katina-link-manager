<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Clicks {

    const SWEEP_LINKS  = 3;
    const SWEEP_WINDOW = 300;

    public static function record( KATINA_Link $link, $click_id = '', $page = '' ) {
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $is_bot     = KATINA_Bot_Detector::is_bot( $user_agent );
        if ( $is_bot && 'no' === get_option( 'katina_store_bot_clicks', 'yes' ) ) {
            return;
        }
        $referrer   = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

        // Strip query string from stored referrer — they routinely leak PII
        // (search queries, session tokens, email addresses).
        if ( $referrer ) {
            $parts    = wp_parse_url( $referrer );
            $referrer = ( isset( $parts['scheme'], $parts['host'] ) ? $parts['scheme'] . '://' . $parts['host'] : '' )
                      . ( isset( $parts['path'] ) ? $parts['path'] : '' );
        }

        // The page slug content links carry; without one, the path of a referrer on this site.
        $source_page = $page;
        if ( '' === $source_page && $referrer && wp_parse_url( $referrer, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
            $source_page = (string) wp_parse_url( $referrer, PHP_URL_PATH );
        }

        if ( strlen( $referrer ) > 2048 ) {
            $referrer = substr( $referrer, 0, 2048 );
        }

        $ua_hash      = $user_agent ? md5( $user_agent ) : '';
        $country_code = self::detect_country();

        $visitor_hash = self::visitor_hash( $user_agent );

        KATINA_Clicks_DB::insert( array(
            'link_id'         => $link->get_id(),
            'clicked_at'      => current_time( 'mysql', true ),
            'referrer'        => $referrer,
            'country_code'    => $country_code,
            'user_agent_hash' => $ua_hash,
            'visitor_hash'    => $visitor_hash,
            'is_bot'          => $is_bot ? 1 : 0,
            'click_id'        => $click_id,
            'source_page'     => substr( $source_page, 0, 200 ),
        ) );

        if ( '' !== $visitor_hash ) {
            KATINA_Clicks_DB::flag_sweep( $visitor_hash, gmdate( 'Y-m-d H:i:s', time() - self::SWEEP_WINDOW ), self::SWEEP_LINKS );
        }
    }

    // One-way hash of IP + UA under a random salt that is replaced daily and discarded, so it can group one visitor's clicks within a day and nothing longer. The IP is hashed here and never stored, whatever the IP collection setting says.
    private static function visitor_hash( $user_agent ) {
        $ip = self::get_client_ip();
        if ( '' === $ip && '' === $user_agent ) {
            return '';
        }

        $key  = 'katina_salt_' . gmdate( 'Y-m-d' );
        $salt = get_transient( $key );
        if ( ! $salt ) {
            $salt = bin2hex( random_bytes( 16 ) );
            set_transient( $key, $salt, 36 * HOUR_IN_SECONDS );
        }

        return substr( hash_hmac( 'sha256', $ip . '|' . $user_agent, $salt ), 0, 16 );
    }

    // Forwarded and CDN headers are client-controlled unless a proxy the site trusts sets them. 'auto' trusts the forwarded chain only when the request arrives from a private address, and then only the entries added by the proxies themselves. CDN country headers are read in the matching explicit mode only.
    public static function trusted_proxy() {
        $mode = (string) get_option( 'katina_trusted_proxy', 'auto' );
        if ( 'auto' === $mode ) {
            $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
            $mode   = $remote && ! filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ? 'forwarded' : 'none';
        }
        $mode = apply_filters( 'katina_trusted_proxy', $mode );
        return in_array( $mode, array( 'cloudflare', 'cloudfront', 'forwarded' ), true ) ? $mode : 'none';
    }

    public static function detect_country() {
        // A CDN-supplied country code carries no IP, so it's read even when IP collection is off.
        $explicit = (string) get_option( 'katina_trusted_proxy', 'auto' );
        $headers  = array(
            'cloudflare' => 'HTTP_CF_IPCOUNTRY',
            'cloudfront' => 'HTTP_CLOUDFRONT_VIEWER_COUNTRY',
            'forwarded'  => 'HTTP_GEOIP_COUNTRY_CODE',
        );
        $header   = $headers[ $explicit ] ?? '';
        if ( '' !== $header && self::trusted_proxy() === $explicit && ! empty( $_SERVER[ $header ] ) ) {
            $code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
            if ( preg_match( '/^[A-Z]{2}$/', $code ) && ! in_array( $code, array( 'XX', 'T1' ), true ) ) {
                return $code;
            }
        }

        // Honor the GDPR toggle: when IP collection is disabled, skip every
        // header read so no IP is touched, looked up, or potentially logged.
        if ( get_option( 'katina_disable_ip_collection', 'yes' ) === 'yes' ) {
            return '';
        }

        $ip = self::get_client_ip();
        if ( ! $ip ) {
            return '';
        }

        if ( function_exists( 'geoip_country_code_by_name' ) ) {
            $code = @geoip_country_code_by_name( $ip );
            return $code ? $code : '';
        }

        return '';
    }

    private static function get_client_ip() {
        $proxy = self::trusted_proxy();

        if ( 'cloudflare' === $proxy && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return $ip;
            }
        }

        if ( in_array( $proxy, array( 'cloudfront', 'forwarded' ), true ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            // Each proxy appends the address it saw, so the client-supplied entries sit on the left. The first public address from the right is the nearest one a trusted proxy vouches for.
            $chain = array_reverse( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) );
            foreach ( $chain as $ip ) {
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                    return $ip;
                }
            }
        }

        if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = filter_var( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), FILTER_VALIDATE_IP );
            return $ip ? $ip : '';
        }

        return '';
    }
}
