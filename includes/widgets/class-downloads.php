<?php
/**
 * "Listemann Downloads" Elementor widget.
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Icons_Manager;
use Elementor\Modules\DynamicTags\Module as TagsModule;
use Elementor\Plugin as Elementor;
use Elementor\Repeater;
use Elementor\Utils;
use Elementor\Widget_Base;
use Listemann_Toolkit\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Download cards in titled groups, as on listemann.com/downloads/.
 *
 * Elementor has no nested repeaters, so the widget has one list of items:
 * a "Group title" item starts a group and the downloads after it belong to
 * that group. Downloads before the first group title form a group without a
 * title. Each download is a single link (the whole card), whose accessible
 * name contains the label, the title and, when needed, the file type and the
 * new-tab hint.
 *
 * Visual settings are written by Elementor as CSS custom properties
 * (--lst-dl-*) on the widget wrapper; the stylesheet falls back to the
 * original's values, including its 1280 px and 768 px steps for the layout
 * values that have no desktop default.
 */
class Downloads extends Widget_Base {

	/**
	 * Allowed tags of the heading.
	 */
	const HEADING_TAGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p' );

	/**
	 * Allowed tags of the group titles.
	 */
	const GROUP_TITLE_TAGS = array( 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p' );

	/**
	 * Token of the label template that is replaced with the file type.
	 */
	const TYPE_TOKEN = '{type}';

	/**
	 * Extensions of server scripts: such an address ("download.php?id=5")
	 * says nothing about the file it delivers, so its type is unknown.
	 */
	const SCRIPT_EXTENSIONS = array( 'php', 'php5', 'phtml', 'asp', 'aspx', 'jsp', 'cgi' );

	/**
	 * Default content: the groups and files of listemann.com/downloads/.
	 */
	const DEFAULT_GROUPS = array(
		'Zertifikate Liechtenstein' => array(
			'ISO9001 2027 DE' => 'https://listemann.com/wp-content/uploads/2024/12/Zertifikat-A4-ISO-9001-Haupt-Listemann_d.pdf',
			'ISO9001 2027 EN' => 'https://listemann.com/wp-content/uploads/2024/12/Zertifikat-A4-ISO-9001-Haupt-Listemann_e.pdf',
		),
		'Zertifikate Schweiz'       => array(
			'EN9100 2027 EN'               => 'https://listemann.com/wp-content/uploads/2024/12/2024_EN9100-Zertifikat-Listemann.pdf',
			'ISO9001 2027 DE'              => 'https://listemann.com/wp-content/uploads/2024/12/Zertifikat-A4-ISO-9001-Unter02-Listemann_d.pdf',
			'ISO9001 2027 EN'              => 'https://listemann.com/wp-content/uploads/2024/12/Zertifikat-A4-ISO-9001-Unter02-Listemann_e.pdf',
			'Nadcap Wärmebehandlung 2027'  => 'https://listemann.com/wp-content/uploads/2025/12/5129236328_HT-Certificate-bis-31.05.2027.pdf',
			'Nadcap Wärmebehandlung Scope' => 'https://listemann.com/wp-content/uploads/2025/12/5129236328_Scope-of-Accreditiation-HT.pdf',
			'Nadcap Schweissen 2027'       => 'https://listemann.com/wp-content/uploads/2025/12/5129229954_EBW-Certificate-bis-30.11.2027.pdf',
			'Nadcap Schweissen Scope'      => 'https://listemann.com/wp-content/uploads/2025/12/5129229954_Scope-of-Accreditiation-Welding.pdf',
		),
		'Zulassungen Schweiz'       => array(
			'Zulassung Luftfahrt Rolls Royce' => 'https://listemann.com/wp-content/uploads/2025/09/214846_OEM_CoA_154688-01.pdf',
		),
		'Zertifikate Polen'         => array(
			'ISO9001 2027 EN' => 'https://listemann.com/wp-content/uploads/2025/01/Zertifikat-A4-ISO-9001-Polen_EN.pdf',
			'ISO9001 2027 PL' => 'https://listemann.com/wp-content/uploads/2025/01/Zertifikat-A4-ISO-9001-Polen_PL.pdf',
		),
		'Warentransport & Zoll'     => array(
			'Versandinformation LI'  => 'https://listemann.com/wp-content/uploads/2021/03/Versandinformation-LI-D-1606288669.pdf',
			'Versandinformation CH'  => 'https://listemann.com/wp-content/uploads/2024/09/Versand-LAG-D-2024.pdf',
			'Zollrechnung Muster LI' => 'https://listemann.com/wp-content/uploads/2024/09/Zollrechnung-Muster-LI-D-2024.pdf',
			'Zollrechnung Muster CH' => 'https://listemann.com/wp-content/uploads/2024/09/Zollrechnung-Muster-CH-D-2024.pdf',
		),
	);

	/**
	 * Ids printed during this request (keeps them unique when the widget is
	 * rendered more than once on a page, e.g. from a template).
	 *
	 * @var string[]
	 */
	private static $used_ids = array();

	/**
	 * Sanitized bundled icons, keyed by name.
	 *
	 * @var string[]
	 */
	private static $icon_cache = array();

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'listemann-downloads';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'Listemann Downloads', 'listemann-toolkit-addon' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-file-download';
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
		return array( 'downloads', 'files', 'pdf', 'resources', 'certificates', 'ressourcen', 'documents', 'listemann' );
	}

