<?php
/**
 * Featured image of a copy: the target site's item chosen by Media (a network item, the hub's own item or a file
 * copy), never MSLS's "linking" importer.
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
 * Featured image importer.
 */
final class Importer_Thumbnail extends BaseImporter {

	/**
	 * Sets the featured image.
	 *
	 * @param array<string, mixed> $data MSLS's post data (unchanged).
	 * @return array<string, mixed>
	 */
	public function import( array $data ) {
		$job = Job::of( $this->import_coordinates );
		if ( null === $job ) {
			return $data;
		}
		$source    = $job->source->ID;
		$thumbnail = (int) $job->on_source( static fn(): int => (int) get_post_thumbnail_id( $source ) );
		$target    = $thumbnail > 0 ? ( $job->media->map[ $thumbnail ] ?? 0 ) : 0;
		$post      = $job->target_id;
		$job->on_target(
			static function () use ( $target, $post ): void {
				if ( $target > 0 ) {
					set_post_thumbnail( $post, $target );
				} else {
					delete_post_thumbnail( $post );
				}
			}
		);
		if ( $target > 0 ) {
			$this->logger->log_success( 'post-thumbnail/set', array( 'id' => $target ) );
		}
		return $data;
	}
}
