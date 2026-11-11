<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KATINA_Migration_Ajax {

    const TIME_BUDGET = 8;

    const SOURCES = array(
        'thirstyaffiliates'  => 'KATINA_Migrator_ThirstyAffiliates',
        'prettylinks'        => 'KATINA_Migrator_PrettyLinks',
        'linkcentral'        => 'KATINA_Migrator_LinkCentral',
        'easyaffiliatelinks' => 'KATINA_Migrator_EasyAffiliateLinks',
    );

    public static function init() {
        add_action( 'wp_ajax_katina_migrate_step', array( __CLASS__, 'handle_step' ) );
    }

    public static function handle_step() {
        check_ajax_referer( 'katina_migration', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( null, 403 );
        }

        $source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
        if ( ! isset( self::SOURCES[ $source ] ) ) {
            wp_send_json_error( null, 400 );
        }

        $class   = self::SOURCES[ $source ];
        $phase   = isset( $_POST['phase'] ) && 'scan' === $_POST['phase'] ? 'scan' : 'links';
        $cursor  = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
        $dry_run = ! empty( $_POST['dry_run'] );
        $scan    = ! empty( $_POST['scan'] ) && ! $dry_run;
        $keep    = ! empty( $_POST['keep_history'] ) && ! $dry_run;

        $totals_key = 'katina_migration_' . get_current_user_id();
        $totals     = 0 === $cursor && 'links' === $phase ? array() : get_transient( $totals_key );
        $totals     = is_array( $totals ) ? $totals : array();

        if ( 'scan' === $phase ) {
            $scanner = new KATINA_Content_Scanner( $class::load_id_map(), $class::get_source_name() );
            $result  = $scanner->scan_and_replace( $cursor, self::TIME_BUDGET );

            $totals['scan']['posts_scanned'] = ( isset( $totals['scan']['posts_scanned'] ) ? $totals['scan']['posts_scanned'] : 0 ) + $result['posts_scanned'];
            $totals['scan']['replacements']  = ( isset( $totals['scan']['replacements'] ) ? $totals['scan']['replacements'] : 0 ) + $result['replacements'];
            $next   = array( 'phase' => 'scan', 'cursor' => $result['last_id'] );
            $done   = $result['done'];
            $status = sprintf(
                /* translators: %d: number of posts scanned so far */
                _n( 'Scanned %d post...', 'Scanned %d posts...', $totals['scan']['posts_scanned'], 'katina-link-manager' ),
                $totals['scan']['posts_scanned']
            );
        } else {
            $migrator = new $class();
            $migrator->set_adopt_prefix( 'auto' )->set_dry_run( $dry_run )->set_cursor( $cursor )->set_time_budget( self::TIME_BUDGET );
            $slice = $migrator->run();

            foreach ( array( 'links', 'categories', 'clicks', 'skipped', 'errors' ) as $key ) {
                $totals[ $key ] = ( isset( $totals[ $key ] ) ? $totals[ $key ] : 0 ) + $slice[ $key ];
            }
            $totals['slug_mismatch'] = array_merge( isset( $totals['slug_mismatch'] ) ? $totals['slug_mismatch'] : array(), $slice['slug_mismatch'] );
            if ( ! empty( $slice['prefix'] ) ) {
                $totals['prefix'] = $slice['prefix'];
            }

            if ( $keep && $slice['clicks'] ) {
                self::keep_imported_history();
            }

            $next   = array( 'phase' => 'links', 'cursor' => $migrator->get_cursor() );
            $done   = $migrator->is_complete();
            $status = sprintf(
                /* translators: 1: links imported so far, 2: click records imported so far */
                __( '%1$d links and %2$d click records so far...', 'katina-link-manager' ),
                $totals['links'] + $totals['skipped'],
                $totals['clicks']
            );

            if ( $done && $scan ) {
                $next   = array( 'phase' => 'scan', 'cursor' => 0 );
                $done   = false;
                $status = __( 'Updating shortcodes and links in post content...', 'katina-link-manager' );
            }
        }

        set_transient( $totals_key, $totals, HOUR_IN_SECONDS );

        $response = array(
            'done'   => $done,
            'next'   => $next,
            'status' => $status,
        );

        if ( $done ) {
            delete_transient( $totals_key );
            $migration_result           = $totals;
            $migration_result['source'] = $class::get_source_name();
            $scan_result                = isset( $totals['scan'] ) ? $totals['scan'] : null;
            ob_start();
            include KATINA_PLUGIN_DIR . 'views/migration-result.php';
            $response['html'] = ob_get_clean();
        }

        wp_send_json_success( $response );
    }

    // Raises the retention setting just far enough that the oldest imported click survives the daily cleanup.
    private static function keep_imported_history() {
        $retention = (int) get_option( 'katina_clicks_retention_days', KATINA_Privacy::DEFAULT_RETENTION_DAYS );
        if ( $retention < 1 ) {
            return;
        }

        global $wpdb;
        $table  = KATINA_Clicks_DB::get_table_name();
        $oldest = $wpdb->get_var( "SELECT MIN(clicked_at) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $oldest ) {
            return;
        }

        $days = (int) ceil( ( time() - strtotime( $oldest . ' UTC' ) ) / DAY_IN_SECONDS ) + 1;
        if ( $days > $retention ) {
            update_option( 'katina_clicks_retention_days', $days );
        }
    }
}
