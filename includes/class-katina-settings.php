<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Settings {

    const PAGE_SLUG = 'katina-settings';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'update_option_katina_link_prefix', array( __CLASS__, 'schedule_rewrite_flush' ) );
        add_action( 'update_option_katina_enable_link_health', array( __CLASS__, 'on_link_health_toggle' ), 10, 2 );
        add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
    }

    // Before 1.4.0, saving one settings tab stored an empty string for every option on the other tabs.
    public static function repair_blanked_options() {
        if ( get_option( 'katina_blank_options_repaired' ) ) {
            return;
        }
        $never_empty = array( 'katina_link_prefix', 'katina_redirect_type', 'katina_nofollow', 'katina_sponsored', 'katina_new_window', 'katina_pass_query_str', 'katina_content_link_attributes', 'katina_enable_click_tracking', 'katina_disable_ip_collection', 'katina_enable_link_fixer', 'katina_enable_link_health' );
        foreach ( $never_empty as $option ) {
            if ( '' === get_option( $option ) ) {
                delete_option( $option );
                if ( 'katina_link_prefix' === $option ) {
                    add_action( 'init', 'flush_rewrite_rules', 99 );
                }
            }
        }
        update_option( 'katina_blank_options_repaired', '1' );
    }

    public static function schedule_rewrite_flush() {
        update_option( 'katina_flush_rewrite_rules', true );
    }

    public static function maybe_flush_rewrites() {
        if ( get_option( 'katina_flush_rewrite_rules' ) ) {
            flush_rewrite_rules();
            delete_option( 'katina_flush_rewrite_rules' );
        }
    }

    public static function on_link_health_toggle( $old_value, $new_value ) {
        if ( $new_value === 'yes' ) {
            KATINA_Link_Health::schedule();
        } else {
            KATINA_Link_Health::unschedule();
        }
    }

    public static function get_tabs() {
        $tabs = array(
            'general'  => __( 'General', 'katina-link-manager' ),
            'tracking' => __( 'Tracking', 'katina-link-manager' ),
            'modules'  => __( 'Modules', 'katina-link-manager' ),
        );

        if ( class_exists( 'KATINA_PRO_Settings' ) ) {
            $tabs['pro'] = __( 'Pro', 'katina-link-manager' );
        }

        return $tabs;
    }

    public static function get_current_tab() {
        $tabs = self::get_tabs();
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
        return isset( $tabs[ $tab ] ) ? $tab : 'general';
    }

    public static function get_tab_url( $tab ) {
        return add_query_arg(
            array(
                'post_type' => 'katina_link',
                'page'      => self::PAGE_SLUG,
                'tab'       => $tab,
            ),
            admin_url( 'edit.php' )
        );
    }

    public static function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=katina_link',
            __( 'Katina Link Manager Settings', 'katina-link-manager' ),
            __( 'Settings', 'katina-link-manager' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function register_settings() {
        // General tab
        add_settings_section( 'katina_link_defaults', __( 'Link Defaults', 'katina-link-manager' ), null, 'katina-settings-general' );

        self::add_field( 'katina_link_prefix', __( 'URL Prefix', 'katina-link-manager' ), 'slug', 'katina_link_defaults', 'katina-settings-general', 'go' );
        self::add_field( 'katina_redirect_type', __( 'Default Redirect Type', 'katina-link-manager' ), 'select', 'katina_link_defaults', 'katina-settings-general', '307', array(
            '301' => '301 (Permanent)',
            '302' => '302 (Temporary)',
            '307' => '307 (Temporary Strict)',
        ) );
        self::add_field( 'katina_nofollow', __( 'Default Nofollow', 'katina-link-manager' ), 'select', 'katina_link_defaults', 'katina-settings-general', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ) );
        self::add_field( 'katina_sponsored', __( 'Default Sponsored', 'katina-link-manager' ), 'select', 'katina_link_defaults', 'katina-settings-general', 'no', array( 'yes' => 'Yes', 'no' => 'No', 'never' => 'Never' ), __( 'Never removes rel="sponsored" from every link, including links set to Yes.', 'katina-link-manager' ) );
        self::add_field( 'katina_new_window', __( 'Default Open in New Window', 'katina-link-manager' ), 'select', 'katina_link_defaults', 'katina-settings-general', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ) );
        self::add_field( 'katina_content_link_attributes', __( 'Apply to Links in Content', 'katina-link-manager' ), 'select', 'katina_link_defaults', 'katina-settings-general', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ), __( 'Adds each link\'s rel and new-window settings to links you pasted into posts by hand. Attributes already on the link are kept.', 'katina-link-manager' ) );
        self::add_field( 'katina_pass_query_str', __( 'Default Pass Query Strings', 'katina-link-manager' ), 'select', 'katina_link_defaults', 'katina-settings-general', 'no', array( 'yes' => 'Yes', 'no' => 'No' ) );

        add_settings_section( 'katina_external_links', __( 'External Links', 'katina-link-manager' ), null, 'katina-settings-general' );

        self::add_field( 'katina_ext_new_window', __( 'Open in New Tab', 'katina-link-manager' ), 'select', 'katina_external_links', 'katina-settings-general', 'no', array( 'yes' => 'Yes', 'no' => 'No' ), __( 'Opens links to other sites in a new tab, unless the link already sets a target.', 'katina-link-manager' ) );
        self::add_field( 'katina_ext_rel', __( 'Add rel Values', 'katina-link-manager' ), 'multicheck', 'katina_external_links', 'katina-settings-general', '', array( 'nofollow' => 'nofollow', 'noreferrer' => 'noreferrer', 'ugc' => 'ugc' ), __( 'Added to links to other sites. Values already on a link are kept.', 'katina-link-manager' ) );
        self::add_field( 'katina_ext_excluded_domains', __( 'Excluded Domains', 'katina-link-manager' ), 'textarea', 'katina_external_links', 'katina-settings-general', '', array(), __( 'One per line. Links to these domains and their subdomains are left alone.', 'katina-link-manager' ) );
        self::add_field( 'katina_ext_comments', __( 'Apply to Comments', 'katina-link-manager' ), 'select', 'katina_external_links', 'katina-settings-general', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ) );

        // Tracking tab
        add_settings_section( 'katina_tracking', __( 'Click Tracking', 'katina-link-manager' ), null, 'katina-settings-tracking' );

        self::add_field( 'katina_enable_click_tracking', __( 'Enable Click Tracking', 'katina-link-manager' ), 'select', 'katina_tracking', 'katina-settings-tracking', 'yes', array( 'yes' => 'Yes', 'no' => 'No' ) );
        self::add_field( 'katina_disable_ip_collection', __( 'Disable IP Collection (GDPR)', 'katina-link-manager' ), 'select', 'katina_tracking', 'katina-settings-tracking', 'yes', array( 'yes' => 'Yes (recommended)', 'no' => 'No' ) );
        self::add_field( 'katina_excluded_bots', __( 'Excluded Bots (one per line)', 'katina-link-manager' ), 'textarea', 'katina_tracking', 'katina-settings-tracking' );
        self::add_field( 'katina_clicks_retention_days', __( 'Click Log Retention (days)', 'katina-link-manager' ), 'int', 'katina_tracking', 'katina-settings-tracking', '365', array(), __( 'Click records older than this are deleted automatically each day. Set to 0 to keep forever (not GDPR-recommended).', 'katina-link-manager' ) );

        // Modules tab
        add_settings_section( 'katina_modules', __( 'Modules', 'katina-link-manager' ), array( __CLASS__, 'render_modules_section_intro' ), 'katina-settings-modules' );

        self::add_field( 'katina_enable_link_fixer', __( 'Link Fixer', 'katina-link-manager' ), 'select', 'katina_modules', 'katina-settings-modules', 'yes', array( 'yes' => 'Enabled', 'no' => 'Disabled' ), __( 'Rewrites outbound links in post content based on your rules.', 'katina-link-manager' ) );
        self::add_field( 'katina_enable_link_health', __( 'Link Health Checks', 'katina-link-manager' ), 'select', 'katina_modules', 'katina-settings-modules', 'no', array( 'yes' => 'Enabled', 'no' => 'Disabled' ), __( 'Off by default. When enabled, the plugin periodically sends outbound HTTP requests to each link\'s destination URL to detect broken or error responses.', 'katina-link-manager' ) );
    }

    public static function render_modules_section_intro() {
        echo '<p>' . esc_html__( 'Toggle features on or off. Disabling a module hides its UI and stops any scheduled tasks associated with it.', 'katina-link-manager' ) . '</p>';
    }

    private static function add_field( $id, $title, $type, $section, $page, $default = '', $options = array(), $description = '' ) {
        switch ( $type ) {
            case 'int':
                $sanitize_cb = 'absint';
                break;
            case 'slug':
                $sanitize_cb = 'sanitize_title';
                break;
            case 'textarea':
                $sanitize_cb = 'sanitize_textarea_field';
                break;
            case 'select':
                $allowed     = array_keys( $options );
                $sanitize_cb = function ( $value ) use ( $allowed ) {
                    $value = sanitize_text_field( (string) $value );
                    return in_array( $value, $allowed, true ) ? $value : '';
                };
                break;
            case 'multicheck':
                $allowed     = array_keys( $options );
                $sanitize_cb = function ( $value ) use ( $allowed ) {
                    $value = is_array( $value ) ? $value : preg_split( '/\s+/', (string) $value );
                    return implode( ' ', array_values( array_intersect( $allowed, array_map( 'sanitize_key', $value ) ) ) );
                };
                break;
            case 'text':
            default:
                $sanitize_cb = 'sanitize_text_field';
                break;
        }

        // Every tab posts to the same settings group, so options.php sends null for fields on the other tabs.
        $field_cb    = $sanitize_cb;
        $sanitize_cb = function ( $value ) use ( $id, $default, $field_cb ) {
            if ( null === $value ) {
                return get_option( $id, $default );
            }
            return call_user_func( $field_cb, $value );
        };

        register_setting( 'katina_settings_group', $id, array(
            'type'              => self::setting_type_for( $type ),
            'sanitize_callback' => $sanitize_cb,
            'default'           => $default,
            'show_in_rest'      => false,
        ) );

        add_settings_field( $id, $title, function() use ( $id, $type, $default, $options, $description ) {
            $value = get_option( $id, $default );
            switch ( $type ) {
                case 'text':
                case 'slug':
                    echo '<input type="text" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
                    if ( $id === 'katina_link_prefix' ) {
                        /* translators: 1: site home URL, 2: URL prefix value */
                        echo '<p class="description">' . sprintf( esc_html__( 'Your links will look like: %1$s/%2$s/your-link/', 'katina-link-manager' ), esc_html( home_url() ), esc_html( $value ) ) . '</p>';
                        echo '<p class="description katina-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__( 'Changing the prefix invalidates every existing cloaked URL already published on your site or in external content.', 'katina-link-manager' ) . '</p>';
                    }
                    break;
                case 'int':
                    echo '<input type="number" min="0" step="1" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" class="small-text" />';
                    break;
                case 'select':
                    echo '<select name="' . esc_attr( $id ) . '">';
                    foreach ( $options as $opt_val => $opt_label ) {
                        echo '<option value="' . esc_attr( $opt_val ) . '" ' . selected( $value, $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
                    }
                    echo '</select>';
                    break;
                case 'multicheck':
                    $checked = preg_split( '/\s+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
                    echo '<input type="hidden" name="' . esc_attr( $id ) . '[]" value="" />';
                    foreach ( $options as $opt_val => $opt_label ) {
                        echo '<label style="margin-right:16px"><input type="checkbox" name="' . esc_attr( $id ) . '[]" value="' . esc_attr( $opt_val ) . '" ' . checked( in_array( $opt_val, $checked, true ), true, false ) . ' /> ' . esc_html( $opt_label ) . '</label>';
                    }
                    break;
                case 'textarea':
                    echo '<textarea name="' . esc_attr( $id ) . '" rows="6" class="large-text">' . esc_textarea( $value ) . '</textarea>';
                    break;
            }

            if ( $description ) {
                echo '<p class="description">' . esc_html( $description ) . '</p>';
            }
        }, $page, $section );
    }

    private static function setting_type_for( $type ) {
        return $type === 'int' ? 'integer' : 'string';
    }

    public static function render_page() {
        include KATINA_PLUGIN_DIR . 'views/admin-settings.php';
    }
}
