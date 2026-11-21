<?php
/**
 * Windrose Anchors integration (optional): tells Anchors which media hub each site of a language group uses, the
 * group's main site, through Anchors' filter `windrose_anchors_group_hubs`. Anchors uses it only while its network
 * administrator chose "Follow language groups"; without Anchors the filter is never called.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media hubs from language groups.
 */
final class Media_Hubs {

	/**
	 * Hooks the filter.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'windrose_anchors_group_hubs', array( __CLASS__, 'hubs' ) );
	}

	/**
	 * Site ID => main site of its group, for every group that names a main site (the main site maps to itself).
	 *
	 * @param mixed $map Map so far.
	 * @return mixed
	 */
	public static function hubs( $map ) {
		$map = is_array( $map ) ? $map : array();
		foreach ( Groups::all() as $group ) {
			$sites = Groups::sites( $group['id'] );
			if ( 0 === $group['main'] || ! in_array( $group['main'], $sites, true ) ) {
				continue;
			}
			foreach ( $sites as $site ) {
				$map[ $site ] = $group['main'];
			}
		}
		return $map;
	}
}
