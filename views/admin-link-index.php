<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Vars provided by KATINA_Link_Report::render_page():
 *  $args       (array of validated filters)
 *  $rows       (array of report rows)
 *  $total      (int total matching rows)
 *  $totals     (array link_type => count)
 *  $last_run   (string mysql datetime or '')
 *  $has_index  (bool)
 *  $post_types (array of post type names)
 *  $scannable  (int posts eligible for scanning)
 */
$pages       = (int) ceil( $total / KATINA_Link_Report::PER_PAGE );
$current     = (int) $args['page'];
$export_base = add_query_arg(
    array(
        'action'           => 'katina_export_index',
        'post_type_filter' => $args['post_type'],
        'orphans_only'     => $args['orphans_only'] ? 1 : '',
        's'                => $args['search'],
        'orderby'          => $args['orderby'],
        'order'            => $args['order'],
    ),
    admin_url( 'admin-post.php' )
);
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Link Index', 'katina-link-manager' ); ?></h1>
    <p class="description">
        <?php esc_html_e( 'Every link on your site, read straight from the database. Drafts, private posts and noindexed pages are included, which a crawler cannot see.', 'katina-link-manager' ); ?>
    </p>

    <div id="katina-scan-panel" style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;">
        <p style="margin:0 0 8px;">
            <?php if ( $last_run ) : ?>
                <strong><?php esc_html_e( 'Last scan:', 'katina-link-manager' ); ?></strong>
                <?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run ) ); ?>
                &nbsp;·&nbsp;
                <?php
                printf(
                    /* translators: 1: internal link count, 2: external link count, 3: managed link count */
                    esc_html__( '%1$d internal, %2$d external, %3$d managed links indexed.', 'katina-link-manager' ),
                    (int) $totals['internal'],
                    (int) $totals['external'],
                    (int) $totals['managed']
                );
                ?>
            <?php else : ?>
                <strong><?php esc_html_e( 'No scan has been run yet.', 'katina-link-manager' ); ?></strong>
                <?php
                printf(
                    /* translators: %d: number of posts that will be scanned */
                    esc_html__( ' %d posts are eligible.', 'katina-link-manager' ),
                    (int) $scannable
                );
                ?>
            <?php endif; ?>
        </p>
        <button type="button" class="button button-primary" id="katina-scan-start">
            <?php echo $last_run ? esc_html__( 'Rescan site', 'katina-link-manager' ) : esc_html__( 'Scan site', 'katina-link-manager' ); ?>
        </button>
        <span id="katina-scan-status" style="margin-left:10px;"></span>
        <progress id="katina-scan-progress" value="0" max="100" style="display:none;width:220px;vertical-align:middle;margin-left:10px;"></progress>
    </div>

    <?php if ( $has_index ) : ?>
        <form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
            <input type="hidden" name="post_type" value="katina_link" />
            <input type="hidden" name="page" value="<?php echo esc_attr( KATINA_Link_Report::PAGE_SLUG ); ?>" />

            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="post_type_filter">
                        <option value=""><?php esc_html_e( 'All post types', 'katina-link-manager' ); ?></option>
                        <?php foreach ( $post_types as $type ) : ?>
                            <?php $obj = get_post_type_object( $type ); ?>
                            <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $type, $args['post_type'] ); ?>>
                                <?php echo esc_html( $obj ? $obj->labels->name : $type ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label style="margin-left:8px;">
                        <input type="checkbox" name="orphans_only" value="1" <?php checked( (bool) $args['orphans_only'] ); ?> />
                        <?php esc_html_e( 'Orphans only', 'katina-link-manager' ); ?>
                    </label>

                    <input type="search" name="s" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search titles', 'katina-link-manager' ); ?>" style="margin-left:8px;" />
                    <?php submit_button( __( 'Filter', 'katina-link-manager' ), '', 'filter', false ); ?>
                </div>

                <div class="alignright">
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'format', 'csv', $export_base ), 'katina_export_index' ) ); ?>">
                        <?php esc_html_e( 'Export CSV', 'katina-link-manager' ); ?>
                    </a>
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'format' => 'csv', 'include_seo' => 1 ), $export_base ), 'katina_export_index' ) ); ?>">
                        <?php esc_html_e( 'Export CSV + SEO meta', 'katina-link-manager' ); ?>
                    </a>
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'format', 'json', $export_base ), 'katina_export_index' ) ); ?>">
                        <?php esc_html_e( 'Export JSON', 'katina-link-manager' ); ?>
                    </a>
                </div>
            </div>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:34%;"><?php echo wp_kses_post( KATINA_Link_Report::sort_link( 'title', __( 'Title', 'katina-link-manager' ), $args ) ); ?></th>
                    <th style="width:10%;"><?php esc_html_e( 'Type', 'katina-link-manager' ); ?></th>
                    <th style="width:10%;"><?php esc_html_e( 'Status', 'katina-link-manager' ); ?></th>
                    <th style="width:11%;"><?php echo wp_kses_post( KATINA_Link_Report::sort_link( 'incoming', __( 'Incoming', 'katina-link-manager' ), $args ) ); ?></th>
                    <th style="width:11%;"><?php echo wp_kses_post( KATINA_Link_Report::sort_link( 'outgoing', __( 'Outgoing', 'katina-link-manager' ), $args ) ); ?></th>
                    <th style="width:11%;"><?php echo wp_kses_post( KATINA_Link_Report::sort_link( 'external', __( 'External', 'katina-link-manager' ), $args ) ); ?></th>
                    <th style="width:13%;"><?php echo wp_kses_post( KATINA_Link_Report::sort_link( 'managed', __( 'Managed', 'katina-link-manager' ), $args ) ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No matching content.', 'katina-link-manager' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <?php $is_orphan = ( (int) $row['incoming'] === 0 ); ?>
                        <tr>
                            <td>
                                <strong><a href="<?php echo esc_url( get_edit_post_link( (int) $row['ID'] ) ); ?>"><?php echo esc_html( $row['post_title'] !== '' ? $row['post_title'] : __( '(no title)', 'katina-link-manager' ) ); ?></a></strong>
                                <?php if ( $is_orphan ) : ?>
                                    <span style="background:#fcf0f1;color:#8a2424;border-radius:3px;padding:1px 6px;font-size:11px;margin-left:6px;"><?php esc_html_e( 'Orphan', 'katina-link-manager' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $row['post_type'] ); ?></td>
                            <td><?php echo esc_html( $row['post_status'] ); ?></td>
                            <td><?php echo (int) $row['incoming']; ?></td>
                            <td><?php echo (int) $row['outgoing_internal']; ?></td>
                            <td><?php echo (int) $row['outgoing_external']; ?></td>
                            <td><?php echo (int) $row['outgoing_managed']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post( paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total'     => $pages,
                        'current'   => $current,
                    ) ) );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
