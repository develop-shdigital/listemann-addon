<?php
/**
 * Continuous Slider widget.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit\Widgets;

use Elementor\Control_Media;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Css_Filter;
use Elementor\Group_Control_Image_Size;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Plugin as Elementor;
use Elementor\Repeater;
use Elementor\Utils;
use Elementor\Widget_Base;
use Listemann_Toolkit\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Endless, full-width image strip that glides continuously (desktop) or
 * advances slide by slide (mobile).
 *
 * The server renders every slide once. Layout values (slides per view, gap,
 * height, motion) are written by Elementor as CSS custom properties, so they
 * follow any breakpoint configuration; the script reads them back from the
 * computed style and clones the slides as often as the viewport requires.
 */
class Continuous_Slider extends Widget_Base {

	/**
	 * Allowed title tags.
	 */
	const TITLE_TAGS = array( 'span', 'div', 'p', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/**
	 * Numeric behaviour settings: key => [ default, min, max ].
	 * Shared by the controls, render() and content_template().
	 */
	const LIMITS = array(
		'speed'         => array( 6, 0.5, 120 ),
		'step_interval' => array( 3500, 500, 60000 ),
		'step_duration' => array( 300, 0, 5000 ),
	);

	/**
	 * Button icons (static markup, coloured with currentColor).
	 */
	const ICONS = array(
		'pause' => '<path d="M4 2h3v12H4zM9 2h3v12H9z"/>',
		'play'  => '<path d="M4 2l10 6-10 6z"/>',
		'prev'  => '<path d="M10.6 2 12 3.4 7.4 8l4.6 4.6-1.4 1.4-6-6z"/>',
		'next'  => '<path d="M5.4 2 4 3.4 8.6 8 4 12.6 5.4 14l6-6z"/>',
	);

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'listemann-continuous-slider';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'Continuous Slider', 'listemann-toolkit-addon' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-slider-push';
	}

	/**
	 * Widget categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( Plugin::CATEGORY );
	}

	/**
	 * Widget search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'slider', 'carousel', 'marquee', 'ticker', 'continuous', 'loop', 'gallery', 'image', 'listemann' );
	}

	/**
	 * Script handles the widget depends on.
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( 'listemann-toolkit-continuous-slider' );
	}

	/**
	 * Style handles the widget depends on.
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( 'listemann-toolkit-continuous-slider' );
	}

	/**
	 * Drops the `.elementor-widget-container` wrapper when Elementor's optimized
	 * markup is active. With the feature off, the Advanced tab targets that
	 * wrapper, so it has to stay.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper(): bool {
		return ! Elementor::$instance->experiments->is_feature_active( 'e_optimized_markup' );
	}

	/**
	 * The output only depends on the widget settings, so Elementor may cache it
	 * (dynamic tags still disable the cache).
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return false;
	}

	/**
	 * Registers the widget controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->register_slides_controls();
		$this->register_slider_controls();
		$this->register_slides_style_controls();
		$this->register_overlay_style_controls();
		$this->register_title_style_controls();
		$this->register_buttons_style_controls();
	}

	/**
	 * Display condition of the button settings: at least one button is on.
	 *
	 * @return array
	 */
	private function get_buttons_condition() {
		return array(
			'relation' => 'or',
			'terms'    => array(
				array(
					'name'     => 'pause_button',
					'operator' => '===',
					'value'    => 'yes',
				),
				array(
					'name'     => 'nav_buttons',
					'operator' => '===',
					'value'    => 'yes',
				),
			),
		);
	}

