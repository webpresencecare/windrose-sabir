<?php
/**
 * Language groups: sets of sites that are translations of each other.
 *
 * Storage, per network:
 *
 * | What                    | Where                                                             |
 * |-------------------------|-------------------------------------------------------------------|
 * | Groups, names, main site | Network option `windrose_sabir_groups`: list of `{ id, label, main }` |
 * | A site's group          | Site meta `windrose_sabir_group` (core's `wp_blogmeta`): group ID |
 *
 * A site belongs to one group at most, and a group holds one site per language (MSLS finds a site by its language).
 * A group may name a main site (`main`, 0 for none): the site of the source language, the default source of Copy
 * and, with Windrose Anchors, the media hub of the group's sites when the network administrator follows language
 * groups there.
 *
 * The site meta is the source of truth for membership; the option only names the groups. A site meta value naming a
 * group that no longer exists counts as no group. Core deletes a site's meta with the site.
 *
 * MSLS may read its site list from site meta itself one day (discussed with Dennis Ploetner, its maintainer); Sabir
 * then writes MSLS's key and drops its own filter. The meta is registered with `show_in_rest` and a `manage_sites` check, ready
 * for core's coming `wp/v2/sites` endpoint.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

use WP_Error;
use WP_Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language groups.
 *
 * @phpstan-type Group array{id: int, label: string, main: int}
 */
final class Groups {

	public const OPTION    = 'windrose_sabir_groups';
	public const META      = 'windrose_sabir_group';
	public const LABEL_MAX = 100;
	public const MIN_SITES = 2;

	/**
	 * Hooks the site meta registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
	}

	/**
	 * Registers the site meta `windrose_sabir_group`.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		register_meta(
			'blog',
			self::META,
			array(
				'type'              => 'integer',
				'description'       => __( 'The Windrose Sabir language group of this site (0: none).', 'windrose-sabir' ),
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn(): bool => current_user_can( 'manage_sites' ),
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * Every group of the current network, in the order they were created.
	 *
	 * @return list<array{id: int, label: string, main: int}>
	 */
	public static function all(): array {
		$raw = get_site_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$groups = array();
		foreach ( $raw as $item ) {
			if ( is_array( $item ) && isset( $item['id'], $item['label'] ) && (int) $item['id'] > 0 ) {
				$groups[] = array(
					'id'    => (int) $item['id'],
					'label' => (string) $item['label'],
					'main'  => (int) ( $item['main'] ?? 0 ),
				);
			}
		}
		return $groups;
	}

	/**
	 * Whether the network has at least one group. Without one, MSLS works exactly as without Windrose Sabir.
	 *
	 * @return bool
	 */
	public static function exist(): bool {
		return array() !== self::all();
	}

	/**
	 * One group.
	 *
	 * @param int $id Group ID.
	 * @return array{id: int, label: string, main: int}|null
	 */
	public static function get( int $id ): ?array {
		return array_find( self::all(), static fn( array $group ): bool => $group['id'] === $id );
	}

	/**
	 * The group of a site.
	 *
	 * @param int $blog_id Site ID.
	 * @return int Group ID, 0 when the site is in no (existing) group.
	 */
	public static function of_site( int $blog_id ): int {
		$id = (int) get_site_meta( $blog_id, self::META, true );
		return $id > 0 && null !== self::get( $id ) ? $id : 0;
	}

	/**
	 * The main site of a site's language group.
	 *
	 * @param int $blog_id Site ID.
	 * @return int Main site ID, 0 when the site is in no group or its group names no main site.
	 */
	public static function main_site( int $blog_id ): int {
		$group = self::get( self::of_site( $blog_id ) );
		if ( null === $group || 0 === $group['main'] ) {
			return 0;
		}
		// A main site that was deleted or left the group no longer counts.
		return in_array( $group['main'], self::sites( $group['id'] ), true ) ? $group['main'] : 0;
	}

