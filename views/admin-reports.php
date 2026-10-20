<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var array $data */
$filters   = $data['filters'];
$series    = $data['series'];
$presets   = KATINA_Reports::get_presets();
$max       = 0;
foreach ( $series as $point ) {
    if ( $point['clicks'] > $max ) {
        $max = $point['clicks'];
    }
}
$y_max = $max > 0 ? $max : 1;

// Chart geometry. Rendered as inline SVG so the page needs no charting library.
$chart_w = 1000;
$chart_h = 260;
$pad_l   = 44;
$pad_b   = 28;
$pad_t   = 12;
$plot_w  = $chart_w - $pad_l - 12;
$plot_h  = $chart_h - $pad_b - $pad_t;
$count   = max( 1, count( $series ) );
$step    = $count > 1 ? $plot_w / ( $count - 1 ) : 0;

$points = array();
foreach ( array_values( $series ) as $i => $point ) {
    $x        = $pad_l + ( $i * $step );
    $y        = $pad_t + $plot_h - ( ( $point['clicks'] / $y_max ) * $plot_h );
    $points[] = array( 'x' => $x, 'y' => $y, 'p' => $point );
}
?>
<div class="wrap katina-reports-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Reports', 'katina-link-manager' ); ?></h1>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
        <?php wp_nonce_field( 'katina_export_clicks' ); ?>
        <input type="hidden" name="action" value="katina_export_clicks" />
        <input type="hidden" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" />
        <input type="hidden" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" />
        <input type="hidden" name="link_id" value="<?php echo esc_attr( $filters['link_id'] ); ?>" />
        <button type="submit" class="page-title-action"><?php esc_html_e( 'Export CSV', 'katina-link-manager' ); ?></button>
    </form>

    <hr class="wp-header-end" />

    <form method="get" class="katina-reports-filters">
        <input type="hidden" name="post_type" value="katina_link" />
        <input type="hidden" name="page" value="<?php echo esc_attr( KATINA_Reports::PAGE_SLUG ); ?>" />

        <label for="katina-from"><?php esc_html_e( 'From', 'katina-link-manager' ); ?></label>
        <input type="date" id="katina-from" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" />

        <label for="katina-to"><?php esc_html_e( 'To', 'katina-link-manager' ); ?></label>
        <input type="date" id="katina-to" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" />

        <label for="katina-link"><?php esc_html_e( 'Link', 'katina-link-manager' ); ?></label>
        <select id="katina-link" name="link_id">
            <option value="0"><?php esc_html_e( 'All links', 'katina-link-manager' ); ?></option>
            <?php foreach ( $data['links'] as $link ) : ?>
                <option value="<?php echo esc_attr( $link->ID ); ?>" <?php selected( $filters['link_id'], $link->ID ); ?>>
                    <?php echo esc_html( $link->post_title ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="button"><?php esc_html_e( 'Apply', 'katina-link-manager' ); ?></button>
    </form>

    <div class="katina-reports-presets">
        <?php foreach ( $presets as $key => $label ) : ?>
            <a class="button<?php echo (string) $key === $filters['preset'] ? ' button-primary' : ''; ?>"
               href="<?php echo esc_url( KATINA_Reports::get_page_url( array(
                   'preset'  => $key,
                   'link_id' => $filters['link_id'],
               ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
        <?php endforeach; ?>
    </div>

    <p class="katina-reports-summary">
        <?php
        printf(
            /* translators: 1: click count, 2: unique click count, 3: start date, 4: end date */
            esc_html__( '%1$s clicks, %2$s unique, from %3$s to %4$s (bots excluded). Unique counts one click per visitor per link per day.', 'katina-link-manager' ),
            '<strong>' . esc_html( number_format_i18n( $data['total'] ) ) . '</strong>',
            '<strong>' . esc_html( number_format_i18n( $data['unique'] ) ) . '</strong>',
            esc_html( $filters['from'] ),
            esc_html( $filters['to'] )
        );
        ?>
    </p>

    <div class="katina-card">
        <h2><?php esc_html_e( 'Clicks per day', 'katina-link-manager' ); ?></h2>
        <?php if ( $data['total'] === 0 ) : ?>
            <p class="katina-empty"><?php esc_html_e( 'No clicks recorded in this range.', 'katina-link-manager' ); ?></p>
        <?php else : ?>
            <svg class="katina-chart" viewBox="0 0 <?php echo esc_attr( $chart_w ); ?> <?php echo esc_attr( $chart_h ); ?>"
                 role="img" preserveAspectRatio="none"
                 aria-label="<?php esc_attr_e( 'Clicks per day chart', 'katina-link-manager' ); ?>">
                <line x1="<?php echo esc_attr( $pad_l ); ?>" y1="<?php echo esc_attr( $pad_t ); ?>"
                      x2="<?php echo esc_attr( $pad_l ); ?>" y2="<?php echo esc_attr( $pad_t + $plot_h ); ?>"
                      stroke="#dcdcde" />
                <line x1="<?php echo esc_attr( $pad_l ); ?>" y1="<?php echo esc_attr( $pad_t + $plot_h ); ?>"
                      x2="<?php echo esc_attr( $pad_l + $plot_w ); ?>" y2="<?php echo esc_attr( $pad_t + $plot_h ); ?>"
                      stroke="#dcdcde" />

                <text x="<?php echo esc_attr( $pad_l - 8 ); ?>" y="<?php echo esc_attr( $pad_t + 4 ); ?>"
                      text-anchor="end" font-size="11" fill="#646970"><?php echo esc_html( number_format_i18n( $y_max ) ); ?></text>
                <text x="<?php echo esc_attr( $pad_l - 8 ); ?>" y="<?php echo esc_attr( $pad_t + $plot_h + 4 ); ?>"
                      text-anchor="end" font-size="11" fill="#646970">0</text>

                <?php
                $poly = array();
                foreach ( $points as $pt ) {
                    $poly[] = round( $pt['x'], 2 ) . ',' . round( $pt['y'], 2 );
                }
                $area = $poly;
                array_unshift( $area, round( $pad_l, 2 ) . ',' . round( $pad_t + $plot_h, 2 ) );
                $area[] = round( $pad_l + $plot_w, 2 ) . ',' . round( $pad_t + $plot_h, 2 );
                ?>
                <polygon points="<?php echo esc_attr( implode( ' ', $area ) ); ?>" fill="#e8e6ff" />
                <polyline points="<?php echo esc_attr( implode( ' ', $poly ) ); ?>"
                          fill="none" stroke="#4F46E5" stroke-width="2"
                          stroke-linejoin="round" stroke-linecap="round" />

                <?php foreach ( $points as $pt ) : ?>
                    <circle cx="<?php echo esc_attr( round( $pt['x'], 2 ) ); ?>"
                            cy="<?php echo esc_attr( round( $pt['y'], 2 ) ); ?>"
                            r="2.5" fill="#4F46E5">
                        <title><?php echo esc_html( sprintf( '%s: %s', $pt['p']['date'], number_format_i18n( $pt['p']['clicks'] ) ) ); ?></title>
                    </circle>
                <?php endforeach; ?>

                <text x="<?php echo esc_attr( $pad_l ); ?>" y="<?php echo esc_attr( $chart_h - 8 ); ?>"
                      font-size="11" fill="#646970"><?php echo esc_html( $filters['from'] ); ?></text>
                <text x="<?php echo esc_attr( $pad_l + $plot_w ); ?>" y="<?php echo esc_attr( $chart_h - 8 ); ?>"
                      text-anchor="end" font-size="11" fill="#646970"><?php echo esc_html( $filters['to'] ); ?></text>
            </svg>
        <?php endif; ?>
    </div>

    <div class="katina-reports-grid">
        <div class="katina-card">
            <h2><?php esc_html_e( 'Top referrers', 'katina-link-manager' ); ?></h2>
            <?php if ( empty( $data['referrers'] ) ) : ?>
                <p class="katina-empty"><?php esc_html_e( 'No referrer data in this range.', 'katina-link-manager' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Source', 'katina-link-manager' ); ?></th>
                            <th style="text-align:right;"><?php esc_html_e( 'Clicks', 'katina-link-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $data['referrers'] as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row->referrer ); ?></td>
                                <td style="text-align:right;"><?php echo esc_html( number_format_i18n( $row->clicks ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="katina-card">
            <h2><?php esc_html_e( 'Top countries', 'katina-link-manager' ); ?></h2>
            <?php if ( empty( $data['countries'] ) ) : ?>
                <p class="katina-empty"><?php esc_html_e( 'No country data in this range.', 'katina-link-manager' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Country', 'katina-link-manager' ); ?></th>
                            <th style="text-align:right;"><?php esc_html_e( 'Clicks', 'katina-link-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $data['countries'] as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row->country_code ); ?></td>
                                <td style="text-align:right;"><?php echo esc_html( number_format_i18n( $row->clicks ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( ! empty( $data['top_links'] ) ) : ?>
        <div class="katina-card">
            <h2><?php esc_html_e( 'Top links', 'katina-link-manager' ); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Link', 'katina-link-manager' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Clicks', 'katina-link-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $data['top_links'] as $row ) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( KATINA_Reports::get_page_url( array(
                                    'preset'  => $filters['preset'],
                                    'from'    => $filters['from'],
                                    'to'      => $filters['to'],
                                    'link_id' => $row->link_id,
                                ) ) ); ?>"><?php echo esc_html( $row->post_title ); ?></a>
                            </td>
                            <td style="text-align:right;"><?php echo esc_html( number_format_i18n( $row->clicks ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
