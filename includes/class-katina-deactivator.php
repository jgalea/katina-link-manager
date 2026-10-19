<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Deactivator {

    public static function deactivate() {
        KATINA_Link_Health::unschedule();
        KATINA_Privacy::unschedule();
        wp_clear_scheduled_hook( KATINA_Link_Health::RECHECK_HOOK );
        flush_rewrite_rules();
    }
}
