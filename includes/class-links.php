<?php
/**
 * Translation links and language groups.
 *
 * Multisite Language Switcher (MSLS) stores a site's translation links per language, not per site: the option
 * `msls_<post ID>` (posts) or `msls_term_<term ID>` (terms) holds { locale: ID on the site of that language }. Which
 * site "that language" is depends on the site's group, so when a site joins, leaves or changes group, its links point
 * to whichever site has that language in the new group.
 *
 * - Before a group is saved, preview() says, for every site whose group changes, what its links will point to (read
 *   only, Sabir 0.1.0).
 * - audit() lists the links that no longer fit a site's group, with a reason each:
 *
 *   | Reason            | The link...                                                                     |
 *   |-------------------|---------------------------------------------------------------------------------|
 *   | `no-site`         | names a language no site of the group uses: it leads nowhere                     |
 *   | `missing`         | names a post or term that does not exist on the site of that language            |
 *   | `not-linked-back` | names a post or term that does not link back: it belongs to another translation (typically after a group change) |
 *   | `own-language`    | names the site's own language (MSLS never uses it)                               |
 *   | `invalid`         | holds something that is not a language and an ID                                 |
 *
 *   Only sites in a language group are checked: links of a site in no group are kept for when it joins one.
 *   remove() deletes listed links after checking again that each one is still listed (dry run first: audit()).
 * - link() writes a set of translations (Copy): each member's option gets every other member, merged with what it
 *   held, through MSLS's own option classes.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation links.
 *
 * @phpstan-type Problem array{site: int, kind: string, id: int, locale: string, target_site: int, target: int, reason: string}
 */
final class Links {

	public const POST = 'post';
	public const TERM = 'term';

	/** Reasons of audit(), in the order a screen lists them. */
	public const REASONS = array( 'no-site', 'missing', 'not-linked-back', 'own-language', 'invalid' );

	/**
	 * MSLS's option name for an object's links.
	 *
	 * @param string $kind post or term.
	 * @param int    $id   Post or term ID.
	 * @return string
	 */
	public static function option_name( string $kind, int $id ): string {
		return ( self::TERM === $kind ? 'msls_term_' : 'msls_' ) . $id;
	}

	/**
	 * An object's links on its site: locale => ID (only well-formed entries).
	 *
	 * @param int    $site Site ID.
	 * @param string $kind post or term.
	 * @param int    $id   Post or term ID.
	 * @return array<string, int>
	 */
	public static function read( int $site, string $kind, int $id ): array {
		$value = get_blog_option( $site, self::option_name( $kind, $id ) );
		$links = array();
		foreach ( is_array( $value ) ? $value : array() as $locale => $target ) {
			if ( is_string( $locale ) && '' !== $locale && (int) $target > 0 ) {
				$links[ $locale ] = (int) $target;
			}
		}
		return $links;
	}

	/**
	 * Replaces an object's links on its site, through MSLS's option classes (same option, same format, not
	 * autoloaded); no links deletes the option.
	 *
	 * @param int                $site  Site ID.
	 * @param string             $kind  post or term.
	 * @param int                $id    Post or term ID.
	 * @param array<string, int> $links Locale => ID.
	 * @return void
	 */
	public static function write( int $site, string $kind, int $id, array $links ): void {
		switch_to_blog( $site );
		try {
			$option = self::TERM === $kind ? new \lloc\Msls\Options\Tax\Term( $id ) : new \lloc\Msls\Options\Post\Post( $id );
			if ( array() === $links ) {
				$option->delete();
				delete_option( self::option_name( $kind, $id ) );
			} else {
				$option->save( $links );
			}
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * The sites of a group by language.
	 *
	 * @param int $group Group ID.
	 * @return array<string, int> Locale => site ID.
	 */
	public static function sites_by_locale( int $group ): array {
		$map = array();
		foreach ( Groups::sites( $group ) as $site ) {
			$map[ Groups::locale( $site ) ] = $site;
		}
		return $map;
	}

	/**
	 * Links a set of translations: each member gets every other member (merged with the links it holds; its own
	 * language is never stored).
	 *
	 * @param string                                   $kind    post or term.
	 * @param array<string, array{site: int, id: int}> $members Locale => member.
	 * @return void
	 */
	public static function link( string $kind, array $members ): void {
		foreach ( $members as $locale => $member ) {
			$links = self::read( $member['site'], $kind, $member['id'] );
			foreach ( $members as $other_locale => $other ) {
				if ( $other_locale !== $locale ) {
					$links[ $other_locale ] = $other['id'];
				}
			}
			unset( $links[ $locale ] );
			ksort( $links );
			self::write( $member['site'], $kind, $member['id'], $links );
		}
	}

	/**
	 * Every MSLS link option of a site: [ kind, object ID, raw value ].
	 *
	 * @param int $site Site ID.
	 * @return list<array{0: string, 1: int, 2: mixed}>
	 */
	private static function options_of( int $site ): array {
		global $wpdb;
		switch_to_blog( $site );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MSLS keeps one option per linked object; read in one query for the network admin.
			$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name REGEXP '^msls_(term_)?[0-9]+$'", ARRAY_A );
		} finally {
			restore_current_blog();
		}
		$options = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name      = (string) $row['option_name'];
			$kind      = str_starts_with( $name, 'msls_term_' ) ? self::TERM : self::POST;
			$options[] = array( $kind, (int) substr( $name, self::TERM === $kind ? 10 : 5 ), maybe_unserialize( (string) $row['option_value'] ) );
		}
		return $options;
	}

