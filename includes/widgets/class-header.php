<?php
/**
 * "Listemann Header" Elementor widget.
 *
 * Recreates the listemann.com header: a logo in a grey box that hangs over
 * the content below, a dropdown / mega menu built from a WordPress menu, an
 * optional language switcher (WPML / Polylang) and a hamburger that opens a
 * full-screen off-canvas menu.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Icons_Manager;
use Elementor\Plugin;
use Elementor\Repeater;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Header widget: logo, mega menu, language switcher and off-canvas menu.
 *
 * Markup overview (classes used by assets/css/header.css and
 * assets/js/header.js):
 *
 *     div.lst-header.lst-hdr-{element id}[data-lst-header]
 *       style                                  (collapse breakpoint, scoped)
 *       div.lst-header__bar > div.lst-header__inner
 *         div.lst-header__brand > div.lst-header__logo-box > a.lst-header__logo > img
 *         div.lst-header__end
 *           nav.lst-header__nav > ul.lst-header__menu   (see Header_Nav_Walker)
 *           div.lst-header__lang > ul.lst-header__menu  (built-in language switcher)
 *           button.lst-header__burger
 *       div.lst-offcanvas.lst-oc-{element id}[role=dialog]  (moved to <body> by the script)
 *
 * Style controls write CSS custom properties. Off-canvas controls use the
 * selector `.lst-oc-{{ID}}` instead of `{{WRAPPER}}`, because the script
 * moves the off-canvas out of the widget (to `<body>`), where `{{WRAPPER}}`
 * selectors no longer match.
 */
class Header extends Widget_Base {

	/**
	 * Handle of the widget stylesheet (registered by the plugin bootstrap).
	 */
	const STYLE_HANDLE = 'listemann-toolkit-header';

	/**
	 * Handle of the Listemann Menu stylesheet, reused for the off-canvas menus.
	 */
	const MENU_STYLE_HANDLE = 'listemann-toolkit-menu';

	/**
	 * Handle of the widget script (registered by the plugin bootstrap).
	 */
	const SCRIPT_HANDLE = 'listemann-toolkit-header';

	/**
	 * Default collapse breakpoint in px (the original hides the menu below 1280px).
	 */
	const DEFAULT_BREAKPOINT = 1280;

	/**
	 * Bundled images: file => intrinsic width and height (for the img attributes).
	 */
	const BUNDLED_IMAGES = array(
		'listemann-logo.svg'       => array( 165, 96 ),
		'listemann-logo-color.svg' => array( 190, 111 ),
	);

	/**
	 * Social networks with a bundled icon (assets/img/social-{key}.svg).
	 */
	const SOCIAL_NETWORKS = array( 'linkedin', 'facebook', 'youtube', 'instagram', 'xing', 'x' );

	/**
	 * Prefix of the option keys of the menu controls ("menu-15").
	 *
	 * The editor receives SELECT options as a JavaScript object, which lists
	 * integer-like keys first and in ascending order: bare IDs would sort the
	 * menus by ID instead of by name and move "— Select —" to the end.
	 * Settings saved as a bare ID (older versions) still work.
	 */
	const MENU_KEY_PREFIX = 'menu-';

	/**
	 * Prefix of the option keys of the template control ("template-260"),
	 * see MENU_KEY_PREFIX.
	 */
	const TEMPLATE_KEY_PREFIX = 'template-';

	/**
	 * Instance keys already used during this request (keeps ids unique when
	 * the same element is rendered more than once on a page).
	 *
	 * @var string[]
	 */
	private static $used_keys = array();

	/**
	 * Templates that are being rendered into an off-canvas right now
	 * (recursion guard).
	 *
	 * @var array<int, bool>
	 */
	private static $rendering_templates = array();

	/**
	 * Sanitized inline SVG icons, keyed by network.
	 *
	 * @var string[]
	 */
	private static $icon_cache = array();

	/**
	 * Widget name (slug).
	 *
	 * @return string
	 */
	public function get_name() {
		return 'listemann-header';
	}

