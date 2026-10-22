<?php
/**
 * Helpers shared by the Listemann Header and Listemann Footer widgets.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Icons_Manager;
use Elementor\Repeater;

defined( 'ABSPATH' ) || exit;

/**
 * Logo image, social links, menu selects and menu columns, and the style
 * control helpers that write CSS custom properties.
 *
 * For classes extending \Elementor\Widget_Base. The class must define the
 * constant MENU_KEY_PREFIX ("menu-", see Header::MENU_KEY_PREFIX).
 */
trait Widget_Helpers {

	/**
	 * Instance keys already used during this request (keeps ids unique when
	 * the same element is rendered more than once on a page). One list per
	 * widget class.
	 *
	 * @var string[]
	 */
	private static $used_keys = array();

	/**
	 * Sanitized inline SVG icons, keyed by network.
	 *
	 * @var string[]
	 */
	private static $icon_cache = array();

	/*
	 * ---------------------------------------------------------------------
	 * Style control helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Adds a px SLIDER that writes a CSS custom property.
	 *
	 * @param string         $id       Control id.
	 * @param string         $label    Escaped label.
	 * @param string         $property CSS custom property name.
	 * @param string         $selector Selector ({{WRAPPER}} or .lst-oc-{{ID}}).
	 * @param int|array|null $sizes    Default size, null for none, or
	 *                                 array( desktop, tablet, mobile ) for a
	 *                                 responsive control (null = no default).
	 * @param array          $extra    Extra control arguments.
	 */
	private function add_size_var_control( $id, $label, $property, $selector, $sizes, array $extra = array() ) {
		$args = array_merge(
			array(
				'label'      => $label,
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 400,
					),
				),
				'selectors'  => array(
					$selector => $property . ': {{SIZE}}{{UNIT}};',
				),
			),
			$extra
		);

		if ( is_array( $sizes ) ) {
			foreach ( array( 'default', 'tablet_default', 'mobile_default' ) as $index => $key ) {
				if ( isset( $sizes[ $index ] ) ) {
					$args[ $key ] = array(
						'unit' => 'px',
						'size' => $sizes[ $index ],
					);
				}
			}

			$this->add_responsive_control( $id, $args );
			return;
		}

		if ( null !== $sizes ) {
			$args['default'] = array(
				'unit' => 'px',
				'size' => $sizes,
			);
		}

		$this->add_control( $id, $args );
	}

	/**
	 * Adds a COLOR control that writes a CSS custom property.
	 *
	 * @param string $id       Control id.
	 * @param string $label    Escaped label.
	 * @param string $property CSS custom property name.
	 * @param string $selector Selector ({{WRAPPER}} or .lst-oc-{{ID}}).
	 * @param string $color    Default color ('' = none).
	 * @param array  $extra    Extra control arguments.
	 */
	private function add_color_var_control( $id, $label, $property, $selector, $color = '', array $extra = array() ) {
		$this->add_control(
			$id,
			array_merge(
				array(
					'label'     => $label,
					'type'      => Controls_Manager::COLOR,
					'default'   => $color,
					'selectors' => array(
						$selector => $property . ': {{VALUE}};',
					),
				),
				$extra
			)
		);
	}

	/**
	 * `fields_options` for a typography group with defaults.
	 *
	 * @param int    $size        Font size (px).
	 * @param string $weight      Font weight.
	 * @param string $transform   Text transform.
	 * @param int    $line_height Line height (px).
	 * @return array
	 */
	private function typography_defaults( $size, $weight, $transform, $line_height ) {
		return array(
			'typography'     => array( 'default' => 'custom' ),
			'font_size'      => array(
				'default' => array(
					'unit' => 'px',
					'size' => $size,
				),
			),
			'font_weight'    => array( 'default' => $weight ),
			'text_transform' => array( 'default' => $transform ),
			'line_height'    => array(
				'default' => array(
					'unit' => 'px',
					'size' => $line_height,
				),
			),
		);
	}

	/**
	 * Style controls of the menu headings in menu columns (menu.css).
	 *
	 * @param string $p   Control id prefix ("oc_").
	 * @param string $sel Element that gets the --lst-menu-* properties and
	 *                    contains the menus.
	 */
	private function add_menu_heading_style_controls( $p, $sel ) {
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => $p . 'heading_typography',
				'selector'       => $sel . ' .lst-menu__item--heading > .lst-menu__link',
				'fields_options' => $this->typography_defaults( 18, '700', 'uppercase', 34 ),
			)
		);

		$this->add_color_var_control( $p . 'heading_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-menu-heading-color', $sel, '#626262' );
		$this->add_color_var_control( $p . 'heading_hover_color', esc_html__( 'Hover color', 'listemann-toolkit-addon' ), '--lst-menu-heading-hover-color', $sel, '#dd191a' );

		$this->add_size_var_control(
			$p . 'group_gap',
			esc_html__( 'Spacing between groups', 'listemann-toolkit-addon' ),
			'--lst-menu-group-gap',
			$sel,
			25,
			array(
				'range' => array(
					'px' => array(
						'min' => 0,
						'max' => 120,
					),
				),
			)
		);
	}

	/**
	 * Style controls of the menu items in menu columns (menu.css).
	 *
	 * @param string $p   Control id prefix ("oc_").
	 * @param string $sel Element that gets the --lst-menu-* properties and
	 *                    contains the menus.
	 */
	private function add_menu_item_style_controls( $p, $sel ) {
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => $p . 'item_typography',
				'selector'       => $sel . ' .lst-menu__item:not(.lst-menu__item--heading) > .lst-menu__link',
				'fields_options' => $this->typography_defaults( 15, '400', 'none', 34 ),
			)
		);

		$this->add_color_var_control( $p . 'item_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-menu-item-color', $sel, '#626262' );
		$this->add_color_var_control( $p . 'item_hover_color', esc_html__( 'Hover color', 'listemann-toolkit-addon' ), '--lst-menu-item-hover-color', $sel, '#dd191a' );
		$this->add_color_var_control( $p . 'item_active_color', esc_html__( 'Active color', 'listemann-toolkit-addon' ), '--lst-menu-item-active-color', $sel, '#dd191a' );

		$nested = $sel . ' .lst-menu__sub .lst-menu__sub > .lst-menu__item:not(.lst-menu__item--heading) > .lst-menu__link';

		$this->add_control(
			$p . 'nested_font_size',
			array(
				'label'      => esc_html__( 'Nested items font size', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 6,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 13,
				),
				'selectors'  => array(
					$nested => 'font-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			$p . 'nested_line_height',
			array(
				'label'      => esc_html__( 'Nested items line height', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 6,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 24,
				),
				'selectors'  => array(
					$nested => 'line-height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_size_var_control(
			$p . 'indent',
			esc_html__( 'Indent per nested level', 'listemann-toolkit-addon' ),
			'--lst-menu-indent',
			$sel,
			25,
			array(
				'range' => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
			)
		);
	}

	/**
	 * Style controls of the red line before the menu items (menu.css).
	 *
	 * @param string $p   Control id prefix ("oc_").
	 * @param string $sel Element that gets the --lst-menu-* properties.
	 */
	private function add_menu_line_style_controls( $p, $sel ) {
		$this->add_size_var_control(
			$p . 'line_width',
			esc_html__( 'Width', 'listemann-toolkit-addon' ),
			'--lst-menu-line-w',
			$sel,
			30,
			array(
				'range' => array(
					'px' => array(
						'min' => 0,
						'max' => 120,
					),
				),
			)
		);

		$this->add_size_var_control(
			$p . 'line_width_nested',
			esc_html__( 'Width (nested levels)', 'listemann-toolkit-addon' ),
			'--lst-menu-line-w-nested',
			$sel,
			20,
			array(
				'range' => array(
					'px' => array(
						'min' => 0,
						'max' => 120,
					),
				),
			)
		);

		$this->add_size_var_control(
			$p . 'line_thickness',
			esc_html__( 'Thickness', 'listemann-toolkit-addon' ),
			'--lst-menu-line-h',
			$sel,
			2,
			array(
				'range' => array(
					'px' => array(
						'min' => 1,
						'max' => 20,
					),
				),
			)
		);

		$this->add_color_var_control( $p . 'line_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-menu-line-color', $sel, '#dd191a' );

		$this->add_size_var_control(
			$p . 'line_gap',
			esc_html__( 'Gap to text', 'listemann-toolkit-addon' ),
			'--lst-menu-line-gap',
			$sel,
			5,
			array(
				'range' => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Render helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether a switcher setting is on.
	 *
	 * @param array  $settings Settings.
	 * @param string $key      Setting key.
	 * @return bool
	 */
	private function is_on( array $settings, $key ) {
		return isset( $settings[ $key ] ) && 'yes' === $settings[ $key ];
	}

	/**
	 * Id suffix unique per element and per request.
	 *
	 * @return string
	 */
	private function get_instance_key() {
		$base = sanitize_html_class( $this->get_id() );
		$key  = $base;
		$i    = 2;

		while ( in_array( $key, self::$used_keys, true ) ) {
			$key = $base . '-' . $i;
			++$i;
		}

		self::$used_keys[] = $key;

		return $key;
	}

	/**
	 * `<img>` for a MEDIA value, with intrinsic size when it is known.
	 *
	 * @param array  $image     MEDIA value (url, id).
	 * @param string $alt       Alternative text.
	 * @param string $css_class CSS class.
	 * @return string Escaped HTML.
	 */
	private function get_image_html( array $image, $alt, $css_class ) {
		$url    = isset( $image['url'] ) ? (string) $image['url'] : '';
		$width  = 0;
		$height = 0;

		if ( ! empty( $image['id'] ) ) {
			$src = wp_get_attachment_image_src( (int) $image['id'], 'full' );

			if ( $src && $src[0] === $url && $src[1] > 1 && $src[2] > 1 ) {
				$width  = (int) $src[1];
				$height = (int) $src[2];
			}
		}

		// Bundled images: file => intrinsic width and height.
		$bundled = array(
			'listemann-logo.svg'       => array( 165, 96 ),
			'listemann-logo-color.svg' => array( 190, 111 ),
		);

		foreach ( $bundled as $file => $size ) {
			if ( LISTEMANN_TOOLKIT_URL . 'assets/img/' . $file === $url ) {
				list( $width, $height ) = $size;
			}
		}

		return sprintf(
			'<img class="%1$s" src="%2$s" alt="%3$s"%4$s decoding="async">',
			esc_attr( $css_class ),
			esc_url( $url ),
			esc_attr( $alt ),
			$width && $height ? sprintf( ' width="%d" height="%d"', $width, $height ) : ''
		);
	}

	/**
	 * Social links list.
	 *
	 * Links that open in a new tab get `rel="noopener"`, and screen readers
	 * hear "(opens in a new tab)" after the network name.
	 *
	 * @param array  $links   Repeater items (network, icon, link, label).
	 * @param string $block   BEM block of the classes ("lst-offcanvas" gives
	 *                        ul.lst-offcanvas__social, a.lst-offcanvas__social-link).
	 * @param bool   $new_tab Open every link in a new tab (otherwise only the
	 *                        links with "Open in new window" ticked).
	 * @return string Escaped HTML ('' when there are no links).
	 */
	private function get_social_links_html( array $links, $block, $new_tab = false ) {
		$labels = $this->get_network_labels();
		$items  = '';

		foreach ( $links as $index => $link ) {
			if ( ! is_array( $link ) || empty( $link['link']['url'] ) || ! is_array( $link['link'] ) ) {
				continue;
			}

			$network = isset( $link['network'] ) && ( isset( $labels[ $link['network'] ] ) || 'custom' === $link['network'] ) ? $link['network'] : 'custom';
			$icon    = 'custom' === $network ? $this->get_custom_icon_html( isset( $link['icon'] ) ? $link['icon'] : array() ) : $this->get_network_icon( $network );
			$label   = isset( $link['label'] ) && is_string( $link['label'] ) ? trim( $link['label'] ) : '';

			if ( '' === $label ) {
				$label = isset( $labels[ $network ] ) ? $labels[ $network ] : __( 'Social link', 'listemann-toolkit-addon' );
			}

			$attr_key = 'social-' . $index;

			$this->add_link_attributes( $attr_key, $link['link'] );

			if ( $new_tab ) {
				$this->add_render_attribute( $attr_key, 'target', '_blank', true );
			}

			$target = $this->get_render_attributes( $attr_key, 'target' );

			if ( is_array( $target ) && in_array( '_blank', $target, true ) ) {
				$this->add_render_attribute( $attr_key, 'rel', 'noopener' );
				$label .= ' ' . __( '(opens in a new tab)', 'listemann-toolkit-addon' );
			}

			$this->add_render_attribute(
				$attr_key,
				array(
					'class'      => array( $block . '__social-link', $block . '__social-link--' . $network ),
					'aria-label' => $label,
				)
			);

			$items .= '<li><a ' . $this->get_render_attribute_string( $attr_key ) . '>' . $icon . '</a></li>';
		}

		return '' === $items ? '' : '<ul class="' . esc_attr( $block . '__social' ) . '">' . $items . '</ul>';
	}

	/**
	 * A menu rendered with the Listemann Menu walker (menu.css) in a
	 * labelled `<nav>`: top-level items are headings, "#" items plain text,
	 * sub-items get the red line.
	 *
	 * @param \WP_Term $menu      Menu.
	 * @param string   $id_prefix Id of the list, prefix of the item ids (unique per page).
	 * @param string   $label     Accessible label ('' = menu name).
	 * @param array    $options {
	 *     Optional.
	 *
	 *     @type int    $depth    Levels shown (0 = all). Default 0.
	 *     @type bool   $headings Top-level items with sub-items are headings. Default true.
	 *     @type string $line     Items with the line: '', 'sub', 'top', 'all'. Default 'sub'.
	 *     @type string $class    Classes of the `<nav>`.
	 * }
	 * @return string HTML ('' when the menu has no items).
	 */
	private function get_menu_nav_html( \WP_Term $menu, $id_prefix, $label, array $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'depth'    => 0,
				'headings' => true,
				'line'     => 'sub',
				'class'    => 'lst-menu lst-menu--vertical lst-menu--headings lst-menu--line-sub',
			)
		);

		$depth = max( 0, (int) $options['depth'] );
		$html  = wp_nav_menu(
			array(
				'menu'           => $menu->term_id,
				'theme_location' => '',
				'container'      => '',
				'menu_id'        => $id_prefix,
				'menu_class'     => 'lst-menu__list',
				'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
				'item_spacing'   => 'discard',
				'depth'          => $depth,
				'echo'           => false,
				'fallback_cb'    => '__return_empty_string',
				'walker'         => new Menu_Walker(
					array(
						'headings'     => (bool) $options['headings'],
						'hash_as_text' => true,
						'line'         => $options['line'],
						'id_prefix'    => $id_prefix,
						'depth'        => $depth,
					)
				),
			)
		);

		if ( empty( $html ) ) {
			return '';
		}

		if ( '' === $label ) {
			$label = wp_specialchars_decode( $menu->name, ENT_QUOTES );
		}

		return sprintf(
			'<nav class="%1$s" aria-label="%2$s">%3$s</nav>',
			esc_attr( $options['class'] ),
			esc_attr( $label ),
			$html
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Data helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Extracts the ID from an option key ("menu-15") or a bare ID ("15").
	 *
	 * @param mixed  $value  Saved setting.
	 * @param string $prefix Option key prefix.
	 * @return int ID, 0 when the value is neither.
	 */
	private function get_id_from_key( $value, $prefix ) {
		if ( ! is_scalar( $value ) || ! preg_match( '/^(?:' . preg_quote( $prefix, '/' ) . ')?(\d+)$/', (string) $value, $matches ) ) {
			return 0;
		}

		return absint( $matches[1] );
	}

	/**
	 * Options for the menu controls: "menu-{term ID}" => menu name, sorted by
	 * name (see MENU_KEY_PREFIX).
	 *
	 * @return string[]
	 */
	private function get_menu_options() {
		$options = array();

		foreach ( wp_get_nav_menus() as $menu ) {
			$options[ self::MENU_KEY_PREFIX . $menu->term_id ] = esc_html( $menu->name );
		}

		return $options;
	}

	/**
	 * Resolve a saved menu setting: an option key ("menu-15"), a bare term ID
	 * (older versions) or a slug (imported data).
	 *
	 * Deliberately does not fall back to another menu: wp_nav_menu() would
	 * silently render the first non-empty menu when the requested one is
	 * missing.
	 *
	 * @param mixed $value Saved setting.
	 * @return \WP_Term|false
	 */
	private function get_menu( $value ) {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return false;
		}

		$value = (string) $value;
		$id    = $this->get_id_from_key( $value, self::MENU_KEY_PREFIX );
		$menu  = $id ? wp_get_nav_menu_object( $id ) : false;

		if ( ! $menu && ! preg_match( '/^\d+$/', $value ) ) {
			$menu = get_term_by( 'slug', $value, 'nav_menu' );
		}

		return $menu instanceof \WP_Term ? $menu : false;
	}

	/**
	 * Labels of the networks with a bundled icon (assets/img/social-{key}.svg).
	 *
	 * @return string[]
	 */
	private function get_network_labels() {
		return array(
			'linkedin'  => 'LinkedIn',
			'facebook'  => 'Facebook',
			'youtube'   => 'YouTube',
			'instagram' => 'Instagram',
			'xing'      => 'XING',
			'x'         => 'X',
		);
	}

	/**
	 * Fields of the social links repeater: network, custom icon, link and
	 * accessible label.
	 *
	 * @return array Repeater controls.
	 */
	private function get_social_repeater_fields() {
		$social = new Repeater();

		$social->add_control(
			'network',
			array(
				'label'   => esc_html__( 'Network', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $this->get_network_labels() + array( 'custom' => esc_html__( 'Custom icon', 'listemann-toolkit-addon' ) ),
				'default' => 'linkedin',
			)
		);

		$social->add_control(
			'icon',
			array(
				'label'     => esc_html__( 'Icon', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::ICONS,
				'default'   => array(
					'value'   => 'fas fa-link',
					'library' => 'fa-solid',
				),
				'condition' => array(
					'network' => 'custom',
				),
			)
		);

		$social->add_control(
			'link',
			array(
				'label'       => esc_html__( 'Link', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => 'https://',
				'default'     => array(
					'url' => '',
				),
				'dynamic'     => array(
					'active' => true,
				),
			)
		);

		$social->add_control(
			'label',
			array(
				'label'       => esc_html__( 'Accessible label', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => esc_html__( 'Network name', 'listemann-toolkit-addon' ),
			)
		);

		return $social->get_controls();
	}

	/**
	 * Title of a social links repeater row in the editor (the network name).
	 *
	 * @return string Underscore template.
	 */
	private function get_social_title_field() {
		return '<# var lstNetworks = ' . wp_json_encode( (object) ( $this->get_network_labels() + array( 'custom' => __( 'Custom icon', 'listemann-toolkit-addon' ) ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '; #>{{{ lstNetworks[ network ] || network }}}';
	}

	/**
	 * Title of a menu column repeater row in the editor (the menu name).
	 *
	 * @param string[] $menus Menu options, see get_menu_options().
	 * @return string Underscore template.
	 */
	private function get_menu_title_field( array $menus ) {
		return '<# var lstMenus = ' . wp_json_encode( (object) $menus, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '; #>{{{ lstMenus[ menu ] || lstMenus[ "' . self::MENU_KEY_PREFIX . '" + menu ] || "' . esc_js( __( 'Menu column', 'listemann-toolkit-addon' ) ) . '" }}}';
	}

	/**
	 * Default social links: the three Listemann profiles.
	 *
	 * @return array[] Repeater items.
	 */
	private function get_default_social_links() {
		return array(
			array(
				'network' => 'linkedin',
				'link'    => array( 'url' => 'https://www.linkedin.com/company/listemann-ag/' ),
			),
			array(
				'network' => 'facebook',
				'link'    => array( 'url' => 'https://www.facebook.com/Listemann/' ),
			),
			array(
				'network' => 'youtube',
				'link'    => array( 'url' => 'https://www.youtube.com/@listemann-perfectingmaterials' ),
			),
		);
	}

	/**
	 * Bundled network icon as inline SVG (currentColor).
	 *
	 * @param string $network Network key.
	 * @return string Sanitized SVG ('' when unknown).
	 */
	private function get_network_icon( $network ) {
		if ( ! array_key_exists( $network, $this->get_network_labels() ) ) {
			return '';
		}

		if ( ! isset( self::$icon_cache[ $network ] ) ) {
			$file = LISTEMANN_TOOLKIT_PATH . 'assets/img/social-' . $network . '.svg';
			$svg  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.

			self::$icon_cache[ $network ] = wp_kses( $svg, $this->get_svg_kses() );
		}

		return self::$icon_cache[ $network ];
	}

	/**
	 * Custom icon (ICONS control) markup.
	 *
	 * @param mixed $icon ICONS value.
	 * @return string
	 */
	private function get_custom_icon_html( $icon ) {
		if ( ! is_array( $icon ) || empty( $icon['value'] ) ) {
			return '';
		}

		ob_start();
		Icons_Manager::render_icon( $icon, array( 'aria-hidden' => 'true' ) );

		return (string) ob_get_clean();
	}

	/**
	 * Allowed SVG markup for the bundled icons.
	 *
	 * @return array
	 */
	private function get_svg_kses() {
		return array(
			'svg'  => array(
				'xmlns'       => true,
				'width'       => true,
				'height'      => true,
				'viewbox'     => true,
				'aria-hidden' => true,
				'focusable'   => true,
				'class'       => true,
				'fill'        => true,
			),
			'g'    => array(
				'fill'      => true,
				'transform' => true,
			),
			'path' => array(
				'd'         => true,
				'fill'      => true,
				'transform' => true,
				'fill-rule' => true,
				'clip-rule' => true,
			),
		);
	}
}
