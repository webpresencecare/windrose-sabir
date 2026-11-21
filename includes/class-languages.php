<?php
/**
 * Language names for the admin screens, in their own language ("Français (France)"), from PHP's intl extension.
 * Nothing is asked from wordpress.org. Without intl, the locale code is the name.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language names.
 */
final class Languages {

	/**
	 * A locale's name in its own language.
	 *
	 * @param string $locale WordPress locale (fr_FR, ar, de_CH_informal).
	 * @return string Name, or the locale code when unknown.
	 */
	public static function name( string $locale ): string {
		if ( ! class_exists( \Locale::class ) ) {
			return $locale;
		}
		// WordPress variants (de_CH_informal, pt_PT_ao90) are not ICU locales: name the language and region only.
		$base = implode( '_', array_slice( explode( '_', $locale ), 0, 2 ) );
		$name = \Locale::getDisplayName( $base, $base );
		if ( ! is_string( $name ) || '' === $name || $name === $base ) {
			return $locale;
		}
		return mb_ucfirst( $name );
	}
}
