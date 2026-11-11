<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Upgrader {

    const DB_VERSION = '1.7';

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
            self::merge_bots( KATINA_Bot_Detector::MONITOR_BOTS );
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

        if ( version_compare( $current, '1.4', '<' ) ) {
            self::merge_bots( array_merge( KATINA_Bot_Detector::PREVIEW_BOTS, KATINA_Bot_Detector::CLIENT_BOTS, KATINA_Bot_Detector::AI_BOTS ) );
        }

        if ( version_compare( $current, '1.5', '<' ) ) {
            KATINA_Clicks_DB::create_table();
        }

        if ( version_compare( $current, '1.6', '<' ) ) {
            self::tag_pretty_links_imports();
        }

        if ( version_compare( $current, '1.7', '<' ) ) {
            // The Link Fixer now defaults to off; sites that never saved the setting were running it.
            add_option( 'katina_enable_link_fixer', 'yes' );
        }

        update_option( 'katina_db_version', self::DB_VERSION );
        update_option( 'katina_installed_version', KATINA_VERSION );
    }

    // Links imported from Pretty Links by earlier versions carry no source mark, and the root-level fallback now relies on it.
    private static function tag_pretty_links_imports() {
        global $wpdb;
        $table = $wpdb->prefix . 'prli_links';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $rows = $wpdb->get_results( "SELECT id, slug FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        foreach ( $rows as $row ) {
            $link = KATINA_Link::find_by_slug( $row->slug );
            if ( $link && '' === (string) get_post_meta( $link->get_id(), KATINA_Link::SOURCE_META, true ) ) {
                update_post_meta( $link->get_id(), KATINA_Link::SOURCE_META, 'prettylinks:' . (int) $row->id );
            }
        }
    }

    // Adds new default signatures to a saved list without touching what's already there.
    private static function merge_bots( array $bots ) {
        $saved = get_option( 'katina_excluded_bots', false );
        if ( ! is_string( $saved ) || '' === trim( $saved ) ) {
            return;
        }

        $lines = array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', $saved ) ) ), 'strlen' );
        $have  = array_map( 'strtolower', $lines );
        foreach ( $bots as $bot ) {
            if ( ! in_array( $bot, $have, true ) ) {
                $lines[] = $bot;
            }
        }

        update_option( 'katina_excluded_bots', implode( "\n", $lines ) );
    }
}
