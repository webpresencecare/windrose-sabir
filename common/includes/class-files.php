<?php
/**
 * Files that WordPress itself writes with PHP: uploads, temporary files, the plugin's own files.
 *
 * Through core's Filesystem API, always its direct method: WordPress writes uploads and temporary files with PHP on
 * every host, also where it needs FTP details to update plugins, so these files never need them. Not for files of
 * WordPress's installation (wp-config.php, plugins, themes): those depend on the host's method.
 *
 * @package Windrose\Common
 */

namespace Windrose\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, writes and copies files through core's direct Filesystem API.
 */
final class Files {

	/**
	 * The direct filesystem, made once.
	 *
	 * @var \WP_Filesystem_Direct|null
	 */
	private static ?\WP_Filesystem_Direct $direct = null;

	/**
	 * Core's direct filesystem.
	 *
	 * Always give its put_contents(), chmod() and mkdir() a mode: without one they fall back to core's FS_CHMOD_FILE
	 * and FS_CHMOD_DIR, which only WP_Filesystem() defines, so the call stops with an error on most requests. Make
	 * folders with core's wp_mkdir_p().
	 *
	 * @return \WP_Filesystem_Direct
	 */
	public static function direct(): \WP_Filesystem_Direct {
		if ( null === self::$direct ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			self::$direct = new \WP_Filesystem_Direct( null );
		}
		return self::$direct;
	}

	/**
	 * Content of a file.
	 *
	 * @param string $file File.
	 * @return string|null Null when it is not a readable file.
	 */
	public static function read( string $file ): ?string {
		$filesystem = self::direct();
		if ( ! $filesystem->is_file( $file ) || ! $filesystem->is_readable( $file ) ) {
			return null;
		}
		$contents = $filesystem->get_contents( $file );
		return is_string( $contents ) ? $contents : null;
	}

	/**
	 * Writes a file. An existing file keeps its permissions; a new one gets the usual permissions of new files.
	 *
	 * @param string $file     File.
	 * @param string $contents Content.
	 * @return bool
	 */
	public static function write( string $file, string $contents ): bool {
		$filesystem = self::direct();
		if ( ! $filesystem->exists( $file ) && ! $filesystem->touch( $file ) ) {
			return false;
		}
		$mode = (int) octdec( $filesystem->getchmod( $file ) );
		return $filesystem->put_contents( $file, $contents, $mode > 0 ? $mode : 0644 );
	}

	/**
	 * Copies a file.
	 *
	 * @param string $from      File to copy.
	 * @param string $to        The copy.
	 * @param bool   $overwrite Whether an existing file at $to may be replaced (a temporary file from wp_tempnam()).
	 * @return bool
	 */
	public static function copy( string $from, string $to, bool $overwrite = false ): bool {
		return self::direct()->copy( $from, $to, $overwrite );
	}
}
