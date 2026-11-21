<?php
/**
 * Updates for Windrose plugins from their GitHub releases.
 *
 * Every Windrose plugin declares `Update URI: https://github.com/webpresencecare/<slug>`. WordPress then asks the
 * `update_plugins_github.com` filter about it, and never sends it to wordpress.org. This class answers from the latest
 * GitHub release of that repository, and only when the release is usable:
 *
 * - not a draft or prerelease, tag `vX.Y.Z`;
 * - an asset named `<slug>.zip` in state `uploaded`, with the sha256 `digest` GitHub publishes for it;
 * - asset addresses inside the same repository.
 *
 * Before WordPress unpacks anything (`upgrader_pre_download`), the package is downloaded here, its size and sha256 are
 * compared with the release, and every file in the zip must sit in a `<slug>/` folder. Anything else stops the update.
 *
 * Requests: GitHub's API only, User-Agent "Windrose", no site data. Results are cached 12 hours (1 hour when nothing
 * usable was found).
 *
 * A plugin folder that is a git working copy (has a `.git` folder) is never updated: an update would replace the code
 * being worked on. Filter: `windrose_common_update_checks`.
 *
 * Other files of a release (for example Windrose Livery's font packs) come through `release_asset()`: the release of
 * one tag, an asset of that exact name, downloaded the same way and checked against the same published sha256.
 *
 * @package Windrose\Common
 */

declare( strict_types=1 );

namespace Windrose\Common;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Windrose update channel.
 *
 * @phpstan-type Release array{status: string, code: int, checked: int, version: string, tag: string, package: string, digest: string, size: int, notes: string, page: string}
 */
final class Updater {

	/** GitHub owner of every Windrose repository. */
	public const OWNER = 'webpresencecare';

	/** Site transient prefix of the cached release of each plugin. */
	public const CACHE_PREFIX = 'windrose_common_release_';

	/** Hosts GitHub redirects release downloads to. */
	private const DOWNLOAD_HOSTS = array(
		'objects.githubusercontent.com',
		'release-assets.githubusercontent.com',
		'github-releases.githubusercontent.com',
	);

	/**
	 * Whether the hooks are in place.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Adds the hooks (once).
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'filter_update' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
	}

	/**
	 * The GitHub repository address of a Windrose plugin.
	 *
	 * @param string $slug Plugin folder name.
	 * @return string
	 */
	public static function repository_url( string $slug ): string {
		return 'https://github.com/' . self::OWNER . '/' . $slug;
	}

