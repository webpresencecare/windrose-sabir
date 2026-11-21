<?php
/**
 * Windrose Anchors, seen from Copy (optional: Anchors 0.4.0 or later). Every call goes through here, so Copy works the
 * same without Anchors (images are then copied as files).
 *
 * The filter `windrose_sabir_copy_network_media` (bool) can switch the integration off, for example in tests.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir\Copy;

use Windrose\Anchors\Hub;
use Windrose\Anchors\Proxy;
use Windrose\Anchors\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Windrose Anchors adapter.
 */
final class Network_Media {

	/** Meta Windrose Anchors stores on a network item: the hub attachment it shows. */
	public const HUB_META = '_windrose_anchors_hub';

	/**
	 * Whether Windrose Anchors (with several media hubs, 0.4.0 or later) is active and used by Copy.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		// Several media hubs (Settings::hub_for()) came with Windrose Anchors 0.4.0, as this constant.
		$loaded = class_exists( Proxy::class ) && class_exists( Hub::class ) && defined( 'Windrose\\Anchors\\Settings::GROUP_HUBS' );
		/**
		 * Filters whether Copy uses Windrose Anchors for images (network items instead of file copies).
		 *
		 * @param bool $use True when Windrose Anchors 0.4.0 or later is active.
		 */
		return $loaded && (bool) apply_filters( 'windrose_sabir_copy_network_media', true );
	}

	/**
	 * The media hub a site uses (itself when it is a hub).
	 *
	 * @param int $site Site ID.
	 * @return int
	 */
	public static function hub_for( int $site ): int {
		return Settings::hub_for( $site );
	}

	/**
	 * Whether a site may use network media.
	 *
	 * @param int $site Site ID.
	 * @return bool
	 */
	public static function site_allowed( int $site ): bool {
		return Settings::site_allowed( $site );
	}

	/**
	 * Whether a hub item is shared.
	 *
	 * @param int $hub_id   Attachment ID on the hub.
	 * @param int $hub_site Hub site ID.
	 * @return bool
	 */
	public static function is_shared( int $hub_id, int $hub_site ): bool {
		return Hub::is_shared( $hub_id, $hub_site );
	}

	/**
	 * Whether a hub item may be shown to other sites (attached to nothing or to a published post).
	 *
	 * @param int $hub_id   Attachment ID on the hub.
	 * @param int $hub_site Hub site ID.
	 * @return bool
	 */
	public static function is_listable( int $hub_id, int $hub_site ): bool {
		return Hub::is_listable( $hub_id, $hub_site );
	}

	/**
	 * Shares a hub item with the network. The caller checks `upload_files` on the hub.
	 *
	 * @param int $hub_id   Attachment ID on the hub.
	 * @param int $hub_site Hub site ID.
	 * @return true|WP_Error
	 */
	public static function share( int $hub_id, int $hub_site ): bool|WP_Error {
		return Hub::set_shared( $hub_id, true, $hub_site );
	}

	/**
	 * Creates (or finds) the current site's network item of an item of its hub. Call it while switched to the site.
	 *
	 * @param int  $hub_id      Attachment ID on the current site's hub.
	 * @param int  $parent_id   Post to attach a new network item to.
	 * @param bool $unpublished Also an item attached to a post not published yet on the hub (Anchors 0.5.0).
	 * @return array{id: int, created: bool}|WP_Error
	 */
	public static function create( int $hub_id, int $parent_id, bool $unpublished = false ): array|WP_Error {
		return $unpublished && self::supports_unpublished() ? Proxy::create( $hub_id, $parent_id, true ) : Proxy::create( $hub_id, $parent_id );
	}

	/**
	 * Whether Windrose Anchors makes network items for items of unpublished posts on request (0.5.0 or later).
	 *
	 * @return bool
	 */
	public static function supports_unpublished(): bool {
		return defined( 'Windrose\\Anchors\\Proxy::CREATE_UNPUBLISHED' );
	}

	/**
	 * Whether a hub item is attached to an unpublished post the current user may read on the hub, and Anchors can
	 * make a network item of it.
	 *
	 * @param int $hub_id   Attachment ID on the hub.
	 * @param int $hub_site Hub site ID.
	 * @return bool
	 */
	public static function unpublished_ok( int $hub_id, int $hub_site ): bool {
		return self::supports_unpublished() && Proxy::readable_unpublished( $hub_id, $hub_site );
	}

	/**
	 * The current site's network item of a hub item, or 0. Call it while switched to the site.
	 *
	 * @param int $hub_id   Attachment ID on the hub.
	 * @param int $hub_site Hub site ID.
	 * @return int
	 */
	public static function find( int $hub_id, int $hub_site ): int {
		return Proxy::find( $hub_id, $hub_site );
	}
}
