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
$sub_id_param  = get_post_meta( $link->get_id(), '_katina_sub_id_param', true );
$click_param   = get_post_meta( $link->get_id(), '_katina_click_id_param', true );
$geo_countries = get_post_meta( $link->get_id(), '_katina_geo_countries', true );
$geo_fallback  = get_post_meta( $link->get_id(), '_katina_geo_fallback_url', true );
$expires_ts    = (int) get_post_meta( $link->get_id(), '_katina_expires_at', true );
$expires_at    = $expires_ts ? wp_date( 'Y-m-d\TH:i', $expires_ts ) : '';
$max_clicks    = (int) get_post_meta( $link->get_id(), '_katina_max_clicks', true );
$expired_url   = get_post_meta( $link->get_id(), '_katina_expired_url', true );
?>
<table class="form-table katina-meta-box">
    <tr>
        <th><label for="katina_destination_url"><?php esc_html_e( 'Destination URL', 'katina-link-manager' ); ?></label></th>
        <td><input type="url" id="katina_destination_url" name="katina_destination_url" value="<?php echo esc_url( $dest_url ); ?>" class="widefat" placeholder="https://" required /></td>
    </tr>
    <tr>
        <th><label for="katina_slug"><?php esc_html_e( 'Slug', 'katina-link-manager' ); ?></label></th>
        <td>
            <input type="text" id="katina_slug" name="katina_slug" value="<?php echo esc_attr( $link->get_slug() ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Generated from the title', 'katina-link-manager' ); ?>" data-post-id="<?php echo esc_attr( $link->get_id() ); ?>" autocomplete="off" />
            <button type="button" class="button" id="katina-generate-slug"><?php esc_html_e( 'Generate short slug', 'katina-link-manager' ); ?></button>
            <p class="description"><?php esc_html_e( 'The last part of the cloaked URL, for example', 'katina-link-manager' ); ?> <code id="katina-slug-preview"><?php echo esc_html( $link->get_cloaked_url() ); ?></code></p>
            <p class="description katina-slug-feedback" id="katina-slug-feedback" role="status" aria-live="polite"></p>
        </td>
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
                <option value="308" <?php selected( $redirect_type, '308' ); ?>>308 (Permanent Strict)</option>
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
    <tr class="katina-field-group">
        <th><label for="katina_sub_id_param"><?php esc_html_e( 'Sub-ID Parameter', 'katina-link-manager' ); ?></label></th>
        <td>
            <input type="text" id="katina_sub_id_param" name="katina_sub_id_param" value="<?php echo esc_attr( $sub_id_param ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. sub_id', 'katina-link-manager' ); ?>" />
            <p class="description"><?php esc_html_e( 'Adds the slug of the page the link was clicked on to the destination under this parameter name, so your affiliate network can report per page. If the network wants the sub-ID inside another value, put [page] in the destination URL instead and leave this blank. Links in post content are tagged automatically.', 'katina-link-manager' ); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="katina_click_id_param"><?php esc_html_e( 'Click ID Parameter', 'katina-link-manager' ); ?></label></th>
        <td>
            <input type="text" id="katina_click_id_param" name="katina_click_id_param" value="<?php echo esc_attr( $click_param ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. click_id', 'katina-link-manager' ); ?>" />
            <p class="description"><?php esc_html_e( 'Adds the ID of each logged click to the destination under this parameter name, so a sign-up reported by your affiliate network can be matched to the click in your export. To put it inside another value, use [click] in the destination URL instead and leave this blank.', 'katina-link-manager' ); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="katina_geo_countries"><?php esc_html_e( 'Excluded Countries', 'katina-link-manager' ); ?></label></th>
        <td>
            <input type="text" id="katina_geo_countries" name="katina_geo_countries" value="<?php echo esc_attr( $geo_countries ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. PT, ES', 'katina-link-manager' ); ?>" />
            <p class="description"><?php esc_html_e( 'Two-letter country codes. Visitors from these countries go to the fallback URL below instead. Needs a CDN or host that supplies the visitor country.', 'katina-link-manager' ); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="katina_geo_fallback_url"><?php esc_html_e( 'Fallback URL', 'katina-link-manager' ); ?></label></th>
        <td><input type="url" id="katina_geo_fallback_url" name="katina_geo_fallback_url" value="<?php echo esc_url( $geo_fallback ); ?>" class="widefat" placeholder="https://" /></td>
    </tr>
    <tr class="katina-field-group">
        <th><label for="katina_expires_at"><?php esc_html_e( 'Expires On', 'katina-link-manager' ); ?></label></th>
        <td>
            <input type="datetime-local" id="katina_expires_at" name="katina_expires_at" value="<?php echo esc_attr( $expires_at ); ?>" />
            <p class="description"><?php esc_html_e( 'Site time. After this, visitors go to the expiry URL below.', 'katina-link-manager' ); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="katina_max_clicks"><?php esc_html_e( 'Expires After Clicks', 'katina-link-manager' ); ?></label></th>
        <td>
            <input type="number" min="0" step="1" id="katina_max_clicks" name="katina_max_clicks" value="<?php echo $max_clicks ? esc_attr( $max_clicks ) : ''; ?>" class="small-text" />
            <p class="description"><?php esc_html_e( 'Counts clicks from visitors, not bots. Leave blank for no limit.', 'katina-link-manager' ); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="katina_expired_url"><?php esc_html_e( 'Expiry URL', 'katina-link-manager' ); ?></label></th>
        <td>
            <input type="url" id="katina_expired_url" name="katina_expired_url" value="<?php echo esc_url( $expired_url ); ?>" class="widefat" placeholder="https://" />
            <p class="description"><?php esc_html_e( 'Where visitors go once the link has expired. Leave blank to show a not-found page.', 'katina-link-manager' ); ?></p>
        </td>
    </tr>
</table>
