<?php
/**
 * Site Health tests of Windrose Sabir (Tools, Site Health on each site).
 *
 * `windrose_sabir_language_group`: is the site in a language group, and does that match its Multisite Language
 * Switcher (MSLS) settings?
 *
 * | Situation                                                        | Status      |
 * |------------------------------------------------------------------|-------------|
 * | The network has no language group                                 | recommended |
 * | In a group, MSLS set up here                                      | good        |
 * | In a group, MSLS not set up or "Exclude this blog from output"    | recommended |
 * | In no group, MSLS set up here (its settings have no effect)       | recommended |
 * | In no group, MSLS not set up or excluded (not a language site)    | good        |
 *
 * On a site in a group, also:
 *
 * - `windrose_sabir_language_item` (Site Editor module, block themes): the header template part (patterns and
 *   template parts inside it followed) holds a language item, directly or in its Navigation block (the menu it
 *   references, or the menu core falls back to: the most recently published one). Recommended otherwise.
 * - `windrose_sabir_translation_links`: translation links of this site that no longer fit its group (to missing
 *   posts, not linked back, after a group change), from Links::audit(). Recommended when there are some.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Health test.
 *
 * @phpstan-type Result array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string}
 */
final class Health {

	public const TEST       = 'windrose_sabir_language_group';
	public const ITEM_TEST  = 'windrose_sabir_language_item';
	public const LINKS_TEST = 'windrose_sabir_translation_links';

	/** How deep patterns and template parts are followed. */
	private const MAX_DEPTH = 5;

	/**
	 * Hooks the test.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'site_status_tests', array( __CLASS__, 'register' ) );
	}

	/**
	 * Adds the test (`site_status_tests`).
	 *
	 * @param mixed $tests Tests by type (direct, async).
	 * @return mixed
	 */
	public static function register( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		$direct               = is_array( $tests['direct'] ?? null ) ? $tests['direct'] : array();
		$direct[ self::TEST ] = array(
			'label' => __( 'Language group', 'windrose-sabir' ),
			'test'  => array( __CLASS__, 'test' ),
		);
		if ( 0 !== Groups::of_site( get_current_blog_id() ) ) {
			if ( Modules::on( 'site_editor' ) && wp_is_block_theme() ) {
				$direct[ self::ITEM_TEST ] = array(
					'label' => __( 'Language item in the header', 'windrose-sabir' ),
					'test'  => array( __CLASS__, 'language_item_test' ),
				);
			}
			$direct[ self::LINKS_TEST ] = array(
				'label' => __( 'Translation links', 'windrose-sabir' ),
				'test'  => array( __CLASS__, 'links_test' ),
			);
		}
		$tests['direct'] = $direct;
		return $tests;
	}

	/**
	 * Runs the test for the current site.
	 *
	 * @return array<string, mixed>
	 * @phpstan-return Result
	 */
	public static function test(): array {
		$site_id = get_current_blog_id();
		$msls    = get_option( 'msls' );
		$set_up  = is_array( $msls ) && empty( $msls['exclude_current_blog'] );

		if ( ! Groups::exist() ) {
			return self::result(
				'recommended',
				__( 'No language group is set up yet', 'windrose-sabir' ),
				__( 'Windrose Sabir is active, but the network has no language group. Multisite Language Switcher links the sites of its reference user, as without Windrose Sabir. A language group says which sites are translations of each other; sites outside every group then show no language switcher.', 'windrose-sabir' )
			);
		}

		$group = Groups::get( Groups::of_site( $site_id ) );
		// One site per language: checked when the group is saved, but a site's language can change afterwards.
		$locale = Groups::locale( $site_id );
		$twin   = null === $group ? null : array_find( Groups::sites( $group['id'] ), static fn( int $other ): bool => $other !== $site_id && Groups::locale( $other ) === $locale );
		if ( null !== $twin ) {
			return self::result(
				'critical',
				/* translators: 1: other site's name and address, 2: locale code (fr_FR). */
				sprintf( __( '%1$s uses the same language as this site (%2$s)', 'windrose-sabir' ), Groups::site_label( $twin ), $locale ),
				__( 'A language group holds one site per language: translation links and Copy cannot tell the two sites apart. Change the language of one of them (Settings, General, Site Language), or move one to another group (Network Admin, Sites, Language groups).', 'windrose-sabir' )
			);
		}
		if ( null !== $group && $set_up ) {
			return self::result(
				'good',
				/* translators: %s: group name. */
				sprintf( __( 'This site is in the language group “%s”', 'windrose-sabir' ), $group['label'] ),
				Msls_Settings::summary()
			);
		}
		if ( null !== $group ) {
			return self::result(
				'recommended',
				/* translators: %s: group name. */
				sprintf( __( 'This site is in the language group “%s”, but Multisite Language Switcher is not set up here', 'windrose-sabir' ), $group['label'] ),
				__( 'Other sites of the group do not link to this site until its language is saved in Settings, Multisite Language Switcher (and “Exclude this blog from output” is not ticked).', 'windrose-sabir' ),
				true
			);
		}
		if ( $set_up ) {
			return self::result(
				'recommended',
				__( 'This site is in no language group', 'windrose-sabir' ),
				__( 'Multisite Language Switcher is set up on this site, but its settings have no effect while the site is in no language group: no language switcher, no translation links. If this site is a translation of other sites, add it to their language group. If it is not a language site, tick “Exclude this blog from output” in Settings, Multisite Language Switcher.', 'windrose-sabir' ),
				true
			);
		}
		return self::result(
			'good',
			__( 'This site is not a language site', 'windrose-sabir' ),
			__( 'This site is in no language group and Multisite Language Switcher is not used here, so it shows no language switcher.', 'windrose-sabir' )
		);
	}

