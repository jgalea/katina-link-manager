<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Admin {

    private static $click_counts = null;

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post_katina_link', array( __CLASS__, 'save_link_meta' ), 10, 2 );
        add_action( 'bulk_edit_custom_box', array( __CLASS__, 'render_bulk_edit' ), 10, 2 );
        add_action( 'save_post_katina_link', array( __CLASS__, 'save_bulk_edit' ), 11 );
        add_filter( 'wp_insert_post_data', array( __CLASS__, 'apply_custom_slug' ), 10, 2 );
        add_action( 'wp_ajax_katina_check_slug', array( __CLASS__, 'ajax_check_slug' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_filter( 'manage_katina_link_posts_columns', array( __CLASS__, 'custom_columns' ) );
        add_filter( 'manage_katina_link_posts_columns', array( __CLASS__, 'strip_foreign_columns' ), 100 );
        add_filter( 'manage_edit-katina_link_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
        add_filter( 'posts_clauses', array( __CLASS__, 'sort_by_clicks' ), 10, 2 );
        add_action( 'manage_katina_link_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
        add_action( 'admin_menu', array( __CLASS__, 'add_dashboard_page' ) );
        add_action( 'admin_post_katina_check_health_now', array( __CLASS__, 'handle_health_check' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'filter_by_health_status' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_active_health_filter_notice' ) );
    }

    public static function render_active_health_filter_notice() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-katina_link' ) {
            return;
        }
        $status = isset( $_GET['katina_health'] ) ? sanitize_key( wp_unslash( $_GET['katina_health'] ) ) : '';
        if ( ! $status ) {
            return;
        }

        $label_map = array(
            'broken_error' => __( 'Broken / Error', 'katina-link-manager' ),
            'broken'       => __( 'Broken', 'katina-link-manager' ),
            'error'        => __( 'Error', 'katina-link-manager' ),
            'server_error' => __( 'Server Error', 'katina-link-manager' ),
            'healthy'      => __( 'Healthy', 'katina-link-manager' ),
            'unchecked'    => __( 'Unchecked', 'katina-link-manager' ),
            'no_url'       => __( 'No URL', 'katina-link-manager' ),
        );
        $label = isset( $label_map[ $status ] ) ? $label_map[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );

        $clear_url = remove_query_arg( 'katina_health' );

        echo '<div class="notice notice-info katina-filter-notice"><p>';
        printf(
            /* translators: %s: filter label */
            esc_html__( 'Filter active: %s.', 'katina-link-manager' ),
            '<strong>' . esc_html( $label ) . '</strong>'
        );
        echo ' <a href="' . esc_url( $clear_url ) . '">' . esc_html__( 'Clear filter', 'katina-link-manager' ) . '</a>';
        echo '</p></div>';
    }

    public static function filter_by_health_status( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        global $pagenow;
        if ( $pagenow !== 'edit.php' || $query->get( 'post_type' ) !== 'katina_link' ) {
            return;
        }

        $status = isset( $_GET['katina_health'] ) ? sanitize_key( wp_unslash( $_GET['katina_health'] ) ) : '';
        if ( ! $status ) {
            return;
        }

        $meta_query = array();

        if ( $status === 'unchecked' ) {
            $meta_query[] = array(
                'key'     => KATINA_Link_Health::META_STATUS,
                'compare' => 'NOT EXISTS',
            );
        } elseif ( $status === 'broken_error' ) {
            $meta_query[] = array(
                'key'     => KATINA_Link_Health::META_STATUS,
                'value'   => array( 'broken', 'error' ),
                'compare' => 'IN',
            );
        } else {
            $meta_query[] = array(
                'key'   => KATINA_Link_Health::META_STATUS,
                'value' => $status,
            );
        }

        $query->set( 'meta_query', $meta_query );
    }

    public static function add_dashboard_page() {
        add_submenu_page(
            'edit.php?post_type=katina_link',
            __( 'Dashboard', 'katina-link-manager' ),
            __( 'Dashboard', 'katina-link-manager' ),
            'edit_katina_links',
            'katina-dashboard',
            array( __CLASS__, 'render_dashboard' ),
            0
        );
    }

    public static function render_dashboard() {
        include KATINA_PLUGIN_DIR . 'views/admin-dashboard.php';
    }

    public static function add_meta_boxes() {
        global $post;

        add_meta_box(
            'katina_link_settings',
            __( 'Link Settings', 'katina-link-manager' ),
            array( __CLASS__, 'render_meta_box' ),
            'katina_link',
            'normal',
            'high'
        );

        // Skip stats box on auto-draft (Add New) — there's nothing to show yet.
        if ( $post && $post->post_status !== 'auto-draft' ) {
            add_meta_box(
                'katina_link_stats',
                __( 'Click Statistics', 'katina-link-manager' ),
                array( __CLASS__, 'render_stats_box' ),
                'katina_link',
                'side',
                'default'
            );
        }
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'katina_save_link_meta', 'katina_link_nonce' );
        $link = new KATINA_Link( $post->ID );
        include KATINA_PLUGIN_DIR . 'views/link-meta-box.php';
    }

    public static function render_stats_box( $post ) {
        $total  = KATINA_Clicks_DB::get_total_clicks( $post->ID );
        $last30 = KATINA_Clicks_DB::get_clicks_for_link( $post->ID, 30 );
        $last7  = KATINA_Clicks_DB::get_clicks_for_link( $post->ID, 7 );
        $bar_pct = $total > 0 ? round( ( $last30 / $total ) * 100 ) : 0;
        ?>
        <div class="katina-stats-box">
            <div class="katina-stats-grid">
                <div class="katina-stat-row">
                    <span class="katina-stat-row-label"><?php esc_html_e( 'Total clicks', 'katina-link-manager' ); ?></span>
                    <span class="katina-stat-row-value"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
                </div>
                <div class="katina-stat-row">
                    <span class="katina-stat-row-label"><?php esc_html_e( 'Last 30 days', 'katina-link-manager' ); ?></span>
                    <span class="katina-stat-row-value"><?php echo esc_html( number_format_i18n( $last30 ) ); ?></span>
                </div>
                <div class="katina-stat-row">
                    <span class="katina-stat-row-label"><?php esc_html_e( 'Last 7 days', 'katina-link-manager' ); ?></span>
                    <span class="katina-stat-row-value"><?php echo esc_html( number_format_i18n( $last7 ) ); ?></span>
                </div>
            </div>
            <?php if ( $total > 0 ) : ?>
            <div class="katina-mini-bar">
                <div class="katina-mini-bar-label">
                    <span><?php esc_html_e( '30-day share', 'katina-link-manager' ); ?></span>
                    <span><?php echo esc_html( $bar_pct ); ?>%</span>
                </div>
                <div class="katina-mini-bar-track">
                    <div class="katina-mini-bar-fill" style="width: <?php echo esc_attr( $bar_pct ); ?>%;"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function bulk_edit_fields() {
        $site_default = __( 'Site default', 'katina-link-manager' );
        $yes_no       = array(
            'yes'     => __( 'Yes', 'katina-link-manager' ),
            'no'      => __( 'No', 'katina-link-manager' ),
            'default' => $site_default,
        );
        return array(
            'redirect_type'  => array(
                'label'   => __( 'Redirect type', 'katina-link-manager' ),
                'options' => array(
                    '301'     => '301',
                    '302'     => '302',
                    '307'     => '307',
                    '308'     => '308',
                    'default' => $site_default,
                ),
            ),
            'nofollow'       => array( 'label' => __( 'Nofollow', 'katina-link-manager' ), 'options' => $yes_no ),
            'sponsored'      => array( 'label' => __( 'Sponsored', 'katina-link-manager' ), 'options' => $yes_no ),
            'new_window'     => array( 'label' => __( 'Open in new window', 'katina-link-manager' ), 'options' => $yes_no ),
            'pass_query_str' => array( 'label' => __( 'Pass query strings', 'katina-link-manager' ), 'options' => $yes_no ),
        );
    }

    public static function render_bulk_edit( $column, $post_type ) {
        if ( 'katina_link' !== $post_type || 'katina_destination' !== $column ) {
            return;
        }
        ?>
        <fieldset class="inline-edit-col-right katina-bulk-edit">
            <div class="inline-edit-col">
                <span class="title"><?php esc_html_e( 'Link settings', 'katina-link-manager' ); ?></span>
                <?php wp_nonce_field( 'katina_bulk_edit', 'katina_bulk_nonce' ); ?>
                <?php foreach ( self::bulk_edit_fields() as $field => $config ) : ?>
                    <label>
                        <span class="title"><?php echo esc_html( $config['label'] ); ?></span>
                        <select name="katina_bulk_<?php echo esc_attr( $field ); ?>">
                            <option value=""><?php esc_html_e( '&mdash; No change &mdash;', 'katina-link-manager' ); ?></option>
                            <?php foreach ( $config['options'] as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    public static function save_bulk_edit( $post_id ) {
        if ( empty( $_REQUEST['bulk_edit'] ) || ! isset( $_REQUEST['katina_bulk_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['katina_bulk_nonce'] ) ), 'katina_bulk_edit' ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        foreach ( self::bulk_edit_fields() as $field => $config ) {
            $key = 'katina_bulk_' . $field;
            if ( ! isset( $_REQUEST[ $key ] ) ) {
                continue;
            }
            $value = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
            if ( '' === $value || ! isset( $config['options'][ $value ] ) ) {
                continue;
            }
            update_post_meta( $post_id, '_katina_' . $field, $value );
        }
    }

    public static function apply_custom_slug( $data, $postarr ) {
        if ( 'katina_link' !== $data['post_type'] || ! isset( $_POST['katina_slug'], $_POST['katina_link_nonce'] ) ) {
            return $data;
        }
        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['katina_link_nonce'] ) ), 'katina_save_link_meta' ) ) {
            return $data;
        }
        if ( ! current_user_can( 'edit_katina_links' ) ) {
            return $data;
        }

        $slug = sanitize_title( wp_unslash( $_POST['katina_slug'] ) );
        if ( '' !== $slug ) {
            $data['post_name'] = $slug;
        }
        return $data;
    }

    // Messages about a slug that another link or a page already uses, or a URL prefix that a page already owns.
    public static function slug_conflicts( $slug, $exclude_id = 0 ) {
        global $wpdb;
        $messages = array();
        $slug     = sanitize_title( $slug );
        $prefix   = trim( (string) get_option( 'katina_link_prefix', 'go' ), '/' );

        if ( '' !== $slug ) {
            $other_link = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'katina_link' AND post_name = %s AND ID <> %d AND post_status NOT IN ('trash', 'auto-draft') LIMIT 1",
                $slug,
                (int) $exclude_id
            ) );
            if ( $other_link ) {
                $messages[] = __( 'Another link already uses this slug. A number will be added to keep it unique.', 'katina-link-manager' );
            }

            $page = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ('page', 'post') AND post_status = 'publish' LIMIT 1",
                $slug
            ) );
            if ( $page ) {
                $messages[] = __( 'A page or post on this site already uses this slug. The link works under the URL prefix, but pick a different slug if you also want it at the site root.', 'katina-link-manager' );
            }
        }

        if ( '' !== $prefix && get_page_by_path( $prefix ) ) {
            /* translators: %s: URL prefix */
            $messages[] = sprintf( __( 'A page exists at /%s/. Links under that prefix take priority over its child pages with the same slug.', 'katina-link-manager' ), $prefix );
        }

        return $messages;
    }

    public static function ajax_check_slug() {
        check_ajax_referer( 'katina_check_slug', 'nonce' );
        if ( ! current_user_can( 'edit_katina_links' ) ) {
            wp_send_json_error( null, 403 );
        }

        $slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
        wp_send_json_success( array(
            'slug'     => $slug,
            'messages' => self::slug_conflicts( $slug, isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 ),
        ) );
    }

    public static function save_link_meta( $post_id, $post ) {
        if ( ! isset( $_POST['katina_link_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['katina_link_nonce'] ) ), 'katina_save_link_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Use esc_url_raw for destination URL specifically; reject non-http(s) schemes.
        if ( isset( $_POST['katina_destination_url'] ) ) {
            $url = esc_url_raw( wp_unslash( $_POST['katina_destination_url'] ) );
            if ( $url && ! KATINA_Link::is_safe_destination( $url ) ) {
                $url = ''; // refuse to store dangerous schemes
            }
            update_post_meta( $post_id, '_katina_destination_url', $url );
        }

        if ( isset( $_POST['katina_geo_fallback_url'] ) ) {
            $fallback = esc_url_raw( wp_unslash( $_POST['katina_geo_fallback_url'] ) );
            update_post_meta( $post_id, '_katina_geo_fallback_url', KATINA_Link::is_safe_destination( $fallback ) ? $fallback : '' );
        }

        if ( isset( $_POST['katina_expires_at'] ) ) {
            $expires_at = sanitize_text_field( wp_unslash( $_POST['katina_expires_at'] ) );
            $timestamp  = '' === $expires_at ? 0 : (int) get_gmt_from_date( str_replace( 'T', ' ', $expires_at ), 'U' );
            update_post_meta( $post_id, '_katina_expires_at', $timestamp > 0 ? $timestamp : '' );
        }
        if ( isset( $_POST['katina_max_clicks'] ) ) {
            $max_clicks = absint( wp_unslash( $_POST['katina_max_clicks'] ) );
            update_post_meta( $post_id, '_katina_max_clicks', $max_clicks > 0 ? $max_clicks : '' );
        }
        if ( isset( $_POST['katina_expired_url'] ) ) {
            $expired_url = esc_url_raw( wp_unslash( $_POST['katina_expired_url'] ) );
            update_post_meta( $post_id, '_katina_expired_url', KATINA_Link::is_safe_destination( $expired_url ) ? $expired_url : '' );
        }

        $text_fields = array( 'redirect_type', 'nofollow', 'sponsored', 'new_window', 'pass_query_str', 'css_classes', 'rel_tags', 'sub_id_param', 'click_id_param', 'geo_countries' );
        foreach ( $text_fields as $field ) {
            $post_key = 'katina_' . $field;
            if ( isset( $_POST[ $post_key ] ) ) {
                $value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
                update_post_meta( $post_id, '_katina_' . $field, 'redirect_type' === $field ? KATINA_Link::sanitize_redirect_type( $value ) : $value );
            }
        }
    }

    public static function custom_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $val ) {
            $new[ $key ] = $val;
            if ( $key === 'title' ) {
                $new['katina_destination'] = __( 'Destination', 'katina-link-manager' );
                $new['katina_cloaked_url'] = __( 'Cloaked URL', 'katina-link-manager' );
                $new['katina_clicks']      = __( 'Clicks', 'katina-link-manager' );
                $new['katina_health']      = __( 'Health', 'katina-link-manager' );
            }
        }
        return $new;
    }

    public static function sortable_columns( $columns ) {
        $columns['katina_clicks'] = 'katina_clicks';
        return $columns;
    }

    public static function sort_by_clicks( $clauses, $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return $clauses;
        }
        if ( $query->get( 'post_type' ) !== 'katina_link' ) {
            return $clauses;
        }
        if ( $query->get( 'orderby' ) !== 'katina_clicks' ) {
            return $clauses;
        }

        global $wpdb;
        $clicks_table = KATINA_Clicks_DB::get_table_name();
        $order        = strtoupper( $query->get( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';

        $clauses['join']    .= " LEFT JOIN ( SELECT link_id, COUNT(*) AS total FROM {$clicks_table} WHERE is_bot = 0 GROUP BY link_id ) katina_counts ON katina_counts.link_id = {$wpdb->posts}.ID";
        $clauses['orderby']  = "COALESCE( katina_counts.total, 0 ) {$order}, {$wpdb->posts}.ID DESC";

        return $clauses;
    }

    public static function strip_foreign_columns( $columns ) {
        // Affiliate links are redirect endpoints, not viewable pages.
        // Generic analytics plugins (Koko Analytics Plus, Lean Analytics)
        // add their "Views (30d)" column to every non-hierarchical CPT
        // via manage_posts_columns. Strip those here so the Links list stays clean.
        unset( $columns['kap_views'], $columns['la_views'] );
        return $columns;
    }

    public static function column_content( $column, $post_id ) {
        $link = new KATINA_Link( $post_id );
        switch ( $column ) {
            case 'katina_destination':
                $url = $link->get_destination_url();
                echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( wp_trim_words( $url, 5, '...' ) ) . '</a>';
                break;
            case 'katina_cloaked_url':
                $cloaked = $link->get_cloaked_url();
                echo '<code data-url="' . esc_url( $cloaked ) . '">' . esc_html( str_replace( home_url(), '', $cloaked ) ) . '</code>';
                break;
            case 'katina_clicks':
                if ( null === self::$click_counts ) {
                    global $wp_query;
                    self::$click_counts = KATINA_Clicks_DB::get_total_clicks_for( wp_list_pluck( $wp_query->posts, 'ID' ) );
                }
                echo '<span class="katina-click-count">' . esc_html( number_format_i18n( isset( self::$click_counts[ $post_id ] ) ? self::$click_counts[ $post_id ] : 0 ) ) . '</span>';
                break;
            case 'katina_health':
                $status = get_post_meta( $post_id, KATINA_Link_Health::META_STATUS, true );
                $code   = get_post_meta( $post_id, KATINA_Link_Health::META_HTTP_CODE, true );
                $error  = get_post_meta( $post_id, KATINA_Link_Health::META_ERROR, true );
                echo wp_kses_post( KATINA_Link_Health::render_badge( $status ?: 'unchecked', $code, $error ) );
                break;
        }
    }

    public static function render_migration_page() {
        include KATINA_PLUGIN_DIR . 'views/admin-migration.php';
    }

    public static function handle_health_check() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'katina_check_health_now' );

        if ( ! KATINA_Link_Health::is_enabled() ) {
            wp_die( 'Link health checks are disabled.' );
        }

        $queued = KATINA_Link_Health::queue_recheck_all();

        wp_redirect( admin_url( 'edit.php?post_type=katina_link&page=katina-dashboard&katina_health_queued=' . $queued ) );
        exit;
    }

    public static function enqueue_admin_assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        // Load on the link CPT list/edit screens and on every Katina admin
        // page. The standalone pages (dashboard, settings, tools, setup
        // wizard) don't all carry post_type in their URL, so a post_type
        // check alone misses them — match the screen id too.
        $is_katina_screen = 'katina_link' === $screen->post_type
            || false !== strpos( $screen->id, 'katina' );

        if ( ! $is_katina_screen ) {
            return;
        }

        wp_enqueue_style( 'katina-admin', KATINA_PLUGIN_URL . 'assets/css/admin.css', array(), KATINA_VERSION );
        wp_enqueue_script( 'katina-admin', KATINA_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), KATINA_VERSION, true );
        wp_localize_script( 'katina-admin', 'katinaAdmin', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'slugNonce' => wp_create_nonce( 'katina_check_slug' ),
            'homeUrl'   => home_url( '/' ),
            'prefix'    => trim( (string) get_option( 'katina_link_prefix', 'go' ), '/' ),
            'i18n'      => array(
                'copied' => __( 'Copied!', 'katina-link-manager' ),
            ),
        ) );
    }
}
