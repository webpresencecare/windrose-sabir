<?php
/**
 * The language item block `windrose/language-item` (Site Editor module): links to the current page in
 * the other languages of the site's group, through Multisite Language Switcher's own links (the same addresses as
 * its switcher and hreflang).
 *
 * - Inside a Navigation block, one navigation item per language (`<li class="wp-block-navigation-item">`, styled like
 *   the other items); elsewhere a list. Core's Navigation accepts it through its `allowedBlocks` (server: the block
 *   metadata filter; editor: the `blocks.registerBlockType` filter in src/language-item).
 * - Text: the language's name in its own language (default; with the region when two sites share a language), its
 *   code (FR, with the name for screen readers), or the label set in MSLS's settings.
 * - Each link has `lang` and `hreflang` (the language's BCP 47 tag, short when it is unique, as MSLS's hreflang). The
 *   current language is plain text, not a link (`lang`, `aria-current="true"`). Language names carry `translate="no"`.
 * - Options: show the current language (default yes); hide languages with no translation of this page (default no:
 *   they link to that site's home page; MSLS itself gives no address there).
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

use WP_Block;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language item block.
 *
 * @phpstan-type Item array{locale: string, name: string, code: string, label: string, tag: string, current: bool, url: string}
 */
final class Language_Item {

	public const NAME   = 'windrose/language-item';
	public const HANDLE = 'windrose-sabir-language-item';

	/**
	 * Hooks the block when the Site Editor module is on.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! Modules::on( 'site_editor' ) ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'block_type_metadata', array( __CLASS__, 'navigation_metadata' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_data' ) );
	}

	/**
	 * Registers the scripts, the style and the block.
	 *
	 * @return void
	 */
	public static function register(): void {
		$asset_file = WINDROSE_SABIR_DIR . '/build/language-item.asset.php';
		$asset      = is_readable( $asset_file ) ? require $asset_file : array();
		$deps       = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver        = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_SABIR_VERSION;
		$src        = plugins_url( 'build/language-item.js', WINDROSE_SABIR_FILE );
		wp_register_script( self::HANDLE, '' === $src ? false : $src, $deps, $ver, array( 'in_footer' => false ) );
		wp_set_script_translations( self::HANDLE, 'windrose-sabir', WINDROSE_SABIR_DIR . '/languages' );
		wp_register_style( self::HANDLE, plugins_url( 'blocks/language-item/style.css', WINDROSE_SABIR_FILE ), array(), WINDROSE_SABIR_VERSION );
		wp_register_style( self::HANDLE . '-editor', plugins_url( 'build/language-item.css', WINDROSE_SABIR_FILE ), array(), $ver );
		register_block_type(
			WINDROSE_SABIR_DIR . '/blocks/language-item',
			array( 'render_callback' => array( __CLASS__, 'render' ) )
		);
	}

	/**
	 * Lets core's Navigation block hold the language item.
	 *
	 * @param mixed $metadata Block metadata.
	 * @return mixed
	 */
	public static function navigation_metadata( $metadata ) {
		if ( ! is_array( $metadata ) || 'core/navigation' !== ( $metadata['name'] ?? '' ) ) {
			return $metadata;
		}
		$allowed = is_array( $metadata['allowedBlocks'] ?? null ) ? $metadata['allowedBlocks'] : array();
		if ( ! in_array( self::NAME, $allowed, true ) ) {
			$allowed[] = self::NAME;
		}
		$metadata['allowedBlocks'] = $allowed;
		return $metadata;
	}

	/**
	 * The languages of the current site's group for the editor preview (names, codes, labels; no links).
	 *
	 * @return void
	 */
	public static function editor_data(): void {
		$items = array();
		foreach ( self::items( false ) as $item ) {
			unset( $item['url'] );
			$items[] = $item;
		}
		wp_add_inline_script( self::HANDLE, 'window.windroseSabirLanguages = ' . wp_json_encode( $items ) . ';', 'before' );
	}

	/**
	 * The languages of the current site's MSLS collection, with links to the current page when $links is true.
	 *
	 * @param bool                 $links Compute the links (front end).
	 * @param array<string, mixed> $attrs Block attributes (showCurrent, hideMissing).
	 * @return list<array{locale: string, name: string, code: string, label: string, tag: string, current: bool, url: string}>
	 */
	public static function items( bool $links, array $attrs = array() ): array {
		if ( ! Plugin::msls_active() ) {
			return array();
		}
		$collection = msls_blog_collection();
		$blogs      = $collection->get_objects();
		$locales    = array();
		foreach ( $blogs as $blog ) {
			$locales[] = $blog->get_language();
		}
		$options = $links ? \lloc\Msls\Options\Options::create() : null;
		$items   = array();
		foreach ( $blogs as $blog ) {
			$locale  = $blog->get_language();
			$current = $collection->is_current_blog( $blog );
			$url     = '';
			if ( null !== $options ) {
				if ( $current && false === ( $attrs['showCurrent'] ?? true ) ) {
					continue;
				}
				$plain = \lloc\Msls\Options\Options::class === get_class( $options );
				if ( ! $current && true === ( $attrs['hideMissing'] ?? false ) && ! $plain && ! $options->has_value( $locale ) ) {
					continue;
				}
				// The current language is plain text: no address needed.
				$url = $current ? '' : (string) $blog->get_url( $options );
				if ( '' === $url && ! $current ) {
					// MSLS gives no address for a page without translation there: its home page, unless hidden.
					if ( true === ( $attrs['hideMissing'] ?? false ) ) {
						continue;
					}
					$url = (string) get_home_url( (int) $blog->userblog_id, '/' );
				}
			}
			$items[] = array(
				'locale'  => $locale,
				'name'    => self::name( $locale, $locales ),
				'code'    => strtoupper( self::tag( $locale, $locales ) ),
				'label'   => (string) $blog->get_description(),
				'tag'     => self::tag( $locale, $locales ),
				'current' => $current,
				'url'     => $url,
			);
		}//end foreach
		return $items;
	}

