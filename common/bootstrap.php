<?php
/**
 * Windrose Common bootstrap.
 *
 * Every Windrose plugin bundles a copy of the Windrose Common library in its own `common/` folder and requires this
 * file from its main file. Each copy registers itself; on `plugins_loaded` (priority -1000) the newest registered copy
 * is loaded once and fires `windrose_common_loaded`. So a plugin works alone, and when several Windrose plugins are
 * active they all share the newest copy.
 *
 * This file is identical in every copy except the version line, and its shape must never change: an old plugin's copy
 * may be the one that defines the loader below.
 *
 * Source: windrose-helm/common/. Never edit a copy inside another plugin; run windrose-helm/bin/sync-common.sh instead.
 *
 * @package Windrose\Common
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'windrose_common_load' ) ) {
	/**
	 * Loads the newest registered copy of Windrose Common, once.
	 *
	 * @return void
	 */
	function windrose_common_load(): void {
		if ( defined( 'WINDROSE_COMMON_VERSION' ) ) {
			return;
		}
		$copies = $GLOBALS['windrose_common_copies'] ?? array();
		if ( ! is_array( $copies ) || array() === $copies ) {
			return;
		}
		uasort( $copies, static fn( $a, $b ): int => version_compare( (string) $a, (string) $b ) );
		$newest = (string) array_key_last( $copies );
		if ( is_readable( $newest . '/load.php' ) ) {
			require_once $newest . '/load.php';
		}
	}
	add_action( 'plugins_loaded', 'windrose_common_load', -1000 );
}//end if

if ( ! isset( $GLOBALS['windrose_common_copies'] ) || ! is_array( $GLOBALS['windrose_common_copies'] ) ) {
	$GLOBALS['windrose_common_copies'] = array();
}
// This copy's version: the only line that differs between copies.
$GLOBALS['windrose_common_copies'][ __DIR__ ] = '1.4.0';

// A plugin included after plugins_loaded (for example during its own activation) still gets the library.
if ( did_action( 'plugins_loaded' ) ) {
	windrose_common_load();
}
