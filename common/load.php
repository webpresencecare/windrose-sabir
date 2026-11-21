<?php
/**
 * Loads this copy of Windrose Common (called once, by bootstrap.php, for the newest registered copy).
 *
 * @package Windrose\Common
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WINDROSE_COMMON_VERSION', (string) ( $GLOBALS['windrose_common_copies'][ __DIR__ ] ?? '0.0.0' ) );
define( 'WINDROSE_COMMON_DIR', __DIR__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Windrose\\Common\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) );
		$file = __DIR__ . '/includes/class-' . $name . '.php';
		if ( ! str_contains( $name, '\\' ) && is_readable( $file ) ) {
			require_once $file;
		}
	}
);

\Windrose\Common\Updater::init();

/**
 * Fires once Windrose Common is loaded. Windrose plugins register with the updater here.
 *
 * @param string $version The loaded Windrose Common version.
 */
do_action( 'windrose_common_loaded', WINDROSE_COMMON_VERSION );
