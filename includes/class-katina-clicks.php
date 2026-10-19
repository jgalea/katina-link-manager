<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Clicks {

    public static function record( KATINA_Link $link ) {
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $is_bot     = KATINA_Bot_Detector::is_bot( $user_agent );
        $referrer   = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

        // Strip query string from stored referrer — they routinely leak PII
        // (search queries, session tokens, email addresses).
        if ( $referrer ) {
            $parts    = wp_parse_url( $referrer );
            $referrer = ( isset( $parts['scheme'], $parts['host'] ) ? $parts['scheme'] . '://' . $parts['host'] : '' )
                      . ( isset( $parts['path'] ) ? $parts['path'] : '' );
        }

        if ( strlen( $referrer ) > 2048 ) {
            $referrer = substr( $referrer, 0, 2048 );
        }

        $ua_hash      = $user_agent ? md5( $user_agent ) : '';
        $country_code = self::detect_country();

        KATINA_Clicks_DB::insert( array(
            'link_id'         => $link->get_id(),
            'clicked_at'      => current_time( 'mysql', true ),
            'referrer'        => $referrer,
            'country_code'    => $country_code,
            'user_agent_hash' => $ua_hash,
            'is_bot'          => $is_bot ? 1 : 0,
        ) );
    }

    private static function detect_country() {
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
        $headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
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