	/**
	 * A language's name in its own language: the language alone, or with its region when another site of the list
	 * uses the same language.
	 *
	 * @param string   $locale  Locale.
	 * @param string[] $locales Every locale of the list.
	 * @return string
	 */
	public static function name( string $locale, array $locales ): string {
		$language = strtok( $locale, '_' );
		$shared   = count( array_filter( $locales, static fn( string $other ): bool => strtok( $other, '_' ) === $language ) ) > 1;
		if ( $shared || ! class_exists( \Locale::class ) ) {
			return Languages::name( $locale );
		}
		$name = \Locale::getDisplayLanguage( (string) $language, (string) $language );
		return is_string( $name ) && '' !== $name && $name !== $language ? mb_ucfirst( $name ) : Languages::name( $locale );
	}

	/**
	 * A language's BCP 47 tag: the language alone when unique in the list (fr), else with its region (en-GB).
	 *
	 * @param string   $locale  Locale.
	 * @param string[] $locales Every locale of the list.
	 * @return string
	 */
	public static function tag( string $locale, array $locales ): string {
		$parts    = explode( '_', $locale );
		$language = strtolower( $parts[0] );
		$shared   = count( array_filter( $locales, static fn( string $other ): bool => strtolower( explode( '_', $other )[0] ) === $language ) ) > 1;
		if ( ! $shared || ! isset( $parts[1] ) ) {
			return $language;
		}
		return $language . '-' . strtoupper( $parts[1] );
	}

	/**
	 * Renders the block.
	 *
	 * @param array<string, mixed> $attrs   Attributes.
	 * @param string               $content Saved content (none).
	 * @param WP_Block|null        $block   Block instance.
	 * @return string
	 */
	public static function render( $attrs, $content = '', $block = null ): string {
		unset( $content );
		$attrs = is_array( $attrs ) ? $attrs : array();
		$items = self::items( true, $attrs );
		if ( array() === $items ) {
			return '';
		}
		$display = in_array( $attrs['display'] ?? 'name', array( 'name', 'code', 'label' ), true ) ? (string) ( $attrs['display'] ?? 'name' ) : 'name';
		$in_nav  = $block instanceof WP_Block && array_key_exists( 'maxNestingLevel', $block->context );
		$extra   = is_string( $attrs['className'] ?? null ) ? ' ' . $attrs['className'] : '';
		$html    = '';
		foreach ( $items as $item ) {
			$text = match ( $display ) {
				'code'  => sprintf( '<span translate="no">%1$s</span><span class="windrose-language-item__name"> (%2$s)</span>', esc_html( $item['code'] ), esc_html( $item['name'] ) ),
				'label' => esc_html( '' !== $item['label'] ? $item['label'] : $item['name'] ),
				default => sprintf( '<span translate="no">%s</span>', esc_html( $item['name'] ) ),
			};
			$text  = $in_nav ? '<span class="wp-block-navigation-item__label">' . $text . '</span>' : $text;
			$tag   = esc_attr( $item['tag'] );
			$link  = $item['current']
				? sprintf( '<span class="%1$s" lang="%2$s" aria-current="true">%3$s</span>', $in_nav ? 'wp-block-navigation-item__content' : 'windrose-language-item__current', $tag, $text )
				: sprintf( '<a class="%1$s" href="%2$s" hreflang="%3$s" lang="%3$s">%4$s</a>', $in_nav ? 'wp-block-navigation-item__content' : 'windrose-language-item__link', esc_url( $item['url'] ), $tag, $text );
			$class = ( $in_nav ? 'wp-block-navigation-item ' : '' ) . 'windrose-language-item__item' . ( $item['current'] ? ' is-current' : '' );
			$html .= sprintf( '<li class="%1$s">%2$s</li>', esc_attr( $class . ( $in_nav ? $extra : '' ) ), $link );
		}
		if ( $in_nav ) {
			return $html;
		}
		return sprintf( '<ul %1$s>%2$s</ul>', get_block_wrapper_attributes( array( 'class' => 'windrose-language-item' ) ), $html );
	}
}
