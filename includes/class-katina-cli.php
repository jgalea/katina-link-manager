<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

class KATINA_CLI {

    public static function register() {
        WP_CLI::add_command( 'katina-link-manager migrate', array( __CLASS__, 'migrate' ) );
        WP_CLI::add_command( 'katina-link-manager trash', array( __CLASS__, 'trash' ) );
        WP_CLI::add_command( 'katina-link-manager untrash', array( __CLASS__, 'untrash' ) );
    }

    /**
     * Send one or more Katina Link Manager links to the trash.
     *
     * Works around WP-CLI's `wp post delete` refusing to trash custom post
     * types without `--force`.
     *
     * ## OPTIONS
     *
     * <id>...
     * : One or more katina_link post IDs.
     *
     * ## EXAMPLES
     *
     *     wp katina-link-manager trash 12345
     *     wp katina-link-manager trash 12345 67890
     */
    public static function trash( $args, $assoc_args ) {
        foreach ( $args as $id ) {
            $id   = (int) $id;
            $post = get_post( $id );

            if ( ! $post || 'katina_link' !== $post->post_type ) {
                WP_CLI::warning( "Skipping {$id}: not an katina_link." );
                continue;
            }
            if ( 'trash' === $post->post_status ) {
                WP_CLI::log( "Already in trash: {$id}." );
                continue;
            }
            if ( wp_trash_post( $id ) ) {
                WP_CLI::success( "Trashed {$id} ({$post->post_title})." );
            } else {
                WP_CLI::warning( "Failed to trash {$id}." );
            }
        }
    }

    /**
     * Restore one or more trashed Katina Link Manager links.
     *
     * ## OPTIONS
     *
     * <id>...
     * : One or more katina_link post IDs.
     *
     * ## EXAMPLES
     *
     *     wp katina-link-manager untrash 12345
     */
    public static function untrash( $args, $assoc_args ) {
        foreach ( $args as $id ) {
            $id   = (int) $id;
            $post = get_post( $id );

            if ( ! $post || 'katina_link' !== $post->post_type ) {
                WP_CLI::warning( "Skipping {$id}: not an katina_link." );
                continue;
            }
            if ( 'trash' !== $post->post_status ) {
                WP_CLI::log( "Not in trash: {$id}." );
                continue;
            }
            if ( wp_untrash_post( $id ) ) {
                WP_CLI::success( "Restored {$id} ({$post->post_title})." );
            } else {
                WP_CLI::warning( "Failed to restore {$id}." );
            }
        }
    }

    /**
     * Migrate links and click history from a competitor plugin. Safe to run
     * repeatedly: links are matched by their source ID and slug, and clicks
     * resume after the last one imported for each link.
     *
     * ## OPTIONS
     *
     * <source>
     * : Source plugin slug. One of: thirstyaffiliates, prettylinks, linkcentral, easyaffiliatelinks.
     *
     * [--dry-run]
     * : Report what would be created and imported without writing anything.
     *
     * [--adopt-prefix]
     * : ThirstyAffiliates only. Use its URL prefix and global defaults (redirect type, nofollow, new window, sponsored) as Katina's, so existing cloaked URLs keep working. Happens automatically when Katina has no links yet.
     *
     * [--rewrite-content]
     * : Also rewrite post content, replacing the source plugin's shortcodes, tags and cloaked URLs with Katina links. Not needed when the compatibility layer is enough.
     *
     * ## EXAMPLES
     *
     *     wp katina-link-manager migrate thirstyaffiliates --dry-run
     *     wp katina-link-manager migrate thirstyaffiliates --adopt-prefix
     */
    public static function migrate( $args, $assoc_args ) {
        $map = array(
            'thirstyaffiliates'  => 'KATINA_Migrator_ThirstyAffiliates',
            'prettylinks'        => 'KATINA_Migrator_PrettyLinks',
            'linkcentral'        => 'KATINA_Migrator_LinkCentral',
            'easyaffiliatelinks' => 'KATINA_Migrator_EasyAffiliateLinks',
        );

        $source = isset( $args[0] ) ? strtolower( $args[0] ) : '';
        if ( ! isset( $map[ $source ] ) ) {
            WP_CLI::error( 'Unknown source. Use one of: ' . implode( ', ', array_keys( $map ) ) );
        }

        $class = $map[ $source ];
        if ( ! $class::is_available() ) {
            WP_CLI::error( "No {$source} data found on this site." );
        }

        $dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
        $adopt   = WP_CLI\Utils\get_flag_value( $assoc_args, 'adopt-prefix', false ) ? true : 'auto';

        $retention = (int) get_option( 'katina_clicks_retention_days', 365 );
        if ( $retention > 0 ) {
            WP_CLI::warning( sprintf(
                'Click retention is %d days. Imported clicks older than that are deleted by the daily cleanup. Run `wp option update katina_clicks_retention_days 0` first to keep the full history.',
                $retention
            ) );
        }

        WP_CLI::log( ( $dry_run ? 'Dry run: ' : '' ) . "Running {$class}::run()..." );
        $migrator = new $class();
        $migrator->set_dry_run( $dry_run )->set_adopt_prefix( $adopt );
        $migrator->set_progress( function ( $done, $total, $results ) {
            if ( 0 === $done % 25 || $done === $total ) {
                WP_CLI::log( sprintf( '%d/%d links processed, %d clicks so far.', $done, $total, $results['clicks'] ) );
            }
        } );
        $results = $migrator->run();

        if ( ! empty( $results['prefix'] ) ) {
            $p = $results['prefix'];
            if ( $p['adopted'] ) {
                WP_CLI::log( sprintf( '%s URL prefix "%s" (was "%s").', $dry_run ? 'Would adopt' : 'Adopted', $p['ta'], $p['katina'] ) );
            } elseif ( $p['differs'] ) {
                WP_CLI::warning( sprintf( 'Source prefix "%s" differs from Katina\'s "%s". Existing cloaked URLs will not resolve; re-run with --adopt-prefix to switch.', $p['ta'], $p['katina'] ) );
            }
        }
        if ( ! empty( $results['slug_mismatch'] ) ) {
            WP_CLI::warning( 'Slug changed on insert for: ' . implode( ', ', $results['slug_mismatch'] ) );
        }

        if ( ! $dry_run && WP_CLI\Utils\get_flag_value( $assoc_args, 'rewrite-content', false ) ) {
            $scanner = new KATINA_Content_Scanner( $migrator->get_id_map(), $class::get_source_name() );
            $scan    = $scanner->scan_and_replace();
            WP_CLI::log( sprintf( 'Scanned %d posts, %d replacements.', $scan['posts_scanned'], $scan['replacements'] ) );
        }

        WP_CLI::success( sprintf(
            '%s %d links, %d categories, %d clicks. Skipped: %d. Errors: %d.',
            $dry_run ? 'Would import' : 'Imported',
            $results['links'],
            $results['categories'],
            $results['clicks'],
            $results['skipped'],
            $results['errors']
        ) );
    }
}

KATINA_CLI::register();
