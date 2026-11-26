<?php
/**
 * REST routes (namespace windrose-sabir/v1), for the network admin screen. Network administrators only
 * (`manage_network_options`); changes apply to the current network.
 *
 * | Route           | Method | Does                                                                        |
 * |-----------------|--------|-----------------------------------------------------------------------------|
 * | /groups         | GET    | The groups with their sites, and every site of the network (language, MSLS state, group) |
 * | /groups         | POST   | Creates a group: `label`, `sites` (IDs), `main` (optional main site)          |
 * | /groups/<id>    | POST, PUT | Changes a group's name, sites and main site                              |
 * | /groups/<id>    | DELETE | Deletes a group (its sites are then in no group)                             |
 * | /groups/preview | POST   | What saving `sites` for group `id` would do to translation links, read only       |
 * | /settings       | GET, POST | Module switches (`modules`) and the language display (`display`)           |
 * | /links          | GET    | Translation links that no longer fit their group, with names, dry run          |
 * | /links/remove   | POST   | Removes the listed links (`items`: site, kind, id, locale), each checked again  |
 * | /logbook        | GET    | What the logbook export holds (counts) and the download addresses              |
 *
 * Copy's routes (/copy/<post>) are in Copy\Editor.
 *
 * Every write answers with the same body as GET, so the screen shows what was saved.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Site;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes.
 *
 * @phpstan-type SiteRow array{id: int, name: string, address: string, url: string, settings: string, locale: string, language: string, msls: string, status: string, group: int}
 */
final class Rest {

	public const NAMESPACE = 'windrose-sabir/v1';

	/**
	 * Hooks the routes.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$group_args = array(
			'label' => array(
				'type'     => 'string',
				'required' => true,
			),
			'sites' => array(
				'type'     => 'array',
				'items'    => array( 'type' => 'integer' ),
				'required' => true,
			),
			'main'  => array(
				'type'    => 'integer',
				'default' => 0,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/groups',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_groups' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_group' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => $group_args,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/groups/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'preview_group' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'id'    => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'sites' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_settings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'modules' => array(
							'type'                 => 'object',
							'properties'           => array_fill_keys( Modules::MODULES, array( 'type' => 'boolean' ) ),
							'additionalProperties' => false,
						),
						'display' => array(
							'type' => 'string',
							'enum' => Modules::DISPLAYS,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/links',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_links' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/links/remove',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'remove_links' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'site'   => array(
									'type'     => 'integer',
									'required' => true,
								),
								'kind'   => array(
									'type'     => 'string',
									'enum'     => array( Links::POST, Links::TERM ),
									'required' => true,
								),
								'id'     => array(
									'type'     => 'integer',
									'required' => true,
								),
								'locale' => array(
									'type'     => 'string',
									'required' => true,
								),
							),
							'additionalProperties' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/logbook',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_logbook' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/groups/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_group' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => $group_args,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_group' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * Network administrators only.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_network_options' );
	}

	/**
	 * GET /groups.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_groups(): WP_REST_Response {
		return new WP_REST_Response( self::state() );
	}

	/**
	 * POST /groups.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_group( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::save( 0, $request );
	}

	/**
	 * POST or PUT /groups/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_group( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::save( (int) $request->get_param( 'id' ), $request );
	}

	/**
	 * POST /groups/preview: what saving a group would do to translation links (read only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function preview_group( WP_REST_Request $request ): WP_REST_Response {
		$sites = $request->get_param( 'sites' );
		return new WP_REST_Response( array( 'messages' => Links::preview( (int) $request->get_param( 'id' ), is_array( $sites ) ? array_map( 'intval', $sites ) : array() ) ) );
	}

	/**
	 * DELETE /groups/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_group( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = Groups::delete( (int) $request->get_param( 'id' ) );
		return $result instanceof WP_Error ? $result : new WP_REST_Response( self::state() );
	}

	/**
	 * Creates or changes a group from a request.
	 *
	 * @param int             $id      Group ID, 0 for a new one.
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function save( int $id, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$label = $request->get_param( 'label' );
		$sites = $request->get_param( 'sites' );
		$saved = Groups::save( $id, is_string( $label ) ? $label : '', is_array( $sites ) ? array_map( 'intval', $sites ) : array(), (int) $request->get_param( 'main' ) );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}
		$state          = self::state();
		$state['saved'] = $saved;
		return new WP_REST_Response( $state, 0 === $id ? 201 : 200 );
	}

	/**
	 * The groups and the network's sites.
	 *
	 * @return array{groups: list<array{id: int, label: string, main: int, sites: list<int>}>, sites: list<array<string, mixed>>}
	 * @phpstan-return array{groups: list<array{id: int, label: string, main: int, sites: list<int>}>, sites: list<SiteRow>}
	 */
	public static function state(): array {
		$groups = array();
		foreach ( Groups::all() as $group ) {
			$group['sites'] = Groups::sites( $group['id'] );
			$groups[]       = $group;
		}
		$sites = array();
		$all   = get_sites(
			array(
				'network_id' => get_current_network_id(),
				'number'     => 0,
				'deleted'    => 0,
				'orderby'    => 'id',
				'order'      => 'ASC',
			)
		);
		foreach ( is_array( $all ) ? $all : array() as $site ) {
			if ( $site instanceof WP_Site ) {
				$sites[] = self::site_row( $site );
			}
		}
		return array(
			'groups' => $groups,
			'sites'  => $sites,
		);
	}

