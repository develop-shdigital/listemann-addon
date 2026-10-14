<?php
/**
 * Nav menu walker for the desktop (mega) menu of the "Listemann Header" widget.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit\Widgets;

use Listemann_Toolkit\Mega_Menu_Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the main menu of the header as a dropdown / mega menu.
 *
 * Structure (BEM classes next to the core classes such as
 * `current-menu-item` and `menu-item-has-children`):
 *
 *     li.lst-header__item--top
 *       a.lst-header__link--top            (link) + button.lst-header__toggle (chevron)
 *       | button.lst-header__link--top     (items without a URL or with "#": the whole label opens the dropdown)
 *       ul.lst-header__dropdown            (the white panel, a grid in mega mode)
 *         li.lst-header__item--group       (2nd level item with children: a column heading)
 *           (a|span).lst-header__group-title
 *           ul.lst-header__sub > li > (a|span).lst-header__sublink
 *         li.lst-header__item--sub         (2nd level item without children)
 *           (a|span).lst-header__sublink
 *
 * - Every toggle is a real `<button>` with `aria-expanded` / `aria-controls`.
 * - Items whose URL is empty or "#" never render as fake links: top-level
 *   items with children become the toggle button, all others a `<span>`.
 * - A top-level item with a "Mega menu columns" value of 2 or more (see
 *   {@see Mega_Menu_Fields}) gets `lst-header__item--mega` and the custom
 *   property `--lst-hdr-mega-cols`.
 * - Ids are prefixed per widget instance, so the same menu can be used by
 *   several headers on one page.
 *
 * All core filters of {@see \Walker_Nav_Menu::start_el()} and
 * {@see \Walker_Nav_Menu::start_lvl()} are applied.
 */
class Header_Nav_Walker extends \Walker_Nav_Menu {

	/**
	 * Inline chevron icon (from the original theme, recoloured via currentColor).
	 */
	const CHEVRON = '<svg class="lst-header__chevron" width="10" height="7" viewBox="0 0 11.171 7.441" aria-hidden="true" focusable="false"><path d="M1.819 0 0 1.855l5.586 5.586 5.585-5.586L9.352 0 5.586 3.8Z" fill="currentColor"/></svg>';

	/**
	 * Attributes that only make sense on an `<a>` element.
	 *
	 * @var string[]
	 */
	const LINK_ONLY_ATTRIBUTES = array( 'href', 'target', 'rel', 'download', 'hreflang', 'ping', 'referrerpolicy', 'type', 'aria-current' );

	/**
	 * Prefix of all ids printed by this walker (unique per widget instance).
	 *
	 * @var string
	 */
	private $id_prefix = 'lst-header';

	/**
	 * Id of the dropdown of the top-level item that is being rendered.
	 *
	 * @var string
	 */
	private $current_dropdown_id = '';

	/**
	 * Privacy policy URL, cached like the core walker does.
	 *
	 * @var string
	 */
	private $privacy_url = '';

	/**
	 * Constructor.
	 *
	 * @param array $options {
	 *     Optional. Walker options.
	 *
	 *     @type string $id_prefix Prefix of the ids printed by the walker. Default 'lst-header'.
	 * }
	 */
	public function __construct( array $options = array() ) {
		if ( method_exists( '\Walker_Nav_Menu', '__construct' ) ) {
			parent::__construct();
		}

		$prefix = isset( $options['id_prefix'] ) ? sanitize_html_class( (string) $options['id_prefix'] ) : '';

		$this->id_prefix   = '' !== $prefix ? $prefix : 'lst-header';
		$this->privacy_url = (string) get_privacy_policy_url();
	}

	/**
	 * Starts a sub-menu list.
	 *
	 * The first level is the dropdown panel, deeper levels are plain lists
	 * inside it.
	 *
	 * @param string         $output Used to append additional content (passed by reference).
	 * @param int            $depth  Depth of the parent item.
	 * @param \stdClass|null $args   An object of wp_nav_menu() arguments.
	 */
	public function start_lvl( &$output, $depth = 0, $args = null ) {
		$depth   = (int) $depth;
		$classes = array( 'sub-menu', 'lst-header__sub', 'lst-header__sub--depth-' . ( $depth + 1 ) );

		if ( 0 === $depth ) {
			$classes[] = 'lst-header__dropdown';
		}

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$class_names = implode( ' ', (array) apply_filters( 'nav_menu_submenu_css_class', $classes, $args, $depth ) );

		$atts = array(
			'id'    => 0 === $depth ? $this->current_dropdown_id : '',
			'class' => $class_names,
		);

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$atts = apply_filters( 'nav_menu_submenu_attributes', $atts, $args, $depth );

		$output .= '<ul' . $this->build_atts( $atts ) . '>';
	}

