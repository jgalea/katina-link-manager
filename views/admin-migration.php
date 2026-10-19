<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$migrators = array(
    'thirstyaffiliates'  => 'KATINA_Migrator_ThirstyAffiliates',
    'prettylinks'        => 'KATINA_Migrator_PrettyLinks',
    'linkcentral'        => 'KATINA_Migrator_LinkCentral',
    'easyaffiliatelinks' => 'KATINA_Migrator_EasyAffiliateLinks',
);

$migration_result = null;
$scan_result      = null;
$cleanup_result   = null;
$cleanup_source   = '';

if ( isset( $_POST['katina_migrate_source'] ) && check_admin_referer( 'katina_run_migration' ) ) {
    $source_key = sanitize_key( wp_unslash( $_POST['katina_migrate_source'] ) );
    if ( isset( $migrators[ $source_key ] ) ) {
        $class    = $migrators[ $source_key ];
        $migrator = new $class();
        $migrator->set_adopt_prefix( 'auto' );
        $migration_result = $migrator->run();
        $migration_result['source'] = $class::get_source_name();

        if ( ! empty( $_POST['katina_scan_content'] ) ) {
            $scanner = new KATINA_Content_Scanner( $migrator->get_id_map(), $class::get_source_name() );
            $scan_result = $scanner->scan_and_replace();
        }
    }
} elseif ( isset( $_POST['katina_cleanup_source'] ) && check_admin_referer( 'katina_cleanup_orphaned' ) ) {
    $source_key = sanitize_key( wp_unslash( $_POST['katina_cleanup_source'] ) );
    if ( isset( $migrators[ $source_key ] ) ) {
        $class          = $migrators[ $source_key ];
        $cleanup_source = $class::get_source_name();
        $cleanup_result = $class::cleanup_orphaned_data();
    }
}

$states = array();
foreach ( $migrators as $key => $class ) {
    if ( $class::is_available() ) {
        $state = 'active';
    } elseif ( $class::has_orphaned_data() ) {
        $state = 'orphaned';
    } else {
        continue;
    }
    $states[ $key ] = array(
        'name'  => $class::get_source_name(),
        'count' => $state === 'active' ? $class::get_source_count() : null,
        'class' => $class,
        'state' => $state,
    );
}
?>

<?php if ( $migration_result ) : ?>
    <div class="notice notice-success">
        <p><strong><?php
            /* translators: %s: source plugin name (e.g. ThirstyAffiliates) */
            printf( esc_html__( 'Migration from %s complete!', 'katina-link-manager' ), esc_html( $migration_result['source'] ) );
        ?></strong></p>
        <ul class="katina-result-list">
            <li><?php
                /* translators: %d: number of links imported */
                printf( esc_html( _n( '%d link imported', '%d links imported', (int) $migration_result['links'], 'katina-link-manager' ) ), (int) $migration_result['links'] );
            ?></li>
            <li><?php
                /* translators: %d: number of categories created */
                printf( esc_html( _n( '%d category created', '%d categories created', (int) $migration_result['categories'], 'katina-link-manager' ) ), (int) $migration_result['categories'] );
            ?></li>
            <li><?php
                /* translators: %d: number of click records imported */
                printf( esc_html( _n( '%d click record imported', '%d click records imported', (int) $migration_result['clicks'], 'katina-link-manager' ) ), (int) $migration_result['clicks'] );
            ?></li>
            <?php if ( $migration_result['skipped'] ) : ?>
                <li><?php
                    /* translators: %d: number of links skipped */
                    printf( esc_html( _n( '%d link skipped (already exists or no URL)', '%d links skipped (already exist or no URL)', (int) $migration_result['skipped'], 'katina-link-manager' ) ), (int) $migration_result['skipped'] );
                ?></li>
            <?php endif; ?>
            <?php if ( $migration_result['errors'] ) : ?>
                <li class="katina-result-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php
                    /* translators: %d: number of errors */
                    printf( esc_html( _n( '%d error occurred', '%d errors occurred', (int) $migration_result['errors'], 'katina-link-manager' ) ), (int) $migration_result['errors'] );
                ?></li>
            <?php endif; ?>
        </ul>
        <p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=katina_link' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'View imported links', 'katina-link-manager' ); ?> &rarr;</a></p>
    </div>

    <?php if ( $scan_result ) : ?>
        <div class="notice notice-info">
            <p><?php
                printf(
                    /* translators: 1: number of posts scanned, 2: number of posts updated */
                    esc_html__( 'Content scan: %1$d posts scanned, %2$d posts updated with new link URLs.', 'katina-link-manager' ),
                    (int) $scan_result['posts_scanned'],
                    (int) $scan_result['replacements']
                );
            ?></p>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ( $cleanup_result ) : ?>
    <div class="notice notice-success">
        <p><strong><?php
            /* translators: %s: source plugin name (e.g. ThirstyAffiliates) */
            printf( esc_html__( 'Cleaned up leftover %s data.', 'katina-link-manager' ), esc_html( $cleanup_source ) );
        ?></strong></p>
        <ul class="katina-result-list">
            <li><?php
                /* translators: %d: number of posts deleted */
                printf( esc_html( _n( '%d post deleted', '%d posts deleted', (int) $cleanup_result['posts'], 'katina-link-manager' ) ), (int) $cleanup_result['posts'] );
            ?></li>
            <li><?php
                /* translators: %d: number of postmeta rows deleted */
                printf( esc_html( _n( '%d postmeta row deleted', '%d postmeta rows deleted', (int) $cleanup_result['postmeta'], 'katina-link-manager' ) ), (int) $cleanup_result['postmeta'] );
            ?></li>
            <li><?php
                /* translators: %d: number of taxonomy terms deleted */
                printf( esc_html( _n( '%d taxonomy term deleted', '%d taxonomy terms deleted', (int) $cleanup_result['terms'], 'katina-link-manager' ) ), (int) $cleanup_result['terms'] );
            ?></li>
            <li><?php
                /* translators: %d: number of database tables dropped */
                printf( esc_html( _n( '%d database table dropped', '%d database tables dropped', (int) $cleanup_result['tables'], 'katina-link-manager' ) ), (int) $cleanup_result['tables'] );
            ?></li>
            <li><?php
                /* translators: %d: number of plugin options deleted */
                printf( esc_html( _n( '%d plugin option deleted', '%d plugin options deleted', (int) $cleanup_result['options'], 'katina-link-manager' ) ), (int) $cleanup_result['options'] );
            ?></li>
        </ul>
    </div>
