<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_ThirstyAffiliates_Compat {

    const SOURCE = 'thirstyaffiliates';

    private static $enabled = false;

    private static $map = null;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ), 99 );
    }

    public static function register() {
        if ( shortcode_exists( 'thirstylink' ) || ! apply_filters( 'katina_thirstyaffiliates_compat', true ) ) {
            return;
        }

        self::$enabled = true;
        add_shortcode( 'thirstylink', array( __CLASS__, 'render_shortcode' ) );
        add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 9 );
        add_filter( 'widget_text', array( __CLASS__, 'filter_content' ), 9 );
    }

    public static function render_shortcode( $atts, $content = '' ) {
        $atts = shortcode_atts( array(
            'ids'      => '',
            'linkid'   => '',
            'linktext' => '',
            'class'    => '',
            'rel'      => '',
            'target'   => '',
            'title'    => '',
            'href'     => '',
        ), $atts, 'thirstylink' );

        $candidates = array();
        if ( '' !== $atts['linkid'] ) {
            $candidates[] = (int) $atts['linkid'];
        } else {
            $candidates = array_filter( array_map( 'intval', explode( ',', (string) $atts['ids'] ) ) );
        }

        $link_ids = array();
        foreach ( $candidates as $old_id ) {
            $katina_id = self::link_id_from_old_id( $old_id );
            if ( $katina_id ) {
                $link_ids[] = $katina_id;
            }
        }

        $text = '' !== trim( (string) $content ) ? wp_kses_post( $content ) : esc_html( $atts['linktext'] );

        // Like ThirstyAffiliates, show plain text when the link no longer exists.
        if ( empty( $link_ids ) ) {
            return $text;
        }

        $link = new KATINA_Link( $link_ids[ array_rand( $link_ids ) ] );
        if ( '' === trim( (string) $text ) ) {
            $text = esc_html( $link->get_title() );
        }

        return self::build_anchor( $link, $text, array(
            'class'  => $atts['class'],
            'rel'    => $atts['rel'],
            'target' => $atts['target'],
            'title'  => $atts['title'],
        ) );
    }

    public static function filter_content( $content ) {
        if ( ! self::$enabled ) {
            return $content;
        }
        return self::transform_ta_tags( $content );
    }

    public static function transform_ta_tags( $content, $id_map = null, &$count = 0 ) {
        $count = 0;
        if ( ! is_string( $content ) || false === stripos( $content, '<ta' ) ) {
            return $content;
        }

        return preg_replace_callback(
            '#<ta(\s[^>]*)?>(.*?)</ta>#is',
            function ( $m ) use ( $id_map ) {
                $attrs = isset( $m[1] ) ? $m[1] : '';
                $inner = preg_replace( '#</?a(\s[^>]*)?>#i', '', $m[2] );

                $old_id = preg_match( '/\blinkid\s*=\s*([\'"]?)(\d+)\1/i', $attrs, $id ) ? (int) $id[2] : 0;
                $href   = preg_match( '/\bhref\s*=\s*([\'"])(.*?)\1/is', $attrs, $h ) ? html_entity_decode( $h[2] ) : '';

                $katina_id = 0;
                if ( $old_id ) {
                    $katina_id = null !== $id_map
                        ? ( isset( $id_map[ $old_id ] ) ? (int) $id_map[ $old_id ] : 0 )
                        : self::link_id_from_old_id( $old_id );
                }
                if ( ! $katina_id && $href ) {
                    $katina_id = self::link_id_from_url( $href );
                }

                // A linkid that no longer resolves renders as plain text, as it did under ThirstyAffiliates.
                if ( ! $katina_id ) {
                    return ! $old_id && $href && KATINA_Link::is_safe_destination( $href )
                        ? '<a href="' . esc_url( $href ) . '">' . $inner . '</a>'
                        : $inner;
                }

                $link = new KATINA_Link( $katina_id );
                return self::build_anchor( $link, $inner );
            },
            $content,
            -1,
            $count
        );
    }

    public static function build_anchor( KATINA_Link $link, $text, array $overrides = array() ) {
        $rel = isset( $overrides['rel'] ) && '' !== $overrides['rel'] ? $overrides['rel'] : $link->get_rel_tags();
        if ( KATINA_Link::sponsored_disabled() ) {
            $rel = trim( preg_replace( '/\s+/', ' ', preg_replace( '/(^|\s)sponsored(?=\s|$)/i', ' ', $rel ) ) );
        }

        if ( isset( $overrides['target'] ) && '' !== $overrides['target'] ) {
            $target = $overrides['target'];
        } else {
            $target = $link->opens_new_window() ? '_blank' : '';
        }
        if ( '_blank' === $target && false === strpos( $rel, 'noopener' ) ) {
            $rel = trim( $rel . ' noopener' );
        }

        $class = trim( (string) $link->get_css_classes() . ' ' . ( isset( $overrides['class'] ) ? $overrides['class'] : '' ) );

        $html = '<a href="' . esc_url( $link->get_cloaked_url() ) . '"';
        if ( '' !== $class ) {
            $html .= ' class="' . esc_attr( $class ) . '"';
        }
        if ( '' !== $rel ) {
            $html .= ' rel="' . esc_attr( $rel ) . '"';
        }
        if ( '' !== $target ) {
            $html .= ' target="' . esc_attr( $target ) . '"';
        }
        if ( ! empty( $overrides['title'] ) ) {
            $html .= ' title="' . esc_attr( $overrides['title'] ) . '"';
        }

        return $html . '>' . $text . '</a>';
    }

    public static function link_id_from_old_id( $old_id ) {
        $map = self::get_map();
        return isset( $map[ (int) $old_id ] ) ? $map[ (int) $old_id ] : 0;
    }

    public static function link_id_from_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['path'] ) ) {
            return 0;
        }

        if ( ! empty( $parts['host'] ) ) {
            $home = wp_parse_url( home_url(), PHP_URL_HOST );
            if ( strtolower( preg_replace( '/^www\./', '', $parts['host'] ) ) !== strtolower( preg_replace( '/^www\./', '', (string) $home ) ) ) {
                return 0;
            }
        }

        $segments = explode( '/', trim( $parts['path'], '/' ) );
        if ( 2 !== count( $segments ) ) {
            return 0;
        }

        $prefixes = array_filter( array(
            trim( (string) get_option( 'katina_link_prefix', 'go' ), '/' ),
            trim( (string) get_option( 'ta_link_prefix', '' ), '/' ),
            trim( (string) get_option( 'ta_link_prefix_custom', '' ), '/' ),
        ) );
        if ( ! in_array( $segments[0], $prefixes, true ) ) {
            return 0;
        }

        $link = KATINA_Link::find_by_slug( $segments[1] );
        return $link ? $link->get_id() : 0;
    }

    private static function get_map() {
        if ( null !== self::$map ) {
            return self::$map;
        }

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'katina_link' AND p.post_status = 'publish'
             WHERE pm.meta_key = %s AND pm.meta_value LIKE %s",
            KATINA_Link::SOURCE_META,
            $wpdb->esc_like( self::SOURCE . ':' ) . '%'
        ) );

        self::$map = array();
        foreach ( $rows as $row ) {
            self::$map[ (int) substr( $row->meta_value, strlen( self::SOURCE ) + 1 ) ] = (int) $row->post_id;
        }

        return self::$map;
    }
}
