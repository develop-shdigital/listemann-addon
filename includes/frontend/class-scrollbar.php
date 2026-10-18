<?php
/**
 * Branded scrollbar (brand red by default) on all front-end pages.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit\Frontend;

use Listemann_Toolkit\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Prints a small inline stylesheet that colors the page scrollbar.
 *
 * Chromium/WebKit use the `::-webkit-scrollbar` pseudo-elements (these allow a
 * pixel width). Firefox only supports the standard `scrollbar-color` and
 * `scrollbar-width: thin|auto`; they are wrapped in
 * `@supports not selector(::-webkit-scrollbar)` because Chrome 121+ ignores
 * the `::-webkit-scrollbar` styles of an element that has `scrollbar-color`
 * or `scrollbar-width` set.
 *
 * All rules are scoped to the page scroller (`html`, or `body` for themes
 * that scroll the body), so inner scrollbars (textareas, tables, panels) keep
 * their default size.
 */
final class Scrollbar {

	/**
	 * Style handle (inline-only style, no file).
	 *
	 * @var string
	 */
	const HANDLE = 'listemann-toolkit-scrollbar';

	/**
	 * Widths up to this value (px) map to `scrollbar-width: thin` in Firefox.
	 *
	 * @var int
	 */
	const FIREFOX_THIN_MAX = 8;

	/**
	 * Whether the hooks have been added.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Registers the hooks. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueues the inline scrollbar styles when enabled.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}

		$css = self::get_css( Settings::get() );

		if ( '' === $css ) {
			return;
		}

		wp_register_style( self::HANDLE, false, array(), LISTEMANN_TOOLKIT_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, $css );
	}

	/**
	 * Builds the scrollbar CSS for the given settings.
	 *
	 * @param array $settings Plugin settings.
	 * @return string CSS, or an empty string when the scrollbar is disabled.
	 */
	public static function get_css( array $settings ) {
		$scrollbar = isset( $settings['scrollbar'] ) && is_array( $settings['scrollbar'] ) ? $settings['scrollbar'] : array();
		$enabled   = ! empty( $scrollbar['enabled'] );

		/**
		 * Filters whether the branded scrollbar styles are printed.
		 *
		 * @param bool  $enabled  Whether the branded scrollbar is enabled.
		 * @param array $settings Current plugin settings.
		 */
		if ( ! apply_filters( 'listemann_toolkit_scrollbar_enabled', $enabled, $settings ) ) {
			return '';
		}

		$defaults = Settings::defaults();
		$brand    = Settings::color(
			isset( $settings['appearance']['brand_color'] ) ? $settings['appearance']['brand_color'] : '',
			$defaults['appearance']['brand_color']
		);
		$color    = Settings::color( isset( $scrollbar['color'] ) ? $scrollbar['color'] : '', $brand );
		$width    = isset( $scrollbar['width'] ) ? max( 2, min( 24, absint( $scrollbar['width'] ) ) ) : (int) $defaults['scrollbar']['width'];
		$ff_width = $width <= self::FIREFOX_THIN_MAX ? 'thin' : 'auto';

		return sprintf(
			'html::-webkit-scrollbar,body::-webkit-scrollbar{-webkit-appearance:none;width:%1$dpx;height:%1$dpx}'
			. 'html::-webkit-scrollbar-thumb,body::-webkit-scrollbar-thumb{border-radius:0;background-color:%2$s;box-shadow:0 0 1px rgba(255,255,255,.5)}'
			. 'html::-webkit-scrollbar-track,html::-webkit-scrollbar-corner,body::-webkit-scrollbar-track,body::-webkit-scrollbar-corner{background-color:transparent}'
			. '@supports not selector(::-webkit-scrollbar){html{scrollbar-color:%2$s transparent;scrollbar-width:%3$s}}',
			$width,
			$color,
			$ff_width
		);
	}
}
