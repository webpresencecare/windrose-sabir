<?php
/**
 * Per-site settings of Windrose Sabir, in the site option `windrose_sabir_site`, edited in Windrose
 * Sabir's section of Multisite Language Switcher's settings page (Msls_Settings) and saved by MSLS's own form.
 *
 * | Key          | Value                                                                                   |
 * |--------------|-----------------------------------------------------------------------------------------|
 * | `post_types` | Content types this site may copy to other languages; missing: every type MSLS handles     |
 * | `terms`      | When a copied post has a category or tag with no translation on this site: `create` (default: create it here, linked) or `skip` |
 *
 * Translate's settings (post types and default provider) join it in a later version. The form sends `form` = 1 with the fields,
 * so a save of MSLS's settings without them keeps the stored values.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-site settings.
 *
 * @phpstan-type Settings array{post_types: list<string>|null, terms: string}
 */
final class Site_Settings {

	public const OPTION = 'windrose_sabir_site';

	/** Values of `terms`. */
	public const TERMS = array( 'create', 'skip' );

	/**
	 * The current site's settings.
	 *
	 * @return array{post_types: list<string>|null, terms: string}
	 */
	public static function get(): array {
		$value = get_option( self::OPTION, array() );
		$value = is_array( $value ) ? $value : array();
		$types = null;
		if ( isset( $value['post_types'] ) && is_array( $value['post_types'] ) ) {
			$types = array_values( array_filter( array_map( 'strval', $value['post_types'] ), static fn( string $type ): bool => '' !== $type ) );
		}
		$terms = isset( $value['terms'] ) && in_array( $value['terms'], self::TERMS, true ) ? (string) $value['terms'] : 'create';
		return array(
			'post_types' => $types,
			'terms'      => $terms,
		);
	}

	/**
	 * Settings of another site.
	 *
	 * @param int $blog_id Site ID.
	 * @return array{post_types: list<string>|null, terms: string}
	 */
	public static function of_site( int $blog_id ): array {
		switch_to_blog( $blog_id );
		try {
			return self::get();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Content types Multisite Language Switcher handles on the current site: MSLS's own list (posts, pages, public
	 * custom types, its `msls_supported_post_types` filter), without attachments and types not registered here.
	 *
	 * @return list<string>
	 */
	public static function msls_post_types(): array {
		$types = array_filter( \lloc\Msls\ContentTypes\PostType::get(), 'is_string' );
		return array_values( array_unique( array_filter( $types, static fn( string $type ): bool => 'attachment' !== $type && post_type_exists( $type ) ) ) );
	}

	/**
	 * Content types the current site may copy.
	 *
	 * @return list<string>
	 */
	public static function copy_post_types(): array {
		$chosen = self::get()['post_types'];
		$msls   = self::msls_post_types();
		return null === $chosen ? $msls : array_values( array_intersect( $msls, $chosen ) );
	}

	/**
	 * Cleans the settings before they are saved (`register_setting` sanitize callback).
	 *
	 * WordPress saves every option of MSLS's settings group, also when the form did not show Windrose Sabir's fields:
	 * the value is then missing, and the stored settings are kept. The form sends `form` = 1, so no box ticked means
	 * no content type. WordPress may run this twice on the first save (update_option(), then add_option()): a value
	 * cleaned once comes out the same.
	 *
	 * @param mixed $value Submitted value.
	 * @return array{post_types: list<string>|null, terms: string}
	 */
	public static function sanitize( $value ): array {
		if ( ! is_array( $value ) ) {
			return self::get();
		}
		$types = null;
		if ( isset( $value['post_types'] ) && is_array( $value['post_types'] ) ) {
			$given = array_map( 'sanitize_key', array_map( 'strval', $value['post_types'] ) );
			$types = array_values( array_intersect( self::msls_post_types(), $given ) );
		} elseif ( isset( $value['form'] ) ) {
			$types = array();
		}
		$terms = isset( $value['terms'] ) && is_string( $value['terms'] ) && in_array( $value['terms'], self::TERMS, true ) ? $value['terms'] : 'create';
		return array(
			'post_types' => $types,
			'terms'      => $terms,
		);
	}
}
