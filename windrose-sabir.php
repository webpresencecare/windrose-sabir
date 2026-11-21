<?php
/**
 * Plugin Name:       Windrose Sabir - Languages
 * Plugin URI:        https://www.webpresencecare.com/plugins
 * Description:       Multilingual add-on for Multisite Language Switcher: language groups choose which sites of the network are translations of each other; Copy creates linked drafts in other languages; a language item for the Navigation block; Logbook export.
 * Version:           0.3.3
 * Requires at least: 7.2
 * Requires PHP:      8.4
 * Requires Plugins:  multisite-language-switcher
 * Author:            Patricia BT
 * Author URI:        https://patriciabt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       windrose-sabir
 * Domain Path:       /languages
 * Network:           true
 * Update URI:        https://github.com/webpresencecare/windrose-sabir
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WINDROSE_SABIR_VERSION', '0.3.3' );
define( 'WINDROSE_SABIR_FILE', __FILE__ );
define( 'WINDROSE_SABIR_DIR', __DIR__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$parts  = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name   = (string) array_pop( $parts );
		$folder = array() === $parts ? '' : strtolower( implode( '/', $parts ) ) . '/';
		$file   = __DIR__ . '/includes/' . $folder . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once __DIR__ . '/common/bootstrap.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
