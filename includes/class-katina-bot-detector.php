<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Bot_Detector {

    const SEARCH_BOTS = array( 'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot', 'sogou', 'exabot', 'ia_archiver', 'facebot', 'ahrefs', 'semrush', 'mj12bot', 'dotbot', 'petalbot' );

    // Site-management tools, uptime monitors and link checkers that sweep /go/ URLs on a schedule.
    const MONITOR_BOTS = array( 'modularconnector', 'managewp', 'mainwp', 'jetpack', 'wp-umbrella', 'uptimerobot', 'pingdom', 'statuscake', 'site24x7', 'betteruptime', 'hetrixtools', 'nodeping', 'freshping', 'updown.io', 'uptime-kuma', 'linkchecker', 'w3c-checklink', 'screaming frog', 'sitebulb', 'xenu', 'deadlinkchecker', 'gtmetrix' );

    public static function default_list() {
        return implode( "\n", array_merge( self::SEARCH_BOTS, self::MONITOR_BOTS ) );
    }

    public static function is_bot( $user_agent = '' ) {
        if ( empty( $user_agent ) ) {
            $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        }

        if ( empty( $user_agent ) ) {
            return true;
        }

        $bot_list = get_option( 'katina_excluded_bots', '' );
        if ( empty( $bot_list ) ) {
            return false;
        }

        $bots = array_filter( array_map( 'trim', explode( "\n", strtolower( $bot_list ) ) ) );
        $ua   = strtolower( $user_agent );

        foreach ( $bots as $bot ) {
            if ( $bot && strpos( $ua, $bot ) !== false ) {
                return true;
            }
        }

        return false;
    }
}
