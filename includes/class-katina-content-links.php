<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Applies link settings to plain <a> tags at render time: a cloaked link gets its own rel and
// target, and a link to another site gets the External Links settings.
class KATINA_Content_Links {

    private static $cache = array();

    public static function init() {
        add_filter( 'the_content', array( __CLASS__, 'filter' ), 12 );
        add_filter( 'widget_text', array( __CLASS__, 'filter' ), 12 );
        add_filter( 'comment_text', array( __CLASS__, 'filter_comment' ), 12 );
    }

    public static function filter_comment( $content ) {
        if ( get_option( 'katina_ext_comments', 'yes' ) !== 'yes' ) {
            return $content;
        }
        return self::filter( $content, false );
    }

    public static function filter( $content, $managed = true ) {
        if ( ! is_string( $content ) || false === stripos( $content, '<a' ) ) {
            return $content;
        }

        $managed      = $managed && get_option( 'katina_content_link_attributes', 'yes' ) === 'yes';
        $ext_new_tab = get_option( 'katina_ext_new_window', 'no' ) === 'yes';
        $ext_rel     = preg_split( '/\s+/', (string) get_option( 'katina_ext_rel', '' ), -1, PREG_SPLIT_NO_EMPTY );
        $external    = $ext_new_tab || $ext_rel;
        if ( ! $managed && ! $external ) {
            return $content;
        }

        $tags = new WP_HTML_Tag_Processor( $content );
        while ( $tags->next_tag( 'a' ) ) {
            $href = $tags->get_attribute( 'href' );
            if ( ! is_string( $href ) || '' === $href ) {
                continue;
            }

            $add_rel = array();
            $new_tab = false;

            $host = self::external_host( $href );
            if ( null === $host ) {
                $link = $managed ? self::resolve( $href ) : null;
                if ( ! $link ) {
                    continue;
                }
                $add_rel = preg_split( '/\s+/', $link->get_rel_tags(), -1, PREG_SPLIT_NO_EMPTY );
                $new_tab = $link->opens_new_window();
            } elseif ( $external && ! self::is_excluded( $host ) ) {
                $add_rel = $ext_rel;
                $new_tab = $ext_new_tab;
            } else {
                continue;
            }

            $rel = preg_split( '/\s+/', strtolower( trim( (string) $tags->get_attribute( 'rel' ) ) ), -1, PREG_SPLIT_NO_EMPTY );
            $rel = array_merge( $rel, $add_rel );

            if ( null === $tags->get_attribute( 'target' ) && $new_tab ) {
                $tags->set_attribute( 'target', '_blank' );
            }
            if ( '_blank' === $tags->get_attribute( 'target' ) ) {
                $rel[] = 'noopener';
            }
            if ( in_array( 'nofollow', $rel, true ) ) {
                $rel = array_diff( $rel, array( 'follow' ) );
            }
            if ( KATINA_Link::sponsored_disabled() ) {
                $rel = array_diff( $rel, array( 'sponsored' ) );
            }

            $rel = array_unique( $rel );
            if ( $rel ) {
                $tags->set_attribute( 'rel', implode( ' ', $rel ) );
            } else {
                $tags->remove_attribute( 'rel' );
            }
        }

        return $tags->get_updated_html();
    }

    // The lowercased host of a link to another site, or null for relative and same-site links.
    private static function external_host( $href ) {
        $href = html_entity_decode( trim( $href ) );
        if ( 0 === strpos( $href, '//' ) ) {
            $href = 'https:' . $href;
        }
        if ( ! preg_match( '#^https?://#i', $href ) ) {
            return null;
        }
        $host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
        if ( '' === $host ) {
            return null;
        }
        $home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        return preg_replace( '/^www\./', '', $host ) === preg_replace( '/^www\./', '', $home ) ? null : $host;
    }

    private static function is_excluded( $host ) {
        static $domains = null;
        if ( null === $domains ) {
            $domains = array();
            foreach ( preg_split( '/[\s,]+/', strtolower( (string) get_option( 'katina_ext_excluded_domains', '' ) ), -1, PREG_SPLIT_NO_EMPTY ) as $domain ) {
                $domain = preg_replace( '#^(https?:)?//#', '', $domain );
                $domain = preg_replace( '/^www\./', '', strtok( $domain, '/' ) );
                if ( '' !== $domain ) {
                    $domains[] = $domain;
                }
            }
        }

        $host = preg_replace( '/^www\./', '', $host );
        foreach ( $domains as $domain ) {
            if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
                return true;
            }
        }
        return false;
    }

    private static function resolve( $href ) {
        $href = html_entity_decode( $href );
        if ( ! array_key_exists( $href, self::$cache ) ) {
            $id = KATINA_ThirstyAffiliates_Compat::link_id_from_url( $href );
            self::$cache[ $href ] = $id ? new KATINA_Link( $id ) : null;
        }
        return self::$cache[ $href ];
    }
}
