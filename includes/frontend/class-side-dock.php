<?php
/**
 * Global side dock: "Contact info" box (opens on click) and "Back to top"
 * button (label opens on hover / keyboard focus), printed on every
 * front-end page.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit\Frontend;

use Listemann_Toolkit\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the side dock in the front-end footer and enqueues its assets.
 */
final class Side_Dock {

	/**
	 * Style and script handle.
	 *
	 * @var string
	 */
	const HANDLE = 'listemann-toolkit-side-dock';

	/**
	 * ID of the contact panel (referenced by aria-controls).
	 *
	 * @var string
	 */
	const PANEL_ID = 'lst-dock-panel';

	/**
	 * Whether the hooks have been added.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Inline SVG icons, cached per request.
	 *
	 * @var array<string, string>
	 */
	private static $icons = array();

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

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 20 );
	}

	/**
	 * Whether the dock should be output on the current request.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || is_embed() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		$settings = Settings::get();
		$enabled  = self::has_contact( $settings ) || ! empty( $settings['to_top']['enabled'] );

		/**
		 * Filters whether the side dock (contact box + back to top) is printed.
		 *
		 * @param bool  $enabled  Whether the dock is enabled for this request.
		 * @param array $settings Current plugin settings.
		 */
		return (bool) apply_filters( 'listemann_toolkit_side_dock_enabled', $enabled, $settings );
	}

	/**
	 * Enqueues the dock CSS/JS (only when the dock is enabled).
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( ! self::is_enabled() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			LISTEMANN_TOOLKIT_URL . 'assets/css/side-dock.css',
			array(),
			LISTEMANN_TOOLKIT_VERSION
		);
		wp_add_inline_style( self::HANDLE, self::get_inline_css( Settings::get() ) );

		wp_enqueue_script(
			self::HANDLE,
			LISTEMANN_TOOLKIT_URL . 'assets/js/side-dock.js',
			array(),
			LISTEMANN_TOOLKIT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Custom properties driven by the settings.
	 *
	 * @param array $settings Plugin settings.
	 * @return string CSS.
	 */
	public static function get_inline_css( array $settings ) {
		$defaults   = Settings::defaults();
		$appearance = isset( $settings['appearance'] ) && is_array( $settings['appearance'] ) ? $settings['appearance'] : array();
		$colors     = array();

		foreach ( $defaults['appearance'] as $key => $default ) {
			$colors[ $key ] = Settings::color( isset( $appearance[ $key ] ) ? $appearance[ $key ] : '', $default );
		}

		return sprintf(
			'.lst-dock{--lst-dock-brand:%1$s;--lst-dock-text:%2$s;--lst-dock-top-bg:%3$s;--lst-dock-top-arrow:%4$s}',
			$colors['brand_color'],
			$colors['text_color'],
			$colors['to_top_color'],
			$colors['arrow_color']
		);
	}

	/**
	 * Prints the dock markup (hooked to wp_footer).
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! wp_style_is( self::HANDLE, 'enqueued' ) || ! self::is_enabled() ) {
			return;
		}

		$settings    = Settings::get();
		$has_contact = self::has_contact( $settings );
		$has_to_top  = ! empty( $settings['to_top']['enabled'] );
		$offset      = $has_to_top ? absint( $settings['to_top']['offset'] ) : 0;

		$classes = array( 'lst-dock' );
		if ( ! $has_contact ) {
			$classes[] = 'lst-dock--top-only';
		}
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-lst-dock data-lst-offset="<?php echo esc_attr( (string) $offset ); ?>">
			<?php
			if ( $has_contact ) {
				self::render_contact( $settings['contact'] );
			}

			if ( $has_to_top ) {
				self::render_to_top( $settings['to_top'], $offset );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Whether the contact box is enabled and has something to show.
	 *
	 * @param array $settings Plugin settings.
	 * @return bool
	 */
	private static function has_contact( array $settings ) {
		return ! empty( $settings['contact']['enabled'] )
			&& ! empty( $settings['contact']['sections'] )
			&& is_array( $settings['contact']['sections'] );
	}

	/**
	 * Prints the contact toggle and panel.
	 *
	 * @param array $contact Contact settings.
	 * @return void
	 */
	private static function render_contact( array $contact ) {
		$label = isset( $contact['label'] ) && '' !== trim( (string) $contact['label'] )
			? (string) $contact['label']
			: __( 'Contact', 'listemann-toolkit-addon' );
		?>
		<div class="lst-dock__contact" data-lst-contact>
			<button type="button" class="lst-dock__toggle" data-lst-toggle aria-expanded="false" aria-controls="<?php echo esc_attr( self::PANEL_ID ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
				<span class="lst-dock__icon lst-dock__icon--contact" aria-hidden="true"><?php echo self::icon( 'contact' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized with wp_kses() in icon(). ?></span>
			</button>
			<div class="lst-dock__panel" id="<?php echo esc_attr( self::PANEL_ID ); ?>" role="region" aria-label="<?php echo esc_attr( $label ); ?>" data-lst-panel>
				<?php
				foreach ( $contact['sections'] as $index => $section ) {
					if ( is_array( $section ) ) {
						self::render_section( $section, (int) $index );
					}
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints one contact section.
	 *
	 * @param array $section Section settings.
	 * @param int   $index   Section index (used for unique IDs).
	 * @return void
	 */
	private static function render_section( array $section, $index ) {
		$title    = isset( $section['title'] ) ? trim( (string) $section['title'] ) : '';
		$size     = isset( $section['size'] ) && in_array( $section['size'], Settings::SECTION_SIZES, true ) ? $section['size'] : 'large';
		$lines    = isset( $section['lines'] ) && is_array( $section['lines'] ) ? $section['lines'] : array();
		$title_id = 'lst-dock-section-' . $index;

		if ( '' === $title && empty( $lines ) ) {
			return;
		}
		?>
		<div class="lst-dock__section lst-dock__section--<?php echo esc_attr( $size ); ?>"<?php echo '' !== $title ? ' role="group" aria-labelledby="' . esc_attr( $title_id ) . '"' : ''; ?>>
			<?php if ( '' !== $title ) : ?>
				<p class="lst-dock__title" id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $title ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $lines ) ) : ?>
				<ul class="lst-dock__lines" role="list">
					<?php
					foreach ( $lines as $line ) {
						if ( is_array( $line ) ) {
							self::render_line( $line );
						}
					}
					?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Prints one contact line (link or plain text).
	 *
	 * @param array $line Line settings.
	 * @return void
	 */
	private static function render_line( array $line ) {
		$text = isset( $line['text'] ) ? trim( (string) $line['text'] ) : '';
		$url  = isset( $line['url'] ) ? trim( (string) $line['url'] ) : '';

		if ( '' === $text ) {
			return;
		}

		echo '<li class="lst-dock__line">';

		if ( '' !== $url ) {
			$new_tab = ! empty( $line['new_tab'] );
			printf(
				'<a class="lst-dock__link" href="%1$s"%2$s>%3$s%4$s</a>',
				esc_url( $url, Settings::url_protocols() ),
				$new_tab ? ' target="_blank" rel="noopener"' : '',
				esc_html( $text ),
				$new_tab ? '<span class="lst-dock__sr">' . esc_html__( '(opens in a new tab)', 'listemann-toolkit-addon' ) . '</span>' : ''
			);
		} else {
			echo '<span class="lst-dock__text">' . esc_html( $text ) . '</span>';
		}

		echo '</li>';
	}

	/**
	 * Prints the back-to-top button.
	 *
	 * @param array $to_top Back-to-top settings.
	 * @param int   $offset Scroll offset after which the button is shown.
	 * @return void
	 */
	private static function render_to_top( array $to_top, $offset ) {
		$lines = Settings::label_lines( isset( $to_top['label'] ) ? $to_top['label'] : '' );

		if ( empty( $lines ) ) {
			$lines = array( __( 'Back to top', 'listemann-toolkit-addon' ) );
		}

		$classes = 'lst-dock__top' . ( $offset > 0 ? ' is-hidden' : '' );
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" data-lst-top>
			<button type="button" class="lst-dock__top-btn" data-lst-top-button aria-label="<?php echo esc_attr( implode( ' ', $lines ) ); ?>">
				<span class="lst-dock__icon lst-dock__icon--top" aria-hidden="true"><?php echo self::icon( 'to-top' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized with wp_kses() in icon(). ?></span>
				<span class="lst-dock__top-label" aria-hidden="true"><?php echo implode( '<br>', array_map( 'esc_html', $lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each line is escaped. ?></span>
			</button>
		</div>
		<?php
	}

	/**
	 * Returns an inline SVG icon from assets/img, sanitized with wp_kses().
	 *
	 * Inline SVG (instead of <img>) lets the icon colors follow the settings
	 * through `currentColor` and CSS custom properties.
	 *
	 * @param string $name Icon name (file name without extension).
	 * @return string Sanitized SVG markup or an empty string.
	 */
	private static function icon( $name ) {
		if ( isset( self::$icons[ $name ] ) ) {
			return self::$icons[ $name ];
		}

		$svg  = '';
		$file = LISTEMANN_TOOLKIT_PATH . 'assets/img/' . sanitize_file_name( $name ) . '.svg';

		if ( is_readable( $file ) ) {
			$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin asset.
			$svg      = is_string( $contents ) ? trim( wp_kses( $contents, self::svg_allowed_html() ) ) : '';
		}

		self::$icons[ $name ] = $svg;

		return $svg;
	}

	/**
	 * Allowed SVG elements/attributes for the dock icons.
	 *
	 * @return array
	 */
	private static function svg_allowed_html() {
		$common = array(
			'class'        => true,
			'fill'         => true,
			'stroke'       => true,
			'stroke-width' => true,
			'transform'    => true,
		);

		return array(
			'svg'  => array_merge(
				$common,
				array(
					'xmlns'   => true,
					'width'   => true,
					'height'  => true,
					'viewbox' => true,
				)
			),
			'g'    => $common,
			'path' => array_merge( $common, array( 'd' => true ) ),
			'rect' => array_merge(
				$common,
				array(
					'x'      => true,
					'y'      => true,
					'width'  => true,
					'height' => true,
				)
			),
		);
	}
}