	/**
	 * One site for the screen.
	 *
	 * @param WP_Site $site Site.
	 * @return array<string, mixed>
	 * @phpstan-return SiteRow
	 */
	private static function site_row( WP_Site $site ): array {
		$id     = (int) $site->blog_id;
		$locale = Groups::locale( $id );
		$msls   = get_blog_option( $id, 'msls' );
		$state  = 'missing';
		if ( is_array( $msls ) ) {
			$state = empty( $msls['exclude_current_blog'] ) ? 'configured' : 'excluded';
		}
		$status = 'public';
		if ( '1' === (string) $site->archived ) {
			$status = 'archived';
		} elseif ( '1' === (string) $site->spam ) {
			$status = 'spam';
		}
		return array(
			'id'       => $id,
			'name'     => (string) $site->blogname,
			'address'  => untrailingslashit( $site->domain . $site->path ),
			'url'      => (string) $site->home,
			'settings' => get_admin_url( $id, 'options-general.php?page=' . Msls_Settings::PAGE ),
			'locale'   => $locale,
			'language' => Languages::name( $locale ),
			'msls'     => $state,
			'status'   => $status,
			'group'    => Groups::of_site( $id ),
		);
	}

	/**
	 * GET /settings.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'modules' => Modules::all(),
				'display' => Modules::display(),
			)
		);
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$modules = $request->get_param( 'modules' );
		if ( is_array( $modules ) ) {
			Modules::save( $modules );
		}
		$display = $request->get_param( 'display' );
		if ( is_string( $display ) ) {
			Modules::save_display( $display );
		}
		return self::get_settings();
	}

	/**
	 * GET /links: the dry run.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_links(): WP_REST_Response {
		return new WP_REST_Response( self::links_state() );
	}

	/**
	 * POST /links/remove.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function remove_links( WP_REST_Request $request ): WP_REST_Response {
		$items = array();
		$given = $request->get_param( 'items' );
		foreach ( is_array( $given ) ? $given : array() as $item ) {
			if ( is_array( $item ) ) {
				$items[] = array(
					'site'   => (int) ( $item['site'] ?? 0 ),
					'kind'   => Links::TERM === ( $item['kind'] ?? '' ) ? Links::TERM : Links::POST,
					'id'     => (int) ( $item['id'] ?? 0 ),
					'locale' => (string) ( $item['locale'] ?? '' ),
				);
			}
		}
		$result = Links::remove( $items );
		return new WP_REST_Response( $result + self::links_state() );
	}

	/**
	 * Links that no longer fit, with names for the screen.
	 *
	 * @return array{groups: bool, items: list<array<string, mixed>>}
	 */
	private static function links_state(): array {
		$problems = Links::audit();
		$names    = self::object_names( $problems );
		$reasons  = array(
			'no-site'         => __( 'No site of the group uses this language', 'windrose-sabir' ),
			'missing'         => __( 'The post or term no longer exists there', 'windrose-sabir' ),
			'not-linked-back' => __( 'It does not link back: it belongs to another translation', 'windrose-sabir' ),
			'own-language'    => __( 'It names the site’s own language', 'windrose-sabir' ),
			'invalid'         => __( 'Not a language and an ID', 'windrose-sabir' ),
		);
		$items    = array();
		foreach ( $problems as $problem ) {
			$name    = $names[ $problem['site'] ][ $problem['kind'] ][ $problem['id'] ] ?? array(
				'title' => '',
				'edit'  => '',
			);
			$items[] = $problem + array(
				'key'        => implode( '|', array( $problem['site'], $problem['kind'], $problem['id'], $problem['locale'] ) ),
				'siteName'   => Groups::site_label( $problem['site'] ),
				'title'      => '' !== $name['title'] ? $name['title'] : sprintf(
					/* translators: %d: post or term ID. */
					__( '(no title, ID %d)', 'windrose-sabir' ),
					$problem['id']
				),
				'edit'       => $name['edit'],
				'language'   => '' === $problem['locale'] ? '' : Languages::name( $problem['locale'] ),
				'targetName' => $problem['target_site'] > 0 ? Groups::site_label( $problem['target_site'] ) : '',
				'reasonText' => $reasons[ $problem['reason'] ] ?? $problem['reason'],
			);
		}
		return array(
			'groups' => Groups::exist(),
			'items'  => $items,
		);
	}

