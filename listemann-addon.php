<?php
/**
 * Plugin Name:       Listemann Addon
 * Description:       Custom Elementor widgets for listemann.com, starting with a continuous, full-width image slider.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            SH Digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       listemann-addon
 * Domain Path:       /languages
 *
 * Elementor tested up to:     4.3.5
 * Elementor Pro tested up to: 4.3.1
 *
 * @package Listemann_Addon
 */

defined( 'ABSPATH' ) || exit;

define( 'LISTEMANN_ADDON_VERSION', '1.0.0' );
define( 'LISTEMANN_ADDON_FILE', __FILE__ );
define( 'LISTEMANN_ADDON_PATH', plugin_dir_path( __FILE__ ) );
define( 'LISTEMANN_ADDON_URL', plugin_dir_url( __FILE__ ) );
define( 'LISTEMANN_ADDON_MIN_PHP', '7.4' );
define( 'LISTEMANN_ADDON_MIN_ELEMENTOR', '3.24.0' );

/**
 * Admin notice shown when the server runs an unsupported PHP version.
 *
 * Kept in this file (and free of modern syntax) because the plugin classes are
 * never loaded on such servers.
 *
 * @return void
 */
function listemann_addon_php_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: Plugin name, 2: Required PHP version, 3: Current PHP version. */
				__( '%1$s requires PHP %2$s or newer. This site runs PHP %3$s, so the plugin is inactive.', 'listemann-addon' ),
				'Listemann Addon',
				LISTEMANN_ADDON_MIN_PHP,
				PHP_VERSION
			)
		)
	);
}

if ( version_compare( PHP_VERSION, LISTEMANN_ADDON_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'listemann_addon_php_notice' );
	return;
}

/**
 * Autoloads classes of the `Listemann_Addon` namespace.
 *
 * Follows the WordPress file naming convention:
 * `Listemann_Addon\Widgets\Continuous_Slider` → `includes/widgets/class-continuous-slider.php`.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function listemann_addon_autoload( $class_name ) {
	$prefix = 'Listemann_Addon\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
	$class = array_pop( $parts );
	$dirs  = array_map(
		static function ( $part ) {
			return strtolower( str_replace( '_', '-', $part ) );
		},
		$parts
	);
	$file  = LISTEMANN_ADDON_PATH . 'includes/' . ( $dirs ? implode( '/', $dirs ) . '/' : '' )
		. 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}

spl_autoload_register( 'listemann_addon_autoload' );

// Elementor may load after this plugin, so its availability is checked on plugins_loaded.
add_action( 'plugins_loaded', array( 'Listemann_Addon\\Plugin', 'instance' ) );
