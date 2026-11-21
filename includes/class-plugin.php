<?php
/**
 * Windrose Sabir: start-up.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin.
 */
final class Plugin {

	/**
	 * Hooks everything (on plugins_loaded). Windrose Sabir only works on a multisite network, with Multisite Language
	 * Switcher active (WordPress enforces `Requires Plugins`; the check here covers a must-use or manual setup).
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( ! is_multisite() ) {
			add_action( 'admin_notices', array( __CLASS__, 'multisite_notice' ) );
			return;
		}
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );

		Groups::init();
		Media_Hubs::init();
		Rest::init();
		Admin::init();
		if ( ! self::msls_active() ) {
			add_action( 'network_admin_notices', array( __CLASS__, 'msls_notice' ) );
			return;
		}
		Scope::init();
		Msls_Settings::init();
		Health::init();
		Copy\Copier::init();
		Copy\Editor::init();
		Copy\Bulk::init();
		Language_Item::init();
		Display::init();
		Toolbar::init();
		Logbook::init();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'windrose-sabir logbook', Cli::class );
		}
	}

	/**
	 * Whether Multisite Language Switcher is loaded.
	 *
	 * @return bool
	 */
	public static function msls_active(): bool {
		return defined( 'MSLS_PLUGIN_VERSION' );
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'windrose-sabir', false, dirname( plugin_basename( WINDROSE_SABIR_FILE ) ) . '/languages' );
	}

	/**
	 * Says that Windrose Sabir needs a multisite network.
	 *
	 * @return void
	 */
	public static function multisite_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		wp_admin_notice( esc_html__( 'Windrose Sabir works on a multisite network only. It does nothing on this single site.', 'windrose-sabir' ), array( 'type' => 'warning' ) );
	}

	/**
	 * Says that language groups wait for Multisite Language Switcher.
	 *
	 * @return void
	 */
	public static function msls_notice(): void {
		if ( ! current_user_can( 'manage_network_plugins' ) ) {
			return;
		}
		wp_admin_notice( esc_html__( 'Windrose Sabir needs Multisite Language Switcher: language groups have no effect until it is active.', 'windrose-sabir' ), array( 'type' => 'warning' ) );
	}

	/**
	 * Suggested text for each site's privacy policy (Settings, Privacy, Policy guide).
	 *
	 * @return void
	 */
	public static function privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'Windrose Sabir collects nothing about visitors, sets no cookies and contacts no other service. It chooses which sites of this network are linked as translations of each other, and copies content between them when an editor asks for it.', 'windrose-sabir' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Windrose Sabir', 'windrose-sabir' ), wp_kses_post( $text ) );
	}
}