	/**
	 * Widget title shown in the panel.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'Listemann Header', 'listemann-toolkit-addon' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-header';
	}

	/**
	 * Panel categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'listemann' );
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'header', 'menu', 'mega menu', 'navigation', 'nav', 'logo', 'hamburger', 'off-canvas', 'language', 'listemann' );
	}

	/**
	 * Stylesheets the widget depends on.
	 *
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( self::MENU_STYLE_HANDLE, self::STYLE_HANDLE );
	}

	/**
	 * Scripts the widget depends on.
	 *
	 * @return string[]
	 */
	public function get_script_depends(): array {
		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * Drop the `.elementor-widget-container` wrapper when Elementor's
	 * optimized markup is active. The widget CSS works with and without it.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper(): bool {
		return ! Plugin::$instance->experiments->is_feature_active( 'e_optimized_markup' );
	}

	/**
	 * The output depends on the current URL (current-menu-item classes,
	 * language URLs), so it must never be served from Elementor's element
	 * cache.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Keep the header out of the plain-text post content Elementor saves.
	 */
	public function render_plain_content() {}

	/**
	 * Element data for the editor (and for saving): menu and template IDs
	 * saved by older versions as bare numbers get the option key prefix, so
	 * the editor's selects show them as selected.
	 *
	 * @param bool $with_html_content Whether to include the rendered HTML.
	 * @return array
	 */
	public function get_raw_data( $with_html_content = false ) {
		$data = parent::get_raw_data( $with_html_content );

		if ( empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return $data;
		}

		$settings = $this->prefix_legacy_id( $data['settings'], 'menu', self::MENU_KEY_PREFIX );
		$settings = $this->prefix_legacy_id( $settings, 'offcanvas_template', self::TEMPLATE_KEY_PREFIX );

		if ( isset( $settings['offcanvas_columns'] ) && is_array( $settings['offcanvas_columns'] ) ) {
			foreach ( $settings['offcanvas_columns'] as $index => $column ) {
				if ( is_array( $column ) ) {
					$settings['offcanvas_columns'][ $index ] = $this->prefix_legacy_id( $column, 'menu', self::MENU_KEY_PREFIX );
				}
			}
		}

		$data['settings'] = $settings;

		return $data;
	}

	/**
	 * Adds an option key prefix to a setting saved as a bare ID.
	 *
	 * @param array  $settings Settings (or a repeater row).
	 * @param string $key      Setting key.
	 * @param string $prefix   Option key prefix.
	 * @return array
	 */
	private function prefix_legacy_id( array $settings, $key, $prefix ) {
		if ( isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) && preg_match( '/^[1-9]\d*$/', (string) $settings[ $key ] ) ) {
			$settings[ $key ] = $prefix . $settings[ $key ];
		}

		return $settings;
	}

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
	 * Register the widget controls.
	 */
	protected function register_controls() {
		$this->register_logo_controls();
		$this->register_menu_controls();
		$this->register_language_controls();
		$this->register_offcanvas_controls();
		$this->register_layout_controls();

		$this->register_bar_style_controls();
		$this->register_logo_box_style_controls();
		$this->register_item_style_controls();
		$this->register_dropdown_style_controls();
		$this->register_language_style_controls();
		$this->register_hamburger_style_controls();
		$this->register_offcanvas_style_controls();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Content tab
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Content tab: logo.
	 */
	private function register_logo_controls() {
		$this->start_controls_section(
			'section_logo',
			array(
				'label' => esc_html__( 'Logo', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'logo',
			array(
				'label'       => esc_html__( 'Logo image', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::MEDIA,
				'media_types' => array( 'image', 'svg' ),
				'default'     => $this->get_default_logo(),
				'dynamic'     => array(
					'active' => true,
				),
				'description' => esc_html__( 'Defaults to the site logo (Customizer) or the bundled Listemann logo.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'logo_link',
			array(
				'label'       => esc_html__( 'Link', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => home_url( '/' ),
				'default'     => array(
					'url' => home_url( '/' ),
				),
				'dynamic'     => array(
					'active' => true,
				),
			)
		);

		$this->add_control(
			'logo_alt',
			array(
				'label'   => esc_html__( 'Alternative text', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::TEXT,
				'default' => get_bloginfo( 'name', 'display' ),
				'dynamic' => array(
					'active' => true,
				),
			)
		);

		$this->add_size_var_control(
			'logo_width',
			esc_html__( 'Logo width', 'listemann-toolkit-addon' ),
			'--lst-hdr-logo-w',
			'{{WRAPPER}}',
			array( 165, 65, 65 ),
			array(
				'range' => array(
					'px' => array(
						'min' => 20,
						'max' => 400,
					),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: main menu.
	 */
	private function register_menu_controls() {
		$this->start_controls_section(
			'section_menu',
			array(
				'label' => esc_html__( 'Main menu', 'listemann-toolkit-addon' ),
			)
		);

		$menus = $this->get_menu_options();

		if ( $menus ) {
			$this->add_control(
				'menu',
				array(
					'label'        => esc_html__( 'Menu', 'listemann-toolkit-addon' ),
					'type'         => Controls_Manager::SELECT,
					'options'      => $menus,
					'default'      => (string) array_keys( $menus )[0],
					'save_default' => true,
					'description'  => sprintf(
						/* translators: 1: Link open tag, 2: Link close tag. */
						esc_html__( 'Go to the %1$sMenus screen%2$s to manage your menus.', 'listemann-toolkit-addon' ),
						'<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '" target="_blank">',
						'</a>'
					),
				)
			);
		} else {
			$this->add_control(
				'menu_missing_notice',
				array(
					'type'            => Controls_Manager::RAW_HTML,
					'raw'             => sprintf(
						/* translators: 1: Link open tag, 2: Link close tag. */
						esc_html__( 'There are no menus on your site yet. %1$sCreate a menu%2$s in Appearance > Menus, then reload the editor.', 'listemann-toolkit-addon' ),
						'<a href="' . esc_url( admin_url( 'nav-menus.php?action=edit&menu=0' ) ) . '" target="_blank">',
						'</a>'
					),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
				)
			);
		}

		$this->add_control(
			'mega_notice',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Mega menu: second-level items that have sub-items are shown as column headings with their sub-items beneath. To show a dropdown as a multi-column panel, set "Mega menu columns" (2–6) on its top-level item in Appearance > Menus.', 'listemann-toolkit-addon' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'mega_width',
			array(
				'label'       => esc_html__( 'Mega panel width', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'auto' => esc_html__( 'Auto (fit content)', 'listemann-toolkit-addon' ),
					'full' => esc_html__( 'Full (header content width)', 'listemann-toolkit-addon' ),
				),
				'default'     => 'auto',
				'description' => esc_html__( 'Applies to items with 2 or more mega menu columns.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'show_chevrons',
			array(
				'label'        => esc_html__( 'Show dropdown chevrons', 'listemann-toolkit-addon' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => esc_html__( 'When hidden, the dropdown toggle buttons stay available to keyboard users.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'open_on',
			array(
				'label'       => esc_html__( 'Open dropdowns on', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'hover' => esc_html__( 'Hover', 'listemann-toolkit-addon' ),
					'click' => esc_html__( 'Click', 'listemann-toolkit-addon' ),
				),
				'default'     => 'hover',
				'description' => esc_html__( 'With "Click", the first click on a parent item opens its dropdown and a second click follows its link.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'menu_aria_label',
			array(
				'label'       => esc_html__( 'Accessible label', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => esc_html__( 'Menu name', 'listemann-toolkit-addon' ),
				'description' => esc_html__( 'Label of the navigation landmark for screen readers. Leave empty to use the menu name.', 'listemann-toolkit-addon' ),
				'separator'   => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: language switcher.
	 */
	private function register_language_controls() {
		$this->start_controls_section(
			'section_language',
			array(
				'label' => esc_html__( 'Language switcher', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'show_language',
			array(
				'label'        => esc_html__( 'Show language switcher (WPML / Polylang)', 'listemann-toolkit-addon' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'label_block'  => true,
				'description'  => esc_html__( 'Shown only when WPML or Polylang provides two or more languages, and not when WPML / Polylang already adds language items to the selected menu.', 'listemann-toolkit-addon' ),
			)
		);

		if ( ! $this->has_multilingual_plugin() ) {
			$this->add_control(
				'language_notice',
				array(
					'type'            => Controls_Manager::RAW_HTML,
					'raw'             => esc_html__( 'Neither WPML nor Polylang is active, so the language switcher is hidden.', 'listemann-toolkit-addon' ),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
					'condition'       => array(
						'show_language' => 'yes',
					),
				)
			);
		}

		$this->add_control(
			'language_display',
			array(
				'label'     => esc_html__( 'Display', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'flag_code' => esc_html__( 'Flag + code', 'listemann-toolkit-addon' ),
					'code'      => esc_html__( 'Code only', 'listemann-toolkit-addon' ),
					'flag'      => esc_html__( 'Flag only', 'listemann-toolkit-addon' ),
				),
				'default'   => 'flag_code',
				'condition' => array(
					'show_language' => 'yes',
				),
			)
		);

		$this->add_control(
			'offcanvas_language',
			array(
				'label'        => esc_html__( 'Also show in the off-canvas', 'listemann-toolkit-addon' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => esc_html__( 'Adds the languages as a group below the last off-canvas menu column (the header switcher is hidden below the collapse breakpoint).', 'listemann-toolkit-addon' ),
				'condition'    => array(
					'show_language' => 'yes',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: hamburger and off-canvas content.
	 */
	private function register_offcanvas_controls() {
		$this->start_controls_section(
			'section_offcanvas',
			array(
				'label' => esc_html__( 'Hamburger / off-canvas', 'listemann-toolkit-addon' ),
			)
		);

		// Every device needs a default: a switcher without one is '' (off), and
		// the dictionary would turn that into `none` on any additional
		// breakpoint the site enables (Laptop, Tablet Extra, Widescreen ...).
		$this->add_responsive_control(
			'show_hamburger',
			array(
				'label'                => esc_html__( 'Show hamburger', 'listemann-toolkit-addon' ),
				'type'                 => Controls_Manager::SWITCHER,
				'default'              => 'yes',
				'widescreen_default'   => 'yes',
				'laptop_default'       => 'yes',
				'tablet_extra_default' => 'yes',
				'tablet_default'       => 'yes',
				'mobile_extra_default' => 'yes',
				'mobile_default'       => 'yes',
				'return_value'         => 'yes',
				'description'          => esc_html__( 'Below the collapse breakpoint (Layout section) the hamburger is always shown, because it is then the only way to reach the menu.', 'listemann-toolkit-addon' ),
				'selectors_dictionary' => array(
					'yes' => 'inline-flex',
					''    => 'none',
				),
				'selectors'            => array(
					'{{WRAPPER}}' => '--lst-hdr-burger-display: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'offcanvas_source',
			array(
				'label'     => esc_html__( 'Off-canvas content', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'menus'    => esc_html__( 'Menus', 'listemann-toolkit-addon' ),
					'template' => esc_html__( 'Elementor template', 'listemann-toolkit-addon' ),
				),
				'default'   => 'menus',
				'separator' => 'before',
			)
		);

		$this->add_control(
			'offcanvas_template',
			array(
				'label'       => esc_html__( 'Template', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array( '' => esc_html__( '— Select —', 'listemann-toolkit-addon' ) ) + $this->get_template_options(),
				'default'     => '',
				'description' => sprintf(
					/* translators: 1: Link open tag, 2: Link close tag. */
					esc_html__( 'Saved templates are managed under %1$sTemplates > Saved Templates%2$s.', 'listemann-toolkit-addon' ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=elementor_library' ) ) . '" target="_blank">',
					'</a>'
				),
				'condition'   => array(
					'offcanvas_source' => 'template',
				),
			)
		);

		$menus = $this->get_menu_options();

		$columns = new Repeater();

		$columns->add_control(
			'menu',
			array(
				'label'   => esc_html__( 'Menu', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array( '' => esc_html__( '— Select —', 'listemann-toolkit-addon' ) ) + $menus,
				'default' => '',
			)
		);

		$columns->add_control(
			'label',
			array(
				'label'       => esc_html__( 'Accessible label', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => esc_html__( 'Menu name', 'listemann-toolkit-addon' ),
				'description' => esc_html__( 'Label of the navigation landmark for screen readers. Leave empty to use the menu name.', 'listemann-toolkit-addon' ),
			)
		);

		$column_defaults = array();

		foreach ( array_slice( array_keys( $menus ), 0, 3 ) as $menu_id ) {
			$column_defaults[] = array( 'menu' => (string) $menu_id );
		}

		$this->add_control(
			'offcanvas_columns',
			array(
				'label'         => esc_html__( 'Menu columns', 'listemann-toolkit-addon' ),
				'type'          => Controls_Manager::REPEATER,
				'fields'        => $columns->get_controls(),
				'default'       => $column_defaults,
				'prevent_empty' => false,
				'title_field'   => '<# var lstMenus = ' . wp_json_encode( (object) $menus, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '; #>{{{ lstMenus[ menu ] || lstMenus[ "' . self::MENU_KEY_PREFIX . '" + menu ] || "' . esc_js( __( 'Menu column', 'listemann-toolkit-addon' ) ) . '" }}}',
				'description'   => esc_html__( 'Each column shows one menu: top-level items are headings, their sub-items get the red line.', 'listemann-toolkit-addon' ),
				'condition'     => array(
					'offcanvas_source' => 'menus',
				),
			)
		);

		$this->add_control(
			'offcanvas_show_logo',
			array(
				'label'        => esc_html__( 'Show logo', 'listemann-toolkit-addon' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'separator'    => 'before',
				'condition'    => array(
					'offcanvas_source' => 'menus',
				),
			)
		);

		$this->add_control(
			'offcanvas_logo',
			array(
				'label'       => esc_html__( 'Off-canvas logo', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::MEDIA,
				'media_types' => array( 'image', 'svg' ),
				'default'     => array(
					'url' => LISTEMANN_TOOLKIT_URL . 'assets/img/listemann-logo-color.svg',
					'id'  => '',
				),
				'dynamic'     => array(
					'active' => true,
				),
				'condition'   => array(
					'offcanvas_source'    => 'menus',
					'offcanvas_show_logo' => 'yes',
				),
			)
		);

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

		$this->add_control(
			'social_links',
			array(
				'label'         => esc_html__( 'Social links', 'listemann-toolkit-addon' ),
				'type'          => Controls_Manager::REPEATER,
				'fields'        => $social->get_controls(),
				'default'       => array(
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
				),
				'prevent_empty' => false,
				'title_field'   => '<# var lstNetworks = ' . wp_json_encode( (object) ( $this->get_network_labels() + array( 'custom' => __( 'Custom icon', 'listemann-toolkit-addon' ) ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '; #>{{{ lstNetworks[ network ] || network }}}',
				'condition'     => array(
					'offcanvas_source' => 'menus',
				),
			)
		);

		$this->add_control(
			'offcanvas_label',
			array(
				'label'       => esc_html__( 'Accessible label', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => esc_html__( 'Menu', 'listemann-toolkit-addon' ),
				'description' => esc_html__( 'Name of the off-canvas dialog for screen readers.', 'listemann-toolkit-addon' ),
				'separator'   => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: collapse breakpoint and sticky behaviour.
	 */
	private function register_layout_controls() {
		$this->start_controls_section(
			'section_layout',
			array(
				'label' => esc_html__( 'Layout', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'collapse_breakpoint',
			array(
				'label'       => esc_html__( 'Collapse breakpoint (px)', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 5000,
				'step'        => 1,
				'default'     => self::DEFAULT_BREAKPOINT,
				'description' => esc_html__( 'Below this screen width the main menu and the language switcher are hidden; only the logo and the hamburger remain. 0 = never collapse.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'sticky',
			array(
				'label'        => esc_html__( 'Sticky header', 'listemann-toolkit-addon' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'separator'    => 'before',
				'description'  => esc_html__( 'Once the header has scrolled out of view, a compact version stays fixed at the top. It does not work inside a parent that uses CSS transforms.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_size_var_control(
			'sticky_height',
			esc_html__( 'Sticky height', 'listemann-toolkit-addon' ),
			'--lst-hdr-sticky-h',
			'{{WRAPPER}}',
			array( 90, 60, 60 ),
			array(
				'range'     => array(
					'px' => array(
						'min' => 30,
						'max' => 200,
					),
				),
				'condition' => array(
					'sticky' => 'yes',
				),
			)
		);

		$this->add_size_var_control(
			'sticky_logo_width',
			esc_html__( 'Sticky logo width', 'listemann-toolkit-addon' ),
			'--lst-hdr-sticky-logo-w',
			'{{WRAPPER}}',
			array( 110, 65, 65 ),
			array(
				'range'     => array(
					'px' => array(
						'min' => 20,
						'max' => 400,
					),
				),
				'condition' => array(
					'sticky' => 'yes',
				),
			)
		);

		$this->add_color_var_control(
			'sticky_background',
			esc_html__( 'Sticky background', 'listemann-toolkit-addon' ),
			'--lst-hdr-sticky-bg',
			'{{WRAPPER}}',
			'#ffffff',
			array(
				'condition' => array(
					'sticky' => 'yes',
				),
			)
		);

		$this->add_control(
			'sticky_shadow',
			array(
				'label'                => esc_html__( 'Sticky shadow', 'listemann-toolkit-addon' ),
				'type'                 => Controls_Manager::SWITCHER,
				'default'              => 'yes',
				'return_value'         => 'yes',
				'selectors_dictionary' => array(
					'yes' => '0 2px 12px rgba(0, 0, 0, 0.08)',
					''    => 'none',
				),
				'selectors'            => array(
					'{{WRAPPER}}' => '--lst-hdr-sticky-shadow: {{VALUE}};',
				),
				'condition'            => array(
					'sticky' => 'yes',
				),
			)
		);

		$this->end_controls_section();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Style tab
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Style tab: header bar.
	 */
	private function register_bar_style_controls() {
		$this->start_controls_section(
			'section_style_bar',
			array(
				'label' => esc_html__( 'Header bar', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_size_var_control(
			'bar_height',
			esc_html__( 'Height', 'listemann-toolkit-addon' ),
			'--lst-hdr-h',
			'{{WRAPPER}}',
			array( 170, 60, 60 ),
			array(
				'range' => array(
					'px' => array(
						'min' => 30,
						'max' => 400,
					),
				),
			)
		);

		$this->add_color_var_control(
			'bar_background',
			esc_html__( 'Background color', 'listemann-toolkit-addon' ),
			'--lst-hdr-bg',
			'{{WRAPPER}}'
		);

		$this->add_size_var_control(
			'bar_padding_x',
			esc_html__( 'Side padding', 'listemann-toolkit-addon' ),
			'--lst-hdr-pad-x',
			'{{WRAPPER}}',
			array( 50, 15, 15 ),
			array(
				'range' => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
			)
		);

		$this->add_size_var_control(
			'bar_max_width',
			esc_html__( 'Max content width', 'listemann-toolkit-addon' ),
			'--lst-hdr-max-w',
			'{{WRAPPER}}',
			array( null, null, null ),
			array(
				'size_units'  => array( 'px', '%', 'vw' ),
				'range'       => array(
					'px' => array(
						'min' => 300,
						'max' => 2600,
					),
				),
				'description' => esc_html__( 'Empty = full width. The original uses 1280px (padding included) and 1640px on screens 1640px and wider: enable Elementor\'s Widescreen breakpoint at 1640px (Site Settings > Layout > Breakpoints) and set 1640px there.', 'listemann-toolkit-addon' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: logo box.
	 */
	private function register_logo_box_style_controls() {
		$this->start_controls_section(
			'section_style_logo_box',
			array(
				'label' => esc_html__( 'Logo box', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_color_var_control(
			'logo_box_background',
			esc_html__( 'Background color', 'listemann-toolkit-addon' ),
			'--lst-hdr-logo-box-bg',
			'{{WRAPPER}}',
			'#eeeeee'
		);

		$this->add_size_var_control(
			'logo_box_width',
			esc_html__( 'Width', 'listemann-toolkit-addon' ),
			'--lst-hdr-logo-box-w',
			'{{WRAPPER}}',
			array( 234, 75, 75 ),
			array(
				'range' => array(
					'px' => array(
						'min' => 20,
						'max' => 600,
					),
				),
			)
		);

		$this->add_size_var_control(
			'logo_box_height',
			esc_html__( 'Height', 'listemann-toolkit-addon' ),
			'--lst-hdr-logo-box-h',
			'{{WRAPPER}}',
			array( 200, 85, 85 ),
			array(
				'range'       => array(
					'px' => array(
						'min' => 20,
						'max' => 600,
					),
				),
				'description' => esc_html__( 'May be taller than the header bar: the box then hangs over the content below.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_size_var_control(
			'logo_box_padding_x',
			esc_html__( 'Side padding', 'listemann-toolkit-addon' ),
			'--lst-hdr-logo-box-pad-x',
			'{{WRAPPER}}',
			array( 0, 5, 5 ),
			array(
				'range' => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
			)
		);

		$this->add_size_var_control(
			'logo_margin_bottom',
			esc_html__( 'Logo bottom margin', 'listemann-toolkit-addon' ),
			'--lst-hdr-logo-mb',
			'{{WRAPPER}}',
			array( 27, 17, 17 ),
			array(
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
				'description' => esc_html__( 'Defaults match listemann.com, where a 20px (tablet: 10px) margin plus the gap below its inline logo image put the logo 27px (17px) above the box bottom.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'logo_box_z_index',
			array(
				'label'     => esc_html__( 'Z-index', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 0,
				'max'       => 99999,
				'step'      => 1,
				'default'   => 999,
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-hdr-logo-z: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: top-level menu items.
	 */
	private function register_item_style_controls() {
		$this->start_controls_section(
			'section_style_items',
			array(
				'label' => esc_html__( 'Top-level items', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'top_typography',
				'selector'       => '{{WRAPPER}} .lst-header__link--top',
				'fields_options' => $this->typography_defaults( 15, '700', 'uppercase', 17 ),
			)
		);

		$this->start_controls_tabs( 'tabs_top_colors' );

		$this->start_controls_tab(
			'tab_top_normal',
			array( 'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ) )
		);

		$this->add_color_var_control( 'top_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-hdr-top-color', '{{WRAPPER}}', '#626262' );
		$this->add_color_var_control(
			'chevron_color',
			esc_html__( 'Chevron color', 'listemann-toolkit-addon' ),
			'--lst-hdr-chevron-color',
			'{{WRAPPER}}',
			'',
			array( 'description' => esc_html__( 'Empty = item color.', 'listemann-toolkit-addon' ) )
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_top_hover',
			array( 'label' => esc_html__( 'Hover / open', 'listemann-toolkit-addon' ) )
		);

		$this->add_color_var_control( 'top_hover_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-hdr-top-hover-color', '{{WRAPPER}}', '#dd191a' );
		$this->add_color_var_control(
			'chevron_hover_color',
			esc_html__( 'Chevron color', 'listemann-toolkit-addon' ),
			'--lst-hdr-chevron-hover-color',
			'{{WRAPPER}}',
			'',
			array( 'description' => esc_html__( 'Empty = item hover color.', 'listemann-toolkit-addon' ) )
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_top_active',
			array( 'label' => esc_html__( 'Active', 'listemann-toolkit-addon' ) )
		);

		$this->add_color_var_control(
			'top_active_color',
			esc_html__( 'Color', 'listemann-toolkit-addon' ),
			'--lst-hdr-top-active-color',
			'{{WRAPPER}}',
			'#dd191a',
			array( 'description' => esc_html__( 'Current page and its ancestors.', 'listemann-toolkit-addon' ) )
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_size_var_control(
			'item_spacing',
			esc_html__( 'Item spacing', 'listemann-toolkit-addon' ),
			'--lst-hdr-gap',
			'{{WRAPPER}}',
			array( 38, null, null ),
			array(
				'range'     => array(
					'px' => array(
						'min' => 0,
						'max' => 120,
					),
				),
				'separator' => 'before',
			)
		);

		$this->add_size_var_control(
			'chevron_size',
			esc_html__( 'Chevron width', 'listemann-toolkit-addon' ),
			'--lst-hdr-chevron-w',
			'{{WRAPPER}}',
			10,
			array(
				'range' => array(
					'px' => array(
						'min' => 4,
						'max' => 30,
					),
				),
			)
		);

		$this->add_control(
			'transition_duration',
			array(
				'label'      => esc_html__( 'Transition duration', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'ms', 's' ),
				'default'    => array(
					'unit' => 'ms',
					'size' => 300,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-hdr-transition: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: dropdown panels.
	 */
	private function register_dropdown_style_controls() {
		$this->start_controls_section(
			'section_style_dropdown',
			array(
				'label' => esc_html__( 'Dropdown', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_color_var_control( 'dropdown_background', esc_html__( 'Background color', 'listemann-toolkit-addon' ), '--lst-hdr-dd-bg', '{{WRAPPER}}', '#ffffff' );

		$this->add_control(
			'dropdown_padding',
			array(
				'label'       => esc_html__( 'Padding', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'default'     => array(
					'top'      => '28',
					'right'    => '56',
					'bottom'   => '28',
					'left'     => '28',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'description' => esc_html__( 'The default right padding (56px) reproduces listemann.com, whose dropdown items also kept the 38px item spacing.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-hdr-dd-pad: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'dropdown_box_shadow',
				'selector' => '{{WRAPPER}} .lst-header__dropdown',
			)
		);

		$this->add_size_var_control(
			'dropdown_min_width',
			esc_html__( 'Min width', 'listemann-toolkit-addon' ),
			'--lst-hdr-dd-min-w',
			'{{WRAPPER}}',
			null,
			array(
				'range' => array(
					'px' => array(
						'min' => 0,
						'max' => 800,
					),
				),
			)
		);

		$this->add_size_var_control(
			'dropdown_offset',
			esc_html__( 'Offset top', 'listemann-toolkit-addon' ),
			'--lst-hdr-dd-top',
			'{{WRAPPER}}',
			25,
			array(
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 120,
					),
				),
				'description' => esc_html__( 'Distance from the top of the menu item to the panel.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_size_var_control(
			'mega_column_gap',
			esc_html__( 'Mega panel column gap', 'listemann-toolkit-addon' ),
			'--lst-hdr-mega-gap',
			'{{WRAPPER}}',
			40,
			array(
				'range' => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
			)
		);

		$this->add_control(
			'heading_dropdown_items',
			array(
				'label'     => esc_html__( 'Items', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'dropdown_typography',
				'selector'       => '{{WRAPPER}} .lst-header__sublink',
				'fields_options' => $this->typography_defaults( 15, '400', 'none', 34 ),
			)
		);

		$this->start_controls_tabs( 'tabs_dropdown_colors' );

		$this->start_controls_tab(
			'tab_dropdown_normal',
			array( 'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ) )
		);

		$this->add_color_var_control( 'dropdown_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-hdr-dd-color', '{{WRAPPER}}', '#626262' );

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_dropdown_hover',
			array( 'label' => esc_html__( 'Hover', 'listemann-toolkit-addon' ) )
		);

		$this->add_color_var_control( 'dropdown_hover_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-hdr-dd-hover-color', '{{WRAPPER}}', '#dd191a' );

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_dropdown_active',
			array( 'label' => esc_html__( 'Active', 'listemann-toolkit-addon' ) )
		);

		$this->add_color_var_control( 'dropdown_active_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-hdr-dd-active-color', '{{WRAPPER}}', '#dd191a' );

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_control(
			'heading_dropdown_groups',
			array(
				'label'     => esc_html__( 'Group headings (2nd level items with sub-items)', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'group_typography',
				'selector'       => '{{WRAPPER}} .lst-header__group-title',
				'fields_options' => $this->typography_defaults( 15, '700', 'uppercase', 17 ),
			)
		);

		$this->add_color_var_control( 'group_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-hdr-group-color', '{{WRAPPER}}', '#626262' );
		$this->add_color_var_control( 'group_hover_color', esc_html__( 'Hover color', 'listemann-toolkit-addon' ), '--lst-hdr-group-hover-color', '{{WRAPPER}}', '#dd191a' );

		$this->add_size_var_control(
			'group_heading_gap',
			esc_html__( 'Space below heading', 'listemann-toolkit-addon' ),
			'--lst-hdr-group-gap-top',
			'{{WRAPPER}}',
			14,
			array(
				'range' => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
			)
		);

		$this->add_size_var_control(
			'group_spacing',
			esc_html__( 'Space after group', 'listemann-toolkit-addon' ),
			'--lst-hdr-group-gap-bottom',
			'{{WRAPPER}}',
			28,
			array(
				'range' => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
			)
		);

		$this->add_control(
			'heading_dropdown_line',
			array(
				'label'     => esc_html__( 'Line before items', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_size_var_control(
			'line_width',
			esc_html__( 'Width', 'listemann-toolkit-addon' ),
			'--lst-hdr-line-w',
			'{{WRAPPER}}',
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
			'line_thickness',
			esc_html__( 'Thickness', 'listemann-toolkit-addon' ),
			'--lst-hdr-line-h',
			'{{WRAPPER}}',
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

		$this->add_color_var_control( 'line_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-hdr-line-color', '{{WRAPPER}}', '#dd191a' );

		$this->add_size_var_control(
			'line_gap',
			esc_html__( 'Gap to text', 'listemann-toolkit-addon' ),
			'--lst-hdr-line-gap',
			'{{WRAPPER}}',
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

		$this->end_controls_section();
	}

	/**
	 * Style tab: language switcher.
	 */
	private function register_language_style_controls() {
		$this->start_controls_section(
			'section_style_language',
			array(
				'label'     => esc_html__( 'Language switcher', 'listemann-toolkit-addon' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_language' => 'yes',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'lang_typography',
				'selector' => '{{WRAPPER}} .lst-header__item--lang > .lst-header__link--top',
			)
		);

		$this->add_color_var_control(
			'lang_color',
			esc_html__( 'Color', 'listemann-toolkit-addon' ),
			'--lst-hdr-lang-color',
			'{{WRAPPER}}',
			'',
			array( 'description' => esc_html__( 'Empty = top-level item color.', 'listemann-toolkit-addon' ) )
		);

		$this->add_color_var_control(
			'lang_hover_color',
			esc_html__( 'Hover / open color', 'listemann-toolkit-addon' ),
			'--lst-hdr-lang-hover-color',
			'{{WRAPPER}}',
			'',
			array( 'description' => esc_html__( 'Empty = top-level item hover color.', 'listemann-toolkit-addon' ) )
		);

		$this->add_size_var_control(
			'flag_width',
			esc_html__( 'Flag width', 'listemann-toolkit-addon' ),
			'--lst-hdr-flag-w',
			'{{WRAPPER}}, .lst-oc-{{ID}}',
			18,
			array(
				'range' => array(
					'px' => array(
						'min' => 8,
						'max' => 60,
					),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: hamburger button.
	 */
	private function register_hamburger_style_controls() {
		$this->start_controls_section(
			'section_style_hamburger',
			array(
				'label' => esc_html__( 'Hamburger', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_color_var_control( 'hamburger_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-hdr-burger-color', '{{WRAPPER}}', '#dd191a' );
		$this->add_color_var_control(
			'hamburger_hover_color',
			esc_html__( 'Hover color', 'listemann-toolkit-addon' ),
			'--lst-hdr-burger-hover-color',
			'{{WRAPPER}}',
			''
		);

		$this->add_size_var_control(
			'hamburger_size',
			esc_html__( 'Width', 'listemann-toolkit-addon' ),
			'--lst-hdr-burger-w',
			'{{WRAPPER}}',
			27,
			array(
				'range' => array(
					'px' => array(
						'min' => 12,
						'max' => 80,
					),
				),
			)
		);

		$this->add_size_var_control(
			'hamburger_thickness',
			esc_html__( 'Bar thickness', 'listemann-toolkit-addon' ),
			'--lst-hdr-burger-h',
			'{{WRAPPER}}',
			3,
			array(
				'range' => array(
					'px' => array(
						'min' => 1,
						'max' => 10,
					),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: off-canvas (selectors use `.lst-oc-{{ID}}`, see class doc).
	 */
	private function register_offcanvas_style_controls() {
		$oc = '.lst-oc-{{ID}}';

		$this->start_controls_section(
			'section_style_offcanvas',
			array(
				'label' => esc_html__( 'Off-canvas', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_color_var_control( 'oc_background', esc_html__( 'Background color', 'listemann-toolkit-addon' ), '--lst-oc-bg', $oc, '#ffffff' );
		$this->add_color_var_control( 'oc_close_color', esc_html__( 'Close (X) color', 'listemann-toolkit-addon' ), '--lst-oc-close-color', $oc, '#dd191a' );

		$this->add_size_var_control(
			'oc_close_size',
			esc_html__( 'Close (X) size', 'listemann-toolkit-addon' ),
			'--lst-oc-close-w',
			$oc,
			27,
			array(
				'range' => array(
					'px' => array(
						'min' => 10,
						'max' => 80,
					),
				),
			)
		);

		$this->add_size_var_control(
			'oc_max_width',
			esc_html__( 'Content max width', 'listemann-toolkit-addon' ),
			'--lst-oc-max-w',
			$oc,
			array( 1280, null, null ),
			array(
				'size_units' => array( 'px', '%', 'vw' ),
				'range'      => array(
					'px' => array(
						'min' => 300,
						'max' => 2600,
					),
				),
			)
		);

		$this->add_size_var_control(
			'oc_logo_width',
			esc_html__( 'Logo width', 'listemann-toolkit-addon' ),
			'--lst-oc-logo-w',
			$oc,
			array( 190, 190, 150 ),
			array(
				'range' => array(
					'px' => array(
						'min' => 20,
						'max' => 500,
					),
				),
			)
		);

		// listemann.com uses #a8a8a8 (2.4:1 on white); #949494 is the lightest grey with the 3:1 that icons and their focus outline need (WCAG 1.4.11).
		$this->add_color_var_control( 'oc_social_color', esc_html__( 'Social icon color', 'listemann-toolkit-addon' ), '--lst-oc-social-color', $oc, '#949494' );
		$this->add_color_var_control( 'oc_social_hover_color', esc_html__( 'Social icon hover color', 'listemann-toolkit-addon' ), '--lst-oc-social-hover-color', $oc, '' );

		$this->add_control(
			'oc_duration',
			array(
				'label'      => esc_html__( 'Open / close duration', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'ms', 's' ),
				'default'    => array(
					'unit' => 'ms',
					'size' => 750,
				),
				'selectors'  => array(
					$oc => '--lst-oc-duration: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'heading_oc_headings',
			array(
				'label'     => esc_html__( 'Menu headings', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'oc_heading_typography',
				'selector'       => $oc . ' .lst-menu__item--heading > .lst-menu__link',
				'fields_options' => $this->typography_defaults( 18, '700', 'uppercase', 34 ),
			)
		);

		$this->add_color_var_control( 'oc_heading_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-menu-heading-color', $oc, '#626262' );
		$this->add_color_var_control( 'oc_heading_hover_color', esc_html__( 'Hover color', 'listemann-toolkit-addon' ), '--lst-menu-heading-hover-color', $oc, '#dd191a' );

		$this->add_size_var_control(
			'oc_group_gap',
			esc_html__( 'Spacing between groups', 'listemann-toolkit-addon' ),
			'--lst-menu-group-gap',
			$oc,
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

		$this->add_control(
			'heading_oc_items',
			array(
				'label'     => esc_html__( 'Menu items', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'oc_item_typography',
				'selector'       => $oc . ' .lst-menu__item:not(.lst-menu__item--heading) > .lst-menu__link',
				'fields_options' => $this->typography_defaults( 15, '400', 'none', 34 ),
			)
		);

		$this->add_color_var_control( 'oc_item_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-menu-item-color', $oc, '#626262' );
		$this->add_color_var_control( 'oc_item_hover_color', esc_html__( 'Hover color', 'listemann-toolkit-addon' ), '--lst-menu-item-hover-color', $oc, '#dd191a' );
		$this->add_color_var_control( 'oc_item_active_color', esc_html__( 'Active color', 'listemann-toolkit-addon' ), '--lst-menu-item-active-color', $oc, '#dd191a' );

		$nested = $oc . ' .lst-menu__sub .lst-menu__sub > .lst-menu__item:not(.lst-menu__item--heading) > .lst-menu__link';

		$this->add_control(
			'oc_nested_font_size',
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
			'oc_nested_line_height',
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
			'oc_indent',
			esc_html__( 'Indent per nested level', 'listemann-toolkit-addon' ),
			'--lst-menu-indent',
			$oc,
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

		$this->add_control(
			'heading_oc_line',
			array(
				'label'     => esc_html__( 'Line before items', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_size_var_control(
			'oc_line_width',
			esc_html__( 'Width', 'listemann-toolkit-addon' ),
			'--lst-menu-line-w',
			$oc,
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
			'oc_line_width_nested',
			esc_html__( 'Width (nested levels)', 'listemann-toolkit-addon' ),
			'--lst-menu-line-w-nested',
			$oc,
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
			'oc_line_thickness',
			esc_html__( 'Thickness', 'listemann-toolkit-addon' ),
			'--lst-menu-line-h',
			$oc,
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

		$this->add_color_var_control( 'oc_line_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-menu-line-color', $oc, '#dd191a' );

		$this->add_size_var_control(
			'oc_line_gap',
			esc_html__( 'Gap to text', 'listemann-toolkit-addon' ),
			'--lst-menu-line-gap',
			$oc,
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

		$this->end_controls_section();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Control helpers
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

	/*
	 * ---------------------------------------------------------------------
	 * Render
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Render the widget output on the frontend.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$el_id    = sanitize_html_class( $this->get_id() );
		$key      = $this->get_instance_key();
		$nav_id   = 'lst-hdr-' . $key;
		$oc_id    = 'lst-oc-' . $key;

		$open_on    = isset( $settings['open_on'] ) && 'click' === $settings['open_on'] ? 'click' : 'hover';
		$chevrons   = $this->is_on( $settings, 'show_chevrons' );
		$sticky     = $this->is_on( $settings, 'sticky' );
		$mega_full  = isset( $settings['mega_width'] ) && 'full' === $settings['mega_width'];
		$breakpoint = $this->get_breakpoint( $settings );

		$classes = array(
			'lst-header',
			'lst-hdr-' . $el_id,
			'lst-header--open-' . $open_on,
			$chevrons ? 'lst-header--chevrons' : 'lst-header--no-chevrons',
		);

		if ( $mega_full ) {
			$classes[] = 'lst-header--mega-full';
		}

		if ( $sticky ) {
			$classes[] = 'lst-header--sticky';
		}

		$this->add_render_attribute(
			'root',
			array(
				'class'           => $classes,
				'data-lst-header' => wp_json_encode(
					array(
						'openOn'     => $open_on,
						'breakpoint' => $breakpoint,
						'sticky'     => $sticky,
						'offcanvas'  => $oc_id,
					)
				),
			)
		);

		$main_menu = $this->get_main_menu_html( $settings, $nav_id );
		$languages = $this->is_on( $settings, 'show_language' ) ? $this->get_languages() : array();
		$display   = isset( $settings['language_display'] ) && in_array( $settings['language_display'], array( 'flag_code', 'code', 'flag' ), true ) ? $settings['language_display'] : 'flag_code';

		echo '<div ' . $this->get_render_attribute_string( 'root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_render_attribute_string().

		$this->print_collapse_style( $el_id, $breakpoint );

		echo '<div class="lst-header__bar"><div class="lst-header__inner">';

		$this->render_logo( $settings );

		echo '<div class="lst-header__end">';

		echo $main_menu['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by wp_nav_menu() and Header_Nav_Walker (escaped like core) or an escaped editor notice.

		// WPML / Polylang may already add language items to the menu: do not show the switcher twice.
		if ( $languages && ! $main_menu['has_languages'] ) {
			$this->render_language_switcher( $languages, $display, $nav_id . '-lang' );
		}

		$this->render_hamburger( $oc_id );

		echo '</div></div></div>';

		$this->render_offcanvas( $settings, $oc_id, $el_id, $key, $languages, $display );

		echo '</div>';
	}

	/**
	 * Prints the media query that hides the menu and the language switcher
	 * below the collapse breakpoint, and always shows the hamburger there.
	 *
	 * Elementor cannot generate custom media queries, and a container query
	 * would measure the widget instead of the screen (a header in a boxed
	 * section would always collapse), so a small `<style>` scoped to this
	 * element is printed.
	 *
	 * The "Show hamburger" switch follows Elementor's device ranges, which do
	 * not line up with this breakpoint (desktop starts at 1025px, the default
	 * breakpoint is 1280px). Setting the property on the root, which sits
	 * inside the widget wrapper where Elementor sets it, keeps the hamburger
	 * whenever the menu is hidden.
	 *
	 * @param string $el_id      Sanitized element id.
	 * @param int    $breakpoint Breakpoint in px (0 = never collapse).
	 */
	private function print_collapse_style( $el_id, $breakpoint ) {
		if ( $breakpoint <= 0 ) {
			return;
		}

		printf(
			'<style>@media (max-width:%1$spx){.lst-hdr-%2$s .lst-header__nav,.lst-hdr-%2$s .lst-header__lang{display:none}.lst-hdr-%2$s{--lst-hdr-burger-display:inline-flex}}</style>',
			esc_html( number_format( $breakpoint - 0.02, 2, '.', '' ) ),
			esc_html( $el_id )
		);
	}

	/**
	 * Prints the logo box.
	 *
	 * @param array $settings Widget settings.
	 */
	private function render_logo( array $settings ) {
		$image = isset( $settings['logo'] ) && is_array( $settings['logo'] ) ? $settings['logo'] : array();

		if ( empty( $image['url'] ) ) {
			$image = $this->get_default_logo();
		}

		$alt = isset( $settings['logo_alt'] ) && is_string( $settings['logo_alt'] ) ? trim( $settings['logo_alt'] ) : '';

		if ( '' === $alt ) {
			$alt = get_bloginfo( 'name', 'display' );
		}

		$img = $this->get_image_html( $image, $alt, 'lst-header__logo-img' );

		echo '<div class="lst-header__brand"><div class="lst-header__logo-box">';

		if ( ! empty( $settings['logo_link']['url'] ) ) {
			$this->add_link_attributes( 'logo_link', $settings['logo_link'] );
			$this->add_render_attribute( 'logo_link', 'class', 'lst-header__logo' );

			echo '<a ' . $this->get_render_attribute_string( 'logo_link' ) . '>' . $img . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped by get_render_attribute_string(), image by get_image_html().
		} else {
			echo '<span class="lst-header__logo">' . $img . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_image_html().
		}

		echo '</div></div>';
	}

	/**
	 * Renders the main menu.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $nav_id   Id prefix of this instance.
	 * @return array {
	 *     @type string $html          The `<nav>` (or an editor notice).
	 *     @type bool   $has_languages Whether WPML / Polylang added language items.
	 * }
	 */
	private function get_main_menu_html( array $settings, $nav_id ) {
		$result = array(
			'html'          => '',
			'has_languages' => false,
		);

		$menu = $this->get_menu( isset( $settings['menu'] ) ? $settings['menu'] : '' );

		if ( ! $menu ) {
			if ( ! $this->get_menu_options() ) {
				$result['html'] = $this->get_editor_notice( __( 'There are no menus yet. Create one in Appearance > Menus.', 'listemann-toolkit-addon' ) );
			} elseif ( empty( $settings['menu'] ) ) {
				$result['html'] = $this->get_editor_notice( __( 'Please select a menu.', 'listemann-toolkit-addon' ) );
			} else {
				$result['html'] = $this->get_editor_notice( __( 'The selected menu no longer exists. Please select another menu.', 'listemann-toolkit-addon' ) );
			}

			return $result;
		}

		$html = wp_nav_menu(
			array(
				'menu'           => $menu->term_id,
				'theme_location' => '',
				'container'      => '',
				'menu_id'        => $nav_id . '-menu',
				'menu_class'     => 'lst-header__menu',
				'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
				'item_spacing'   => 'discard',
				'depth'          => 0,
				'echo'           => false,
				'fallback_cb'    => '__return_empty_string',
				'walker'         => new Header_Nav_Walker( array( 'id_prefix' => $nav_id ) ),
			)
		);

		if ( empty( $html ) ) {
			$result['html'] = $this->get_editor_notice( __( 'The selected menu has no items.', 'listemann-toolkit-addon' ) );
			return $result;
		}

		$label = isset( $settings['menu_aria_label'] ) && is_string( $settings['menu_aria_label'] ) ? trim( $settings['menu_aria_label'] ) : '';

		if ( '' === $label ) {
			$label = wp_specialchars_decode( $menu->name, ENT_QUOTES );
		}

		$result['html']          = '<nav class="lst-header__nav" aria-label="' . esc_attr( $label ) . '">' . $html . '</nav>';
		$result['has_languages'] = $this->has_language_items( $html );

		return $result;
	}

	/**
	 * Prints the built-in language switcher (current language + dropdown).
	 *
	 * @param array  $languages Normalized languages, see get_languages().
	 * @param string $display   flag_code, code or flag.
	 * @param string $id        Id of the dropdown.
	 */
	private function render_language_switcher( array $languages, $display, $id ) {
		$current = $this->get_current_language( $languages );
		$others  = array_filter(
			$languages,
			static function ( $language ) use ( $current ) {
				return $language['code'] !== $current['code'];
			}
		);

		$label = sprintf(
			/* translators: %s: Name of the current language. */
			__( 'Change language (current: %s)', 'listemann-toolkit-addon' ),
			$current['name']
		);

		// The visible code ("DE") starts the accessible name (WCAG 2.5.3), so
		// the description is screen-reader text after it, not an aria-label.
		if ( $this->shows_language_code( $current, $display ) ) {
			$label = ' – ' . $label;
		}

		echo '<div class="lst-header__lang"><ul class="lst-header__menu lst-header__menu--lang">';
		echo '<li class="lst-header__item lst-header__item--top lst-header__item--has-sub lst-header__item--lang menu-item-has-children">';

		printf(
			'<button type="button" class="lst-header__link lst-header__link--top lst-header__toggle-label lst-header__lang-toggle" aria-expanded="false" aria-controls="%1$s"><span class="lst-header__label">%2$s</span><span class="lst-header__sr">%3$s</span>%4$s</button>',
			esc_attr( $id ),
			$this->get_language_label_html( $current, $display ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_language_label_html().
			esc_html( $label ),
			Header_Nav_Walker::CHEVRON // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup.
		);

		printf( '<ul id="%s" class="sub-menu lst-header__sub lst-header__sub--depth-1 lst-header__dropdown">', esc_attr( $id ) );

		foreach ( $others as $language ) {
			printf(
				'<li class="lst-header__item lst-header__item--sub"><a class="lst-header__sublink" href="%1$s" hreflang="%2$s"><span class="lst-header__label">%3$s</span></a></li>',
				esc_url( $language['url'] ),
				esc_attr( $language['code'] ),
				$this->get_language_label_html( $language, $display, true ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_language_label_html().
			);
		}

		echo '</ul></li></ul></div>';
	}

	/**
	 * Label of a language: flag and/or code, plus the full name for screen
	 * readers where the visible text is not enough.
	 *
	 * The visible code stays in the accessible name and comes first ("PL
	 * Polski"), so speech input users can say what they see (WCAG 2.5.3).
	 *
	 * @param array  $language    Normalized language.
	 * @param string $display     flag_code, code or flag.
	 * @param bool   $with_sr_name Whether to add the full name for screen readers.
	 * @return string Escaped HTML.
	 */
	private function get_language_label_html( array $language, $display, $with_sr_name = false ) {
		$html      = '';
		$show_code = $this->shows_language_code( $language, $display );

		if ( 'code' !== $display && '' !== $language['flag'] ) {
			$html .= sprintf(
				'<img class="lst-header__flag" src="%s" alt="" width="18" height="12" decoding="async">',
				esc_url( $language['flag'] )
			);
		}

		if ( $show_code ) {
			$html .= sprintf(
				'<span class="lst-header__lang-code">%s</span>',
				esc_html( strtoupper( $language['code'] ) )
			);
		}

		if ( $with_sr_name ) {
			$html .= sprintf(
				'<span class="lst-header__sr" lang="%1$s">%2$s%3$s</span>',
				esc_attr( $language['code'] ),
				$show_code ? ' ' : '',
				esc_html( $language['name'] )
			);
		}

		return $html;
	}

	/**
	 * Whether a language label shows the language code as text.
	 *
	 * @param array  $language Normalized language.
	 * @param string $display  flag_code, code or flag.
	 * @return bool
	 */
	private function shows_language_code( array $language, $display ) {
		return 'flag' !== $display || '' === $language['flag'];
	}

	/**
	 * Prints the hamburger button.
	 *
	 * @param string $oc_id Id of the off-canvas dialog.
	 */
	private function render_hamburger( $oc_id ) {
		printf(
			'<button type="button" class="lst-header__burger" aria-controls="%1$s" aria-expanded="false" aria-haspopup="dialog"><span class="lst-header__burger-bar" aria-hidden="true"></span><span class="lst-header__sr">%2$s</span></button>',
			esc_attr( $oc_id ),
			esc_html__( 'Open menu', 'listemann-toolkit-addon' )
		);
	}

	/**
	 * Prints the off-canvas dialog.
	 *
	 * @param array  $settings  Widget settings.
	 * @param string $oc_id     Id of the dialog.
	 * @param string $el_id     Sanitized element id (style class).
	 * @param string $key       Instance key (id prefix of the menus).
	 * @param array  $languages Normalized languages (may be empty).
	 * @param string $display   Language display mode.
	 */
	private function render_offcanvas( array $settings, $oc_id, $el_id, $key, array $languages, $display ) {
		$label = isset( $settings['offcanvas_label'] ) && is_string( $settings['offcanvas_label'] ) ? trim( $settings['offcanvas_label'] ) : '';

		if ( '' === $label ) {
			$label = __( 'Menu', 'listemann-toolkit-addon' );
		}

		$source = isset( $settings['offcanvas_source'] ) && 'template' === $settings['offcanvas_source'] ? 'template' : 'menus';

		printf(
			'<div id="%1$s" class="lst-offcanvas lst-oc-%2$s lst-offcanvas--%3$s" role="dialog" aria-modal="true" aria-label="%4$s" inert>',
			esc_attr( $oc_id ),
			esc_attr( $el_id ),
			esc_attr( $source ),
			esc_attr( $label )
		);

		printf(
			'<button type="button" class="lst-offcanvas__close"><span class="lst-header__sr">%s</span></button>',
			esc_html__( 'Close menu', 'listemann-toolkit-addon' )
		);

		echo '<div class="lst-offcanvas__scroll">';

		if ( 'template' === $source ) {
			echo '<div class="lst-offcanvas__template">';
			echo $this->get_template_html( isset( $settings['offcanvas_template'] ) ? $settings['offcanvas_template'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor builder content or an escaped editor notice.
			echo '</div>';
		} else {
			$show_languages = $languages && $this->is_on( $settings, 'offcanvas_language' );

			echo '<div class="lst-offcanvas__inner">';
			$this->render_offcanvas_brand( $settings );
			$this->render_offcanvas_columns( $settings, $key, $show_languages ? $languages : array(), $display );
			echo '</div>';
		}

		echo '</div></div>';
	}

	/**
	 * Prints the off-canvas logo and social links column.
	 *
	 * @param array $settings Widget settings.
	 */
	private function render_offcanvas_brand( array $settings ) {
		$logo   = '';
		$social = $this->get_social_links_html( isset( $settings['social_links'] ) && is_array( $settings['social_links'] ) ? $settings['social_links'] : array() );

		if ( $this->is_on( $settings, 'offcanvas_show_logo' ) ) {
			$image = isset( $settings['offcanvas_logo'] ) && is_array( $settings['offcanvas_logo'] ) ? $settings['offcanvas_logo'] : array();

			if ( empty( $image['url'] ) ) {
				$image = array(
					'url' => LISTEMANN_TOOLKIT_URL . 'assets/img/listemann-logo-color.svg',
					'id'  => '',
				);
			}

			$logo = '<div class="lst-offcanvas__logo">' . $this->get_image_html( $image, get_bloginfo( 'name', 'display' ), 'lst-offcanvas__logo-img' ) . '</div>';
		}

		if ( '' === $logo && '' === $social ) {
			return;
		}

		echo '<div class="lst-offcanvas__brand">' . $logo . $social . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both parts are escaped by their builders.
	}

	/**
	 * Social links list.
	 *
	 * @param array $links Repeater items.
	 * @return string Escaped HTML ('' when there are no links).
	 */
	private function get_social_links_html( array $links ) {
		$labels = $this->get_network_labels();
		$items  = '';

		foreach ( $links as $index => $link ) {
			if ( empty( $link['link']['url'] ) ) {
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
			$this->add_render_attribute(
				$attr_key,
				array(
					'class'      => array( 'lst-offcanvas__social-link', 'lst-offcanvas__social-link--' . $network ),
					'aria-label' => $label,
				)
			);

			$items .= '<li><a ' . $this->get_render_attribute_string( $attr_key ) . '>' . $icon . '</a></li>';
		}

		return '' === $items ? '' : '<ul class="lst-offcanvas__social">' . $items . '</ul>';
	}

	/**
	 * Prints the off-canvas menu columns (and the language group).
	 *
	 * @param array  $settings  Widget settings.
	 * @param string $key       Instance key.
	 * @param array  $languages Languages to add as a group ('' = none).
	 * @param string $display   Language display mode.
	 */
	private function render_offcanvas_columns( array $settings, $key, array $languages, $display ) {
		$columns        = isset( $settings['offcanvas_columns'] ) && is_array( $settings['offcanvas_columns'] ) ? $settings['offcanvas_columns'] : array();
		$output         = array();
		$has_wpml_items = false;

		foreach ( array_values( $columns ) as $index => $column ) {
			$menu = $this->get_menu( isset( $column['menu'] ) ? $column['menu'] : '' );

			if ( ! $menu ) {
				$notice = $this->get_editor_notice( __( 'Select a menu for this off-canvas column.', 'listemann-toolkit-addon' ) );

				if ( '' !== $notice ) {
					$output[] = $notice;
				}

				continue;
			}

			$prefix = 'lst-oc-' . $key . '-' . ( $index + 1 );
			$html   = wp_nav_menu(
				array(
					'menu'           => $menu->term_id,
					'theme_location' => '',
					'container'      => '',
					'menu_id'        => $prefix,
					'menu_class'     => 'lst-menu__list',
					'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
					'item_spacing'   => 'discard',
					'depth'          => 0,
					'echo'           => false,
					'fallback_cb'    => '__return_empty_string',
					'walker'         => new Menu_Walker(
						array(
							'headings'     => true,
							'hash_as_text' => true,
							'line'         => 'sub',
							'id_prefix'    => $prefix,
							'depth'        => 0,
						)
					),
				)
			);

			if ( empty( $html ) ) {
				continue;
			}

			$has_wpml_items = $has_wpml_items || $this->has_language_items( $html );

			$label = isset( $column['label'] ) && is_string( $column['label'] ) ? trim( $column['label'] ) : '';

			if ( '' === $label ) {
				$label = wp_specialchars_decode( $menu->name, ENT_QUOTES );
			}

			$output[] = sprintf(
				'<nav class="lst-menu lst-menu--vertical lst-menu--headings lst-menu--line-sub" aria-label="%1$s">%2$s</nav>',
				esc_attr( $label ),
				$html
			);
		}

		if ( $languages && ! $has_wpml_items ) {
			$output[] = $this->get_offcanvas_languages_html( $languages, $display );
		}

		if ( ! $output ) {
			return;
		}

		// The language group joins the last menu column, as on listemann.com.
		$cells = $output;

		if ( $languages && ! $has_wpml_items && count( $cells ) > 1 ) {
			$lang    = array_pop( $cells );
			$last    = array_pop( $cells );
			$cells[] = $last . $lang;
		}

		foreach ( $cells as $cell ) {
			echo '<div class="lst-offcanvas__col">' . $cell . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by wp_nav_menu() / Menu_Walker or escaped builders.
		}
	}

	/**
	 * Language group for the off-canvas (styled like the menu columns).
	 *
	 * @param array  $languages Normalized languages.
	 * @param string $display   Language display mode.
	 * @return string Escaped HTML.
	 */
	private function get_offcanvas_languages_html( array $languages, $display ) {
		$current = $this->get_current_language( $languages );
		$items   = '';

		foreach ( $languages as $language ) {
			if ( $language['code'] === $current['code'] ) {
				continue;
			}

			$items .= sprintf(
				'<li class="lst-menu__item lst-menu__item--sub lst-menu__item--depth-1"><a class="lst-menu__link lst-menu__link--line" href="%1$s" hreflang="%2$s"><span class="lst-menu__label">%3$s</span></a></li>',
				esc_url( $language['url'] ),
				esc_attr( $language['code'] ),
				$this->get_language_label_html( $language, $display, true )
			);
		}

		return sprintf(
			'<nav class="lst-menu lst-menu--vertical lst-menu--headings lst-menu--line-sub lst-offcanvas__lang" aria-label="%1$s"><ul class="lst-menu__list"><li class="lst-menu__item lst-menu__item--top lst-menu__item--depth-0 lst-menu__item--has-sub lst-menu__item--heading"><span class="lst-menu__link lst-menu__text"><span class="lst-menu__label">%2$s</span></span><ul class="lst-menu__sub lst-menu__sub--depth-1">%3$s</ul></li></ul></nav>',
			esc_attr__( 'Language', 'listemann-toolkit-addon' ),
			$this->get_language_label_html( $current, $display, true ),
			$items
		);
	}

	/**
	 * Builder content of the off-canvas template, with a recursion guard.
	 *
	 * @param mixed $template_id Saved setting.
	 * @return string HTML.
	 */
	private function get_template_html( $template_id ) {
		$template_id = $this->get_id_from_key( $template_id, self::TEMPLATE_KEY_PREFIX );

		if ( ! $template_id ) {
			return $this->get_editor_notice( __( 'Please select an Elementor template for the off-canvas.', 'listemann-toolkit-addon' ) );
		}

		$document = Plugin::$instance->documents->get_current();
		$current  = $document ? (int) $document->get_main_id() : 0;

		if ( isset( self::$rendering_templates[ $template_id ] ) || $template_id === $current || 'publish' !== get_post_status( $template_id ) ) {
			return $this->get_editor_notice( __( 'This template cannot be shown here (it is not published, or it contains this header).', 'listemann-toolkit-addon' ) );
		}

		self::$rendering_templates[ $template_id ] = true;

		$html = Plugin::$instance->frontend->get_builder_content_for_display( $template_id );

		unset( self::$rendering_templates[ $template_id ] );

		return is_string( $html ) ? $html : '';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Data helpers
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
	 * Collapse breakpoint in px (empty = default, 0 = never).
	 *
	 * @param array $settings Settings.
	 * @return int
	 */
	private function get_breakpoint( array $settings ) {
		if ( ! isset( $settings['collapse_breakpoint'] ) || '' === $settings['collapse_breakpoint'] || ! is_numeric( $settings['collapse_breakpoint'] ) ) {
			return self::DEFAULT_BREAKPOINT;
		}

		return min( 5000, max( 0, (int) $settings['collapse_breakpoint'] ) );
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
	 * Default logo: the site's custom logo, else the bundled one.
	 *
	 * @return array MEDIA value.
	 */
	private function get_default_logo() {
		$custom_logo = (int) get_theme_mod( 'custom_logo' );

		if ( $custom_logo ) {
			$url = wp_get_attachment_image_url( $custom_logo, 'full' );

			if ( $url ) {
				return array(
					'url' => $url,
					'id'  => $custom_logo,
				);
			}
		}

		return array(
			'url' => LISTEMANN_TOOLKIT_URL . 'assets/img/listemann-logo.svg',
			'id'  => '',
		);
	}

	/**
	 * `<img>` for a MEDIA value, with intrinsic size when it is known.
	 *
	 * @param array  $image MEDIA value (url, id).
	 * @param string $alt   Alternative text.
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

		foreach ( self::BUNDLED_IMAGES as $file => $size ) {
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
	 * Options for the template control (editor only: the list is not
	 * needed to render the page, so the front end skips the query).
	 *
	 * @return string[] "template-{post ID}" => title (type), sorted by title
	 *                  (see MENU_KEY_PREFIX).
	 */
	private function get_template_options() {
		if ( ! is_admin() ) {
			return array();
		}

		$options = array();
		$posts   = get_posts(
			array(
				'post_type'        => 'elementor_library',
				'post_status'      => 'publish',
				'numberposts'      => 100,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Editor only; excludes the site kit.
					array(
						'key'     => '_elementor_template_type',
						'value'   => array( 'kit' ),
						'compare' => 'NOT IN',
					),
				),
			)
		);

		foreach ( $posts as $post ) {
			$type  = (string) get_post_meta( $post->ID, '_elementor_template_type', true );
			$title = '' !== $post->post_title ? $post->post_title : sprintf( '#%d', $post->ID );

			$options[ self::TEMPLATE_KEY_PREFIX . $post->ID ] = esc_html( '' !== $type ? sprintf( '%1$s (%2$s)', $title, $type ) : $title );
		}

		return $options;
	}

	/**
	 * Labels of the networks with a bundled icon.
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
	 * Bundled network icon as inline SVG (currentColor).
	 *
	 * @param string $network Network key.
	 * @return string Sanitized SVG ('' when unknown).
	 */
	private function get_network_icon( $network ) {
		if ( ! in_array( $network, self::SOCIAL_NETWORKS, true ) ) {
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

	/**
	 * Whether WPML or Polylang is active.
	 *
	 * @return bool
	 */
	private function has_multilingual_plugin() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_the_languages' ) || has_filter( 'wpml_active_languages' );
	}

	/**
	 * Languages from WPML or Polylang, normalized.
	 *
	 * @return array[] List of { code, name, url, flag, current }; empty when
	 *                 fewer than two languages are available.
	 */
	private function get_languages() {
		$languages = array();
		$wpml      = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );

		if ( is_array( $wpml ) && $wpml ) {
			foreach ( $wpml as $code => $language ) {
				if ( ! is_array( $language ) ) {
					continue;
				}

				$languages[] = array(
					'code'    => isset( $language['language_code'] ) ? $language['language_code'] : $code,
					'name'    => ! empty( $language['native_name'] ) ? $language['native_name'] : ( ! empty( $language['translated_name'] ) ? $language['translated_name'] : $code ),
					'url'     => isset( $language['url'] ) ? $language['url'] : '',
					'flag'    => isset( $language['country_flag_url'] ) ? $language['country_flag_url'] : '',
					'current' => ! empty( $language['active'] ),
				);
			}
		} elseif ( function_exists( 'pll_the_languages' ) ) {
			$polylang = pll_the_languages(
				array(
					'raw'           => 1,
					'hide_if_empty' => 0,
				)
			);

			if ( is_array( $polylang ) ) {
				foreach ( $polylang as $language ) {
					if ( ! is_array( $language ) || empty( $language['slug'] ) ) {
						continue;
					}

					$languages[] = array(
						'code'    => $language['slug'],
						'name'    => ! empty( $language['name'] ) ? $language['name'] : $language['slug'],
						'url'     => isset( $language['url'] ) ? $language['url'] : '',
						'flag'    => isset( $language['flag'] ) && is_string( $language['flag'] ) ? $language['flag'] : '',
						'current' => ! empty( $language['current_lang'] ),
					);
				}
			}
		}

		/**
		 * Filters the languages of the Listemann Header language switcher.
		 *
		 * @param array[] $languages List of { code, name, url, flag, current }.
		 */
		$languages = apply_filters( 'listemann_toolkit_header_languages', $languages );

		$valid = array();

		foreach ( (array) $languages as $language ) {
			if ( ! is_array( $language ) || empty( $language['code'] ) || empty( $language['url'] ) ) {
				continue;
			}

			$valid[] = array(
				'code'    => sanitize_text_field( (string) $language['code'] ),
				'name'    => sanitize_text_field( (string) ( isset( $language['name'] ) && '' !== $language['name'] ? $language['name'] : $language['code'] ) ),
				'url'     => (string) $language['url'],
				'flag'    => isset( $language['flag'] ) ? (string) $language['flag'] : '',
				'current' => ! empty( $language['current'] ),
			);
		}

		return count( $valid ) > 1 ? $valid : array();
	}

	/**
	 * The current language (the first one when none is flagged).
	 *
	 * @param array[] $languages Normalized languages (not empty).
	 * @return array
	 */
	private function get_current_language( array $languages ) {
		foreach ( $languages as $language ) {
			if ( $language['current'] ) {
				return $language;
			}
		}

		return reset( $languages );
	}

	/**
	 * Whether menu HTML contains language items added by WPML or Polylang.
	 *
	 * @param string $html Menu HTML.
	 * @return bool
	 */
	private function has_language_items( $html ) {
		return (bool) preg_match( '/class="[^"]*\b(?:wpml-ls-menu-item|lang-item)\b/', $html );
	}

	/**
	 * A notice for editors (Elementor editor / preview only).
	 *
	 * @param string $message Plain-text message (escaped here).
	 * @return string Escaped HTML, '' on the public front end.
	 */
	private function get_editor_notice( $message ) {
		$plugin = Plugin::$instance;

		if ( ! $plugin->editor->is_edit_mode() && ! $plugin->preview->is_preview_mode() ) {
			return '';
		}

		return sprintf( '<div class="lst-header-notice" role="note">%s</div>', esc_html( $message ) );
	}
}
