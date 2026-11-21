<?php
/**
 * Media references in post content, for Copy: which attachments a post's blocks use, and the same content with those
 * references moved to the target site's attachments.
 *
 * | Block                 | Reference                                                        |
 * |-----------------------|------------------------------------------------------------------|
 * | core/image            | `id`, class `wp-image-<ID>`, `data-id` (inside a gallery)         |
 * | core/gallery          | `ids` (old galleries); its images are core/image blocks           |
 * | core/cover            | `id`, class `wp-image-<ID>`                                       |
 * | core/media-text       | `mediaId`, class `wp-image-<ID>`                                   |
 * | core/video, core/audio, core/file | `id`                                                 |
 * | Classic content       | `<img>` tags with class `wp-image-<ID>`                           |
 *
 * A reference mapped to 0 is removed (the block keeps the file's address, without a media item). File addresses change
 * only for images copied as files: inside the block (or `<img>` tag) that references them, an address whose file
 * name is one of the source files becomes the matching file of the copy. Content without any change is returned as it
 * was (no parse and serialize round trip).
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir\Copy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media references in blocks.
 */
final class Content {

	/** ID attributes of media blocks. */
	public const ID_ATTRS = array(
		'core/image'      => array( 'id' ),
		'core/cover'      => array( 'id' ),
		'core/media-text' => array( 'mediaId' ),
		'core/video'      => array( 'id' ),
		'core/audio'      => array( 'id' ),
		'core/file'       => array( 'id' ),
		'core/gallery'    => array( 'ids' ),
	);

	/**
	 * Attachment IDs a content references, in order of appearance.
	 *
	 * @param string $content Post content.
	 * @return list<int>
	 */
	public static function attachment_ids( string $content ): array {
		$ids = array();
		self::collect( parse_blocks( $content ), $ids );
		return array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * Collects references of a list of blocks.
	 *
	 * @param array<array<string, mixed>> $blocks Parsed blocks.
	 * @param int[]                       $ids    Found IDs.
	 * @return void
	 */
	private static function collect( array $blocks, array &$ids ): void {
		foreach ( $blocks as $block ) {
			array_push( $ids, ...self::block_ids( $block ) );
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				self::collect( $block['innerBlocks'], $ids );
			}
		}
	}

