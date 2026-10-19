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
    );

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

    public function get_final_destination_url( $request_query_string = '' ) {
        $url = $this->get_destination_url();
        if ( $this->passes_query_string() && $request_query_string ) {
            // Use add_query_arg so query merging is safe instead of naive concat.
            parse_str( $request_query_string, $passed );
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
                $value = 'destination_url' === $key
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
