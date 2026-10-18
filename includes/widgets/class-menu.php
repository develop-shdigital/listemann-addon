<?php
/**
 * "Listemann Menu" Elementor widget.
 *
 * Renders a WordPress nav menu (Appearance > Menus) as a list of grouped
 * links: top-level items act as headings, sub-items are links preceded by
 * a short decorative line, as in the listemann.com footer.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Plugin;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-menu-walker.php';

/**
 * Menu widget with an optional line in front of the menu items.
 */
class Menu extends Widget_Base {

	/**
	 * Handle of the widget stylesheet (registered by the plugin bootstrap).
	 *
	 * @var string
	 */
	const STYLE_HANDLE = 'listemann-toolkit-menu';

	/**
	 * Depth options (0 = all levels).
	 *
	 * @var string[]
	 */
	const DEPTHS = array( '0', '1', '2', '3' );

	/**
	 * List ids already printed during this request (keeps ids unique when
	 * the same widget is rendered more than once on a page).
	 *
	 * @var string[]
	 */
	private static $used_ids = array();

	/**
	 * Widget name (slug).
	 *
	 * @return string
	 */
	public function get_name() {
		return 'listemann-menu';
	}

	/**
	 * Widget title shown in the panel.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'Listemann Menu', 'listemann-toolkit-addon' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-nav-menu';
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
		return array( 'menu', 'nav', 'navigation', 'footer', 'links', 'listemann' );
	}

	/**
	 * Stylesheets the widget depends on.
	 *
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( self::STYLE_HANDLE );
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
	 * aria-current), so it must never be served from Elementor's element
	 * cache.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Keep menus out of the plain-text post content Elementor saves (it is
	 * used for search and as a fallback when Elementor is deactivated).
	 */
	public function render_plain_content() {}

	/**
	 * Register the widget controls.
	 */
	protected function register_controls() {
		$this->register_menu_controls();
		$this->register_heading_style_controls();
		$this->register_item_style_controls();
		$this->register_line_style_controls();
	}