	/**
	 * Installed Windrose plugins: those whose Update URI is their own Windrose repository.
	 *
	 * @return array<string, array<string, string>> Plugin file => plugin headers.
	 */
	public static function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$found = array();
		foreach ( get_plugins() as $file => $headers ) {
			if ( self::is_windrose_plugin( (string) $file, $headers ) ) {
				$found[ (string) $file ] = $headers;
			}
		}
		return $found;
	}

	/**
	 * Whether a plugin is a Windrose plugin served by this channel.
	 *
	 * @param string               $file    Plugin file, relative to the plugins folder.
	 * @param array<string, mixed> $headers Plugin headers.
	 * @return bool
	 */
	public static function is_windrose_plugin( string $file, array $headers ): bool {
		$slug = dirname( $file );
		if ( ! preg_match( '/^windrose-[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			return false;
		}
		return ( $headers['UpdateURI'] ?? '' ) === self::repository_url( $slug );
	}

	/**
	 * Whether this site checks GitHub for a plugin.
	 *
	 * @param string $slug Plugin folder name.
	 * @return bool
	 */
	public static function checks_enabled( string $slug ): bool {
		$enabled = ! is_dir( WP_PLUGIN_DIR . '/' . $slug . '/.git' );

		/**
		 * Filters whether a Windrose plugin is checked for updates on GitHub.
		 *
		 * @param bool   $enabled False when the plugin folder is a git working copy, true otherwise.
		 * @param string $slug    Plugin folder name.
		 */
		return (bool) apply_filters( 'windrose_common_update_checks', $enabled, $slug );
	}

	/**
	 * Answers WordPress's update check for a Windrose plugin (`update_plugins_github.com`).
	 *
	 * @param mixed $update      The update data so far (false when none).
	 * @param mixed $plugin_data Plugin headers.
	 * @param mixed $plugin_file Plugin file, relative to the plugins folder.
	 * @param mixed $locales     Installed locales (unused).
	 * @return mixed
	 */
	public static function filter_update( $update, $plugin_data, $plugin_file, $locales = array() ) {
		unset( $locales );
		if ( ! is_array( $plugin_data ) || ! is_string( $plugin_file ) || ! self::is_windrose_plugin( $plugin_file, $plugin_data ) ) {
			return $update;
		}
		$slug = dirname( $plugin_file );
		if ( ! self::checks_enabled( $slug ) ) {
			return $update;
		}
		$release = self::release( $slug );
		if ( 'ok' !== $release['status'] ) {
			return $update;
		}
		return array(
			'id'      => self::repository_url( $slug ),
			'slug'    => $slug,
			'version' => $release['version'],
			'url'     => self::repository_url( $slug ),
			'package' => $release['package'],
		);
	}

	/**
	 * The latest usable release of a plugin, from the cache unless forced.
	 *
	 * @param string $slug  Plugin folder name.
	 * @param bool   $force Skip the cache.
	 * @return array<string, int|string>
	 * @phpstan-return Release
	 */
	public static function release( string $slug, bool $force = false ): array {
		$key = self::CACHE_PREFIX . $slug;
		if ( ! $force ) {
			$cached = get_site_transient( $key );
			if ( is_array( $cached ) && isset( $cached['status'] ) ) {
				return self::normalize( $cached );
			}
		}
		$release = self::fetch( $slug );
		set_site_transient( $key, $release, 'ok' === $release['status'] ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Forgets every cached release, so the next check asks GitHub again.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		foreach ( array_keys( self::installed_plugins() ) as $file ) {
			delete_site_transient( self::CACHE_PREFIX . dirname( $file ) );
		}
	}

	/**
	 * Asks GitHub for the latest release of a plugin.
	 *
	 * @param string $slug Plugin folder name.
	 * @return array<string, int|string>
	 * @phpstan-return Release
	 */
	private static function fetch( string $slug ): array {
		$result   = self::empty_release( 'error' );
		$response = wp_safe_remote_get(
			'https://api.github.com/repos/' . self::OWNER . '/' . $slug . '/releases/latest',
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'user-agent'  => 'Windrose',
				'headers'     => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $result;
		}
		$code           = (int) wp_remote_retrieve_response_code( $response );
		$result['code'] = $code;
		if ( 404 === $code ) {
			// No published release.
			$result['status'] = 'none';
			return $result;
		}
		if ( 200 !== $code ) {
			return $result;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $result;
		}
		return self::parse_release( $slug, $data );
	}

	/**
	 * Turns GitHub's release answer into a usable release, or says why it is not usable.
	 *
	 * @param string               $slug Plugin folder name.
	 * @param array<string, mixed> $data Decoded answer of GET /repos/{owner}/{repo}/releases/latest.
	 * @return array<string, int|string>
	 * @phpstan-return Release
	 */
	public static function parse_release( string $slug, array $data ): array {
		$result         = self::empty_release( 'invalid' );
		$result['code'] = 200;
		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return $result;
		}
		$tag = is_string( $data['tag_name'] ?? null ) ? $data['tag_name'] : '';
		if ( ! preg_match( '/^v(\d+\.\d+\.\d+)$/', $tag, $version ) ) {
			return $result;
		}
		$asset = self::parse_asset( $slug, $tag, $slug . '.zip', $data );
		if ( null === $asset ) {
			return $result;
		}
		$notes = is_string( $data['body'] ?? null ) ? $data['body'] : '';
		$page  = is_string( $data['html_url'] ?? null ) ? $data['html_url'] : '';
		return array(
			'status'  => 'ok',
			'code'    => 200,
			'checked' => time(),
			'version' => $version[1],
			'tag'     => $tag,
			'package' => $asset['url'],
			'digest'  => $asset['digest'],
			'size'    => $asset['size'],
			'notes'   => mb_substr( $notes, 0, 20000 ),
			'page'    => str_starts_with( $page, 'https://github.com/' . self::OWNER . '/' . $slug . '/releases/' ) ? $page : '',
		);
	}

	/**
	 * Downloads and verifies a Windrose package before WordPress unpacks it (`upgrader_pre_download`).
	 *
	 * @param mixed $reply      False, or what an earlier filter returned.
	 * @param mixed $package    The package address from the update data.
	 * @param mixed $upgrader   The upgrader (unused).
	 * @param mixed $hook_extra Extra arguments: `plugin` is the plugin being updated.
	 * @return mixed Path of the verified file, a WP_Error, or $reply untouched for other packages.
	 */
	public static function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		unset( $upgrader );
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}
		if ( ! preg_match( '#^https://api\.github\.com/repos/' . self::OWNER . '/(windrose-[a-z0-9-]+)/releases/assets/\d+$#', $package, $match ) ) {
			return $reply;
		}
		$slug = $match[1];
		if ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) && dirname( $hook_extra['plugin'] ) !== $slug ) {
			return new WP_Error( 'windrose_common_wrong_package', __( 'This update package belongs to another plugin. Update stopped.', 'windrose-sabir' ) );
		}
		$release = self::release( $slug );
		if ( 'ok' !== $release['status'] || $release['package'] !== $package ) {
			// The cache expired and a newer release replaced the one WordPress offered, or the package is not ours.
			$release = self::release( $slug, true );
		}
		if ( 'ok' !== $release['status'] || $release['package'] !== $package ) {
			return new WP_Error( 'windrose_common_unknown_package', __( 'This update package is not the latest release of the plugin. Check for updates again.', 'windrose-sabir' ) );
		}
		return self::download( $slug, $release );
	}

	/**
	 * Downloads a release asset to a temporary file and verifies it.
	 *
	 * @param string                    $slug    Plugin folder name.
	 * @param array<string, int|string> $release The release.
	 * @phpstan-param Release $release
	 * @return string|WP_Error Temporary file path, or the reason it was refused.
	 */
	private static function download( string $slug, array $release ) {
		$failed = new WP_Error( 'windrose_common_download_failed', __( 'The update package could not be downloaded from GitHub.', 'windrose-sabir' ) );
		$file   = self::fetch_asset( $release['package'], $slug . '.zip' );
		if ( null === $file ) {
			return $failed;
		}

		$verified = self::verify_package( $file, $slug, $release );
		if ( is_wp_error( $verified ) ) {
			wp_delete_file( $file );
			return $verified;
		}
		return $file;
	}

	/**
	 * Downloads a release asset (its api.github.com address) to a temporary file, without checking it.
	 *
	 * @param string $api_url  Asset address on api.github.com.
	 * @param string $tempname Name hint for the temporary file.
	 * @return string|null Path, or null when the download failed.
	 */
	private static function fetch_asset( string $api_url, string $tempname ): ?string {
		// The API answers with a redirect to a signed download address. Follow it by hand.
		$first = wp_safe_remote_get(
			$api_url,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'user-agent'  => 'Windrose',
				'headers'     => array( 'Accept' => 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $first ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $first );
		if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			return null;
		}
		$location = wp_remote_retrieve_header( $first, 'location' );
		$location = is_array( $location ) ? (string) reset( $location ) : (string) $location;
		$host     = strtolower( (string) wp_parse_url( $location, PHP_URL_HOST ) );
		if ( 'https' !== wp_parse_url( $location, PHP_URL_SCHEME ) || ! in_array( $host, self::DOWNLOAD_HOSTS, true ) ) {
			return null;
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file   = wp_tempnam( $tempname );
		$second = wp_safe_remote_get(
			$location,
			array(
				'timeout'     => 300,
				'redirection' => 0,
				'user-agent'  => 'Windrose',
				'stream'      => true,
				'filename'    => $file,
			)
		);
		if ( is_wp_error( $second ) || 200 !== (int) wp_remote_retrieve_response_code( $second ) ) {
			wp_delete_file( $file );
			return null;
		}
		return $file;
	}

	/**
	 * Downloads one named file of a Windrose release (not the plugin zip): the release of an exact tag, an asset of
	 * that exact name in state `uploaded`, addresses inside the same repository, at most `$max_bytes`; then the file's
	 * size and sha256 are compared with what GitHub publishes. The caller deletes the file.
	 *
	 * Asks api.github.com and the release download host; nothing else.
	 *
	 * @param string $slug      Repository (a Windrose plugin's folder name).
	 * @param string $tag       Tag, `vX.Y.Z`.
	 * @param string $name      Asset name (letters, digits, `.`, `_`, `-`).
	 * @param int    $max_bytes Largest accepted size.
	 * @return string|WP_Error Path of the verified temporary file, or the reason it was refused.
	 */
	public static function release_asset( string $slug, string $tag, string $name, int $max_bytes ) {
		if ( ! preg_match( '/^windrose-[a-z0-9-]+$/', $slug ) || ! preg_match( '/^v\d+\.\d+\.\d+$/', $tag ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $name ) ) {
			return new WP_Error( 'windrose_common_asset_name', __( 'This file name or release is not valid.', 'windrose-sabir' ) );
		}
		$failed   = new WP_Error( 'windrose_common_asset_failed', __( 'The file could not be downloaded from GitHub.', 'windrose-sabir' ) );
		$response = wp_safe_remote_get(
			'https://api.github.com/repos/' . self::OWNER . '/' . $slug . '/releases/tags/' . $tag,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'user-agent'  => 'Windrose',
				'headers'     => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $failed;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code && 404 !== $code ) {
			// A GitHub error (rate limit, outage), not a missing file.
			return $failed;
		}
		$data  = 200 === $code ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
		$asset = is_array( $data ) ? self::parse_asset( $slug, $tag, $name, $data ) : null;
		if ( null === $asset ) {
			return new WP_Error( 'windrose_common_asset_missing', __( 'GitHub has no release with this file.', 'windrose-sabir' ) );
		}
		if ( $asset['size'] > $max_bytes ) {
			return new WP_Error( 'windrose_common_asset_size', __( 'The file on GitHub is larger than expected. Download stopped.', 'windrose-sabir' ) );
		}
		$file = self::fetch_asset( $asset['url'], $name );
		if ( null === $file ) {
			return $failed;
		}
		if ( ! self::matches( $file, $asset['size'], $asset['digest'] ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'windrose_common_checksum', __( 'The downloaded file does not match its published checksum. Download stopped.', 'windrose-sabir' ) );
		}
		return $file;
	}

	/**
	 * Finds a usable named asset in GitHub's answer for one release.
	 *
	 * @param string               $slug Repository.
	 * @param string               $tag  Tag.
	 * @param string               $name Asset name.
	 * @param array<string, mixed> $data Decoded answer of GET /repos/{owner}/{repo}/releases/tags/{tag}.
	 * @return array{url: string, digest: string, size: int}|null
	 */
	public static function parse_asset( string $slug, string $tag, string $name, array $data ): ?array {
		if ( ! empty( $data['draft'] ) || ( $data['tag_name'] ?? '' ) !== $tag ) {
			return null;
		}
		$repository = self::OWNER . '/' . $slug;
		foreach ( is_array( $data['assets'] ?? null ) ? $data['assets'] : array() as $asset ) {
			if ( ! is_array( $asset ) || ( $asset['name'] ?? '' ) !== $name || 'uploaded' !== ( $asset['state'] ?? '' ) ) {
				continue;
			}
			$digest  = is_string( $asset['digest'] ?? null ) ? $asset['digest'] : '';
			$api_url = is_string( $asset['url'] ?? null ) ? $asset['url'] : '';
			$browser = is_string( $asset['browser_download_url'] ?? null ) ? $asset['browser_download_url'] : '';
			$size    = is_int( $asset['size'] ?? null ) ? $asset['size'] : 0;
			if (
				! preg_match( '/^sha256:([0-9a-f]{64})$/', $digest, $sha256 )
				|| ! preg_match( '#^https://api\.github\.com/repos/' . preg_quote( $repository, '#' ) . '/releases/assets/\d+$#', $api_url )
				|| 'https://github.com/' . $repository . '/releases/download/' . $tag . '/' . $name !== $browser
				|| $size <= 0
			) {
				return null;
			}
			return array(
				'url'    => $api_url,
				'digest' => $sha256[1],
				'size'   => $size,
			);
		}//end foreach
		return null;
	}

	/**
	 * Checks a downloaded package against its release: size, sha256, and a `<slug>/` root folder.
	 *
	 * @param string                    $file    Path of the downloaded file.
	 * @param string                    $slug    Plugin folder name.
	 * @param array<string, int|string> $release The release.
	 * @phpstan-param Release $release
	 * @return true|WP_Error
	 */
	public static function verify_package( string $file, string $slug, array $release ) {
		if ( ! self::matches( $file, $release['size'], $release['digest'] ) ) {
			return new WP_Error( 'windrose_common_checksum', __( 'The update package does not match its published checksum. Update stopped.', 'windrose-sabir' ) );
		}
		if ( ! self::zip_has_root( $file, $slug ) ) {
			return new WP_Error( 'windrose_common_layout', __( 'The update package does not have the expected folder. Update stopped.', 'windrose-sabir' ) );
		}
		return true;
	}

	/**
	 * Whether a downloaded file has the size and sha256 that GitHub publishes for it.
	 *
	 * @param string $file   Path.
	 * @param int    $size   Published size in bytes.
	 * @param string $digest Published sha256, hexadecimal.
	 * @return bool
	 */
	private static function matches( string $file, int $size, string $digest ): bool {
		return is_readable( $file ) && filesize( $file ) === $size && hash_equals( $digest, (string) hash_file( 'sha256', $file ) );
	}

	/**
	 * Whether every entry of a zip sits inside `<slug>/`.
	 *
	 * @param string $file Zip file path.
	 * @param string $slug Plugin folder name.
	 * @return bool
	 */
	public static function zip_has_root( string $file, string $slug ): bool {
		if ( ! class_exists( 'ZipArchive' ) ) {
			// The checksum already proved the file is the published one.
			return true;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			return false;
		}
		$count = $zip->count();
		$valid = $count > 0;
		for ( $i = 0; $valid && $i < $count; $i++ ) {
			$name  = (string) $zip->getNameIndex( $i );
			$valid = str_starts_with( $name, $slug . '/' ) && ! str_contains( $name, '..' ) && ! str_contains( $name, '\\' );
		}
		$zip->close();
		return $valid;
	}

	/**
	 * Plugin details for the "View details" window, so it never asks wordpress.org about a Windrose plugin.
	 *
	 * @param mixed $result Result so far.
	 * @param mixed $action The plugins_api action.
	 * @param mixed $args   Request arguments.
	 * @return mixed
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || ! is_string( $args->slug ) ) {
			return $result;
		}
		$slug = $args->slug;
		foreach ( self::installed_plugins() as $file => $headers ) {
			if ( dirname( $file ) !== $slug ) {
				continue;
			}
			$release = self::checks_enabled( $slug ) ? self::release( $slug ) : self::empty_release( 'none' );
			$notes   = '' !== $release['notes'] ? '<pre>' . esc_html( (string) $release['notes'] ) . '</pre>' : '';
			return (object) array(
				'name'          => $headers['Name'] ?? $slug,
				'slug'          => $slug,
				'version'       => 'ok' === $release['status'] ? $release['version'] : ( $headers['Version'] ?? '' ),
				'author'        => esc_html( $headers['AuthorName'] ?? '' ),
				'homepage'      => self::repository_url( $slug ),
				'requires'      => $headers['RequiresWP'] ?? '',
				'requires_php'  => $headers['RequiresPHP'] ?? '',
				'download_link' => 'ok' === $release['status'] ? $release['package'] : '',
				'external'      => true,
				'sections'      => array(
					'description' => '<p>' . esc_html( $headers['Description'] ?? '' ) . '</p>',
					'changelog'   => $notes,
				),
			);
		}//end foreach
		return $result;
	}

	/**
	 * Update state of every installed Windrose plugin, for admin screens and Site Health. Uses the cache.
	 *
	 * @param bool $force Ask GitHub again.
	 * @return list<array{slug: string, file: string, name: string, installed: string, latest: string, state: string, code: int, checked: int, page: string}>
	 */
	public static function status( bool $force = false ): array {
		$rows = array();
		foreach ( self::installed_plugins() as $file => $headers ) {
			$slug    = dirname( $file );
			$enabled = self::checks_enabled( $slug );
			$release = $enabled ? self::release( $slug, $force ) : self::empty_release( 'working_copy' );
			$state   = $release['status'];
			if ( 'ok' === $state ) {
				$state = version_compare( (string) $release['version'], $headers['Version'] ?? '0', '>' ) ? 'update' : 'current';
			}
			$rows[] = array(
				'slug'      => $slug,
				'file'      => $file,
				'name'      => (string) ( $headers['Name'] ?? $slug ),
				'installed' => (string) ( $headers['Version'] ?? '' ),
				'latest'    => (string) $release['version'],
				'state'     => $state,
				'code'      => (int) $release['code'],
				'checked'   => $enabled ? (int) $release['checked'] : 0,
				'page'      => (string) $release['page'],
			);
		}
		return $rows;
	}

	/**
	 * Kept for Windrose Helm 0.6.2 and older, which call it when a newer Windrose Common is loaded: no token any more.
	 *
	 * @return bool Always false.
	 */
	public static function has_token(): bool {
		return false;
	}

	/**
	 * Kept for Windrose Helm 0.6.2 and older: no token can be stored any more.
	 *
	 * @param string $token Ignored.
	 * @return WP_Error
	 */
	public static function save_token( #[\SensitiveParameter] string $token ): WP_Error {
		unset( $token );
		return new WP_Error( 'windrose_common_no_token', __( 'Windrose no longer uses a GitHub token: update Windrose Helm.', 'windrose-sabir' ) );
	}

	/**
	 * Kept for Windrose Helm 0.6.2 and older: nothing to delete.
	 *
	 * @return true
	 */
	public static function delete_token(): bool {
		return true;
	}

	/**
	 * A release with no data.
	 *
	 * @param string $status Status.
	 * @return array<string, int|string>
	 * @phpstan-return Release
	 */
	private static function empty_release( string $status ): array {
		return array(
			'status'  => $status,
			'code'    => 0,
			'checked' => time(),
			'version' => '',
			'tag'     => '',
			'package' => '',
			'digest'  => '',
			'size'    => 0,
			'notes'   => '',
			'page'    => '',
		);
	}

	/**
	 * A cached release with every key and type in place.
	 *
	 * @param array<mixed> $cached Cached value.
	 * @return array<string, int|string>
	 * @phpstan-return Release
	 */
	private static function normalize( array $cached ): array {
		$release = self::empty_release( 'error' );
		foreach ( $release as $key => $default ) {
			if ( isset( $cached[ $key ] ) && is_scalar( $cached[ $key ] ) ) {
				$release[ $key ] = is_int( $default ) ? (int) $cached[ $key ] : (string) $cached[ $key ];
			}
		}
		return $release;
	}
}
