<?php
/**
 * Uninstall Windrose Sabir: removes the language groups (network option `windrose_sabir_groups`, and the site meta
 * `windrose_sabir_group` on every site of every network), the module switches and language display
 * (`windrose_sabir_modules`, `windrose_sabir_display`), each site's settings (`windrose_sabir_site`), the marks on
 * file copies made by Copy (`_windrose_sabir_source`, used only to reuse a copy) and the cached update check.
 * Multisite Language Switcher then links the sites of its reference user again.
 *
 * Kept on purpose: Multisite Language Switcher's own settings and translation links (they belong to it, including the
 * links Copy wrote), and the drafts and media Copy made (they are the sites' content).
 * Language item blocks in templates stay in the content and show nothing once the plugin is gone.
 *
 * @package Windrose\Sabir
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$windrose_sabir_networks = get_networks( array( 'fields' => 'ids' ) );
foreach ( is_array( $windrose_sabir_networks ) ? $windrose_sabir_networks : array() as $windrose_sabir_network ) {
	delete_network_option( (int) $windrose_sabir_network, 'windrose_sabir_groups' );
	delete_network_option( (int) $windrose_sabir_network, 'windrose_sabir_modules' );
	delete_network_option( (int) $windrose_sabir_network, 'windrose_sabir_display' );
}
$windrose_sabir_sites = get_sites(
	array(
		'fields' => 'ids',
		'number' => 0,
	)
);
foreach ( is_array( $windrose_sabir_sites ) ? $windrose_sabir_sites : array() as $windrose_sabir_site ) {
	switch_to_blog( (int) $windrose_sabir_site );
	delete_option( 'windrose_sabir_site' );
	delete_post_meta_by_key( '_windrose_sabir_source' );
	restore_current_blog();
}
delete_metadata( 'blog', 0, 'windrose_sabir_group', '', true );
delete_site_transient( 'windrose_common_release_windrose-sabir' );
