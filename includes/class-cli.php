<?php
/**
 * WP-CLI commands of Windrose Sabir.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports the language logbook of the network (languages, groups and translations).
 */
final class Cli {

	/**
	 * Exports the logbook as JSON (schema 1), and optionally the address table as CSV.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Write the JSON to this file instead of the output.
	 *
	 * [--csv=<path>]
	 * : Also write the address table (one column per site, one row per translation set) to this file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp windrose-sabir logbook export --file=logbook.json --csv=addresses.csv
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		unset( $args );
		$data = Logbook::build();
		$json = Logbook::json( $data ) . "\n";
		if ( isset( $assoc_args['file'] ) ) {
			self::write( (string) $assoc_args['file'], $json );
			WP_CLI::success( sprintf( 'Logbook written to %1$s: %2$d sites, %3$d translation sets.', $assoc_args['file'], count( $data['sites'] ), count( $data['sets'] ) ) );
		} else {
			WP_CLI::line( rtrim( $json ) );
		}
		if ( isset( $assoc_args['csv'] ) ) {
			self::write( (string) $assoc_args['csv'], Logbook::csv( $data ) );
			WP_CLI::success( sprintf( 'Address table written to %s.', $assoc_args['csv'] ) );
		}
	}

	/**
	 * Writes a file or stops with an error.
	 *
	 * @param string $path    File.
	 * @param string $content Content.
	 * @return void
	 */
	private static function write( string $path, string $content ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP-CLI writes where the person asked.
		if ( false === file_put_contents( $path, $content ) ) {
			WP_CLI::error( sprintf( 'Could not write %s.', $path ) );
		}
	}
}