	/**
	 * The attachment IDs one block references itself (not its inner blocks).
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return list<int>
	 */
	private static function block_ids( array $block ): array {
		$ids   = array();
		$name  = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		foreach ( self::ID_ATTRS[ $name ] ?? array() as $key ) {
			$value = $attrs[ $key ] ?? null;
			foreach ( is_array( $value ) ? $value : array( $value ) as $id ) {
				if ( is_numeric( $id ) && (int) $id > 0 ) {
					$ids[] = (int) $id;
				}
			}
		}
		$html = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $html, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$ids[] = (int) $id;
			}
		}
		return $ids;
	}

	/**
	 * The content with media references moved.
	 *
	 * @param string                            $content Post content.
	 * @param array<int, int>                   $map     Source attachment ID => target attachment ID (0: remove the reference).
	 * @param array<int, array<string, string>> $files   Source attachment ID => [ source file name => target file URL ] (copies only).
	 * @return string
	 */
	public static function rewrite( string $content, array $map, array $files ): string {
		$changes = array_filter( $map, static fn( int $target, int $source ): bool => $target !== $source, ARRAY_FILTER_USE_BOTH );
		if ( array() === $changes && array() === $files ) {
			return $content;
		}
		$blocks = parse_blocks( $content );
		/**
		 * Parsed blocks, as rewritten (same shape as parse_blocks() gives).
		 *
		 * @var array<array{blockName: string|null, attrs: array<mixed>, innerBlocks: array<array<mixed>>, innerHTML: string, innerContent: array<mixed>}> $blocks
		 */
		$blocks = self::rewrite_blocks( $blocks, $map, $files );
		return serialize_blocks( $blocks );
	}

	/**
	 * Moves references in a list of blocks.
	 *
	 * @param array<array<string, mixed>>       $blocks Parsed blocks.
	 * @param array<int, int>                   $map    ID map.
	 * @param array<int, array<string, string>> $files  File maps.
	 * @return array<array<string, mixed>>
	 */
	private static function rewrite_blocks( array $blocks, array $map, array $files ): array {
		foreach ( $blocks as $index => $block ) {
			$blocks[ $index ] = self::rewrite_block( $block, $map, $files );
		}
		return $blocks;
	}

	/**
	 * Moves references in one block and its inner blocks.
	 *
	 * @param array<string, mixed>              $block Parsed block.
	 * @param array<int, int>                   $map   ID map.
	 * @param array<int, array<string, string>> $files File maps.
	 * @return array<string, mixed>
	 */
	private static function rewrite_block( array $block, array $map, array $files ): array {
		$name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		if ( '' === $name ) {
			// Classic content: each <img> tag on its own.
			$html                  = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
			$new                   = self::rewrite_classic( $html, $map, $files );
			$block['innerHTML']    = $new;
			$block['innerContent'] = array( $new );
			return $block;
		}

		$own      = self::block_ids( $block );
		$file_map = array();
		foreach ( $own as $id ) {
			$file_map += $files[ $id ] ?? array();
		}

		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		foreach ( self::ID_ATTRS[ $name ] ?? array() as $key ) {
			if ( ! array_key_exists( $key, $attrs ) ) {
				continue;
			}
			if ( is_array( $attrs[ $key ] ) ) {
				$list = array();
				foreach ( $attrs[ $key ] as $id ) {
					$target = is_numeric( $id ) ? ( $map[ (int) $id ] ?? (int) $id ) : 0;
					if ( $target > 0 ) {
						$list[] = $target;
					}
				}
				$attrs[ $key ] = $list;
			} elseif ( is_numeric( $attrs[ $key ] ) && isset( $map[ (int) $attrs[ $key ] ] ) ) {
				$target = $map[ (int) $attrs[ $key ] ];
				if ( $target > 0 ) {
					$attrs[ $key ] = $target;
				} else {
					unset( $attrs[ $key ] );
				}
			}
		}//end foreach
		if ( array() !== $file_map ) {
			$attrs = self::rewrite_attr_urls( $attrs, $file_map );
		}
		$block['attrs'] = $attrs;

		$fix = static fn( string $html ): string => self::replace_urls( self::replace_ids( $html, $map ), $file_map );
		if ( is_string( $block['innerHTML'] ?? null ) ) {
			$block['innerHTML'] = $fix( $block['innerHTML'] );
		}
		if ( is_array( $block['innerContent'] ?? null ) ) {
			foreach ( $block['innerContent'] as $index => $chunk ) {
				if ( is_string( $chunk ) ) {
					$block['innerContent'][ $index ] = $fix( $chunk );
				}
			}
		}
		if ( is_array( $block['innerBlocks'] ?? null ) ) {
			$block['innerBlocks'] = self::rewrite_blocks( $block['innerBlocks'], $map, $files );
		}
		return $block;
	}

	/**
	 * Moves `wp-image-<ID>` classes and `data-id` attributes.
	 *
	 * @param string          $html HTML.
	 * @param array<int, int> $map  ID map.
	 * @return string
	 */
	private static function replace_ids( string $html, array $map ): string {
		$html = (string) preg_replace_callback(
			'/(\s?)\bwp-image-(\d+)\b/',
			static function ( array $found ) use ( $map ): string {
				$source = (int) $found[2];
				if ( ! isset( $map[ $source ] ) ) {
					return $found[0];
				}
				return $map[ $source ] > 0 ? $found[1] . 'wp-image-' . $map[ $source ] : '';
			},
			$html
		);
		return (string) preg_replace_callback(
			'/\sdata-id="(\d+)"/',
			static function ( array $found ) use ( $map ): string {
				$source = (int) $found[1];
				if ( ! isset( $map[ $source ] ) ) {
					return $found[0];
				}
				return $map[ $source ] > 0 ? ' data-id="' . $map[ $source ] . '"' : '';
			},
			$html
		);
	}

	/**
	 * Replaces addresses whose file name is in the map.
	 *
	 * @param string                $html     HTML or an attribute value.
	 * @param array<string, string> $file_map Source file name => target URL.
	 * @return string
	 */
	public static function replace_urls( string $html, array $file_map ): string {
		if ( array() === $file_map ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'#https?://[^\s"\'<>()]+#i',
			static function ( array $found ) use ( $file_map ): string {
				$path = (string) wp_parse_url( $found[0], PHP_URL_PATH );
				$name = rawurldecode( wp_basename( $path ) );
				return $file_map[ $name ] ?? $found[0];
			},
			$html
		);
	}

	/**
	 * Replaces addresses in string attributes (cover `url`, file `href`), at any depth.
	 *
	 * @param array<mixed>          $attrs    Attributes.
	 * @param array<string, string> $file_map Source file name => target URL.
	 * @return array<mixed>
	 */
	private static function rewrite_attr_urls( array $attrs, array $file_map ): array {
		foreach ( $attrs as $key => $value ) {
			if ( is_string( $value ) ) {
				$attrs[ $key ] = self::replace_urls( $value, $file_map );
			} elseif ( is_array( $value ) ) {
				$attrs[ $key ] = self::rewrite_attr_urls( $value, $file_map );
			}
		}
		return $attrs;
	}

	/**
	 * Moves references in classic content, one `<img>` tag at a time.
	 *
	 * @param string                            $html  HTML.
	 * @param array<int, int>                   $map   ID map.
	 * @param array<int, array<string, string>> $files File maps.
	 * @return string
	 */
	private static function rewrite_classic( string $html, array $map, array $files ): string {
		return (string) preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( array $found ) use ( $map, $files ): string {
				if ( ! preg_match( '/\bwp-image-(\d+)\b/', $found[0], $id ) ) {
					return $found[0];
				}
				return self::replace_urls( self::replace_ids( $found[0], $map ), $files[ (int) $id[1] ] ?? array() );
			},
			$html
		);
	}
}
