<?php
/**
 * Nav menu walker used by the "Listemann Menu" Elementor widget.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Walker that renders WordPress nav menus with BEM-style classes.
 *
 * Differences to the core walker:
 * - every `<li>` gets `lst-menu__item`, `lst-menu__item--top|sub`,
 *   `lst-menu__item--depth-N` (plus `--has-sub` / `--heading` where they
 *   apply) next to the core classes (`current-menu-item`,
 *   `menu-item-has-children`, ...), so the core "current" classes keep
 *   working for active styling;
 * - links get `lst-menu__link` (and `lst-menu__link--line` when the
 *   decorative line is drawn in front of them), the title is wrapped in
 *   `span.lst-menu__label`;
 * - items whose final URL is empty or "#" can be rendered as a plain
 *   `<span class="lst-menu__link lst-menu__text">` instead of a fake link;
 * - `<li>` ids are prefixed with a per-widget id so the same menu can be
 *   shown several times on a page without duplicate ids;
 * - sub-menus get `lst-menu__sub lst-menu__sub--depth-N`.
 *
 * All core filters of {@see \Walker_Nav_Menu::start_el()} and
 * {@see \Walker_Nav_Menu::start_lvl()} are still applied.
 */
class Menu_Walker extends \Walker_Nav_Menu {

	/**
	 * Attributes that only make sense on an `<a>` element and are removed
	 * when an item is rendered as plain text.
	 *
	 * @var string[]
	 */
	const LINK_ONLY_ATTRIBUTES = array( 'href', 'target', 'rel', 'download', 'hreflang', 'ping', 'referrerpolicy', 'type', 'aria-current' );

	/**
	 * Whether top-level items with visible children are rendered as headings.
	 *
	 * @var bool
	 */
	private $headings = true;

	/**
	 * Whether items with an empty or "#" URL are rendered as plain text.
	 *
	 * @var bool
	 */
	private $hash_as_text = true;

	/**
	 * Which items get the decorative line: '', 'sub', 'top' or 'all'.
	 *
	 * @var string
	 */
	private $line = 'sub';

	/**
	 * Prefix of the `<li>` ids (unique per widget instance).
	 *
	 * @var string
	 */
	private $id_prefix = '';

