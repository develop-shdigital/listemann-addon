<?php
/**
 * "Listemann Posts" Elementor widget.
 *
 * Recreates the listemann.com blog page: a heading, a category filter with
 * checkboxes and a grid of image cards (one wide card next to two stacked
 * ones, then rows of three) with a "Mehr laden" button.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Image_Size;
use Elementor\Group_Control_Typography;
use Elementor\Plugin;
use Elementor\Widget_Base;
use Listemann_Toolkit\Posts_Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Post cards with a category filter and "load more".
 *
 * Markup overview (classes used by assets/css/posts.css and
 * assets/js/posts.js):
 *
 *     div.lst-posts.lst-posts--{layout}[data-lst-posts]
 *       h2.lst-posts__heading
 *       form.lst-posts__filter[method=get] > fieldset > legend + label.lst-posts__option > input + span
 *       div.lst-posts__status[role=status]                  (screen-reader announcements)
 *       div.lst-posts__grid
 *         article.lst-posts__item(--wide|--stacked|--row)
 *           div.lst-posts__media > img.lst-posts__image + span.lst-posts__overlay
 *           div.lst-posts__content
 *             div.lst-posts__main > h3.lst-posts__title > a.lst-posts__link (stretched over the card)
 *                                   time.lst-posts__date, .lst-posts__badges, p.lst-posts__excerpt
 *             div.lst-posts__author > img.lst-posts__avatar + span.lst-posts__author-name
 *       p.lst-posts__empty
 *       div.lst-posts__pagination > a.lst-posts__more     (or nav with page numbers)
 *
 * Without JavaScript the filter is a GET form (`?lst_cat[]=…`) and "load
 * more" is a link to the next page (`?lst_page=2`); both are rendered on the
 * server. The script replaces them with requests to the REST route
 * `listemann-toolkit/v1/posts` (see Posts_Rest), which renders the cards with
 * the same code from the saved widget settings.
 *
 * Layout and style controls write CSS custom properties; posts.css holds the
 * listemann.com values as fallbacks, including the steps at 1279.98px and
 * 767.98px that Elementor's default breakpoints cannot express.
 */
class Posts extends Widget_Base {

	/**
	 * Widget name, also checked by the REST route.
	 */
	const NAME = 'listemann-posts';

	/**
	 * Handle of the widget stylesheet (registered by the plugin bootstrap).
	 */
	const STYLE_HANDLE = 'listemann-toolkit-posts';

	/**
	 * Handle of the widget script (registered by the plugin bootstrap).
	 */
	const SCRIPT_HANDLE = 'listemann-toolkit-posts';

	/**
	 * Upper limit of posts per page / per "load more".
	 */
	const MAX_PER_PAGE = 50;

	/**
	 * Upper limit of the offset setting.
	 */
	const MAX_OFFSET = 100;

	/**
	 * Query arguments of the no-JS fallback (filter form and pagination links).
	 */
	const ARG_PAGE    = 'lst_page';
	const ARG_CATS    = 'lst_cat';
	const ARG_ELEMENT = 'lst_posts';

	/**
	 * Prefix of the option keys of the term and author controls ("term-12").
	 *
	 * The editor receives SELECT2 options as a JavaScript object, which lists
	 * integer-like keys in ascending order: bare IDs would sort the options by
	 * ID instead of by name. Bare IDs are accepted as well.
	 */
	const TERM_KEY_PREFIX = 'term-';
	const USER_KEY_PREFIX = 'user-';

	/**
	 * Public post types that cannot be listed (no published entries / not content).
	 */
	const EXCLUDED_POST_TYPES = array( 'attachment', 'elementor_library', 'e-landing-page', 'e-floating-buttons' );

	/**
	 * Allowed values of the order setting.
	 */
	const ORDERBY = array( 'date', 'modified', 'title', 'menu_order', 'comment_count', 'rand' );

	/**
	 * Allowed heading tags.
	 */
	const HEADING_TAGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div' );

	/**
	 * Allowed card title tags.
	 */
	const TITLE_TAGS = array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div' );

	/**
	 * Layout presets.
	 */
	const LAYOUTS = array( 'featured', 'grid', 'list' );

	/**
	 * Column options: option key => [ columns, columns of the wide card, rows of the wide card ].
	 * Word keys keep the editor's option order (see TERM_KEY_PREFIX).
	 */
	const COLUMNS = array(
		'one'   => array( 1, 1, 1 ),
		'two'   => array( 2, 1, 2 ),
		'three' => array( 3, 2, 2 ),
		'four'  => array( 4, 3, 2 ),
		'five'  => array( 5, 4, 2 ),
		'six'   => array( 6, 5, 2 ),
	);

	/**
	 * Root ids already printed during this request (keeps ids unique when the
	 * same element is rendered more than once on a page).
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
		return self::NAME;
	}

	/**
	 * Widget title shown in the panel.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'Listemann Posts', 'listemann-toolkit-addon' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-posts-grid';
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
		return array( 'blog', 'posts', 'news', 'aktuelles', 'cards', 'grid', 'filter', 'load more', 'listemann' );
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
	 * The output depends on the posts and on the query string (filter, page),
	 * so it must never be served from Elementor's element cache.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Keep the post list out of the plain-text post content Elementor saves.
	 */
	public function render_plain_content() {}

	/*
	 * ---------------------------------------------------------------------
	 * Controls
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Register the widget controls.
	 */
	protected function register_controls() {
		$this->register_heading_controls();
		$this->register_query_controls();
		$this->register_layout_controls();
		$this->register_card_controls();
		$this->register_filter_controls();
		$this->register_pagination_controls();

		$this->register_heading_style_controls();
		$this->register_filter_style_controls();
		$this->register_card_style_controls();
		$this->register_text_style_controls();
		$this->register_button_style_controls();
	}

	/**
	 * Condition of the controls that only apply to a custom query of posts.
	 *
	 * @return array
	 */
	private function get_category_condition() {
		return array(
			'source'    => 'posts',
			'post_type' => 'post',
		);
	}

	/**
	 * Responsive SLIDER control writing a CSS custom property.
	 *
	 * @param string $id          Control id.
	 * @param string $label       Label.
	 * @param string $property    Custom property (without the leading dashes).
	 * @param array  $args        Extra control arguments (default, range, description, condition, ...).
	 * @param bool   $responsive  Whether the control is responsive.
	 * @return void
	 */
	private function add_size_control( $id, $label, $property, array $args = array(), $responsive = true ) {
		$control = array_merge(
			array(
				'label'      => $label,
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--' . $property . ': {{SIZE}}{{UNIT}};',
				),
			),
			$args
		);

