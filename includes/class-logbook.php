<?php
/**
 * Logbook export: the network's languages and translations in files, for leaving, moving to another
 * environment, and a future migration to core multilingual. Network Admin, Windrose Sabir, Logbook (download) and
 * WP-CLI (`wp windrose-sabir logbook export`).
 *
 * JSON (schema 1):
 *
 * | Key         | Holds                                                                                    |
 * |-------------|------------------------------------------------------------------------------------------|
 * | `schema`, `generator`, `exported` | Format version, plugin and version, date (ISO 8601, UTC)              |
 * | `network`   | `id`, `url`                                                                                |
 * | `groups`    | `id`, `label`, `main` (0: none), `sites` (IDs)                                             |
 * | `sites`     | `id`, `url`, `name`, `locale` (WordPress), `language` (BCP 47), `group`                    |
 * | `sets`      | Translation sets: `kind` (post or term), `members` (`site`, `type` (post type or taxonomy), `id`, `status`, `url`, `title`) |
 *
 * Sites: with language groups, the sites in a group; without, the sites where Multisite Language Switcher is set up
 * (not excluded). A set is built from MSLS's links (each language resolved to the site of that language in the same
 * group), keeps only objects that exist, and has at least two members.
 *
 * CSV (the address table, for redirects): one column per site (header: language tag and address), one row per set,
 * with the address of each published member.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

use WP_Post;
use WP_Site;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logbook export.
 *
 * @phpstan-type Member array{site: int, type: string, id: int, status: string, url: string, title: string}
 */
final class Logbook {

	public const SCHEMA = 1;
	public const ACTION = 'windrose_sabir_logbook';

