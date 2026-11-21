<?php
/**
 * Windrose Sabir on MSLS's settings page (Settings, Multisite Language Switcher), through MSLS's `msls_admin_register`
 * action:
 *
 * - a section "Language group (Windrose Sabir)" saying which group the site is in (read only: groups are changed in
 *   Network Admin, Windrose Sabir), and a notice at the top when the site is in no group;
 * - while the Copy module is on and the site is in a group, a section "Copy (Windrose Sabir)" with the site's settings
 *   (option `windrose_sabir_site`, Site_Settings), saved by MSLS's own form.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MSLS settings page additions.
 */
final class Msls_Settings {

	/**
	 * MSLS's settings page slug (options-general.php?page=MslsAdmin).
	 */
	public const PAGE = 'MslsAdmin';

	/**
	 * Hooks the section and the notice.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'msls_admin_register', array( __CLASS__, 'register' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Adds the section (`msls_admin_register`).
	 *
	 * @param mixed $page The settings page MSLS registers its sections on.
	 * @return void
	 */
	public static function register( $page ): void {
		if ( ! is_string( $page ) || '' === $page ) {
			return;
		}
		add_settings_section( 'windrose_sabir_group', __( 'Language group (Windrose Sabir)', 'windrose-sabir' ), array( __CLASS__, 'section' ), $page );
		register_setting(
			'msls',
			Site_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Site_Settings::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
		if ( ! Modules::on( 'copy' ) || 0 === Groups::of_site( get_current_blog_id() ) ) {
			return;
		}
		add_settings_section( 'windrose_sabir_copy', __( 'Copy (Windrose Sabir)', 'windrose-sabir' ), array( __CLASS__, 'copy_section' ), $page );
		add_settings_field( 'windrose_sabir_post_types', __( 'Content to copy', 'windrose-sabir' ), array( __CLASS__, 'post_types_field' ), $page, 'windrose_sabir_copy' );
		add_settings_field( 'windrose_sabir_terms', __( 'Terms without a translation', 'windrose-sabir' ), array( __CLASS__, 'terms_field' ), $page, 'windrose_sabir_copy' );
	}

	/**
	 * Introduces the Copy section.
	 *
	 * @return void
	 */
	public static function copy_section(): void {
		printf(
			'<p>%1$s</p><input type="hidden" name="%2$s[form]" value="1" />',
			esc_html__( 'Copy creates a draft of a post on another site of the language group, linked as its translation. These settings apply to this site.', 'windrose-sabir' ),
			esc_attr( Site_Settings::OPTION )
		);
	}

	/**
	 * The content types this site may copy.
	 *
	 * @return void
	 */
	public static function post_types_field(): void {
		$chosen = Site_Settings::copy_post_types();
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Content to copy', 'windrose-sabir' ) . '</legend>';
		foreach ( Site_Settings::msls_post_types() as $type ) {
			$object = get_post_type_object( $type );
			printf(
				'<label><input type="checkbox" name="%1$s[post_types][]" value="%2$s"%3$s /> %4$s</label><br />',
				esc_attr( Site_Settings::OPTION ),
				esc_attr( $type ),
				checked( in_array( $type, $chosen, true ), true, false ),
				esc_html( null === $object ? $type : (string) $object->labels->name )
			);
		}
		echo '<p class="description">' . esc_html__( 'The editor’s Languages panel and the post lists offer Copy for these.', 'windrose-sabir' ) . '</p></fieldset>';
	}

	/**
	 * What Copy does with categories and tags that have no translation on this site.
	 *
	 * @return void
	 */
	public static function terms_field(): void {
		$value   = Site_Settings::get()['terms'];
		$choices = array(
			'create' => __( 'Create them on this site, linked as translations (rename them here later)', 'windrose-sabir' ),
			'skip'   => __( 'Leave them out', 'windrose-sabir' ),
		);
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Terms without a translation', 'windrose-sabir' ) . '</legend>';
		foreach ( $choices as $key => $label ) {
			printf(
				'<label><input type="radio" name="%1$s[terms]" value="%2$s"%3$s /> %4$s</label><br />',
				esc_attr( Site_Settings::OPTION ),
				esc_attr( $key ),
				checked( $value, $key, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'When a post is copied to this site, its categories and tags use their translations here. This setting is for those without one.', 'windrose-sabir' ) . '</p></fieldset>';
	}

	/**
	 * Prints the section.
	 *
	 * @return void
	 */
	public static function section(): void {
		echo '<p>' . esc_html( self::summary() ) . '</p>';
		if ( current_user_can( 'manage_network_options' ) ) {
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( Admin::url() ), esc_html__( 'Change language groups in Network Admin', 'windrose-sabir' ) );
		} else {
			echo '<p>' . esc_html__( 'Network administrators change language groups.', 'windrose-sabir' ) . '</p>';
		}
	}

	/**
	 * What the group means for this site, in one or two sentences.
	 *
	 * @return string
	 */
	public static function summary(): string {
		$site_id = get_current_blog_id();
		if ( ! Groups::exist() ) {
			return __( 'This network has no language group yet, so Multisite Language Switcher links the sites of its reference user, as usual.', 'windrose-sabir' );
		}
		$group_id = Groups::of_site( $site_id );
		$group    = Groups::get( $group_id );
		if ( null === $group ) {
			return __( 'This site is in no language group, so Multisite Language Switcher shows no language switcher and no translation links here, and these settings have no effect.', 'windrose-sabir' );
		}
		$others = array();
		foreach ( Groups::sites( $group_id ) as $other ) {
			if ( $other !== $site_id ) {
				$others[] = Groups::site_label( $other );
			}
		}
		return sprintf(
			/* translators: 1: group name, 2: list of sites. */
			__( 'This site is in the language group “%1$s”. Its translations can be on: %2$s.', 'windrose-sabir' ),
			$group['label'],
			implode( wp_get_list_item_separator(), $others )
		);
	}

	/**
	 * Notice on MSLS's settings page when the site is in no group while groups exist.
	 *
	 * @return void
	 */
	public static function notice(): void {
		$screen = get_current_screen();
		if ( null === $screen || 'settings_page_' . self::PAGE !== $screen->id || ! Groups::exist() || 0 !== Groups::of_site( get_current_blog_id() ) ) {
			return;
		}
		wp_admin_notice( esc_html( self::summary() ), array( 'type' => 'info' ) );
	}
}
