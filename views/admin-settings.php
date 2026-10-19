<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$tabs    = KATINA_Settings::get_tabs();
$current = KATINA_Settings::get_current_tab();
?>
<div class="wrap katina-settings-wrap">
    <h1><?php esc_html_e( 'Katina Link Manager Settings', 'katina-link-manager' ); ?></h1>

    <nav class="nav-tab-wrapper">
        <?php foreach ( $tabs as $key => $label ) : ?>
            <a href="<?php echo esc_url( KATINA_Settings::get_tab_url( $key ) ); ?>"
               class="nav-tab<?php echo $current === $key ? ' nav-tab-active' : ''; ?>">
                <?php echo esc_html( $label ); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ( $current === 'pro' && class_exists( 'KATINA_PRO_Settings' ) ) : ?>
        <?php KATINA_PRO_Settings::render_form(); ?>
    <?php else : ?>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'katina_settings_group' );
            do_settings_sections( 'katina-settings-' . $current );
            submit_button();
            ?>
        </form>
    <?php endif; ?>
</div>
