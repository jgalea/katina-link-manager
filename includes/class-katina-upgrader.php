<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Upgrader {

    const DB_VERSION = '1.3';

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

        if ( version_compare( $current, '1.2', '<' ) ) {
            KATINA_Clicks_DB::create_table();
            // Sites that never saved a retention ran on the old 365-day default; keep it so the shorter default doesn't purge their history.
            add_option( 'katina_clicks_retention_days', 365 );
        }

        if ( version_compare( $current, '1.3', '<' ) ) {
            KATINA_Post_Type::grant_capabilities();
            // Sites already behind Cloudflare keep reading its headers; the upgrade request itself shows whether it is in front.
            if ( ! empty( $_SERVER['HTTP_CF_RAY'] ) ) {
                add_option( 'katina_trusted_proxy', 'cloudflare' );
            }
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