	/**
	 * Style handles the widget depends on.
	 *
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( 'listemann-toolkit-downloads' );
	}

	/**
	 * Drops the `.elementor-widget-container` wrapper when Elementor's optimized
	 * markup is active. With the feature off, the Advanced tab targets that
	 * wrapper, so it has to stay. The widget CSS works with and without it.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper(): bool {
		return ! Elementor::$instance->experiments->is_feature_active( 'e_optimized_markup' );
	}

	/**
	 * The output only depends on the widget settings (and the attachments they
	 * point to), so Elementor may cache it; dynamic tags still disable the cache.
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
		$this->register_heading_controls();
		$this->register_items_controls();
		$this->register_options_controls();
		$this->register_layout_style_controls();
		$this->register_heading_style_controls();
		$this->register_group_title_style_controls();
		$this->register_card_style_controls();
		$this->register_icon_style_controls();
		$this->register_label_style_controls();
		$this->register_title_style_controls();
	}

	/**
	 * Content tab: heading above the groups.
	 *
	 * @return void
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
				'label'   => esc_html__( 'Show Heading', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'heading_text',
			array(
				'label'       => esc_html__( 'Heading', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array( 'active' => true ),
				'default'     => 'Ressourcen',
				'label_block' => true,
				'condition'   => array( 'show_heading' => 'yes' ),
			)
		);

		$this->add_control(
			'heading_tag',
			array(
				'label'     => esc_html__( 'HTML Tag', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => $this->get_tag_options( self::HEADING_TAGS ),
				'default'   => 'h2',
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: the list of group titles and downloads.
	 *
	 * @return void
	 */
	private function register_items_controls() {
		$this->start_controls_section(
			'section_items',
			array(
				'label' => esc_html__( 'Downloads', 'listemann-toolkit-addon' ),
			)
		);

		$is_file  = array( 'item_type' => 'file' );
		$repeater = new Repeater();

		$repeater->add_control(
			'item_type',
			array(
				'label'   => esc_html__( 'Type', 'listemann-toolkit-addon' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'file'  => esc_html__( 'Download', 'listemann-toolkit-addon' ),
					'group' => esc_html__( 'Group title', 'listemann-toolkit-addon' ),
				),
				'default' => 'file',
			)
		);

		$repeater->add_control(
			'group_title',
			array(
				'label'       => esc_html__( 'Group Title', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array( 'active' => true ),
				'label_block' => true,
				'description' => esc_html__( 'The downloads below this item, up to the next group title, belong to this group.', 'listemann-toolkit-addon' ),
				'condition'   => array( 'item_type' => 'group' ),
			)
		);

		$repeater->add_control(
			'title',
			array(
				'label'       => esc_html__( 'Title', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array( 'active' => true ),
				'label_block' => true,
				'condition'   => $is_file,
			)
		);

		$repeater->add_control(
			'source',
			array(
				'label'     => esc_html__( 'Source', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'media' => esc_html__( 'Media Library', 'listemann-toolkit-addon' ),
					'url'   => esc_html__( 'URL', 'listemann-toolkit-addon' ),
				),
				'default'   => 'media',
				'condition' => $is_file,
			)
		);

		$repeater->add_control(
			'file',
			array(
				'label'       => esc_html__( 'File', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::MEDIA,
				// One entry: the editor filters the media library by the first type only.
				// WordPress reads the comma-separated MIME groups, so every common file is listed.
				'media_types' => array( 'application,image,video,audio,text' ),
				'dynamic'     => array(
					'active'     => true,
					'categories' => array( TagsModule::MEDIA_CATEGORY ),
				),
				'default'     => array(
					'url' => '',
					'id'  => '',
				),
				'condition'   => array_merge( $is_file, array( 'source' => 'media' ) ),
			)
		);

		$repeater->add_control(
			'link',
			array(
				'label'       => esc_html__( 'URL', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::URL,
				'dynamic'     => array( 'active' => true ),
				'placeholder' => esc_html__( 'https://example.com/file.pdf', 'listemann-toolkit-addon' ),
				'condition'   => array_merge( $is_file, array( 'source' => 'url' ) ),
			)
		);

		$repeater->add_control(
			'label',
			array(
				'label'       => esc_html__( 'Label', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array( 'active' => true ),
				'label_block' => true,
				'placeholder' => esc_html__( 'Automatic, e.g. PDF Download', 'listemann-toolkit-addon' ),
				'description' => esc_html__( 'Replaces the label template of the Options section for this download. {type} is the file type.', 'listemann-toolkit-addon' ),
				'condition'   => $is_file,
			)
		);

		$repeater->add_control(
			'icon',
			array(
				'label'       => esc_html__( 'Custom Icon', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::ICONS,
				'skin'        => 'inline',
				'label_block' => false,
				'description' => esc_html__( 'Empty: a document icon chosen by the file type.', 'listemann-toolkit-addon' ),
				'condition'   => $is_file,
			)
		);

		$this->add_control(
			'items',
			array(
				'label'       => esc_html__( 'Items', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => $this->get_default_items(),
				'title_field' => '<# var lstIsGroup = "undefined" !== typeof item_type && "group" === item_type; #>'
					. '<# if ( lstIsGroup ) { #>&#9656; {{ "undefined" !== typeof group_title ? group_title : "" }}'
					. '<# } else { #><span style="display:inline-block;width:1.25em"></span>{{ "undefined" !== typeof title && title ? title : ( "undefined" !== typeof link && link && link.url ? link.url.split( "/" ).pop() : "" ) }}<# } #>',
			)
		);

		$this->add_control(
			'items_help',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Add a "Group title" item to start a group: the downloads after it, up to the next group title, belong to it. Downloads above the first group title form a group without a title. On the live page, groups without downloads are left out.', 'listemann-toolkit-addon' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content tab: label, link behaviour and group title tag.
	 *
	 * @return void
	 */
	private function register_options_controls() {
		$this->start_controls_section(
			'section_options',
			array(
				'label' => esc_html__( 'Options', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'label_template',
			array(
				'label'       => esc_html__( 'Label Template', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::TEXT,
				'dynamic'     => array( 'active' => true ),
				'default'     => '{type} Download',
				'label_block' => true,
				'description' => esc_html__( 'Small text above each title. {type} becomes the file type in capitals (PDF, DOCX, ZIP …), or "File" when the address has no file extension. Shown in capitals by the label typography.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'open_new_tab',
			array(
				'label'       => esc_html__( 'Open in New Tab', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => esc_html__( 'Applies to all downloads. Screen readers announce it. Off: only downloads whose URL has "Open in new window" ticked open in a new tab.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'download_attribute',
			array(
				'label'       => esc_html__( 'Save Instead of Opening', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => 'yes',
				'description' => esc_html__( 'Adds the download attribute, which asks the browser to save the file. Browsers ignore it for files on other domains and open those instead.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'show_file_size',
			array(
				'label'       => esc_html__( 'Show File Size', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => '',
				'description' => esc_html__( 'Shown after the label, for files from the Media Library only.', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'group_title_tag',
			array(
				'label'       => esc_html__( 'Group Title HTML Tag', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $this->get_tag_options( self::GROUP_TITLE_TAGS ),
				'default'     => 'h3',
				'separator'   => 'before',
				'description' => esc_html__( 'Use the level below the heading, without skipping one (the original page used H4 below its H2).', 'listemann-toolkit-addon' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: grid and spacing.
	 *
	 * @return void
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
			'columns',
			array(
				'label'       => esc_html__( 'Columns', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 6,
				'step'        => 1,
				'placeholder' => 3,
				'description' => esc_html__( 'Left empty: 3 columns, 2 below 1280 px and 1 on mobile, like the original. A value also applies to smaller screens that have no value of their own.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-dl-columns: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'column_gap',
			array(
				'label'      => esc_html__( 'Column Gap', 'listemann-toolkit-addon' ),
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
					'size' => 36,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-dl-column-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'row_gap',
			array(
				'label'       => esc_html__( 'Row Gap', 'listemann-toolkit-addon' ),
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
					'size' => 36,
				),
				'description' => esc_html__( 'Half of it is also kept above and below the cards of each group, as room for the shadow and the hover zoom.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-dl-row-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'group_spacing',
			array(
				'label'       => esc_html__( 'Space Between Groups', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 200,
					),
				),
				'description' => esc_html__( 'Also the space below the heading. Left empty: 100 px, 50 px below 1280 px, like the original.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-dl-group-spacing: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'side_spacing',
			array(
				'label'       => esc_html__( 'Side Spacing', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'description' => esc_html__( 'Space left and right of the headings and cards. Left empty: 18 px (half the column gap, as on the original), none on mobile.', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-dl-side-spacing: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: heading.
	 *
	 * @return void
	 */
	private function register_heading_style_controls() {
		$this->start_controls_section(
			'section_style_heading',
			array(
				'label'     => esc_html__( 'Heading', 'listemann-toolkit-addon' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);

		$this->add_control(
			'heading_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#dd191a',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-heading-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'heading_typography',
				'selector'       => '{{WRAPPER}} .lst-dl__heading',
				'fields_options' => $this->get_typography_defaults( 42, '300', 62 ),
			)
		);

		$this->add_responsive_control(
			'heading_margin',
			array(
				'label'              => esc_html__( 'Margin', 'listemann-toolkit-addon' ),
				'type'               => Controls_Manager::DIMENSIONS,
				'size_units'         => array( 'px', 'em', 'rem' ),
				'allowed_dimensions' => 'vertical',
				'placeholder'        => array(
					'top'    => '0',
					'right'  => '',
					'bottom' => '0',
					'left'   => '',
				),
				'description'        => esc_html__( 'Added to the space between groups below the heading.', 'listemann-toolkit-addon' ),
				'selectors'          => array(
					'{{WRAPPER}} .lst-dl__heading' => 'margin-top: {{TOP}}{{UNIT}}; margin-bottom: {{BOTTOM}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: group titles.
	 *
	 * @return void
	 */
	private function register_group_title_style_controls() {
		$this->start_controls_section(
			'section_style_group_title',
			array(
				'label' => esc_html__( 'Group Titles', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'group_title_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#626262',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-group-title-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'group_title_typography',
				'selector'       => '{{WRAPPER}} .lst-dl__group-title',
				'fields_options' => $this->get_typography_defaults( 30, '300', 44 ),
			)
		);

		$this->add_responsive_control(
			'group_title_spacing',
			array(
				'label'      => esc_html__( 'Spacing Below', 'listemann-toolkit-addon' ),
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
					'size' => 16,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-dl-group-title-spacing: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: cards, accent bar and hover effect.
	 *
	 * @return void
	 */
	private function register_card_style_controls() {
		$this->start_controls_section(
			'section_style_card',
			array(
				'label' => esc_html__( 'Cards', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'card_min_height',
			array(
				'label'      => esc_html__( 'Min Height', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 400,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 110,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-dl-card-min-height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'card_padding',
			array(
				'label'       => esc_html__( 'Padding', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::DIMENSIONS,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'default'     => array(
					'top'      => '10',
					'right'    => '25',
					'bottom'   => '10',
					'left'     => '25',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'description' => esc_html__( 'The icon sits in the top left corner of the padding (25 px from the edge and 10 px from the top by default; the accent bar covers the first 10 px).', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}} .lst-dl__card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'card_border_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .lst-dl__card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'card_tabs' );

		$this->start_controls_tab(
			'card_normal',
			array(
				'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'card_background',
			array(
				'label'     => esc_html__( 'Background Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-card-bg: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'           => 'card_shadow',
				'selector'       => '{{WRAPPER}}',
				'fields_options' => array(
					'box_shadow_type' => array( 'default' => 'yes' ),
					'box_shadow'      => array(
						'default'   => array(
							'horizontal' => 20,
							'vertical'   => 20,
							'blur'       => 46,
							'spread'     => 0,
							'color'      => 'rgba(0, 0, 0, 0.15)',
						),
						'selectors' => array(
							'{{SELECTOR}}' => '--lst-dl-shadow: {{HORIZONTAL}}px {{VERTICAL}}px {{BLUR}}px {{SPREAD}}px {{COLOR}} {{box_shadow_position.VALUE}};',
						),
					),
				),
			)
		);

		$this->add_control(
			'accent_color',
			array(
				'label'     => esc_html__( 'Accent Bar Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#dd191a',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-accent-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'card_hover',
			array(
				'label' => esc_html__( 'Hover', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'card_hover_background',
			array(
				'label'     => esc_html__( 'Background Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-card-hover-bg: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'           => 'card_hover_shadow',
				'selector'       => '{{WRAPPER}}',
				'fields_options' => array(
					'box_shadow' => array(
						'selectors' => array(
							'{{SELECTOR}}' => '--lst-dl-hover-shadow: {{HORIZONTAL}}px {{VERTICAL}}px {{BLUR}}px {{SPREAD}}px {{COLOR}} {{box_shadow_position.VALUE}};',
						),
					),
				),
			)
		);

		$this->add_control(
			'accent_hover_color',
			array(
				'label'     => esc_html__( 'Accent Bar Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-accent-hover-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_control(
			'accent_position',
			array(
				'label'                => esc_html__( 'Accent Bar', 'listemann-toolkit-addon' ),
				'type'                 => Controls_Manager::SELECT,
				'options'              => array(
					'start' => esc_html__( 'Left', 'listemann-toolkit-addon' ),
					'top'   => esc_html__( 'Top', 'listemann-toolkit-addon' ),
					'none'  => esc_html__( 'None', 'listemann-toolkit-addon' ),
				),
				'default'              => 'start',
				'separator'            => 'before',
				'selectors_dictionary' => array(
					'start' => 'display: block; inset-block: 0; inset-inline: 0 auto; inline-size: var(--lst-dl-accent-size, 10px); block-size: auto;',
					'top'   => 'display: block; inset-block: 0 auto; inset-inline: 0; inline-size: auto; block-size: var(--lst-dl-accent-size, 10px);',
					'none'  => 'display: none;',
				),
				'selectors'            => array(
					'{{WRAPPER}} .lst-dl__card::after' => '{{VALUE}}',
				),
			)
		);

		$this->add_control(
			'accent_size',
			array(
				'label'      => esc_html__( 'Accent Bar Width', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 1,
						'max' => 40,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 10,
				),
				'condition'  => array( 'accent_position!' => 'none' ),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-dl-accent-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'hover_effect',
			array(
				'label'                => esc_html__( 'Hover Effect', 'listemann-toolkit-addon' ),
				'type'                 => Controls_Manager::SELECT,
				'options'              => array(
					'scale'  => esc_html__( 'Zoom', 'listemann-toolkit-addon' ),
					'lift'   => esc_html__( 'Lift', 'listemann-toolkit-addon' ),
					'shadow' => esc_html__( 'Stronger shadow', 'listemann-toolkit-addon' ),
					'none'   => esc_html__( 'None', 'listemann-toolkit-addon' ),
				),
				'default'              => 'scale',
				'separator'            => 'before',
				'description'          => esc_html__( 'Also shown on keyboard focus. Touch screens skip the movement.', 'listemann-toolkit-addon' ),
				'selectors_dictionary' => array(
					'scale'  => '--lst-dl-hover-transform: scale(var(--lst-dl-hover-scale, 1.11)); --lst-dl-hover-shadow-effect: initial;',
					'lift'   => '--lst-dl-hover-transform: translateY(-6px); --lst-dl-hover-shadow-effect: initial;',
					'shadow' => '--lst-dl-hover-transform: none; --lst-dl-hover-shadow-effect: 20px 30px 60px 0 rgba(0, 0, 0, 0.25);',
					'none'   => '--lst-dl-hover-transform: none; --lst-dl-hover-shadow-effect: initial;',
				),
				'selectors'            => array(
					'{{WRAPPER}}' => '{{VALUE}}',
				),
			)
		);

		$this->add_control(
			'hover_scale',
			array(
				'label'     => esc_html__( 'Zoom Factor', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min'  => 1,
						'max'  => 1.5,
						'step' => 0.01,
					),
				),
				'default'   => array( 'size' => 1.11 ),
				'condition' => array( 'hover_effect' => 'scale' ),
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-hover-scale: {{SIZE}};',
				),
			)
		);

		$this->add_control(
			'hover_duration',
			array(
				'label'      => esc_html__( 'Transition Duration', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'ms' ),
				'range'      => array(
					'ms' => array(
						'min'  => 0,
						'max'  => 2000,
						'step' => 50,
					),
				),
				'default'    => array(
					'unit' => 'ms',
					'size' => 300,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-dl-duration: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'focus_color',
			array(
				'label'     => esc_html__( 'Focus Outline Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#dd191a',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-focus-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: file icon.
	 *
	 * @return void
	 */
	private function register_icon_style_controls() {
		$this->start_controls_section(
			'section_style_icon',
			array(
				'label' => esc_html__( 'Icon', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'icon_size',
			array(
				'label'       => esc_html__( 'Width', 'listemann-toolkit-addon' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'range'       => array(
					'px' => array(
						'min' => 8,
						'max' => 80,
					),
				),
				'default'     => array(
					'unit' => 'px',
					'size' => 20,
				),
				'description' => esc_html__( 'The bundled icons are 20 × 27 px. Their position comes from the card padding (Cards › Padding).', 'listemann-toolkit-addon' ),
				'selectors'   => array(
					'{{WRAPPER}}' => '--lst-dl-icon-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'icon_spacing',
			array(
				'label'      => esc_html__( 'Spacing to Text', 'listemann-toolkit-addon' ),
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
					'size' => 15,
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-dl-icon-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'icon_offset',
			array(
				'label'      => esc_html__( 'Vertical Offset', 'listemann-toolkit-addon' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => -30,
						'max' => 60,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}}' => '--lst-dl-icon-offset: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'icon_tabs' );

		$this->start_controls_tab(
			'icon_normal',
			array(
				'label' => esc_html__( 'Normal', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'icon_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#d9d9d9',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-icon-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'icon_hover',
			array(
				'label' => esc_html__( 'Hover', 'listemann-toolkit-addon' ),
			)
		);

		$this->add_control(
			'icon_hover_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-icon-hover-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Style tab: label ("PDF DOWNLOAD").
	 *
	 * @return void
	 */
	private function register_label_style_controls() {
		$this->start_controls_section(
			'section_style_label',
			array(
				'label' => esc_html__( 'Label', 'listemann-toolkit-addon' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'label_typography',
				'selector'       => '{{WRAPPER}} .lst-dl__label',
				'fields_options' => array_merge(
					$this->get_typography_defaults( 14, '400', 28 ),
					array( 'text_transform' => array( 'default' => 'uppercase' ) )
				),
			)
		);

		$this->add_control(
			'label_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#626262',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-label-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'label_hover_color',
			array(
				'label'     => esc_html__( 'Hover Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-label-hover-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style tab: download titles.
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

		$typography = $this->get_typography_defaults( 18, '300', 28 );
		// The original's title is 17 px on mobile.
		$typography['font_size']['mobile_default'] = array(
			'unit' => 'px',
			'size' => 17,
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'           => 'title_typography',
				'selector'       => '{{WRAPPER}} .lst-dl__title',
				'fields_options' => $typography,
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#dd191a',
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-title-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'title_hover_color',
			array(
				'label'     => esc_html__( 'Hover Color', 'listemann-toolkit-addon' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}}' => '--lst-dl-title-hover-color: {{VALUE}};',
				),
			)
		);

		// Next to the icon the title overlaps the label line by 1 px, under it 4 px clear the icon (as on the original).
		$beside = '"icon label" "icon title"';
		$under  = '"icon label" "title title"';

		$this->add_control(
			'title_layout',
			array(
				'label'                => esc_html__( 'Title Position', 'listemann-toolkit-addon' ),
				'type'                 => Controls_Manager::SELECT,
				'options'              => array(
					'auto'   => esc_html__( 'Under the icon below 1280 px (original)', 'listemann-toolkit-addon' ),
					'beside' => esc_html__( 'Always next to the icon', 'listemann-toolkit-addon' ),
					'under'  => esc_html__( 'Always under the icon', 'listemann-toolkit-addon' ),
				),
				'default'              => 'auto',
				'separator'            => 'before',
				'selectors_dictionary' => array(
					'auto'   => "--lst-dl-title-areas: {$beside}; --lst-dl-title-areas-narrow: {$under}; --lst-dl-title-shift: -1px; --lst-dl-title-shift-narrow: 4px;",
					'beside' => "--lst-dl-title-areas: {$beside}; --lst-dl-title-areas-narrow: {$beside}; --lst-dl-title-shift: -1px; --lst-dl-title-shift-narrow: -1px;",
					'under'  => "--lst-dl-title-areas: {$under}; --lst-dl-title-areas-narrow: {$under}; --lst-dl-title-shift: 4px; --lst-dl-title-shift-narrow: 4px;",
				),
				'selectors'            => array(
					'{{WRAPPER}}' => '{{VALUE}}',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Typography defaults (custom, px) of a text element.
	 *
	 * @param int|float $size        Font size in px.
	 * @param string    $weight      Font weight.
	 * @param int|float $line_height Line height in px.
	 * @return array fields_options.
	 */
	private function get_typography_defaults( $size, $weight, $line_height ) {
		return array(
			'typography'  => array( 'default' => 'custom' ),
			'font_size'   => array(
				'default' => array(
					'unit' => 'px',
					'size' => $size,
				),
			),
			'font_weight' => array( 'default' => $weight ),
			'line_height' => array(
				'default'        => array(
					'unit' => 'px',
					'size' => $line_height,
				),
				'tablet_default' => array( 'unit' => 'px' ),
				'mobile_default' => array( 'unit' => 'px' ),
			),
		);
	}

	/**
	 * Options of a tag select: tag => label.
	 *
	 * @param string[] $tags Tags.
	 * @return array
	 */
	private function get_tag_options( array $tags ) {
		$options = array();

		foreach ( $tags as $tag ) {
			$options[ $tag ] = 'h' === $tag[0] ? strtoupper( $tag ) : $tag;
		}

		return $options;
	}

	/**
	 * Default items: the groups and files of listemann.com/downloads/.
	 *
	 * @return array
	 */
	private function get_default_items() {
		$items = array();

		foreach ( self::DEFAULT_GROUPS as $group => $files ) {
			$items[] = array(
				'item_type'   => 'group',
				'group_title' => $group,
			);

			foreach ( $files as $title => $url ) {
				$items[] = array(
					'item_type' => 'file',
					'title'     => $title,
					'source'    => 'url',
					'link'      => array(
						'url'               => $url,
						'is_external'       => '',
						'nofollow'          => '',
						'custom_attributes' => '',
					),
				);
			}
		}

		return $items;
	}

	/**
	 * Splits the flat item list into groups.
	 *
	 * A "group" item starts a new group; files belong to the last group
	 * started before them. Files before the first group item form a group
	 * without a title. Keep in sync with content_template().
	 *
	 * @param array $items Repeater items.
	 * @return array[] Groups: [ 'title' => string, 'item' => array|null, 'files' => array[] ].
	 */
	public static function group_items( array $items ) {
		$groups  = array();
		$current = null;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( 'group' === ( $item['item_type'] ?? 'file' ) ) {
				$groups[] = array(
					'title' => trim( (string) ( $item['group_title'] ?? '' ) ),
					'item'  => $item,
					'files' => array(),
				);
				$current  = count( $groups ) - 1;
				continue;
			}

			if ( null === $current ) {
				$groups[] = array(
					'title' => '',
					'item'  => null,
					'files' => array(),
				);
				$current  = count( $groups ) - 1;
			}

			$groups[ $current ]['files'][] = $item;
		}

		return $groups;
	}

	/**
	 * File type of a URL: its extension in capitals ("PDF"), '' when the
	 * address has none or ends in a server script (.php …). Query strings
	 * and fragments are ignored. For a Media Library file without one, the
	 * attached file or the MIME type is used. Keep in sync with
	 * content_template().
	 *
	 * @param string $url           File URL.
	 * @param int    $attachment_id Attachment ID, 0 for none.
	 * @return string
	 */
	public static function get_file_type( $url, $attachment_id = 0 ) {
		$type = self::get_extension( (string) $url );

		if ( '' === $type && $attachment_id ) {
			$type = self::get_extension( (string) get_attached_file( $attachment_id ) );

			if ( '' === $type ) {
				$mime = (string) get_post_mime_type( $attachment_id );
				$type = function_exists( 'wp_get_default_extension_for_mime_type' ) ? (string) wp_get_default_extension_for_mime_type( $mime ) : '';
				$type = preg_match( '/^[a-z0-9]{1,8}$/i', $type ) ? strtoupper( $type ) : '';
			}
		}

		return $type;
	}

	/**
	 * Whether a URL points to this site (relative URLs included), where
	 * browsers honour the `download` attribute.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_same_site_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( null === $host || false === $host ) {
			return 0 !== strpos( $url, '//' ) && ! preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url );
		}

		return strtolower( $host ) === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/**
	 * Extension of the last path segment of a URL or file path, in capitals.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	private static function get_extension( $url ) {
		$path = preg_split( '/[?#]/', $url, 2 )[0];
		$base = substr( $path, (int) strrpos( '/' . $path, '/' ) );
		$dot  = strrpos( $base, '.' );
		$ext  = $dot ? substr( $base, $dot + 1 ) : '';

		if ( ! preg_match( '/^[a-z0-9]{1,8}$/i', $ext ) || in_array( strtolower( $ext ), self::SCRIPT_EXTENSIONS, true ) ) {
			return '';
		}

		return strtoupper( $ext );
	}

	/**
	 * Fills the label template: {type} becomes the file type or, when it is
	 * unknown, the fallback ("File"). Keep in sync with content_template().
	 *
	 * @param string $template Label template.
	 * @param string $type     File type ('' when unknown).
	 * @param string $fallback Text for an unknown type.
	 * @return string Plain text.
	 */
	public static function build_label( $template, $type, $fallback ) {
		$label = str_replace( self::TYPE_TOKEN, '' !== $type ? $type : $fallback, (string) $template );

		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $label ) ) );
	}

	/**
	 * Screen-reader text after the visible card text: the file type (unless
	 * the label already shows it) and the new-tab hint, e.g.
	 * "(PDF file, opens in a new tab)". Keep in sync with content_template().
	 *
	 * @param string $type    File type ('' when unknown).
	 * @param string $label   Visible label.
	 * @param bool   $new_tab Whether the link opens in a new tab.
	 * @return string Plain text, '' when there is nothing to add.
	 */
	public static function get_screen_reader_text( $type, $label, $new_tab ) {
		$show_type = '' !== $type && false === stripos( $label, $type );

		if ( $show_type && $new_tab ) {
			/* translators: %s: File type, e.g. PDF. */
			return sprintf( __( '(%s file, opens in a new tab)', 'listemann-toolkit-addon' ), $type );
		}

		if ( $show_type ) {
			/* translators: %s: File type, e.g. PDF. */
			return sprintf( __( '(%s file)', 'listemann-toolkit-addon' ), $type );
		}

		return $new_tab ? __( '(opens in a new tab)', 'listemann-toolkit-addon' ) : '';
	}

	/**
	 * Human-readable size of a Media Library file ("120 KB"), from the
	 * attachment metadata when it has it, otherwise from the file.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string '' when unknown.
	 */
	public static function get_file_size( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return '';
		}

		$meta  = wp_get_attachment_metadata( $attachment_id );
		$bytes = is_array( $meta ) && ! empty( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;

		if ( ! $bytes ) {
			$file  = get_attached_file( $attachment_id );
			$bytes = $file && is_readable( $file ) ? (int) filesize( $file ) : 0;
		}

		return $bytes ? (string) size_format( $bytes ) : '';
	}

	/**
	 * Whether the widget renders in the Elementor editor or its preview.
	 *
	 * @return bool
	 */
	private function is_editor() {
		$plugin = Elementor::$instance;

		return $plugin->editor->is_edit_mode() || $plugin->preview->is_preview_mode();
	}

	/**
	 * Renders the widget on the front end.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$items    = isset( $settings['items'] ) && is_array( $settings['items'] ) ? array_values( $settings['items'] ) : array();
		$groups   = self::group_items( $items );
		$editor   = $this->is_editor();

		if ( ! $editor ) {
			// Groups without downloads are left out on the live page (keys, and so the ids, stay as in the editor).
			$groups = array_filter(
				$groups,
				static function ( $group ) {
					return ! empty( $group['files'] );
				}
			);

			if ( ! $groups ) {
				return;
			}
		}

		$heading = 'yes' === ( $settings['show_heading'] ?? '' ) ? trim( (string) ( $settings['heading_text'] ?? '' ) ) : '';
		?>
		<div class="lst-dl">
			<?php
			if ( '' !== $heading ) {
				$tag = in_array( $settings['heading_tag'] ?? '', self::HEADING_TAGS, true ) ? $settings['heading_tag'] : 'h2';
				printf(
					'<%1$s class="lst-dl__heading">%2$s</%1$s>',
					Utils::validate_html_tag( $tag ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated tag name.
					esc_html( $heading )
				);
			}

			if ( ! $groups ) {
				$this->print_editor_hint( __( 'Add group titles and downloads in the Downloads section.', 'listemann-toolkit-addon' ), 'notice' );
			}

			foreach ( $groups as $index => $group ) {
				$this->render_group( $group, $index, $settings );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders one group: its title (if any) and its cards.
	 *
	 * @param array $group    Group from group_items().
	 * @param int   $index    Zero-based position.
	 * @param array $settings Widget settings.
	 * @return void
	 */
	private function render_group( array $group, $index, array $settings ) {
		$key = 'group-' . $index;
		$tag = in_array( $settings['group_title_tag'] ?? '', self::GROUP_TITLE_TAGS, true ) ? $settings['group_title_tag'] : 'h3';
		$id  = '';

		$this->add_render_attribute( $key, 'class', 'lst-dl__group' );

		if ( ! empty( $group['item']['_id'] ) ) {
			$this->add_render_attribute( $key, 'class', 'elementor-repeater-item-' . $group['item']['_id'] );
		}

		if ( '' !== $group['title'] ) {
			$id = $this->get_unique_id( 'lst-dl-' . $this->get_id() . '-g' . ( $index + 1 ) );
			$this->add_render_attribute( $key, 'aria-labelledby', $id );
		}
		?>
		<section <?php $this->print_render_attribute_string( $key ); ?>>
			<?php
			if ( '' !== $id ) {
				printf(
					'<%1$s class="lst-dl__group-title" id="%2$s">%3$s</%1$s>',
					Utils::validate_html_tag( $tag ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated tag name.
					esc_attr( $id ),
					esc_html( $group['title'] )
				);
			}

			if ( $group['files'] ) {
				echo '<ul class="lst-dl__grid" role="list">';

				foreach ( $group['files'] as $file_index => $item ) {
					$this->render_card( $item, $index . '-' . $file_index, $settings );
				}

				echo '</ul>';
			} else {
				$this->print_editor_hint( __( 'This group has no downloads yet: add "Download" items below its title. It is hidden on the live page until then.', 'listemann-toolkit-addon' ), 'notice' );
			}
			?>
		</section>
		<?php
	}

	/**
	 * Renders one download card.
	 *
	 * @param array  $item     Repeater item.
	 * @param string $suffix   Unique suffix of the render attribute keys.
	 * @param array  $settings Widget settings.
	 * @return void
	 */
	private function render_card( array $item, $suffix, array $settings ) {
		$key    = 'card-' . $suffix;
		$source = 'url' === ( $item['source'] ?? 'media' ) ? 'url' : 'media';
		$link   = isset( $item['link'] ) && is_array( $item['link'] ) ? $item['link'] : array();
		$file   = isset( $item['file'] ) && is_array( $item['file'] ) ? $item['file'] : array();
		$id     = 'media' === $source && ! empty( $file['id'] ) ? absint( $file['id'] ) : 0;
		$url    = 'url' === $source ? (string) ( $link['url'] ?? '' ) : (string) ( $file['url'] ?? '' );

		if ( $id ) {
			$attachment_url = wp_get_attachment_url( $id );
			$url            = $attachment_url ? $attachment_url : $url;
		}

		$href     = '' !== trim( $url ) ? esc_url( $url ) : '';
		$type     = self::get_file_type( $url, $id );
		$custom   = trim( (string) ( $item['label'] ?? '' ) );
		$label    = self::build_label( '' !== $custom ? $custom : ( $settings['label_template'] ?? '' ), $type, __( 'File', 'listemann-toolkit-addon' ) );
		$title    = trim( (string) ( $item['title'] ?? '' ) );
		$size     = $id && 'yes' === ( $settings['show_file_size'] ?? '' ) ? self::get_file_size( $id ) : '';
		$new_tab  = false;
		$card_tag = '' !== $href ? 'a' : 'div';
		// Browsers honour `download` only for files on this site; those are saved, never opened in a tab.
		$saves = 'a' === $card_tag && 'yes' === ( $settings['download_attribute'] ?? '' ) && ( 'media' === $source || self::is_same_site_url( $url ) );

		$this->add_render_attribute( $key, 'class', 'lst-dl__card' );

		if ( 'a' === $card_tag ) {
			if ( 'url' === $source ) {
				$this->add_link_attributes( $key, $link );

				if ( $saves ) {
					$this->remove_render_attribute( $key, 'target' );
				}
			} else {
				$this->add_render_attribute( $key, 'href', $href );
			}

			if ( 'yes' === ( $settings['open_new_tab'] ?? '' ) && ! $saves ) {
				$this->add_render_attribute( $key, 'target', '_blank', true );
			}

			$target  = $this->get_render_attributes( $key, 'target' );
			$new_tab = ! $saves && is_array( $target ) && in_array( '_blank', $target, true );

			if ( $new_tab ) {
				$this->add_render_attribute( $key, 'rel', 'noopener' );
			}

			if ( 'yes' === ( $settings['download_attribute'] ?? '' ) ) {
				$this->add_render_attribute( $key, 'download', '' );
			}
		} else {
			$this->add_render_attribute( $key, 'class', 'is-disabled' );
		}

		$sr_text = 'a' === $card_tag ? self::get_screen_reader_text( $type, $label, $new_tab ) : '';
		?>
		<li class="<?php echo esc_attr( 'lst-dl__item' . ( empty( $item['_id'] ) ? '' : ' elementor-repeater-item-' . $item['_id'] ) ); ?>">
			<<?php Utils::print_validated_html_tag( $card_tag ); ?> <?php $this->print_render_attribute_string( $key ); ?>>
				<span class="lst-dl__icon" aria-hidden="true"><?php echo $this->get_icon_html( $item, $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized in get_icon_html(). ?></span>
				<?php if ( '' !== $label || '' !== $size ) : ?>
					<span class="lst-dl__label"><?php echo esc_html( $label ) . ( '' !== $size ? '<span class="lst-dl__size">' . esc_html( $size ) . '</span>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both parts escaped. ?></span>
				<?php endif; ?>
				<?php if ( '' !== $title ) : ?>
					<span class="lst-dl__title"><?php echo esc_html( $title ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $sr_text ) : ?>
					<span class="lst-dl__sr"><?php echo esc_html( $sr_text ); ?></span>
				<?php endif; ?>
				<?php
				if ( 'div' === $card_tag ) {
					$this->print_editor_hint( __( 'No file or URL yet: this card is not a link.', 'listemann-toolkit-addon' ) );
				}
				?>
			</<?php Utils::print_validated_html_tag( $card_tag ); ?>>
		</li>
		<?php
	}

	/**
	 * Icon markup of a card: the custom icon, otherwise the bundled PDF or
	 * generic document icon.
	 *
	 * @param array  $item Repeater item.
	 * @param string $type File type.
	 * @return string Sanitized HTML.
	 */
	private function get_icon_html( array $item, $type ) {
		if ( ! empty( $item['icon']['value'] ) ) {
			ob_start();
			Icons_Manager::render_icon( $item['icon'], array( 'aria-hidden' => 'true' ) );
			$html = (string) ob_get_clean();

			if ( '' !== trim( $html ) ) {
				return $html;
			}
		}

		return self::get_bundled_icon( 'PDF' === $type ? 'file-pdf' : 'file-generic' );
	}

	/**
	 * Bundled icon from assets/img as inline SVG (currentColor).
	 *
	 * @param string $name file-pdf or file-generic.
	 * @return string Sanitized SVG ('' when missing).
	 */
	private static function get_bundled_icon( $name ) {
		if ( ! in_array( $name, array( 'file-pdf', 'file-generic' ), true ) ) {
			return '';
		}

		if ( ! isset( self::$icon_cache[ $name ] ) ) {
			$file = LISTEMANN_TOOLKIT_PATH . 'assets/img/' . $name . '.svg';
			$svg  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.

			self::$icon_cache[ $name ] = trim( wp_kses( $svg, self::get_svg_kses() ) );
		}

		return self::$icon_cache[ $name ];
	}

	/**
	 * Allowed SVG markup for the bundled icons (the allow-list of the header
	 * widget's bundled icons).
	 *
	 * @return array
	 */
	private static function get_svg_kses() {
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
	 * Returns an id that is unique within the request.
	 *
	 * @param string $base Preferred id.
	 * @return string
	 */
	private function get_unique_id( $base ) {
		$id = $base;
		$i  = 2;

		while ( in_array( $id, self::$used_ids, true ) ) {
			$id = $base . '-' . $i;
			++$i;
		}

		self::$used_ids[] = $id;

		return $id;
	}

	/**
	 * Prints a hint for editors (Elementor editor / preview only).
	 *
	 * @param string $message Plain-text message (escaped here).
	 * @param string $kind    hint (inside a card) or notice (a block).
	 * @return void
	 */
	private function print_editor_hint( $message, $kind = 'hint' ) {
		if ( ! $this->is_editor() ) {
			return;
		}

		printf(
			'<span class="lst-dl__%1$s" role="note">%2$s</span>',
			'notice' === $kind ? 'notice' : 'hint',
			esc_html( $message )
		);
	}

	/**
	 * Live preview template for the editor. Mirrors render(), including the
	 * grouping, the label template and the file type detection.
	 *
	 * @return void
	 */
	protected function content_template() {
		$i18n = array(
			'file'       => __( 'File', 'listemann-toolkit-addon' ),
			/* translators: %s: File type, e.g. PDF. */
			'typeNewTab' => __( '(%s file, opens in a new tab)', 'listemann-toolkit-addon' ),
			/* translators: %s: File type, e.g. PDF. */
			'type'       => __( '(%s file)', 'listemann-toolkit-addon' ),
			'newTab'     => __( '(opens in a new tab)', 'listemann-toolkit-addon' ),
			'noItems'    => __( 'Add group titles and downloads in the Downloads section.', 'listemann-toolkit-addon' ),
			'emptyGroup' => __( 'This group has no downloads yet: add "Download" items below its title. It is hidden on the live page until then.', 'listemann-toolkit-addon' ),
			'noFile'     => __( 'No file or URL yet: this card is not a link.', 'listemann-toolkit-addon' ),
		);
		?>
		<#
		var lstDl = {
			items: Array.isArray( settings.items ) ? settings.items : [],
			headingTags: <?php echo wp_json_encode( self::HEADING_TAGS ); ?>,
			groupTags: <?php echo wp_json_encode( self::GROUP_TITLE_TAGS ); ?>,
			scripts: <?php echo wp_json_encode( self::SCRIPT_EXTENSIONS ); ?>,
			i18n: <?php echo wp_json_encode( $i18n ); ?>,
			groups: [],
			current: -1
		};

		lstDl.extension = function( url ) {
			var path = String( url || '' ).split( /[?#]/ )[ 0 ],
				base = path.substring( path.lastIndexOf( '/' ) + 1 ),
				dot = base.lastIndexOf( '.' ),
				ext = dot > 0 ? base.substring( dot + 1 ) : '';

			return /^[a-z0-9]{1,8}$/i.test( ext ) && -1 === lstDl.scripts.indexOf( ext.toLowerCase() ) ? ext.toUpperCase() : '';
		};

		lstDl.sameSite = function( url ) {
			try {
				return new URL( url, window.location.href ).host === window.location.host;
			} catch ( e ) {
				return false;
			}
		};

		lstDl.label = function( template, type ) {
			var text = String( template || '' ).split( '<?php echo esc_js( self::TYPE_TOKEN ); ?>' ).join( type || lstDl.i18n.file );

			return text.replace( /<[^>]*>/g, '' ).replace( /\s+/g, ' ' ).trim();
		};

		lstDl.srText = function( type, label, newTab ) {
			var showType = '' !== type && -1 === label.toLowerCase().indexOf( type.toLowerCase() );

			if ( showType && newTab ) {
				return lstDl.i18n.typeNewTab.replace( '%s', type );
			}

			if ( showType ) {
				return lstDl.i18n.type.replace( '%s', type );
			}

			return newTab ? lstDl.i18n.newTab : '';
		};

		lstDl.size = function( id ) {
			var attachment;

			if ( ! id || 'yes' !== settings.show_file_size || ! window.wp || ! wp.media || ! wp.media.attachment ) {
				return '';
			}

			attachment = wp.media.attachment( id );

			if ( ! attachment.get( 'filesizeHumanReadable' ) && ! attachment.lstDlFetching ) {
				attachment.lstDlFetching = true;
				attachment.fetch().then( function() {
					view.render();
				} );
			}

			return attachment.get( 'filesizeHumanReadable' ) || '';
		};

		_.each( lstDl.items, function( item ) {
			if ( 'group' === item.item_type ) {
				lstDl.groups.push( { title: String( item.group_title || '' ).trim(), item: item, files: [] } );
				lstDl.current = lstDl.groups.length - 1;
				return;
			}

			if ( lstDl.current < 0 ) {
				lstDl.groups.push( { title: '', item: null, files: [] } );
				lstDl.current = lstDl.groups.length - 1;
			}

			lstDl.groups[ lstDl.current ].files.push( item );
		} );

		lstDl.heading = 'yes' === settings.show_heading ? String( settings.heading_text || '' ).trim() : '';
		lstDl.headingTag = lstDl.headingTags.indexOf( settings.heading_tag ) > -1 ? settings.heading_tag : 'h2';
		lstDl.groupTag = lstDl.groupTags.indexOf( settings.group_title_tag ) > -1 ? settings.group_title_tag : 'h3';
		#>
		<div class="lst-dl">
			<# if ( lstDl.heading ) { #>
				<{{ lstDl.headingTag }} class="lst-dl__heading">{{ lstDl.heading }}</{{ lstDl.headingTag }}>
			<# } #>
			<# if ( ! lstDl.groups.length ) { #>
				<span class="lst-dl__notice" role="note">{{ lstDl.i18n.noItems }}</span>
			<# } #>
			<# _.each( lstDl.groups, function( group, index ) {
				var groupKey = 'group-' + index,
					groupId = group.title ? 'lst-dl-' + view.getID() + '-g' + ( index + 1 ) : '';

				view.addRenderAttribute( groupKey, 'class', 'lst-dl__group' );

				if ( group.item && group.item._id ) {
					view.addRenderAttribute( groupKey, 'class', 'elementor-repeater-item-' + group.item._id );
				}

				if ( groupId ) {
					view.addRenderAttribute( groupKey, 'aria-labelledby', groupId );
				}
				#>
				<section {{{ view.getRenderAttributeString( groupKey ) }}}>
					<# if ( groupId ) { #>
						<{{ lstDl.groupTag }} class="lst-dl__group-title" id="{{ groupId }}">{{ group.title }}</{{ lstDl.groupTag }}>
					<# } #>
					<# if ( group.files.length ) { #>
						<ul class="lst-dl__grid" role="list">
							<# _.each( group.files, function( item, fileIndex ) {
								var cardKey = 'card-' + index + '-' + fileIndex,
									source = 'url' === item.source ? 'url' : 'media',
									link = item.link || {},
									file = item.file || {},
									attachmentId = 'media' === source && file.id ? file.id : 0,
									url = String( ( 'url' === source ? link.url : file.url ) || '' ),
									href = url.trim() && elementor.helpers.sanitizeUrl( url ) ? url : '',
									type = lstDl.extension( url ),
									custom = String( item.label || '' ).trim(),
									label = lstDl.label( custom || settings.label_template, type ),
									title = String( item.title || '' ).trim(),
									size = lstDl.size( attachmentId ),
									newTab = false,
									saves = false,
									cardTag = href ? 'a' : 'div',
									icon = item.icon && item.icon.value ? elementor.helpers.renderIcon( view, item.icon, { 'aria-hidden': 'true' }, 'i', 'object' ) : null,
									srText;

								if ( ! type && attachmentId && window.wp && wp.media && wp.media.attachment ) {
									type = lstDl.extension( wp.media.attachment( attachmentId ).get( 'filename' ) ) || String( wp.media.attachment( attachmentId ).get( 'subtype' ) || '' ).replace( /[^a-z0-9]/gi, '' ).substring( 0, 8 ).toUpperCase();
									label = lstDl.label( custom || settings.label_template, type );
								}

								view.addRenderAttribute( cardKey, 'class', 'lst-dl__card' );

								if ( href ) {
									view.addRenderAttribute( cardKey, 'href', href );
									saves = 'yes' === settings.download_attribute && ( 'media' === source || lstDl.sameSite( href ) );
									newTab = ! saves && ( 'yes' === settings.open_new_tab || ( 'url' === source && !! link.is_external ) );

									if ( newTab ) {
										view.addRenderAttribute( cardKey, { target: '_blank', rel: ( 'url' === source && link.nofollow ? 'nofollow ' : '' ) + 'noopener' } );
									} else if ( 'url' === source && link.nofollow ) {
										view.addRenderAttribute( cardKey, 'rel', 'nofollow' );
									}

									if ( 'yes' === settings.download_attribute ) {
										view.addRenderAttribute( cardKey, 'download', '' );
									}
								} else {
									view.addRenderAttribute( cardKey, 'class', 'is-disabled' );
								}

								srText = href ? lstDl.srText( type, label, newTab ) : '';
								#>
								<li class="lst-dl__item<# if ( item._id ) { #> elementor-repeater-item-{{ item._id }}<# } #>">
									<{{ cardTag }} {{{ view.getRenderAttributeString( cardKey ) }}}>
										<span class="lst-dl__icon" aria-hidden="true"><#
											if ( icon && icon.rendered ) {
												#>{{{ icon.value }}}<#
											} else if ( 'PDF' === type ) {
												#><?php echo self::get_bundled_icon( 'file-pdf' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized with wp_kses(). ?><#
											} else {
												#><?php echo self::get_bundled_icon( 'file-generic' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized with wp_kses(). ?><#
											}
										#></span>
										<# if ( label || size ) { #>
											<span class="lst-dl__label">{{ label }}<# if ( size ) { #><span class="lst-dl__size">{{ size }}</span><# } #></span>
										<# } #>
										<# if ( title ) { #>
											<span class="lst-dl__title">{{ title }}</span>
										<# } #>
										<# if ( srText ) { #>
											<span class="lst-dl__sr">{{ srText }}</span>
										<# } #>
										<# if ( ! href ) { #>
											<span class="lst-dl__hint" role="note">{{ lstDl.i18n.noFile }}</span>
										<# } #>
									</{{ cardTag }}>
								</li>
							<# } ); #>
						</ul>
					<# } else { #>
						<span class="lst-dl__notice" role="note">{{ lstDl.i18n.emptyGroup }}</span>
					<# } #>
				</section>
			<# } ); #>
		</div>
		<?php
	}
}
