<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Activator {

    public static function activate() {
        KATINA_Clicks_DB::create_table();
        KATINA_Link_Index::create_table();
        self::migrate_legacy_data();
        self::set_default_options();
        // Link health checks make outbound HTTP requests, so they stay off until
        // the user explicitly enables them. Enabling the setting schedules the cron.
        KATINA_Privacy::schedule();
        set_transient( 'katina_activation_redirect', true, 30 );
        flush_rewrite_rules();
    }

    private static function migrate_legacy_data() {
        global $wpdb;

        if ( get_option( 'katina_legacy_migration_done' ) ) {
            return;
        }

        // Clicks table: copy rows from wp_lp_clicks into wp_katina_clicks, then drop the legacy table.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        $old_clicks = $wpdb->prefix . 'lp_clicks';
        $new_clicks = $wpdb->prefix . 'katina_clicks';
        $old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_clicks ) );
        if ( $old_exists ) {
            $wpdb->query(
                "INSERT INTO `{$new_clicks}` (id, link_id, clicked_at, referrer, country_code, user_agent_hash, is_bot)
                 SELECT id, link_id, clicked_at, referrer, country_code, user_agent_hash, is_bot
                 FROM `{$old_clicks}`
                 WHERE id NOT IN (SELECT id FROM `{$new_clicks}`)"
            );
            $wpdb->query( "DROP TABLE `{$old_clicks}`" );
        }
        // phpcs:enable

        // Taxonomy rename: lp_category -> katina_category, lp_tag -> katina_tag.
        $wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => 'katina_category' ), array( 'taxonomy' => 'lp_category' ) );
        $wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => 'katina_tag' ), array( 'taxonomy' => 'lp_tag' ) );

        // Post type rename
        $wpdb->update( $wpdb->posts, array( 'post_type' => 'katina_link' ), array( 'post_type' => 'lp_link' ) );

        // Post meta key rename
        $meta_map = array(
            '_lp_destination_url'   => '_katina_destination_url',
            '_lp_redirect_type'     => '_katina_redirect_type',
            '_lp_nofollow'          => '_katina_nofollow',
            '_lp_sponsored'         => '_katina_sponsored',
            '_lp_new_window'        => '_katina_new_window',
            '_lp_pass_query_str'    => '_katina_pass_query_str',
            '_lp_rel_tags'          => '_katina_rel_tags',
            '_lp_css_classes'       => '_katina_css_classes',
            '_lp_health_checked_at' => '_katina_health_checked_at',
            '_lp_health_error'      => '_katina_health_error',
            '_lp_health_http_code'  => '_katina_health_http_code',
            '_lp_health_status'     => '_katina_health_status',
        );
        foreach ( $meta_map as $old => $new ) {
            $wpdb->update( $wpdb->postmeta, array( 'meta_key' => $new ), array( 'meta_key' => $old ) );
        }

        // Option key rename (free + pro)
        $option_map = array(
            'lp_link_prefix'           => 'katina_link_prefix',
            'lp_redirect_type'         => 'katina_redirect_type',
            'lp_nofollow'              => 'katina_nofollow',
            'lp_sponsored'             => 'katina_sponsored',
            'lp_new_window'            => 'katina_new_window',
            'lp_pass_query_str'        => 'katina_pass_query_str',
            'lp_enable_click_tracking' => 'katina_enable_click_tracking',
            'lp_disable_ip_collection' => 'katina_disable_ip_collection',
            'lp_enable_link_fixer'     => 'katina_enable_link_fixer',
            'lp_enable_link_health'    => 'katina_enable_link_health',
            'lp_excluded_bots'         => 'katina_excluded_bots',
            'lp_clicks_retention_days' => 'katina_clicks_retention_days',
            'lp_setup_complete'        => 'katina_setup_complete',
            'lp_db_version'            => 'katina_db_version',
            'lp_installed_version'     => 'katina_installed_version',
            'lpp_ai_provider'          => 'katinapro_ai_provider',
            'lpp_ai_api_key'           => 'katinapro_ai_api_key',
            'lpp_enable_auto_linking'  => 'katinapro_enable_auto_linking',
            'lpp_enable_suggestions'   => 'katinapro_enable_suggestions',
            'lpp_enable_content_ai'    => 'katinapro_enable_content_ai',
        );
        foreach ( $option_map as $old => $new ) {
            $value = get_option( $old );
            if ( $value !== false && get_option( $new ) === false ) {
                add_option( $new, $value );
                delete_option( $old );
            }
        }

        update_option( 'katina_legacy_migration_done', '1' );
    }

    private static function set_default_options() {
        $defaults = array(
            'katina_link_prefix'           => 'go',
            'katina_redirect_type'         => '307',
            'katina_nofollow'              => 'yes',
            'katina_sponsored'             => 'no',
            'katina_new_window'            => 'yes',
            'katina_pass_query_str'        => 'no',
            'katina_enable_click_tracking' => 'yes',
            'katina_disable_ip_collection' => 'yes',
            'katina_enable_link_fixer'     => 'yes',
            'katina_enable_link_health'    => 'no',
            'katina_excluded_bots'         => "googlebot\nbingbot\nslurp\nduckduckbot\nbaiduspider\nyandexbot\nsogou\nexabot\nia_archiver\nfacebot\nahrefs\nsemrush\nmj12bot\ndotbot\npetalbot",
        );
        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value );
            }
        }
        add_option( 'katina_db_version', '1.0' );
        add_option( 'katina_installed_version', KATINA_VERSION );
    }
}
