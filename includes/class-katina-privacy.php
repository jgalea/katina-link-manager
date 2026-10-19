<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GDPR plumbing: data exporter, eraser, retention cron, privacy policy snippet.
 *
 * Click data is anonymous-by-design (no IP, no user ID, only a UA hash) but the
 * referrer + country combination can be loosely identifying so we still register
 * exporter/eraser handlers keyed on referrer-host so site owners can fulfil
 * subject access / erasure requests.
 */
class KATINA_Privacy {

    const RETENTION_HOOK = 'katina_clicks_retention_cron';

    public static function init() {
        add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_policy_content' ) );
        add_action( self::RETENTION_HOOK, array( __CLASS__, 'run_retention' ) );
        self::schedule();
    }

    public static function schedule() {
        if ( ! wp_next_scheduled( self::RETENTION_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RETENTION_HOOK );
        }
    }

    public static function unschedule() {
        $ts = wp_next_scheduled( self::RETENTION_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::RETENTION_HOOK );
        }
    }

    public static function run_retention() {
        $days = (int) get_option( 'katina_clicks_retention_days', 365 );
        if ( $days < 1 ) {
            return;
        }
        KATINA_Clicks_DB::cleanup_old_data( $days );
    }

    public static function register_exporter( $exporters ) {
        $exporters['katina-link-manager-clicks'] = array(
            'exporter_friendly_name' => __( 'Katina Link Manager click logs', 'katina-link-manager' ),
            'callback'               => array( __CLASS__, 'export_user_data' ),
        );
        return $exporters;
    }

    public static function register_eraser( $erasers ) {
        $erasers['katina-link-manager-clicks'] = array(
            'eraser_friendly_name' => __( 'Katina Link Manager click logs', 'katina-link-manager' ),
            'callback'             => array( __CLASS__, 'erase_user_data' ),
        );
        return $erasers;
    }

    /**
     * Export click rows whose referrer host matches the email's domain.
     * This is best-effort — anonymous click logs don't link to a user_id.
     */
    public static function export_user_data( $email_address, $page = 1 ) {
        global $wpdb;
        $table   = KATINA_Clicks_DB::get_table_name();
        $domain  = self::email_domain( $email_address );
        $items   = array();

        if ( $domain ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, link_id, clicked_at, referrer, country_code FROM {$table}
                 WHERE referrer LIKE %s LIMIT 200 OFFSET %d",
                '%' . $wpdb->esc_like( $domain ) . '%',
                ( $page - 1 ) * 200
            ) );

            foreach ( $rows as $row ) {
                $items[] = array(
                    'group_id'    => 'katina-link-manager-clicks',
                    'group_label' => __( 'Katina Link Manager click logs', 'katina-link-manager' ),
                    'item_id'     => 'katina-click-' . $row->id,
                    'data'        => array(
                        array( 'name' => __( 'Link ID', 'katina-link-manager' ),     'value' => (int) $row->link_id ),
                        array( 'name' => __( 'Clicked at', 'katina-link-manager' ),  'value' => $row->clicked_at ),
                        array( 'name' => __( 'Referrer', 'katina-link-manager' ),    'value' => $row->referrer ),
                        array( 'name' => __( 'Country code', 'katina-link-manager' ), 'value' => $row->country_code ),
                    ),
                );
            }
        }

        return array(
            'data' => $items,
            'done' => count( $items ) < 200,
        );
    }

    public static function erase_user_data( $email_address, $page = 1 ) {
        global $wpdb;
        $table   = KATINA_Clicks_DB::get_table_name();
        $domain  = self::email_domain( $email_address );
        $deleted = 0;

        if ( $domain ) {
            $deleted = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE referrer LIKE %s LIMIT 500",
                '%' . $wpdb->esc_like( $domain ) . '%'
            ) );
        }

        return array(
            'items_removed'  => $deleted,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => $deleted < 500,
        );
    }

    public static function register_policy_content() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        $content  = '<p>' . esc_html__( 'This site uses Katina Link Manager to manage and track clicks on outbound links. When you click a managed link the following data may be recorded: the timestamp (UTC), the destination link, the referring page (with query strings stripped), a 2-letter country code derived from your IP, and a one-way hash of your browser user-agent for bot detection. Your IP address is not stored. Click logs older than the configured retention period are automatically deleted.', 'katina-link-manager' ) . '</p>';
        $content .= '<p>' . esc_html__( 'Katina Link Manager also performs background health checks against the destinations of your managed links. Those destination servers will see periodic requests from this site identifying themselves with a "Katina Link Manager Health Checker" user-agent.', 'katina-link-manager' ) . '</p>';

        if ( get_option( 'katinapro_enable_suggestions', 'no' ) === 'yes' || get_option( 'katinapro_enable_content_ai', 'no' ) === 'yes' ) {
            $provider = get_option( 'katinapro_ai_provider', 'openai' );
            $content .= '<p>' . sprintf(
                /* translators: %s: provider name */
                esc_html__( 'Katina Link Manager Pro is enabled and may transmit excerpts of post content to %s for AI-driven link suggestions and content gap analysis. Review that provider\'s privacy policy and add it to your processor list as required by your jurisdiction.', 'katina-link-manager' ),
                $provider === 'anthropic' ? 'Anthropic' : 'OpenAI'
            ) . '</p>';
        }

        wp_add_privacy_policy_content( 'Katina Link Manager', $content );
    }

    private static function email_domain( $email ) {
        $parts = explode( '@', (string) $email );
        return isset( $parts[1] ) ? strtolower( $parts[1] ) : '';
    }
}
