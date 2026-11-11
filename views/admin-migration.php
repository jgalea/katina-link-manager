<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$migrators = KATINA_Migration_Ajax::SOURCES;

$cleanup_result = null;
$cleanup_source = '';

if ( isset( $_POST['katina_cleanup_source'] ) && check_admin_referer( 'katina_cleanup_orphaned' ) ) {
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

$retention_days = (int) get_option( 'katina_clicks_retention_days', KATINA_Privacy::DEFAULT_RETENTION_DAYS );
$autostart      = isset( $_GET['katina_autostart'] ) ? sanitize_key( wp_unslash( $_GET['katina_autostart'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$autostart_scan = ! empty( $_GET['katina_scan'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
// A link alone only pre-selects the source; the nonce from the setup wizard is what allows the import to start.
$autostart_run  = isset( $_GET['katina_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['katina_nonce'] ) ), 'katina_autostart_' . $autostart );

wp_enqueue_script( 'katina-migration', KATINA_PLUGIN_URL . 'assets/js/migration.js', array( 'jquery' ), KATINA_VERSION, true );
wp_localize_script( 'katina-migration', 'katinaMigration', array(
    'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
    'nonce'     => wp_create_nonce( 'katina_migration' ),
    'autostart' => isset( $states[ $autostart ] ) ? array( 'source' => $autostart, 'scan' => $autostart_scan, 'run' => (bool) $autostart_run ) : null,
    'i18n'      => array(
        'starting' => __( 'Starting...', 'katina-link-manager' ),
        'confirm'  => __( 'Start importing now?', 'katina-link-manager' ),
        'failed'   => __( 'The migration stopped because of a server error. Run it again to carry on where it left off.', 'katina-link-manager' ),
    ),
) );
?>

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

                <form method="post" class="katina-migrate-form">
                    <input type="hidden" name="katina_migrate_source" value="<?php echo esc_attr( $key ); ?>" />
                    <p>
                        <label>
                            <input type="checkbox" name="katina_scan_content" value="1" checked />
                            <?php esc_html_e( 'Also scan and update shortcodes/links in post content', 'katina-link-manager' ); ?>
                        </label>
                    </p>
                    <?php if ( $retention_days > 0 ) : ?>
                        <p class="katina-warn">
                            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                            <?php
                            printf(
                                /* translators: %d: click retention in days */
                                esc_html__( 'Click records older than %d days are deleted every day, so older imported history would be removed again.', 'katina-link-manager' ),
                                (int) $retention_days
                            );
                            ?>
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="katina_keep_history" value="1" checked />
                                <?php esc_html_e( 'Keep the imported click history (raises the retention period to cover it)', 'katina-link-manager' ); ?>
                            </label>
                        </p>
                    <?php endif; ?>
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
                    submit_button( __( 'Preview without importing', 'katina-link-manager' ), 'secondary', 'katina_dry_run', false );
                    ?>
                    <div class="katina-migrate-progress" hidden>
                        <div class="katina-progress-track"><div class="katina-progress-bar"></div></div>
                        <p class="katina-migrate-status" role="status" aria-live="polite"></p>
                    </div>
                    <div class="katina-migrate-result"></div>
                </form>
            <?php else : ?>
                <p><?php
                    /* translators: %s: source plugin name */
                    printf( esc_html__( 'The %s plugin is not active, but its data is still in your database. You can either import it into Katina Link Manager or remove it entirely.', 'katina-link-manager' ), esc_html( $info['name'] ) );
                ?></p>

                <form method="post" class="katina-inline-form katina-migrate-form">
                    <input type="hidden" name="katina_migrate_source" value="<?php echo esc_attr( $key ); ?>" />
                    <label class="katina-inline-checkbox">
                        <input type="checkbox" name="katina_scan_content" value="1" checked />
                        <?php esc_html_e( 'Also scan and update shortcodes/links in post content', 'katina-link-manager' ); ?>
                    </label>
                    <?php if ( $retention_days > 0 ) : ?>
                        <p class="katina-warn">
                            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                            <?php
                            printf(
                                /* translators: %d: click retention in days */
                                esc_html__( 'Click records older than %d days are deleted every day, so older imported history would be removed again.', 'katina-link-manager' ),
                                (int) $retention_days
                            );
                            ?>
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="katina_keep_history" value="1" checked />
                                <?php esc_html_e( 'Keep the imported click history (raises the retention period to cover it)', 'katina-link-manager' ); ?>
                            </label>
                        </p>
                    <?php endif; ?>
                    <?php
                    submit_button( __( 'Import data', 'katina-link-manager' ), 'secondary', 'submit', false );
                    submit_button( __( 'Preview without importing', 'katina-link-manager' ), 'secondary', 'katina_dry_run', false );
                    ?>
                    <div class="katina-migrate-progress" hidden>
                        <div class="katina-progress-track"><div class="katina-progress-bar"></div></div>
                        <p class="katina-migrate-status" role="status" aria-live="polite"></p>
                    </div>
                    <div class="katina-migrate-result"></div>
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
