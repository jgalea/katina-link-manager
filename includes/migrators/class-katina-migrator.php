<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class KATINA_Migrator {

    protected $results = array(
        'links'      => 0,
        'categories' => 0,
        'clicks'     => 0,
        'skipped'    => 0,
        'errors'     => 0,
        'slug_mismatch' => array(),
    );

    protected $id_map = array();

    protected $dry_run = false;

    protected $adopt_prefix = false;

    protected $progress = null;

    public function set_dry_run( $dry_run ) {
        $this->dry_run = (bool) $dry_run;
        return $this;
    }

    public function set_adopt_prefix( $adopt ) {
        $this->adopt_prefix = $adopt;
        return $this;
    }

    public function set_progress( $callback ) {
        $this->progress = is_callable( $callback ) ? $callback : null;
        return $this;
    }

    abstract public static function is_available();

    abstract public static function get_source_name();

    abstract public static function get_source_count();

    abstract public function run();

    // Orphaned data — when the source plugin is inactive but its tables/posts still exist.
    abstract public static function has_orphaned_data();

    abstract public static function cleanup_orphaned_data();

    protected static function cleanup_generic( $post_type, array $taxonomies = array(), array $tables = array(), array $option_like_patterns = array() ) {
        global $wpdb;
        $results = array(
            'postmeta' => 0,
            'posts'    => 0,
            'terms'    => 0,
            'tables'   => 0,
            'options'  => 0,
        );

        if ( $post_type ) {
            $results['postmeta'] = (int) $wpdb->query( $wpdb->prepare(
                "DELETE pm FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = %s",
                $post_type
            ) );
            $results['posts'] = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->posts} WHERE post_type = %s",
                $post_type
            ) );
        }

        if ( $taxonomies ) {
            $placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE tr FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tt.taxonomy IN ({$placeholders})",
                $taxonomies
            ) );
            $results['terms'] = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ({$placeholders})",
                $taxonomies
            ) );
            $wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id NOT IN (SELECT term_id FROM {$wpdb->term_taxonomy})" );
        }

        foreach ( $tables as $table ) {
            $full = $wpdb->prefix . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full ) {
                $wpdb->query( "DROP TABLE IF EXISTS `{$full}`" );
                $results['tables']++;
            }
        }

        foreach ( $option_like_patterns as $pattern ) {
            $results['options'] += (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            ) );
        }

        return $results;
    }

    protected static function cpt_has_posts( $post_type ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
            $post_type
        ) ) > 0;
    }

    protected static function table_exists( $suffix ) {
        global $wpdb;
        $full = $wpdb->prefix . $suffix;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full;
    }

    protected function find_by_source_id( $source, $old_id ) {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %s
             WHERE p.post_type = 'katina_link' AND p.post_status <> 'trash'
             ORDER BY p.ID ASC LIMIT 1",
            KATINA_Link::SOURCE_META,
            $source . ':' . (int) $old_id
        ) );
        return $id ? (int) $id : 0;
    }

    protected function create_link( array $data ) {
        $slug      = isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '';
        $source    = isset( $data['source'] ) ? $data['source'] : '';
        $has_old   = isset( $data['old_id'] );
        $source_id = $has_old && $source ? $source . ':' . (int) $data['old_id'] : '';

        $existing_id = 0;
        if ( $source_id ) {
            $existing_id = $this->find_by_source_id( $source, $data['old_id'] );
        }
        if ( ! $existing_id && $slug ) {
            $existing = KATINA_Link::find_by_slug( $slug );
            $existing_id = $existing ? $existing->get_id() : 0;
        }

        if ( $existing_id ) {
            if ( $has_old ) {
                $this->id_map[ $data['old_id'] ] = $existing_id;
                if ( $source_id && ! $this->dry_run && get_post_meta( $existing_id, KATINA_Link::SOURCE_META, true ) !== $source_id ) {
                    update_post_meta( $existing_id, KATINA_Link::SOURCE_META, $source_id );
                }
            }
            $this->results['skipped']++;
            return $existing_id;
        }

        if ( $this->dry_run ) {
            $this->results['links']++;
            return -1;
        }

        $post_id = wp_insert_post( array(
            'post_type'   => 'katina_link',
            'post_status' => isset( $data['status'] ) ? $data['status'] : 'publish',
            'post_title'  => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
            'post_name'   => $slug,
        ), true );

        if ( is_wp_error( $post_id ) ) {
            $this->results['errors']++;
            return false;
        }

        if ( $slug && get_post_field( 'post_name', $post_id ) !== $slug ) {
            $this->results['slug_mismatch'][] = $slug;
        }

        $link = new KATINA_Link( $post_id );
        $link->save_meta( array(
            'destination_url' => isset( $data['destination_url'] ) ? $data['destination_url'] : '',
            'redirect_type'   => isset( $data['redirect_type'] ) ? $data['redirect_type'] : 'default',
            'nofollow'        => isset( $data['nofollow'] ) ? $data['nofollow'] : 'default',
            'sponsored'       => isset( $data['sponsored'] ) ? $data['sponsored'] : 'default',
            'new_window'      => isset( $data['new_window'] ) ? $data['new_window'] : 'default',
            'css_classes'     => isset( $data['css_classes'] ) ? $data['css_classes'] : '',
            'pass_query_str'  => isset( $data['pass_query_str'] ) ? $data['pass_query_str'] : 'default',
            'rel_tags'        => isset( $data['rel_tags'] ) ? $data['rel_tags'] : '',
        ) );

        if ( $source_id ) {
            update_post_meta( $post_id, KATINA_Link::SOURCE_META, $source_id );
        }

        if ( $has_old ) {
            $this->id_map[ $data['old_id'] ] = $post_id;
        }

        $this->results['links']++;
        return $post_id;
    }

    protected function map_category( $name, $parent_id = 0 ) {
        $existing = get_term_by( 'name', $name, 'katina_category' );
        if ( $existing && (int) $existing->parent === (int) $parent_id ) {
            return $existing->term_id;
        }

        $result = wp_insert_term( $name, 'katina_category', array(
            'parent' => (int) $parent_id,
        ) );

        if ( is_wp_error( $result ) ) {
            if ( isset( $result->error_data['term_exists'] ) ) {
                return (int) $result->error_data['term_exists'];
            }
            $this->results['errors']++;
            return 0;
        }

        $this->results['categories']++;
        return (int) $result['term_id'];
    }

    protected function import_click( $link_id, array $click_data ) {
        $result = KATINA_Clicks_DB::insert( array(
            'link_id'        => (int) $link_id,
            'clicked_at'     => isset( $click_data['clicked_at'] ) ? $click_data['clicked_at'] : current_time( 'mysql', true ),
            'referrer'       => isset( $click_data['referrer'] ) ? $click_data['referrer'] : '',
            'country_code'   => isset( $click_data['country_code'] ) ? $click_data['country_code'] : '',
            'user_agent_hash' => isset( $click_data['user_agent_hash'] ) ? $click_data['user_agent_hash'] : '',
            'is_bot'         => isset( $click_data['is_bot'] ) ? (int) $click_data['is_bot'] : 0,
        ) );

        if ( false === $result ) {
            $this->results['errors']++;
            return false;
        }

        $this->results['clicks']++;
        return true;
    }

    protected function normalize_bool( $value ) {
        if ( is_bool( $value ) ) {
            return $value ? 'yes' : 'no';
        }
        $normalized = strtolower( trim( (string) $value ) );
        if ( in_array( $normalized, array( 'yes', '1', 'true', 'on' ), true ) ) {
            return 'yes';
        }
        if ( in_array( $normalized, array( 'no', '0', 'false', 'off' ), true ) ) {
            return 'no';
        }
        return 'default';
    }

    public function get_results() {
        return $this->results;
    }

    public function get_id_map() {
        return $this->id_map;
    }
}