	/**
	 * Every MSLS link option of a site, cleaned: [ kind, object ID, locale => linked ID ], invalid entries left out.
	 * The one reader of MSLS's link storage besides the audit, which needs the raw values (options_of()).
	 *
	 * @param int $site Site ID.
	 * @return list<array{0: string, 1: int, 2: array<string, int>}>
	 */
	public static function links_of( int $site ): array {
		$all = array();
		foreach ( self::options_of( $site ) as list( $kind, $id, $value ) ) {
			$links = array();
			foreach ( is_array( $value ) ? $value : array() as $locale => $target ) {
				if ( is_string( $locale ) && '' !== $locale && is_scalar( $target ) && (int) $target > 0 ) {
					$links[ $locale ] = (int) $target;
				}
			}
			$all[] = array( $kind, $id, $links );
		}
		return $all;
	}

	/**
	 * What exists on a site, and the links those objects hold, for a list of post and term IDs.
	 *
	 * @param int   $site     Site ID.
	 * @param int[] $post_ids Post IDs.
	 * @param int[] $term_ids Term IDs.
	 * @return array{posts: array<int, true>, terms: array<int, true>, links: array<string, array<string, int>>}
	 */
	private static function lookup( int $site, array $post_ids, array $term_ids ): array {
		global $wpdb;
		$found = array(
			'posts' => array(),
			'terms' => array(),
			'links' => array(),
		);
		switch_to_blog( $site );
		try {
			foreach ( array_chunk( array_values( array_unique( $post_ids ) ), 500 ) as $chunk ) {
				$in = implode( ',', array_map( 'intval', $chunk ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integers only; one query per 500 links.
				foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($in) AND post_type NOT IN ('revision', 'nav_menu_item') AND post_status <> 'auto-draft'" ) as $id ) {
					$found['posts'][ (int) $id ] = true;
				}
			}
			foreach ( array_chunk( array_values( array_unique( $term_ids ) ), 500 ) as $chunk ) {
				$in = implode( ',', array_map( 'intval', $chunk ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Same.
				foreach ( (array) $wpdb->get_col( "SELECT term_id FROM {$wpdb->terms} WHERE term_id IN ($in)" ) as $id ) {
					$found['terms'][ (int) $id ] = true;
				}
			}
		} finally {
			restore_current_blog();
		}
		foreach ( self::links_of( $site ) as list( $kind, $id, $links ) ) {
			$found['links'][ $kind . ':' . $id ] = $links;
		}
		return $found;
	}

	/**
	 * Links that no longer fit their site's group (see the file comment).
	 *
	 * @param int $only_site Check this site only (0: every site in a group).
	 * @return list<array{site: int, kind: string, id: int, locale: string, target_site: int, target: int, reason: string}>
	 */
	public static function audit( int $only_site = 0 ): array {
		$problems = array();
		$pending  = array();
		foreach ( Groups::all() as $group ) {
			$by_locale = self::sites_by_locale( $group['id'] );
			foreach ( $by_locale as $own => $site ) {
				if ( $only_site > 0 && $site !== $only_site ) {
					continue;
				}
				foreach ( self::options_of( $site ) as $option ) {
					list( $kind, $id, $value ) = $option;
					if ( ! is_array( $value ) ) {
						continue;
					}
					foreach ( $value as $locale => $target ) {
						$problem = array(
							'site'        => $site,
							'kind'        => $kind,
							'id'          => $id,
							'locale'      => (string) $locale,
							'target_site' => 0,
							'target'      => is_scalar( $target ) ? (int) $target : 0,
							'reason'      => '',
						);
						if ( ! is_string( $locale ) || '' === $locale || $problem['target'] <= 0 ) {
							$problem['reason'] = 'invalid';
						} elseif ( $locale === $own ) {
							$problem['reason'] = 'own-language';
						} elseif ( ! isset( $by_locale[ $locale ] ) ) {
							$problem['reason'] = 'no-site';
						} else {
							$problem['target_site'] = $by_locale[ $locale ];
							$pending[]              = array( $problem, $own );
							continue;
						}
						$problems[] = $problem;
					}//end foreach
				}//end foreach
			}//end foreach
		}//end foreach

		// Look up every target site once.
		$wanted = array();
		foreach ( $pending as $item ) {
			$wanted[ $item[0]['target_site'] ][ $item[0]['kind'] ][] = $item[0]['target'];
		}
		$found = array();
		foreach ( $wanted as $site => $kinds ) {
			$found[ $site ] = self::lookup( $site, $kinds[ self::POST ] ?? array(), $kinds[ self::TERM ] ?? array() );
		}
		foreach ( $pending as $item ) {
			list( $problem, $own ) = $item;
			$there                 = $found[ $problem['target_site'] ];
			$exists                = self::TERM === $problem['kind'] ? isset( $there['terms'][ $problem['target'] ] ) : isset( $there['posts'][ $problem['target'] ] );
			if ( ! $exists ) {
				$problem['reason'] = 'missing';
			} elseif ( ( $there['links'][ $problem['kind'] . ':' . $problem['target'] ][ $own ] ?? 0 ) !== $problem['id'] ) {
				$problem['reason'] = 'not-linked-back';
			} else {
				continue;
			}
			$problems[] = $problem;
		}
		usort(
			$problems,
			static fn( array $a, array $b ): int => array( $a['site'], $a['kind'], $a['id'], $a['locale'] ) <=> array( $b['site'], $b['kind'], $b['id'], $b['locale'] )
		);
		return $problems;
	}

	/**
	 * Removes links listed by audit(). Each one is checked again first: a link that fits now is kept.
	 *
	 * @param list<array{site: int, kind: string, id: int, locale: string}> $items Links to remove.
	 * @return array{removed: int, kept: int}
	 */
	public static function remove( array $items ): array {
		$listed = array();
		$sites  = array_values( array_unique( array_map( static fn( array $item ): int => (int) $item['site'], $items ) ) );
		foreach ( $sites as $site ) {
			foreach ( self::audit( $site ) as $problem ) {
				$listed[ self::key( $problem ) ] = true;
			}
		}
		$removed = 0;
		$kept    = 0;
		$changes = array();
		foreach ( $items as $item ) {
			if ( isset( $listed[ self::key( $item ) ] ) ) {
				$changes[ $item['site'] . '|' . $item['kind'] . '|' . $item['id'] ][] = $item['locale'];
			} else {
				++$kept;
			}
		}
		foreach ( $changes as $object => $locales ) {
			list( $site, $kind, $id ) = explode( '|', $object );
			$value                    = get_blog_option( (int) $site, self::option_name( $kind, (int) $id ) );
			$value                    = is_array( $value ) ? $value : array();
			foreach ( array_unique( $locales ) as $locale ) {
				if ( array_key_exists( $locale, $value ) ) {
					unset( $value[ $locale ] );
					++$removed;
				}
			}
			$links = array();
			foreach ( $value as $locale => $target ) {
				if ( is_string( $locale ) && '' !== $locale && is_scalar( $target ) && (int) $target > 0 ) {
					$links[ $locale ] = (int) $target;
				}
			}
			self::write( (int) $site, $kind, (int) $id, $links );
		}
		return array(
			'removed' => $removed,
			'kept'    => $kept,
		);
	}

	/**
	 * Identifies one link.
	 *
	 * @param array{site: int, kind: string, id: int, locale: string} $item Link.
	 * @return string
	 */
	private static function key( array $item ): string {
		return $item['site'] . '|' . $item['kind'] . '|' . $item['id'] . '|' . $item['locale'];
	}

	/**
	 * A site's translation links: locale => number of links, without links to the site's own language.
	 *
	 * @param int $blog_id Site ID.
	 * @return array<string, int>
	 */
	public static function of_site( int $blog_id ): array {
		$own    = Groups::locale( $blog_id );
		$counts = array();
		foreach ( self::links_of( $blog_id ) as list( , , $links ) ) {
			foreach ( array_keys( $links ) as $locale ) {
				if ( $locale !== $own ) {
					$counts[ $locale ] = ( $counts[ $locale ] ?? 0 ) + 1;
				}
			}
		}
		ksort( $counts );
		return $counts;
	}

	/**
	 * What saving a group would do to translation links, in sentences: for each site that joins (or moves into) the
	 * group and holds links, where each language will point; for each site that leaves it, that its links stay unseen.
	 *
	 * @param int        $id       Group ID, 0 for a new group.
	 * @param array<int> $site_ids The group's sites after saving.
	 * @return list<string>
	 */
	public static function preview( int $id, array $site_ids ): array {
		$site_ids = array_values( array_unique( array_filter( array_map( 'intval', $site_ids ), static fn( int $site ): bool => $site > 0 ) ) );
		$group    = Groups::get( $id );
		$label    = null === $group ? __( 'this language group', 'windrose-sabir' ) : sprintf(
			/* translators: %s: group name. */
			__( '“%s”', 'windrose-sabir' ),
			$group['label']
		);
		$by_locale = array();
		foreach ( $site_ids as $site ) {
			$by_locale[ Groups::locale( $site ) ] = $site;
		}
		$messages = array();
		foreach ( $site_ids as $site ) {
			if ( $id > 0 && Groups::of_site( $site ) === $id ) {
				continue;
			}
			foreach ( self::of_site( $site ) as $locale => $count ) {
				$target     = $by_locale[ $locale ] ?? 0;
				$language   = Languages::name( $locale );
				$messages[] = $target > 0
					? sprintf(
						/* translators: 1: site name and address, 2: number of links, 3: language name, 4: group name, 5: site name and address. */
						_n(
							'%1$s has %2$d translation link to %3$s. In %4$s, %3$s is %5$s: the link will point to that site.',
							'%1$s has %2$d translation links to %3$s. In %4$s, %3$s is %5$s: the links will point to that site.',
							$count,
							'windrose-sabir'
						),
						Groups::site_label( $site ),
						$count,
						$language,
						$label,
						Groups::site_label( $target )
					)
					: sprintf(
						/* translators: 1: site name and address, 2: number of links, 3: language name, 4: group name. */
						_n(
							'%1$s has %2$d translation link to %3$s. No site of %4$s uses %3$s, so the link will lead nowhere.',
							'%1$s has %2$d translation links to %3$s. No site of %4$s uses %3$s, so the links will lead nowhere.',
							$count,
							'windrose-sabir'
						),
						Groups::site_label( $site ),
						$count,
						$language,
						$label
					);
			}//end foreach
		}//end foreach
		if ( $id > 0 ) {
			foreach ( array_diff( Groups::sites( $id ), $site_ids ) as $site ) {
				$total = array_sum( self::of_site( $site ) );
				if ( $total > 0 ) {
					$messages[] = sprintf(
						/* translators: 1: site name and address, 2: number of links. */
						_n(
							'%1$s leaves the group: its %2$d translation link is kept, but not shown while the site is in no language group.',
							'%1$s leaves the group: its %2$d translation links are kept, but not shown while the site is in no language group.',
							$total,
							'windrose-sabir'
						),
						Groups::site_label( $site ),
						$total
					);
				}
			}
		}
		return $messages;
	}
}
