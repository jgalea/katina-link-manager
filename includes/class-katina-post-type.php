<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Post_Type {

    public static function register() {
        self::register_post_type();
        self::register_taxonomies();
        self::register_rewrite_rules();
    }

    private static function register_post_type() {
        $labels = array(
            'name'               => __( 'Links', 'katina-link-manager' ),
            'singular_name'      => __( 'Link', 'katina-link-manager' ),
            'add_new'            => __( 'Add New Link', 'katina-link-manager' ),
            'add_new_item'       => __( 'Add New Link', 'katina-link-manager' ),
            'edit_item'          => __( 'Edit Link', 'katina-link-manager' ),
            'new_item'           => __( 'New Link', 'katina-link-manager' ),
            'view_item'          => __( 'View Link', 'katina-link-manager' ),
            'search_items'       => __( 'Search Links', 'katina-link-manager' ),
            'not_found'          => __( 'No links found', 'katina-link-manager' ),
            'not_found_in_trash' => __( 'No links found in trash', 'katina-link-manager' ),
            'all_items'          => __( 'All Links', 'katina-link-manager' ),
            'menu_name'          => __( 'Katina Link Manager', 'katina-link-manager' ),
        );

        register_post_type( 'katina_link', array(
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'rest_base'           => 'katina-links',
            'menu_icon'           => 'dashicons-admin-links',
            'menu_position'       => 26,
            'supports'            => array( 'title' ),
            'has_archive'         => false,
            'rewrite'             => false,
            'exclude_from_search' => true,
            'hierarchical'        => false,
            'capability_type'     => 'post',
        ) );
    }

    private static function register_taxonomies() {
        register_taxonomy( 'katina_category', 'katina_link', array(
            'labels' => array(
                'name'          => __( 'Link Categories', 'katina-link-manager' ),
                'singular_name' => __( 'Link Category', 'katina-link-manager' ),
                'add_new_item'  => __( 'Add New Category', 'katina-link-manager' ),
                'edit_item'     => __( 'Edit Category', 'katina-link-manager' ),
                'search_items'  => __( 'Search Categories', 'katina-link-manager' ),
                'all_items'     => __( 'All Categories', 'katina-link-manager' ),
            ),
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => false,
        ) );

        register_taxonomy( 'katina_tag', 'katina_link', array(
            'labels' => array(
                'name'          => __( 'Link Tags', 'katina-link-manager' ),
                'singular_name' => __( 'Link Tag', 'katina-link-manager' ),
                'add_new_item'  => __( 'Add New Tag', 'katina-link-manager' ),
                'edit_item'     => __( 'Edit Tag', 'katina-link-manager' ),
                'search_items'  => __( 'Search Tags', 'katina-link-manager' ),
                'all_items'     => __( 'All Tags', 'katina-link-manager' ),
            ),
            'hierarchical'      => false,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => false,
        ) );
    }

    private static function register_rewrite_rules() {
        $prefix = get_option( 'katina_link_prefix', 'go' );

        // Match: /go/any-slug
        add_rewrite_rule(
            '^' . preg_quote( $prefix, '/' ) . '/([^/]+)/?$',
            'index.php?katina_link_slug=$matches[1]',
            'top'
        );

        add_rewrite_tag( '%katina_link_slug%', '([^/]+)' );
    }

    /**
     * Get the full cloaked URL for a link.
     */
    public static function get_link_url( $post_id ) {
        $prefix = get_option( 'katina_link_prefix', 'go' );
        $slug   = get_post_field( 'post_name', $post_id );
        return home_url( $prefix . '/' . $slug . '/' );
    }
}