<?php endif; ?>

<?php if ( empty( $states ) ) : ?>
    <div class="katina-empty-state">
        <span class="dashicons dashicons-search" aria-hidden="true"></span>
        <h2><?php esc_html_e( 'No plugins detected', 'katina-link-manager' ); ?></h2>
        <p><?php esc_html_e( 'Katina Link Manager can migrate links from ThirstyAffiliates, Pretty Links, LinkCentral, and Easy Affiliate Links. No compatible data was found on this site.', 'katina-link-manager' ); ?></p>
        <p><?php esc_html_e( 'You can also import links via CSV from the Import / Export tab.', 'katina-link-manager' ); ?></p>
    </div>
<?php else : ?>
    <p><?php esc_html_e( 'Katina Link Manager detected link data from the following plugins. Migration is non-destructive — your original data is preserved.', 'katina-link-manager' ); ?></p>

    <?php foreach ( $states as $key => $info ) : ?>
        <div class="katina-card katina-migration-card">
            <h2>
                <?php echo esc_html( $info['name'] ); ?>
                <?php if ( $info['state'] === 'orphaned' ) : ?>
                    <span class="katina-badge katina-badge-warning"><?php esc_html_e( 'Leftover data', 'katina-link-manager' ); ?></span>
                <?php endif; ?>
            </h2>

            <?php if ( $info['state'] === 'active' ) : ?>
                <p><?php
                    /* translators: %d: number of links found in source plugin */
                    printf( esc_html( _n( '%d link found.', '%d links found.', (int) $info['count'], 'katina-link-manager' ) ), (int) $info['count'] );
                ?></p>

                <form method="post">
                    <?php wp_nonce_field( 'katina_run_migration' ); ?>
                    <input type="hidden" name="katina_migrate_source" value="<?php echo esc_attr( $key ); ?>" />
                    <p>
                        <label>
                            <input type="checkbox" name="katina_scan_content" value="1" checked />
                            <?php esc_html_e( 'Also scan and update shortcodes/links in post content', 'katina-link-manager' ); ?>
                        </label>
                    </p>
                    <?php
                    submit_button(
                        sprintf(
                            /* translators: %s: source plugin name */
                            __( 'Migrate from %s', 'katina-link-manager' ),
                            $info['name']
                        ),
                        'primary',
                        'submit',
                        false
                    );
                    ?>
                </form>
            <?php else : ?>
                <p><?php
                    /* translators: %s: source plugin name */
                    printf( esc_html__( 'The %s plugin is not active, but its data is still in your database. You can either import it into Katina Link Manager or remove it entirely.', 'katina-link-manager' ), esc_html( $info['name'] ) );
                ?></p>

                <form method="post" class="katina-inline-form">
                    <?php wp_nonce_field( 'katina_run_migration' ); ?>
                    <input type="hidden" name="katina_migrate_source" value="<?php echo esc_attr( $key ); ?>" />
                    <label class="katina-inline-checkbox">
                        <input type="checkbox" name="katina_scan_content" value="1" checked />
                        <?php esc_html_e( 'Also scan and update shortcodes/links in post content', 'katina-link-manager' ); ?>
                    </label>
                    <?php submit_button( __( 'Import data', 'katina-link-manager' ), 'secondary', 'submit', false ); ?>
                </form>

                <?php /* translators: %s: source plugin name */ ?>
                <form method="post" class="katina-inline-form" onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'This will permanently delete all leftover %s data (posts, tables, options). This cannot be undone. Continue?', 'katina-link-manager' ), $info['name'] ) ); ?>');">
                    <?php wp_nonce_field( 'katina_cleanup_orphaned' ); ?>
                    <input type="hidden" name="katina_cleanup_source" value="<?php echo esc_attr( $key ); ?>" />
                    <?php submit_button( __( 'Clean up', 'katina-link-manager' ), 'delete', 'submit', false ); ?>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
