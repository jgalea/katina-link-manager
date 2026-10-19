<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Katina_Link_Manager {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'init', array( 'KATINA_Post_Type', 'register' ), 0 );
        add_action( 'init', array( 'KATINA_Redirect', 'maybe_redirect_prefixed' ), 1 );
        add_filter( 'robots_txt', array( 'KATINA_Redirect', 'add_robots_disallow' ), 10, 2 );
        add_action( 'template_redirect', array( 'KATINA_Redirect', 'maybe_404_unmatched_link' ), 0 );
        add_action( 'template_redirect', array( 'KATINA_Redirect', 'maybe_redirect_fallback' ) );

        KATINA_Link_Health::init();
        KATINA_Privacy::init();
        KATINA_Link_Index::init();
        KATINA_ThirstyAffiliates_Compat::init();
        KATINA_Content_Links::init();
        add_action( 'init', array( 'KATINA_Settings', 'repair_blanked_options' ), -1 );

        if ( is_admin() ) {
            KATINA_Admin::init();
            KATINA_Settings::init();
            KATINA_Reports::init();
            KATINA_Link_Report::init();
            KATINA_Tools::init();
            KATINA_CSV::init();
            KATINA_Setup_Wizard::init();
        }

        KATINA_Link_Fixer::init();
        KATINA_Editor::init();

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            require_once KATINA_PLUGIN_DIR . 'includes/class-katina-cli.php';
        }
    }
}
