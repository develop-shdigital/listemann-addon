<?php
/**
 * Admin settings page "Listemann Toolkit" (Settings API).
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit\Admin;

use Listemann_Toolkit\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level admin menu page for the global features: contact box sections,
 * back-to-top button, colors and branded scrollbar.
 */
final class Settings_Page {

	/**
	 * Menu/page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'listemann-toolkit';

	/**
	 * Settings API option group.
	 *
	 * @var string
	 */
	const GROUP = 'listemann_toolkit';

	/**
	 * Capability required to manage the settings.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Action (admin-post.php and nonce) of the "Reset to defaults" form.
	 *
	 * @var string
	 */
	const RESET_ACTION = 'listemann_toolkit_reset';

	/**
	 * Admin style and script handle.
	 *
	 * @var string
	 */
	const HANDLE = 'listemann-toolkit-admin-settings';

	/**
	 * Hook suffix returned by add_menu_page().
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Whether the hooks have been added.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Registers the admin hooks. Safe to call more than once.
	 *
	 * @param string $plugin_basename Optional plugin basename (e.g. plugin_basename( LISTEMANN_TOOLKIT_FILE ))
	 *                                to add a "Settings" link to the Plugins screen row.
	 * @return void
	 */
	public static function init( $plugin_basename = '' ) {
		if ( ! self::$initialized ) {
			self::$initialized = true;

			add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_action( 'admin_post_' . self::RESET_ACTION, array( __CLASS__, 'handle_reset' ) );
		}

		if ( is_string( $plugin_basename ) && '' !== $plugin_basename ) {
			self::register_action_link( $plugin_basename );
		}
	}

	/**
	 * Adds the "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param string $plugin_basename Plugin basename, e.g. "listemann-toolkit-addon/listemann-toolkit-addon.php".
	 * @return void
	 */
	public static function register_action_link( $plugin_basename ) {
		$hook = 'plugin_action_links_' . $plugin_basename;

		if ( false === has_filter( $hook, array( __CLASS__, 'add_action_link' ) ) ) {
			add_filter( $hook, array( __CLASS__, 'add_action_link' ) );
		}
	}

	/**
	 * Filter callback: prepends the "Settings" link.
	 *
	 * @param string[] $links Plugin action links.
	 * @return string[]
	 */
	public static function add_action_link( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}

