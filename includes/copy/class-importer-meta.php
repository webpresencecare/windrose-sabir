<?php
/**
 * Custom fields of a copy: only an allow list, by default the page template (`_wp_page_template`) and footnotes
 * (`footnotes`). Filter `windrose_sabir_copy_meta_keys` adds others, per post type and site.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir\Copy;

use lloc\Msls\ContentImport\Importers\BaseImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post meta importer.
 */
final class Importer_Meta extends BaseImporter {

	/** Custom fields copied by default. */
	public const DEFAULT_KEYS = array( '_wp_page_template', 'footnotes' );

	/**
	 * Copies the allowed custom fields.
	 *
	 * @param array<string, mixed> $data MSLS's post data (unchanged).
	 * @return array<string, mixed>
	 */
	public function import( array $data ) {
		$job = Job::of( $this->import_coordinates );
		if ( null === $job ) {
			return $data;
		}
		/**
		 * Filters the custom fields (meta keys) Copy takes to the new translation.
		 *
		 * @param string[] $keys        Meta keys.
		 * @param string   $post_type   Post type.
		 * @param int      $source_site Source site ID.
		 * @param int      $target_site Target site ID.
		 */
		$keys   = apply_filters( 'windrose_sabir_copy_meta_keys', self::DEFAULT_KEYS, $job->source->post_type, $job->source_site, $job->target_site );
		$keys   = is_array( $keys ) ? array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) ) : array();
		$source = $job->source->ID;
		$values = $job->on_source(
			static function () use ( $keys, $source ): array {
				$values = array();
				foreach ( $keys as $key ) {
					if ( metadata_exists( 'post', $source, $key ) ) {
						$values[ $key ] = get_post_meta( $source, $key, false );
					}
				}
				return $values;
			}
		);
		$target = $job->target_id;
		$job->on_target(
			static function () use ( $values, $target ): void {
				foreach ( $values as $key => $list ) {
					delete_post_meta( $target, $key );
					foreach ( $list as $value ) {
						add_post_meta( $target, $key, wp_slash( $value ) );
					}
				}
			}
		);
		foreach ( array_keys( $values ) as $key ) {
			$this->logger->log_success( 'meta/added', array( $key => true ) );
		}
		return $data;
	}
}
