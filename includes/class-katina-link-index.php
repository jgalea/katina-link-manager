<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds and queries an index of every link found in site content.
 *
 * Reads post content straight from the database rather than crawling the site,
 * so drafts, private posts, password-protected content and noindexed pages are
 * all covered. Scanning runs in batches to survive large sites.
 */
class KATINA_Link_Index {

    const SCHEMA_VERSION = 1;
    const SCHEMA_OPTION  = 'katina_link_index_schema_version';
    const STATE_OPTION   = 'katina_link_index_state';
    const BATCH_SIZE     = 40;

    const TYPE_INTERNAL = 'internal';
    const TYPE_EXTERNAL = 'external';
    const TYPE_MANAGED  = 'managed';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade_schema' ) );
        add_action( 'save_post', array( __CLASS__, 'reindex_on_save' ), 20, 2 );
        add_action( 'before_delete_post', array( __CLASS__, 'purge_post' ) );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'katina_link_index';
    }

    public static function create_table() {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        // url is unindexed TEXT (URLs blow past MySQL key limits); url_hash carries
        // the lookups. target_id is 0 for external links and for internal URLs that
        // don't resolve to a post (term archives, date archives, the front page).
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT UNSIGNED NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            link_type VARCHAR(16) NOT NULL DEFAULT 'external',
            url TEXT NOT NULL,
            url_hash CHAR(32) NOT NULL,
            anchor VARCHAR(191) NOT NULL DEFAULT '',
            rel VARCHAR(100) NOT NULL DEFAULT '',
            scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_source (source_id),
            KEY idx_target (target_id),
            KEY idx_type (link_type),
            KEY idx_url_hash (url_hash)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
    }

    public static function maybe_upgrade_schema() {
        $current = (int) get_option( self::SCHEMA_OPTION, 0 );
        if ( $current < self::SCHEMA_VERSION ) {
            self::create_table();
        }
    }

    // ── Scanning ──────────────────────────────────────────────────────────────

    /**
     * Post types worth indexing. Attachments and the link CPT itself are noise.
     */
    public static function scannable_post_types() {
        $types = get_post_types( array( 'public' => true ), 'names' );
        unset( $types['attachment'], $types['katina_link'] );
        return apply_filters( 'katina_link_index_post_types', array_values( $types ) );
    }

    /**
     * Statuses worth indexing. Unlike a crawler we can see everything, which is
     * the point — an orphan draft is still an orphan once published.
     */
    public static function scannable_post_statuses() {
        return apply_filters(
            'katina_link_index_post_statuses',
            array( 'publish', 'future', 'draft', 'pending', 'private' )
        );
    }

    public static function count_scannable() {
        global $wpdb;
        $types    = self::scannable_post_types();
        $statuses = self::scannable_post_statuses();
        if ( empty( $types ) || empty( $statuses ) ) {
            return 0;
        }
        $type_ph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $status_ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$type_ph}) AND post_status IN ({$status_ph})",
                array_merge( $types, $statuses )
            )
        );
    }

    /**
     * Index one batch of posts. Returns the progress state so the caller can
     * decide whether to keep going.
     */
    public static function scan_batch( $offset = 0, $batch_size = self::BATCH_SIZE ) {
        global $wpdb;

        $types    = self::scannable_post_types();
        $statuses = self::scannable_post_statuses();
        $total    = self::count_scannable();

        if ( empty( $types ) || empty( $statuses ) ) {
            return array( 'offset' => 0, 'total' => 0, 'done' => true, 'links' => 0 );
        }

        $type_ph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $status_ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $params    = array_merge( $types, $statuses, array( (int) $batch_size, (int) $offset ) );

        // Ordered by ID so paging stays stable while the scan runs.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                 WHERE post_type IN ({$type_ph}) AND post_status IN ({$status_ph})
                 ORDER BY ID ASC LIMIT %d OFFSET %d",
                $params
            )
        );

        $links_found = 0;
        foreach ( $posts as $post ) {
            $links_found += self::index_post( (int) $post->ID, $post->post_content );
        }

        $next = $offset + count( $posts );
        $done = count( $posts ) < $batch_size || $next >= $total;

        $state = array(
            'offset'     => $done ? 0 : $next,
            'total'      => $total,
            'processed'  => $next,
            'done'       => $done,
            'links'      => $links_found,
        );

        if ( $done ) {
            update_option( self::STATE_OPTION, array(
                'last_run'  => current_time( 'mysql' ),
                'total'     => $total,
            ), false );
        }

        return $state;
    }

    /**
     * Replace the index rows for a single post.
     */
    public static function index_post( $post_id, $content = null ) {
        global $wpdb;

        if ( $content === null ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                return 0;
            }
            $content = $post->post_content;
        }

        $content = self::gather_content( $post_id, $content );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( self::table_name(), array( 'source_id' => $post_id ), array( '%d' ) );

        $links = self::extract_links( $content );
        if ( empty( $links ) ) {
            return 0;
        }

        $inserted = 0;
        foreach ( $links as $link ) {
            $classified = self::classify( $link['url'] );
            if ( $classified === null ) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                self::table_name(),
                array(
                    'source_id'  => $post_id,
                    'target_id'  => $classified['target_id'],
                    'link_type'  => $classified['type'],
                    'url'        => $link['url'],
                    'url_hash'   => md5( $link['url'] ),
                    'anchor'     => mb_substr( $link['anchor'], 0, 191 ),
                    'rel'        => mb_substr( $link['rel'], 0, 100 ),
                    'scanned_at' => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
            $inserted++;
        }

        return $inserted;
    }

    /**
     * Page builders keep their markup outside post_content. Elementor is the
     * common case; anything else can hook the filter.
     */
    private static function gather_content( $post_id, $content ) {
        $elementor = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! empty( $elementor ) && is_string( $elementor ) ) {
            // Stored as escaped JSON — decode so hrefs read as plain URLs.
            $decoded  = json_decode( $elementor, true );
            $content .= ' ' . ( is_array( $decoded ) ? wp_json_encode( $decoded ) : $elementor );
        }
        return apply_filters( 'katina_link_index_content', $content, $post_id );
    }

    /**
     * Pull anchors out of raw content. Runs on stored markup, not the_content,
     * so it never triggers the auto-linker or other output filters.
     */
    public static function extract_links( $content ) {
        if ( empty( $content ) || stripos( $content, 'href' ) === false ) {
            return array();
        }

        $links = array();

        // Standard anchors, with anchor text.
        if ( preg_match_all( '/<a\s([^>]*)href=["\']([^"\']+)["\']([^>]*)>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $attrs = $match[1] . ' ' . $match[3];
                $rel   = '';
                if ( preg_match( '/rel=["\']([^"\']*)["\']/i', $attrs, $rel_match ) ) {
                    $rel = $rel_match[1];
                }
                $links[] = array(
                    'url'    => html_entity_decode( trim( $match[2] ), ENT_QUOTES, 'UTF-8' ),
                    'anchor' => trim( wp_strip_all_tags( $match[4] ) ),
                    'rel'    => $rel,
                );
            }
        }

        // Elementor and similar store bare "url":"https://..." pairs with no anchor.
        if ( preg_match_all( '/"url"\s*:\s*"(https?:\\\\?\/\\\\?\/[^"]+)"/i', $content, $json_matches ) ) {
            foreach ( $json_matches[1] as $url ) {
                $url = stripslashes( $url );
                $links[] = array(
                    'url'    => $url,
                    'anchor' => '',
                    'rel'    => '',
                );
            }
        }

        return $links;
    }

    /**
     * Decide what a URL points at. Returns null for links we don't index
     * (anchors, mailto, tel, javascript, empty).
     */
    public static function classify( $url ) {
        $url = trim( $url );
        if ( $url === '' || str_starts_with( $url, '#' ) ) {
            return null;
        }
        if ( preg_match( '/^(mailto:|tel:|javascript:|data:|sms:)/i', $url ) ) {
            return null;
        }

        $home      = home_url();
        $home_host = wp_parse_url( $home, PHP_URL_HOST );

        // Protocol-relative and root-relative URLs are ours by definition.
        if ( str_starts_with( $url, '//' ) ) {
            $absolute = ( is_ssl() ? 'https:' : 'http:' ) . $url;
        } elseif ( str_starts_with( $url, '/' ) ) {
            $absolute = home_url( $url );
        } else {
            $absolute = $url;
        }

        $host = wp_parse_url( $absolute, PHP_URL_HOST );
        if ( empty( $host ) ) {
            return null;
        }

        if ( strtolower( $host ) !== strtolower( (string) $home_host ) ) {
            return array( 'type' => self::TYPE_EXTERNAL, 'target_id' => 0 );
        }

        // A cloaked Katina link is internal markup pointing somewhere external —
        // it deserves its own bucket rather than being counted as an internal link.
        $managed_id = self::resolve_managed_link( $absolute );
        if ( $managed_id ) {
            return array( 'type' => self::TYPE_MANAGED, 'target_id' => $managed_id );
        }

        return array( 'type' => self::TYPE_INTERNAL, 'target_id' => self::resolve_internal_target( $absolute ) );
    }

    /**
     * Map /go/some-slug/ back to the katina_link post it belongs to.
     */
    private static function resolve_managed_link( $url ) {
        $prefix = get_option( 'katina_link_prefix', 'go' );
        if ( empty( $prefix ) ) {
            return 0;
        }

        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        $home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        if ( $home_path !== '' && str_starts_with( $path, $home_path . '/' ) ) {
            $path = substr( $path, strlen( $home_path ) + 1 );
        }

        if ( ! str_starts_with( $path, $prefix . '/' ) ) {
            return 0;
        }

        $slug = substr( $path, strlen( $prefix ) + 1 );
        $slug = trim( explode( '/', $slug )[0] );
        if ( $slug === '' ) {
            return 0;
        }

        $link = get_page_by_path( $slug, OBJECT, 'katina_link' );
        return $link ? (int) $link->ID : 0;
    }

    /**
     * url_to_postid() is the reliable resolver but it's expensive, so results are
     * memoised per request — a big site links to the same pages over and over.
     */
    private static function resolve_internal_target( $url ) {
        static $cache = array();

        $key = md5( $url );
        if ( isset( $cache[ $key ] ) ) {
            return $cache[ $key ];
        }

        $post_id = url_to_postid( $url );

        // url_to_postid() misses drafts and pending posts (no permalink yet), so
        // fall back to matching the last path segment against post_name.
        if ( ! $post_id ) {
            $post_id = self::resolve_by_slug( $url );
        }

        $cache[ $key ] = (int) $post_id;
        return $cache[ $key ];
    }

    private static function resolve_by_slug( $url ) {
        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        if ( $path === '' ) {
            return 0;
        }
        $segments = explode( '/', $path );
        $slug     = sanitize_title( end( $segments ) );
        if ( $slug === '' ) {
            return 0;
        }

        global $wpdb;
        $types    = self::scannable_post_types();
        if ( empty( $types ) ) {
            return 0;
        }
        $type_ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ({$type_ph}) LIMIT 1",
                array_merge( array( $slug ), $types )
            )
        );
    }

    public static function reindex_on_save( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! in_array( $post->post_type, self::scannable_post_types(), true ) ) {
            return;
        }
        if ( ! in_array( $post->post_status, self::scannable_post_statuses(), true ) ) {
            self::purge_post( $post_id );
            return;
        }
        self::index_post( $post_id, $post->post_content );
    }

    public static function purge_post( $post_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( self::table_name(), array( 'source_id' => $post_id ), array( '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update( self::table_name(), array( 'target_id' => 0 ), array( 'target_id' => $post_id ), array( '%d' ), array( '%d' ) );
    }

    public static function truncate() {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "TRUNCATE TABLE {$table}" );
        delete_option( self::STATE_OPTION );
    }

    // ── Queries ───────────────────────────────────────────────────────────────

    public static function last_run() {
        $state = get_option( self::STATE_OPTION, array() );
        return isset( $state['last_run'] ) ? $state['last_run'] : '';
    }

    public static function has_index() {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 || self::last_run() !== '';
    }

    /**
     * One row per post with its link counts. $args: post_type, orphans_only,
     * search, orderby, order, per_page, page.
     */
    public static function get_report( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'post_type'    => '',
            'orphans_only' => false,
            'search'       => '',
            'orderby'      => 'incoming',
            'order'        => 'ASC',
            'per_page'     => 50,
            'page'         => 1,
        );
        $args = wp_parse_args( $args, $defaults );

        $table    = self::table_name();
        $types    = $args['post_type'] ? array( $args['post_type'] ) : self::scannable_post_types();
        $statuses = self::scannable_post_statuses();
        if ( empty( $types ) || empty( $statuses ) ) {
            return array( 'rows' => array(), 'total' => 0 );
        }

        $type_ph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $status_ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $params    = array_merge( $types, $statuses );

        $where = "p.post_type IN ({$type_ph}) AND p.post_status IN ({$status_ph})";
        if ( $args['search'] !== '' ) {
            $where   .= ' AND p.post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        }

        $orderby_map = array(
            'title'    => 'p.post_title',
            'incoming' => 'incoming',
            'outgoing' => 'outgoing_internal',
            'external' => 'outgoing_external',
            'managed'  => 'outgoing_managed',
            'date'     => 'p.post_date',
        );
        $orderby = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'incoming';
        $order   = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        $per_page = max( 1, (int) $args['per_page'] );
        $offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

        // Each count comes from its own pre-aggregated derived table. Correlated
        // subqueries would re-run per post row, which falls over on a large site;
        // these are materialised once and joined on the indexed post ID.
        $joins = "
            LEFT JOIN ( SELECT target_id AS pid, COUNT(*) AS c FROM {$table} WHERE link_type = 'internal' AND target_id > 0 GROUP BY target_id ) inc ON inc.pid = p.ID
            LEFT JOIN ( SELECT source_id AS pid, COUNT(*) AS c FROM {$table} WHERE link_type = 'internal' GROUP BY source_id ) oi ON oi.pid = p.ID
            LEFT JOIN ( SELECT source_id AS pid, COUNT(*) AS c FROM {$table} WHERE link_type = 'external' GROUP BY source_id ) oe ON oe.pid = p.ID
            LEFT JOIN ( SELECT source_id AS pid, COUNT(*) AS c FROM {$table} WHERE link_type = 'managed' GROUP BY source_id ) om ON om.pid = p.ID";

        // No matching row in the incoming aggregate is exactly what an orphan is.
        if ( $args['orphans_only'] ) {
            $where .= ' AND inc.pid IS NULL';
        }

        $sql = "SELECT p.ID, p.post_title, p.post_type, p.post_status, p.post_date,
                    COALESCE(inc.c, 0) AS incoming,
                    COALESCE(oi.c, 0) AS outgoing_internal,
                    COALESCE(oe.c, 0) AS outgoing_external,
                    COALESCE(om.c, 0) AS outgoing_managed
                FROM {$wpdb->posts} p
                {$joins}
                WHERE {$where}
                ORDER BY {$orderby} {$order}, p.ID ASC
                LIMIT %d OFFSET %d";

        $query_params = array_merge( $params, array( $per_page, $offset ) );

        // $sql is assembled from validated identifiers and placeholders above; the
        // sniff can't follow the variable into prepare().
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A );

        // The count only needs the incoming aggregate, and only when filtering orphans.
        $count_join = $args['orphans_only']
            ? "LEFT JOIN ( SELECT target_id AS pid FROM {$table} WHERE link_type = 'internal' AND target_id > 0 GROUP BY target_id ) inc ON inc.pid = p.ID"
            : '';
        $count_sql  = "SELECT COUNT(*) FROM {$wpdb->posts} p {$count_join} WHERE {$where}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

        return array( 'rows' => $rows ?: array(), 'total' => $total );
    }

    /**
     * Posts with no incoming internal links, worst first.
     */
    public static function get_orphans( $limit = 50, $post_type = '' ) {
        $report = self::get_report( array(
            'post_type'    => $post_type,
            'orphans_only' => true,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'per_page'     => $limit,
        ) );
        return $report['rows'];
    }

    public static function get_links_for_post( $post_id, $direction = 'outgoing' ) {
        global $wpdb;
        $table  = self::table_name();
        $column = $direction === 'incoming' ? 'target_id' : 'source_id';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %d ORDER BY link_type ASC, id ASC", $post_id ),
            ARRAY_A
        );
    }

    public static function totals() {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT link_type, COUNT(*) AS total FROM {$table} GROUP BY link_type", ARRAY_A );

        $totals = array( self::TYPE_INTERNAL => 0, self::TYPE_EXTERNAL => 0, self::TYPE_MANAGED => 0 );
        foreach ( $rows ?: array() as $row ) {
            $totals[ $row['link_type'] ] = (int) $row['total'];
        }
        return $totals;
    }

    // ── SEO plugin metadata ───────────────────────────────────────────────────

    /**
     * Read the SEO title/description from whichever SEO plugin is active.
     */
    public static function get_seo_meta( $post_id ) {
        $map = array(
            array( 'title' => '_yoast_wpseo_title', 'desc' => '_yoast_wpseo_metadesc' ),
            array( 'title' => 'rank_math_title', 'desc' => 'rank_math_description' ),
            array( 'title' => '_seopress_titles_title', 'desc' => '_seopress_titles_desc' ),
        );

        foreach ( $map as $keys ) {
            $title = get_post_meta( $post_id, $keys['title'], true );
            $desc  = get_post_meta( $post_id, $keys['desc'], true );
            if ( ! empty( $title ) || ! empty( $desc ) ) {
                return array( 'title' => (string) $title, 'description' => (string) $desc );
            }
        }

        return array( 'title' => '', 'description' => '' );
    }
}
