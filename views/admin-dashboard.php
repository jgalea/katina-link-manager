<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$table = KATINA_Clicks_DB::get_table_name();

$total_links  = wp_count_posts( 'katina_link' )->publish;
$total_clicks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_bot = 0" );
$clicks_30d   = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE is_bot = 0 AND clicked_at >= %s",
    gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
) );
$clicks_7d    = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE is_bot = 0 AND clicked_at >= %s",
    gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
) );

$since = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
$top_links = $wpdb->get_results( $wpdb->prepare(
    "SELECT c.link_id, p.post_title, COUNT(*) as clicks
     FROM {$table} c
     JOIN {$wpdb->posts} p ON c.link_id = p.ID
     WHERE c.is_bot = 0 AND c.clicked_at >= %s
     GROUP BY c.link_id
     ORDER BY clicks DESC
     LIMIT 10",
    $since
) );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Katina Link Manager Dashboard', 'katina-link-manager' ); ?></h1>

    <div class="katina-dashboard-stats">
        <div class="katina-stat-card">
            <div class="katina-stat-icon"><span class="dashicons dashicons-admin-links"></span></div>
            <div class="katina-stat-value"><?php echo esc_html( number_format_i18n( $total_links ) ); ?></div>
            <p class="katina-stat-label"><?php esc_html_e( 'Total Links', 'katina-link-manager' ); ?></p>
        </div>
        <div class="katina-stat-card">
            <div class="katina-stat-icon"><span class="dashicons dashicons-chart-bar"></span></div>
            <div class="katina-stat-value"><?php echo esc_html( number_format_i18n( $total_clicks ) ); ?></div>
            <p class="katina-stat-label"><?php esc_html_e( 'Total Clicks', 'katina-link-manager' ); ?></p>
        </div>
        <div class="katina-stat-card">
            <div class="katina-stat-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
            <div class="katina-stat-value"><?php echo esc_html( number_format_i18n( $clicks_30d ) ); ?></div>
            <p class="katina-stat-label"><?php esc_html_e( 'Clicks (30 days)', 'katina-link-manager' ); ?></p>
        </div>
        <div class="katina-stat-card">
            <div class="katina-stat-icon"><span class="dashicons dashicons-clock"></span></div>
            <div class="katina-stat-value"><?php echo esc_html( number_format_i18n( $clicks_7d ) ); ?></div>
            <p class="katina-stat-label"><?php esc_html_e( 'Clicks (7 days)', 'katina-link-manager' ); ?></p>
        </div>
    </div>

    <?php if ( KATINA_Link_Health::is_enabled() ) : ?>
    <?php
    $health = KATINA_Link_Health::get_summary();
    $health_queued = isset( $_GET['katina_health_queued'] ) ? (int) $_GET['katina_health_queued'] : 0;
    ?>

    <?php if ( $health_queued ) : ?>
        <div class="notice notice-success"><p><?php
            /* translators: %d: number of links queued for health recheck */
            printf( esc_html( _n( 'Health recheck queued for %d link. Results will appear here as the background job completes — refresh in a minute or two.', 'Health recheck queued for %d links. Results will appear here as the background job completes — refresh in a minute or two.', (int) $health_queued, 'katina-link-manager' ) ), (int) $health_queued );
        ?></p></div>
    <?php endif; ?>

    <h2 class="katina-section-title"><?php esc_html_e( 'Link Health', 'katina-link-manager' ); ?></h2>
    <?php
    $health_url = function( $status ) {
        return add_query_arg( array(
            'post_type' => 'katina_link',
            'katina_health' => $status,
        ), admin_url( 'edit.php' ) );
    };
    ?>
    <div class="katina-dashboard-stats">
        <a class="katina-stat-card katina-stat-card-link katina-health-card katina-health-card-healthy" href="<?php echo esc_url( $health_url( 'healthy' ) ); ?>">
            <div class="katina-stat-icon"><span class="dashicons <?php echo esc_attr( KATINA_Link_Health::get_status_icon( 'healthy' ) ); ?>"></span></div>
            <div class="katina-stat-value" style="color: <?php echo esc_attr( KATINA_Link_Health::get_status_color( 'healthy' ) ); ?>;"><?php echo esc_html( $health['healthy'] ); ?></div>
            <p class="katina-stat-label"><?php esc_html_e( 'Healthy', 'katina-link-manager' ); ?></p>
        </a>
        <a class="katina-stat-card katina-stat-card-link katina-health-card katina-health-card-broken" href="<?php echo esc_url( $health_url( 'broken_error' ) ); ?>">
            <div class="katina-stat-icon"><span class="dashicons <?php echo esc_attr( KATINA_Link_Health::get_status_icon( 'broken' ) ); ?>"></span></div>
            <div class="katina-stat-value" style="color: <?php echo esc_attr( KATINA_Link_Health::get_status_color( 'broken' ) ); ?>;"><?php echo esc_html( $health['broken'] + $health['error'] ); ?></div>
            <p class="katina-stat-label"><?php esc_html_e( 'Broken / Error', 'katina-link-manager' ); ?></p>
        </a>
        <a class="katina-stat-card katina-stat-card-link katina-health-card katina-health-card-server_error" href="<?php echo esc_url( $health_url( 'server_error' ) ); ?>">
            <div class="katina-stat-icon"><span class="dashicons <?php echo esc_attr( KATINA_Link_Health::get_status_icon( 'server_error' ) ); ?>"></span></div>
            <div class="katina-stat-value" style="color: <?php echo esc_attr( KATINA_Link_Health::get_status_color( 'server_error' ) ); ?>;"><?php echo esc_html( $health['server_error'] ); ?></div>
            <p class="katina-stat-label"><?php esc_html_e( 'Server Error', 'katina-link-manager' ); ?></p>
        </a>
        <a class="katina-stat-card katina-stat-card-link katina-health-card katina-health-card-unchecked" href="<?php echo esc_url( $health_url( 'unchecked' ) ); ?>">
            <div class="katina-stat-icon"><span class="dashicons <?php echo esc_attr( KATINA_Link_Health::get_status_icon( 'unchecked' ) ); ?>"></span></div>
            <div class="katina-stat-value" style="color: <?php echo esc_attr( KATINA_Link_Health::get_status_color( 'unchecked' ) ); ?>;"><?php echo esc_html( $health['unchecked'] ); ?></div>
            <p class="katina-stat-label"><?php esc_html_e( 'Unchecked', 'katina-link-manager' ); ?></p>
        </a>
    </div>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="katina-recheck-form">
        <?php wp_nonce_field( 'katina_check_health_now' ); ?>
        <input type="hidden" name="action" value="katina_check_health_now" />
        <?php submit_button( __( 'Recheck All Links Now', 'katina-link-manager' ), 'secondary', 'submit', false ); ?>
        <span class="katina-form-hint"><?php esc_html_e( 'Background checks run hourly and stagger across the week.', 'katina-link-manager' ); ?></span>
    </form>
    <?php endif; ?>

    <?php if ( $top_links ) : ?>
    <h2 class="katina-section-title"><?php esc_html_e( 'Top Links (Last 30 Days)', 'katina-link-manager' ); ?></h2>
    <table class="widefat striped katina-top-links">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Link', 'katina-link-manager' ); ?></th>
                <th class="katina-col-clicks"><?php esc_html_e( 'Clicks', 'katina-link-manager' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $top_links as $row ) : ?>
            <tr>
                <td>
                    <a href="<?php echo esc_url( get_edit_post_link( $row->link_id ) ); ?>">
                        <?php echo esc_html( $row->post_title ); ?>
                    </a>
                </td>
                <td class="katina-col-clicks"><span class="katina-click-count"><?php echo esc_html( number_format_i18n( $row->clicks ) ); ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
    <div class="katina-empty-state">
        <?php if ( $total_links === 0 ) : ?>
            <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
            <h2><?php esc_html_e( 'No links yet', 'katina-link-manager' ); ?></h2>
            <p><?php esc_html_e( 'Create your first cloaked link to start tracking clicks.', 'katina-link-manager' ); ?></p>
            <p><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=katina_link' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Add your first link', 'katina-link-manager' ); ?></a></p>
        <?php else : ?>
            <span class="dashicons dashicons-chart-line" aria-hidden="true"></span>
            <p><?php esc_html_e( 'No click data yet. Clicks will appear here once your links start getting traffic.', 'katina-link-manager' ); ?></p>
            <p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=katina_link' ) ); ?>"><?php esc_html_e( 'View all links', 'katina-link-manager' ); ?> &rarr;</a></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
