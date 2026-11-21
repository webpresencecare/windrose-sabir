<?php
/**
 * Terms of a copy (Terms::apply()).
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
 * Terms importer.
 */
final class Importer_Terms extends BaseImporter {

	/**
	 * Copies the terms.
	 *
	 * @param array<string, mixed> $data MSLS's post data (unchanged).
	 * @return array<string, mixed>
	 */
	public function import( array $data ) {
		$job = Job::of( $this->import_coordinates );
		if ( null !== $job ) {
			Terms::apply( $job );
		}
		return $data;
	}
}