	/**
	 * Content tab: menu, depth, layout and behaviour.
	 */
	private function register_menu_controls() {
		$this->start_controls_section(
			'section_menu',
			array(
				'label' => esc_html__( 'Menu', 'listemann-toolkit-addon' ),
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
			'depth',
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

		$this->add_control(
			'layout',
			array(
				'label'       => esc_html__( 'Layout', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'vertical'   => esc_html__( 'Vertical', 'listemann-toolkit-addon' ),
					'horizontal' => esc_html__( 'Horizontal', 'listemann-toolkit-addon' ),
				),
				'default'     => 'vertical',
				'description' => esc_html__( 'Horizontal places the top-level items in a row (e.g. legal links); sub-items stay stacked below their parent.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_responsive_control(
			'horizontal_gap',
			array(
				'label'      => esc_html__( 'Gap between items', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 30,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-menu-h-gap: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array(
					'layout' => 'horizontal',
				),
			)
		);

		$this->add_responsive_control(
			'horizontal_align',
			array(
				'label'     => esc_html__( 'Alignment', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start'    => array(
						'title' => esc_html__( 'Start', 'listemann-toolkit-addon' ),
						'icon'  => 'eicon-justify-start-h',
					),
					'center'        => array(
						'title' => esc_html__( 'Center', 'listemann-toolkit-addon' ),
						'icon'  => 'eicon-justify-center-h',
					),
					'flex-end'      => array(
						'title' => esc_html__( 'End', 'listemann-toolkit-addon' ),
						'icon'  => 'eicon-justify-end-h',
					),
					'space-between' => array(
						'title' => esc_html__( 'Space between', 'listemann-toolkit-addon' ),
						'icon'  => 'eicon-justify-space-between-h',
					),
				),
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-menu-justify: {{VALUE}};',
				),
				'condition' => array(
					'layout' => 'horizontal',
				),
			)
		);

		$this->add_control(
			'top_headings',
			array(
				'label'        => esc_html__( 'Top-level items are headings', 'listemann-toolkit-addon' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'separator'    => 'before',
				'description'  => esc_html__( 'Top-level items that have sub-items use the heading style.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'hash_as_text',
			array(
				'label'        => esc_html__( 'Show "#" links as plain text', 'listemann-toolkit-addon' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => esc_html__( 'Items whose URL is empty or "#" are rendered as text instead of a link (any level).', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'aria_label',
			array(
				'label'       => esc_html__( 'Accessible label', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => esc_html__( 'Menu name', 'listemann-toolkit-addon' ),
				'description' => esc_html__( 'Label of the navigation landmark for screen readers. Leave empty to use the menu name.', 'listemann-toolkit-addon' ),
				'dynamic'     => array(
					'active' => true,
				),
				'separator'   => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: headings (top-level items with sub-items).
	 */
	private function register_heading_style_controls() {
		$this->start_controls_section(
			'section_style_headings',
			array(
				'label' => esc_html__( 'Headings (top level)', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'heading_typography',
				'selector'       => '{{WRAPPER}} .lst-menu__item--heading > .lst-menu__link',
				'fields_options' => array(
					'typography'     => array( 'default' => 'custom' ),
					'font_size'      => array(
						'default' => array(
							'unit' => 'px',
							'size' => 18,
						),
					),
					'font_weight'    => array( 'default' => '700' ),
					'text_transform' => array( 'default' => 'uppercase' ),
					'line_height'    => array(
						'default' => array(
							'unit' => 'px',
							'size' => 34,
						),
					),
				),
			)
		);

		$this->start_controls_tabs( 'tabs_heading_colors' );

		$this->start_controls_tab(
			'tab_heading_normal',
			array( 'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ) )
		);

		$this->add_control(
			'heading_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#626262',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-menu-heading-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_heading_hover',
			array( 'label' => esc_html__( 'Hover', 'listemann-toolkit-addon' ) )
		);

		$this->add_control(
			'heading_hover_color',
			array(
				'label'       => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::COLOR,
				'description' => esc_html__( 'Only for headings that are links.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-menu-heading-hover-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_heading_active',
			array( 'label' => esc_html__( 'Active', 'listemann-toolkit-addon' ) )
		);

		$this->add_control(
			'heading_active_color',
			array(
				'label'       => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::COLOR,
				'description' => esc_html__( 'When the heading or one of its sub-items is the current page.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-menu-heading-active-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_responsive_control(
			'group_spacing',
			array(
				'label'       => esc_html__( 'Spacing between groups', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 150,
					),
				),
				'default'     => array(
					'unit' => 'px',
					'size' => 25,
				),
				'description' => esc_html__( 'Space before and after each group (an item with its sub-items, e.g. a heading with its links), on every level.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-menu-group-gap: {{SIZE}}{{UNIT}};',
				),
				'separator'   => 'before',
			)
		);

		$this->add_responsive_control(
			'heading_spacing',
			array(
				'label'      => esc_html__( 'Spacing below heading', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 0,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-menu-heading-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: items (all items that are not headings).
	 */
	private function register_item_style_controls() {
		$this->start_controls_section(
			'section_style_items',
			array(
				'label' => esc_html__( 'Items (sub levels)', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'item_typography',
				'selector'       => '{{WRAPPER}} .lst-menu__item:not(.lst-menu__item--heading) > .lst-menu__link',
				'fields_options' => array(
					'typography'     => array( 'default' => 'custom' ),
					'font_size'      => array(
						'default' => array(
							'unit' => 'px',
							'size' => 15,
						),
					),
					'font_weight'    => array( 'default' => '400' ),
					'text_transform' => array( 'default' => 'none' ),
					'line_height'    => array(
						'default' => array(
							'unit' => 'px',
							'size' => 34,
						),
					),
				),
			)
		);

		$this->start_controls_tabs( 'tabs_item_colors' );

		$this->start_controls_tab(
			'tab_item_normal',
			array( 'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ) )
		);

		$this->add_control(
			'item_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#626262',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-menu-item-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_item_hover',
			array( 'label' => esc_html__( 'Hover', 'listemann-toolkit-addon' ) )
		);

		$this->add_control(
			'item_hover_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#dd191a',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-menu-item-hover-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_item_active',
			array( 'label' => esc_html__( 'Active', 'listemann-toolkit-addon' ) )
		);

		$this->add_control(
			'item_active_color',
			array(
				'label'       => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::COLOR,
				'default'     => '#dd191a',
				'description' => esc_html__( 'Current page (and parents of the current page).', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-menu-item-active-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

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
					'{{WRAPPER}}' => '--lst-menu-transition: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'item_spacing',
			array(
				'label'      => esc_html__( 'Spacing between items', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 0,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-menu-item-gap: {{SIZE}}{{UNIT}};',
				),
				'separator'  => 'before',
			)
		);

		$this->add_responsive_control(
			'indent',
			array(
				'label'       => esc_html__( 'Indent per nested level', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'     => array(
					'unit' => 'px',
					'size' => 25,
				),
				'description' => esc_html__( 'Links directly below a heading are not indented.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-menu-indent: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'heading_nested',
			array(
				'label'     => esc_html__( 'Nested items (3rd level and deeper)', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$nested_selector = '{{WRAPPER}} .lst-menu__sub .lst-menu__sub > .lst-menu__item:not(.lst-menu__item--heading) > .lst-menu__link';

		$this->add_responsive_control(
			'nested_font_size',
			array(
				'label'      => esc_html__( 'Font size', 'listemann-toolkit-addon' ),
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
					$nested_selector => 'font-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'nested_line_height',
			array(
				'label'      => esc_html__( 'Line height', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 6,
						'max' => 100,
					),
					'em' => array(
						'min'  => 0.5,
						'max'  => 4,
						'step' => 0.1,
					),
				),
				// 24px, not listemann.com's 22px: the stacked links stay WCAG 2.5.8 sized targets.
				'default'    => array(
					'unit' => 'px',
					'size' => 24,
				),
				'selectors'  => array(
					$nested_selector => 'line-height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: the decorative line in front of the items.
	 */
	private function register_line_style_controls() {
		$this->start_controls_section(
			'section_style_line',
			array(
				'label' => esc_html__( 'Line before items', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'line_show',
			array(
				'label'        => esc_html__( 'Show line', 'listemann-toolkit-addon' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$line_on = array( 'line_show' => 'yes' );

		$this->add_control(
			'line_apply',
			array(
				'label'     => esc_html__( 'Apply to', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'sub' => esc_html__( 'Sub-menu items', 'listemann-toolkit-addon' ),
					'all' => esc_html__( 'All items', 'listemann-toolkit-addon' ),
					'top' => esc_html__( 'Top-level items', 'listemann-toolkit-addon' ),
				),
				'default'   => 'sub',
				'condition' => $line_on,
			)
		);

		$this->add_responsive_control(
			'line_width',
			array(
				'label'      => esc_html__( 'Width', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 30,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-menu-line-w: {{SIZE}}{{UNIT}};',
				),
				'condition'  => $line_on,
			)
		);

		$this->add_control(
			'line_thickness',
			array(
				'label'      => esc_html__( 'Thickness', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 1,
						'max' => 20,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 2,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-menu-line-h: {{SIZE}}{{UNIT}};',
				),
				'condition'  => $line_on,
			)
		);

		$this->add_control(
			'line_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#dd191a',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-menu-line-color: {{VALUE}};',
				),
				'condition' => $line_on,
			)
		);

		$this->add_responsive_control(
			'line_gap',
			array(
				'label'      => esc_html__( 'Gap to text', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 5,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-menu-line-gap: {{SIZE}}{{UNIT}};',
				),
				'condition'  => $line_on,
			)
		);

		$this->add_control(
			'line_radius',
			array(
				'label'      => esc_html__( 'Border radius', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 10,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 0,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-menu-line-radius: {{SIZE}}{{UNIT}};',
				),
				'condition'  => $line_on,
			)
		);

		$this->add_control(
			'line_offset',
			array(
				'label'       => esc_html__( 'Vertical offset', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min' => -20,
						'max' => 20,
					),
				),
				'default'     => array(
					'unit' => 'px',
					'size' => 0,
				),
				'description' => esc_html__( 'The line is centred on the first line of text; nudge it up (negative) or down (positive).', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-menu-line-offset: {{SIZE}}{{UNIT}};',
				),
				'condition'   => $line_on,
			)
		);

		$this->add_responsive_control(
			'line_width_nested',
			array(
				'label'      => esc_html__( 'Width (nested items)', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 20,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-menu-line-w-nested: {{SIZE}}{{UNIT}};',
				),
				'condition'  => $line_on,
			)
		);

		$this->add_control(
			'line_hover_effect',
			array(
				'label'     => esc_html__( 'Hover effect', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'none'       => esc_html__( 'None', 'listemann-toolkit-addon' ),
					'grow'       => esc_html__( 'Grow', 'listemann-toolkit-addon' ),
					'color'      => esc_html__( 'Color', 'listemann-toolkit-addon' ),
					'grow-color' => esc_html__( 'Grow and color', 'listemann-toolkit-addon' ),
				),
				'default'   => 'none',
				'separator' => 'before',
				'condition' => $line_on,
			)
		);

		$this->add_control(
			'line_grow',
			array(
				'label'      => esc_html__( 'Grow by', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 10,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-menu-line-grow: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array(
					'line_show'         => 'yes',
					'line_hover_effect' => array( 'grow', 'grow-color' ),
				),
			)
		);

		$this->add_control(
			'line_hover_color',
			array(
				'label'     => esc_html__( 'Hover color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#626262',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-menu-line-hover-color: {{VALUE}};',
				),
				'condition' => array(
					'line_show'         => 'yes',
					'line_hover_effect' => array( 'color', 'grow-color' ),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget on the front end and in the editor preview.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$menu     = $this->get_menu( isset( $settings['menu'] ) ? $settings['menu'] : '' );

		if ( ! $menu ) {
			if ( ! $this->get_menu_options() ) {
				$this->render_editor_notice( __( 'There are no menus yet. Create one in Appearance > Menus.', 'listemann-toolkit-addon' ) );
			} elseif ( empty( $settings['menu'] ) ) {
				$this->render_editor_notice( __( 'Please select a menu.', 'listemann-toolkit-addon' ) );
			} else {
				$this->render_editor_notice( __( 'The selected menu no longer exists. Please select another menu.', 'listemann-toolkit-addon' ) );
			}
			return;
		}

		$depth  = isset( $settings['depth'] ) && in_array( (string) $settings['depth'], self::DEPTHS, true ) ? (int) $settings['depth'] : 0;
		$layout = isset( $settings['layout'] ) && 'horizontal' === $settings['layout'] ? 'horizontal' : 'vertical';

		$headings     = isset( $settings['top_headings'] ) && 'yes' === $settings['top_headings'];
		$hash_as_text = isset( $settings['hash_as_text'] ) && 'yes' === $settings['hash_as_text'];
		$line_show    = isset( $settings['line_show'] ) && 'yes' === $settings['line_show'];
		$line_apply   = isset( $settings['line_apply'] ) && in_array( $settings['line_apply'], array( 'sub', 'top', 'all' ), true ) ? $settings['line_apply'] : 'sub';
		$hover_effect = isset( $settings['line_hover_effect'] ) && in_array( $settings['line_hover_effect'], array( 'grow', 'color', 'grow-color' ), true ) ? $settings['line_hover_effect'] : 'none';

		$list_id = $this->get_unique_list_id();

		$menu_html = wp_nav_menu(
			array(
				'menu'           => $menu->term_id,
				'theme_location' => '',
				'container'      => '',
				'menu_id'        => $list_id,
				'menu_class'     => 'lst-menu__list',
				'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
				'item_spacing'   => 'discard',
				'depth'          => $depth,
				'echo'           => false,
				'fallback_cb'    => '__return_empty_string',
				'walker'         => new Menu_Walker(
					array(
						'headings'     => $headings,
						'hash_as_text' => $hash_as_text,
						'line'         => $line_show ? $line_apply : '',
						'id_prefix'    => $list_id,
						'depth'        => $depth,
					)
				),
			)
		);

		if ( empty( $menu_html ) ) {
			$this->render_editor_notice( __( 'The selected menu has no items.', 'listemann-toolkit-addon' ) );
			return;
		}

		$label = isset( $settings['aria_label'] ) && is_string( $settings['aria_label'] ) ? trim( $settings['aria_label'] ) : '';

		if ( '' === $label ) {
			$label = wp_specialchars_decode( $menu->name, ENT_QUOTES );
		}

		$classes = array( 'lst-menu', 'lst-menu--' . $layout );

		if ( $headings ) {
			$classes[] = 'lst-menu--headings';
		}

		if ( $line_show ) {
			$classes[] = 'lst-menu--line-' . $line_apply;

			if ( 'none' !== $hover_effect ) {
				$classes[] = 'lst-menu--line-hover-' . $hover_effect;
			}
		}

		$this->add_render_attribute(
			'nav',
			array(
				'class'      => $classes,
				'aria-label' => $label,
			)
		);

		printf(
			'<nav %1$s>%2$s</nav>',
			$this->get_render_attribute_string( 'nav' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_render_attribute_string().
			$menu_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by wp_nav_menu() and Menu_Walker, which escape attributes like core.
		);
	}

	/**
	 * Options for the menu control: term ID => menu name.
	 *
	 * @return string[]
	 */
	private function get_menu_options() {
		$options = array();

		foreach ( wp_get_nav_menus() as $menu ) {
			$options[ (string) $menu->term_id ] = esc_html( $menu->name );
		}

		return $options;
	}

	/**
	 * Resolve the saved menu setting (term ID, or a slug for imported data).
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
		$menu  = preg_match( '/^\d+$/', $value ) ? wp_get_nav_menu_object( (int) $value ) : get_term_by( 'slug', $value, 'nav_menu' );

		return $menu instanceof \WP_Term ? $menu : false;
	}

	/**
	 * Id of the menu `<ul>` (and prefix of the item ids), unique per
	 * widget instance and per request.
	 *
	 * @return string
	 */
	private function get_unique_list_id() {
		$base = 'lst-menu-' . $this->get_id();
		$id   = $base;
		$i    = 2;

		while ( in_array( $id, self::$used_ids, true ) ) {
			$id = $base . '-' . $i;
			++$i;
		}

		self::$used_ids[] = $id;

		return $id;
	}

	/**
	 * Print a notice for editors (Elementor editor / preview only).
	 *
	 * Nothing is printed on the public front end.
	 *
	 * @param string $message Plain-text message (escaped here).
	 */
	private function render_editor_notice( $message ) {
		$plugin = Plugin::$instance;

		if ( ! $plugin->editor->is_edit_mode() && ! $plugin->preview->is_preview_mode() ) {
			return;
		}

		printf( '<div class="lst-menu-notice" role="note">%s</div>', esc_html( $message ) );
	}
}
