<?php
/**
 * Modules of Windrose Sabir and the network's language display. Language groups are
 * always on; each other module has one switch.
 *
 * | Network option             | Value                                                                    |
 * |----------------------------|--------------------------------------------------------------------------|
 * | `windrose_sabir_modules`   | `{ copy: bool, site_editor: bool, logbook: bool }`, every module on by default |
 * | `windrose_sabir_display`   | `names` (default), `codes` or `flags`: how Multisite Language Switcher's own switcher and the toolbar show languages |
 *
 * The language display only applies once the network has a language group, like everything else in Windrose Sabir:
 * until then Multisite Language Switcher works exactly as without it.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module switches and the language display.
 */
final class Modules {

	public const OPTION         = 'windrose_sabir_modules';
	public const DISPLAY_OPTION = 'windrose_sabir_display';

	/** Modules with a switch, in screen order. */
	public const MODULES = array( 'copy', 'site_editor', 'logbook' );

	/** Language display values. */
	public const DISPLAYS = array( 'names', 'codes', 'flags' );

	/**
	 * Every module switch.
	 *
	 * @return array<string, bool>
	 */
	public static function all(): array {
		$stored = get_site_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$result = array();
		foreach ( self::MODULES as $module ) {
			$result[ $module ] = ! array_key_exists( $module, $stored ) || (bool) $stored[ $module ];
		}
		return $result;
	}

	/**
	 * Whether a module is on.
	 *
	 * @param string $module copy, site_editor or logbook.
	 * @return bool
	 */
	public static function on( string $module ): bool {
		return self::all()[ $module ] ?? false;
	}

	/**
	 * Saves module switches (unknown names ignored).
	 *
	 * @param array<string, mixed> $switches Module => on.
	 * @return array<string, bool> Every switch afterwards.
	 */
	public static function save( array $switches ): array {
		$all = self::all();
		foreach ( self::MODULES as $module ) {
			if ( array_key_exists( $module, $switches ) ) {
				$all[ $module ] = (bool) $switches[ $module ];
			}
		}
		update_site_option( self::OPTION, $all );
		return $all;
	}

	/**
	 * The language display of Multisite Language Switcher's switcher.
	 *
	 * @return string names, codes or flags.
	 */
	public static function display(): string {
		$value = get_site_option( self::DISPLAY_OPTION, 'names' );
		return is_string( $value ) && in_array( $value, self::DISPLAYS, true ) ? $value : 'names';
	}

	/**
	 * Saves the language display.
	 *
	 * @param string $display names, codes or flags.
	 * @return bool False for an unknown value.
	 */
	public static function save_display( string $display ): bool {
		if ( ! in_array( $display, self::DISPLAYS, true ) ) {
			return false;
		}
		update_site_option( self::DISPLAY_OPTION, $display );
		return true;
	}
}
