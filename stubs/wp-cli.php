<?php
/**
 * The WP-CLI methods Windrose Sabir calls (class-cli.php, class-plugin.php), for PHPStan.
 *
 * @package Windrose\Sabir
 */

class WP_CLI {
	/**
	 * @param string          $name     Command name.
	 * @param callable|string $callable Command class.
	 * @param array<string, mixed> $args Options.
	 */
	public static function add_command( $name, $callable, $args = array() ): bool {}
	public static function success( string $message ): void {}
	public static function line( string $message = '' ): void {}
	/**
	 * @return never
	 */
	public static function error( string $message, bool $exit = true ): void {}
}
