<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Upgrader {

    const DB_VERSION = '1.1';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), -2 );
    }

    public static function maybe_upgrade() {
        $current = (string) get_option( 'katina_db_version', '1.0' );
        if ( version_compare( $current, self::DB_VERSION, '>=' ) ) {
            return;
        }

        if ( version_compare( $current, '1.1', '<' ) ) {
            KATINA_Clicks_DB::create_table();
            self::merge_monitor_bots();
        }

        update_option( 'katina_db_version', self::DB_VERSION );
        update_option( 'katina_installed_version', KATINA_VERSION );
    }

    // Adds the monitor/link-checker defaults to a saved list without touching what's already there.
    private static function merge_monitor_bots() {
        $saved = get_option( 'katina_excluded_bots', false );
        if ( ! is_string( $saved ) || '' === trim( $saved ) ) {
            return;
        }

        $lines = array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', $saved ) ) ), 'strlen' );
        $have  = array_map( 'strtolower', $lines );
        foreach ( KATINA_Bot_Detector::MONITOR_BOTS as $bot ) {
            if ( ! in_array( $bot, $have, true ) ) {
                $lines[] = $bot;
            }
        }

        update_option( 'katina_excluded_bots', implode( "\n", $lines ) );
    }
}
