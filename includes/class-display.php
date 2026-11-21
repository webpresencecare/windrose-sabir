<?php
/**
 * Language display of Multisite Language Switcher's own switcher (Site Editor module): names
 * (default), codes, or flags. With names or codes, Windrose Sabir swaps MSLS's link object through its documented
 * `msls_link_create` filter, so no flag shows on the front end whatever each site's MSLS "Display" setting says
 * With "flags", MSLS works as configured on each site. The toolbar follows the same choice (Toolbar); other
 * admin screens keep MSLS's "Admin display" setting.
 *
 * Only while the network has a language group (nothing changes before one is saved).
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switcher display.
 */
final class Display {

	/**
	 * Hooks the filter.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'msls_link_create', array( __CLASS__, 'link' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'style' ) );
	}

	/**
	 * With codes, the language item's style hides the screen reader text of MSLS's links.
	 *
	 * @return void
	 */
	public static function style(): void {
		if ( Modules::on( 'site_editor' ) && Groups::exist() && 'codes' === Modules::display() ) {
			wp_enqueue_style( Language_Item::HANDLE );
		}
	}

	/**
	 * The link object MSLS uses for each language (`msls_link_create`).
	 *
	 * @param mixed $link    MSLS's link object.
	 * @param mixed $display MSLS's display setting.
	 * @return mixed
	 */
	public static function link( $link, $display = null ) {
		unset( $display );
		if ( ! Modules::on( 'site_editor' ) || ! Groups::exist() ) {
			return $link;
		}
		return match ( Modules::display() ) {
			'codes' => new Code_Link(),
			'names' => new \lloc\Msls\Link\TextOnly(),
			default => $link,
		};
	}
}
