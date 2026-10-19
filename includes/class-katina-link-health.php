<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Link_Health {

    const META_STATUS     = '_katina_health_status';
    const META_HTTP_CODE  = '_katina_health_http_code';
    const META_CHECKED_AT = '_katina_health_checked_at';
    const META_ERROR      = '_katina_health_error';
    const CRON_HOOK       = 'katina_health_check_cron';
    const RECHECK_HOOK    = 'katina_health_recheck_drain';
    const BATCH_SIZE      = 20;
    const MAX_BODY_BYTES  = 262144;
    const MAX_REDIRECTS   = 5;

    public static function init() {
        if ( ! self::is_enabled() ) {
            return;
        }
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_batch_check' ) );
        add_action( self::RECHECK_HOOK, array( __CLASS__, 'drain_recheck_queue' ) );
    }

    public static function is_enabled() {
        return get_option( 'katina_enable_link_health', 'no' ) === 'yes';
    }

    public static function schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'twicedaily', self::CRON_HOOK );
        }
    }

    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    public static function run_batch_check() {
        global $wpdb;

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

        $post_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = %s
            WHERE p.post_type = 'katina_link'
              AND p.post_status = 'publish'
              AND ( pm.meta_value IS NULL OR pm.meta_value < %s )
            ORDER BY pm.meta_value ASC
            LIMIT %d",
            self::META_CHECKED_AT,
            $cutoff,
            self::BATCH_SIZE
        ) );

        foreach ( $post_ids as $post_id ) {
            self::check_link( (int) $post_id );
        }
    }

    public static function check_link( $post_id ) {
        $link = new KATINA_Link( $post_id );
        $url  = $link->get_destination_url();

        if ( ! $url ) {
            $result = self::result( 'no_url', '', '' );
        } elseif ( ! KATINA_Link::is_safe_destination( $url ) || ! self::is_safe_outbound_host( $url ) ) {
            // SSRF guards: scheme allowlist + private/reserved IP block.
            $result = self::result( 'error', '', 'Refused: unsafe destination scheme or private IP' );
        } else {
            // Claim the slot first so a link that times out the cron run isn't
            // re-picked at the head of every following batch.
            update_post_meta( $post_id, self::META_CHECKED_AT, gmdate( 'Y-m-d H:i:s' ) );
            $result = self::classify( $url );
        }

        update_post_meta( $post_id, self::META_STATUS, $result['status'] );
        update_post_meta( $post_id, self::META_HTTP_CODE, $result['code'] );
        update_post_meta( $post_id, self::META_CHECKED_AT, gmdate( 'Y-m-d H:i:s' ) );
        update_post_meta( $post_id, self::META_ERROR, $result['error'] );
    }

    /**
     * Fetch a destination and classify it without touching post meta.
     *
     * Returns array( 'status' => string, 'code' => int|string, 'error' => string ).
     */
    public static function classify( $url ) {
        // GET rather than HEAD: we need the final URL after redirects and a slice
        // of the body to spot affiliate networks that answer 200 on a dead link.
        $args = array(
            'timeout'             => 15,
            'sslverify'           => true,
            'reject_unsafe_urls'  => true,
            'user-agent'          => 'Katina Link Manager Health Checker (WordPress)',
            'limit_response_size' => self::MAX_BODY_BYTES,
        );

        list( $response, $final_url ) = self::fetch( $url, $args );
        $code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

        // A 4xx that looks like bot-protection or an auth refusal (Cloudflare,
        // Akamai, tracking redirectors, SaaS hosts) usually means the destination is
        // alive but rejects a bare HTTP client — not a broken link. Retry once with
        // browser-style headers before classifying. If it still refuses us, downgrade
        // to 'unknown' rather than 'broken': a real visitor with a browser session
        // typically passes where an automated client cannot. Only 404/410 are treated
        // as genuinely dead.
        $bot_block_codes = array( 400, 401, 403, 405, 406, 429 );
        if ( in_array( $code, $bot_block_codes, true ) ) {
            $browser_args = array_merge( $args, array(
                'user-agent'          => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'headers'             => array(
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Sec-Fetch-Dest'  => 'document',
                    'Sec-Fetch-Mode'  => 'navigate',
                    'Sec-Fetch-Site'  => 'none',
                ),
            ) );
            list( $browser_response, $browser_final_url ) = self::fetch( $url, $browser_args );
            if ( ! is_wp_error( $browser_response ) ) {
                $browser_code = (int) wp_remote_retrieve_response_code( $browser_response );
                if ( $browser_code < 400 || 404 === $browser_code || 410 === $browser_code ) {
                    // Either it resolved, or it's genuinely dead (404/410) even for a
                    // browser — let it fall through to normal classification.
                    $response  = $browser_response;
                    $final_url = $browser_final_url;
                    $code      = $browser_code;
                } elseif ( 400 === $browser_code && ! self::is_bot_challenge( $browser_response ) ) {
                    // A plain 400 with no challenge page is a tracker rejecting the
                    // link itself (e.g. a deactivated campaign returning
                    // {"status":false}), not bot protection.
                    return self::result( 'broken', 400, 'Tracking link rejected (400 Bad Request) for a browser too; the campaign is probably deactivated' );
                } elseif ( in_array( $browser_code, $bot_block_codes, true ) ) {
                    // Still refused with a realistic browser profile — treat as
                    // indeterminate rather than broken; humans typically get through.
                    return self::result( 'unknown', $browser_code, 'Blocked by bot protection or auth refusal (browser UA also refused)' );
                }
            }
        }

        if ( is_wp_error( $response ) ) {
            return self::result( 'error', '', $response->get_error_message() );
        }

        $status = self::code_to_status( $code );
        if ( 'healthy' !== $status ) {
            return self::result( $status, $code, '' );
        }

        // A 2xx/3xx only proves *something* answered. Affiliate networks answer
        // dead links with a 200 "merchant closed" or generic comparison page, so
        // inspect where the redirect chain actually ended.
        $is_html   = false !== stripos( (string) wp_remote_retrieve_header( $response, 'content-type' ), 'html' );
        $dead_end  = self::detect_dead_end( $final_url, $is_html ? wp_remote_retrieve_body( $response ) : '' );
        if ( $dead_end ) {
            return self::result( 'broken', $code, $dead_end );
        }

        if ( self::lost_tracking( $url, $final_url ) ) {
            return self::result(
                'unknown',
                $code,
                sprintf( 'Landed on %s with none of the link\'s tracking parameters; check the affiliate program is still active', $final_url )
            );
        }

        return self::result( 'healthy', $code, '' );
    }

    private static function result( $status, $code, $error ) {
        return array(
            'status' => $status,
            'code'   => $code,
            'error'  => $error,
        );
    }

    /**
     * GET with redirects followed by hand, so every hop goes through the same
     * scheme and private-IP checks as the first URL. Core's reject_unsafe_urls
     * misses link-local (169.254/16, cloud metadata) and IPv6 targets.
     *
     * Returns array( response|WP_Error, final URL ).
     */
    private static function fetch( $url, $args ) {
        $args['redirection'] = 0;
        for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
            if ( $hop > 0 && ( ! KATINA_Link::is_safe_destination( $url ) || ! self::is_safe_outbound_host( $url ) ) ) {
                return array( new WP_Error( 'katina_unsafe_redirect', 'Refused: redirect to an unsafe destination scheme or private IP' ), $url );
            }
            $response = wp_remote_get( $url, $args );
            if ( is_wp_error( $response ) ) {
                return array( $response, $url );
            }
            $code     = (int) wp_remote_retrieve_response_code( $response );
            $location = wp_remote_retrieve_header( $response, 'location' );
            if ( is_array( $location ) ) {
                $location = end( $location );
            }
            if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) || ! $location ) {
                return array( $response, $url );
            }
            $url = WP_Http::make_absolute_url( $location, $url );
        }
        return array( new WP_Error( 'katina_too_many_redirects', 'Too many redirects' ), $url );
    }

    /**
     * Pages that answer 200 but mean the link is dead. Patterns are matched
     * case-insensitively against the final URL; body phrases against the first
     * MAX_BODY_BYTES of an HTML page. Both lists are filterable.
     */
    private static function detect_dead_end( $final_url, $body ) {
        $url_patterns = apply_filters(
            'katina_health_dead_end_url_patterns',
            array(
                '#awin1\.com/closedMerchant#i'             => 'Affiliate network reports the merchant program as closed',
                '#comparison\.financeads\.net#i'           => 'Affiliate network sends visitors to a generic comparison page instead of the merchant',
                '#bluesnap\.com/jsp/not_found#i'           => 'Payment provider reports the product as unavailable',
                '#typeform\.com/private-typeform#i'        => 'Destination form is private or no longer published',
                '#^https?://[^/?\#]+/(?:[^?\#]*/)?(?:closure|closed|shutdown)/?(?:[?\#]|$)#i' => 'Destination is a closure notice',
            )
        );
        foreach ( $url_patterns as $pattern => $reason ) {
            if ( preg_match( $pattern, $final_url ) ) {
                return $reason . ' (' . $final_url . ')';
            }
        }

        if ( '' === (string) $body ) {
            return '';
        }

        if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $body, $m ) ) {
            $title = wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES ) );
            if ( preg_match( '#page not found|\b404\b\W{0,3}(?:not found|error|page)|\berror\W{0,3}404\b|^\W*404\W*$#i', trim( $title ) ) ) {
                return 'Destination page is a "not found" page (' . $final_url . ')';
            }
        }

        $phrases = apply_filters(
            'katina_health_dead_end_phrases',
            array( 'has permanently closed', 'has been permanently closed' )
        );
        // strip_tags, not wp_strip_all_tags: core's lazy <script> regex takes
        // seconds on a hostile 256 KB body. Script text can't hold these phrases
        // in any way that matters.
        $text = strip_tags( $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- wp_strip_all_tags() runs a lazy <script> regex that takes seconds on a hostile 256 KB body.
        foreach ( $phrases as $phrase ) {
            if ( false !== stripos( $text, $phrase ) ) {
                return sprintf( 'Destination says "%s" (%s)', $phrase, $final_url );
            }
        }

        return '';
    }

    /**
     * True when the link carried query parameters, the chain ended on a
     * different host, and none of those parameters survived. That's the
     * typical shape of a tracker that silently dropped a dead campaign.
     */
    private static function lost_tracking( $destination, $final_url ) {
        $dest_query  = (string) wp_parse_url( $destination, PHP_URL_QUERY );
        $final_query = (string) wp_parse_url( $final_url, PHP_URL_QUERY );
        if ( '' === $dest_query || '' !== $final_query ) {
            return false;
        }
        return self::bare_host( $destination ) !== self::bare_host( $final_url );
    }

    private static function bare_host( $url ) {
        return preg_replace( '#^www\.#', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
    }

    private static function is_bot_challenge( $response ) {
        if ( wp_remote_retrieve_header( $response, 'cf-mitigated' ) ) {
            return true;
        }
        $body = (string) wp_remote_retrieve_body( $response );
        foreach ( array( 'just a moment', 'challenge-platform', 'captcha', 'attention required', 'access denied', 'verify you are human' ) as $marker ) {
            if ( false !== stripos( $body, $marker ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reject destinations that resolve to private/reserved/loopback IPs to
     * prevent SSRF against internal services and cloud metadata endpoints
     * (e.g. 169.254.169.254 on AWS).
     */
    private static function is_safe_outbound_host( $url ) {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) {
            return false;
        }

        // Resolve and reject if any A/AAAA points to a private/reserved range.
        $ips = array();
        $a   = @gethostbynamel( $host );
        if ( is_array( $a ) ) {
            $ips = array_merge( $ips, $a );
        }
        $records = @dns_get_record( $host, DNS_AAAA );
        if ( is_array( $records ) ) {
            foreach ( $records as $r ) {
                if ( ! empty( $r['ipv6'] ) ) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        if ( empty( $ips ) ) {
            // Hostname doesn't resolve — treat as unsafe (will be marked error).
            return false;
        }
        foreach ( $ips as $ip ) {
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return false;
            }
        }
        return true;
    }

    private static function code_to_status( $code ) {
        if ( $code >= 200 && $code < 400 ) {
            return 'healthy';
        }
        // Only 404 (Not Found) and 410 (Gone) reliably indicate a dead link.
        if ( 404 === $code || 410 === $code ) {
            return 'broken';
        }
        // Other 4xx were already retried as a browser in classify(); anything still
        // here falls through to 'unknown' for manual review.
        if ( $code >= 500 ) {
            return 'server_error';
        }
        return 'unknown';
    }

    /**
     * Schedule a full recheck of every link to run in the background.
     * Returns the number of links queued.
     *
     * Implementation: invalidate all checked-at timestamps so the existing
     * batch query picks every link up, then schedule a single drain event
     * that chains itself until the queue is empty. Avoids the multi-minute
     * synchronous request the old check_all_now() used to produce.
     */
    public static function queue_recheck_all() {
        global $wpdb;

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s",
            'katina_link',
            'publish'
        ) );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %s
             WHERE meta_key = %s AND post_id IN (
                SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s
             )",
            '1970-01-01 00:00:00',
            self::META_CHECKED_AT,
            'katina_link',
            'publish'
        ) );

        if ( ! wp_next_scheduled( self::RECHECK_HOOK ) ) {
            wp_schedule_single_event( time() + 5, self::RECHECK_HOOK );
        }

        return $count;
    }

    /**
     * Drain handler — runs one batch and schedules the next if work remains.
     */
    public static function drain_recheck_queue() {
        self::run_batch_check();

        global $wpdb;
        $cutoff   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        $remaining = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type='katina_link' AND p.post_status='publish'
               AND ( pm.meta_value IS NULL OR pm.meta_value < %s )",
            self::META_CHECKED_AT,
            $cutoff
        ) );

        if ( $remaining > 0 && ! wp_next_scheduled( self::RECHECK_HOOK ) ) {
            wp_schedule_single_event( time() + 10, self::RECHECK_HOOK );
        }
    }

    public static function get_summary() {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.meta_value AS status, COUNT(*) AS cnt
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = %s
            WHERE p.post_type = 'katina_link'
              AND p.post_status = 'publish'
            GROUP BY pm.meta_value",
            self::META_STATUS
        ) );

        $summary = array(
            'healthy'      => 0,
            'broken'       => 0,
            'server_error' => 0,
            'error'        => 0,
            'no_url'       => 0,
            'unknown'      => 0,
            'unchecked'    => 0,
        );

        foreach ( $rows as $row ) {
            if ( array_key_exists( $row->status, $summary ) ) {
                $summary[ $row->status ] = (int) $row->cnt;
            }
        }

        $total_published = (int) wp_count_posts( 'katina_link' )->publish;
        $checked         = array_sum( $summary ) - $summary['unchecked'];
        $summary['unchecked'] = max( 0, $total_published - $checked );

        return $summary;
    }

    public static function get_status_label( $status ) {
        $labels = array(
            'healthy'      => __( 'Healthy', 'katina-link-manager' ),
            'broken'       => __( 'Broken', 'katina-link-manager' ),
            'server_error' => __( 'Server Error', 'katina-link-manager' ),
            'error'        => __( 'Error', 'katina-link-manager' ),
            'no_url'       => __( 'No URL', 'katina-link-manager' ),
            'unknown'      => __( 'Unknown', 'katina-link-manager' ),
            'unchecked'    => __( 'Unchecked', 'katina-link-manager' ),
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
    }

    public static function get_status_color( $status ) {
        // AA-compliant text colors on white (4.5:1 minimum)
        $colors = array(
            'healthy'      => '#137333',
            'broken'       => '#b91c1c',
            'server_error' => '#a06f00',
            'error'        => '#b91c1c',
            'no_url'       => '#646970',
            'unknown'      => '#646970',
            'unchecked'    => '#646970',
        );
        return isset( $colors[ $status ] ) ? $colors[ $status ] : '#646970';
    }

    public static function get_status_icon( $status ) {
        $icons = array(
            'healthy'      => 'dashicons-yes-alt',
            'broken'       => 'dashicons-warning',
            'server_error' => 'dashicons-warning',
            'error'        => 'dashicons-warning',
            'no_url'       => 'dashicons-editor-unlink',
            'unknown'      => 'dashicons-editor-help',
            'unchecked'    => 'dashicons-clock',
        );
        return isset( $icons[ $status ] ) ? $icons[ $status ] : 'dashicons-editor-help';
    }

    public static function render_badge( $status, $http_code = '', $error = '' ) {
        $label = self::get_status_label( $status );
        $icon  = self::get_status_icon( $status );
        $extra = $http_code ? ' <small class="katina-health-badge-code">(' . esc_html( $http_code ) . ')</small>' : '';
        $title = $error ? ' title="' . esc_attr( $error ) . '"' : '';

        return sprintf(
            '<span class="katina-health-badge katina-health-%1$s"%5$s><span class="dashicons %2$s" aria-hidden="true"></span><span class="katina-health-badge-label">%3$s</span></span>%4$s',
            esc_attr( $status ),
            esc_attr( $icon ),
            esc_html( $label ),
            $extra,
            $title
        );
    }
}
