<?php
/**
 * "Mega menu columns" field for nav menu items (used by the Listemann Header widget).
 *
 * @package Listemann_Toolkit
 */

namespace Listemann_Toolkit;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Mega menu columns" number field to every item on Appearance → Menus.
 *
 * The value is stored in the post meta `_lst_mega_columns` (0–6) of the menu
 * item. The header walker reads it for top-level items: 2 or more columns turn
 * the item's dropdown into a multi-column mega panel.
 *
 * The Customizer's menu panel is not supported: it saves menu items through
 * its own JS settings, which never post this field.
 */
final class Mega_Menu_Fields {

	/**
	 * Post meta key of the column count.
	 */
	const META_KEY = '_lst_mega_columns';

	/**
	 * Highest accepted column count.
	 */
	const MAX_COLUMNS = 6;

	/**
	 * Name of the posted field (an array keyed by menu item ID).
	 */
	const FIELD_NAME = 'lst_mega_columns';

	/**
	 * Action of the nonce that core prints once per Appearance → Menus form
	 * (outside the menu items, so it survives removing any item).
	 */
	const NONCE_ACTION = 'update-nav_menu';

	/**
	 * Name of that core nonce field.
	 */
	const NONCE_NAME = 'update-nav-menu-nonce';

	/**
	 * Whether init() already ran.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Hooks the field into the menu editor. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		add_action( 'wp_nav_menu_item_custom_fields', array( __CLASS__, 'render_field' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'save_field' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_style' ) );
	}

	/**
	 * Returns the stored column count of a menu item (0 when unset).
	 *
	 * @param int $menu_item_id Menu item (post) ID.
	 * @return int Column count between 0 and MAX_COLUMNS.
	 */
	public static function get_columns( $menu_item_id ) {
		$value = absint( get_post_meta( (int) $menu_item_id, self::META_KEY, true ) );

		return min( $value, self::MAX_COLUMNS );
	}

	/**
	 * Prints the field inside a menu item's settings box.
	 *
	 * @param int      $item_id   Menu item ID.
	 * @param \WP_Post $menu_item Menu item data object (unused).
	 * @return void
	 */
	public static function render_field( $item_id, $menu_item = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$item_id  = (int) $item_id;
		$value    = self::get_columns( $item_id );
		$field_id = 'edit-menu-item-lst-mega-columns-' . $item_id;
		?>
		<p class="field-lst-mega-columns description description-wide">
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php esc_html_e( 'Mega menu columns (Listemann Header)', 'listemann-toolkit-addon' ); ?><br />
				<input
					type="number"
					id="<?php echo esc_attr( $field_id ); ?>"
					class="widefat"
					name="<?php echo esc_attr( self::FIELD_NAME . '[' . $item_id . ']' ); ?>"
					value="<?php echo $value ? esc_attr( (string) $value ) : ''; ?>"
					min="0"
					max="<?php echo esc_attr( (string) self::MAX_COLUMNS ); ?>"
					step="1"
				/>
			</label>
			<span class="description">
				<?php
				printf(
					/* translators: %d: Highest column count. */
					esc_html__( 'Applies to top-level items only: 2–%d shows the dropdown as a multi-column mega panel (each sub item becomes a column). Leave empty or 0 for a normal dropdown.', 'listemann-toolkit-addon' ),
					(int) self::MAX_COLUMNS
				);
				?>
			</span>
		</p>
		<?php
	}

	/**
	 * Saves the field when a menu is saved on Appearance → Menus.
	 *
	 * Does nothing unless the field of this item was posted with the menu
	 * screen's own nonce (core's `update-nav-menu-nonce`) by a user who may
	 * edit menus, so programmatic updates keep the value. The core nonce is
	 * used instead of a nonce of this plugin because a field printed inside a
	 * menu item would be lost when that item is removed before saving.
	 *
	 * @param int $menu_id         Menu (term) ID (unused).
	 * @param int $menu_item_db_id Menu item (post) ID.
	 * @return void
	 */
	public static function save_field( $menu_id, $menu_item_db_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		$menu_item_db_id = (int) $menu_item_db_id;

		// The nonce is verified below, before anything is written.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ self::FIELD_NAME ] ) || ! is_array( $_POST[ self::FIELD_NAME ] ) || ! array_key_exists( $menu_item_db_id, $_POST[ self::FIELD_NAME ] ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		// sanitize_text_field() returns '' for arrays, absint() turns that into 0.
		$value = min( absint( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_NAME ][ $menu_item_db_id ] ) ) ), self::MAX_COLUMNS );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $value > 0 ) {
			update_post_meta( $menu_item_db_id, self::META_KEY, $value );
		} else {
			delete_post_meta( $menu_item_db_id, self::META_KEY );
		}
	}

	/**
	 * Hides the field for nested items on the menu screen.
	 *
	 * Core keeps a `menu-item-depth-N` class on every item and updates it while
	 * items are dragged, so the field follows the item's current depth.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue_admin_style( $hook_suffix ) {
		if ( 'nav-menus.php' !== $hook_suffix ) {
			return;
		}

		wp_add_inline_style( 'nav-menus', '.menu-item:not(.menu-item-depth-0) .field-lst-mega-columns{display:none}' );
	}
}
