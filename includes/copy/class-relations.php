<?php
/**
 * Relations for Windrose Sabir's copies: MSLS's `Relations` without writing anything. Outside wp-admin, MSLS saves the
 * links of an import under the wrong post (`msls_0`) and leaves the wrong site switched,
 * so Copy writes the translation links itself (Links::link()) once the import ran.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir\Copy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Relations that MSLS's content importer collects but does not write.
 */
final class Relations extends \lloc\Msls\ContentImport\Relations {

	/**
	 * Writes nothing: Copy links the posts itself.
	 *
	 * @return void
	 */
	public function create(): void {}
}
