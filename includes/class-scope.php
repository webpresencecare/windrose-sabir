<?php
/**
 * Applies language groups to Multisite Language Switcher (MSLS), through its documented filters only:
 *
 * | Filter                            | On a site in a group             | On a site in no group       |
 * |-----------------------------------|----------------------------------|-----------------------------|
 * | `msls_blog_collection_construct`  | The group's sites                | No site                     |
 * | `msls_supported_post_types`       | Unchanged                        | None (no MSLS editor boxes) |
 * | `msls_supported_taxonomies`       | Unchanged                        | None                        |
 *
 * MSLS uses its collection for the switcher, hreflang links, the editor's translation boxes, the list columns and
 * the admin bar, so all of them follow the group. In a group, the group itself is the site list: MSLS's reference
 * user no longer limits it (MSLS is moving the same way, to a site list in site meta). Archived, spam and deleted
 * sites are left out, as MSLS's own list does, and MSLS still skips sites where it is not set up or excluded.
 *
 * Nothing changes while the network has no group: MSLS then works exactly as without Windrose Sabir.
 *
 * MSLS builds its collection once per request, for the site the request is for.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MSLS filters.
 */
final class Scope {

	/**
	 * Hooks the MSLS filters (late, after other add-ons).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'msls_blog_collection_construct', array( __CLASS__, 'collection' ), 20 );
		add_filter( 'msls_supported_post_types', array( __CLASS__, 'content_types' ), 20 );
		add_filter( 'msls_supported_taxonomies', array( __CLASS__, 'content_types' ), 20 );
	}

	/**
	 * The sites MSLS may link from the current site (`msls_blog_collection_construct`).
	 *
	 * @param mixed $blogs Site objects as `get_blogs_of_user()` returns them (MSLS: the reference user's sites).
	 * @return mixed Same shape: the group's sites, or none outside every group.
	 */
	public static function collection( $blogs ) {
		if ( ! Groups::exist() ) {
			return $blogs;
		}
		$group = Groups::of_site( get_current_blog_id() );
		if ( 0 === $group ) {
			return array();
		}
		$ids = Groups::sites( $group, true );
		if ( array() === $ids ) {
			return array();
		}
		$sites = get_sites(
			array(
				'site__in' => $ids,
				'number'   => 0,
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);
		$list  = array();
		foreach ( is_array( $sites ) ? $sites : array() as $site ) {
			if ( ! $site instanceof \WP_Site ) {
				continue;
			}
			$id          = (int) $site->blog_id;
			$list[ $id ] = (object) array(
				'userblog_id' => $id,
				'blogname'    => $site->blogname,
				'domain'      => $site->domain,
				'path'        => $site->path,
				'site_id'     => (int) $site->network_id,
				'siteurl'     => $site->siteurl,
				'archived'    => $site->archived,
				'mature'      => $site->mature,
				'spam'        => $site->spam,
				'deleted'     => $site->deleted,
			);
		}
		return $list;
	}

	/**
	 * Post types and taxonomies MSLS handles (`msls_supported_post_types`, `msls_supported_taxonomies`): none on a
	 * site in no group, so MSLS shows no editor boxes or list columns there.
	 *
	 * @param mixed $types Post type or taxonomy names.
	 * @return mixed
	 */
	public static function content_types( $types ) {
		if ( Groups::exist() && 0 === Groups::of_site( get_current_blog_id() ) ) {
			return array();
		}
		return $types;
	}
}