	/**
	 * Hooks the download action.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
	}

	/**
	 * Address of a download (JSON or CSV), with its nonce.
	 *
	 * @param string $format json or csv.
	 * @return string
	 */
	public static function download_url( string $format ): string {
		// Not wp_nonce_url(): it escapes "&" for HTML, and the screen uses the address as it is.
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'format'   => $format,
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Sends a file (admin-post.php, network administrators).
	 *
	 * @return void
	 */
	public static function download(): void {
		if ( ! current_user_can( 'manage_network_options' ) || ! Modules::on( 'logbook' ) ) {
			wp_die( esc_html__( 'You may not export the logbook.', 'windrose-sabir' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$format = isset( $_GET['format'] ) && 'csv' === $_GET['format'] ? 'csv' : 'json';
		$data   = self::build();
		$name   = sprintf( 'windrose-sabir-logbook-%s.%s', gmdate( 'Y-m-d' ), $format );
		nocache_headers();
		header( 'Content-Type: ' . ( 'csv' === $format ? 'text/csv' : 'application/json' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo 'csv' === $format ? self::csv( $data ) : self::json( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A file download, not HTML.
		exit;
	}

	/**
	 * The logbook as JSON text.
	 *
	 * @param array<string, mixed> $data Logbook (build()).
	 * @return string
	 */
	public static function json( array $data ): string {
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * The sites the logbook covers.
	 *
	 * @return list<int>
	 */
	private static function site_ids(): array {
		if ( Groups::exist() ) {
			$ids = array();
			foreach ( Groups::all() as $group ) {
				array_push( $ids, ...Groups::sites( $group['id'] ) );
			}
			sort( $ids );
			return $ids;
		}
		$ids   = array();
		$sites = get_sites(
			array(
				'network_id' => get_current_network_id(),
				'number'     => 0,
				'deleted'    => 0,
				'fields'     => 'ids',
			)
		);
		foreach ( is_array( $sites ) ? $sites : array() as $id ) {
			$msls = get_blog_option( (int) $id, 'msls' );
			if ( is_array( $msls ) && empty( $msls['exclude_current_blog'] ) ) {
				$ids[] = (int) $id;
			}
		}
		return $ids;
	}

	/**
	 * Builds the logbook.
	 *
	 * @return array{schema: int, generator: string, exported: string, network: array{id: int, url: string}, groups: list<array{id: int, label: string, main: int, sites: list<int>}>, sites: list<array{id: int, url: string, name: string, locale: string, language: string, group: int}>, sets: list<array{kind: string, members: list<array{site: int, type: string, id: int, status: string, url: string, title: string}>}>}
	 */
	public static function build(): array {
		$site_ids = self::site_ids();
		$sites    = array();
		$locales  = array();
		foreach ( $site_ids as $id ) {
			$site = get_site( $id );
			if ( ! $site instanceof WP_Site ) {
				continue;
			}
			$locale         = Groups::locale( $id );
			$locales[ $id ] = $locale;
			$sites[]        = array(
				'id'       => $id,
				'url'      => (string) get_home_url( $id, '/' ),
				'name'     => (string) $site->blogname,
				'locale'   => $locale,
				'language' => self::bcp47( $locale ),
				'group'    => Groups::of_site( $id ),
			);
		}
		$groups = array();
		foreach ( Groups::all() as $group ) {
			$group['sites'] = Groups::sites( $group['id'] );
			$groups[]       = $group;
		}
		$network = get_network();
		return array(
			'schema'    => self::SCHEMA,
			'generator' => 'Windrose Sabir ' . WINDROSE_SABIR_VERSION,
			'exported'  => gmdate( 'c' ),
			'network'   => array(
				'id'  => null === $network ? 0 : (int) $network->id,
				'url' => (string) get_home_url( (int) get_main_site_id(), '/' ),
			),
			'groups'    => $groups,
			'sites'     => $sites,
			'sets'      => self::sets( $locales ),
		);
	}

	/**
	 * A WordPress locale as a BCP 47 tag (fr_FR → fr-FR; variants such as de_CH_informal keep language and region).
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function bcp47( string $locale ): string {
		$parts = array_slice( explode( '_', $locale ), 0, 2 );
		return implode( '-', $parts );
	}

	/**
	 * Translation sets from MSLS's links.
	 *
	 * @param array<int, string> $locales Site ID => locale, the sites covered.
	 * @return list<array{kind: string, members: list<array{site: int, type: string, id: int, status: string, url: string, title: string}>}>
	 */
	private static function sets( array $locales ): array {
		// The site of each language, per group (or among every covered site without groups).
		$resolve = array();
		foreach ( $locales as $site => $locale ) {
			$group                          = Groups::exist() ? Groups::of_site( $site ) : 0;
			$resolve[ $group ][ $locale ] ??= $site;
		}
		$parent = array();
		$find   = static function ( string $node ) use ( &$parent, &$find ): string {
			if ( ! isset( $parent[ $node ] ) || $parent[ $node ] === $node ) {
				$parent[ $node ] = $node;
				return $node;
			}
			$parent[ $node ] = $find( $parent[ $node ] );
			return $parent[ $node ];
		};
		$nodes  = array();
		foreach ( $locales as $site => $locale ) {
			$group = Groups::exist() ? Groups::of_site( $site ) : 0;
			foreach ( Links::links_of( $site ) as list( $kind, $id, $links ) ) {
				$from           = $kind . ':' . $site . ':' . $id;
				$nodes[ $from ] = true;
				foreach ( $links as $other_locale => $other_id ) {
					$other_site = $resolve[ $group ][ $other_locale ] ?? 0;
					if ( 0 === $other_site || $other_site === $site ) {
						continue;
					}
					$to                       = $kind . ':' . $other_site . ':' . $other_id;
					$nodes[ $to ]             = true;
					$parent[ $find( $from ) ] = $find( $to );
				}
			}
		}
		$components = array();
		foreach ( array_keys( $nodes ) as $node ) {
			$components[ $find( (string) $node ) ][] = (string) $node;
		}
		// Look up every member once per site.
		$wanted = array();
		foreach ( $components as $members ) {
			foreach ( $members as $node ) {
				list( $kind, $site, $id )         = explode( ':', $node );
				$wanted[ (int) $site ][ $kind ][] = (int) $id;
			}
		}
		$details = array();
		foreach ( $wanted as $site => $kinds ) {
			$details[ $site ] = self::details( $site, $kinds[ Links::POST ] ?? array(), $kinds[ Links::TERM ] ?? array() );
		}
		$sets = array();
		foreach ( $components as $members ) {
			$list = array();
			$kind = '';
			foreach ( $members as $node ) {
				list( $kind, $site, $id ) = explode( ':', $node );
				$found                    = $details[ (int) $site ][ $kind ][ (int) $id ] ?? null;
				if ( null !== $found ) {
					$list[] = $found;
				}
			}
			if ( count( $list ) < 2 ) {
				continue;
			}
			usort( $list, static fn( array $a, array $b ): int => $a['site'] <=> $b['site'] );
			$sets[] = array(
				'kind'    => $kind,
				'members' => $list,
			);
		}
		usort( $sets, static fn( array $a, array $b ): int => array( $a['kind'], $a['members'][0]['site'], $a['members'][0]['id'] ) <=> array( $b['kind'], $b['members'][0]['site'], $b['members'][0]['id'] ) );
		return $sets;
	}

	/**
	 * Members as the logbook lists them, for the objects that exist.
	 *
	 * @param int   $site     Site ID.
	 * @param int[] $post_ids Post IDs.
	 * @param int[] $term_ids Term IDs.
	 * @return array<string, array<int, array{site: int, type: string, id: int, status: string, url: string, title: string}>>
	 */
	private static function details( int $site, array $post_ids, array $term_ids ): array {
		$found = array(
			Links::POST => array(),
			Links::TERM => array(),
		);
		switch_to_blog( $site );
		try {
			// Read by ID, whatever the post type: get_posts( 'any' ) skips types the main site does not have.
			$post_ids = array_values( array_unique( $post_ids ) );
			_prime_post_caches( $post_ids, false, false );
			foreach ( $post_ids as $id ) {
				$post = get_post( $id );
				if ( ! $post instanceof WP_Post || in_array( $post->post_type, array( 'revision', 'nav_menu_item' ), true ) || ! in_array( $post->post_status, array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
					continue;
				}
				$found[ Links::POST ][ $post->ID ] = array(
					'site'   => $site,
					'type'   => $post->post_type,
					'id'     => $post->ID,
					'status' => $post->post_status,
					'url'    => in_array( $post->post_type, array( 'post', 'page' ), true ) ? (string) get_permalink( $post ) : home_url( '?post_type=' . rawurlencode( $post->post_type ) . '&p=' . $post->ID ),
					'title'  => $post->post_title,
				);
			}
			foreach ( array_values( array_unique( $term_ids ) ) as $id ) {
				$term = get_term( $id );
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$found[ Links::TERM ][ $term->term_id ] = array(
					'site'   => $site,
					'type'   => $term->taxonomy,
					'id'     => $term->term_id,
					'status' => 'publish',
					'url'    => self::plain_term_url( $term ),
					'title'  => $term->name,
				);
			}
		} finally {
			restore_current_blog();
		}//end try
		return $found;
	}

	/**
	 * A term's plain address on the current (switched-to) site, which that site redirects to its pretty address.
	 * switch_to_blog() keeps the first site's rewrite rules, so get_term_link() would build the address with the
	 * first site's bases (its /blog/ prefix, its category base); the same for custom post types.
	 *
	 * @param WP_Term $term Term.
	 * @return string
	 */
	private static function plain_term_url( WP_Term $term ): string {
		if ( 'category' === $term->taxonomy ) {
			return home_url( '?cat=' . $term->term_id );
		}
		return home_url( '?taxonomy=' . rawurlencode( $term->taxonomy ) . '&term=' . rawurlencode( $term->slug ) );
	}

	/**
	 * The address table (CSV): one column per site, one row per set, published members only.
	 *
	 * @param array<string, mixed> $data Logbook (build()).
	 * @return string
	 */
	public static function csv( array $data ): string {
		$sites   = is_array( $data['sites'] ?? null ) ? $data['sites'] : array();
		$columns = array();
		$header  = array( 'set', 'kind' );
		foreach ( $sites as $site ) {
			$columns[] = (int) $site['id'];
			$header[]  = $site['language'] . ' ' . $site['url'];
		}
		$rows   = array( $header );
		$number = 0;
		foreach ( is_array( $data['sets'] ?? null ) ? $data['sets'] : array() as $set ) {
			$cells     = array_fill_keys( $columns, '' );
			$published = 0;
			foreach ( $set['members'] as $member ) {
				if ( 'publish' === $member['status'] && '' !== $member['url'] && array_key_exists( $member['site'], $cells ) ) {
					$cells[ $member['site'] ] = $member['url'];
					++$published;
				}
			}
			if ( $published < 2 ) {
				continue;
			}
			$rows[] = array_merge( array( (string) ++$number, (string) $set['kind'] ), array_values( $cells ) );
		}
		$out = '';
		foreach ( $rows as $row ) {
			$out .= implode( ',', array_map( array( __CLASS__, 'csv_cell' ), $row ) ) . "\r\n";
		}
		return $out;
	}

	/**
	 * One CSV cell (RFC 4180 quoting; a leading =, +, - or @ is prefixed so spreadsheets never read it as a formula).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function csv_cell( $value ): string {
		$text = is_scalar( $value ) ? (string) $value : '';
		if ( '' !== $text && in_array( $text[0], array( '=', '+', '-', '@' ), true ) ) {
			$text = "'" . $text;
		}
		return '"' . str_replace( '"', '""', $text ) . '"';
	}
}
