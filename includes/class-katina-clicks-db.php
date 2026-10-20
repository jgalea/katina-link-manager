<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Clicks_DB {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'katina_clicks';
    }

    public static function create_table() {
        global $wpdb;
        $table   = self::get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            link_id BIGINT UNSIGNED NOT NULL,
            clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            referrer VARCHAR(2048) DEFAULT '',
            country_code CHAR(2) DEFAULT '',
            user_agent_hash CHAR(32) DEFAULT '',
            visitor_hash CHAR(16) NOT NULL DEFAULT '',
            is_bot TINYINT(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_link_id (link_id),
            KEY idx_clicked_at (clicked_at),
            KEY idx_link_date (link_id, clicked_at),
            KEY idx_visitor (visitor_hash, clicked_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function insert( array $data ) {
        global $wpdb;
        return $wpdb->insert( self::get_table_name(), $data, array(
            '%d',   // link_id
            '%s',   // clicked_at
            '%s',   // referrer
            '%s',   // country_code
            '%s',   // user_agent_hash
            '%s',   // visitor_hash
            '%d',   // is_bot
        ) );
    }

    /**
     * Flags a visitor's clicks as bot when they hit $min_links or more distinct
     * links since $since, including the click just recorded. Returns true when
     * it flagged.
     */
    public static function flag_sweep( $visitor_hash, $since, $min_links ) {
        global $wpdb;
        $table = self::get_table_name();

        $links = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT link_id) FROM {$table} WHERE visitor_hash = %s AND clicked_at >= %s",
            $visitor_hash,
            $since
        ) );

        if ( $links < $min_links ) {
            return false;
        }

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET is_bot = 1 WHERE visitor_hash = %s AND clicked_at >= %s AND is_bot = 0",
            $visitor_hash,
            $since
        ) );

        return true;
    }

    public static function bulk_insert( array $rows ) {
        global $wpdb;
        if ( empty( $rows ) ) {
            return 0;
        }

        $table        = self::get_table_name();
        $placeholders = array();
        $values       = array();

        foreach ( $rows as $row ) {
            $placeholders[] = '(%d, %s, %s, %s, %s, %d)';
            $values[]       = (int) $row['link_id'];
            $values[]       = (string) $row['clicked_at'];
            $values[]       = isset( $row['referrer'] ) ? (string) $row['referrer'] : '';
            $values[]       = isset( $row['country_code'] ) ? (string) $row['country_code'] : '';
            $values[]       = isset( $row['user_agent_hash'] ) ? (string) $row['user_agent_hash'] : '';
            $values[]       = ! empty( $row['is_bot'] ) ? 1 : 0;
        }

        // $table is wpdb->prefix-derived (internal); $placeholders are static format strings.
        // $values are prepared via $wpdb->prepare() below.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = "INSERT INTO {$table} (link_id, clicked_at, referrer, country_code, user_agent_hash, is_bot) VALUES " . implode( ', ', $placeholders );

        return $wpdb->query( $wpdb->prepare( $sql, $values ) );
        // phpcs:enable
    }

    public static function get_earliest_clicked_at( $link_id ) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(clicked_at) FROM {$table} WHERE link_id = %d",
            (int) $link_id
        ) );
    }

    public static function get_clicks_for_link( $link_id, $days = 30 ) {
        global $wpdb;
        $table = self::get_table_name();
        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE link_id = %d AND clicked_at >= %s AND is_bot = 0",
            $link_id,
            $since
        ) );
    }

    public static function get_total_clicks( $link_id ) {
        global $wpdb;
        $table = self::get_table_name();

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE link_id = %d AND is_bot = 0",
            $link_id
        ) );
    }

    /**
     * Reporting queries below take an explicit [$from, $to) datetime window and an
     * optional $link_id. Passing 0 for $link_id reports across every link.
     *
     * $to is exclusive so callers can pass the day after the last day they want
     * and still capture clicks logged during it.
     */

    /**
     * Clicks grouped by UTC hour (the leading 13 chars of the stored datetime,
     * "Y-m-d H"). We bucket by hour in SQL with a plain string op — portable
     * across MySQL/MariaDB and SQLite, unlike date arithmetic — then the caller
     * shifts each hour to the site's local day. Hour granularity is exact for
     * whole-hour timezones; a half-hour zone can misplace clicks in the ~30 min
     * around local midnight onto the neighbouring day, which is immaterial for a
     * daily chart. Row count is bounded (<= 24 x days).
     */
    public static function get_clicks_by_hour( $from, $to, $link_id = 0 ) {
        global $wpdb;
        $table = self::get_table_name();

        if ( $link_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT SUBSTR(clicked_at, 1, 13) as utc_hour, COUNT(*) as clicks
                 FROM {$table}
                 WHERE is_bot = 0 AND link_id = %d AND clicked_at >= %s AND clicked_at < %s
                 GROUP BY utc_hour
                 ORDER BY utc_hour ASC",
                $link_id,
                $from,
                $to
            ) );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT SUBSTR(clicked_at, 1, 13) as utc_hour, COUNT(*) as clicks
             FROM {$table}
             WHERE is_bot = 0 AND clicked_at >= %s AND clicked_at < %s
             GROUP BY utc_hour
             ORDER BY utc_hour ASC",
            $from,
            $to
        ) );
    }

    public static function get_top_referrers( $from, $to, $limit = 10, $link_id = 0 ) {
        global $wpdb;
        $table = self::get_table_name();

        if ( $link_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT referrer, COUNT(*) as clicks
                 FROM {$table}
                 WHERE is_bot = 0 AND referrer != '' AND link_id = %d
                   AND clicked_at >= %s AND clicked_at < %s
                 GROUP BY referrer
                 ORDER BY clicks DESC
                 LIMIT %d",
                $link_id,
                $from,
                $to,
                $limit
            ) );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT referrer, COUNT(*) as clicks
             FROM {$table}
             WHERE is_bot = 0 AND referrer != ''
               AND clicked_at >= %s AND clicked_at < %s
             GROUP BY referrer
             ORDER BY clicks DESC
             LIMIT %d",
            $from,
            $to,
            $limit
        ) );
    }

    public static function get_top_countries( $from, $to, $limit = 20, $link_id = 0 ) {
        global $wpdb;
        $table = self::get_table_name();

        if ( $link_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT country_code, COUNT(*) as clicks
                 FROM {$table}
                 WHERE is_bot = 0 AND country_code != '' AND link_id = %d
                   AND clicked_at >= %s AND clicked_at < %s
                 GROUP BY country_code
                 ORDER BY clicks DESC
                 LIMIT %d",
                $link_id,
                $from,
                $to,
                $limit
            ) );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT country_code, COUNT(*) as clicks
             FROM {$table}
             WHERE is_bot = 0 AND country_code != ''
               AND clicked_at >= %s AND clicked_at < %s
             GROUP BY country_code
             ORDER BY clicks DESC
             LIMIT %d",
            $from,
            $to,
            $limit
        ) );
    }

    public static function get_clicks_in_range( $from, $to, $link_id = 0 ) {
        global $wpdb;
        $table = self::get_table_name();

        if ( $link_id > 0 ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE is_bot = 0 AND link_id = %d AND clicked_at >= %s AND clicked_at < %s",
                $link_id,
                $from,
                $to
            ) );
        }

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE is_bot = 0 AND clicked_at >= %s AND clicked_at < %s",
            $from,
            $to
        ) );
    }

    /**
     * Human clicks counted once per visitor per link per UTC day. Rows logged
     * before visitor hashes existed have no hash to group on, so each counts as
     * its own click.
     */
    public static function get_unique_clicks_in_range( $from, $to, $link_id = 0 ) {
        global $wpdb;
        $table    = self::get_table_name();
        $link_sql = $link_id > 0 ? $wpdb->prepare( ' AND link_id = %d', $link_id ) : '';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $hashed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM {$table}
                WHERE is_bot = 0 AND visitor_hash != '' AND clicked_at >= %s AND clicked_at < %s{$link_sql}
                GROUP BY visitor_hash, link_id, SUBSTR(clicked_at, 1, 10)
             ) u",
            $from,
            $to
        ) );

        $legacy = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE is_bot = 0 AND visitor_hash = '' AND clicked_at >= %s AND clicked_at < %s{$link_sql}",
            $from,
            $to
        ) );
        // phpcs:enable

        return $hashed + $legacy;
    }

    public static function get_top_links( $from, $to, $limit = 10 ) {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT c.link_id, p.post_title, COUNT(*) as clicks
             FROM {$table} c
             INNER JOIN {$wpdb->posts} p ON p.ID = c.link_id
             WHERE c.is_bot = 0 AND c.clicked_at >= %s AND c.clicked_at < %s
             GROUP BY c.link_id, p.post_title
             ORDER BY clicks DESC
             LIMIT %d",
            $from,
            $to,
            $limit
        ) );
    }

    /**
     * Rows for the CSV export. Bots are included here (with the flag) so an
     * export is a faithful dump of the log rather than a filtered view.
     */
    public static function get_clicks_for_export( $from, $to, $link_id = 0 ) {
        global $wpdb;
        $table = self::get_table_name();

        if ( $link_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT c.clicked_at, c.link_id, p.post_title, c.referrer, c.country_code, c.is_bot
                 FROM {$table} c
                 LEFT JOIN {$wpdb->posts} p ON p.ID = c.link_id
                 WHERE c.link_id = %d AND c.clicked_at >= %s AND c.clicked_at < %s
                 ORDER BY c.clicked_at ASC",
                $link_id,
                $from,
                $to
            ) );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT c.clicked_at, c.link_id, p.post_title, c.referrer, c.country_code, c.is_bot
             FROM {$table} c
             LEFT JOIN {$wpdb->posts} p ON p.ID = c.link_id
             WHERE c.clicked_at >= %s AND c.clicked_at < %s
             ORDER BY c.clicked_at ASC",
            $from,
            $to
        ) );
    }

    public static function cleanup_old_data( $days = 365 ) {
        global $wpdb;
        $table  = self::get_table_name();
        $before = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        return $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE clicked_at < %s",
            $before
        ) );
    }
}
