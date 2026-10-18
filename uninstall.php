<?php
/**
 * Uninstall routine: removes the plugin settings and the mega menu item meta.
 *
 * Runs only when the plugin is deleted from the Plugins screen. The plugin
 * itself is not loaded at this point, so the option name and the meta key
 * (Mega_Menu_Fields::META_KEY) are repeated here.
 *
 * @package Listemann_Toolkit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'listemann_toolkit_uninstall_site' ) ) {
	/**
	 * Deletes the plugin option and the nav menu item meta of the current site.
	 *
	 * @return void
	 */
	function listemann_toolkit_uninstall_site() {
		delete_option( 'listemann_toolkit_settings' );
		delete_post_meta_by_key( '_lst_mega_columns' );
	}
}

if ( is_multisite() ) {
	$listemann_toolkit_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $listemann_toolkit_site_ids as $listemann_toolkit_site_id ) {
		switch_to_blog( (int) $listemann_toolkit_site_id );
		listemann_toolkit_uninstall_site();
		restore_current_blog();
	}
} else {
	listemann_toolkit_uninstall_site();
}