	/**
	 * Content tab: slides, image resolution and title tag.
	 *
	 * @return void
	 */
	private function register_slides_controls() {
		$this->start_controls_section(
			'section_slides',
			array(
				'label' => esc_html__( 'Slides', 'listemann-toolkit-addon' ),
			)
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'image',
			array(
				'label'   => esc_html__( 'Image', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::MEDIA,
				'dynamic' => array( 'active' => true ),
				'default' => array( 'url' => Utils::get_placeholder_image_src() ),
			)
		);

		$repeater->add_control(
			'title',
			array(
				'label'       => esc_html__( 'Title', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array( 'active' => true ),
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'link',
			array(
				'label'       => esc_html__( 'Link', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::URL,
				'dynamic'     => array( 'active' => true ),
				'placeholder' => esc_html__( 'https://your-link.com', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'slides',
			array(
				'label'       => esc_html__( 'Slides', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => $this->get_default_slides(),
				'title_field' => '{{ title }}',
			)
		);

		// The group name "image" yields the settings image_size and image_custom_dimension.
		$this->add_group_control(
			Group_Control_Image_Size::get_type(),
			array(
				'name'      => 'image',
				'default'   => 'large',
				'separator' => 'before',
			)
		);

		$this->add_control(
			'title_tag',
			array(
				'label'   => esc_html__( 'Title HTML Tag', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'span' => 'span',
					'div'  => 'div',
					'p'    => 'p',
					'h2'   => 'H2',
					'h3'   => 'H3',
					'h4'   => 'H4',
					'h5'   => 'H5',
					'h6'   => 'H6',
				),
				'default' => 'span',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: layout and motion.
	 *
	 * @return void
	 */
	private function register_slider_controls() {
		$this->start_controls_section(
			'section_slider',
			array(
				'label' => esc_html__( 'Slider', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_responsive_control(
			'slides_per_view',
			array(
				'label'          => esc_html__( 'Slides per View', 'listemann-toolkit-addon' ),
				'type'           => Controls_Manager::NUMBER,
				'min'            => 1,
				'max'            => 12,
				'step'           => 0.1,
				'placeholder'    => 7,
				'tablet_default' => 4,
				'mobile_default' => 2,
				'description'    => esc_html__( 'Decimals (e.g. 2.5) let the next slide peek in. Left empty, the strip shows 7 slides, 5 below 1300 px and 4 below 1100 px, like the original.', 'listemann-toolkit-addon' ),
				'selectors'      => array(
					'{{WRAPPER}}' => '--lst-spv: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => esc_html__( 'Gap', 'listemann-toolkit-addon' ),
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
					'size' => 15,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'edge_spacing',
			array(
				'label'                => esc_html__( 'Gap at the Edges', 'listemann-toolkit-addon' ),
				'type'                 => Controls_Manager::SELECT,
				'options'              => array(
					'half' => esc_html__( 'Half gap', 'listemann-toolkit-addon' ),
					'none' => esc_html__( 'None (flush)', 'listemann-toolkit-addon' ),
				),
				'default'              => 'half',
				'description'          => esc_html__( 'Half gap: each slide plus its gap takes exactly 1 / slides per view of the width, as on the original strip. None: the outer slides touch the edges.', 'listemann-toolkit-addon' ),
				'selectors_dictionary' => array(
					'half' => '1',
					'none' => '0',
				),
				'selectors'            => array(
					'{{WRAPPER}}' => '--lst-edge: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'height',
			array(
				'label'          => esc_html__( 'Height', 'listemann-toolkit-addon' ),
				'type'           => Controls_Manager::SLIDER,
				'size_units'     => array( 'px', 'vh', 'em', 'rem' ),
				'range'          => array(
					'px' => array(
						'min' => 100,
						'max' => 1200,
					),
					'vh' => array(
						'min' => 10,
						'max' => 100,
					),
				),
				'default'        => array(
					'unit' => 'px',
					'size' => 550,
				),
				'tablet_default' => array(
					'unit' => 'px',
					'size' => 300,
				),
				'mobile_default' => array(
					'unit' => 'px',
					'size' => 300,
				),
				'selectors'      => array(
					'{{WRAPPER}}' => '--lst-height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'motion',
			array(
				'label'          => esc_html__( 'Motion', 'listemann-toolkit-addon' ),
				'type'           => Controls_Manager::SELECT,
				'options'        => array(
					'continuous' => esc_html__( 'Continuous glide', 'listemann-toolkit-addon' ),
					'step'       => esc_html__( 'Step (one slide at a time)', 'listemann-toolkit-addon' ),
					'none'       => esc_html__( 'Static', 'listemann-toolkit-addon' ),
				),
				'default'        => 'continuous',
				'mobile_default' => 'step',
				'description'    => esc_html__( 'Visitors who prefer reduced motion always get a static strip they can drag or move with the previous / next buttons.', 'listemann-toolkit-addon' ),
				'selectors'      => array(
					'{{WRAPPER}}' => '--lst-mode: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'direction',
			array(
				'label'       => esc_html__( 'Direction', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'left'  => esc_html__( 'Left', 'listemann-toolkit-addon' ),
					'right' => esc_html__( 'Right', 'listemann-toolkit-addon' ),
				),
				'default'     => 'left',
				'description' => esc_html__( 'Applies to continuous and step motion.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'heading_continuous',
			array(
				'label'     => esc_html__( 'Continuous Glide', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'speed',
			array(
				'label'       => esc_html__( 'Seconds per Slide', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => self::LIMITS['speed'][1],
				'max'         => self::LIMITS['speed'][2],
				'step'        => 0.1,
				'default'     => self::LIMITS['speed'][0],
				'description' => esc_html__( 'Time one slide (including the gap) takes to pass. Lower is faster.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'continuous_pause_on_hover',
			array(
				'label'   => esc_html__( 'Pause on Hover', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => '',
			)
		);

		$this->add_control(
			'continuous_draggable',
			array(
				'label'   => esc_html__( 'Allow Drag / Swipe', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => '',
			)
		);

		$this->add_control(
			'heading_step',
			array(
				'label'     => esc_html__( 'Step', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'step_interval',
			array(
				'label'   => esc_html__( 'Interval (ms)', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => self::LIMITS['step_interval'][1],
				'max'     => self::LIMITS['step_interval'][2],
				'step'    => 100,
				'default' => self::LIMITS['step_interval'][0],
			)
		);

		$this->add_control(
			'step_duration',
			array(
				'label'   => esc_html__( 'Transition Duration (ms)', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => self::LIMITS['step_duration'][1],
				'max'     => self::LIMITS['step_duration'][2],
				'step'    => 50,
				'default' => self::LIMITS['step_duration'][0],
			)
		);

		$this->add_control(
			'step_pause_on_hover',
			array(
				'label'   => esc_html__( 'Pause on Hover', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'step_draggable',
			array(
				'label'   => esc_html__( 'Allow Drag / Swipe', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'heading_static',
			array(
				'label'     => esc_html__( 'Static', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'static_draggable',
			array(
				'label'   => esc_html__( 'Allow Drag / Swipe', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => '',
			)
		);

		$this->add_control(
			'heading_buttons',
			array(
				'label'     => esc_html__( 'Buttons', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'pause_button',
			array(
				'label'       => esc_html__( 'Pause Button', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => esc_html__( 'Lets visitors stop and restart the motion. Accessibility guidelines (WCAG 2.2.2) require it for content that moves on its own.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'nav_buttons',
			array(
				'label'       => esc_html__( 'Previous / Next Buttons', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => esc_html__( 'Shown while the strip stands still (paused, static or reduced motion) and not every slide fits, so all slides can be reached without dragging.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'buttons_visibility',
			array(
				'label'       => esc_html__( 'Show Buttons', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'always' => esc_html__( 'Always', 'listemann-toolkit-addon' ),
					'hover'  => esc_html__( 'On hover and keyboard focus', 'listemann-toolkit-addon' ),
				),
				'default'     => 'always',
				'description' => esc_html__( 'Touch screens always show them.', 'listemann-toolkit-addon' ),
				'conditions'  => $this->get_buttons_condition(),
			)
		);

		$this->add_control(
			'aria_label',
			array(
				'label'       => esc_html__( 'Accessible Label', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array( 'active' => true ),
				'default'     => 'Einsatzgebiete',
				'label_block' => true,
				'separator'   => 'before',
				'description' => esc_html__( 'Name of the carousel announced by screen readers.', 'listemann-toolkit-addon' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: slides and images.
	 *
	 * @return void
	 */
	private function register_slides_style_controls() {
		$this->start_controls_section(
			'section_style_slides',
			array(
				'label' => esc_html__( 'Slides', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'slide_border_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .lst-cslider__card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'image_position',
			array(
				'label'     => esc_html__( 'Image Position', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'center center' => esc_html__( 'Center Center', 'listemann-toolkit-addon' ),
					'center top'    => esc_html__( 'Center Top', 'listemann-toolkit-addon' ),
					'center bottom' => esc_html__( 'Center Bottom', 'listemann-toolkit-addon' ),
					'left center'   => esc_html__( 'Left Center', 'listemann-toolkit-addon' ),
					'left top'      => esc_html__( 'Left Top', 'listemann-toolkit-addon' ),
					'left bottom'   => esc_html__( 'Left Bottom', 'listemann-toolkit-addon' ),
					'right center'  => esc_html__( 'Right Center', 'listemann-toolkit-addon' ),
					'right top'     => esc_html__( 'Right Top', 'listemann-toolkit-addon' ),
					'right bottom'  => esc_html__( 'Right Bottom', 'listemann-toolkit-addon' ),
				),
				'default'   => 'center center',
				'selectors' => array(
					'{{WRAPPER}} .lst-cslider__image' => 'object-position: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'image_height',
			array(
				'label'          => esc_html__( 'Image Height', 'listemann-toolkit-addon' ),
				'type'           => Controls_Manager::SLIDER,
				'size_units'     => array( '%', 'px', 'vh' ),
				'range'          => array(
					'%'  => array(
						'min' => 100,
						'max' => 200,
					),
					'px' => array(
						'min' => 100,
						'max' => 1500,
					),
					'vh' => array(
						'min' => 10,
						'max' => 150,
					),
				),
				'default'        => array(
					'unit' => '%',
					'size' => 109,
				),
				'tablet_default' => array(
					'unit' => '%',
					'size' => 100,
				),
				'description'    => esc_html__( 'Percentages are relative to the slide height. A taller image is cropped at the bottom: the original strip shows 600 px images in 550 px slides (109 %). The image never gets shorter than the slide.', 'listemann-toolkit-addon' ),
				'selectors'      => array(
					'{{WRAPPER}}' => '--lst-image-height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'image_effects_tabs' );

		$this->start_controls_tab(
			'image_effects_normal',
			array(
				'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_group_control(
			Group_Control_Css_Filter::get_type(),
			array(
				'name'     => 'image_filters',
				'selector' => '{{WRAPPER}} .lst-cslider__image',
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'image_effects_hover',
			array(
				'label' => esc_html__( 'Hover', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_group_control(
			Group_Control_Css_Filter::get_type(),
			array(
				'name'     => 'image_filters_hover',
				'selector' => '{{WRAPPER}} .lst-cslider__slide:hover .lst-cslider__image, {{WRAPPER}} .lst-cslider__card:focus-visible .lst-cslider__image',
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Style tab: colour overlay.
	 *
	 * @return void
	 */
	private function register_overlay_style_controls() {
		$this->start_controls_section(
			'section_style_overlay',
			array(
				'label' => esc_html__( 'Overlay', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->start_controls_tabs( 'overlay_tabs' );

		$this->start_controls_tab(
			'overlay_normal',
			array(
				'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'overlay_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-overlay-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'overlay_opacity',
			array(
				'label'     => esc_html__( 'Opacity', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1,
						'step' => 0.01,
					),
				),
				'default'   => array( 'size' => 0.35 ),
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-overlay-opacity: {{SIZE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'overlay_hover',
			array(
				'label' => esc_html__( 'Hover', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'overlay_hover_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#dd191a',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-overlay-hover-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'overlay_hover_opacity',
			array(
				'label'     => esc_html__( 'Opacity', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1,
						'step' => 0.01,
					),
				),
				'default'   => array( 'size' => 0.75 ),
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-overlay-hover-opacity: {{SIZE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_control(
			'title_shade_opacity',
			array(
				'label'       => esc_html__( 'Shade Behind Title', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SLIDER,
				'range'       => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1,
						'step' => 0.01,
					),
				),
				'default'     => array( 'size' => 0 ),
				'separator'   => 'before',
				'description' => esc_html__( 'A gradient that darkens the lower half of the slide. On light images, about 0.6 keeps white titles readable (WCAG contrast 4.5:1).', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-shade-opacity: {{SIZE}};',
				),
			)
		);

		$this->add_control(
			'title_shade_color',
			array(
				'label'     => esc_html__( 'Shade Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-shade-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'hover_transition',
			array(
				'label'      => esc_html__( 'Transition Duration', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'ms' ),
				'range'      => array(
					'ms' => array(
						'min'  => 0,
						'max'  => 3000,
						'step' => 50,
					),
				),
				'default'    => array(
					'unit' => 'ms',
					'size' => 300,
				),
				'separator'  => 'before',
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-hover-duration: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: slide titles.
	 *
	 * @return void
	 */
	private function register_title_style_controls() {
		$this->start_controls_section(
			'section_style_title',
			array(
				'label' => esc_html__( 'Title', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->start_controls_tabs( 'title_tabs' );

		$this->start_controls_tab(
			'title_normal',
			array(
				'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-title-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'title_hover',
			array(
				'label' => esc_html__( 'Hover', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'title_hover_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-title-hover-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'title_typography',
				'selector'       => '{{WRAPPER}} .lst-cslider__title',
				'separator'      => 'before',
				'fields_options' => array(
					'typography'     => array( 'default' => 'custom' ),
					'font_size'      => array(
						'default'        => array(
							'unit' => 'vw',
							'size' => 1.3,
						),
						'tablet_default' => array(
							'unit' => 'vw',
							'size' => 2.2,
						),
						'mobile_default' => array(
							'unit' => 'vw',
							'size' => 4.2,
						),
					),
					'line_height'    => array(
						'size_units'      => array( 'px', 'em', 'rem', 'vw', 'custom' ),
						'range'           => array(
							'px' => array(
								'min' => 1,
								'max' => 200,
							),
							'vw' => array(
								'min'  => 0.1,
								'max'  => 20,
								'step' => 0.1,
							),
						),
						'default'         => array(
							'unit' => 'vw',
							'size' => 1.7,
						),
						'desktop_default' => array(
							'unit' => 'vw',
							'size' => 1.7,
						),
						'tablet_default'  => array(
							'unit' => 'vw',
							'size' => 2.8,
						),
						'mobile_default'  => array(
							'unit' => 'vw',
							'size' => 6.5,
						),
					),
					'letter_spacing' => array(
						'default' => array(
							'unit' => 'px',
							'size' => 0.5,
						),
					),
				),
			)
		);

		$this->add_group_control(
			Group_Control_Text_Shadow::get_type(),
			array(
				'name'     => 'title_text_shadow',
				'selector' => '{{WRAPPER}} .lst-cslider__title',
			)
		);

		$this->add_responsive_control(
			'title_padding',
			array(
				'label'      => esc_html__( 'Padding', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'default'    => array(
					'top'      => '0',
					'right'    => '15',
					'bottom'   => '15',
					'left'     => '20',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'separator'  => 'before',
				'selectors'  => array(
					'{{WRAPPER}} .lst-cslider__title' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'title_position',
			array(
				'label'                => esc_html__( 'Vertical Position', 'listemann-toolkit-addon' ),
				'type'                 => Controls_Manager::CHOOSE,
				'options'              => array(
					'top'    => array(
						'title' => esc_html__( 'Top', 'listemann-toolkit-addon' ),
						'icon'  => 'eicon-v-align-top',
					),
					'center' => array(
						'title' => esc_html__( 'Center', 'listemann-toolkit-addon' ),
						'icon'  => 'eicon-v-align-middle',
					),
					'bottom' => array(
						'title' => esc_html__( 'Bottom', 'listemann-toolkit-addon' ),
						'icon'  => 'eicon-v-align-bottom',
					),
				),
				'default'              => 'bottom',
				'selectors_dictionary' => array(
					'top'    => 'flex-start',
					'center' => 'center',
					'bottom' => 'flex-end',
				),
				'selectors'            => array(
					'{{WRAPPER}} .lst-cslider__card' => 'justify-content: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'title_align',
			array(
				'label'     => esc_html__( 'Alignment', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array(
						'title' => esc_html__( 'Left', 'listemann-toolkit-addon' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => esc_html__( 'Center', 'listemann-toolkit-addon' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => esc_html__( 'Right', 'listemann-toolkit-addon' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array(
					'{{WRAPPER}} .lst-cslider__title' => 'text-align: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: pause and previous / next buttons.
	 *
	 * @return void
	 */
	private function register_buttons_style_controls() {
		$this->start_controls_section(
			'section_style_buttons',
			array(
				'label'      => esc_html__( 'Buttons', 'listemann-toolkit-addon' ),
				'tab'        => Controls_Manager::TAB_STYLE,
				'conditions' => $this->get_buttons_condition(),
			)
		);

		$this->add_responsive_control(
			'buttons_position',
			array(
				'label'                => esc_html__( 'Position', 'listemann-toolkit-addon' ),
				'type'                 => Controls_Manager::SELECT,
				'options'              => array(
					'top-right'    => esc_html__( 'Top Right', 'listemann-toolkit-addon' ),
					'top-left'     => esc_html__( 'Top Left', 'listemann-toolkit-addon' ),
					'bottom-right' => esc_html__( 'Bottom Right', 'listemann-toolkit-addon' ),
					'bottom-left'  => esc_html__( 'Bottom Left', 'listemann-toolkit-addon' ),
				),
				'default'              => 'top-right',
				'selectors_dictionary' => array(
					'top-right'    => 'top: var(--_inset); right: var(--_inset); bottom: auto; left: auto;',
					'top-left'     => 'top: var(--_inset); right: auto; bottom: auto; left: var(--_inset);',
					'bottom-right' => 'top: auto; right: var(--_inset); bottom: var(--_inset); left: auto;',
					'bottom-left'  => 'top: auto; right: auto; bottom: var(--_inset); left: var(--_inset);',
				),
				'selectors'            => array(
					'{{WRAPPER}} .lst-cslider__controls' => '{{VALUE}}',
				),
			)
		);

		$this->start_controls_tabs( 'buttons_tabs' );

		$this->start_controls_tab(
			'buttons_normal',
			array(
				'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'buttons_color',
			array(
				'label'     => esc_html__( 'Icon Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-button-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'buttons_background',
			array(
				'label'     => esc_html__( 'Background Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(0, 0, 0, 0.6)',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-button-bg: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'buttons_hover',
			array(
				'label' => esc_html__( 'Hover', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'buttons_hover_color',
			array(
				'label'     => esc_html__( 'Icon Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-button-hover-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'buttons_hover_background',
			array(
				'label'     => esc_html__( 'Background Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#dd191a',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-button-hover-bg: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Default slides: the four "Einsatzgebiete" of listemann.com.
	 *
	 * @return array
	 */
	private function get_default_slides() {
		$titles = array( 'Luft- & Raumfahrt', 'Energie- & Umwelttechnik', 'Medizintechnik', 'Formen- & Werkzeugbau' );
		$slides = array();

		foreach ( $titles as $title ) {
			$slides[] = array(
				'image' => array( 'url' => Utils::get_placeholder_image_src() ),
				'title' => $title,
				'link'  => array( 'url' => '' ),
			);
		}

		return $slides;
	}

	/**
	 * Renders the widget on the front end.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$slides   = isset( $settings['slides'] ) && is_array( $settings['slides'] ) ? array_values( $settings['slides'] ) : array();

		if ( ! $slides ) {
			return;
		}

		$title_tag = in_array( $settings['title_tag'] ?? '', self::TITLE_TAGS, true ) ? $settings['title_tag'] : 'span';
		$label     = trim( wp_strip_all_tags( (string) ( $settings['aria_label'] ?? '' ) ) );
		$pause     = 'yes' === ( $settings['pause_button'] ?? '' );
		$nav       = 'yes' === ( $settings['nav_buttons'] ?? '' );

		$this->add_render_attribute(
			'slider',
			array(
				'class'            => 'lst-cslider',
				'data-lst-options' => wp_json_encode( $this->get_engine_options( $settings ) ),
			)
		);

		if ( ( $pause || $nav ) && 'hover' === ( $settings['buttons_visibility'] ?? '' ) ) {
			$this->add_render_attribute( 'slider', 'class', 'lst-cslider--buttons-on-hover' );
		}

		if ( '' !== $label ) {
			$this->add_render_attribute(
				'slider',
				array(
					'role'                 => 'region',
					'aria-roledescription' => __( 'carousel', 'listemann-toolkit-addon' ),
					'aria-label'           => $label,
				)
			);
		}
		?>
		<div <?php $this->print_render_attribute_string( 'slider' ); ?>>
			<?php if ( $pause || $nav ) : ?>
				<div class="lst-cslider__controls">
					<?php
					if ( $nav ) {
						$this->print_button( 'prev' );
					}

					if ( $pause ) {
						$this->print_button( 'pause' );
					}

					if ( $nav ) {
						$this->print_button( 'next' );
					}
					?>
				</div>
			<?php endif; ?>
			<div class="lst-cslider__viewport">
				<div class="lst-cslider__track">
					<?php
					foreach ( $slides as $index => $slide ) {
						$this->render_slide( $slide, $index, count( $slides ), $title_tag, $settings );
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders one slide.
	 *
	 * @param array  $slide     Repeater item.
	 * @param int    $index     Zero-based position.
	 * @param int    $total     Number of slides.
	 * @param string $title_tag Validated title tag.
	 * @param array  $settings  Widget settings.
	 * @return void
	 */
	private function render_slide( array $slide, $index, $total, $title_tag, array $settings ) {
		$slide_key = 'slide-' . $index;
		$card_key  = 'card-' . $index;
		$title     = isset( $slide['title'] ) ? (string) $slide['title'] : '';
		$has_title = '' !== trim( $title );
		$link      = isset( $slide['link'] ) && is_array( $slide['link'] ) ? $slide['link'] : array();
		$card_tag  = empty( $link['url'] ) ? 'div' : 'a';

		$this->add_render_attribute(
			$slide_key,
			array(
				'class'                => array( 'lst-cslider__slide', 'elementor-repeater-item-' . ( $slide['_id'] ?? $index ) ),
				'role'                 => 'group',
				'aria-roledescription' => __( 'slide', 'listemann-toolkit-addon' ),
				/* translators: 1: Slide number, 2: Number of slides. */
				'aria-label'           => sprintf( __( '%1$d of %2$d', 'listemann-toolkit-addon' ), $index + 1, $total ),
			)
		);

		$this->add_render_attribute(
			$card_key,
			array(
				'class'     => 'lst-cslider__card',
				'draggable' => 'false',
			)
		);

		if ( 'a' === $card_tag ) {
			$this->add_link_attributes( $card_key, $link );
		}
		?>
		<div <?php $this->print_render_attribute_string( $slide_key ); ?>>
			<<?php Utils::print_validated_html_tag( $card_tag ); ?> <?php $this->print_render_attribute_string( $card_key ); ?>>
				<?php echo $this->get_image_html( $slide, $settings, $has_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_image_html(). ?>
				<span class="lst-cslider__overlay" aria-hidden="true"></span>
				<?php if ( $has_title ) : ?>
					<<?php Utils::print_validated_html_tag( $title_tag ); ?> class="lst-cslider__title"><?php echo esc_html( $title ); ?></<?php Utils::print_validated_html_tag( $title_tag ); ?>>
				<?php endif; ?>
			</<?php Utils::print_validated_html_tag( $card_tag ); ?>>
		</div>
		<?php
	}

	/**
	 * Prints the pause / play button or a previous / next button. Shared by
	 * render() and content_template(); the script switches the pause button's
	 * label between its two data attributes.
	 *
	 * @param string $name pause, prev or next.
	 * @return void
	 */
	private function print_button( $name ) {
		if ( 'pause' === $name ) {
			printf(
				'<button type="button" class="lst-cslider__button lst-cslider__pause" aria-label="%1$s" data-lst-pause-label="%1$s" data-lst-play-label="%2$s">',
				esc_attr__( 'Pause carousel', 'listemann-toolkit-addon' ),
				esc_attr__( 'Play carousel', 'listemann-toolkit-addon' )
			);
			$this->print_icon( 'pause' );
			$this->print_icon( 'play' );
		} else {
			printf(
				'<button type="button" class="lst-cslider__button lst-cslider__nav lst-cslider__%1$s" aria-label="%2$s">',
				esc_attr( $name ),
				'prev' === $name ? esc_attr__( 'Previous slide', 'listemann-toolkit-addon' ) : esc_attr__( 'Next slide', 'listemann-toolkit-addon' )
			);
			$this->print_icon( $name );
		}

		echo '</button>';
	}

	/**
	 * Prints a button icon.
	 *
	 * @param string $name Key of self::ICONS.
	 * @return void
	 */
	private function print_icon( $name ) {
		printf(
			'<svg class="lst-cslider__icon lst-cslider__icon-%1$s" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false">%2$s</svg>',
			esc_attr( $name ),
			self::ICONS[ $name ] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup from a class constant.
		);
	}

	/**
	 * Builds the `<img>` markup of a slide.
	 *
	 * A slide with a visible title gets an empty alt text: the title already
	 * names the link, and repeating the image description would make screen
	 * readers announce both.
	 *
	 * @param array $slide      Repeater item.
	 * @param array $settings   Widget settings.
	 * @param bool  $decorative Whether the image is decorative.
	 * @return string Escaped HTML.
	 */
	private function get_image_html( array $slide, array $settings, $decorative ) {
		$image = isset( $slide['image'] ) && is_array( $slide['image'] ) ? $slide['image'] : array();
		$id    = ! empty( $image['id'] ) ? absint( $image['id'] ) : 0;
		$url   = ! empty( $image['url'] ) ? $image['url'] : '';
		$size  = ! empty( $settings['image_size'] ) ? $settings['image_size'] : 'large';
		$alt   = $decorative ? '' : Control_Media::get_image_alt( $image );
		$attrs = array(
			'class'     => 'lst-cslider__image',
			'alt'       => $alt,
			'decoding'  => 'async',
			'draggable' => 'false',
		);

		if ( $id && ! wp_attachment_is_image( $id ) ) {
			$id = 0;
		}

		$registered_sizes = array_merge( get_intermediate_image_sizes(), array( 'full' ) );

		// Responsive (srcset) markup for library images in a registered size. The
		// `loading` attribute is left to WordPress / Elementor's image loading
		// optimization, which would otherwise add a second one.
		if ( $id && in_array( $size, $registered_sizes, true ) && ! Elementor::$instance->frontend->is_static_render_mode() ) {
			$html = wp_get_attachment_image( $id, $size, false, $attrs );

			if ( $html ) {
				return $html;
			}
		}

		if ( $id ) {
			$src = Group_Control_Image_Size::get_attachment_image_src(
				$id,
				'image',
				array(
					'image'                  => $image,
					'image_size'             => $size,
					'image_custom_dimension' => $settings['image_custom_dimension'] ?? array(),
				)
			);

			if ( $src ) {
				$url = $src;
			}
		}

		if ( ! $url ) {
			return '';
		}

		return sprintf(
			'<img class="lst-cslider__image" src="%1$s" alt="%2$s" loading="lazy" decoding="async" draggable="false">',
			esc_url( $url ),
			esc_attr( $alt )
		);
	}

	/**
	 * Behaviour options for the script (`data-lst-options`). Layout values are
	 * not included; the script reads those from CSS custom properties.
	 *
	 * Keep in sync with content_template().
	 *
	 * @param array $settings Widget settings.
	 * @return array
	 */
	private function get_engine_options( array $settings ) {
		return array(
			'direction'  => 'right' === ( $settings['direction'] ?? '' ) ? 'right' : 'left',
			'continuous' => array(
				'speed'        => $this->get_number( $settings, 'speed' ),
				'pauseOnHover' => 'yes' === ( $settings['continuous_pause_on_hover'] ?? '' ),
				'draggable'    => 'yes' === ( $settings['continuous_draggable'] ?? '' ),
			),
			'step'       => array(
				'interval'     => (int) $this->get_number( $settings, 'step_interval' ),
				'duration'     => (int) $this->get_number( $settings, 'step_duration' ),
				'pauseOnHover' => 'yes' === ( $settings['step_pause_on_hover'] ?? '' ),
				'draggable'    => 'yes' === ( $settings['step_draggable'] ?? '' ),
			),
			'none'       => array(
				'draggable' => 'yes' === ( $settings['static_draggable'] ?? '' ),
			),
		);
	}

	/**
	 * Reads a numeric setting and clamps it to self::LIMITS.
	 *
	 * @param array  $settings Widget settings.
	 * @param string $key      Setting key (a key of self::LIMITS).
	 * @return float
	 */
	private function get_number( array $settings, $key ) {
		list( $default, $min, $max ) = self::LIMITS[ $key ];

		if ( ! isset( $settings[ $key ] ) || ! is_numeric( $settings[ $key ] ) ) {
			return (float) $default;
		}

		return (float) min( max( (float) $settings[ $key ], $min ), $max );
	}

	/**
	 * Live preview template for the editor. Mirrors render().
	 *
	 * @return void
	 */
	protected function content_template() {
		?>
		<#
		var lstSlides = Array.isArray( settings.slides ) ? settings.slides : [];

		if ( lstSlides.length ) {
			var lstLimits = <?php echo wp_json_encode( self::LIMITS ); ?>,
				lstTitleTags = <?php echo wp_json_encode( self::TITLE_TAGS ); ?>,
				lstTitleTag = lstTitleTags.indexOf( settings.title_tag ) > -1 ? settings.title_tag : 'span',
				lstNumber = function( key ) {
					var value = parseFloat( settings[ key ] ),
						limit = lstLimits[ key ];

					return isNaN( value ) ? limit[ 0 ] : Math.min( Math.max( value, limit[ 1 ] ), limit[ 2 ] );
				},
				lstOn = function( key ) {
					return 'yes' === settings[ key ];
				},
				lstOptions = {
					direction: 'right' === settings.direction ? 'right' : 'left',
					continuous: {
						speed: lstNumber( 'speed' ),
						pauseOnHover: lstOn( 'continuous_pause_on_hover' ),
						draggable: lstOn( 'continuous_draggable' )
					},
					step: {
						interval: Math.round( lstNumber( 'step_interval' ) ),
						duration: Math.round( lstNumber( 'step_duration' ) ),
						pauseOnHover: lstOn( 'step_pause_on_hover' ),
						draggable: lstOn( 'step_draggable' )
					},
					none: {
						draggable: lstOn( 'static_draggable' )
					}
				},
				lstLabel = String( settings.aria_label || '' ).replace( /<[^>]*>/g, '' ).trim(),
				lstPause = lstOn( 'pause_button' ),
				lstNav = lstOn( 'nav_buttons' ),
				lstSlideLabel = <?php echo wp_json_encode( /* translators: 1: Slide number, 2: Number of slides. */ __( '%1$d of %2$d', 'listemann-toolkit-addon' ) ); ?>;

			view.addRenderAttribute( 'slider', {
				'class': 'lst-cslider',
				'data-lst-options': JSON.stringify( lstOptions )
			} );

			if ( ( lstPause || lstNav ) && 'hover' === settings.buttons_visibility ) {
				view.addRenderAttribute( 'slider', 'class', 'lst-cslider--buttons-on-hover' );
			}

			if ( lstLabel ) {
				view.addRenderAttribute( 'slider', {
					role: 'region',
					'aria-roledescription': '<?php echo esc_js( __( 'carousel', 'listemann-toolkit-addon' ) ); ?>',
					'aria-label': lstLabel
				} );
			}
			#>
			<div {{{ view.getRenderAttributeString( 'slider' ) }}}>
				<# if ( lstPause || lstNav ) { #>
					<div class="lst-cslider__controls">
						<# if ( lstNav ) { #>
							<?php $this->print_button( 'prev' ); ?>
						<# } #>
						<# if ( lstPause ) { #>
							<?php $this->print_button( 'pause' ); ?>
						<# } #>
						<# if ( lstNav ) { #>
							<?php $this->print_button( 'next' ); ?>
						<# } #>
					</div>
				<# } #>
				<div class="lst-cslider__viewport">
					<div class="lst-cslider__track">
						<# _.each( lstSlides, function( slide, index ) {
							var image = slide.image || {},
								imageUrl = image.url || '',
								title = 'string' === typeof slide.title ? slide.title : '',
								href = slide.link && slide.link.url ? slide.link.url : '',
								cardTag = href ? 'a' : 'div',
								slideKey = 'slide-' + index,
								cardKey = 'card-' + index;

							if ( image.id ) {
								imageUrl = elementor.imagesManager.getImageUrl( {
									id: image.id,
									url: image.url,
									size: settings.image_size,
									dimension: settings.image_custom_dimension,
									model: view.getEditModel()
								} ) || image.url;
							}

							view.addRenderAttribute( slideKey, {
								'class': [ 'lst-cslider__slide', 'elementor-repeater-item-' + ( slide._id || index ) ],
								role: 'group',
								'aria-roledescription': '<?php echo esc_js( __( 'slide', 'listemann-toolkit-addon' ) ); ?>',
								'aria-label': lstSlideLabel.replace( '%1$d', index + 1 ).replace( '%2$d', lstSlides.length )
							} );

							view.addRenderAttribute( cardKey, {
								'class': 'lst-cslider__card',
								draggable: 'false'
							} );

							if ( href ) {
								view.addRenderAttribute( cardKey, 'href', elementor.helpers.sanitizeUrl ? elementor.helpers.sanitizeUrl( href ) : href );

								if ( slide.link.is_external ) {
									view.addRenderAttribute( cardKey, 'target', '_blank' );
								}

								if ( slide.link.nofollow ) {
									view.addRenderAttribute( cardKey, 'rel', 'nofollow' );
								}
							}
							#>
							<div {{{ view.getRenderAttributeString( slideKey ) }}}>
								<{{ cardTag }} {{{ view.getRenderAttributeString( cardKey ) }}}>
									<# if ( imageUrl ) { #>
										<img class="lst-cslider__image" src="{{ imageUrl }}" alt="" loading="lazy" decoding="async" draggable="false">
									<# } #>
									<span class="lst-cslider__overlay" aria-hidden="true"></span>
									<# if ( title.trim() ) { #>
										<{{ lstTitleTag }} class="lst-cslider__title">{{ title }}</{{ lstTitleTag }}>
									<# } #>
								</{{ cardTag }}>
							</div>
						<# } ); #>
					</div>
				</div>
			</div>
		<# } #>
		<?php
	}
}