	/**
	 * A test result.
	 *
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Heading.
	 * @param string $description Explanation (plain text).
	 * @param bool   $msls_link   Also link to MSLS's settings page.
	 * @param string $test        Test name.
	 * @param string $actions_html Other actions (HTML, escaped), instead of the language groups link.
	 * @return array<string, mixed>
	 * @phpstan-return Result
	 */
	private static function result( string $status, string $label, string $description, bool $msls_link = false, string $test = self::TEST, string $actions_html = '' ): array {
		$actions = array();
		if ( $msls_link && current_user_can( 'manage_options' ) ) {
			$actions[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( admin_url( 'options-general.php?page=' . Msls_Settings::PAGE ) ), esc_html__( 'Multisite Language Switcher settings', 'windrose-sabir' ) );
		}
		if ( '' !== $actions_html ) {
			$actions[] = $actions_html;
		} elseif ( current_user_can( 'manage_network_options' ) ) {
			$actions[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( Admin::url() ), esc_html__( 'Language groups', 'windrose-sabir' ) );
		}
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Languages', 'windrose-sabir' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => array() === $actions ? '' : '<p>' . implode( ' | ', $actions ) . '</p>',
			'test'        => $test,
		);
	}

	/**
	 * Is there a language item in the header?
	 *
	 * @return array<string, mixed>
	 * @phpstan-return Result
	 */
	public static function language_item_test(): array {
		$part   = get_block_template( get_stylesheet() . '//header', 'wp_template_part' );
		$edit   = '';
		$action = '';
		if ( current_user_can( 'edit_theme_options' ) ) {
			$edit   = admin_url( 'site-editor.php?postType=wp_template_part&postId=' . rawurlencode( get_stylesheet() . '//header' ) . '&canvas=edit' );
			$action = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $edit ), esc_html__( 'Edit the header', 'windrose-sabir' ) );
		}
		if ( null === $part || '' === trim( (string) $part->content ) ) {
			return self::result(
				'recommended',
				__( 'The theme has no header template part', 'windrose-sabir' ),
				__( 'Windrose Sabir looks for its language item in the header template part, and this theme has none. Add a Language item block where visitors should find the other languages.', 'windrose-sabir' ),
				false,
				self::ITEM_TEST
			);
		}
		$header = (string) $part->content;
		if ( self::contains( $header, Language_Item::NAME ) ) {
			return self::result(
				'good',
				__( 'The header has a language item', 'windrose-sabir' ),
				__( 'Visitors find the other languages of this page in the header.', 'windrose-sabir' ),
				false,
				self::ITEM_TEST,
				$action
			);
		}
		if ( ! self::contains( $header, 'core/navigation' ) ) {
			return self::result(
				'recommended',
				__( 'The header has no Navigation block', 'windrose-sabir' ),
				__( 'Add a Navigation block to the header, with a Language item in it, so visitors find the other languages of each page.', 'windrose-sabir' ),
				false,
				self::ITEM_TEST,
				$action
			);
		}
		return self::result(
			'recommended',
			__( 'The header’s navigation has no language item', 'windrose-sabir' ),
			__( 'Add a Language item block to the Navigation block of the header, so visitors find the other languages of each page.', 'windrose-sabir' ),
			false,
			self::ITEM_TEST,
			$action
		);
	}

	/**
	 * Whether block content holds a block, following patterns, template parts and navigation menus. Scans with
	 * core's block processor: no block tree is built (except for a Navigation block's own items), only the
	 * attributes of the blocks it follows are read.
	 *
	 * @param string $content Block content.
	 * @param string $name    Block name.
	 * @param int    $depth   Depth of patterns, parts and menus followed.
	 * @return bool
	 */
	private static function contains( string $content, string $name, int $depth = 0 ): bool {
		$blocks = new \WP_Block_Processor( $content );
		while ( $blocks->next_block() ) {
			if ( $blocks->is_block_type( $name ) ) {
				return true;
			}
			if ( $depth >= self::MAX_DEPTH ) {
				continue;
			}
			$more = '';
			if ( $blocks->is_block_type( 'core/pattern' ) ) {
				$attrs   = $blocks->allocate_and_return_parsed_attributes() ?? array();
				$pattern = is_string( $attrs['slug'] ?? null ) ? \WP_Block_Patterns_Registry::get_instance()->get_registered( $attrs['slug'] ) : null;
				$more    = is_array( $pattern ) && is_string( $pattern['content'] ?? null ) ? $pattern['content'] : '';
			} elseif ( $blocks->is_block_type( 'core/template-part' ) ) {
				$attrs = $blocks->allocate_and_return_parsed_attributes() ?? array();
				$theme = is_string( $attrs['theme'] ?? null ) ? $attrs['theme'] : get_stylesheet();
				$part  = is_string( $attrs['slug'] ?? null ) ? get_block_template( $theme . '//' . $attrs['slug'], 'wp_template_part' ) : null;
				$more  = null === $part ? '' : (string) $part->content;
			} elseif ( $blocks->is_block_type( 'core/navigation' ) && Language_Item::NAME === $name ) {
				// The block's own items when it has some, otherwise its menu. Extracting leaves the processor on the
				// block's last delimiter, so next_block() goes on after it.
				$navigation = $blocks->extract_full_block_and_advance();
				$inner      = is_array( $navigation['innerBlocks'] ?? null ) ? $navigation['innerBlocks'] : array();
				if ( array() !== $inner ) {
					// @phpstan-ignore argument.type (extract_full_block_and_advance() gives parse_blocks()'s shape.)
					if ( self::contains( serialize_blocks( $inner ), $name, $depth ) ) {
						return true;
					}
					continue;
				}
				$attrs = is_array( $navigation['attrs'] ?? null ) ? $navigation['attrs'] : array();
				$more  = self::navigation_menu( (int) ( $attrs['ref'] ?? 0 ) );
			}//end if
			if ( '' !== $more && self::contains( $more, $name, $depth + 1 ) ) {
				return true;
			}
		}//end while
		return false;
	}

	/**
	 * The content of a Navigation block's menu: its `ref`, or the menu core falls back to without one (the most
	 * recently published, as `WP_Navigation_Fallback` picks it; never created here).
	 *
	 * @param int $ref Menu ID, 0 for none.
	 * @return string
	 */
	private static function navigation_menu( int $ref ): string {
		if ( $ref > 0 ) {
			$menu = get_post( $ref );
			return $menu instanceof \WP_Post && 'wp_navigation' === $menu->post_type ? $menu->post_content : '';
		}
		$menus = get_posts(
			array(
				'post_type'     => 'wp_navigation',
				'post_status'   => 'publish',
				'numberposts'   => 1,
				'order'         => 'DESC',
				'orderby'       => 'date',
				'no_found_rows' => true,
			)
		);
		return array() === $menus ? '' : $menus[0]->post_content;
	}

	/**
	 * Translation links of this site that no longer fit its group.
	 *
	 * @return array<string, mixed>
	 * @phpstan-return Result
	 */
	public static function links_test(): array {
		$problems = Links::audit( get_current_blog_id() );
		$action   = '';
		if ( current_user_can( 'manage_network_options' ) ) {
			$action = sprintf( '<a href="%1$s">%2$s</a>', esc_url( Admin::url( Admin::LINKS ) ), esc_html__( 'Check translation links', 'windrose-sabir' ) );
		}
		if ( array() === $problems ) {
			return self::result(
				'good',
				__( 'Translation links fit the language group', 'windrose-sabir' ),
				__( 'Every translation link of this site leads to a post or term of its language group that links back.', 'windrose-sabir' ),
				false,
				self::LINKS_TEST,
				$action
			);
		}
		return self::result(
			'recommended',
			sprintf(
				/* translators: %d: number of links. */
				_n( '%d translation link no longer fits the language group', '%d translation links no longer fit the language group', count( $problems ), 'windrose-sabir' ),
				count( $problems )
			),
			__( 'Some translation links of this site lead to posts or terms that no longer exist, that do not link back, or to languages no site of the group uses (for example after the site changed language group). Visitors may land on the wrong page. A network administrator can review and remove them in Network Admin, Windrose Sabir, Translation links.', 'windrose-sabir' ),
			false,
			self::LINKS_TEST,
			$action
		);
	}
}
