<?php
/**
 * Plugin Name: Katina Link Manager
 * Description: Manage, cloak, track, and optimize your outbound links from one place.
 * Version: 1.5.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: RebelCode
 * Author URI: https://rebelcode.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: katina-link-manager
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KATINA_VERSION', '1.5.0' );
define( 'KATINA_PLUGIN_FILE', __FILE__ );
define( 'KATINA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KATINA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KATINA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader — defense-in-depth: only permit resolved paths inside plugin dir.
spl_autoload_register( function ( $class ) {
    $prefix = 'KATINA_';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }
    // Reject class names containing traversal chars; PHP won't produce these in
    // a class identifier but belt-and-braces guards against someone hand-calling
    // the loader with an attacker-influenced string.
    if ( strpbrk( $class, '/\\.' ) !== false ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $filename = 'class-katina-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

    $base = realpath( KATINA_PLUGIN_DIR );
    foreach ( array( 'includes/', 'includes/migrators/' ) as $subdir ) {
        $file = KATINA_PLUGIN_DIR . $subdir . $filename;
        if ( ! file_exists( $file ) ) {
            continue;
        }
        $resolved = realpath( $file );
        if ( $resolved && $base && strpos( $resolved, $base ) === 0 ) {
            require_once $resolved;
        }
        return;
    }
} );

// The main class is named Katina Link Manager, not KATINA_*, so load it manually
require_once KATINA_PLUGIN_DIR . 'includes/class-katina-link-manager.php';

register_activation_hook( __FILE__, array( 'KATINA_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KATINA_Deactivator', 'deactivate' ) );

Katina_Link_Manager::instance();