		if ( current_user_can( self::CAPABILITY ) ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( self::page_url() ),
					esc_html__( 'Settings', 'listemann-toolkit-addon' )
				)
			);
		}

		return $links;
	}

	/**
	 * URL of the settings page.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Adds the top-level menu page.
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		$hook_suffix = add_menu_page(
			__( 'Listemann Toolkit', 'listemann-toolkit-addon' ),
			__( 'Listemann Toolkit', 'listemann-toolkit-addon' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-admin-tools',
			81
		);

		self::$hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
	}

	/**
	 * Registers the setting, sections and fields.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'description'       => __( 'Listemann Toolkit global features (contact box, back to top, scrollbar).', 'listemann-toolkit-addon' ),
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);

		// Contact box.
		add_settings_section(
			'lst_contact',
			__( 'Contact box', 'listemann-toolkit-addon' ),
			array( __CLASS__, 'render_contact_intro' ),
			self::PAGE_SLUG
		);
		self::add_field( 'contact', 'enabled', __( 'Enable', 'listemann-toolkit-addon' ), 'checkbox', array( 'text' => __( 'Show the contact button and box on every page', 'listemann-toolkit-addon' ) ) );
		self::add_field( 'contact', 'label', __( 'Button label', 'listemann-toolkit-addon' ), 'text', array( 'description' => __( 'Read by screen readers for the contact button (the button itself only shows an icon).', 'listemann-toolkit-addon' ) ) );
		add_settings_field(
			'lst-contact-sections',
			__( 'Sections', 'listemann-toolkit-addon' ),
			array( __CLASS__, 'render_sections_field' ),
			self::PAGE_SLUG,
			'lst_contact',
			array( 'class' => 'lst-row-sections' )
		);

		// Back to top.
		add_settings_section( 'lst_to_top', __( 'Back to top', 'listemann-toolkit-addon' ), '__return_false', self::PAGE_SLUG );
		self::add_field( 'to_top', 'enabled', __( 'Enable', 'listemann-toolkit-addon' ), 'checkbox', array( 'text' => __( 'Show the back-to-top button on every page', 'listemann-toolkit-addon' ) ) );
		self::add_field( 'to_top', 'label', __( 'Label', 'listemann-toolkit-addon' ), 'textarea', array( 'description' => __( 'Shown next to the arrow on hover. Each line of text becomes a line in the label (three short lines fit best).', 'listemann-toolkit-addon' ) ) );
		self::add_field(
			'to_top',
			'offset',
			__( 'Show after scrolling', 'listemann-toolkit-addon' ),
			'number',
			array(
				'min'         => 0,
				'max'         => 100000,
				'unit'        => 'px',
				'description' => __( '0 = always visible.', 'listemann-toolkit-addon' ),
			)
		);

		// Appearance.
		add_settings_section( 'lst_appearance', __( 'Appearance', 'listemann-toolkit-addon' ), '__return_false', self::PAGE_SLUG );
		self::add_field( 'appearance', 'brand_color', __( 'Brand color', 'listemann-toolkit-addon' ), 'color', array( 'description' => __( 'Contact box, contact button and scrollbar.', 'listemann-toolkit-addon' ) ) );
		self::add_field( 'appearance', 'text_color', __( 'Text color on brand color', 'listemann-toolkit-addon' ), 'color', array( 'description' => __( 'Text and icon color in the contact box and button.', 'listemann-toolkit-addon' ) ) );
		self::add_field( 'appearance', 'to_top_color', __( 'Back-to-top button color', 'listemann-toolkit-addon' ), 'color', array( 'description' => __( 'For accessibility (WCAG 1.4.11) the button needs a contrast of at least 3:1 to the arrow color and to the page background, e.g. #8c8c8c or darker with the default arrow color. The default grey matches listemann.com but is lighter.', 'listemann-toolkit-addon' ) ) );
		self::add_field( 'appearance', 'arrow_color', __( 'Back-to-top arrow color', 'listemann-toolkit-addon' ), 'color' );

		// Scrollbar.
		add_settings_section( 'lst_scrollbar', __( 'Scrollbar', 'listemann-toolkit-addon' ), '__return_false', self::PAGE_SLUG );
		self::add_field( 'scrollbar', 'enabled', __( 'Enable', 'listemann-toolkit-addon' ), 'checkbox', array( 'text' => __( 'Color the page scrollbar', 'listemann-toolkit-addon' ) ) );
		self::add_field(
			'scrollbar',
			'color',
			__( 'Color', 'listemann-toolkit-addon' ),
			'color',
			array(
				'allow_empty' => true,
				'description' => __( 'Leave empty to use the brand color.', 'listemann-toolkit-addon' ),
			)
		);
		self::add_field(
			'scrollbar',
			'width',
			__( 'Width', 'listemann-toolkit-addon' ),
			'number',
			array(
				'min'         => 2,
				'max'         => 24,
				'unit'        => 'px',
				'description' => __( 'Chrome, Edge and Safari use the exact width. Firefox only supports "thin" (used up to 8px) and "auto" (wider values).', 'listemann-toolkit-addon' ),
			)
		);
	}

	/**
	 * Enqueues the color picker and the page script/style on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( '' === self::$hook_suffix || $hook_suffix !== self::$hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			LISTEMANN_TOOLKIT_URL . 'assets/css/admin-settings.css',
			array( 'wp-color-picker' ),
			LISTEMANN_TOOLKIT_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			LISTEMANN_TOOLKIT_URL . 'assets/js/admin-settings.js',
			array( 'jquery', 'wp-color-picker', 'wp-a11y' ),
			LISTEMANN_TOOLKIT_VERSION,
			true
		);

		$l10n = array(
			'untitled'       => __( '(untitled)', 'listemann-toolkit-addon' ),
			'sectionAdded'   => __( 'Section added.', 'listemann-toolkit-addon' ),
			'sectionRemoved' => __( 'Section removed.', 'listemann-toolkit-addon' ),
			'lineAdded'      => __( 'Line added.', 'listemann-toolkit-addon' ),
			'lineRemoved'    => __( 'Line removed.', 'listemann-toolkit-addon' ),
			'movedUp'        => __( 'Moved up.', 'listemann-toolkit-addon' ),
			'movedDown'      => __( 'Moved down.', 'listemann-toolkit-addon' ),
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.listemannToolkitAdmin = ' . wp_json_encode( array( 'i18n' => $l10n ) ) . ';',
			'before'
		);
	}

	/**
	 * Handles the "Reset to defaults" form (admin-post.php).
	 *
	 * @return void
	 */
	public static function handle_reset() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'listemann-toolkit-addon' ), 403 );
		}

		check_admin_referer( self::RESET_ACTION );

		delete_option( Settings::OPTION );

		add_settings_error( self::GROUP, 'listemann_toolkit_reset', __( 'Settings reset to defaults.', 'listemann-toolkit-addon' ), 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', self::page_url() ) );
		exit;
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap lst-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="lst-settings__intro">
				<?php esc_html_e( 'These features are added automatically to every page of the website: the contact box (opens on click) and the back-to-top button (opens on hover) on the right edge, and the colored scrollbar.', 'listemann-toolkit-addon' ); ?>
			</p>

			<?php settings_errors(); ?>

			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post" class="lst-settings__form">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="lst-settings__reset" data-lst-confirm="<?php echo esc_attr__( 'Reset all Listemann Toolkit settings to the defaults? Your changes will be lost.', 'listemann-toolkit-addon' ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::RESET_ACTION ); ?>">
				<?php wp_nonce_field( self::RESET_ACTION ); ?>
				<?php submit_button( __( 'Reset to defaults', 'listemann-toolkit-addon' ), 'secondary', 'lst-reset', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Description of the contact box section.
	 *
	 * @return void
	 */
	public static function render_contact_intro() {
		echo '<p>' . esc_html__( 'The red box that slides in from the right edge when the contact button is clicked.', 'listemann-toolkit-addon' ) . '</p>';
	}

	/**
	 * Registers a simple field.
	 *
	 * @param string $group Settings group key.
	 * @param string $key   Setting key.
	 * @param string $title Field title.
	 * @param string $type  checkbox|text|textarea|number|color.
	 * @param array  $args  Extra arguments for the renderer.
	 * @return void
	 */
	private static function add_field( $group, $key, $title, $type, array $args = array() ) {
		$id   = self::field_id( $group, $key );
		$args = array_merge(
			$args,
			array(
				'group' => $group,
				'key'   => $key,
				'type'  => $type,
				'id'    => $id,
			)
		);

		if ( 'checkbox' !== $type ) {
			$args['label_for'] = $id;
		}

		add_settings_field( $id, $title, array( __CLASS__, 'render_field' ), self::PAGE_SLUG, 'lst_' . $group, $args );
	}

	/**
	 * Renders a simple field.
	 *
	 * @param array $args Field arguments (see add_field()).
	 * @return void
	 */
	public static function render_field( $args ) {
		$settings = Settings::get();
		$group    = $args['group'];
		$key      = $args['key'];
		$value    = isset( $settings[ $group ][ $key ] ) ? $settings[ $group ][ $key ] : '';
		$name     = self::field_name( $group, $key );
		$id       = $args['id'];
		$desc_id  = $id . '-description';
		$has_desc = ! empty( $args['description'] );
		$describe = $has_desc ? ' aria-describedby="' . esc_attr( $desc_id ) . '"' : '';

		switch ( $args['type'] ) {
			case 'checkbox':
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s%4$s> %5$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					$describe, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					esc_html( isset( $args['text'] ) ? $args['text'] : '' )
				);
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="3" cols="30" class="lst-textarea"%3$s>%4$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					$describe, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					esc_textarea( (string) $value )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" step="1" class="small-text"%6$s> %7$s',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) absint( $value ) ),
					esc_attr( (string) $args['min'] ),
					esc_attr( (string) $args['max'] ),
					$describe, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					esc_html( isset( $args['unit'] ) ? $args['unit'] : '' )
				);
				break;

			case 'color':
				$defaults = Settings::defaults();
				$default  = empty( $args['allow_empty'] ) && isset( $defaults[ $group ][ $key ] ) ? $defaults[ $group ][ $key ] : '';
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="lst-color-picker" data-default-color="%4$s" maxlength="7" spellcheck="false" autocomplete="off"%5$s>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) $default ),
					$describe // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
				break;

			case 'text':
			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text"%4$s>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					$describe // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
				break;
		}

		if ( $has_desc ) {
			printf( '<p class="description" id="%1$s">%2$s</p>', esc_attr( $desc_id ), esc_html( $args['description'] ) );
		}
	}

	/**
	 * Renders the repeatable contact sections (with <template>s for the JS).
	 *
	 * @return void
	 */
	public static function render_sections_field() {
		$sections = Settings::get( 'contact' );
		$sections = isset( $sections['sections'] ) && is_array( $sections['sections'] ) ? array_values( $sections['sections'] ) : array();
		$base     = self::field_name( 'contact', 'sections' );
		?>
		<fieldset class="lst-rep" data-lst-repeater data-lst-base="<?php echo esc_attr( $base ); ?>" aria-describedby="lst-sections-description">
			<legend class="screen-reader-text"><?php esc_html_e( 'Contact box sections', 'listemann-toolkit-addon' ); ?></legend>
			<p class="description" id="lst-sections-description">
				<?php esc_html_e( 'Each section has a title (shown in capitals) and lines. A line can link to a phone number (tel:+41522451850), an e-mail address (mailto:info@example.com) or a web page (https://…); phone numbers and e-mail addresses without prefix are recognized automatically. Leave the link empty for plain text. Empty lines and sections are removed when saving.', 'listemann-toolkit-addon' ); ?>
			</p>

			<div class="lst-rep__sections" data-lst-list="sections">
				<?php
				foreach ( $sections as $index => $section ) {
					if ( is_array( $section ) ) {
						self::render_section( (int) $index, $section );
					}
				}
				?>
			</div>

			<p class="lst-rep__empty" data-lst-empty="sections"<?php echo empty( $sections ) ? '' : ' hidden'; ?>>
				<?php esc_html_e( 'No sections yet. The contact box is only shown when it has at least one section.', 'listemann-toolkit-addon' ); ?>
			</p>

			<p class="lst-rep__footer">
				<button type="button" class="button" data-lst-action="section-add">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Add section', 'listemann-toolkit-addon' ); ?>
				</button>
			</p>

			<template data-lst-template="section">
				<?php
				self::render_section(
					null,
					array(
						'title' => '',
						'size'  => 'large',
						'lines' => array(),
					)
				);
				?>
			</template>
			<template data-lst-template="line">
				<?php
				self::render_line(
					null,
					null,
					array(
						'text'    => '',
						'url'     => '',
						'new_tab' => false,
					)
				);
				?>
			</template>
		</fieldset>
		<?php
	}

	/**
	 * Renders one section of the repeater.
	 *
	 * @param int|null $index   Section index, or null for the JS template (no names).
	 * @param array    $section Section values.
	 * @return void
	 */
	private static function render_section( $index, array $section ) {
		$title = isset( $section['title'] ) ? (string) $section['title'] : '';
		$size  = isset( $section['size'] ) && in_array( $section['size'], Settings::SECTION_SIZES, true ) ? $section['size'] : 'large';
		$lines = isset( $section['lines'] ) && is_array( $section['lines'] ) ? array_values( $section['lines'] ) : array();
		$sizes = array(
			'large' => __( 'Large – like phone numbers (19px)', 'listemann-toolkit-addon' ),
			'small' => __( 'Small – like addresses (15px)', 'listemann-toolkit-addon' ),
		);
		?>
		<div class="lst-rep__section" data-lst-item="section">
			<div class="lst-rep__section-header">
				<h3 class="lst-rep__section-heading">
					<span class="lst-rep__section-number" data-lst-number><?php echo esc_html( null === $index ? '' : (string) ( $index + 1 ) ); ?></span>
					<span class="lst-rep__section-title" data-lst-heading><?php echo esc_html( '' !== $title ? $title : __( '(untitled)', 'listemann-toolkit-addon' ) ); ?></span>
				</h3>
				<div class="lst-rep__tools">
					<?php
					self::render_tool_button( 'section-up', 'dashicons-arrow-up-alt2', __( 'Move section up', 'listemann-toolkit-addon' ) );
					self::render_tool_button( 'section-down', 'dashicons-arrow-down-alt2', __( 'Move section down', 'listemann-toolkit-addon' ) );
					?>
					<button type="button" class="button-link button-link-delete lst-rep__remove" data-lst-action="section-remove">
						<?php esc_html_e( 'Remove section', 'listemann-toolkit-addon' ); ?>
					</button>
				</div>
			</div>

			<div class="lst-rep__section-fields">
				<label class="lst-rep__field">
					<span class="lst-rep__field-label"><?php esc_html_e( 'Title', 'listemann-toolkit-addon' ); ?></span>
					<input type="text" class="regular-text" data-lst-field="title" value="<?php echo esc_attr( $title ); ?>"<?php self::print_name( $index, 'title' ); ?>>
				</label>
				<label class="lst-rep__field">
					<span class="lst-rep__field-label"><?php esc_html_e( 'Text size', 'listemann-toolkit-addon' ); ?></span>
					<select data-lst-field="size"<?php self::print_name( $index, 'size' ); ?>>
						<?php foreach ( $sizes as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $size, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>

			<div class="lst-rep__lines">
				<div class="lst-rep__lines-head" aria-hidden="true">
					<span><?php esc_html_e( 'Text', 'listemann-toolkit-addon' ); ?></span>
					<span><?php esc_html_e( 'Link URL', 'listemann-toolkit-addon' ); ?></span>
				</div>
				<div class="lst-rep__lines-list" data-lst-list="lines">
					<?php
					foreach ( $lines as $line_index => $line ) {
						if ( is_array( $line ) ) {
							self::render_line( $index, (int) $line_index, $line );
						}
					}
					?>
				</div>
				<p class="lst-rep__empty" data-lst-empty="lines"<?php echo empty( $lines ) ? '' : ' hidden'; ?>>
					<?php esc_html_e( 'No lines yet.', 'listemann-toolkit-addon' ); ?>
				</p>
				<button type="button" class="button button-small" data-lst-action="line-add">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Add line', 'listemann-toolkit-addon' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders one line of a section.
	 *
	 * @param int|null $section_index Section index, or null for the JS template.
	 * @param int|null $line_index    Line index, or null for the JS template.
	 * @param array    $line          Line values.
	 * @return void
	 */
	private static function render_line( $section_index, $line_index, array $line ) {
		$text    = isset( $line['text'] ) ? (string) $line['text'] : '';
		$url     = isset( $line['url'] ) ? (string) $line['url'] : '';
		$new_tab = ! empty( $line['new_tab'] );
		$named   = null !== $section_index && null !== $line_index;
		?>
		<div class="lst-rep__line" data-lst-item="line">
			<label class="lst-rep__line-field lst-rep__line-field--text">
				<span class="lst-rep__line-label"><?php esc_html_e( 'Text', 'listemann-toolkit-addon' ); ?></span>
				<input type="text" data-lst-line-field="text" value="<?php echo esc_attr( $text ); ?>"<?php echo $named ? self::name_attr( $section_index, 'lines][' . $line_index . '][text' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name_attr(). ?>>
			</label>
			<label class="lst-rep__line-field lst-rep__line-field--url">
				<span class="lst-rep__line-label"><?php esc_html_e( 'Link URL', 'listemann-toolkit-addon' ); ?></span>
				<input type="text" inputmode="url" spellcheck="false" autocomplete="off" placeholder="tel:+41…, mailto:…, https://…" data-lst-line-field="url" value="<?php echo esc_attr( $url ); ?>"<?php echo $named ? self::name_attr( $section_index, 'lines][' . $line_index . '][url' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name_attr(). ?>>
			</label>
			<label class="lst-rep__line-field lst-rep__line-field--new-tab">
				<input type="checkbox" value="1" data-lst-line-field="new_tab"<?php checked( $new_tab ); ?><?php echo $named ? self::name_attr( $section_index, 'lines][' . $line_index . '][new_tab' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name_attr(). ?>>
				<?php esc_html_e( 'Open in new tab', 'listemann-toolkit-addon' ); ?>
			</label>
			<div class="lst-rep__tools">
				<?php
				self::render_tool_button( 'line-up', 'dashicons-arrow-up-alt2', __( 'Move line up', 'listemann-toolkit-addon' ) );
				self::render_tool_button( 'line-down', 'dashicons-arrow-down-alt2', __( 'Move line down', 'listemann-toolkit-addon' ) );
				self::render_tool_button( 'line-remove', 'dashicons-trash', __( 'Remove line', 'listemann-toolkit-addon' ) );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders an icon-only tool button.
	 *
	 * @param string $action Action name (data-lst-action).
	 * @param string $icon   Dashicons class.
	 * @param string $label  Accessible label (also used as tooltip).
	 * @return void
	 */
	private static function render_tool_button( $action, $icon, $label ) {
		printf(
			'<button type="button" class="button button-small lst-rep__icon-button" data-lst-action="%1$s" aria-label="%2$s" title="%2$s"><span class="dashicons %3$s" aria-hidden="true"></span></button>',
			esc_attr( $action ),
			esc_attr( $label ),
			esc_attr( $icon )
		);
	}

	/**
	 * Prints the name attribute of a section-level repeater field.
	 *
	 * @param int|null $index Section index, or null for the JS template (prints nothing).
	 * @param string   $field Field key.
	 * @return void
	 */
	private static function print_name( $index, $field ) {
		if ( null !== $index ) {
			echo self::name_attr( $index, $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in name_attr().
		}
	}

	/**
	 * Builds an escaped ` name="…"` attribute for a repeater field.
	 *
	 * @param int    $section_index Section index.
	 * @param string $path          Path below the section, e.g. "title" or "lines][0][text".
	 * @return string
	 */
	private static function name_attr( $section_index, $path ) {
		return ' name="' . esc_attr( self::field_name( 'contact', 'sections' ) . '[' . (int) $section_index . '][' . $path . ']' ) . '"';
	}

	/**
	 * Name of a settings field.
	 *
	 * @param string $group Settings group key.
	 * @param string $key   Setting key.
	 * @return string
	 */
	private static function field_name( $group, $key ) {
		return Settings::OPTION . '[' . $group . '][' . $key . ']';
	}

	/**
	 * ID of a settings field.
	 *
	 * @param string $group Settings group key.
	 * @param string $key   Setting key.
	 * @return string
	 */
	private static function field_id( $group, $key ) {
		return 'lst-' . str_replace( '_', '-', $group . '-' . $key );
	}
}
