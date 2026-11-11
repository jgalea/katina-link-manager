<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="notice notice-success inline">
    <p><strong><?php
        if ( $dry_run ) {
            /* translators: %s: source plugin name (e.g. ThirstyAffiliates) */
            printf( esc_html__( 'Preview of the migration from %s. Nothing was changed.', 'katina-link-manager' ), esc_html( $migration_result['source'] ) );
        } else {
            /* translators: %s: source plugin name (e.g. ThirstyAffiliates) */
            printf( esc_html__( 'Migration from %s complete!', 'katina-link-manager' ), esc_html( $migration_result['source'] ) );
        }
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
        <?php if ( ! empty( $migration_result['prefix']['adopted'] ) ) : ?>
            <li><?php
                /* translators: %s: URL prefix adopted from the source plugin */
                printf( esc_html__( 'URL prefix set to "%s" so existing cloaked URLs keep working', 'katina-link-manager' ), esc_html( $migration_result['prefix']['ta'] ) );
            ?></li>
        <?php elseif ( ! empty( $migration_result['prefix']['differs'] ) ) : ?>
            <li><?php
                /* translators: 1: URL prefix of the source plugin, 2: URL prefix used by Katina Link Manager */
                printf( esc_html__( 'The source plugin used the prefix "%1$s" and Katina Link Manager uses "%2$s". Existing cloaked URLs resolve once the prefix matches.', 'katina-link-manager' ), esc_html( $migration_result['prefix']['ta'] ), esc_html( $migration_result['prefix']['katina'] ) );
            ?></li>
        <?php endif; ?>
        <?php if ( $migration_result['errors'] ) : ?>
            <li class="katina-result-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php
                /* translators: %d: number of errors */
                printf( esc_html( _n( '%d error occurred', '%d errors occurred', (int) $migration_result['errors'], 'katina-link-manager' ) ), (int) $migration_result['errors'] );
            ?></li>
        <?php endif; ?>
    </ul>
    <?php if ( ! $dry_run ) : ?>
        <p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=katina_link' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'View imported links', 'katina-link-manager' ); ?> &rarr;</a></p>
    <?php endif; ?>
</div>

<?php if ( $scan_result ) : ?>
    <div class="notice notice-info inline">
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