	/**
	 * Maximum depth that is displayed (0 = all levels). Only used when the
	 * wp_nav_menu() arguments do not carry a depth.
	 *
	 * @var int
	 */
	private $max_depth = 0;

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
	 *     @type bool   $headings     Top-level items with visible children are headings. Default true.
	 *     @type bool   $hash_as_text Render empty/"#" URLs as plain text. Default true.
	 *     @type string $line         Items that get the line: '', 'sub', 'top' or 'all'. Default 'sub'.
	 *     @type string $id_prefix    Prefix for the `<li>` ids. Default '' (core ids).
	 *     @type int    $depth        Depth passed to wp_nav_menu() (0 = all). Default 0.
	 * }
	 */
	public function __construct( array $options = array() ) {
		if ( method_exists( '\Walker_Nav_Menu', '__construct' ) ) {
			parent::__construct();
		}

		$options = wp_parse_args(
			$options,
			array(
				'headings'     => true,
				'hash_as_text' => true,
				'line'         => 'sub',
				'id_prefix'    => '',
				'depth'        => 0,
			)
		);

		$this->headings     = (bool) $options['headings'];
		$this->hash_as_text = (bool) $options['hash_as_text'];
		$this->line         = in_array( $options['line'], array( 'sub', 'top', 'all' ), true ) ? $options['line'] : '';
		$this->id_prefix    = sanitize_html_class( (string) $options['id_prefix'] );
		$this->max_depth    = max( 0, (int) $options['depth'] );
		$this->privacy_url  = (string) get_privacy_policy_url();
	}

	/**
	 * Starts a sub-menu list.
	 *
	 * @param string        $output Used to append additional content (passed by reference).
	 * @param int           $depth  Depth of the parent item.
	 * @param stdClass|null $args   An object of wp_nav_menu() arguments.
	 */
	public function start_lvl( &$output, $depth = 0, $args = null ) {
		list( $t, $n ) = $this->get_spacing( $args );

		$indent  = str_repeat( $t, $depth );
		$classes = array( 'sub-menu', 'lst-menu__sub', 'lst-menu__sub--depth-' . ( $depth + 1 ) );

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$class_names = implode( ' ', (array) apply_filters( 'nav_menu_submenu_css_class', $classes, $args, $depth ) );

		$atts = array( 'class' => $class_names );

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$atts = apply_filters( 'nav_menu_submenu_attributes', $atts, $args, $depth );

		$output .= "{$n}{$indent}<ul" . $this->build_atts( $atts ) . ">{$n}";
	}

	/**
	 * Starts an element (the `<li>` and its link or text).
	 *
	 * Mirrors {@see \Walker_Nav_Menu::start_el()} (same filters, same
	 * escaping through build_atts()) and adds the widget's classes.
	 *
	 * @param string        $output            Used to append additional content (passed by reference).
	 * @param WP_Post       $data_object       Menu item data object.
	 * @param int           $depth             Depth of the menu item.
	 * @param stdClass|null $args              An object of wp_nav_menu() arguments.
	 * @param int           $current_object_id Optional. ID of the current menu item. Default 0.
	 */
	public function start_el( &$output, $data_object, $depth = 0, $args = null, $current_object_id = 0 ) {
		$menu_item = $data_object;
		$depth     = (int) $depth;

		list( $t ) = $this->get_spacing( $args );

		$indent = $depth ? str_repeat( $t, $depth ) : '';

		// Children are only printed when the depth limit allows it (see Walker::display_element()).
		$max_depth  = isset( $args->depth ) ? max( 0, (int) $args->depth ) : $this->max_depth;
		$has_sub    = $this->has_children && ( 0 === $max_depth || $max_depth > $depth + 1 );
		$is_heading = 0 === $depth && $this->headings && $has_sub;

		$classes   = empty( $menu_item->classes ) ? array() : (array) $menu_item->classes;
		$classes[] = 'menu-item-' . $menu_item->ID;
		$classes[] = 'lst-menu__item';
		$classes[] = 0 === $depth ? 'lst-menu__item--top' : 'lst-menu__item--sub';
		$classes[] = 'lst-menu__item--depth-' . $depth;

		if ( $has_sub ) {
			$classes[] = 'lst-menu__item--has-sub';
		}

		if ( $is_heading ) {
			$classes[] = 'lst-menu__item--heading';
		}

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$args = apply_filters( 'nav_menu_item_args', $args, $menu_item, $depth );

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$class_names = implode( ' ', (array) apply_filters( 'nav_menu_css_class', array_filter( $classes ), $menu_item, $args, $depth ) );

		$default_id = '' !== $this->id_prefix ? $this->id_prefix . '-item-' . $menu_item->ID : 'menu-item-' . $menu_item->ID;

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$id = apply_filters( 'nav_menu_item_id', $default_id, $menu_item, $args, $depth );

		$li_atts = array(
			'id'    => ! empty( $id ) ? $id : '',
			'class' => ! empty( $class_names ) ? $class_names : '',
		);

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$li_atts = apply_filters( 'nav_menu_item_attributes', $li_atts, $menu_item, $args, $depth );

		$output .= $indent . '<li' . $this->build_atts( $li_atts ) . '>';

		/** This filter is documented in wp-includes/post-template.php */
		$title = apply_filters( 'the_title', $menu_item->title, $menu_item->ID );

		// Save filtered value before filtering again.
		$the_title_filtered = $title;

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$title = apply_filters( 'nav_menu_item_title', $title, $menu_item, $args, $depth );

		$atts           = array();
		$atts['class']  = 'lst-menu__link';
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

		// Make sure the widget's classes survive filters that replace the class attribute.
		$link_classes = isset( $atts['class'] ) && is_string( $atts['class'] ) ? preg_split( '/\s+/', trim( $atts['class'] ) ) : array();
		$link_classes = array_merge( array( 'lst-menu__link' ), array_filter( (array) $link_classes ) );

		if ( $this->has_line( $depth ) ) {
			$link_classes[] = 'lst-menu__link--line';
		}

		$href    = isset( $atts['href'] ) && is_scalar( $atts['href'] ) ? trim( (string) $atts['href'] ) : '';
		$is_text = $this->hash_as_text && ( '' === $href || '#' === $href );

		if ( $is_text ) {
			$link_classes[] = 'lst-menu__text';

			foreach ( self::LINK_ONLY_ATTRIBUTES as $attribute ) {
				unset( $atts[ $attribute ] );
			}
		}

		$atts['class'] = implode( ' ', array_unique( $link_classes ) );
		$tag           = $is_text ? 'span' : 'a';

		$before      = isset( $args->before ) ? $args->before : '';
		$after       = isset( $args->after ) ? $args->after : '';
		$link_before = isset( $args->link_before ) ? $args->link_before : '';
		$link_after  = isset( $args->link_after ) ? $args->link_after : '';

		// The title is not escaped, exactly like in core: menu item titles may contain HTML.
		$item_output  = $before;
		$item_output .= '<' . $tag . $this->build_atts( $atts ) . '>';
		$item_output .= '<span class="lst-menu__label">' . $link_before . $title . $link_after . '</span>';
		$item_output .= '</' . $tag . '>';
		$item_output .= $after;

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$output .= apply_filters( 'walker_nav_menu_start_el', $item_output, $menu_item, $depth, $args );
	}

	/**
	 * Whether the decorative line is drawn in front of items of a depth.
	 *
	 * @param int $depth Depth of the menu item.
	 * @return bool
	 */
	private function has_line( $depth ) {
		switch ( $this->line ) {
			case 'all':
				return true;
			case 'top':
				return 0 === $depth;
			case 'sub':
				return 0 < $depth;
			default:
				return false;
		}
	}

	/**
	 * Whitespace used between tags, honouring the `item_spacing` argument.
	 *
	 * @param stdClass|null $args An object of wp_nav_menu() arguments.
	 * @return string[] Tab and newline characters (empty strings when discarded).
	 */
	private function get_spacing( $args ) {
		if ( isset( $args->item_spacing ) && 'discard' === $args->item_spacing ) {
			return array( '', '' );
		}

		return array( "\t", "\n" );
	}
}
