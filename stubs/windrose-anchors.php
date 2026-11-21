<?php
/**
 * The Windrose Anchors methods Windrose Sabir's Copy calls (Copy\Network_Media), for PHPStan: Anchors is optional and
 * not installed where the checks run on GitHub. Signatures from Windrose Anchors 0.5.0.
 *
 * @package Windrose\Sabir
 */

namespace Windrose\Anchors;

final class Settings {
	public const GROUP_HUBS = 'windrose_anchors_group_hubs';
	public static function hub_for( int $blog_id ): int {}
	public static function site_allowed( ?int $blog_id = null ): bool {}
}

final class Hub {
	public static function is_shared( int $hub_id, int $site = 0 ): bool {}
	public static function is_listable( int $hub_id, int $site = 0 ): bool {}
	/**
	 * @return true|\WP_Error
	 */
	public static function set_shared( int $hub_id, bool $shared, int $site = 0 ) {}
}

final class Proxy {
	public const CREATE_UNPUBLISHED = true;
	public static function find( int $hub_id, int $site = 0 ): int {}
	/**
	 * @return array{id: int, created: bool}|\WP_Error
	 */
	public static function create( int $hub_id, int $parent_id = 0, bool $unpublished = false ) {}
	public static function readable_unpublished( int $hub_id, int $hub ): bool {}
}
