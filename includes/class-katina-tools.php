<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Tools {

    const PAGE_SLUG = 'katina-tools';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
    }

    public static function get_tabs() {
        return array(
            'import-export' => __( 'Import / Export', 'katina-link-manager' ),
            'migrate'       => __( 'Migrate', 'katina-link-manager' ),
        );
    }

    public static function get_current_tab() {
        $tabs = self::get_tabs();
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'import-export';
        return isset( $tabs[ $tab ] ) ? $tab : 'import-export';
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

    public static function add_menu() {
        add_submenu_page(
            'edit.php?post_type=katina_link',
            __( 'Katina Link Manager Tools', 'katina-link-manager' ),
            __( 'Tools', 'katina-link-manager' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        $tabs    = self::get_tabs();
        $current = self::get_current_tab();
        ?>
        <div class="wrap katina-tools-wrap">
            <h1><?php esc_html_e( 'Katina Link Manager Tools', 'katina-link-manager' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                    <a href="<?php echo esc_url( self::get_tab_url( $key ) ); ?>"
                       class="nav-tab<?php echo $current === $key ? ' nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php
            if ( $current === 'migrate' ) {
                KATINA_Admin::render_migration_page();
            } else {
                KATINA_CSV::render_tab();
            }
            ?>
        </div>
        <?php
    }
}