	/**
	 * The sites of a group on the current network, by ID.
	 *
	 * @param int  $group_id    Group ID.
	 * @param bool $public_only Leave out archived, spam and deleted sites (as MSLS's own site list does).
	 * @return list<int>
	 */
	public static function sites( int $group_id, bool $public_only = false ): array {
		if ( $group_id <= 0 ) {
			return array();
		}
		$args = array(
			'fields'     => 'ids',
			'number'     => 0,
			'network_id' => get_current_network_id(),
			'orderby'    => 'id',
			'order'      => 'ASC',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small table (one row per site in a group), cached by core's site query cache.
			'meta_query' => array(
				array(
					'key'   => self::META,
					'value' => (string) $group_id,
				),
			),
		);
		if ( $public_only ) {
			$args['archived'] = 0;
			$args['spam']     = 0;
			$args['deleted']  = 0;
		}
		$ids = get_sites( $args );
		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * A site's language as MSLS reads it: the site's WPLANG option, en_US when empty.
	 *
	 * @param int $blog_id Site ID.
	 * @return string Locale.
	 */
	public static function locale( int $blog_id ): string {
		$locale = get_blog_option( $blog_id, 'WPLANG' );
		return is_string( $locale ) && '' !== $locale ? $locale : 'en_US';
	}

	/**
	 * A site's name and address, for messages.
	 *
	 * @param int $blog_id Site ID.
	 * @return string
	 */
	public static function site_label( int $blog_id ): string {
		$site = get_site( $blog_id );
		if ( ! $site instanceof WP_Site ) {
			/* translators: %d: site ID. */
			return sprintf( __( 'Site %d', 'windrose-sabir' ), $blog_id );
		}
		$address = untrailingslashit( $site->domain . $site->path );
		$name    = trim( (string) $site->blogname );
		return '' === $name ? $address : sprintf(
			/* translators: 1: site name, 2: site address. */
			__( '%1$s (%2$s)', 'windrose-sabir' ),
			$name,
			$address
		);
	}

	/**
	 * Creates or changes a group.
	 *
	 * @param int        $id       Group ID, 0 for a new group.
	 * @param string     $label    Name.
	 * @param array<int> $site_ids Sites of the group (at least two, one per language, in no other group).
	 * @param int        $main     Main site (one of the group's sites), 0 for none.
	 * @return int|WP_Error The group ID.
	 */
	public static function save( int $id, string $label, array $site_ids, int $main = 0 ): int|WP_Error {
		$groups = self::all();
		if ( $id > 0 && null === self::get( $id ) ) {
			return new WP_Error( 'windrose_sabir_group_not_found', __( 'This language group no longer exists.', 'windrose-sabir' ), array( 'status' => 404 ) );
		}

		$label = trim( sanitize_text_field( $label ) );
		if ( '' === $label ) {
			return new WP_Error( 'windrose_sabir_group_label', __( 'Give the language group a name.', 'windrose-sabir' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $label ) > self::LABEL_MAX ) {
			return new WP_Error(
				'windrose_sabir_group_label',
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'The name of a language group can have up to %d characters.', 'windrose-sabir' ), self::LABEL_MAX ),
				array( 'status' => 400 )
			);
		}
		$taken = array_find( $groups, static fn( array $group ): bool => $group['id'] !== $id && 0 === strcasecmp( $group['label'], $label ) );
		if ( null !== $taken ) {
			return new WP_Error(
				'windrose_sabir_group_label',
				/* translators: %s: group name. */
				sprintf( __( 'Another language group is already called “%s”.', 'windrose-sabir' ), $label ),
				array( 'status' => 400 )
			);
		}

		$site_ids = array_values( array_unique( array_filter( array_map( 'intval', $site_ids ), static fn( int $site ): bool => $site > 0 ) ) );
		sort( $site_ids );
		if ( count( $site_ids ) < self::MIN_SITES ) {
			return new WP_Error( 'windrose_sabir_group_sites', __( 'Choose at least two sites: a language group links sites that are translations of each other.', 'windrose-sabir' ), array( 'status' => 400 ) );
		}
		$check = self::check_sites( $id, $site_ids );
		if ( $check instanceof WP_Error ) {
			return $check;
		}
		if ( 0 !== $main && ! in_array( $main, $site_ids, true ) ) {
			return new WP_Error( 'windrose_sabir_group_main', __( 'The main site must be one of the sites of the language group.', 'windrose-sabir' ), array( 'status' => 400 ) );
		}
		$main = max( 0, $main );

		if ( 0 === $id ) {
			$id       = 1 + array_reduce( $groups, static fn( int $max, array $group ): int => max( $max, $group['id'] ), 0 );
			$groups[] = array(
				'id'    => $id,
				'label' => $label,
				'main'  => $main,
			);
		} else {
			foreach ( $groups as $index => $group ) {
				if ( $group['id'] === $id ) {
					$groups[ $index ]['label'] = $label;
					$groups[ $index ]['main']  = $main;
				}
			}
		}
		update_site_option( self::OPTION, $groups );

		foreach ( array_diff( self::sites( $id ), $site_ids ) as $removed ) {
			delete_site_meta( $removed, self::META );
		}
		foreach ( $site_ids as $site_id ) {
			update_site_meta( $site_id, self::META, $id );
		}
		return $id;
	}