		if ( $responsive ) {
			$this->add_responsive_control( $id, $control );
		} else {
			$this->add_control( $id, $control );
		}
	}

	/**
	 * COLOR control writing a CSS custom property.
	 *
	 * @param string $id       Control id.
	 * @param string $label    Label.
	 * @param string $property Custom property (without the leading dashes).
	 * @param string $default  Default colour, '' for none (the CSS fallback applies).
	 * @param array  $args     Extra control arguments.
	 * @return void
	 */
	private function add_color_control( $id, $label, $property, $default = '', array $args = array() ) {
		$this->add_control(
			$id,
			array_merge(
				array(
					'label'     => $label,
					'type'      => Controls_Manager::COLOR,
					'default'   => $default,
					'selectors' => array(
						'{{WRAPPER}}' => '--' . $property . ': {{VALUE}};',
					),
				),
				$args
			)
		);
	}

	/**
	 * Content tab: heading.
	 */
	private function register_heading_controls() {
		$this->start_controls_section(
			'section_heading',
			array(
				'label' => esc_html__( 'Heading', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'show_heading',
			array(
				'label'   => esc_html__( 'Show heading', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'heading_text',
			array(
				'label'       => esc_html__( 'Text', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'Aktuelle Informationen der Listemann Gruppe',
				'label_block' => true,
				'dynamic'     => array(
					'active' => true,
				),
				'condition'   => array(
					'show_heading' => 'yes',
				),
			)
		);

		$this->add_control(
			'heading_tag',
			array(
				'label'       => esc_html__( 'HTML tag', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array_combine( self::HEADING_TAGS, array_map( 'strtoupper', self::HEADING_TAGS ) ),
				'default'     => 'h2',
				'description' => esc_html__( 'H2 by default: the page usually has its own H1 (on listemann.com this heading is the H1 of the blog page).', 'listemann-toolkit-addon' ),
				'condition'   => array(
					'show_heading' => 'yes',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: query.
	 */
	private function register_query_controls() {
		$this->start_controls_section(
			'section_query',
			array(
				'label' => esc_html__( 'Query', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'source',
			array(
				'label'   => esc_html__( 'Source', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'posts'   => esc_html__( 'Posts (custom query)', 'listemann-toolkit-addon' ),
					'current' => esc_html__( 'Current query (archive templates)', 'listemann-toolkit-addon' ),
				),
				'default' => 'posts',
			)
		);

		$this->add_control(
			'current_query_notice',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Shows the posts of the archive the template is displayed on (category, tag, author, search, blog page), with its page size and pagination. The filter is not available here.', 'listemann-toolkit-addon' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
				'condition'       => array(
					'source' => 'current',
				),
			)
		);

		$this->add_control(
			'post_type',
			array(
				'label'     => esc_html__( 'Post type', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => $this->get_post_type_options(),
				'default'   => 'post',
				'condition' => array(
					'source' => 'posts',
				),
			)
		);

		$this->add_control(
			'include_categories',
			array(
				'label'       => esc_html__( 'Categories', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => $this->get_term_options( 'category' ),
				'description' => esc_html__( 'Only posts in one of these categories. Empty: all categories.', 'listemann-toolkit-addon' ),
				'condition'   => $this->get_category_condition(),
			)
		);

		$this->add_control(
			'exclude_categories',
			array(
				'label'       => esc_html__( 'Exclude categories', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => $this->get_term_options( 'category' ),
				'condition'   => $this->get_category_condition(),
			)
		);

		$this->add_control(
			'include_tags',
			array(
				'label'       => esc_html__( 'Tags', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => $this->get_term_options( 'post_tag' ),
				'description' => esc_html__( 'Only posts with one of these tags. Empty: all.', 'listemann-toolkit-addon' ),
				'condition'   => $this->get_category_condition(),
			)
		);

		$this->add_control(
			'include_authors',
			array(
				'label'       => esc_html__( 'Authors', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => $this->get_author_options(),
				'description' => esc_html__( 'Only posts by these authors. Empty: all.', 'listemann-toolkit-addon' ),
				'condition'   => array(
					'source' => 'posts',
				),
			)
		);

		$this->add_control(
			'orderby',
			array(
				'label'     => esc_html__( 'Order by', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'date'          => esc_html__( 'Date', 'listemann-toolkit-addon' ),
					'modified'      => esc_html__( 'Last modified', 'listemann-toolkit-addon' ),
					'title'         => esc_html__( 'Title', 'listemann-toolkit-addon' ),
					'menu_order'    => esc_html__( 'Menu order', 'listemann-toolkit-addon' ),
					'comment_count' => esc_html__( 'Comment count', 'listemann-toolkit-addon' ),
					'rand'          => esc_html__( 'Random', 'listemann-toolkit-addon' ),
				),
				'default'   => 'date',
				'separator' => 'before',
				'condition' => array(
					'source' => 'posts',
				),
			)
		);

		$this->add_control(
			'order',
			array(
				'label'     => esc_html__( 'Order', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'DESC' => esc_html__( 'Descending', 'listemann-toolkit-addon' ),
					'ASC'  => esc_html__( 'Ascending', 'listemann-toolkit-addon' ),
				),
				'default'   => 'DESC',
				'condition' => array(
					'source'   => 'posts',
					'orderby!' => 'rand',
				),
			)
		);

		$this->add_control(
			'posts_per_page',
			array(
				'label'     => esc_html__( 'Posts on the first page', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => self::MAX_PER_PAGE,
				'default'   => 9,
				'separator' => 'before',
				'condition' => array(
					'source' => 'posts',
				),
			)
		);

		$this->add_control(
			'posts_per_load',
			array(
				'label'       => esc_html__( 'Posts per "load more" / page', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => self::MAX_PER_PAGE,
				'default'     => 6,
				'description' => esc_html__( 'Number of posts each click on "load more" (or each further page) adds.', 'listemann-toolkit-addon' ),
				'condition'   => array(
					'source' => 'posts',
				),
			)
		);

		$this->add_control(
			'offset',
			array(
				'label'       => esc_html__( 'Offset', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => self::MAX_OFFSET,
				'default'     => 0,
				'description' => esc_html__( 'Skips this many posts, e.g. those shown by another widget above.', 'listemann-toolkit-addon' ),
				'condition'   => array(
					'source' => 'posts',
				),
			)
		);

		$this->add_control(
			'exclude_current',
			array(
				'label'       => esc_html__( 'Exclude the current post', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => esc_html__( 'On a single post (e.g. "more news" below an article), the post itself is not listed.', 'listemann-toolkit-addon' ),
				'condition'   => array(
					'source' => 'posts',
				),
			)
		);

		$this->add_control(
			'ignore_sticky',
			array(
				'label'       => esc_html__( 'Ignore sticky posts', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => '',
				'description' => esc_html__( 'Off: sticky posts come first.', 'listemann-toolkit-addon' ),
				'condition'   => array(
					'source'    => 'posts',
					'post_type' => 'post',
				),
			)
		);

		$this->add_control(
			'only_with_image',
			array(
				'label'     => esc_html__( 'Only posts with a featured image', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => '',
				'condition' => array(
					'source' => 'posts',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: layout.
	 */
	private function register_layout_controls() {
		$this->start_controls_section(
			'section_layout',
			array(
				'label' => esc_html__( 'Layout', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'       => esc_html__( 'Layout', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'featured' => esc_html__( 'Featured (1 wide + 2 stacked, then rows)', 'listemann-toolkit-addon' ),
					'grid'     => esc_html__( 'Grid (all cards equal)', 'listemann-toolkit-addon' ),
					'list'     => esc_html__( 'List (image left, text right)', 'listemann-toolkit-addon' ),
				),
				'default'     => 'featured',
				'description' => esc_html__( 'Featured is the listemann.com blog: the first three posts of the first page share the first rows, the others follow in rows.', 'listemann-toolkit-addon' ),
			)
		);

		$columns = array( '' => esc_html__( 'Default', 'listemann-toolkit-addon' ) );
		$css     = array();

		foreach ( self::COLUMNS as $key => $values ) {
			$columns[ $key ] = (string) $values[0];
			$css[ $key ]     = sprintf( '--lst-cols: %1$d; --lst-wide-span: %2$d; --lst-wide-rows: %3$d;', $values[0], $values[1], $values[2] );
		}

		$this->add_responsive_control(
			'columns',
			array(
				'label'                => esc_html__( 'Columns', 'listemann-toolkit-addon' ),
				'type'                 => Controls_Manager::SELECT,
				'options'              => $columns,
				'default'              => '',
				'description'          => esc_html__( 'Default: 3 columns, 1 below 768 px (the list: 1 column). The wide card spans all columns but one.', 'listemann-toolkit-addon' ),
				'selectors_dictionary' => $css,
				'selectors'            => array(
					'{{WRAPPER}}' => '{{VALUE}}',
				),
			)
		);

		$this->add_size_control(
			'gap',
			esc_html__( 'Gap', 'listemann-toolkit-addon' ),
			'lst-gap',
			array(
				'description' => esc_html__( 'Default: 60 px, 30 px below 1280 px and 15 px below 768 px, like the original.', 'listemann-toolkit-addon' ),
			)
		);

		$height = array(
			'size_units' => array( 'px', 'vh', 'em', 'rem' ),
			'range'      => array(
				'px' => array(
					'min' => 100,
					'max' => 1200,
				),
				'vh' => array(
					'min' => 10,
					'max' => 100,
				),
			),
		);

		$this->add_size_control(
			'height_wide',
			esc_html__( 'Height of the wide card', 'listemann-toolkit-addon' ),
			'lst-h-wide',
			array_merge(
				$height,
				array(
					'separator'   => 'before',
					'description' => esc_html__( 'Default: 700 px minus the gap (640 px, 670 px below 1280 px), 300 px below 768 px.', 'listemann-toolkit-addon' ),
					'condition'   => array(
						'layout' => 'featured',
					),
				)
			)
		);

		$this->add_size_control(
			'height_stacked',
			esc_html__( 'Height of the stacked cards', 'listemann-toolkit-addon' ),
			'lst-h-stacked',
			array_merge(
				$height,
				array(
					'description' => esc_html__( 'Default: the two cards together are as high as the wide card (290 px).', 'listemann-toolkit-addon' ),
					'condition'   => array(
						'layout' => 'featured',
					),
				)
			)
		);

		$this->add_size_control(
			'height_row',
			esc_html__( 'Card height', 'listemann-toolkit-addon' ),
			'lst-h-row',
			array_merge(
				$height,
				array(
					'description' => esc_html__( 'Minimum height of the other cards. Default: 320 px minus the gap (260 px, 290 px below 1280 px), 320 px below 768 px. A card grows instead of letting its text overlap.', 'listemann-toolkit-addon' ),
				)
			)
		);

		$this->add_size_control(
			'list_image_width',
			esc_html__( 'Image width', 'listemann-toolkit-addon' ),
			'lst-list-media',
			array(
				'size_units'  => array( '%', 'px' ),
				'range'       => array(
					'%'  => array(
						'min' => 10,
						'max' => 90,
					),
					'px' => array(
						'min' => 50,
						'max' => 800,
					),
				),
				'description' => esc_html__( 'Default: 40 %. Below 768 px the image is shown above the text.', 'listemann-toolkit-addon' ),
				'condition'   => array(
					'layout' => 'list',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: card content.
	 */
	private function register_card_controls() {
		$this->start_controls_section(
			'section_card',
			array(
				'label' => esc_html__( 'Card content', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'show_date',
			array(
				'label'   => esc_html__( 'Show date', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'date_format',
			array(
				'label'     => esc_html__( 'Date format', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'custom' => esc_html__( 'Custom', 'listemann-toolkit-addon' ),
					'site'   => esc_html__( 'Site setting (Settings > General)', 'listemann-toolkit-addon' ),
				),
				'default'   => 'custom',
				'condition' => array(
					'show_date' => 'yes',
				),
			)
		);

		$this->add_control(
			'date_format_custom',
			array(
				'label'       => esc_html__( 'Custom format', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'd. F Y',
				'placeholder' => 'd. F Y',
				'description' => sprintf(
					/* translators: 1: Link open tag, 2: Link close tag. */
					esc_html__( 'PHP date format, e.g. "d. F Y" = 07. Februar 2026 (month names in the site language). %1$sDocumentation%2$s', 'listemann-toolkit-addon' ),
					'<a href="https://wordpress.org/documentation/article/customize-date-and-time-format/" target="_blank" rel="noopener">',
					'</a>'
				),
				'condition'   => array(
					'show_date'   => 'yes',
					'date_format' => 'custom',
				),
			)
		);

		$this->add_control(
			'show_author',
			array(
				'label'     => esc_html__( 'Show author', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_avatar',
			array(
				'label'       => esc_html__( 'Show profile picture', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => '',
				'description' => esc_html__( 'Round picture before the name (Gravatar or an avatar plugin). Not shown while avatars are off in Settings > Discussion.', 'listemann-toolkit-addon' ),
				'condition'   => array(
					'show_author' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_categories',
			array(
				'label'     => esc_html__( 'Show categories', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => '',
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_excerpt',
			array(
				'label'   => esc_html__( 'Show excerpt', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => '',
			)
		);

		$this->add_control(
			'excerpt_length',
			array(
				'label'     => esc_html__( 'Excerpt length (words)', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 100,
				'default'   => 20,
				'condition' => array(
					'show_excerpt' => 'yes',
				),
			)
		);

		$this->add_control(
			'title_tag',
			array(
				'label'     => esc_html__( 'Title HTML tag', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array_combine( self::TITLE_TAGS, array_map( 'strtoupper', self::TITLE_TAGS ) ),
				'default'   => 'h3',
				'separator' => 'before',
			)
		);

		$this->add_control(
			'title_lines',
			array(
				'label'       => esc_html__( 'Maximum title lines', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 12,
				'default'     => 4,
				'description' => esc_html__( 'Longer titles end with "…" (the full title stays the link text). 0: no limit; the card grows if needed.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'fallback_image',
			array(
				'label'       => esc_html__( 'Fallback image', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::MEDIA,
				'description' => esc_html__( 'For posts without a featured image. Empty: the card background colour.', 'listemann-toolkit-addon' ),
				'separator'   => 'before',
			)
		);

		// The group name "image" yields the settings image_size and image_custom_dimension.
		$this->add_group_control(
			Group_Control_Image_Size::get_type(),
			array(
				'name'    => 'image',
				'default' => 'large',
			)
		);

		$this->add_control(
			'new_tab',
			array(
				'label'     => esc_html__( 'Open posts in a new tab', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => '',
				'separator' => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: category filter.
	 */
	private function register_filter_controls() {
		$this->start_controls_section(
			'section_filter',
			array(
				'label'     => esc_html__( 'Filter', 'listemann-toolkit-addon' ),
				'condition' => $this->get_category_condition(),
			)
		);

		$this->add_control(
			'show_filter',
			array(
				'label'   => esc_html__( 'Show category filter', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$filter_condition = array( 'show_filter' => 'yes' );

		$this->add_control(
			'filter_label',
			array(
				'label'       => esc_html__( 'Label', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'Filter:',
				'description' => esc_html__( 'Name of the group of checkboxes. Left empty, screen readers announce "Filter by category".', 'listemann-toolkit-addon' ),
				'condition'   => $filter_condition,
			)
		);

		$this->add_control(
			'filter_terms_source',
			array(
				'label'       => esc_html__( 'Categories in the filter', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'auto'   => esc_html__( 'All categories of the query', 'listemann-toolkit-addon' ),
					'custom' => esc_html__( 'Selected categories', 'listemann-toolkit-addon' ),
				),
				'default'     => 'auto',
				'description' => esc_html__( 'All: the categories chosen under Query (or every category), minus the excluded ones.', 'listemann-toolkit-addon' ),
				'condition'   => $filter_condition,
			)
		);

		$this->add_control(
			'filter_terms',
			array(
				'label'       => esc_html__( 'Categories', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => $this->get_term_options( 'category' ),
				'condition'   => array(
					'show_filter'         => 'yes',
					'filter_terms_source' => 'custom',
				),
			)
		);

		$this->add_control(
			'filter_mode',
			array(
				'label'       => esc_html__( 'Selection', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'multiple' => esc_html__( 'Several categories (checkboxes)', 'listemann-toolkit-addon' ),
					'single'   => esc_html__( 'One category (radio buttons)', 'listemann-toolkit-addon' ),
				),
				'default'     => 'multiple',
				'description' => esc_html__( 'With several categories ticked, posts in any of them are shown.', 'listemann-toolkit-addon' ),
				'condition'   => $filter_condition,
			)
		);

		$this->add_control(
			'filter_all_text',
			array(
				'label'     => esc_html__( '"All" option', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Alle',
				'condition' => array(
					'show_filter' => 'yes',
					'filter_mode' => 'single',
				),
			)
		);

		$this->add_control(
			'filter_show_count',
			array(
				'label'     => esc_html__( 'Show post counts', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => '',
				'condition' => $filter_condition,
			)
		);

		$this->add_control(
			'filter_hide_empty',
			array(
				'label'     => esc_html__( 'Hide empty categories', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'condition' => $filter_condition,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: pagination ("load more").
	 */
	private function register_pagination_controls() {
		$this->start_controls_section(
			'section_pagination',
			array(
				'label' => esc_html__( 'Load more', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'pagination',
			array(
				'label'       => esc_html__( 'Pagination', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'load_more' => esc_html__( '"Load more" button', 'listemann-toolkit-addon' ),
					'numbers'   => esc_html__( 'Page numbers', 'listemann-toolkit-addon' ),
					'none'      => esc_html__( 'None', 'listemann-toolkit-addon' ),
				),
				'default'     => 'load_more',
				'description' => esc_html__( 'Without JavaScript (and for search engines) the button is a link to the next page.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'load_more_text',
			array(
				'label'     => esc_html__( 'Button text', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Mehr laden',
				'condition' => array(
					'pagination' => 'load_more',
				),
			)
		);

		$this->add_control(
			'loading_text',
			array(
				'label'     => esc_html__( 'Text while loading', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Lädt …',
				'condition' => array(
					'pagination' => 'load_more',
				),
			)
		);

		$this->add_control(
			'no_more_text',
			array(
				'label'       => esc_html__( 'Text when all posts are shown', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => 'Alle Beiträge werden angezeigt.',
				'description' => esc_html__( 'Replaces the button after the last posts were loaded. Empty: the button disappears.', 'listemann-toolkit-addon' ),
				'label_block' => true,
				'condition'   => array(
					'pagination' => 'load_more',
				),
			)
		);

		$this->add_control(
			'empty_text',
			array(
				'label'       => esc_html__( 'Text when no posts match', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'Keine Beiträge gefunden.',
				'label_block' => true,
				'separator'   => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: heading.
	 */
	private function register_heading_style_controls() {
		$this->start_controls_section(
			'section_style_heading',
			array(
				'label'     => esc_html__( 'Heading', 'listemann-toolkit-addon' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_heading' => 'yes',
				),
			)
		);

		$this->add_color_control( 'heading_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), 'lst-heading-color', '#dd191a' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'        => 'heading_typography',
				'selector'    => '{{WRAPPER}} .lst-posts__heading',
				'description' => esc_html__( 'Default: 42 px / 62 px, light (300); 36 / 45 px below 1280 px, 24 / 32 px below 768 px.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_size_control(
			'heading_spacing',
			esc_html__( 'Spacing below', 'listemann-toolkit-addon' ),
			'lst-heading-gap',
			array(
				'description' => esc_html__( 'Default: 20 px.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_responsive_control(
			'heading_align',
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
					'{{WRAPPER}} .lst-posts__heading' => 'text-align: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: filter.
	 */
	private function register_filter_style_controls() {
		$this->start_controls_section(
			'section_style_filter',
			array(
				'label'     => esc_html__( 'Filter', 'listemann-toolkit-addon' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array_merge( $this->get_category_condition(), array( 'show_filter' => 'yes' ) ),
			)
		);

		$this->add_color_control( 'filter_legend_color', esc_html__( 'Label color', 'listemann-toolkit-addon' ), 'lst-legend-color', '#626262' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'filter_legend_typography',
				'label'    => esc_html__( 'Label typography', 'listemann-toolkit-addon' ),
				'selector' => '{{WRAPPER}} .lst-posts__legend',
			)
		);

		$this->add_color_control(
			'filter_option_color',
			esc_html__( 'Category color', 'listemann-toolkit-addon' ),
			'lst-option-color',
			'#626262',
			array( 'separator' => 'before' )
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'filter_option_typography',
				'label'    => esc_html__( 'Category typography', 'listemann-toolkit-addon' ),
				'selector' => '{{WRAPPER}} .lst-posts__option',
			)
		);

		$this->add_control(
			'heading_filter_box',
			array(
				'label'     => esc_html__( 'Checkbox', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_size_control(
			'filter_box_size',
			esc_html__( 'Size', 'listemann-toolkit-addon' ),
			'lst-box-size',
			array(
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 10,
						'max' => 60,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 22,
				),
			),
			false
		);

		$this->add_size_control(
			'filter_box_border',
			esc_html__( 'Border width', 'listemann-toolkit-addon' ),
			'lst-box-border',
			array(
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 10,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 2,
				),
			),
			false
		);

		$this->add_color_control( 'filter_box_color', esc_html__( 'Border color', 'listemann-toolkit-addon' ), 'lst-box-color', '#dd191a' );
		$this->add_color_control( 'filter_box_background', esc_html__( 'Background color', 'listemann-toolkit-addon' ), 'lst-box-bg', '#ffffff' );
		$this->add_color_control( 'filter_dot_color', esc_html__( 'Check mark color', 'listemann-toolkit-addon' ), 'lst-dot-color', '#dd191a' );

		$this->add_size_control(
			'filter_dot_size',
			esc_html__( 'Check mark size', 'listemann-toolkit-addon' ),
			'lst-dot-size',
			array(
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min' => 2,
						'max' => 40,
					),
				),
				'default'     => array(
					'unit' => 'px',
					'size' => 6,
				),
				'description' => esc_html__( 'A dot in the middle of the box; hovering a category shows it at half opacity.', 'listemann-toolkit-addon' ),
			),
			false
		);

		$this->add_size_control(
			'filter_option_gap',
			esc_html__( 'Space between categories', 'listemann-toolkit-addon' ),
			'lst-option-gap',
			array(
				'separator'   => 'before',
				'description' => esc_html__( 'Default: 20 px (side by side), 0 below 768 px (one per line).', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_responsive_control(
			'filter_direction',
			array(
				'label'                => esc_html__( 'Arrangement', 'listemann-toolkit-addon' ),
				'type'                 => Controls_Manager::SELECT,
				'options'              => array(
					''       => esc_html__( 'Default', 'listemann-toolkit-addon' ),
					'row'    => esc_html__( 'Side by side', 'listemann-toolkit-addon' ),
					'column' => esc_html__( 'One per line', 'listemann-toolkit-addon' ),
				),
				'default'              => '',
				'description'          => esc_html__( 'Default: side by side, one per line below 768 px.', 'listemann-toolkit-addon' ),
				'selectors_dictionary' => array(
					'row'    => '--lst-filter-dir: row;',
					'column' => '--lst-filter-dir: column;',
				),
				'selectors'            => array(
					'{{WRAPPER}}' => '{{VALUE}}',
				),
			)
		);

		$this->add_size_control(
			'filter_spacing',
			esc_html__( 'Spacing below the filter', 'listemann-toolkit-addon' ),
			'lst-filter-gap',
			array(
				'description' => esc_html__( 'Default: 30 px, 40 px below 1280 px.', 'listemann-toolkit-addon' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: cards (shape, image, overlay, hover and focus).
	 */
	private function register_card_style_controls() {
		$this->start_controls_section(
			'section_style_card',
			array(
				'label' => esc_html__( 'Cards', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_size_control(
			'card_radius',
			esc_html__( 'Border radius', 'listemann-toolkit-addon' ),
			'lst-radius',
			array(
				'size_units' => array( 'px', '%', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 10,
				),
			),
			false
		);

		$this->add_color_control(
			'card_background',
			esc_html__( 'Background color', 'listemann-toolkit-addon' ),
			'lst-card-bg',
			'#cfcfcf',
			array( 'description' => esc_html__( 'Shown behind the image and for posts without one.', 'listemann-toolkit-addon' ) )
		);

		$this->add_color_control(
			'list_background',
			esc_html__( 'Text area background', 'listemann-toolkit-addon' ),
			'lst-list-bg',
			'',
			array(
				'description' => esc_html__( 'Default: white.', 'listemann-toolkit-addon' ),
				'condition'   => array(
					'layout' => 'list',
				),
			)
		);

		$this->add_responsive_control(
			'image_position',
			array(
				'label'     => esc_html__( 'Image position', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					''              => esc_html__( 'Default', 'listemann-toolkit-addon' ),
					'center center' => esc_html__( 'Center Center', 'listemann-toolkit-addon' ),
					'center top'    => esc_html__( 'Center Top', 'listemann-toolkit-addon' ),
					'center bottom' => esc_html__( 'Center Bottom', 'listemann-toolkit-addon' ),
					'left center'   => esc_html__( 'Left Center', 'listemann-toolkit-addon' ),
					'right center'  => esc_html__( 'Right Center', 'listemann-toolkit-addon' ),
				),
				'default'   => '',
				'selectors' => array(
					'{{WRAPPER}} .lst-posts__image' => 'object-position: {{VALUE}};',
				),
			)
		);

		$this->add_color_control(
			'overlay_color',
			esc_html__( 'Overlay color', 'listemann-toolkit-addon' ),
			'lst-overlay-color',
			'#000000',
			array( 'separator' => 'before' )
		);

		$opacity = array(
			'type'  => Controls_Manager::SLIDER,
			'range' => array(
				'px' => array(
					'min'  => 0,
					'max'  => 1,
					'step' => 0.01,
				),
			),
		);

		$this->add_control(
			'overlay_opacity',
			array_merge(
				$opacity,
				array(
					'label'     => esc_html__( 'Overlay opacity', 'listemann-toolkit-addon' ),
					'default'   => array( 'size' => 0.35 ),
					'selectors' => array(
						'{{WRAPPER}}' => '--lst-overlay-opacity: {{SIZE}};',
					),
				)
			)
		);

		$this->add_control(
			'hover_effect',
			array(
				'label'        => esc_html__( 'Hover effect', 'listemann-toolkit-addon' ),
				'type'         => Controls_Manager::SELECT,
				'options'      => array(
					'title'  => esc_html__( 'Title turns red', 'listemann-toolkit-addon' ),
					'zoom'   => esc_html__( 'Image zoom', 'listemann-toolkit-addon' ),
					'darken' => esc_html__( 'Darker overlay', 'listemann-toolkit-addon' ),
					'none'   => esc_html__( 'None', 'listemann-toolkit-addon' ),
				),
				'default'      => 'title',
				'prefix_class' => 'lst-posts-hover-',
				'separator'    => 'before',
				'description'  => esc_html__( 'Applies when the pointer is over the card or the card link has keyboard focus. A red title is hard to read on dark photos: zoom or a darker overlay keep the white text.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'overlay_hover_opacity',
			array_merge(
				$opacity,
				array(
					'label'       => esc_html__( 'Overlay opacity on hover', 'listemann-toolkit-addon' ),
					'description' => esc_html__( 'Default: unchanged; 0.6 with "Darker overlay".', 'listemann-toolkit-addon' ),
					'selectors'   => array(
						'{{WRAPPER}}' => '--lst-overlay-hover-opacity: {{SIZE}};',
					),
				)
			)
		);

		$this->add_control(
			'zoom_scale',
			array(
				'label'     => esc_html__( 'Zoom factor', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min'  => 1,
						'max'  => 1.5,
						'step' => 0.01,
					),
				),
				'default'   => array( 'size' => 1.06 ),
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-zoom: {{SIZE}};',
				),
				'condition' => array(
					'hover_effect' => 'zoom',
				),
			)
		);

		$this->add_control(
			'hover_transition',
			array(
				'label'      => esc_html__( 'Transition duration', 'listemann-toolkit-addon' ),
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
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-duration: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'card_shadow_tabs' );

		$this->start_controls_tab(
			'card_shadow_normal',
			array( 'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ) )
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_shadow',
				'selector' => '{{WRAPPER}} .lst-posts__item',
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'card_shadow_hover',
			array( 'label' => esc_html__( 'Hover', 'listemann-toolkit-addon' ) )
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_shadow_hover',
				'selector' => '{{WRAPPER}} .lst-posts__item:hover, {{WRAPPER}} .lst-posts__item:focus-within',
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_color_control(
			'focus_color',
			esc_html__( 'Focus outline color', 'listemann-toolkit-addon' ),
			'lst-focus-color',
			'#dd191a',
			array(
				'separator'   => 'before',
				'description' => esc_html__( 'Ring around the card, checkbox or button that has keyboard focus. Needs a contrast of at least 3:1 to the page background.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_responsive_control(
			'content_padding',
			array(
				'label'      => esc_html__( 'Text padding', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem', '%' ),
				'default'    => array(
					'top'      => '25',
					'right'    => '15',
					'bottom'   => '0',
					'left'     => '25',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'separator'  => 'before',
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-pad-top: {{TOP}}{{UNIT}}; --lst-pad-right: {{RIGHT}}{{UNIT}}; --lst-pad-bottom: {{BOTTOM}}{{UNIT}}; --lst-pad-left: {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: date, title, author, categories and excerpt.
	 */
	private function register_text_style_controls() {
		$white = esc_html__( 'Default: white (list layout: grey).', 'listemann-toolkit-addon' );

		// Date.
		$this->start_controls_section(
			'section_style_date',
			array(
				'label'     => esc_html__( 'Date', 'listemann-toolkit-addon' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_date' => 'yes',
				),
			)
		);

		$this->add_color_control( 'date_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), 'lst-date-color', '', array( 'description' => $white ) );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'date_typography',
				'selector' => '{{WRAPPER}} .lst-posts__date',
			)
		);

		$this->end_controls_section();

		// Title.
		$this->start_controls_section(
			'section_style_title',
			array(
				'label' => esc_html__( 'Title', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->start_controls_tabs( 'title_color_tabs' );

		$this->start_controls_tab(
			'title_color_normal',
			array( 'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ) )
		);

		$this->add_color_control( 'title_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), 'lst-title-color', '', array( 'description' => esc_html__( 'Default: white (list layout: dark grey).', 'listemann-toolkit-addon' ) ) );

		$this->end_controls_tab();

		$this->start_controls_tab(
			'title_color_hover',
			array( 'label' => esc_html__( 'Hover', 'listemann-toolkit-addon' ) )
		);

		$this->add_color_control(
			'title_hover_color',
			esc_html__( 'Color', 'listemann-toolkit-addon' ),
			'lst-title-hover-color',
			'#dd191a',
			array( 'description' => esc_html__( 'Used by the hover effect "Title turns red".', 'listemann-toolkit-addon' ) )
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'        => 'title_typography',
				'selector'    => '{{WRAPPER}} .lst-posts__title',
				'separator'   => 'before',
				'description' => esc_html__( 'Default: 24 px / 36 px; 18 / 33.6 px below 1280 px.', 'listemann-toolkit-addon' ),
			)
		);

		$this->end_controls_section();

		// Author.
		$this->start_controls_section(
			'section_style_author',
			array(
				'label'     => esc_html__( 'Author', 'listemann-toolkit-addon' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_author' => 'yes',
				),
			)
		);

		$this->add_color_control( 'author_color', esc_html__( 'Color', 'listemann-toolkit-addon' ), 'lst-author-color', '', array( 'description' => $white ) );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'        => 'author_typography',
				'selector'    => '{{WRAPPER}} .lst-posts__author-name',
				'description' => esc_html__( 'Default: 16 px; 14 px below 1280 px.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_size_control(
			'avatar_size',
			esc_html__( 'Picture size', 'listemann-toolkit-addon' ),
			'lst-avatar-size',
			array(
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 16,
						'max' => 120,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 55,
				),
				'condition'  => array(
					'show_avatar' => 'yes',
				),
			),
			false
		);

		$this->add_size_control(
			'author_gap',
			esc_html__( 'Space after the picture', 'listemann-toolkit-addon' ),
			'lst-author-gap',
			array(
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'unit' => 'px',
					'size' => 20,
				),
				'condition'  => array(
					'show_avatar' => 'yes',
				),
			),
			false
		);

		$this->add_size_control(
			'author_bottom',
			esc_html__( 'Distance from the bottom', 'listemann-toolkit-addon' ),
			'lst-author-bottom',
			array(
				'default' => array(
					'unit' => 'px',
					'size' => 30,
				),
			)
		);

		$this->add_size_control(
			'author_left',
			esc_html__( 'Distance from the left', 'listemann-toolkit-addon' ),
			'lst-author-left',
			array(
				'default'     => array(
					'unit' => 'px',
					'size' => 25,
				),
				'description' => esc_html__( 'Measured from the card edge (in the list layout: from the text area).', 'listemann-toolkit-addon' ),
			)
		);

		$this->end_controls_section();

		// Categories and excerpt.
		$this->start_controls_section(
			'section_style_meta',
			array(
				'label'      => esc_html__( 'Categories and excerpt', 'listemann-toolkit-addon' ),
				'tab'        => Controls_Manager::TAB_STYLE,
				'conditions' => array(
					'relation' => 'or',
					'terms'    => array(
						array(
							'name'  => 'show_categories',
							'value' => 'yes',
						),
						array(
							'name'  => 'show_excerpt',
							'value' => 'yes',
						),
					),
				),
			)
		);

		$this->add_color_control( 'badge_color', esc_html__( 'Category text color', 'listemann-toolkit-addon' ), 'lst-badge-color', '#ffffff' );
		$this->add_color_control( 'badge_background', esc_html__( 'Category background', 'listemann-toolkit-addon' ), 'lst-badge-bg', '#dd191a' );

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'badge_typography',
				'label'    => esc_html__( 'Category typography', 'listemann-toolkit-addon' ),
				'selector' => '{{WRAPPER}} .lst-posts__badge',
			)
		);

		$this->add_color_control(
			'excerpt_color',
			esc_html__( 'Excerpt color', 'listemann-toolkit-addon' ),
			'lst-excerpt-color',
			'',
			array(
				'separator'   => 'before',
				'description' => $white,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'excerpt_typography',
				'label'    => esc_html__( 'Excerpt typography', 'listemann-toolkit-addon' ),
				'selector' => '{{WRAPPER}} .lst-posts__excerpt',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: "load more" button and page numbers.
	 */
	private function register_button_style_controls() {
		$this->start_controls_section(
			'section_style_button',
			array(
				'label'     => esc_html__( 'Load more button', 'listemann-toolkit-addon' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'pagination!' => 'none',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'        => 'button_typography',
				'selector'    => '{{WRAPPER}} .lst-posts__more, {{WRAPPER}} .lst-posts__pagination .page-numbers',
				'description' => esc_html__( 'Default: 20 px, uppercase, 3 px letter spacing; 18 px below 768 px.', 'listemann-toolkit-addon' ),
			)
		);

		$this->start_controls_tabs( 'button_tabs' );

		$this->start_controls_tab(
			'button_normal',
			array( 'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ) )
		);

		$this->add_color_control( 'button_color', esc_html__( 'Text color', 'listemann-toolkit-addon' ), 'lst-button-color', '#ffffff' );
		$this->add_color_control( 'button_background', esc_html__( 'Background color', 'listemann-toolkit-addon' ), 'lst-button-bg', '#dd191a' );
		$this->add_color_control( 'button_border_color', esc_html__( 'Border color', 'listemann-toolkit-addon' ), 'lst-button-border-color', '#dd191a' );

		$this->end_controls_tab();

		$this->start_controls_tab(
			'button_hover',
			array( 'label' => esc_html__( 'Hover', 'listemann-toolkit-addon' ) )
		);

		$this->add_color_control( 'button_hover_color', esc_html__( 'Text color', 'listemann-toolkit-addon' ), 'lst-button-hover-color', '#dd191a' );
		$this->add_color_control( 'button_hover_background', esc_html__( 'Background color', 'listemann-toolkit-addon' ), 'lst-button-hover-bg', 'rgba(0, 0, 0, 0)' );
		$this->add_color_control( 'button_hover_border_color', esc_html__( 'Border color', 'listemann-toolkit-addon' ), 'lst-button-hover-border-color', '#dd191a' );

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_size_control(
			'button_border_width',
			esc_html__( 'Border width', 'listemann-toolkit-addon' ),
			'lst-button-border',
			array(
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 10,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 3,
				),
				'separator'  => 'before',
			),
			false
		);

		$this->add_size_control(
			'button_radius',
			esc_html__( 'Border radius', 'listemann-toolkit-addon' ),
			'lst-button-radius',
			array(
				'size_units' => array( 'px', '%', 'em' ),
				'default'    => array(
					'unit' => 'px',
					'size' => 0,
				),
			),
			false
		);

		$this->add_responsive_control(
			'button_padding',
			array(
				'label'       => esc_html__( 'Padding', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'description' => esc_html__( 'Default: 17 px 105 px; 10 px 0 below 768 px.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-button-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'button_width',
			array(
				'label'                => esc_html__( 'Width', 'listemann-toolkit-addon' ),
				'type'                 => Controls_Manager::SELECT,
				'options'              => array(
					''     => esc_html__( 'Default', 'listemann-toolkit-addon' ),
					'auto' => esc_html__( 'Fit the text', 'listemann-toolkit-addon' ),
					'full' => esc_html__( 'Full width', 'listemann-toolkit-addon' ),
				),
				'default'              => '',
				'description'          => esc_html__( 'Default: fit the text, full width below 768 px.', 'listemann-toolkit-addon' ),
				'selectors_dictionary' => array(
					'auto' => '--lst-button-width: auto;',
					'full' => '--lst-button-width: 100%;',
				),
				'selectors'            => array(
					'{{WRAPPER}}' => '{{VALUE}}',
				),
			)
		);

		$this->add_size_control(
			'button_spacing',
			esc_html__( 'Spacing above', 'listemann-toolkit-addon' ),
			'lst-button-gap',
			array(
				'description' => esc_html__( 'Default: 80 px; 65 px below 1280 px, 50 px below 768 px.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_size_control(
			'button_spacing_bottom',
			esc_html__( 'Spacing below', 'listemann-toolkit-addon' ),
			'lst-button-after',
			array(
				'description' => esc_html__( 'Default: 100 px; 75 px below 768 px.', 'listemann-toolkit-addon' ),
			)
		);

		$this->end_controls_section();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Control options
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Whether option lists (terms, authors) are needed: only the editor shows
	 * them. Saved settings never depend on them.
	 *
	 * @return bool
	 */
	private function needs_options() {
		return is_admin() || ( isset( Plugin::$instance->preview ) && Plugin::$instance->preview->is_preview_mode() );
	}

	/**
	 * Public post types (attachments and Elementor templates excluded).
	 *
	 * @return array<string, string>
	 */
	private function get_post_type_options() {
		$options = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( in_array( $name, self::EXCLUDED_POST_TYPES, true ) ) {
				continue;
			}

			$options[ $name ] = $object->labels->name;
		}

		return $options ? $options : array( 'post' => esc_html__( 'Posts', 'listemann-toolkit-addon' ) );
	}

	/**
	 * Terms of a taxonomy, keyed "term-{id}".
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array<string, string>
	 */
	private function get_term_options( $taxonomy ) {
		if ( ! $this->needs_options() ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 500,
				'orderby'    => 'name',
			)
		);

		$options = array();

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$options[ self::TERM_KEY_PREFIX . $term->term_id ] = $term->name;
			}
		}

		return $options;
	}

	/**
	 * Users who have published posts, keyed "user-{id}".
	 *
	 * @return array<string, string>
	 */
	private function get_author_options() {
		if ( ! $this->needs_options() ) {
			return array();
		}

		$options = array();
		$users   = get_users(
			array(
				'has_published_posts' => true,
				'orderby'             => 'display_name',
				'number'              => 200,
				'fields'              => array( 'ID', 'display_name' ),
			)
		);

		foreach ( $users as $user ) {
			$options[ self::USER_KEY_PREFIX . $user->ID ] = $user->display_name;
		}

		return $options;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Query
	 * ---------------------------------------------------------------------
	 */

	/**
	 * IDs from a list of option keys ("term-12") or bare IDs.
	 *
	 * @param mixed  $values Saved setting (array or scalar).
	 * @param string $prefix Option key prefix.
	 * @return int[] Unique positive IDs.
	 */
	public static function parse_ids( $values, $prefix ) {
		$ids = array();

		foreach ( (array) $values as $value ) {
			if ( is_scalar( $value ) && preg_match( '/^(?:' . preg_quote( $prefix, '/' ) . ')?([1-9]\d*)$/', (string) $value, $matches ) ) {
				$ids[] = (int) $matches[1];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Reads a whole number from the settings and clamps it.
	 *
	 * @param array  $settings Settings.
	 * @param string $key      Setting key.
	 * @param int    $default  Default.
	 * @param int    $min      Minimum.
	 * @param int    $max      Maximum.
	 * @return int
	 */
	private static function get_int( array $settings, $key, $default, $min, $max ) {
		$value = isset( $settings[ $key ] ) && is_numeric( $settings[ $key ] ) ? (int) $settings[ $key ] : $default;

		return max( $min, min( $max, $value ) );
	}

	/**
	 * Normalized query settings (also used by the REST route and the tests).
	 *
	 * @param array $settings Widget settings.
	 * @return array
	 */
	public static function get_query_settings( array $settings ) {
		$post_type = isset( $settings['post_type'] ) && is_string( $settings['post_type'] ) ? sanitize_key( $settings['post_type'] ) : 'post';

		if ( ! post_type_exists( $post_type ) || ! is_post_type_viewable( $post_type ) || in_array( $post_type, self::EXCLUDED_POST_TYPES, true ) ) {
			$post_type = 'post';
		}

		$is_post = 'post' === $post_type;
		$orderby = isset( $settings['orderby'] ) && in_array( $settings['orderby'], self::ORDERBY, true ) ? $settings['orderby'] : 'date';

		return array(
			'source'             => isset( $settings['source'] ) && 'current' === $settings['source'] ? 'current' : 'posts',
			'post_type'          => $post_type,
			'include_categories' => $is_post ? self::parse_ids( $settings['include_categories'] ?? array(), self::TERM_KEY_PREFIX ) : array(),
			'exclude_categories' => $is_post ? self::parse_ids( $settings['exclude_categories'] ?? array(), self::TERM_KEY_PREFIX ) : array(),
			'include_tags'       => $is_post ? self::parse_ids( $settings['include_tags'] ?? array(), self::TERM_KEY_PREFIX ) : array(),
			'authors'            => self::parse_ids( $settings['include_authors'] ?? array(), self::USER_KEY_PREFIX ),
			'orderby'            => $orderby,
			'order'              => isset( $settings['order'] ) && 'ASC' === $settings['order'] ? 'ASC' : 'DESC',
			'first'              => self::get_int( $settings, 'posts_per_page', 9, 1, self::MAX_PER_PAGE ),
			'more'               => self::get_int( $settings, 'posts_per_load', 6, 1, self::MAX_PER_PAGE ),
			'offset'             => self::get_int( $settings, 'offset', 0, 0, self::MAX_OFFSET ),
			'exclude_current'    => 'yes' === ( $settings['exclude_current'] ?? '' ),
			'sticky'             => $is_post && 'yes' !== ( $settings['ignore_sticky'] ?? '' ),
			'only_with_image'    => 'yes' === ( $settings['only_with_image'] ?? '' ),
		);
	}

	/**
	 * Position of a page in the list of posts: the first page shows `first`
	 * posts, every further page `more`, all after `offset` skipped posts.
	 *
	 * @param int $page   Page number (1-based).
	 * @param int $first  Posts on the first page.
	 * @param int $more   Posts per further page.
	 * @param int $offset Skipped posts.
	 * @return array{start: int, count: int} Zero-based start and number of posts.
	 */
	public static function get_page_window( $page, $first, $more, $offset = 0 ) {
		$page = max( 1, (int) $page );

		if ( 1 === $page ) {
			return array(
				'start' => (int) $offset,
				'count' => (int) $first,
			);
		}

		return array(
			'start' => (int) $offset + (int) $first + ( $page - 2 ) * (int) $more,
			'count' => (int) $more,
		);
	}

	/**
	 * Number of pages for a number of posts (after the offset).
	 *
	 * @param int $total Posts.
	 * @param int $first Posts on the first page.
	 * @param int $more  Posts per further page.
	 * @return int
	 */
	public static function get_max_pages( $total, $first, $more ) {
		$total = (int) $total;

		if ( $total <= 0 ) {
			return 0;
		}

		if ( $total <= $first ) {
			return 1;
		}

		return 1 + (int) ceil( ( $total - $first ) / max( 1, (int) $more ) );
	}

	/**
	 * WP_Query arguments shared by every page: post type, taxonomy filters,
	 * authors, order. Pagination is added by query_posts().
	 *
	 * Only published posts are listed (no drafts, no private posts, also not
	 * for logged-in editors, so the editor shows what visitors see).
	 *
	 * @param array $query      Normalized query settings (get_query_settings()).
	 * @param int[] $cats       Filter terms (already checked against the allowed terms).
	 * @param int   $exclude_id Post to leave out (the current post), 0 for none.
	 * @param int   $seed       Seed for random order (keeps the order across pages).
	 * @return array
	 */
	public static function build_query_args( array $query, array $cats = array(), $exclude_id = 0, $seed = 0 ) {
		if ( 'rand' === $query['orderby'] ) {
			$orderby = $seed ? 'RAND(' . absint( $seed ) . ')' : 'rand';
		} else {
			// The ID keeps the order stable between pages when dates (or titles) are equal.
			$orderby = array(
				$query['orderby'] => $query['order'],
				'ID'              => $query['order'],
			);
		}

		$args = array(
			'post_type'           => $query['post_type'],
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'orderby'             => $orderby,
			'suppress_filters'    => false,
			'lst_posts_widget'    => true, // Lets sites target these queries in pre_get_posts.
		);

		$tax_query = array();

		if ( $query['include_categories'] ) {
			$tax_query[] = array(
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => $query['include_categories'],
			);
		}

		if ( $query['exclude_categories'] ) {
			$tax_query[] = array(
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => $query['exclude_categories'],
				'operator' => 'NOT IN',
			);
		}

		if ( $query['include_tags'] ) {
			$tax_query[] = array(
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => $query['include_tags'],
			);
		}

		if ( $cats ) {
			$tax_query[] = array(
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => array_values( array_map( 'intval', $cats ) ),
			);
		}

		if ( $tax_query ) {
			$tax_query['relation'] = 'AND';
			$args['tax_query']     = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		if ( $query['authors'] ) {
			$args['author__in'] = $query['authors'];
		}

		if ( $query['only_with_image'] ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'EXISTS',
				),
			);
		}

		if ( $query['exclude_current'] && $exclude_id ) {
			$args['post__not_in'] = array( (int) $exclude_id ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
		}

		return $args;
	}

	/**
	 * Queries one page of posts.
	 *
	 * Sticky posts (when not ignored) come first, before the regular posts:
	 * the list is treated as "sticky posts, then the rest", and the page
	 * window is cut out of it, so sticky posts appear exactly once, also with
	 * an offset and on later pages.
	 *
	 * @param array $query      Normalized query settings.
	 * @param int   $page       Page number.
	 * @param int[] $cats       Filter terms.
	 * @param int   $exclude_id Post to leave out.
	 * @param int   $seed       Seed for random order.
	 * @return array{posts: \WP_Post[], found: int, max_pages: int, page: int, query: \WP_Query|null}
	 */
	public static function query_posts( array $query, $page, array $cats = array(), $exclude_id = 0, $seed = 0 ) {
		$page   = max( 1, (int) $page );
		$args   = self::build_query_args( $query, $cats, $exclude_id, $seed );
		$window = self::get_page_window( $page, $query['first'], $query['more'], $query['offset'] );
		$sticky = array();

		if ( $query['sticky'] ) {
			$sticky_ids = array_diff( array_map( 'intval', (array) get_option( 'sticky_posts', array() ) ), (array) ( $args['post__not_in'] ?? array() ) );

			if ( $sticky_ids ) {
				$sticky_query = new \WP_Query(
					array_merge(
						$args,
						array(
							'post__in'       => array_values( $sticky_ids ),
							'posts_per_page' => min( count( $sticky_ids ), 500 ),
							'fields'         => 'ids',
							'no_found_rows'  => true,
						)
					)
				);
				$sticky       = array_map( 'intval', $sticky_query->posts );
			}
		}

		$sticky_count = count( $sticky );
		$sticky_page  = array_slice( $sticky, $window['start'], $window['count'] );
		$remaining    = $window['count'] - count( $sticky_page );

		if ( $sticky ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), $sticky ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
		}

		$main = new \WP_Query(
			array_merge(
				$args,
				array(
					// One post at least: found_posts is needed even when sticky posts fill the page.
					'posts_per_page' => max( 1, $remaining ),
					'offset'         => max( 0, $window['start'] - $sticky_count ),
				)
			)
		);

		$posts = array();

		if ( $sticky_page ) {
			$posts = get_posts(
				array(
					'post__in'            => $sticky_page,
					'post_type'           => $query['post_type'],
					'post_status'         => 'publish',
					'orderby'             => 'post__in',
					'posts_per_page'      => count( $sticky_page ),
					'ignore_sticky_posts' => true,
					'suppress_filters'    => false,
				)
			);
		}

		if ( $remaining > 0 ) {
			$posts = array_merge( $posts, array_slice( $main->posts, 0, $remaining ) );
		}

		$found = max( 0, $sticky_count + (int) $main->found_posts - $query['offset'] );

		update_post_thumbnail_cache( $main );

		return array(
			'posts'     => $posts,
			'found'     => $found,
			'max_pages' => self::get_max_pages( $found, $query['first'], $query['more'] ),
			'page'      => $page,
			'query'     => $main,
		);
	}

	/**
	 * Categories shown in the filter.
	 *
	 * @param array $settings Widget settings.
	 * @param array $query    Normalized query settings.
	 * @return \WP_Term[]
	 */
	public static function get_filter_terms( array $settings, array $query ) {
		if ( 'posts' !== $query['source'] || 'post' !== $query['post_type'] || 'yes' !== ( $settings['show_filter'] ?? '' ) ) {
			return array();
		}

		$args = array(
			'taxonomy'   => 'category',
			'hide_empty' => 'yes' === ( $settings['filter_hide_empty'] ?? '' ),
			'orderby'    => 'name',
			'number'     => 100,
		);

		if ( 'custom' === ( $settings['filter_terms_source'] ?? '' ) ) {
			$ids = self::parse_ids( $settings['filter_terms'] ?? array(), self::TERM_KEY_PREFIX );

			if ( ! $ids ) {
				return array();
			}

			$args['include'] = $ids;
			$args['orderby'] = 'include';
		} elseif ( $query['include_categories'] ) {
			$args['include'] = $query['include_categories'];
		}

		if ( $query['exclude_categories'] && empty( $args['include'] ) ) {
			$args['exclude'] = $query['exclude_categories'];
		}

		$terms = get_terms( $args );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$terms,
				static function ( $term ) use ( $query ) {
					return ! in_array( (int) $term->term_id, $query['exclude_categories'], true );
				}
			)
		);
	}

	/**
	 * Keeps only the requested terms that the filter offers.
	 *
	 * @param mixed      $requested Requested term IDs (any input).
	 * @param \WP_Term[] $allowed   Terms of the filter.
	 * @param string     $mode      multiple or single.
	 * @return int[]
	 */
	public static function sanitize_filter_terms( $requested, array $allowed, $mode = 'multiple' ) {
		$allowed_ids = array_map(
			static function ( $term ) {
				return (int) $term->term_id;
			},
			$allowed
		);

		$ids = array_values( array_intersect( self::parse_ids( is_array( $requested ) ? $requested : array( $requested ), '' ), $allowed_ids ) );

		return 'single' === $mode ? array_slice( $ids, 0, 1 ) : $ids;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Date of a post in the configured format, localized (wp_date()).
	 *
	 * @param \WP_Post $post     Post.
	 * @param array    $settings Widget settings.
	 * @return string Unescaped text.
	 */
	public static function format_date( $post, array $settings ) {
		$format = 'site' === ( $settings['date_format'] ?? '' ) ? '' : trim( wp_strip_all_tags( (string) ( $settings['date_format_custom'] ?? '' ) ) );

		if ( '' === $format ) {
			$format = (string) get_option( 'date_format', 'd.m.Y' );
		}

		$timestamp = get_post_timestamp( $post );

		return $timestamp ? (string) wp_date( $format, $timestamp ) : '';
	}

	/**
	 * Page and filter requested through the query string (no-JS fallback).
	 * Applies to this widget when the request names it, or names no widget.
	 *
	 * @param \WP_Term[] $terms Terms of the filter.
	 * @param string     $mode  Filter mode.
	 * @return array{page: int, cats: int[]}
	 */
	private function get_request_state( array $terms, $mode ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public, read-only view state (filter and page), like a search form.
		$target = isset( $_GET[ self::ARG_ELEMENT ] ) && is_string( $_GET[ self::ARG_ELEMENT ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG_ELEMENT ] ) ) : '';

		if ( '' !== $target && $target !== $this->get_id() ) {
			return array(
				'page' => 1,
				'cats' => array(),
			);
		}

		$page = isset( $_GET[ self::ARG_PAGE ] ) && is_scalar( $_GET[ self::ARG_PAGE ] ) ? absint( $_GET[ self::ARG_PAGE ] ) : 1;
		$cats = isset( $_GET[ self::ARG_CATS ] ) ? map_deep( wp_unslash( $_GET[ self::ARG_CATS ] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by map_deep().
		// phpcs:enable

		return array(
			'page' => max( 1, min( 100000, $page ) ),
			'cats' => $terms ? self::sanitize_filter_terms( $cats, $terms, $mode ) : array(),
		);
	}

	/**
	 * URL the filter form and the pagination links are based on: the current
	 * page without this widget's query arguments.
	 *
	 * @return string
	 */
	private function get_base_url() {
		$document = Plugin::$instance->documents->get_current();

		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			$url = $document ? get_permalink( $document->get_main_id() ) : '';

			return $url ? $url : home_url( '/' );
		}

		// Scheme and host of the site (not of the Host header), path and query of the request.
		$home    = wp_parse_url( home_url( '/' ) );
		$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$origin  = ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

		return self::clean_url( $origin . '/' . ltrim( $request, '/' ) );
	}

	/**
	 * Removes this widget's query arguments (and the fragment) from a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function clean_url( $url ) {
		$url = strtok( (string) $url, '#' );

		return remove_query_arg( array( self::ARG_PAGE, self::ARG_CATS, self::ARG_ELEMENT ), $url );
	}

	/**
	 * Renders the widget.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$query    = self::get_query_settings( $settings );
		$layout   = in_array( $settings['layout'] ?? '', self::LAYOUTS, true ) ? $settings['layout'] : 'featured';
		$mode     = 'single' === ( $settings['filter_mode'] ?? '' ) ? 'single' : 'multiple';
		$terms    = self::get_filter_terms( $settings, $query );
		$state    = 'posts' === $query['source'] ? $this->get_request_state( $terms, $mode ) : array(
			'page' => max( 1, (int) get_query_var( 'paged' ) ),
			'cats' => array(),
		);
		$base_url = $this->get_base_url();
		$exclude  = $this->get_current_post_id();
		$seed     = 'rand' === $query['orderby'] ? wp_rand( 1, 99999 ) : 0;

		if ( 'current' === $query['source'] ) {
			$result = $this->get_current_query_result();
		} else {
			$result = self::query_posts( $query, $state['page'], $state['cats'], $exclude, $seed );
		}

		$root_id = $this->get_unique_root_id();
		$classes = array( 'lst-posts', 'lst-posts--' . $layout );
		$lines   = self::get_int( $settings, 'title_lines', 4, 0, 12 );

		if ( $lines ) {
			$classes[] = 'lst-posts--clamp';
		}

		$document = Plugin::$instance->documents->get_current();
		$config   = array(
			'element'    => $this->get_id(),
			'document'   => $document ? (int) $document->get_main_id() : 0,
			'source'     => $query['source'],
			'pagination' => $this->get_pagination_type( $settings ),
			'page'       => (int) $result['page'],
			'maxPages'   => (int) $result['max_pages'],
			'found'      => (int) $result['found'],
			'cats'       => $state['cats'],
			'exclude'    => (int) $exclude,
			'seed'       => (int) $seed,
			'rest'       => Posts_Rest::get_url(),
			'error'      => __( 'The posts could not be loaded. Please try again.', 'listemann-toolkit-addon' ),
			'saveHint'   => __( 'Save the page to try the filter and "load more" in the preview.', 'listemann-toolkit-addon' ),
		);

		if ( is_user_logged_in() ) {
			// Lets logged-in users load more posts from drafts and private pages (preview, editor).
			$config['nonce'] = wp_create_nonce( 'wp_rest' );
		}

		$this->add_render_attribute(
			'root',
			array(
				'id'               => $root_id,
				'class'            => $classes,
				'data-lst-posts'   => wp_json_encode( $config ),
				'data-lst-element' => $this->get_id(),
			)
		);

		if ( $lines ) {
			$this->add_render_attribute( 'root', 'style', '--lst-title-lines: ' . $lines . ';' );
		}
		?>
		<div <?php $this->print_render_attribute_string( 'root' ); ?>>
			<?php
			$this->render_heading( $settings );

			if ( $terms ) {
				$this->render_filter( $settings, $terms, $state['cats'], $mode, $base_url, $root_id );
			}
			?>
			<div class="lst-posts__status lst-posts__sr" role="status" aria-atomic="true"></div>
			<div class="lst-posts__grid" id="<?php echo esc_attr( $root_id . '-grid' ); ?>">
				<?php $this->render_items( $result['posts'], $result['page'], $settings ); ?>
			</div>
			<p class="lst-posts__empty"<?php echo $result['posts'] ? ' hidden' : ''; ?>><?php echo esc_html( $this->get_empty_text( $settings ) ); ?></p>
			<?php
			echo $this->get_pagination_html( $settings, $result, $base_url, $state['cats'], $root_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_pagination_html().
			?>
		</div>
		<?php
	}

	/**
	 * Result of the main (archive) query, for the "current query" source.
	 *
	 * @return array{posts: \WP_Post[], found: int, max_pages: int, page: int, query: \WP_Query|null}
	 */
	private function get_current_query_result() {
		global $wp_query;

		$is_archive_query = $wp_query instanceof \WP_Query && ! $wp_query->is_singular() && ! Plugin::$instance->editor->is_edit_mode() && ! wp_doing_ajax();

		if ( ! $is_archive_query ) {
			// Editor / template preview: show the latest posts as a stand-in.
			$query = self::get_query_settings( array( 'posts_per_page' => (int) get_option( 'posts_per_page', 10 ) ) );

			return self::query_posts( $query, 1 );
		}

		update_post_thumbnail_cache( $wp_query );

		return array(
			'posts'     => $wp_query->posts,
			'found'     => (int) $wp_query->found_posts,
			'max_pages' => (int) $wp_query->max_num_pages,
			'page'      => max( 1, (int) get_query_var( 'paged' ) ),
			'query'     => $wp_query,
		);
	}

	/**
	 * Post to leave out ("Exclude the current post"): the post being viewed or
	 * the document being edited.
	 *
	 * @return int
	 */
	private function get_current_post_id() {
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}

		$document = Plugin::$instance->documents->get_current();

		return $document && ( wp_doing_ajax() || Plugin::$instance->editor->is_edit_mode() ) ? (int) $document->get_main_id() : 0;
	}

	/**
	 * Unique id of the widget root ("lst-posts-{element id}").
	 *
	 * @return string
	 */
	private function get_unique_root_id() {
		$base = 'lst-posts-' . $this->get_id();
		$id   = $base;
		$n    = 2;

		while ( in_array( $id, self::$used_ids, true ) ) {
			$id = $base . '-' . $n++;
		}

		self::$used_ids[] = $id;

		return $id;
	}

	/**
	 * Pagination type of the settings.
	 *
	 * @param array $settings Widget settings.
	 * @return string load_more, numbers or none.
	 */
	private function get_pagination_type( array $settings ) {
		return in_array( $settings['pagination'] ?? '', array( 'load_more', 'numbers', 'none' ), true ) ? $settings['pagination'] : 'load_more';
	}

	/**
	 * Visible text when no post matches.
	 *
	 * @param array $settings Widget settings.
	 * @return string
	 */
	private function get_empty_text( array $settings ) {
		$text = trim( wp_strip_all_tags( (string) ( $settings['empty_text'] ?? '' ) ) );

		return '' !== $text ? $text : __( 'No posts found.', 'listemann-toolkit-addon' );
	}

	/**
	 * Prints the heading.
	 *
	 * @param array $settings Widget settings.
	 */
	private function render_heading( array $settings ) {
		$text = trim( (string) ( $settings['heading_text'] ?? '' ) );

		if ( 'yes' !== ( $settings['show_heading'] ?? '' ) || '' === $text ) {
			return;
		}

		$tag = in_array( $settings['heading_tag'] ?? '', self::HEADING_TAGS, true ) ? $settings['heading_tag'] : 'h2';

		printf( '<%1$s class="lst-posts__heading">%2$s</%1$s>', tag_escape( $tag ), esc_html( wp_strip_all_tags( $text ) ) );
	}

	/**
	 * Prints the category filter: a GET form, so it also works without
	 * JavaScript; the script submits it in the background instead.
	 *
	 * @param array      $settings Widget settings.
	 * @param \WP_Term[] $terms    Terms.
	 * @param int[]      $active   Active term IDs.
	 * @param string     $mode     multiple or single.
	 * @param string     $base_url Base URL.
	 * @param string     $root_id  Id of the widget root.
	 */
	private function render_filter( array $settings, array $terms, array $active, $mode, $base_url, $root_id ) {
		$label  = trim( wp_strip_all_tags( (string) ( $settings['filter_label'] ?? '' ) ) );
		$type   = 'single' === $mode ? 'radio' : 'checkbox';
		$counts = 'yes' === ( $settings['filter_show_count'] ?? '' );
		$action = strtok( $base_url, '?' );
		$query  = array();

		wp_parse_str( (string) wp_parse_url( $base_url, PHP_URL_QUERY ), $query );

		printf( '<form class="lst-posts__filter" method="get" action="%s">', esc_url( $action . '#' . $root_id ) );

		// Keeps the other query arguments of the page (e.g. ?page_id=12) when the form is sent.
		foreach ( $query as $key => $value ) {
			foreach ( (array) $value as $sub_key => $sub_value ) {
				if ( is_scalar( $sub_value ) ) {
					printf(
						'<input type="hidden" name="%1$s" value="%2$s">',
						esc_attr( is_array( $value ) ? $key . '[' . ( is_int( $sub_key ) ? '' : $sub_key ) . ']' : $key ),
						esc_attr( (string) $sub_value )
					);
				}
			}
		}

		printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( self::ARG_ELEMENT ), esc_attr( $this->get_id() ) );

		echo '<fieldset class="lst-posts__fieldset">';
		printf(
			'<legend class="lst-posts__legend%1$s">%2$s</legend>',
			'' === $label ? ' lst-posts__sr' : '',
			esc_html( '' !== $label ? $label : __( 'Filter by category', 'listemann-toolkit-addon' ) )
		);
		echo '<div class="lst-posts__options">';

		if ( 'radio' === $type ) {
			$all = trim( wp_strip_all_tags( (string) ( $settings['filter_all_text'] ?? '' ) ) );

			$this->print_filter_option( $type, '', '' !== $all ? $all : __( 'All', 'listemann-toolkit-addon' ), ! $active, null );
		}

		foreach ( $terms as $term ) {
			$this->print_filter_option( $type, (string) $term->term_id, $term->name, in_array( (int) $term->term_id, $active, true ), $counts ? (int) $term->count : null );
		}

		echo '</div></fieldset>';
		printf( '<button type="submit" class="lst-posts__apply">%s</button>', esc_html__( 'Apply filter', 'listemann-toolkit-addon' ) );
		echo '</form>';
	}

	/**
	 * Prints one checkbox / radio button of the filter.
	 *
	 * @param string   $type    checkbox or radio.
	 * @param string   $value   Term ID ('' for "all").
	 * @param string   $label   Label.
	 * @param bool     $checked Whether it is checked.
	 * @param int|null $count   Post count to show, null for none.
	 */
	private function print_filter_option( $type, $value, $label, $checked, $count ) {
		printf(
			'<label class="lst-posts__option"><input type="%1$s" class="lst-posts__check" name="%2$s[]" value="%3$s"%4$s><span class="lst-posts__box" aria-hidden="true"></span><span class="lst-posts__option-label">%5$s%6$s</span></label>',
			esc_attr( $type ),
			esc_attr( self::ARG_CATS ),
			esc_attr( $value ),
			$checked ? ' checked' : '',
			esc_html( $label ),
			null === $count ? '' : ' <span class="lst-posts__count">(' . esc_html( number_format_i18n( $count ) ) . ')</span>'
		);
	}

	/**
	 * Prints the cards of one page.
	 *
	 * @param \WP_Post[] $posts    Posts.
	 * @param int        $page     Page number (the featured layout only applies to page 1).
	 * @param array      $settings Widget settings.
	 */
	public function render_items( array $posts, $page, array $settings ) {
		$layout   = in_array( $settings['layout'] ?? '', self::LAYOUTS, true ) ? $settings['layout'] : 'featured';
		$featured = 'featured' === $layout && 1 === (int) $page;
		$original = $GLOBALS['post'] ?? null;

		cache_users( wp_list_pluck( $posts, 'post_author' ) );

		foreach ( array_values( $posts ) as $index => $post ) {
			$variant = 'row';

			if ( $featured && 0 === $index ) {
				$variant = 'wide';
			} elseif ( $featured && $index < 3 ) {
				$variant = 'stacked';
			}

			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
			setup_postdata( $post );

			$this->render_card( $post, $variant, 1 === (int) $page && 0 === $index, $settings );
		}

		$GLOBALS['post'] = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the original post.

		if ( $original instanceof \WP_Post ) {
			setup_postdata( $original );
		}
	}

	/**
	 * Prints one card. The title link is the only link; it is stretched over
	 * the whole card (CSS), so the card has one tab stop and one accessible
	 * name.
	 *
	 * @param \WP_Post $post     Post.
	 * @param string   $variant  wide, stacked or row.
	 * @param bool     $is_first Whether it is the first card of the first page (image loads eagerly).
	 * @param array    $settings Widget settings.
	 */
	private function render_card( $post, $variant, $is_first, array $settings ) {
		$title_tag = in_array( $settings['title_tag'] ?? '', self::TITLE_TAGS, true ) ? $settings['title_tag'] : 'h3';
		$image     = $this->get_card_image_html( $post, $variant, $is_first, $settings );
		$new_tab   = 'yes' === ( $settings['new_tab'] ?? '' );
		$title     = get_the_title( $post );

		if ( '' === trim( wp_strip_all_tags( $title ) ) ) {
			$title = __( '(no title)', 'listemann-toolkit-addon' );
		}

		$classes = array( 'lst-posts__item', 'lst-posts__item--' . $variant );

		if ( ! $image ) {
			$classes[] = 'lst-posts__item--no-image';
		}

		printf( '<article class="%1$s" data-post-id="%2$d">', esc_attr( implode( ' ', $classes ) ), (int) $post->ID );
		echo '<div class="lst-posts__media">';
		echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_card_image_html().
		echo '<span class="lst-posts__overlay" aria-hidden="true"></span></div>';
		echo '<div class="lst-posts__content"><div class="lst-posts__main">';

		printf(
			'<%1$s class="lst-posts__title"><a class="lst-posts__link" href="%2$s"%3$s>%4$s%5$s</a></%1$s>',
			tag_escape( $title_tag ),
			esc_url( get_permalink( $post ) ),
			$new_tab ? ' target="_blank" rel="noopener"' : '',
			esc_html( wp_strip_all_tags( $title ) ),
			$new_tab ? '<span class="lst-posts__sr"> ' . esc_html__( '(opens in a new tab)', 'listemann-toolkit-addon' ) . '</span>' : ''
		);

		if ( 'yes' === ( $settings['show_date'] ?? '' ) ) {
			$date = self::format_date( $post, $settings );

			if ( '' !== $date ) {
				printf( '<time class="lst-posts__date" datetime="%1$s">%2$s</time>', esc_attr( (string) get_post_time( 'c', true, $post ) ), esc_html( $date ) );
			}
		}

		if ( 'yes' === ( $settings['show_categories'] ?? '' ) && is_object_in_taxonomy( $post->post_type, 'category' ) ) {
			$categories = get_the_category( $post->ID );

			if ( $categories ) {
				echo '<p class="lst-posts__badges">';

				foreach ( $categories as $category ) {
					printf( '<span class="lst-posts__badge">%s</span>', esc_html( $category->name ) );
				}

				echo '</p>';
			}
		}

		if ( 'yes' === ( $settings['show_excerpt'] ?? '' ) && ! post_password_required( $post ) ) {
			$length  = self::get_int( $settings, 'excerpt_length', 20, 1, 100 );
			$excerpt = wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post ) ), $length, '…' );

			if ( '' !== $excerpt ) {
				printf( '<p class="lst-posts__excerpt">%s</p>', esc_html( $excerpt ) );
			}
		}

		echo '</div>';

		if ( 'yes' === ( $settings['show_author'] ?? '' ) && post_type_supports( $post->post_type, 'author' ) ) {
			$this->render_author( $post, $settings );
		}

		echo '</div></article>';
	}

	/**
	 * Prints the author line (picture and name).
	 *
	 * @param \WP_Post $post     Post.
	 * @param array    $settings Widget settings.
	 */
	private function render_author( $post, array $settings ) {
		$author_id = (int) $post->post_author;
		$name      = $author_id ? get_the_author_meta( 'display_name', $author_id ) : '';

		if ( '' === $name ) {
			return;
		}

		$avatar = '';

		if ( 'yes' === ( $settings['show_avatar'] ?? '' ) ) {
			// Decorative: the name follows.
			$avatar = get_avatar(
				$author_id,
				55,
				'',
				'',
				array(
					'class'   => 'lst-posts__avatar',
					'loading' => 'lazy',
				)
			);
		}

		printf(
			'<div class="lst-posts__author%1$s">%2$s<span class="lst-posts__author-name"><span class="lst-posts__sr">%3$s </span>%4$s</span></div>',
			$avatar ? ' lst-posts__author--avatar' : '',
			$avatar ? wp_kses_post( $avatar ) : '',
			esc_html__( 'Written by', 'listemann-toolkit-addon' ),
			esc_html( $name )
		);
	}

	/**
	 * Image of a card: the featured image, else the fallback image.
	 *
	 * Uses `wp_get_attachment_image()` (srcset / sizes). The first card of the
	 * first page is usually above the fold and loads eagerly with a high
	 * priority, all other images lazily (on page views with Elementor's image
	 * loading optimization, Elementor applies the same rules). The alt text is
	 * the image's own (or empty): the title already names the link.
	 *
	 * @param \WP_Post $post     Post.
	 * @param string   $variant  wide, stacked or row.
	 * @param bool     $is_first Whether it is the first card.
	 * @param array    $settings Widget settings.
	 * @return string Escaped HTML, '' for none.
	 */
	private function get_card_image_html( $post, $variant, $is_first, array $settings ) {
		$id       = (int) get_post_thumbnail_id( $post );
		$fallback = isset( $settings['fallback_image'] ) && is_array( $settings['fallback_image'] ) ? $settings['fallback_image'] : array();

		if ( ! $id && ! empty( $fallback['id'] ) ) {
			$id = (int) $fallback['id'];
		}

		if ( $id && ! wp_attachment_is_image( $id ) ) {
			$id = 0;
		}

		$size  = ! empty( $settings['image_size'] ) ? $settings['image_size'] : 'large';
		$attrs = array(
			'class'    => 'lst-posts__image',
			'decoding' => 'async',
			'sizes'    => 'wide' === $variant ? '(max-width: 767px) 100vw, 66vw' : '(max-width: 767px) 100vw, 33vw',
		);

		$own_loading = ! self::elementor_optimizes_images();

		if ( $own_loading && $is_first ) {
			$attrs['loading']       = false;
			$attrs['fetchpriority'] = 'high';
		} elseif ( $own_loading ) {
			$attrs['loading'] = 'lazy';
		}

		$loading_html = '';

		if ( $own_loading ) {
			$loading_html = $is_first ? ' fetchpriority="high"' : ' loading="lazy"';
		}

		if ( $id ) {
			if ( 'custom' === $size ) {
				$src = Group_Control_Image_Size::get_attachment_image_src(
					$id,
					'image',
					array(
						'image'                  => array( 'id' => $id ),
						'image_size'             => 'custom',
						'image_custom_dimension' => $settings['image_custom_dimension'] ?? array(),
					)
				);

				if ( $src ) {
					return sprintf(
						'<img class="lst-posts__image" src="%1$s" alt="%2$s" decoding="async"%3$s>',
						esc_url( $src ),
						esc_attr( trim( wp_strip_all_tags( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) ),
						$loading_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute.
					);
				}

				$size = 'large';
			}

			$html = wp_get_attachment_image( $id, $size, false, $attrs );

			if ( $html ) {
				return $html;
			}
		}

		if ( ! empty( $fallback['url'] ) && empty( $fallback['id'] ) ) {
			return sprintf(
				'<img class="lst-posts__image" src="%1$s" alt="" decoding="async"%2$s>',
				esc_url( $fallback['url'] ),
				$loading_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute.
			);
		}

		return '';
	}

	/**
	 * Whether Elementor's "Optimized Image Loading" decides `loading` and
	 * `fetchpriority` for this output. It rewrites every `<img>` of a page
	 * view (first images eager, the largest early one with a high priority,
	 * the others lazy) and would duplicate attributes that are already set.
	 * Cards rendered for the REST route and the editor are not processed.
	 *
	 * @return bool
	 */
	private static function elementor_optimizes_images() {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		return class_exists( '\Elementor\Modules\ImageLoadingOptimization\Module' ) && '1' === get_option( 'elementor_optimized_image_loading', '1' );
	}

	/**
	 * Pagination markup: the "load more" link (a real link to the next page,
	 * which the script turns into a background request) or page numbers.
	 * Always returns the wrapper, so the script can replace it.
	 *
	 * @param array  $settings Widget settings.
	 * @param array  $result   Query result (page, max_pages).
	 * @param string $base_url Base URL of the links.
	 * @param int[]  $cats     Active filter terms (kept in the links).
	 * @param string $root_id  Id of the widget root (link fragment).
	 * @return string Escaped HTML.
	 */
	public function get_pagination_html( array $settings, array $result, $base_url, array $cats, $root_id = '' ) {
		$type    = $this->get_pagination_type( $settings );
		$page    = (int) $result['page'];
		$max     = (int) $result['max_pages'];
		$current = 'current' === ( $settings['source'] ?? '' );
		$root_id = '' !== $root_id ? $root_id : 'lst-posts-' . $this->get_id();

		if ( 'none' === $type || $max < 1 ) {
			return '<div class="lst-posts__pagination" hidden></div>';
		}

		$link = function ( $number ) use ( $base_url, $cats, $current, $root_id ) {
			if ( $current ) {
				return get_pagenum_link( $number );
			}

			$args = array( self::ARG_PAGE => $number > 1 ? $number : false );

			if ( $cats ) {
				$args[ self::ARG_CATS ] = array_values( $cats );
			}

			if ( $number > 1 || $cats ) {
				$args[ self::ARG_ELEMENT ] = $this->get_id();
			}

			return add_query_arg( $args, $base_url ) . '#' . $root_id;
		};

		if ( 'numbers' === $type ) {
			if ( $max < 2 ) {
				return '<div class="lst-posts__pagination" hidden></div>';
			}

			$items = array();

			for ( $number = 1; $number <= $max; $number++ ) {
				$near = abs( $number - $page ) <= 2 || 1 === $number || $max === $number;

				if ( ! $near ) {
					if ( '…' !== end( $items ) ) {
						$items[] = '…';
					}
					continue;
				}

				$items[] = $number;
			}

			$html = '';

			if ( $page > 1 ) {
				$html .= sprintf( '<a class="page-numbers prev" href="%1$s" rel="prev"><span aria-hidden="true">‹</span><span class="lst-posts__sr">%2$s</span></a>', esc_url( $link( $page - 1 ) ), esc_html__( 'Previous page', 'listemann-toolkit-addon' ) );
			}

			foreach ( $items as $item ) {
				if ( '…' === $item ) {
					$html .= '<span class="page-numbers dots" aria-hidden="true">…</span>';
				} elseif ( $item === $page ) {
					$html .= sprintf( '<span class="page-numbers current" aria-current="page"><span class="lst-posts__sr">%1$s </span>%2$s</span>', esc_html__( 'Page', 'listemann-toolkit-addon' ), esc_html( number_format_i18n( $item ) ) );
				} else {
					$html .= sprintf( '<a class="page-numbers" href="%1$s"><span class="lst-posts__sr">%2$s </span>%3$s</a>', esc_url( $link( $item ) ), esc_html__( 'Page', 'listemann-toolkit-addon' ), esc_html( number_format_i18n( $item ) ) );
				}
			}

			if ( $page < $max ) {
				$html .= sprintf( '<a class="page-numbers next" href="%1$s" rel="next"><span aria-hidden="true">›</span><span class="lst-posts__sr">%2$s</span></a>', esc_url( $link( $page + 1 ) ), esc_html__( 'Next page', 'listemann-toolkit-addon' ) );
			}

			return sprintf( '<nav class="lst-posts__pagination lst-posts__pagination--numbers" aria-label="%1$s">%2$s</nav>', esc_attr__( 'Pages', 'listemann-toolkit-addon' ), $html );
		}

		if ( $page < $max ) {
			$text    = trim( wp_strip_all_tags( (string) ( $settings['load_more_text'] ?? '' ) ) );
			$loading = trim( wp_strip_all_tags( (string) ( $settings['loading_text'] ?? '' ) ) );

			return sprintf(
				'<div class="lst-posts__pagination"><a class="lst-posts__more" href="%1$s" rel="next" data-lst-loading="%2$s">%3$s</a></div>',
				esc_url( $link( $page + 1 ) ),
				esc_attr( '' !== $loading ? $loading : __( 'Loading…', 'listemann-toolkit-addon' ) ),
				esc_html( '' !== $text ? $text : __( 'Load more', 'listemann-toolkit-addon' ) )
			);
		}

		$done = trim( wp_strip_all_tags( (string) ( $settings['no_more_text'] ?? '' ) ) );

		if ( $page > 1 && '' !== $done ) {
			return sprintf( '<div class="lst-posts__pagination"><p class="lst-posts__no-more">%s</p></div>', esc_html( $done ) );
		}

		return '<div class="lst-posts__pagination" hidden></div>';
	}

	/**
	 * Data for the REST route: one page of cards rendered from the saved
	 * settings. The filter terms are reduced to the ones the filter offers.
	 *
	 * @param int    $page       Page number.
	 * @param mixed  $cats       Requested filter terms.
	 * @param int    $exclude_id Post to leave out ("Exclude the current post").
	 * @param int    $seed       Seed for random order.
	 * @param string $base_url   URL of the page (for the pagination links).
	 * @return array{html: string, pagination: string, page: int, maxPages: int, found: int, count: int, cats: int[], message: string}
	 */
	public function get_rest_data( $page, $cats, $exclude_id, $seed, $base_url ) {
		$settings = $this->get_settings_for_display();
		$query    = self::get_query_settings( $settings );
		$mode     = 'single' === ( $settings['filter_mode'] ?? '' ) ? 'single' : 'multiple';
		$cats     = self::sanitize_filter_terms( $cats, self::get_filter_terms( $settings, $query ), $mode );
		$result   = self::query_posts( $query, $page, $cats, $exclude_id, $seed );

		ob_start();
		$this->render_items( $result['posts'], $result['page'], $settings );
		$html = (string) ob_get_clean();

		$count = count( $result['posts'] );

		if ( 1 === $result['page'] ) {
			$message = $count
				/* translators: %s: Number of posts. */
				? sprintf( _n( '%s post found.', '%s posts found.', $result['found'], 'listemann-toolkit-addon' ), number_format_i18n( $result['found'] ) )
				: $this->get_empty_text( $settings );
		} else {
			/* translators: %s: Number of posts. */
			$message = sprintf( _n( '%s more post loaded.', '%s more posts loaded.', $count, 'listemann-toolkit-addon' ), number_format_i18n( $count ) );

			if ( $result['page'] >= $result['max_pages'] ) {
				$message .= ' ' . __( 'All posts are shown.', 'listemann-toolkit-addon' );
			}
		}

		return array(
			'html'       => $html,
			'pagination' => $this->get_pagination_html( $settings, $result, $base_url, $cats ),
			'page'       => (int) $result['page'],
			'maxPages'   => (int) $result['max_pages'],
			'found'      => (int) $result['found'],
			'count'      => $count,
			'cats'       => $cats,
			'message'    => $message,
		);
	}
}
