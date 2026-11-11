<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Rest_Posts_Controller extends WP_REST_Posts_Controller {

    public function get_items_permissions_check( $request ) {
        return $this->require_link_access() ?: parent::get_items_permissions_check( $request );
    }

    public function get_item_permissions_check( $request ) {
        return $this->require_link_access() ?: parent::get_item_permissions_check( $request );
    }

    private function require_link_access() {
        if ( current_user_can( 'edit_katina_links' ) ) {
            return null;
        }
        return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to view links.', 'katina-link-manager' ), array( 'status' => rest_authorization_required_code() ) );
    }
}