	/**
	 * Titles and edit addresses of the posts and terms holding links, read once per site.
	 *
	 * @param list<array{site: int, kind: string, id: int}> $problems Links.
	 * @return array<int, array<string, array<int, array{title: string, edit: string}>>>
	 */
	private static function object_names( array $problems ): array {
		$wanted = array();
		foreach ( $problems as $problem ) {
			$wanted[ $problem['site'] ][ $problem['kind'] ][] = $problem['id'];
		}
		$names = array();
		foreach ( $wanted as $site => $kinds ) {
			switch_to_blog( $site );
			try {
				// Any post or term of that site, whatever its type, taxonomy or status (trash included): types and
				// taxonomies registered only on that site are unknown here, so no query by type. One query each.
				$post_ids = array_map( 'intval', array_values( array_unique( $kinds[ Links::POST ] ?? array() ) ) );
				_prime_post_caches( $post_ids, false, false );
				foreach ( $post_ids as $post_id ) {
					$post = get_post( $post_id );
					if ( $post instanceof WP_Post ) {
						$names[ $site ][ Links::POST ][ $post->ID ] = array(
							'title' => $post->post_title,
							// A post in the trash cannot be edited: its title stays plain text.
							'edit'  => 'trash' === $post->post_status ? '' : (string) get_edit_post_link( $post->ID, 'raw' ),
						);
					}
				}
				$term_ids = array_map( 'intval', array_values( array_unique( $kinds[ Links::TERM ] ?? array() ) ) );
				_prime_term_caches( $term_ids, false );
				foreach ( $term_ids as $term_id ) {
					$term = get_term( $term_id );
					if ( $term instanceof WP_Term ) {
						$names[ $site ][ Links::TERM ][ $term_id ] = array(
							'title' => $term->name,
							'edit'  => (string) get_edit_term_link( $term ),
						);
					}
				}
			} finally {
				restore_current_blog();
			}//end try
		}//end foreach
		return $names;
	}

	/**
	 * GET /logbook: counts and download addresses.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_logbook(): WP_REST_Response|WP_Error {
		if ( ! Modules::on( 'logbook' ) ) {
			return new WP_Error( 'windrose_sabir_logbook_off', __( 'The Logbook module is switched off.', 'windrose-sabir' ), array( 'status' => 403 ) );
		}
		$data  = Logbook::build();
		$posts = count( array_filter( $data['sets'], static fn( array $set ): bool => Links::POST === $set['kind'] ) );
		return new WP_REST_Response(
			array(
				'sites' => count( $data['sites'] ),
				'posts' => $posts,
				'terms' => count( $data['sets'] ) - $posts,
				'json'  => Logbook::download_url( 'json' ),
				'csv'   => Logbook::download_url( 'csv' ),
			)
		);
	}
}
