<?php
/**
 * "Listemann Footer" Elementor widget.
 *
 * Recreates the listemann.com footer: the colour logo with the social icons
 * below it, the footer menus in columns (headings and items with the red
 * line, styled like the Listemann Menu widget) and an optional bottom bar
 * with a copyright line and legal links.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Plugin as Elementor;
use Elementor\Repeater;
use Elementor\Widget_Base;
use Listemann_Toolkit\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Footer widget: logo, social links, menu columns and a bottom bar.
 *
 * Markup overview (classes used by assets/css/footer.css; the menus use
 * assets/css/menu.css and the Listemann Menu walker):
 *
 *     div.lst-footer
 *       div.lst-footer__top                     (flex row; grid below 1280px)
 *         div.lst-footer__brand
 *           div.lst-footer__logo > a.lst-footer__logo-link > img
 *           ul.lst-footer__social > li > a.lst-footer__social-link > svg
 *         div.lst-footer__col > nav.lst-menu[aria-label] > ul.lst-menu__list   (one per menu)
 *       div.lst-footer__bottom                  (optional)
 *         p.lst-footer__copy, nav.lst-menu.lst-menu--horizontal.lst-footer__legal
 *
 * The `<footer>` landmark is the theme's (or the Theme Builder template's)
 * job; the widget only renders its content. Style controls write CSS custom
 * properties; values left empty fall back to the original's steps at 1280px
 * and 768px (see footer.css).
 */
class Footer extends Widget_Base {

	use Widget_Helpers;

	/**
	 * Handle of the widget stylesheet (registered by the plugin bootstrap).
	 */
	const STYLE_HANDLE = 'listemann-toolkit-footer';

	/**
	 * Handle of the Listemann Menu stylesheet, used for the menu columns.
	 */
	const MENU_STYLE_HANDLE = 'listemann-toolkit-menu';

	/**
	 * Prefix of the option keys of the menu controls ("menu-15"), see
	 * Header::MENU_KEY_PREFIX.
	 */
	const MENU_KEY_PREFIX = 'menu-';

	/**
	 * Allowed values of the depth control (0 = all levels).
	 */
	const DEPTHS = array( '0', '1', '2', '3' );

	/**
	 * Token of the bottom bar text that becomes the current year.
	 */
	const YEAR_TOKEN = '{year}';

	/**
	 * Maximum number of menu columns preset from the "Footer …" menus.
	 */
	const MAX_DEFAULT_COLUMNS = 4;

	/**
	 * Widget name (slug).
	 *
	 * @return string
	 */
	public function get_name() {
		return 'listemann-footer';
	}

	/**
	 * Widget title shown in the panel.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'Listemann Footer', 'listemann-toolkit-addon' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-footer';
	}

	/**
	 * Panel categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( Plugin::CATEGORY );
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'footer', 'fusszeile', 'fußzeile', 'menu', 'social', 'logo', 'copyright', 'listemann' );
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
	 * Drop the `.elementor-widget-container` wrapper when Elementor's
	 * optimized markup is active. The widget CSS works with and without it.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper(): bool {
		return ! Elementor::$instance->experiments->is_feature_active( 'e_optimized_markup' );
	}

	/**
	 * The output depends on the current URL (current-menu-item classes) and
	 * the date ({year}), so it must never be served from Elementor's element
	 * cache.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Keep the footer out of the plain-text post content Elementor saves.
	 */
	public function render_plain_content() {}

