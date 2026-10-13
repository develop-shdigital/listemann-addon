<?php
/**
 * Plugin settings: option name, defaults, accessors and sanitization.
 *
 * This class is the single source of truth for the global (non-Elementor)
 * features of the plugin. The admin settings page writes the option through
 * {@see Settings::sanitize()}, the front-end features read it through
 * {@see Settings::get()}.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit;

defined( 'ABSPATH' ) || exit;

/**
 * Settings store for the side dock, back-to-top button and branded scrollbar.
 *
 * Option structure (`listemann_toolkit_settings`):
 *
 *     array(
 *         'contact'    => array(
 *             'enabled'  => bool,
 *             'label'    => string,  // Accessible name of the contact button.
 *             'sections' => array(
 *                 array(
 *                     'title' => string,
 *                     'size'  => 'large'|'small',
 *                     'lines' => array(
 *                         array( 'text' => string, 'url' => string, 'new_tab' => bool ),
 *                     ),
 *                 ),
 *             ),
 *         ),
 *         'to_top'     => array( 'enabled' => bool, 'label' => string, 'offset' => int ),
 *         'appearance' => array(
 *             'brand_color'  => '#rrggbb',
 *             'to_top_color' => '#rrggbb',
 *             'arrow_color'  => '#rrggbb',
 *             'text_color'   => '#rrggbb',
 *         ),
 *         'scrollbar'  => array( 'enabled' => bool, 'color' => '#rrggbb'|'', 'width' => int ),
 *     )
 */
final class Settings {

	/**
	 * Name of the option holding all settings.
	 *
	 * @var string
	 */
	const OPTION = 'listemann_toolkit_settings';

	/**
	 * Maximum number of contact sections that are stored.
	 *
	 * @var int
	 */
	const MAX_SECTIONS = 20;

	/**
	 * Maximum number of lines per contact section that are stored.
	 *
	 * @var int
	 */
	const MAX_LINES = 30;

	/**
	 * Allowed text sizes of a contact section.
	 *
	 * @var string[]
	 */
	const SECTION_SIZES = array( 'large', 'small' );

	/**
	 * Default settings (the content of the original listemann.com side box).
	 *
	 * @return array
	 */
	public static function defaults() {
		$defaults = array(
			'contact'    => array(
				'enabled'  => true,
				'label'    => 'Kontakt',
				'sections' => array(
					array(
						'title' => 'Telefon',
						'size'  => 'large',
						'lines' => array(
							array(
								'text'    => 'FL: +423 375 90 10',
								'url'     => 'tel:+4233759010',
								'new_tab' => false,
							),
							array(
								'text'    => 'CH: +41 52 245 18 50',
								'url'     => 'tel:+41522451850',
								'new_tab' => false,
							),
							array(
								'text'    => 'PL: +48 12 6534 230',
								'url'     => 'tel:+48126534230',
								'new_tab' => false,
							),
						),
					),
					array(
						'title' => 'Anfahrt',
						'size'  => 'small',
						'lines' => array(
							array(
								'text'    => 'FL: Ober Au 38, LI-9487 Bendern',
								'url'     => 'https://maps.app.goo.gl/s9WtF3FWkJATFpgJ6',
								'new_tab' => false,
							),
							array(
								'text'    => 'CH: Sulzer Allee 25, 8404 Winterthur',
								'url'     => 'https://maps.app.goo.gl/u4u5vxS3gY9enZgR9',
								'new_tab' => false,
							),
							array(
								'text'    => 'PL: Biskupińska 23, 30-732 Kraków',
								'url'     => 'https://maps.app.goo.gl/HY2Aj67Ec8WeUw358',
								'new_tab' => false,
							),
						),
					),
				),
			),
			'to_top'     => array(
				'enabled' => true,
				'label'   => "Zurück\nnach\noben",
				'offset'  => 0,
			),
			'appearance' => array(
				'brand_color'  => '#dd191a',
				'to_top_color' => '#a8a8a8',
				'arrow_color'  => '#fafafa',
				'text_color'   => '#ffffff',
			),
			'scrollbar'  => array(
				'enabled' => true,
				'color'   => '',
				'width'   => 5,
			),
		);

		/**
		 * Filters the default settings of the Listemann Toolkit global features.
		 *
		 * @param array $defaults Default settings.
		 */
		$filtered = apply_filters( 'listemann_toolkit_default_settings', $defaults );

		return is_array( $filtered ) ? $filtered : $defaults;
	}

