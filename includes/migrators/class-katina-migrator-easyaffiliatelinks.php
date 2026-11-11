<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Migrator_EasyAffiliateLinks extends KATINA_Migrator {

    public static function is_available() {
        return post_type_exists( 'easy_affiliate_link' );
    }

    public static function has_orphaned_data() {
        return self::cpt_has_posts( 'easy_affiliate_link' ) || self::table_exists( 'eafl_clicks' );
    }

    public static function cleanup_orphaned_data() {
        return self::cleanup_generic(
            'easy_affiliate_link',
            array( 'eafl_category' ),
            array( 'eafl_clicks' ),
            array( 'eafl\_%', 'easy\_affiliate\_links\_%' )
        );
    }

    public static function get_source_name() {
        return 'Easy Affiliate Links';
    }

    public static function get_source_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'easy_affiliate_link'
             AND post_status IN ('publish', 'draft', 'private')"
        );
    }

    const SOURCE = 'easyaffiliatelinks';

    public function run() {
        global $wpdb;
        self::register_source_taxonomy( 'eafl_category', 'easy_affiliate_link' );
        $has_clicks   = self::table_exists( 'eafl_clicks' );
        $clicks_table = $wpdb->prefix . 'eafl_clicks';

        $fetch_clicks = function ( $source_id, $after_id, $cutoff, $limit ) use ( $wpdb, $clicks_table ) {
            $sql  = "SELECT id, date, referer, agent FROM `{$clicks_table}` WHERE link_id = %d AND id > %d";
            $args = array( $source_id, $after_id );
            if ( $cutoff ) {
                $sql   .= ' AND date < %s';
                $args[] = $cutoff;
            }
            $sql   .= ' ORDER BY id ASC LIMIT %d';
            $args[] = $limit;
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
            return array_map( function ( $row ) {
                return array(
                    'id'         => (int) $row['id'],
                    'clicked_at' => $row['date'],
                    'referrer'   => (string) $row['referer'],
                    'user_agent' => (string) $row['agent'],
                );
            }, $rows );
        };

        while ( true ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'easy_affiliate_link' AND post_status IN ('publish', 'draft', 'private') AND ID > %d ORDER BY ID ASC LIMIT 100",
                $this->cursor
            ) );

            if ( empty( $ids ) ) {
                break;
            }

            foreach ( $ids as $post_id ) {
                $post = get_post( (int) $post_id );
                $meta = get_post_meta( (int) $post_id );

                if ( $post ) {
                    $link_id = $this->create_link( array(
                        'source'          => self::SOURCE,
                        'old_id'          => $post->ID,
                        'title'           => $post->post_title,
                        'slug'            => $post->post_name,
                        'destination_url' => isset( $meta['eafl_url'][0] ) ? $meta['eafl_url'][0] : '',
                        'redirect_type'   => isset( $meta['eafl_redirect_type'][0] ) ? $meta['eafl_redirect_type'][0] : 'default',
                        'nofollow'        => ( isset( $meta['eafl_nofollow'][0] ) && 'nofollow' === $meta['eafl_nofollow'][0] ) ? 'yes' : 'default',
                        'sponsored'       => ( isset( $meta['eafl_sponsored'][0] ) && $meta['eafl_sponsored'][0] ) ? 'yes' : 'default',
                        'new_window'      => ( isset( $meta['eafl_target'][0] ) && '_blank' === $meta['eafl_target'][0] ) ? 'yes' : 'default',
                        'css_classes'     => isset( $meta['eafl_classes'][0] ) ? $meta['eafl_classes'][0] : '',
                        'pass_query_str'  => 'default',
                        'rel_tags'        => '',
                    ) );

                    if ( $link_id ) {
                        $this->migrate_categories( $post->ID, $link_id );
                        if ( $has_clicks && ! $this->import_link_clicks( $link_id, $post->ID, $fetch_clicks ) ) {
                            $this->complete = false;
                            return $this->results;
                        }
                    }
                }

                $this->cursor = (int) $post_id;
                if ( $this->out_of_time() ) {
                    $this->complete = false;
                    return $this->results;
                }
            }
        }

        return $this->results;
    }

    private function migrate_categories( $old_post_id, $link_id ) {
        $terms = wp_get_object_terms( $old_post_id, 'eafl_category', array( 'orderby' => 'parent' ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return;
        }

        $term_id_map  = array();
        $new_term_ids = array();

        foreach ( $terms as $term ) {
            $parent_id = 0;
            if ( $term->parent && isset( $term_id_map[ $term->parent ] ) ) {
                $parent_id = $term_id_map[ $term->parent ];
            }

            $new_term_id = $this->map_category( $term->name, $parent_id );
            $term_id_map[ $term->term_id ] = $new_term_id;

            if ( $new_term_id ) {
                $new_term_ids[] = $new_term_id;
            }
        }

        if ( $new_term_ids ) {
            wp_set_object_terms( $link_id, $new_term_ids, 'katina_category' );
        }
    }
}
