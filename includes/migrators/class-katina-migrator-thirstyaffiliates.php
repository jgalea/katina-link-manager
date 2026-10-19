<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Migrator_ThirstyAffiliates extends KATINA_Migrator {

    public static function is_available() {
        return post_type_exists( 'thirstylink' );
    }

    public static function has_orphaned_data() {
        return self::cpt_has_posts( 'thirstylink' ) || self::table_exists( 'ta_link_clicks' );
    }

    public static function cleanup_orphaned_data() {
        return self::cleanup_generic(
            'thirstylink',
            array( 'thirstylink-category', 'thirstylink-tag' ),
            array( 'ta_link_clicks', 'ta_link_clicks_meta' ),
            // Prefix-anchored only — broad %thirsty% would delete unrelated plugin/theme options.
            array( 'ta\_%', 'tap\_%', 'thirsty%', 'thirstylink%', '\_thirsty%', 'external_updates-thirstyaffiliates%', '\_ac\_columns\_default\_thirstylink%' )
        );
    }

    public static function get_source_name() {
        return 'ThirstyAffiliates';
    }

    public static function get_source_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'thirstylink'
             AND post_status IN ('publish', 'draft', 'private')"
        );
    }

    const SOURCE = 'thirstyaffiliates';

    const CLICK_BATCH = 2000;

    private $globals = array();

    public static function get_link_prefix() {
        $prefix = get_option( 'ta_link_prefix', 'recommends' );
        if ( 'custom' === $prefix ) {
            $prefix = get_option( 'ta_link_prefix_custom', 'recommends' );
        }
        $prefix = trim( (string) $prefix, '/' );
        return '' !== $prefix ? $prefix : 'recommends';
    }

    public function run() {
        $this->globals = $this->read_globals();
        $had_links     = $this->katina_has_links();

        $ids = get_posts( array(
            'post_type'        => 'thirstylink',
            'post_status'      => array( 'publish', 'draft', 'private' ),
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'no_found_rows'    => true,
        ) );

        $this->results['prefix'] = $this->handle_prefix( $had_links );

        $total = count( $ids );
        foreach ( $ids as $index => $post_id ) {
            $this->migrate_one( (int) $post_id );
            if ( $this->progress ) {
                call_user_func( $this->progress, $index + 1, $total, $this->results );
            }
            if ( 0 === ( $index + 1 ) % 50 ) {
                wp_cache_flush_runtime();
            }
        }

        return $this->results;
    }

    private function read_globals() {
        $additional = preg_split( '/\s+/', trim( (string) get_option( 'ta_additional_rel_tags', '' ) ), -1, PREG_SPLIT_NO_EMPTY );
        $redirect   = (string) get_option( 'ta_link_redirect_type', '301' );

        return array(
            'redirect_type'  => in_array( $redirect, array( '301', '302', '307' ), true ) ? $redirect : '301',
            'nofollow'       => $this->normalize_bool( get_option( 'ta_no_follow', 'yes' ) ),
            'new_window'     => $this->normalize_bool( get_option( 'ta_new_window', 'yes' ) ),
            'pass_query_str' => $this->normalize_bool( get_option( 'ta_pass_query_str', 'no' ) ),
            'rel_tags'       => $additional,
            'css_classes'    => trim( (string) get_option( 'ta_additional_css_classes', '' ) ),
        );
    }

    private function katina_has_links() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'katina_link' AND post_status NOT IN ('trash', 'auto-draft')"
        ) > 0;
    }

    private function handle_prefix( $had_links ) {
        $ta_prefix     = self::get_link_prefix();
        $katina_prefix = (string) get_option( 'katina_link_prefix', 'go' );
        $info          = array(
            'ta'      => $ta_prefix,
            'katina'  => $katina_prefix,
            'adopted' => false,
            'differs' => $ta_prefix !== $katina_prefix,
        );

        $adopt = true === $this->adopt_prefix || ( 'auto' === $this->adopt_prefix && ! $had_links );
        if ( ! $adopt || ! $info['differs'] ) {
            return $info;
        }

        $info['adopted'] = true;
        if ( $this->dry_run ) {
            return $info;
        }

        update_option( 'katina_link_prefix', $ta_prefix );
        update_option( 'katina_redirect_type', $this->globals['redirect_type'] );
        update_option( 'katina_nofollow', $this->globals['nofollow'] );
        update_option( 'katina_new_window', $this->globals['new_window'] );
        update_option( 'katina_sponsored', in_array( 'sponsored', $this->globals['rel_tags'], true ) ? 'yes' : 'no' );
        delete_option( 'rewrite_rules' );

        return $info;
    }

    private function resolve_flag( $value, $fallback ) {
        $value = strtolower( trim( (string) $value ) );
        if ( '' === $value || 'global' === $value ) {
            return $fallback;
        }
        return $this->normalize_bool( $value );
    }

    private function migrate_one( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $meta = get_post_meta( $post_id );
        $g    = $this->globals;

        $redirect_type = isset( $meta['_ta_redirect_type'][0] ) ? (string) $meta['_ta_redirect_type'][0] : 'global';
        if ( ! in_array( $redirect_type, array( '301', '302', '307' ), true ) ) {
            $redirect_type = $g['redirect_type'];
        }

        $all_rel   = preg_split( '/\s+/', trim( ( isset( $meta['_ta_rel_tags'][0] ) ? $meta['_ta_rel_tags'][0] : '' ) . ' ' . implode( ' ', $g['rel_tags'] ) ), -1, PREG_SPLIT_NO_EMPTY );
        $sponsored = in_array( 'sponsored', $all_rel, true );
        $rel_tags  = array_values( array_unique( array_diff( $all_rel, array( 'nofollow', 'sponsored' ) ) ) );

        $css = trim( ( isset( $meta['_ta_css_classes'][0] ) ? $meta['_ta_css_classes'][0] : '' ) . ' ' . $g['css_classes'] );

        $link_id = $this->create_link( array(
            'source'          => self::SOURCE,
            'old_id'          => $post_id,
            'title'           => $post->post_title,
            'slug'            => $post->post_name,
            'status'          => 'publish' === $post->post_status ? 'publish' : 'draft',
            'destination_url' => isset( $meta['_ta_destination_url'][0] ) ? $meta['_ta_destination_url'][0] : '',
            'redirect_type'   => $redirect_type,
            'nofollow'        => $this->resolve_flag( isset( $meta['_ta_no_follow'][0] ) ? $meta['_ta_no_follow'][0] : '', $g['nofollow'] ),
            'sponsored'       => $sponsored ? 'yes' : 'no',
            'new_window'      => $this->resolve_flag( isset( $meta['_ta_new_window'][0] ) ? $meta['_ta_new_window'][0] : '', $g['new_window'] ),
            'css_classes'     => $css,
            'pass_query_str'  => $this->resolve_flag( isset( $meta['_ta_pass_query_str'][0] ) ? $meta['_ta_pass_query_str'][0] : '', $g['pass_query_str'] ),
            'rel_tags'        => implode( ' ', $rel_tags ),
        ) );

        if ( ! $link_id ) {
            return;
        }

        if ( ! $this->dry_run ) {
            $this->migrate_categories( $post_id, $link_id );
        }
        $this->migrate_clicks( $post_id, $link_id );
    }

    private function migrate_categories( $old_post_id, $link_id ) {
        $terms = wp_get_object_terms( $old_post_id, 'thirstylink-category', array( 'orderby' => 'parent' ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return;
        }

        $term_id_map = array();
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

    private function migrate_clicks( $old_post_id, $link_id ) {
        global $wpdb;

        $clicks_table = $wpdb->prefix . 'ta_link_clicks';
        $meta_table   = $wpdb->prefix . 'ta_link_clicks_meta';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $clicks_table ) ) !== $clicks_table ) {
            return;
        }

        $has_meta = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) ) === $meta_table;

        $last_id = -1 === (int) $link_id ? false : get_post_meta( $link_id, KATINA_Link::LAST_CLICK_META, true );
        $cutoff  = '';
        if ( '' === $last_id || false === $last_id ) {
            $last_id = 0;
            // Links migrated by an earlier version carry no resume marker: only
            // take clicks older than the first one already recorded natively.
            if ( $link_id > 0 ) {
                $cutoff = (string) KATINA_Clicks_DB::get_earliest_clicked_at( $link_id );
            }
        }
        $last_id = (int) $last_id;

        if ( $this->dry_run ) {
            $sql  = "SELECT COUNT(*) FROM {$clicks_table} WHERE link_id = %d AND id > %d";
            $args = array( $old_post_id, $last_id );
            if ( $cutoff ) {
                $sql   .= ' AND date_clicked < %s';
                $args[] = $cutoff;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $this->results['clicks'] += (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
            return;
        }

        while ( true ) {
            $sql  = "SELECT id, date_clicked FROM {$clicks_table} WHERE link_id = %d AND id > %d";
            $args = array( $old_post_id, $last_id );
            if ( $cutoff ) {
                $sql   .= ' AND date_clicked < %s';
                $args[] = $cutoff;
            }
            $sql   .= ' ORDER BY id ASC LIMIT %d';
            $args[] = self::CLICK_BATCH;

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $clicks = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
            if ( empty( $clicks ) ) {
                break;
            }

            $click_meta = array();
            if ( $has_meta ) {
                $ids          = array_map( 'intval', wp_list_pluck( $clicks, 'id' ) );
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $meta_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT click_id, meta_key, meta_value FROM {$meta_table}
                     WHERE meta_key IN ('http_referer', 'browser_device', 'user_geolocation') AND click_id IN ({$placeholders})",
                    $ids
                ) );
                // phpcs:enable
                foreach ( $meta_rows as $row ) {
                    $click_meta[ (int) $row->click_id ][ $row->meta_key ] = (string) $row->meta_value;
                }
            }

            $rows = array();
            foreach ( $clicks as $click ) {
                $m          = isset( $click_meta[ (int) $click->id ] ) ? $click_meta[ (int) $click->id ] : array();
                $user_agent = isset( $m['browser_device'] ) ? $m['browser_device'] : '';
                $country    = isset( $m['user_geolocation'] ) ? strtoupper( $m['user_geolocation'] ) : '';
                $referrer   = isset( $m['http_referer'] ) ? $this->clean_referrer( $m['http_referer'] ) : '';

                $rows[] = array(
                    'link_id'         => $link_id,
                    'clicked_at'      => $click->date_clicked,
                    'referrer'        => $referrer,
                    'country_code'    => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
                    'user_agent_hash' => $user_agent ? md5( $user_agent ) : '',
                    'is_bot'          => $user_agent && KATINA_Bot_Detector::is_bot( $user_agent ) ? 1 : 0,
                );
            }

            $inserted = KATINA_Clicks_DB::bulk_insert( $rows );
            if ( false === $inserted ) {
                $this->results['errors']++;
                break;
            }

            $this->results['clicks'] += count( $rows );
            $last                     = end( $clicks );
            $last_id                  = (int) $last->id;
            update_post_meta( $link_id, KATINA_Link::LAST_CLICK_META, $last_id );

            $count = count( $clicks );
            unset( $clicks, $rows, $click_meta, $meta_rows );
            if ( self::CLICK_BATCH > $count ) {
                break;
            }
        }
    }

    private function clean_referrer( $referrer ) {
        $referrer = esc_url_raw( $referrer );
        if ( '' === $referrer ) {
            return '';
        }
        $parts = wp_parse_url( $referrer );
        $clean = ( isset( $parts['scheme'], $parts['host'] ) ? $parts['scheme'] . '://' . $parts['host'] : '' )
               . ( isset( $parts['path'] ) ? $parts['path'] : '' );
        return substr( $clean, 0, 2048 );
    }
}
