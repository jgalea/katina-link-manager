<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Link {

    private $id;
    private $post;

    const META_KEYS = array(
        'destination_url'    => '_katina_destination_url',
        'redirect_type'      => '_katina_redirect_type',
        'nofollow'           => '_katina_nofollow',
        'sponsored'          => '_katina_sponsored',
        'new_window'         => '_katina_new_window',
        'css_classes'        => '_katina_css_classes',
        'pass_query_str'     => '_katina_pass_query_str',
        'rel_tags'           => '_katina_rel_tags',
        'sub_id_param'       => '_katina_sub_id_param',
        'click_id_param'     => '_katina_click_id_param',
        'geo_countries'      => '_katina_geo_countries',
        'geo_fallback_url'   => '_katina_geo_fallback_url',
    );

    // Set on cloaked links in post content so a link knows which page it was clicked on.
    const PAGE_PARAM = 'ksrc';

    // A destination containing this token gets the page slug there, with hyphens as underscores.
    const PAGE_TOKEN = '[page]';

    // A destination containing this token gets the logged click's ID there.
    const CLICK_TOKEN = '[click]';

    const SOURCE_META = '_katina_source_id';

    const LAST_CLICK_META = '_katina_source_last_click_id';

    public function __construct( $post_id ) {
        $this->id   = (int) $post_id;
        $this->post = get_post( $this->id );
    }

    public function get_id() {
        return $this->id;
    }

    public function get_title() {
        return $this->post ? $this->post->post_title : '';
    }

    public function get_slug() {
        return $this->post ? $this->post->post_name : '';
    }

    public function get_destination_url() {
        return get_post_meta( $this->id, self::META_KEYS['destination_url'], true );
    }

    public function get_redirect_type() {
        $type = get_post_meta( $this->id, self::META_KEYS['redirect_type'], true );
        if ( ! $type || $type === 'default' ) {
            $type = get_option( 'katina_redirect_type', '307' );
        }
        return (int) $type;
    }

    public function is_nofollow() {
        $val = get_post_meta( $this->id, self::META_KEYS['nofollow'], true );
        if ( ! $val || $val === 'default' ) {
            return get_option( 'katina_nofollow', 'yes' ) === 'yes';
        }
        return $val === 'yes';
    }

    public static function sponsored_disabled() {
        return get_option( 'katina_sponsored', 'no' ) === 'never';
    }

    public function is_sponsored() {
        if ( self::sponsored_disabled() ) {
            return false;
        }
        $val = get_post_meta( $this->id, self::META_KEYS['sponsored'], true );
        if ( ! $val || $val === 'default' ) {
            return get_option( 'katina_sponsored', 'no' ) === 'yes';
        }
        return $val === 'yes';
    }

    public function opens_new_window() {
        $val = get_post_meta( $this->id, self::META_KEYS['new_window'], true );
        if ( ! $val || $val === 'default' ) {
            return get_option( 'katina_new_window', 'yes' ) === 'yes';
        }
        return $val === 'yes';
    }

    public function get_css_classes() {
        return get_post_meta( $this->id, self::META_KEYS['css_classes'], true );
    }

    public function passes_query_string() {
        $val = get_post_meta( $this->id, self::META_KEYS['pass_query_str'], true );
        if ( ! $val || $val === 'default' ) {
            return get_option( 'katina_pass_query_str', 'no' ) === 'yes';
        }
        return $val === 'yes';
    }

    public function get_sub_id_param() {
        return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) get_post_meta( $this->id, self::META_KEYS['sub_id_param'], true ) );
    }

    public function get_click_id_param() {
        return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) get_post_meta( $this->id, self::META_KEYS['click_id_param'], true ) );
    }

    public function wants_page() {
        return '' !== $this->get_sub_id_param() || false !== strpos( (string) $this->get_destination_url(), self::PAGE_TOKEN );
    }

    public function get_geo_fallback_url() {
        $url = (string) get_post_meta( $this->id, self::META_KEYS['geo_fallback_url'], true );
        return self::is_safe_destination( $url ) ? $url : '';
    }

    public function is_geo_excluded( $country ) {
        $list = preg_split( '/[\s,]+/', strtoupper( (string) get_post_meta( $this->id, self::META_KEYS['geo_countries'], true ) ), -1, PREG_SPLIT_NO_EMPTY );
        return '' !== $country && in_array( $country, $list, true );
    }

    public function get_rel_tags() {
        $tags = array();
        if ( $this->is_nofollow() ) {
            $tags[] = 'nofollow';
        }
        if ( $this->is_sponsored() ) {
            $tags[] = 'sponsored';
        }
        $custom = get_post_meta( $this->id, self::META_KEYS['rel_tags'], true );
        if ( $custom ) {
            $tags = array_merge( $tags, array_map( 'trim', explode( ' ', $custom ) ) );
        }
        if ( self::sponsored_disabled() ) {
            $tags = array_diff( $tags, array( 'sponsored' ) );
        }
        return implode( ' ', array_unique( $tags ) );
    }

    public function get_cloaked_url() {
        return KATINA_Post_Type::get_link_url( $this->id );
    }

    public function get_final_destination_url( $request_query_string = '', $page = '', $click_id = '' ) {
        $url = str_replace(
            array( self::PAGE_TOKEN, self::CLICK_TOKEN ),
            array( strtr( $page, '-', '_' ), $click_id ),
            (string) $this->get_destination_url()
        );
        if ( $this->passes_query_string() && $request_query_string ) {
            // Use add_query_arg so query merging is safe instead of naive concat.
            parse_str( $request_query_string, $passed );
            unset( $passed[ self::PAGE_PARAM ] );
            // Parameters the destination already sets (an affiliate tag, say) stay as the site owner wrote them.
            parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $existing );
            $passed = array_diff_key( $passed, $existing );
            if ( ! empty( $passed ) ) {
                $url = add_query_arg( urlencode_deep( $passed ), $url );
            }
        }
        return $url;
    }

    /**
     * Whitelist of safe URL schemes for the destination.
     * Anything else (javascript:, data:, vbscript:, file:, etc.) is rejected.
     */
    public static function is_safe_destination( $url ) {
        if ( ! is_string( $url ) || $url === '' ) {
            return false;
        }
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        return in_array( $scheme, array( 'http', 'https' ), true );
    }

    public function save_meta( array $data ) {
        foreach ( self::META_KEYS as $key => $meta_key ) {
            if ( isset( $data[ $key ] ) ) {
                // sanitize_text_field() strips percent-encoded octets, which breaks affiliate URLs.
                $value = in_array( $key, array( 'destination_url', 'geo_fallback_url' ), true )
                    ? esc_url_raw( $data[ $key ], array( 'http', 'https' ) )
                    : sanitize_text_field( $data[ $key ] );
                update_post_meta( $this->id, $meta_key, $value );
            }
        }
    }

    public static function find_by_slug( $slug ) {
        $posts = get_posts( array(
            'post_type'      => 'katina_link',
            'name'           => sanitize_title( $slug ),
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ) );
        if ( empty( $posts ) ) {
            return null;
        }
        return new self( $posts[0]->ID );
    }
}