	/**
	 * Ends a sub-menu list.
	 *
	 * @param string         $output Used to append additional content (passed by reference).
	 * @param int            $depth  Depth of the parent item.
	 * @param \stdClass|null $args   An object of wp_nav_menu() arguments.
	 */
	public function end_lvl( &$output, $depth = 0, $args = null ) {
		$output .= '</ul>';
	}

	/**
	 * Starts an element: the `<li>`, its link (or button / text) and, for
	 * top-level items with children, the dropdown toggle.
	 *
	 * @param string         $output            Used to append additional content (passed by reference).
	 * @param \WP_Post       $data_object       Menu item data object.
	 * @param int            $depth             Depth of the menu item.
	 * @param \stdClass|null $args              An object of wp_nav_menu() arguments.
	 * @param int            $current_object_id Optional. ID of the current menu item. Default 0.
	 */
	public function start_el( &$output, $data_object, $depth = 0, $args = null, $current_object_id = 0 ) {
		$menu_item = $data_object;
		$depth     = (int) $depth;

		// Children are only printed when the depth limit allows it (see Walker::display_element()).
		$max_depth = isset( $args->depth ) ? max( 0, (int) $args->depth ) : 0;
		$has_sub   = $this->has_children && ( 0 === $max_depth || $max_depth > $depth + 1 );
		$is_top    = 0 === $depth;
		$is_group  = 1 === $depth && $has_sub;
		$columns   = 0;

		$classes   = empty( $menu_item->classes ) ? array() : (array) $menu_item->classes;
		$classes[] = 'menu-item-' . $menu_item->ID;
		$classes[] = 'lst-header__item';

		if ( $is_top ) {
			$classes[] = 'lst-header__item--top';
		} elseif ( $is_group ) {
			$classes[] = 'lst-header__item--group';
		} else {
			$classes[] = 'lst-header__item--sub';
		}

		if ( $has_sub ) {
			$classes[] = 'lst-header__item--has-sub';
		}

		if ( $is_top && $has_sub && is_numeric( $menu_item->ID ) ) {
			$columns = Mega_Menu_Fields::get_columns( (int) $menu_item->ID );

			if ( $columns >= 2 ) {
				$classes[] = 'lst-header__item--mega';
			}
		}

		if ( $is_top ) {
			$this->current_dropdown_id = $has_sub ? $this->id_prefix . '-sub-' . sanitize_html_class( (string) $menu_item->ID ) : '';
		}

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$args = apply_filters( 'nav_menu_item_args', $args, $menu_item, $depth );

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$class_names = implode( ' ', (array) apply_filters( 'nav_menu_css_class', array_filter( $classes ), $menu_item, $args, $depth ) );

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$id = apply_filters( 'nav_menu_item_id', $this->id_prefix . '-item-' . $menu_item->ID, $menu_item, $args, $depth );

		$li_atts = array(
			'id'    => ! empty( $id ) ? $id : '',
			'class' => ! empty( $class_names ) ? $class_names : '',
			'style' => $columns >= 2 ? '--lst-hdr-mega-cols:' . $columns : '',
		);

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$li_atts = apply_filters( 'nav_menu_item_attributes', $li_atts, $menu_item, $args, $depth );

		$output .= '<li' . $this->build_atts( $li_atts ) . '>';

		/** This filter is documented in wp-includes/post-template.php */
		$title = apply_filters( 'the_title', $menu_item->title, $menu_item->ID );

		// Save filtered value before filtering again.
		$the_title_filtered = $title;

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$title = apply_filters( 'nav_menu_item_title', $title, $menu_item, $args, $depth );

		$atts           = array();
		$atts['target'] = ! empty( $menu_item->target ) ? $menu_item->target : '';
		$atts['rel']    = ! empty( $menu_item->xfn ) ? $menu_item->xfn : '';

		if ( ! empty( $menu_item->url ) ) {
			if ( $this->privacy_url === $menu_item->url ) {
				$atts['rel'] = empty( $atts['rel'] ) ? 'privacy-policy' : $atts['rel'] . ' privacy-policy';
			}

			$atts['href'] = $menu_item->url;
		} else {
			$atts['href'] = '';
		}

		$atts['aria-current'] = ! empty( $menu_item->current ) ? 'page' : '';

		// Add title attribute only if it does not match the link text (before or after filtering).
		if ( ! empty( $menu_item->attr_title )
			&& trim( strtolower( $menu_item->attr_title ) ) !== trim( strtolower( $menu_item->title ) )
			&& trim( strtolower( $menu_item->attr_title ) ) !== trim( strtolower( $the_title_filtered ) )
			&& trim( strtolower( $menu_item->attr_title ) ) !== trim( strtolower( $title ) )
		) {
			$atts['title'] = $menu_item->attr_title;
		} else {
			$atts['title'] = '';
		}

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$atts = (array) apply_filters( 'nav_menu_link_attributes', $atts, $menu_item, $args, $depth );

		$href    = isset( $atts['href'] ) && is_scalar( $atts['href'] ) ? trim( (string) $atts['href'] ) : '';
		$is_text = '' === $href || '#' === $href;

		if ( $is_top ) {
			$own_class = 'lst-header__link lst-header__link--top';
		} elseif ( $is_group ) {
			$own_class = 'lst-header__group-title';
		} else {
			$own_class = 'lst-header__sublink';
		}

		// Keep classes added by filters, add ours in front.
		$extra_classes = isset( $atts['class'] ) && is_string( $atts['class'] ) ? trim( $atts['class'] ) : '';
		$atts['class'] = trim( $own_class . ' ' . $extra_classes );

		$before      = isset( $args->before ) ? $args->before : '';
		$after       = isset( $args->after ) ? $args->after : '';
		$link_before = isset( $args->link_before ) ? $args->link_before : '';
		$link_after  = isset( $args->link_after ) ? $args->link_after : '';

		// The title is not escaped, exactly like in core: menu item titles may contain HTML.
		$label = '<span class="lst-header__label">' . $link_before . $title . $link_after . '</span>';

		if ( $is_top && $has_sub && $is_text ) {
			// No destination: the whole item is the dropdown toggle.
			foreach ( self::LINK_ONLY_ATTRIBUTES as $attribute ) {
				unset( $atts[ $attribute ] );
			}

			$atts['type']          = 'button';
			$atts['class']        .= ' lst-header__toggle-label';
			$atts['aria-expanded'] = 'false';
			$atts['aria-controls'] = $this->current_dropdown_id;

			$item_output = $before . '<button' . $this->build_atts( $atts ) . '>' . $label . self::CHEVRON . '</button>' . $after;
		} else {
			$tag = $is_text ? 'span' : 'a';

			if ( $is_text ) {
				foreach ( self::LINK_ONLY_ATTRIBUTES as $attribute ) {
					unset( $atts[ $attribute ] );
				}

				$atts['class'] .= ' lst-header__text';
			}

			$item_output = $before . '<' . $tag . $this->build_atts( $atts ) . '>' . $label . '</' . $tag . '>' . $after;

			if ( $is_top && $has_sub ) {
				$item_output .= sprintf(
					'<button type="button" class="lst-header__toggle" aria-expanded="false" aria-controls="%1$s"><span class="lst-header__sr">%2$s</span>%3$s</button>',
					esc_attr( $this->current_dropdown_id ),
					esc_html(
						sprintf(
							/* translators: %s: Menu item title. */
							__( '%s submenu', 'listemann-toolkit-addon' ),
							trim( wp_strip_all_tags( $title ) )
						)
					),
					self::CHEVRON
				);
			}
		}

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$output .= apply_filters( 'walker_nav_menu_start_el', $item_output, $menu_item, $depth, $args );
	}

	/**
	 * Ends an element.
	 *
	 * @param string         $output      Used to append additional content (passed by reference).
	 * @param \WP_Post       $data_object Menu item data object. Not used.
	 * @param int            $depth       Depth of page. Not used.
	 * @param \stdClass|null $args        An object of wp_nav_menu() arguments.
	 */
	public function end_el( &$output, $data_object, $depth = 0, $args = null ) {
		$output .= '</li>';
	}
}
