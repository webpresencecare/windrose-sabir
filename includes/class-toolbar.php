<?php
/**
 * The toolbar (admin bar) follows the network's language display (Display). Multisite Language
 * Switcher adds each site's flag, or its "Admin display" label, after the site names in the toolbar ("site-name" and
 * the "blog-<ID>" items of My Sites). With "names" or "codes", Windrose Sabir replaces that flag with the language
 * name (MSLS's description of the site, as the switcher shows it) or the language code, after MSLS (priority 999),
 * through core's admin bar API. Only items MSLS changed are touched. With "flags", MSLS works as configured.
 *
 * Only while the network has a language group and the Site Editor module is on, as Display.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

use lloc\Msls\Blog\Blog;
use WP_Admin_Bar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Toolbar language display.
 */
final class Toolbar {

	/**
	 * Hooks after Multisite Language Switcher's own toolbar changes.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_bar_menu', array( __CLASS__, 'update' ), 1000 );
	}

	/**
	 * Replaces MSLS's flags in the toolbar.
	 *
	 * @param WP_Admin_Bar $bar The toolbar.
	 * @return void
	 */
	public static function update( WP_Admin_Bar $bar ): void {
		if ( ! Modules::on( 'site_editor' ) || ! Groups::exist() || ! function_exists( 'msls_blog_collection' ) ) {
			return;
		}
		$display = Modules::display();
		if ( 'flags' === $display ) {
			return;
		}

		$collection = msls_blog_collection();
		$items      = array();
		foreach ( $collection->get_plugin_active_blogs() as $blog ) {
			$items[ 'blog-' . (int) $blog->userblog_id ] = $blog;
		}
		$current = $collection->get_current_blog();
		if ( $current instanceof Blog ) {
			$items['site-name'] = $current;
		}

		foreach ( $items as $id => $blog ) {
			$node  = $bar->get_node( $id );
			$title = is_object( $node ) && isset( $node->title ) && is_string( $node->title ) ? $node->title : null;
			if ( null === $title ) {
				continue;
			}
			$title = self::title( $title, (string) $blog->get_language(), $blog->get_description(), $display );
			if ( null !== $title ) {
				$bar->add_node(
					array(
						'id'    => $id,
						'title' => $title,
					)
				);
			}
		}
	}

	/**
	 * A toolbar title with the language instead of MSLS's flag.
	 *
	 * @param string $title       Title as MSLS left it: site name, then its icon wrapper at the end.
	 * @param string $locale      Site language (`fr_FR`).
	 * @param string $description MSLS's description of the site ("Français"), or the locale when it has none.
	 * @param string $display     `names` or `codes`.
	 * @return string|null Null when MSLS added no icon to this item.
	 */
	public static function title( string $title, string $locale, string $description, string $display ): ?string {
		$start = strpos( $title, '<span class="msls-icon-wrapper' );
		if ( false === $start || '' === $locale ) {
			return null;
		}
		if ( 'codes' === $display ) {
			$text = strtoupper( (string) strtok( $locale, '_' ) );
		} else {
			$text = ( '' === $description || $description === $locale ) ? Languages::name( $locale ) : $description;
		}
		return sprintf(
			'%1$s <span class="windrose-sabir-toolbar-language" lang="%2$s">(%3$s)</span>',
			rtrim( substr( $title, 0, $start ) ),
			esc_attr( str_replace( '_', '-', $locale ) ),
			esc_html( $text )
		);
	}
}
