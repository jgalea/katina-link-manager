<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** @var KATINA_Link $link */

$dest_url      = $link->get_destination_url();
$redirect_type = get_post_meta( $link->get_id(), '_katina_redirect_type', true ) ?: 'default';
$nofollow      = get_post_meta( $link->get_id(), '_katina_nofollow', true ) ?: 'default';
$sponsored     = get_post_meta( $link->get_id(), '_katina_sponsored', true ) ?: 'default';
$new_window    = get_post_meta( $link->get_id(), '_katina_new_window', true ) ?: 'default';
$pass_qs       = get_post_meta( $link->get_id(), '_katina_pass_query_str', true ) ?: 'default';
$css_classes   = $link->get_css_classes();
$rel_tags      = get_post_meta( $link->get_id(), '_katina_rel_tags', true );
?>
<table class="form-table katina-meta-box">
    <tr>
        <th><label for="katina_destination_url"><?php esc_html_e( 'Destination URL', 'katina-link-manager' ); ?></label></th>
        <td><input type="url" id="katina_destination_url" name="katina_destination_url" value="<?php echo esc_url( $dest_url ); ?>" class="widefat" placeholder="https://" required /></td>
    </tr>
    <tr>
        <th><label><?php esc_html_e( 'Cloaked URL', 'katina-link-manager' ); ?></label></th>
        <td>
            <span class="katina-cloaked-url-wrap">
                <code id="katina-cloaked-url"><?php echo esc_html( $link->get_cloaked_url() ); ?></code>
                <button type="button" class="katina-copy-btn" data-clipboard-target="#katina-cloaked-url" aria-label="<?php esc_attr_e( 'Copy URL to clipboard', 'katina-link-manager' ); ?>">
                    <span class="dashicons dashicons-clipboard"></span>
                    <span class="katina-copy-label"><?php esc_html_e( 'Copy', 'katina-link-manager' ); ?></span>
                </button>
            </span>
        </td>
    </tr>
    <tr class="katina-field-group">
        <th><label for="katina_redirect_type"><?php esc_html_e( 'Redirect Type', 'katina-link-manager' ); ?></label></th>
        <td>
            <select id="katina_redirect_type" name="katina_redirect_type">
                <option value="default" <?php selected( $redirect_type, 'default' ); ?>><?php
                    /* translators: %s: site default value (e.g. 307) */
                    printf( esc_html__( 'Default (%s)', 'katina-link-manager' ), esc_html( get_option( 'katina_redirect_type', '307' ) ) );
                ?></option>
                <option value="301" <?php selected( $redirect_type, '301' ); ?>>301 (Permanent)</option>
                <option value="302" <?php selected( $redirect_type, '302' ); ?>>302 (Temporary)</option>
                <option value="307" <?php selected( $redirect_type, '307' ); ?>>307 (Temporary Strict)</option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label for="katina_nofollow"><?php esc_html_e( 'Nofollow', 'katina-link-manager' ); ?></label></th>
        <td>
            <select id="katina_nofollow" name="katina_nofollow">
                <option value="default" <?php selected( $nofollow, 'default' ); ?>><?php
                    /* translators: %s: site default value (Yes or No) */
                    printf( esc_html__( 'Default (%s)', 'katina-link-manager' ), get_option( 'katina_nofollow', 'yes' ) === 'yes' ? esc_html__( 'Yes', 'katina-link-manager' ) : esc_html__( 'No', 'katina-link-manager' ) );
                ?></option>
                <option value="yes" <?php selected( $nofollow, 'yes' ); ?>><?php esc_html_e( 'Yes', 'katina-link-manager' ); ?></option>
                <option value="no" <?php selected( $nofollow, 'no' ); ?>><?php esc_html_e( 'No', 'katina-link-manager' ); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label for="katina_sponsored"><?php esc_html_e( 'Sponsored', 'katina-link-manager' ); ?></label></th>
        <td>
            <select id="katina_sponsored" name="katina_sponsored">
                <option value="default" <?php selected( $sponsored, 'default' ); ?>><?php
                    /* translators: %s: site default value (Yes or No) */
                    printf( esc_html__( 'Default (%s)', 'katina-link-manager' ), KATINA_Link::sponsored_disabled() ? esc_html__( 'Never', 'katina-link-manager' ) : ( get_option( 'katina_sponsored', 'no' ) === 'yes' ? esc_html__( 'Yes', 'katina-link-manager' ) : esc_html__( 'No', 'katina-link-manager' ) ) );
                ?></option>
                <option value="yes" <?php selected( $sponsored, 'yes' ); ?>><?php esc_html_e( 'Yes', 'katina-link-manager' ); ?></option>
                <option value="no" <?php selected( $sponsored, 'no' ); ?>><?php esc_html_e( 'No', 'katina-link-manager' ); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label for="katina_new_window"><?php esc_html_e( 'Open in New Window', 'katina-link-manager' ); ?></label></th>
        <td>
            <select id="katina_new_window" name="katina_new_window">
                <option value="default" <?php selected( $new_window, 'default' ); ?>><?php
                    /* translators: %s: site default value (Yes or No) */
                    printf( esc_html__( 'Default (%s)', 'katina-link-manager' ), get_option( 'katina_new_window', 'yes' ) === 'yes' ? esc_html__( 'Yes', 'katina-link-manager' ) : esc_html__( 'No', 'katina-link-manager' ) );
                ?></option>
                <option value="yes" <?php selected( $new_window, 'yes' ); ?>><?php esc_html_e( 'Yes', 'katina-link-manager' ); ?></option>
                <option value="no" <?php selected( $new_window, 'no' ); ?>><?php esc_html_e( 'No', 'katina-link-manager' ); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label for="katina_pass_query_str"><?php esc_html_e( 'Pass Query Strings', 'katina-link-manager' ); ?></label></th>
        <td>
            <select id="katina_pass_query_str" name="katina_pass_query_str">
                <option value="default" <?php selected( $pass_qs, 'default' ); ?>><?php
                    /* translators: %s: site default value (Yes or No) */
                    printf( esc_html__( 'Default (%s)', 'katina-link-manager' ), get_option( 'katina_pass_query_str', 'no' ) === 'yes' ? esc_html__( 'Yes', 'katina-link-manager' ) : esc_html__( 'No', 'katina-link-manager' ) );
                ?></option>
                <option value="yes" <?php selected( $pass_qs, 'yes' ); ?>><?php esc_html_e( 'Yes', 'katina-link-manager' ); ?></option>
                <option value="no" <?php selected( $pass_qs, 'no' ); ?>><?php esc_html_e( 'No', 'katina-link-manager' ); ?></option>
            </select>
        </td>
    </tr>
    <tr class="katina-field-group">
        <th><label for="katina_css_classes"><?php esc_html_e( 'CSS Classes', 'katina-link-manager' ); ?></label></th>
        <td><input type="text" id="katina_css_classes" name="katina_css_classes" value="<?php echo esc_attr( $css_classes ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'Optional CSS classes', 'katina-link-manager' ); ?>" /></td>
    </tr>
    <tr>
        <th><label for="katina_rel_tags"><?php esc_html_e( 'Additional Rel Tags', 'katina-link-manager' ); ?></label></th>
        <td>
            <input type="text" id="katina_rel_tags" name="katina_rel_tags" value="<?php echo esc_attr( $rel_tags ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'e.g. ugc', 'katina-link-manager' ); ?>" />
            <p class="description"><?php esc_html_e( 'Space-separated. Nofollow and sponsored are handled by their own settings above.', 'katina-link-manager' ); ?></p>
        </td>
    </tr>
</table>
