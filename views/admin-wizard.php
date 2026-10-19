<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$competitors = KATINA_Setup_Wizard::detect_competitors();
?>

<div class="wrap katina-wizard-wrap">
    <h1><?php esc_html_e( 'Welcome to Katina Link Manager', 'katina-link-manager' ); ?></h1>
    <p><?php esc_html_e( 'Let\'s get your link manager set up. This will only take a moment.', 'katina-link-manager' ); ?></p>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'katina_wizard_save' ); ?>
        <input type="hidden" name="action" value="katina_wizard_save" />

        <?php if ( ! empty( $competitors ) ) : ?>
        <div class="katina-card">
            <h2><?php esc_html_e( 'Migrate Your Links', 'katina-link-manager' ); ?></h2>
            <p><?php esc_html_e( 'We detected link data from another plugin. Want to import it?', 'katina-link-manager' ); ?></p>

            <?php foreach ( $competitors as $key => $info ) : ?>
                <label class="katina-radio-card">
                    <input type="radio" name="katina_migrate_source" value="<?php echo esc_attr( $key ); ?>" />
                    <strong><?php echo esc_html( $info['name'] ); ?></strong>
                    — <?php
                    /* translators: %d: number of links found in source plugin */
                    printf( esc_html( _n( '%d link found', '%d links found', (int) $info['count'], 'katina-link-manager' ) ), (int) $info['count'] );
                    ?>
                </label>
            <?php endforeach; ?>

            <label class="katina-radio-card">
                <input type="radio" name="katina_migrate_source" value="" checked />
                <?php esc_html_e( 'Skip migration — I\'ll start fresh', 'katina-link-manager' ); ?>
            </label>

            <p class="katina-scan-option">
                <label>
                    <input type="checkbox" name="katina_scan_content" value="1" checked />
                    <?php esc_html_e( 'Also update shortcodes in existing posts', 'katina-link-manager' ); ?>
                </label>
            </p>
        </div>
        <?php endif; ?>

        <div class="katina-card">
            <h2><?php esc_html_e( 'Link Defaults', 'katina-link-manager' ); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'URL Prefix', 'katina-link-manager' ); ?></th>
                    <td>
                        <input type="text" name="katina_link_prefix" value="<?php echo esc_attr( get_option( 'katina_link_prefix', 'go' ) ); ?>" class="regular-text" />
                        <p class="description"><?php
                            /* translators: %s: home URL */
                            printf( esc_html__( 'Links will be: %s/go/your-link/', 'katina-link-manager' ), esc_html( home_url() ) );
                        ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Redirect Type', 'katina-link-manager' ); ?></th>
                    <td>
                        <select name="katina_redirect_type">
                            <option value="307" <?php selected( get_option( 'katina_redirect_type', '307' ), '307' ); ?>>307 (Temporary)</option>
                            <option value="301" <?php selected( get_option( 'katina_redirect_type', '307' ), '301' ); ?>>301 (Permanent)</option>
                            <option value="302" <?php selected( get_option( 'katina_redirect_type', '307' ), '302' ); ?>>302 (Found)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Add nofollow', 'katina-link-manager' ); ?></th>
                    <td>
                        <select name="katina_nofollow">
                            <option value="yes" <?php selected( get_option( 'katina_nofollow', 'yes' ), 'yes' ); ?>><?php esc_html_e( 'Yes', 'katina-link-manager' ); ?></option>
                            <option value="no" <?php selected( get_option( 'katina_nofollow', 'yes' ), 'no' ); ?>><?php esc_html_e( 'No', 'katina-link-manager' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Open in new window', 'katina-link-manager' ); ?></th>
                    <td>
                        <select name="katina_new_window">
                            <option value="yes" <?php selected( get_option( 'katina_new_window', 'yes' ), 'yes' ); ?>><?php esc_html_e( 'Yes', 'katina-link-manager' ); ?></option>
                            <option value="no" <?php selected( get_option( 'katina_new_window', 'yes' ), 'no' ); ?>><?php esc_html_e( 'No', 'katina-link-manager' ); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button( __( 'Finish Setup', 'katina-link-manager' ), 'primary large' ); ?>
        <a class="katina-skip-link" href="<?php echo esc_url( admin_url( 'edit.php?post_type=katina_link' ) ); ?>"><?php esc_html_e( 'Skip setup', 'katina-link-manager' ); ?></a>
    </form>
</div>
