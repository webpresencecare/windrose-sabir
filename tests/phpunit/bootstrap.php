<?php
/**
 * PHPUnit bootstrap: the WordPress test library (multisite), with Multisite Language Switcher (MSLS) and Windrose
 * Sabir loaded as network plugins.
 *
 * Environment:
 * - WP_TESTS_DIR: the test library (tests/phpunit of wordpress-develop), as bin/install-wp-tests.sh installs it.
 * - WP_TESTS_CONFIG_FILE_PATH: its wp-tests-config.php (optional; default next to the test library).
 * - WINDROSE_MSLS_DIR: MSLS's folder. Default: next to the test library's folder (bin/install-wp-tests.sh), else the
 *   copy installed in the web root (read only).
 * - WINDROSE_ANCHORS_FILE: Windrose Anchors' main file, loaded too when it exists (Copy's network media tests; they
 *   are skipped without it, as on GitHub). Default: the working copy next to this plugin. "none" leaves it out.
 *
 * The tests use their own core copy and test database, never real data. On GitHub (ci.yml), bin/install-wp-tests.sh
 * installs them.
 *
 * @package Windrose\Sabir
 */

$windrose_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/var/www/html/tools/wp-tests/tests/phpunit';

$windrose_msls_dir = getenv( 'WINDROSE_MSLS_DIR' );
if ( ! $windrose_msls_dir ) {
	$windrose_msls_dir = dirname( $windrose_tests_dir, 3 ) . '/msls';
	if ( ! is_file( $windrose_msls_dir . '/MultisiteLanguageSwitcher.php' ) ) {
		$windrose_msls_dir = '/var/www/html/web/wp-content/plugins/multisite-language-switcher';
	}
}
define( 'WINDROSE_TESTS_MSLS_FILE', $windrose_msls_dir . '/MultisiteLanguageSwitcher.php' );

$windrose_anchors_file = getenv( 'WINDROSE_ANCHORS_FILE' );
if ( ! $windrose_anchors_file ) {
	$windrose_anchors_file = dirname( __DIR__, 3 ) . '/windrose-anchors/windrose-anchors.php';
}
define( 'WINDROSE_TESTS_ANCHORS_FILE', is_file( $windrose_anchors_file ) ? $windrose_anchors_file : '' );

if ( getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) );
}
foreach ( array( dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills', '/var/www/html/tools/vendor/yoast/phpunit-polyfills' ) as $windrose_polyfills ) {
	if ( is_dir( $windrose_polyfills ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $windrose_polyfills );
		break;
	}
}

require_once $windrose_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WINDROSE_TESTS_MSLS_FILE;
		if ( '' !== WINDROSE_TESTS_ANCHORS_FILE ) {
			require WINDROSE_TESTS_ANCHORS_FILE;
		}
		require dirname( __DIR__, 2 ) . '/windrose-sabir.php';
	}
);

// MSLS only links sites where it is active: make it network-active, as on a real network.
tests_add_filter(
	'site_option_active_sitewide_plugins',
	static function ( $plugins ) {
		$plugins = is_array( $plugins ) ? $plugins : array();
		$plugins[ plugin_basename( WINDROSE_TESTS_MSLS_FILE ) ] = time();
		return $plugins;
	}
);

require $windrose_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/class-sabir-testcase.php';
