<?php
/**
 * The network admin screens of Windrose Sabir (React, src/network, built into build/), Network Admin, Windrose Sabir:
 *
 * | Screen            | Page slug                  | Does                                                      |
 * |-------------------|----------------------------|-----------------------------------------------------------|
 * | Language groups   | `windrose-sabir`           | Groups and their main sites                               |
 * | Translation links | `windrose-sabir-links`     | Links that no longer fit their group, removal             |
 * | Logbook           | `windrose-sabir-logbook`   | Export (Logbook module)                                    |
 * | Modules           | `windrose-sabir-modules`   | Module switches and the language display                  |
 *
 * Translation providers join in a later version.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Network admin screen.
 */
final class Admin {

	public const SLUG    = 'windrose-sabir';
	public const LINKS   = 'windrose-sabir-links';
	public const LOGBOOK = 'windrose-sabir-logbook';
	public const MODULES = 'windrose-sabir-modules';
	public const HANDLE  = 'windrose-sabir-network';

	/**
	 * Hook suffixes of the screens: hook suffix => screen name.
	 *
	 * @var array<string, string>
	 */
	private static array $hooks = array();

	/**
	 * Hooks the menu, assets and the Plugins screen link.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'network_admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( WINDROSE_SABIR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * A screen's address.
	 *
	 * @param string $page Page slug (default Language groups).
	 * @return string
	 */
	public static function url( string $page = self::SLUG ): string {
		return network_admin_url( 'admin.php?page=' . $page );
	}

	/**
	 * Adds the menu items.
	 *
	 * @return void
	 */
	public static function menu(): void {
		$render = array( __CLASS__, 'render' );
		$top    = add_menu_page( __( 'Language groups', 'windrose-sabir' ), __( 'Windrose Sabir', 'windrose-sabir' ), 'manage_network_options', self::SLUG, $render, 'core/language' );
		$pages  = array(
			'groups'  => add_submenu_page( self::SLUG, __( 'Language groups', 'windrose-sabir' ), __( 'Language groups', 'windrose-sabir' ), 'manage_network_options', self::SLUG, $render ),
			'links'   => add_submenu_page( self::SLUG, __( 'Translation links', 'windrose-sabir' ), __( 'Translation links', 'windrose-sabir' ), 'manage_network_options', self::LINKS, $render ),
			'logbook' => Modules::on( 'logbook' ) ? add_submenu_page( self::SLUG, __( 'Logbook', 'windrose-sabir' ), __( 'Logbook', 'windrose-sabir' ), 'manage_network_options', self::LOGBOOK, $render ) : false,
			'modules' => add_submenu_page( self::SLUG, __( 'Modules', 'windrose-sabir' ), __( 'Modules', 'windrose-sabir' ), 'manage_network_options', self::MODULES, $render ),
		);
		if ( is_string( $top ) ) {
			self::$hooks[ $top ] = 'groups';
		}
		foreach ( $pages as $screen => $hook ) {
			if ( is_string( $hook ) ) {
				self::$hooks[ $hook ] = $screen;
			}
		}
	}

	/**
	 * Titles of the screens.
	 *
	 * @return array<string, string>
	 */
	private static function titles(): array {
		return array(
			'groups'  => __( 'Language groups', 'windrose-sabir' ),
			'links'   => __( 'Translation links', 'windrose-sabir' ),
			'logbook' => __( 'Logbook', 'windrose-sabir' ),
			'modules' => __( 'Modules', 'windrose-sabir' ),
		);
	}

	/**
	 * Prints the screen container; the app renders into it.
	 *
	 * @return void
	 */
	public static function render(): void {
		$screen = self::$hooks[ (string) current_filter() ] ?? 'groups';
		printf(
			'<div class="wrap windrose-sabir"><h1>%1$s</h1><div id="windrose-sabir-app"><p>%2$s</p></div><noscript><p>%3$s</p></noscript></div>',
			esc_html( self::titles()[ $screen ] ?? '' ),
			esc_html__( 'Loading…', 'windrose-sabir' ),
			esc_html__( 'This screen needs JavaScript.', 'windrose-sabir' )
		);
	}

	/**
	 * Loads the app on its own screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( ! isset( self::$hooks[ $hook_suffix ] ) || ! is_network_admin() ) {
			return;
		}
		$asset_file = WINDROSE_SABIR_DIR . '/build/network.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$deps  = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_SABIR_VERSION;

		wp_enqueue_script( self::HANDLE, plugins_url( 'build/network.js', WINDROSE_SABIR_FILE ), $deps, $ver, array( 'in_footer' => true ) );
		wp_set_script_translations( self::HANDLE, 'windrose-sabir', WINDROSE_SABIR_DIR . '/languages' );
		wp_add_inline_script(
			self::HANDLE,
			'window.windroseSabirNetwork = ' . wp_json_encode(
				array(
					'screen'   => self::$hooks[ $hook_suffix ],
					'rest'     => '/' . Rest::NAMESPACE . '/groups',
					'root'     => '/' . Rest::NAMESPACE,
					'urls'     => array(
						'groups' => self::url(),
						'links'  => self::url( self::LINKS ),
					),
					'minSites' => Groups::MIN_SITES,
					'labelMax' => Groups::LABEL_MAX,
					// Windrose Anchors 0.4.0 or later: where "Follow language groups" is.
					'anchors'  => defined( 'Windrose\\Anchors\\Settings::GROUP_HUBS' ) ? network_admin_url( 'admin.php?page=windrose-anchors' ) : '',
				)
			) . ';',
			'before'
		);
		wp_enqueue_style( self::HANDLE, plugins_url( 'build/network.css', WINDROSE_SABIR_FILE ), array( 'wp-components' ), $ver );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
	}

	/**
	 * "Language groups" link on the network Plugins screen.
	 *
	 * @param array<string, string> $links Action links.
	 * @return array<string, string>
	 */
	public static function action_links( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		return array_merge(
			array( 'windrose-sabir-groups' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::url() ), esc_html__( 'Language groups', 'windrose-sabir' ) ) ),
			$links
		);
	}
}
