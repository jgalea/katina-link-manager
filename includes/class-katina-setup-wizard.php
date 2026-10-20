<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Setup_Wizard {

    public static function init() {
        if ( get_option( 'katina_setup_complete' ) ) {
            return;
        }

        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
        add_action( 'admin_post_katina_wizard_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
        add_action( 'admin_post_katina_dismiss_setup_notice', array( __CLASS__, 'dismiss_notice' ) );
    }

    public static function add_page() {
        add_submenu_page(
            null,
            __( 'Katina Link Manager Setup', 'katina-link-manager' ),
            __( 'Setup', 'katina-link-manager' ),
            'manage_options',
            'katina-setup',
            array( __CLASS__, 'render' )
        );
    }

    public static function maybe_redirect() {
        if ( ! get_transient( 'katina_activation_redirect' ) ) {
            return;
        }

        delete_transient( 'katina_activation_redirect' );

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

        if ( is_network_admin() ) {
            return;
        }

        if ( isset( $_GET['activate-multi'] ) ) {
            return;
        }

        wp_redirect( admin_url( 'admin.php?page=katina-setup' ) );
        exit;
    }

    public static function dismiss_notice() {
        check_admin_referer( 'katina_dismiss_setup_notice' );

        update_user_meta( get_current_user_id(), 'katina_setup_notice_dismissed', 1 );

        $back = wp_get_referer();
        wp_safe_redirect( $back ? $back : admin_url() );
        exit;
    }

    public static function setup_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( get_user_meta( get_current_user_id(), 'katina_setup_notice_dismissed', true ) ) {
            return;
        }

        $links = wp_count_posts( 'katina_link' );
        if ( $links && ( (int) $links->publish + (int) $links->draft + (int) $links->private ) > 0 ) {
            return;
        }

        $screen = get_current_screen();
        if ( $screen && $screen->id === 'admin_page_katina-setup' ) {
            return;
        }

        $wizard_url  = admin_url( 'admin.php?page=katina-setup' );
        $dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=katina_dismiss_setup_notice' ), 'katina_dismiss_setup_notice' );
        ?>
        <div class="notice notice-info">
            <p>
                <?php
                printf(
                    /* translators: %s: setup wizard link */
                    esc_html__( 'Welcome to Katina Link Manager! Complete the %s to get started.', 'katina-link-manager' ),
                    '<a href="' . esc_url( $wizard_url ) . '">' . esc_html__( 'setup wizard', 'katina-link-manager' ) . '</a>'
                );
                ?>
                <a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'katina-link-manager' ); ?></a>
            </p>
        </div>
        <?php
    }

    public static function detect_competitors() {
        $migrators = array(
            'thirstyaffiliates'  => array(
                'class' => 'KATINA_Migrator_ThirstyAffiliates',
                'name'  => 'ThirstyAffiliates',
            ),
            'prettylinks'        => array(
                'class' => 'KATINA_Migrator_PrettyLinks',
                'name'  => 'Pretty Links',
            ),
            'linkcentral'        => array(
                'class' => 'KATINA_Migrator_LinkCentral',
                'name'  => 'LinkCentral',
            ),
            'easyaffiliatelinks' => array(
                'class' => 'KATINA_Migrator_EasyAffiliateLinks',
                'name'  => 'Easy Affiliate Links',
            ),
        );

        $detected = array();

        foreach ( $migrators as $slug => $data ) {
            $class = $data['class'];
            if ( $class::is_available() ) {
                $detected[ $slug ] = array(
                    'name'  => $data['name'],
                    'count' => $class::get_source_count(),
                );
            }
        }

        return $detected;
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        check_admin_referer( 'katina_wizard_save' );

        $settings = array(
            'katina_link_prefix'   => 'sanitize_text_field',
            'katina_redirect_type' => 'sanitize_text_field',
            'katina_nofollow'      => 'sanitize_text_field',
            'katina_new_window'    => 'sanitize_text_field',
        );

        foreach ( $settings as $key => $sanitize ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_option( $key, $sanitize( wp_unslash( $_POST[ $key ] ) ) );
            }
        }

        if ( ! empty( $_POST['katina_migrate_source'] ) ) {
            $migrators = array(
                'thirstyaffiliates'  => 'KATINA_Migrator_ThirstyAffiliates',
                'prettylinks'        => 'KATINA_Migrator_PrettyLinks',
                'linkcentral'        => 'KATINA_Migrator_LinkCentral',
                'easyaffiliatelinks' => 'KATINA_Migrator_EasyAffiliateLinks',
            );

            $source_key = sanitize_key( wp_unslash( $_POST['katina_migrate_source'] ) );

            if ( isset( $migrators[ $source_key ] ) ) {
                $class    = $migrators[ $source_key ];
                $migrator = new $class();
                $migrator->run();

                if ( ! empty( $_POST['katina_scan_content'] ) ) {
                    $scanner = new KATINA_Content_Scanner( $migrator->get_id_map(), $class::get_source_name() );
                    $scanner->scan_and_replace();
                }
            }
        }

        update_option( 'katina_setup_complete', true );
        flush_rewrite_rules();

        wp_redirect( admin_url( 'edit.php?post_type=katina_link&page=katina-dashboard' ) );
        exit;
    }

    public static function render() {
        include KATINA_PLUGIN_DIR . 'views/admin-wizard.php';
    }
}