	/**
	 * Register the widget controls.
	 */
	protected function register_controls() {
		$this->register_logo_controls();
		$this->register_social_controls();
		$this->register_menu_controls();
		$this->register_bottom_controls();

		$this->register_footer_style_controls();
		$this->register_layout_style_controls();
		$this->register_brand_style_controls();
		$this->register_menu_style_controls();
		$this->register_bottom_style_controls();
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
			'show_logo',
			array(
				'label'        => esc_html__( 'Show logo', 'listemann-toolkit-addon' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$condition = array( 'show_logo' => 'yes' );

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
				'description' => esc_html__( 'Defaults to the bundled Listemann colour logo.', 'listemann-toolkit-addon' ),
				'condition'   => $condition,
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
				'condition'   => $condition,
			)
		);

		$this->add_control(
			'logo_alt',
			array(
				'label'     => esc_html__( 'Alternative text', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => get_bloginfo( 'name', 'display' ),
				'dynamic'   => array(
					'active' => true,
				),
				'condition' => $condition,
			)
		);

		$this->add_size_var_control(
			'logo_width',
			esc_html__( 'Logo width', 'listemann-toolkit-addon' ),
			'--lst-ft-logo-w',
			'{{WRAPPER}}',
			array( 125 ),
			array(
				'range'     => array(
					'px' => array(
						'min' => 20,
						'max' => 400,
					),
				),
				'condition' => $condition,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: social links.
	 */
	private function register_social_controls() {
		$this->start_controls_section(
			'section_social',
			array(
				'label' => esc_html__( 'Social links', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'social_links',
			array(
				'label'         => esc_html__( 'Social links', 'listemann-toolkit-addon' ),
				'type'          => Controls_Manager::REPEATER,
				'fields'        => $this->get_social_repeater_fields(),
				'default'       => $this->get_default_social_links(),
				'prevent_empty' => false,
				'title_field'   => $this->get_social_title_field(),
			)
		);

		$this->add_control(
			'social_new_tab',
			array(
				'label'        => esc_html__( 'Open in New Tab', 'listemann-toolkit-addon' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => esc_html__( 'Applies to all social links. Screen readers announce it. Off: only links with "Open in new window" ticked open in a new tab.', 'listemann-toolkit-addon' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: menu columns.
	 */
	private function register_menu_controls() {
		$this->start_controls_section(
			'section_menus',
			array(
				'label' => esc_html__( 'Menu columns', 'listemann-toolkit-addon' ),
			)
		);

		$menus    = $this->get_menu_options();
		$defaults = $this->get_default_columns();

		if ( ! $menus ) {
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
		} elseif ( ! $defaults ) {
			$this->add_control(
				'menu_defaults_notice',
				array(
					'type'            => Controls_Manager::RAW_HTML,
					'raw'             => sprintf(
						/* translators: 1: Link open tag, 2: Link close tag. */
						esc_html__( 'No menus named "Footer …" were found, so no columns are preset. Add a column per menu below, or %1$screate the footer menus%2$s in Appearance > Menus and reload the editor.', 'listemann-toolkit-addon' ),
						'<a href="' . esc_url( admin_url( 'nav-menus.php?action=edit&menu=0' ) ) . '" target="_blank">',
						'</a>'
					),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
				)
			);
		}

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
				'placeholder' => esc_html__( 'Headings of the menu', 'listemann-toolkit-addon' ),
				'description' => esc_html__( 'Label of the navigation landmark for screen readers. Leave empty to use the headings of the menu (or its name if it has none).', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'menu_columns',
			array(
				'label'         => esc_html__( 'Menu columns', 'listemann-toolkit-addon' ),
				'type'          => Controls_Manager::REPEATER,
				'fields'        => $columns->get_controls(),
				'default'       => $defaults,
				'prevent_empty' => false,
				'title_field'   => $this->get_menu_title_field( $menus ),
				'description'   => sprintf(
					/* translators: 1: Link open tag, 2: Link close tag. */
					esc_html__( 'Each column shows one menu: top-level items are headings, their sub-items get the red line. Preset: the menus named "Footer 1", "Footer 2" … (up to four). %1$sManage menus%2$s', 'listemann-toolkit-addon' ),
					'<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '" target="_blank">',
					'</a>'
				),
			)
		);

		$this->add_control(
			'menu_depth',
			array(
				'label'   => esc_html__( 'Depth', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'0' => esc_html__( 'All levels', 'listemann-toolkit-addon' ),
					'1' => esc_html__( '1 level', 'listemann-toolkit-addon' ),
					'2' => esc_html__( '2 levels', 'listemann-toolkit-addon' ),
					'3' => esc_html__( '3 levels', 'listemann-toolkit-addon' ),
				),
				'default' => '0',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: bottom bar.
	 */
	private function register_bottom_controls() {
		$this->start_controls_section(
			'section_bottom',
			array(
				'label' => esc_html__( 'Bottom bar', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'show_bottom',
			array(
				'label'        => esc_html__( 'Show bottom bar', 'listemann-toolkit-addon' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'description'  => esc_html__( 'A line below the menus with a copyright text and, optionally, legal links (not on listemann.com).', 'listemann-toolkit-addon' ),
			)
		);

		$condition = array( 'show_bottom' => 'yes' );

		$this->add_control(
			'bottom_text',
			array(
				'label'       => esc_html__( 'Text', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '© ' . self::YEAR_TOKEN . ' Listemann AG',
				'label_block' => true,
				'dynamic'     => array(
					'active' => true,
				),
				'description' => esc_html__( '{year} becomes the current year.', 'listemann-toolkit-addon' ),
				'condition'   => $condition,
			)
		);

		$this->add_control(
			'bottom_menu',
			array(
				'label'       => esc_html__( 'Legal links menu', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array( '' => esc_html__( '— None —', 'listemann-toolkit-addon' ) ) + $this->get_menu_options(),
				'default'     => '',
				'description' => esc_html__( 'Its top-level items are shown in a row, e.g. Impressum and Datenschutz.', 'listemann-toolkit-addon' ),
				'condition'   => $condition,
			)
		);

		$this->add_control(
			'bottom_menu_label',
			array(
				'label'       => esc_html__( 'Accessible label', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => esc_html__( 'Legal links', 'listemann-toolkit-addon' ),
				'description' => esc_html(
					sprintf(
						/* translators: %s: Default label ("Legal links"). */
						__( 'Label of the navigation landmark for screen readers. Leave empty to use "%s".', 'listemann-toolkit-addon' ),
						__( 'Legal links', 'listemann-toolkit-addon' )
					)
				),
				'condition'   => array(
					'show_bottom'  => 'yes',
					'bottom_menu!' => '',
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
	 * Style tab: footer box (background, padding).
	 */
	private function register_footer_style_controls() {
		$this->start_controls_section(
			'section_style_footer',
			array(
				'label' => esc_html__( 'Footer', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_color_var_control( 'background_color', esc_html__( 'Background color', 'listemann-toolkit-addon' ), '--lst-ft-bg', '{{WRAPPER}}', '#ffffff' );

		$this->add_responsive_control(
			'padding',
			array(
				'label'       => esc_html__( 'Padding', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', 'em', 'rem', '%' ),
				'placeholder' => array(
					'top'    => '150',
					'right'  => '0',
					'bottom' => '150',
					'left'   => '0',
				),
				'description' => esc_html__( 'Left empty: 150 px above and below; 75 px above and below under 1280 px; 75 px above and 50 px below on mobile, like the original. A value also applies to smaller screens that have no value of their own.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-ft-pad: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: layout of the columns.
	 */
	private function register_layout_style_controls() {
		$this->start_controls_section(
			'section_style_layout',
			array(
				'label' => esc_html__( 'Layout', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'column_gap',
			array(
				'label'       => esc_html__( 'Column gap', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
				'description' => esc_html__( 'Left empty: at least 30 px on desktop, where the logo and the menus are spread across the width; none below 1280 px, where the menus share the width equally, like the original.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-ft-col-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'row_gap',
			array(
				'label'       => esc_html__( 'Row gap', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
				'description' => esc_html__( 'Space between the rows below 1280 px, including the row with the logo. Left empty: 25 px; on mobile 30 px between the menus, like the original.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-ft-row-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'tablet_columns',
			array(
				'label'       => esc_html__( 'Columns below 1280 px', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 6,
				'step'        => 1,
				'placeholder' => 2,
				'separator'   => 'before',
				'description' => esc_html__( 'Below 1280 px the logo and the social icons share the first row and the menus wrap into this many columns. Left empty: 2.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-ft-cols-tablet: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'mobile_columns',
			array(
				'label'       => esc_html__( 'Columns below 768 px', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 4,
				'step'        => 1,
				'placeholder' => 1,
				'description' => esc_html__( 'Left empty: 1.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-ft-cols-mobile: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: logo and social icons.
	 */
	private function register_brand_style_controls() {
		$this->start_controls_section(
			'section_style_social',
			array(
				'label' => esc_html__( 'Social icons', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		// listemann.com uses #a8a8a8 (2.4:1 on white); #949494 is the lightest grey with the 3:1 that icons and their focus outline need (WCAG 1.4.11), as in the header.
		$this->add_color_var_control( 'social_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-ft-social-color', '{{WRAPPER}}', '#949494' );
		$this->add_color_var_control( 'social_hover_color', esc_html__( 'Hover color', 'listemann-toolkit-addon' ), '--lst-ft-social-hover-color', '{{WRAPPER}}', '' );

		$this->add_size_var_control(
			'social_size',
			esc_html__( 'Icon height', 'listemann-toolkit-addon' ),
			'--lst-ft-social-h',
			'{{WRAPPER}}',
			20,
			array(
				'range' => array(
					'px' => array(
						'min' => 8,
						'max' => 80,
					),
				),
			)
		);

		$this->add_size_var_control(
			'social_spacing',
			esc_html__( 'Space beside each icon', 'listemann-toolkit-addon' ),
			'--lst-ft-social-pad',
			'{{WRAPPER}}',
			10,
			array(
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'description' => esc_html__( 'Left and right of every icon (it also enlarges the clickable area).', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_size_var_control(
			'social_offset',
			esc_html__( 'Space above the icons', 'listemann-toolkit-addon' ),
			'--lst-ft-social-top',
			'{{WRAPPER}}',
			50,
			array(
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
				'description' => esc_html__( 'Between the logo and the icons on desktop (the original\'s 40 px plus the gap below its inline logo). Below 1280 px the icons sit to the right of the logo.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'social_hover_scale',
			array(
				'label'     => esc_html__( 'Hover zoom', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 2,
				'step'      => 0.05,
				'default'   => 1.25,
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-ft-social-scale: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: menu headings, items and the line before items (the same
	 * controls and defaults as the header's off-canvas menu columns).
	 */
	private function register_menu_style_controls() {
		$top = '{{WRAPPER}} .lst-footer__top';

		$this->start_controls_section(
			'section_style_headings',
			array(
				'label' => esc_html__( 'Menu headings', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_menu_heading_style_controls( 'menu_', $top );

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_items',
			array(
				'label' => esc_html__( 'Menu items', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_menu_item_style_controls( 'menu_', $top );

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_line',
			array(
				'label' => esc_html__( 'Line before items', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_menu_line_style_controls( 'menu_', $top );

		$this->end_controls_section();
	}

	/**
	 * Style tab: bottom bar.
	 */
	private function register_bottom_style_controls() {
		$condition = array( 'show_bottom' => 'yes' );

		$this->start_controls_section(
			'section_style_bottom',
			array(
				'label'     => esc_html__( 'Bottom bar', 'listemann-toolkit-addon' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => $condition,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'bottom_typography',
				'selector'       => '{{WRAPPER}} .lst-footer__bottom',
				'fields_options' => $this->typography_defaults( 14, '400', 'none', 24 ),
			)
		);

		$this->add_color_var_control( 'bottom_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), '--lst-ft-bottom-color', '{{WRAPPER}}', '#626262' );
		$this->add_color_var_control( 'bottom_hover_color', esc_html__( 'Link hover color', 'listemann-toolkit-addon' ), '--lst-ft-bottom-hover-color', '{{WRAPPER}}', '#dd191a' );

		$this->add_size_var_control(
			'bottom_border_width',
			esc_html__( 'Top border width', 'listemann-toolkit-addon' ),
			'--lst-ft-bottom-border-w',
			'{{WRAPPER}}',
			1,
			array(
				'range'     => array(
					'px' => array(
						'min' => 0,
						'max' => 10,
					),
				),
				'separator' => 'before',
			)
		);

		$this->add_color_var_control( 'bottom_border_color', esc_html__( 'Top border color', 'listemann-toolkit-addon' ), '--lst-ft-bottom-border-color', '{{WRAPPER}}', '#e5e5e5' );

		$this->add_size_var_control(
			'bottom_spacing',
			esc_html__( 'Space above', 'listemann-toolkit-addon' ),
			'--lst-ft-bottom-gap',
			'{{WRAPPER}}',
			50,
			array(
				'range' => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
			)
		);

		$this->add_responsive_control(
			'bottom_padding',
			array(
				'label'      => esc_html__( 'Padding', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'default'    => array(
					'top'      => '25',
					'right'    => '0',
					'bottom'   => '0',
					'left'     => '0',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-ft-bottom-pad: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
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
		$key      = $this->get_instance_key();
		$brand    = $this->get_brand_html( $settings );
		$columns  = $this->get_columns_html( $settings, $key );
		$bottom   = $this->is_on( $settings, 'show_bottom' ) ? $this->get_bottom_html( $settings, $key ) : '';

		if ( '' === $brand && ! $columns && '' === $bottom ) {
			echo $this->get_editor_notice( __( 'The footer is empty: show the logo, or add social links or menu columns.', 'listemann-toolkit-addon' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_editor_notice().
			return;
		}

		echo '<div class="lst-footer">';

		if ( '' !== $brand || $columns ) {
			echo '<div class="lst-footer__top">';
			echo $brand; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_brand_html().

			foreach ( $columns as $column ) {
				echo '<div class="lst-footer__col">' . $column . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by wp_nav_menu() / Menu_Walker (escaped like core) or an escaped editor notice.
			}

			echo '</div>';
		}

		echo $bottom; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_bottom_html().
		echo '</div>';
	}

	/**
	 * Logo and social links.
	 *
	 * @param array $settings Widget settings.
	 * @return string Escaped HTML ('' when both are off or empty).
	 */
	private function get_brand_html( array $settings ) {
		$logo   = '';
		$social = $this->get_social_links_html(
			isset( $settings['social_links'] ) && is_array( $settings['social_links'] ) ? $settings['social_links'] : array(),
			'lst-footer',
			$this->is_on( $settings, 'social_new_tab' )
		);

		if ( $this->is_on( $settings, 'show_logo' ) ) {
			$image = isset( $settings['logo'] ) && is_array( $settings['logo'] ) ? $settings['logo'] : array();

			if ( empty( $image['url'] ) ) {
				$image = $this->get_default_logo();
			}

			$alt = isset( $settings['logo_alt'] ) && is_string( $settings['logo_alt'] ) ? trim( $settings['logo_alt'] ) : '';

			if ( '' === $alt ) {
				$alt = get_bloginfo( 'name', 'display' );
			}

			$img = $this->get_image_html( $image, $alt, 'lst-footer__logo-img' );

			if ( ! empty( $settings['logo_link']['url'] ) && is_array( $settings['logo_link'] ) ) {
				$this->add_link_attributes( 'logo_link', $settings['logo_link'] );
				$this->add_render_attribute( 'logo_link', 'class', 'lst-footer__logo-link' );

				$logo = '<a ' . $this->get_render_attribute_string( 'logo_link' ) . '>' . $img . '</a>';
			} else {
				$logo = '<span class="lst-footer__logo-link">' . $img . '</span>';
			}

			$logo = '<div class="lst-footer__logo">' . $logo . '</div>';
		}

		if ( '' === $logo && '' === $social ) {
			return '';
		}

		return '<div class="lst-footer__brand">' . $logo . $social . '</div>';
	}

	/**
	 * The menu columns (and editor notices for columns that show nothing).
	 *
	 * @param array  $settings Widget settings.
	 * @param string $key      Instance key (id prefix of the menus).
	 * @return string[] HTML of each column.
	 */
	private function get_columns_html( array $settings, $key ) {
		$columns = isset( $settings['menu_columns'] ) && is_array( $settings['menu_columns'] ) ? $settings['menu_columns'] : array();
		$depth   = isset( $settings['menu_depth'] ) && in_array( (string) $settings['menu_depth'], self::DEPTHS, true ) ? (int) $settings['menu_depth'] : 0;
		$cells   = array();

		foreach ( array_values( $columns ) as $index => $column ) {
			if ( ! is_array( $column ) ) {
				continue;
			}

			$menu = $this->get_menu( isset( $column['menu'] ) ? $column['menu'] : '' );
			$html = '';

			if ( $menu ) {
				$label = isset( $column['label'] ) && is_string( $column['label'] ) ? trim( $column['label'] ) : '';
				$label = '' === $label ? $this->get_menu_headings_label( $menu ) : $label;
				$html  = $this->get_menu_nav_html( $menu, 'lst-ft-' . $key . '-' . ( $index + 1 ), $label, array( 'depth' => $depth ) );

				if ( '' === $html ) {
					$html = $this->get_editor_notice( __( 'The selected menu has no items.', 'listemann-toolkit-addon' ) );
				}
			} elseif ( empty( $column['menu'] ) ) {
				$html = $this->get_editor_notice( __( 'Select a menu for this column.', 'listemann-toolkit-addon' ) );
			} else {
				$html = $this->get_editor_notice( __( 'The selected menu no longer exists. Please select another menu.', 'listemann-toolkit-addon' ) );
			}

			if ( '' !== $html ) {
				$cells[] = $html;
			}
		}

		return $cells;
	}

	/**
	 * Accessible name of a column without its own label: the menu's headings
	 * (top-level items with sub-items), e.g. "Verfahren, Einsatzgebiete". The
	 * preset menus are picked by their names ("Footer 1" …), which say nothing
	 * about their content.
	 *
	 * @param \WP_Term $menu Menu.
	 * @return string Plain text ('' = no headings, the menu name is used).
	 */
	private function get_menu_headings_label( \WP_Term $menu ) {
		$items = wp_get_nav_menu_items( $menu->term_id );

		if ( ! is_array( $items ) ) {
			return '';
		}

		$parents = array();

		foreach ( $items as $item ) {
			$parents[ (int) $item->menu_item_parent ] = true;
		}

		$titles = array();

		foreach ( $items as $item ) {
			if ( 0 === (int) $item->menu_item_parent && isset( $parents[ (int) $item->ID ] ) ) {
				$title = trim( wp_specialchars_decode( wp_strip_all_tags( $item->title ), ENT_QUOTES ) );

				if ( '' !== $title ) {
					$titles[] = $title;
				}
			}
		}

		return implode( ', ', $titles );
	}

	/**
	 * The bottom bar: text ({year} replaced) and the legal links menu.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $key      Instance key (id prefix of the menu).
	 * @return string Escaped HTML ('' when both are empty).
	 */
	private function get_bottom_html( array $settings, $key ) {
		$html = '';
		$text = isset( $settings['bottom_text'] ) && is_string( $settings['bottom_text'] ) ? trim( $settings['bottom_text'] ) : '';

		if ( '' !== $text ) {
			$html .= '<p class="lst-footer__copy">' . wp_kses_post( str_replace( self::YEAR_TOKEN, wp_date( 'Y' ), $text ) ) . '</p>';
		}

		$value = isset( $settings['bottom_menu'] ) ? $settings['bottom_menu'] : '';
		$menu  = $this->get_menu( $value );

		if ( $menu ) {
			$label = isset( $settings['bottom_menu_label'] ) && is_string( $settings['bottom_menu_label'] ) ? trim( $settings['bottom_menu_label'] ) : '';
			$label = '' === $label ? __( 'Legal links', 'listemann-toolkit-addon' ) : $label;
			$html .= $this->get_menu_nav_html(
				$menu,
				'lst-ft-' . $key . '-legal',
				$label,
				array(
					'depth'    => 1,
					'headings' => false,
					'line'     => '',
					'class'    => 'lst-menu lst-menu--horizontal lst-footer__legal',
				)
			);
		} elseif ( is_scalar( $value ) && '' !== (string) $value ) {
			$html .= $this->get_editor_notice( __( 'The selected menu no longer exists. Please select another menu.', 'listemann-toolkit-addon' ) );
		}

		return '' === $html ? '' : '<div class="lst-footer__bottom">' . $html . '</div>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Data helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Default logo: the bundled colour logo.
	 *
	 * @return array MEDIA value.
	 */
	private function get_default_logo() {
		return array(
			'url' => LISTEMANN_TOOLKIT_URL . 'assets/img/listemann-logo-color.svg',
			'id'  => '',
		);
	}

	/**
	 * Preset menu columns: the menus named "Footer 1", "Footer 2" … in the
	 * order of their number, otherwise the menus whose names start with
	 * "Footer" (or "Fusszeile" / "Fußzeile") in natural order; at most
	 * MAX_DEFAULT_COLUMNS.
	 *
	 * @return array[] Repeater items ( 'menu' => "menu-{term ID}" ).
	 */
	private function get_default_columns() {
		$numbered = array();
		$named    = array();

		foreach ( wp_get_nav_menus() as $menu ) {
			$name = trim( wp_specialchars_decode( $menu->name, ENT_QUOTES ) );

			if ( preg_match( '/^(?:footer|fusszeile|fußzeile)[\s_-]*(\d+)$/iu', $name, $matches ) ) {
				$numbered[ $menu->term_id ] = (int) $matches[1];
			} elseif ( preg_match( '/^(?:footer|fusszeile|fußzeile)/iu', $name ) ) {
				$named[ $menu->term_id ] = $name;
			}
		}

		if ( $numbered ) {
			asort( $numbered, SORT_NUMERIC );
			$ids = array_keys( $numbered );
		} else {
			natcasesort( $named );
			$ids = array_keys( $named );
		}

		$columns = array();

		foreach ( array_slice( $ids, 0, self::MAX_DEFAULT_COLUMNS ) as $id ) {
			$columns[] = array( 'menu' => self::MENU_KEY_PREFIX . $id );
		}

		return $columns;
	}

	/**
	 * A notice for editors (Elementor editor / preview only).
	 *
	 * @param string $message Plain-text message (escaped here).
	 * @return string Escaped HTML, '' on the public front end.
	 */
	private function get_editor_notice( $message ) {
		$plugin = Elementor::$instance;

		if ( ! $plugin->editor->is_edit_mode() && ! $plugin->preview->is_preview_mode() ) {
			return '';
		}

		return sprintf( '<div class="lst-footer-notice" role="note">%s</div>', esc_html( $message ) );
	}
}