	/**
	 * Deletes a group. Its sites are then in no group (MSLS shows nothing there while other groups exist).
	 *
	 * @param int $id Group ID.
	 * @return true|WP_Error
	 */
	public static function delete( int $id ): bool|WP_Error {
		if ( null === self::get( $id ) ) {
			return new WP_Error( 'windrose_sabir_group_not_found', __( 'This language group no longer exists.', 'windrose-sabir' ), array( 'status' => 404 ) );
		}
		foreach ( self::sites( $id ) as $site_id ) {
			delete_site_meta( $site_id, self::META );
		}
		$groups = array_values( array_filter( self::all(), static fn( array $group ): bool => $group['id'] !== $id ) );
		if ( array() === $groups ) {
			delete_site_option( self::OPTION );
		} else {
			update_site_option( self::OPTION, $groups );
		}
		return true;
	}

	/**
	 * Checks the sites of a group: on this network, not deleted, in no other group, one per language.
	 *
	 * @param int   $id       Group ID (0 for a new group).
	 * @param int[] $site_ids Site IDs.
	 * @return true|WP_Error
	 */
	private static function check_sites( int $id, array $site_ids ): bool|WP_Error {
		$network = get_current_network_id();
		$locales = array();
		foreach ( $site_ids as $site_id ) {
			$site = get_site( $site_id );
			if ( ! $site instanceof WP_Site || (int) $site->network_id !== $network || '1' === (string) $site->deleted ) {
				return new WP_Error(
					'windrose_sabir_group_sites',
					/* translators: %d: site ID. */
					sprintf( __( 'Site %d does not exist on this network.', 'windrose-sabir' ), $site_id ),
					array( 'status' => 400 )
				);
			}
			$other = self::of_site( $site_id );
			if ( 0 !== $other && $other !== $id ) {
				$group = self::get( $other );
				return new WP_Error(
					'windrose_sabir_group_sites',
					sprintf(
						/* translators: 1: site name and address, 2: group name. */
						__( '%1$s is already in the language group “%2$s”. A site can be in one language group only.', 'windrose-sabir' ),
						self::site_label( $site_id ),
						null === $group ? '' : $group['label']
					),
					array( 'status' => 400 )
				);
			}
			$locale = self::locale( $site_id );
			if ( isset( $locales[ $locale ] ) ) {
				return new WP_Error(
					'windrose_sabir_group_sites',
					sprintf(
						/* translators: 1: site name and address, 2: other site name and address, 3: locale code (fr_FR). */
						__( '%1$s and %2$s both use the language %3$s. A language group holds one site per language.', 'windrose-sabir' ),
						self::site_label( $locales[ $locale ] ),
						self::site_label( $site_id ),
						$locale
					),
					array( 'status' => 400 )
				);
			}
			$locales[ $locale ] = $site_id;
		}//end foreach
		return true;
	}
}
