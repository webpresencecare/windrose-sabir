<?php
/**
 * A Multisite Language Switcher link showing the language code (FR), with the site's label for screen readers.
 * Used by Display when the network's language display is "codes".
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Code link.
 */
class Code_Link extends \lloc\Msls\Link\Link {

	/**
	 * Description in MSLS's lists.
	 *
	 * @return string
	 */
	public static function get_description(): string {
		return __( 'Language code', 'windrose-sabir' );
	}

	/**
	 * The code (from the link's locale, MSLS's `alt`) and the label for screen readers.
	 *
	 * @return string
	 */
	public function __toString(): string {
		$locale = is_string( $this->alt ) ? $this->alt : '';
		$code   = strtoupper( (string) strtok( $locale, '_' ) );
		$label  = is_string( $this->txt ) ? $this->txt : '';
		return sprintf(
			'<span translate="no">%1$s</span>%2$s',
			esc_html( $code ),
			'' === $label ? '' : '<span class="windrose-language-item__name"> (' . esc_html( $label ) . ')</span>'
		);
	}
}
