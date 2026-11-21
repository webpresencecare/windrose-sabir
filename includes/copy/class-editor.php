<?php
/**
 * Copy in the block editor: the "Languages" panel (src/editor) and its REST routes.
 *
 * | Route                         | Method | Does                                                                     |
 * |-------------------------------|--------|--------------------------------------------------------------------------|
 * | /copy/<post>                  | GET    | The other sites of the group: translation (if any), whether the person may copy there and why not, what would happen to the media |
 * | /copy/<post>                  | POST   | Copies the post to `sites` (IDs) as linked drafts; `images`: `network` (default) or `copy`; one result per site |
 *
 * Permission: the Copy module is on and the person may read the post on this site; each site is checked again in
 * Copier::check() (rights on that site).
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir\Copy;

use Windrose\Sabir\Groups;
use Windrose\Sabir\Modules;
use Windrose\Sabir\Rest;
use Windrose\Sabir\Site_Settings;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Editor panel and routes.
 */
final class Editor {

	public const HANDLE = 'windrose-sabir-editor';

	/**
	 * Hooks the routes and the editor script.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			Rest::NAMESPACE,
			'/copy/(?P<post>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_targets' ),
					'permission_callback' => array( __CLASS__, 'can_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'copy' ),
					'permission_callback' => array( __CLASS__, 'can_read' ),
					'args'                => array(
						'sites'  => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'required' => true,
							'minItems' => 1,
						),
						'images' => array(
							'type'    => 'string',
							'enum'    => array( 'network', 'copy' ),
							'default' => 'network',
						),
					),
				),
			)
		);
	}

	/**
	 * Copy is on and the person may read the post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function can_read( WP_REST_Request $request ): bool|WP_Error {
		if ( ! Modules::on( 'copy' ) ) {
			return new WP_Error( 'windrose_sabir_copy_off', __( 'Copy is switched off on this network.', 'windrose-sabir' ), array( 'status' => 403 ) );
		}
		$post = get_post( (int) $request['post'] );
		return $post instanceof WP_Post && current_user_can( 'read_post', $post->ID );
	}

	/**
	 * GET /copy/<post>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_targets( WP_REST_Request $request ): WP_REST_Response {
		$post = get_post( (int) $request['post'] );
		return new WP_REST_Response(
			array(
				'targets' => Copier::targets( $post instanceof WP_Post ? $post : null ),
				'anchors' => Network_Media::available(),
			)
		);
	}

	/**
	 * POST /copy/<post>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function copy( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['post'];
		$sites   = $request->get_param( 'sites' );
		$images  = 'copy' === $request->get_param( 'images' ) ? 'copy' : 'network';
		$results = array();
		foreach ( array_unique( array_map( 'intval', is_array( $sites ) ? $sites : array() ) ) as $site ) {
			$result = Copier::copy( $post_id, $site, $images );
			if ( $result instanceof WP_Error ) {
				$results[] = array(
					'site'  => $site,
					'ok'    => false,
					'error' => $result->get_error_message(),
					'code'  => $result->get_error_code(),
				);
			} else {
				$results[] = array( 'ok' => true ) + $result;
			}
		}
		$post = get_post( $post_id );
		return new WP_REST_Response(
			array(
				'results' => $results,
				'targets' => Copier::targets( $post instanceof WP_Post ? $post : null ),
				'anchors' => Network_Media::available(),
			)
		);
	}

	/**
	 * Loads the panel in the post editor, where Copy can be used: module on, the site in a group, the post type
	 * copied from this site.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'post' !== $screen->base || ! Modules::on( 'copy' ) || 0 === Groups::of_site( get_current_blog_id() ) ) {
			return;
		}
		if ( ! in_array( (string) $screen->post_type, Site_Settings::copy_post_types(), true ) ) {
			return;
		}
		$asset_file = WINDROSE_SABIR_DIR . '/build/editor.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$deps  = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_SABIR_VERSION;
		wp_enqueue_script( self::HANDLE, plugins_url( 'build/editor.js', WINDROSE_SABIR_FILE ), $deps, $ver, array( 'in_footer' => true ) );
		wp_set_script_translations( self::HANDLE, 'windrose-sabir', WINDROSE_SABIR_DIR . '/languages' );
		wp_add_inline_script( self::HANDLE, 'window.windroseSabirEditor = ' . wp_json_encode( array( 'rest' => '/' . Rest::NAMESPACE . '/copy' ) ) . ';', 'before' );
		wp_enqueue_style( self::HANDLE, plugins_url( 'build/editor.css', WINDROSE_SABIR_FILE ), array( 'wp-components' ), $ver );
	}
}
