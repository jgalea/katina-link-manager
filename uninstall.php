<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Delete all katina_link posts and their meta
$posts = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'katina_link'" );
if ( $posts ) {
    foreach ( $posts as $post_id ) {
        wp_delete_post( (int) $post_id, true );
    }
}

// Delete custom taxonomy terms
$terms = $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('katina_category', 'katina_tag')" );
if ( $terms ) {
    foreach ( $terms as $term_id ) {
        wp_delete_term( (int) $term_id, 'katina_category' );
        wp_delete_term( (int) $term_id, 'katina_tag' );
    }
}

// Unschedule health check cron
wp_clear_scheduled_hook( 'katina_health_check_cron' );

// Drop plugin tables
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}katina_clicks" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}katina_link_index" );

// Delete options
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'katina\_%'" );

// Flush rewrite rules
flush_rewrite_rules();
