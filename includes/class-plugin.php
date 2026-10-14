<?php
/**
 * Plugin bootstrap.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 *
 * Starts the site-wide features (settings page, side dock, scrollbar), which
 * work without Elementor, and — when a supported Elementor is active — registers
 * the widget category, the widget assets and the widgets. Widget assets are only
 * registered here; every widget declares the handles it needs via
 * get_script_depends() and get_style_depends(), so Elementor enqueues them only
 * on pages that use it.
 */
final class Plugin {

	/**
	 * Elementor panel category of all widgets of this plugin.
	 */
	const CATEGORY = 'listemann';

	/**
	 * Widgets to register.
	 *
	 * To add a widget, create `includes/widgets/class-{name}.php` containing
	 * `Listemann_Toolkit\Widgets\{Name}` and append the class here.
	 */
	const WIDGETS = array(
		Widgets\Continuous_Slider::class,
		Widgets\Menu::class,
		Widgets\Header::class,
	);

	/**
	 * Widget scripts: handle => [ path relative to the plugin root, dependencies ].
	 */
	const SCRIPTS = array(
		'listemann-toolkit-continuous-slider' => array( 'assets/js/continuous-slider.js', array( 'elementor-frontend' ) ),
		'listemann-toolkit-header'            => array( 'assets/js/header.js', array() ),
	);

	/**
	 * Widget styles: handle => [ path relative to the plugin root, dependencies ].
	 */
	const STYLES = array(
		'listemann-toolkit-continuous-slider' => array( 'assets/css/continuous-slider.css', array() ),
		'listemann-toolkit-menu'              => array( 'assets/css/menu.css', array() ),
		'listemann-toolkit-header'            => array( 'assets/css/header.css', array( 'listemann-toolkit-menu' ) ),
	);

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the plugin instance, creating it on first call.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks the plugin into WordPress and, when available, Elementor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Site-wide features, independent of Elementor.
		Frontend\Side_Dock::init();
		Frontend\Scrollbar::init();
		Mega_Menu_Fields::init();

		if ( is_admin() ) {
			Admin\Settings_Page::init( plugin_basename( LISTEMANN_TOOLKIT_FILE ) );
		}

		if ( ! did_action( 'elementor/loaded' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_elementor' ) );
			return;
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) || version_compare( ELEMENTOR_VERSION, LISTEMANN_TOOLKIT_MIN_ELEMENTOR, '<' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_outdated_elementor' ) );
			return;
		}

		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( $this, 'register_scripts' ) );
		add_action( 'elementor/frontend/after_register_styles', array( $this, 'register_styles' ) );
	}

	/**
	 * Prevents cloning of the singleton.
	 */
	private function __clone() {}

	/**
	 * Prevents unserializing of the singleton.
	 *
	 * @throws \LogicException Always.
	 */
	public function __wakeup() {
		throw new \LogicException( 'Cannot unserialize a singleton.' );
	}

	/**
	 * Loads translations from the plugin's languages folder.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'listemann-toolkit-addon', false, dirname( plugin_basename( LISTEMANN_TOOLKIT_FILE ) ) . '/languages' );
	}

	/**
	 * Adds the "Listemann" category to the Elementor panel.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elementor elements manager.
	 * @return void
	 */
	public function register_category( $elements_manager ) {
		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => esc_html__( 'Listemann', 'listemann-toolkit-addon' ),
				'icon'  => 'eicon-slider-push',
			)
		);
	}

	/**
	 * Registers all widgets listed in self::WIDGETS.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ) {
		foreach ( self::WIDGETS as $widget_class ) {
			if ( class_exists( $widget_class ) ) {
				$widgets_manager->register( new $widget_class() );
			}
		}
	}

	/**
	 * Registers the front-end scripts (front end and editor preview).
	 *
	 * @return void
	 */
	public function register_scripts() {
		foreach ( self::SCRIPTS as $handle => $script ) {
			wp_register_script(
				$handle,
				LISTEMANN_TOOLKIT_URL . $script[0],
				$script[1],
				$this->get_asset_version( $script[0] ),
				array( 'in_footer' => true )
			);
		}
	}

	/**
	 * Registers the front-end styles (front end and editor preview).
	 *
	 * @return void
	 */
	public function register_styles() {
		foreach ( self::STYLES as $handle => $style ) {
			wp_register_style(
				$handle,
				LISTEMANN_TOOLKIT_URL . $style[0],
				$style[1],
				$this->get_asset_version( $style[0] )
			);
		}
	}

	/**
	 * Asset version: the file modification time while SCRIPT_DEBUG is on,
	 * the plugin version otherwise.
	 *
	 * @param string $relative_path Path relative to the plugin root.
	 * @return string
	 */
	private function get_asset_version( $relative_path ) {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$mtime = @filemtime( LISTEMANN_TOOLKIT_PATH . $relative_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( $mtime ) {
				return (string) $mtime;
			}
		}

		return LISTEMANN_TOOLKIT_VERSION;
	}

	/**
	 * Admin notice: Elementor is not installed or not active.
	 *
	 * @return void
	 */
	public function notice_missing_elementor() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$plugin = 'elementor/elementor.php';
		$button = '';

		if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			$button = sprintf(
				'<a href="%s" class="button button-primary">%s</a>',
				esc_url( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . $plugin ), 'activate-plugin_' . $plugin ) ),
				esc_html__( 'Activate Elementor', 'listemann-toolkit-addon' )
			);
		} elseif ( current_user_can( 'install_plugins' ) ) {
			$button = sprintf(
				'<a href="%s" class="button button-primary">%s</a>',
				esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=elementor' ), 'install-plugin_elementor' ) ),
				esc_html__( 'Install Elementor', 'listemann-toolkit-addon' )
			);
		}

		$this->print_notice(
			sprintf(
				/* translators: 1: Plugin name, 2: Elementor. */
				esc_html__( '%1$s needs %2$s to be installed and active. Its widgets are unavailable until then.', 'listemann-toolkit-addon' ),
				'<strong>Listemann Toolkit Addon</strong>',
				'<strong>Elementor</strong>'
			),
			$button
		);
	}

	/**
	 * Admin notice: the installed Elementor version is too old.
	 *
	 * @return void
	 */
	public function notice_outdated_elementor() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$button = '';

		if ( current_user_can( 'update_plugins' ) ) {
			$button = sprintf(
				'<a href="%s" class="button button-primary">%s</a>',
				esc_url( self_admin_url( 'update-core.php' ) ),
				esc_html__( 'Update Elementor', 'listemann-toolkit-addon' )
			);
		}

		$this->print_notice(
			sprintf(
				/* translators: 1: Plugin name, 2: Elementor, 3: Required Elementor version. */
				esc_html__( '%1$s needs %2$s version %3$s or newer. Its widgets are unavailable until Elementor is updated.', 'listemann-toolkit-addon' ),
				'<strong>Listemann Toolkit Addon</strong>',
				'<strong>Elementor</strong>',
				esc_html( LISTEMANN_TOOLKIT_MIN_ELEMENTOR )
			),
			$button
		);
	}

	/**
	 * Prints an error notice.
	 *
	 * @param string $message Escaped message HTML.
	 * @param string $button  Escaped button HTML, may be empty.
	 * @return void
	 */
	private function print_notice( $message, $button ) {
		printf(
			'<div class="notice notice-error"><p>%1$s</p>%2$s</div>',
			wp_kses_post( $message ),
			$button ? '<p>' . wp_kses_post( $button ) . '</p>' : ''
		);
	}
}