	/**
	 * Returns the current settings merged with the defaults.
	 *
	 * Each group is merged key by key; lists (contact sections) are taken
	 * as stored, so removed sections do not come back from the defaults.
	 *
	 * @param string|null $group Optional group key (contact, to_top, appearance, scrollbar).
	 * @return array All settings, or the settings of one group (empty array for an unknown group).
	 */
	public static function get( $group = null ) {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		$settings = $defaults;

		if ( is_array( $stored ) ) {
			foreach ( $defaults as $key => $values ) {
				if ( isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) {
					$settings[ $key ] = array_merge( $values, array_intersect_key( $stored[ $key ], $values ) );
				}
			}
		}

		if ( ! is_array( $settings['contact']['sections'] ) ) {
			$settings['contact']['sections'] = array();
		}

		if ( null === $group ) {
			return $settings;
		}

		return isset( $settings[ $group ] ) ? $settings[ $group ] : array();
	}

	/**
	 * URL protocols allowed for contact links.
	 *
	 * @return string[]
	 */
	public static function url_protocols() {
		return array( 'http', 'https', 'tel', 'mailto' );
	}

	/**
	 * Sanitize callback of the option (used by register_setting()).
	 *
	 * The input is expected to be a complete submission of the settings form:
	 * a missing checkbox means "off" and a missing section list means "no
	 * sections". A group that is missing entirely keeps its current value, so
	 * programmatic partial updates do not wipe other groups. The callback is
	 * idempotent (WordPress may run it twice when the option is first added).
	 *
	 * @param mixed $input Raw option value.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( $input ) {
		$current  = self::get();
		$defaults = self::defaults();

		if ( ! is_array( $input ) ) {
			return $current;
		}

		$output = array();

		// Contact box.
		if ( isset( $input['contact'] ) && is_array( $input['contact'] ) ) {
			$contact           = $input['contact'];
			$output['contact'] = array(
				'enabled'  => self::bool_value( $contact, 'enabled' ),
				'label'    => self::text_value( $contact, 'label', $defaults['contact']['label'] ),
				'sections' => self::sanitize_sections( isset( $contact['sections'] ) ? $contact['sections'] : array() ),
			);
		} else {
			$output['contact'] = $current['contact'];
		}

		// Back to top.
		if ( isset( $input['to_top'] ) && is_array( $input['to_top'] ) ) {
			$to_top           = $input['to_top'];
			$output['to_top'] = array(
				'enabled' => self::bool_value( $to_top, 'enabled' ),
				'label'   => self::sanitize_multiline_label( self::scalar_value( $to_top, 'label' ), $defaults['to_top']['label'] ),
				'offset'  => self::int_value( $to_top, 'offset', $defaults['to_top']['offset'], 0, 100000 ),
			);
		} else {
			$output['to_top'] = $current['to_top'];
		}

		// Appearance.
		if ( isset( $input['appearance'] ) && is_array( $input['appearance'] ) ) {
			$output['appearance'] = array();
			foreach ( $defaults['appearance'] as $key => $default ) {
				$output['appearance'][ $key ] = self::color( self::scalar_value( $input['appearance'], $key ), $default );
			}
		} else {
			$output['appearance'] = $current['appearance'];
		}

		// Scrollbar.
		if ( isset( $input['scrollbar'] ) && is_array( $input['scrollbar'] ) ) {
			$scrollbar           = $input['scrollbar'];
			$output['scrollbar'] = array(
				'enabled' => self::bool_value( $scrollbar, 'enabled' ),
				'color'   => self::color( self::scalar_value( $scrollbar, 'color' ), '' ),
				'width'   => self::int_value( $scrollbar, 'width', $defaults['scrollbar']['width'], 2, 24 ),
			);
		} else {
			$output['scrollbar'] = $current['scrollbar'];
		}

		return $output;
	}

	/**
	 * Sanitizes the repeatable contact sections (re-indexed, empty rows dropped).
	 *
	 * @param mixed $sections Raw sections.
	 * @return array
	 */
	public static function sanitize_sections( $sections ) {
		$clean = array();

		if ( ! is_array( $sections ) ) {
			return $clean;
		}

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$title = sanitize_text_field( self::scalar_value( $section, 'title' ) );
			$size  = self::scalar_value( $section, 'size' );
			$lines = self::sanitize_lines( isset( $section['lines'] ) ? $section['lines'] : array() );

			if ( '' === $title && empty( $lines ) ) {
				continue;
			}

			$clean[] = array(
				'title' => $title,
				'size'  => in_array( $size, self::SECTION_SIZES, true ) ? $size : 'large',
				'lines' => $lines,
			);

			if ( count( $clean ) >= self::MAX_SECTIONS ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Sanitizes the lines of one contact section (re-indexed, empty rows dropped).
	 *
	 * @param mixed $lines Raw lines.
	 * @return array
	 */
	public static function sanitize_lines( $lines ) {
		$clean = array();

		if ( ! is_array( $lines ) ) {
			return $clean;
		}

		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$text = sanitize_text_field( self::scalar_value( $line, 'text' ) );
			$url  = self::sanitize_link( self::scalar_value( $line, 'url' ) );

			if ( '' === $text && '' === $url ) {
				continue;
			}

			if ( '' === $text ) {
				// A link needs visible text: fall back to the address itself.
				$text = sanitize_text_field( preg_replace( '#^(?:tel:|mailto:|https?://)#i', '', $url ) );
			}

			$clean[] = array(
				'text'    => $text,
				'url'     => $url,
				'new_tab' => self::bool_value( $line, 'new_tab' ),
			);

			if ( count( $clean ) >= self::MAX_LINES ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Sanitizes a contact link.
	 *
	 * Bare phone numbers become `tel:` links and bare e-mail addresses become
	 * `mailto:` links. Visual separators are removed from `tel:` links
	 * (`tel:+423 375 90 10` becomes `tel:+4233759010`). Only http, https, tel
	 * and mailto (and relative URLs) are kept.
	 *
	 * @param string $url Raw URL.
	 * @return string Sanitized URL or an empty string.
	 */
	public static function sanitize_link( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		if ( preg_match( '/^\+?[0-9][0-9\s().\/-]{4,}$/', $url ) ) {
			$url = 'tel:' . $url;
		} elseif ( is_email( $url ) ) {
			$url = 'mailto:' . $url;
		}

		if ( 0 === stripos( $url, 'tel:' ) ) {
			$url = 'tel:' . preg_replace( '/[\s().\/-]+/', '', substr( $url, 4 ) );
		}

		return esc_url_raw( $url, self::url_protocols() );
	}

	/**
	 * Sanitizes a multi-line label (one visual line per text line).
	 *
	 * @param string $value   Raw value.
	 * @param string $default Fallback when the result is empty.
	 * @return string Lines separated by "\n".
	 */
	public static function sanitize_multiline_label( $value, $default ) {
		$value = sanitize_textarea_field( (string) $value );
		$lines = preg_split( '/\R/u', $value );
		$lines = is_array( $lines ) ? array_filter( array_map( 'trim', $lines ), 'strlen' ) : array();
		$label = implode( "\n", $lines );

		return '' === $label ? (string) $default : $label;
	}

	/**
	 * Splits a multi-line label into its non-empty lines.
	 *
	 * @param string $label Label as stored.
	 * @return string[]
	 */
	public static function label_lines( $label ) {
		$lines = preg_split( '/\R/u', (string) $label );

		return is_array( $lines ) ? array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) ) : array();
	}

	/**
	 * Validates a hex color.
	 *
	 * @param mixed  $value   Raw value.
	 * @param string $default Fallback for empty or invalid values.
	 * @return string Lower-case hex color or the fallback.
	 */
	public static function color( $value, $default ) {
		$color = is_string( $value ) ? sanitize_hex_color( trim( $value ) ) : null;

		return $color ? strtolower( $color ) : $default;
	}

	/**
	 * Reads a boolean (checkbox) value; a missing key means false.
	 *
	 * @param array  $values Raw values.
	 * @param string $key    Key.
	 * @return bool
	 */
	private static function bool_value( array $values, $key ) {
		if ( ! isset( $values[ $key ] ) || ! is_scalar( $values[ $key ] ) ) {
			return false;
		}

		return (bool) filter_var( $values[ $key ], FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Reads a single-line text value.
	 *
	 * @param array  $values  Raw values.
	 * @param string $key     Key.
	 * @param string $default Fallback when the value is empty.
	 * @return string
	 */
	private static function text_value( array $values, $key, $default ) {
		$text = sanitize_text_field( self::scalar_value( $values, $key ) );

		return '' === $text ? (string) $default : $text;
	}

	/**
	 * Reads an integer value clamped to a range.
	 *
	 * @param array  $values  Raw values.
	 * @param string $key     Key.
	 * @param int    $default Fallback when the value is missing or not numeric.
	 * @param int    $min     Minimum.
	 * @param int    $max     Maximum.
	 * @return int
	 */
	private static function int_value( array $values, $key, $default, $min, $max ) {
		$raw = trim( self::scalar_value( $values, $key ) );

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return (int) $default;
		}

		return max( (int) $min, min( (int) $max, (int) round( (float) $raw ) ) );
	}

	/**
	 * Reads a scalar value as string (arrays and objects become '').
	 *
	 * @param array  $values Raw values.
	 * @param string $key    Key.
	 * @return string
	 */
	private static function scalar_value( array $values, $key ) {
		if ( ! isset( $values[ $key ] ) || ! is_scalar( $values[ $key ] ) ) {
			return '';
		}

		return is_bool( $values[ $key ] ) ? ( $values[ $key ] ? '1' : '' ) : (string) $values[ $key ];
	}
}
