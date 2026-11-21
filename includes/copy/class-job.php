<?php
/**
 * One copy of a post to one site: what the importers need, and the report.
 *
 * Every write on the target site runs through on_target(): it switches to the target site and sets up core's KSES
 * filters for the current user **there** (so copied content passes the target site's rules unless the user has
 * `unfiltered_html` there), then restores the site and the filters, also when something fails.
 *
 * Multisite Language Switcher's importers find their job from the import coordinates (of()).
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir\Copy;

use lloc\Msls\ContentImport\ImportCoordinates;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A copy in progress.
 */
final class Job {

	/**
	 * Running jobs by coordinates object.
	 *
	 * @var array<int, Job>
	 */
	private static array $running = array();

	/**
	 * Media of the copy.
	 *
	 * @var Media
	 */
	public Media $media;

	/**
	 * What happened, for the person who copies: terms and other notes (images are in $media).
	 *
	 * @var array{terms: list<array{name: string, taxonomy: string, how: string}>, notes: list<string>}
	 */
	public array $report = array(
		'terms' => array(),
		'notes' => array(),
	);

	/**
	 * Starts a job.
	 *
	 * @param WP_Post $source        Source post (on the source site).
	 * @param int     $source_site   Source site ID.
	 * @param string  $source_locale Source site language.
	 * @param int     $target_site   Target site ID.
	 * @param string  $target_locale Target site language.
	 * @param int     $target_id     The draft on the target site.
	 * @param string  $images        network or copy (Media).
	 */
	public function __construct(
		public readonly WP_Post $source,
		public readonly int $source_site,
		public readonly string $source_locale,
		public readonly int $target_site,
		public readonly string $target_locale,
		public readonly int $target_id,
		string $images = 'network',
	) {
		$this->media = new Media( $source_site, $target_site, $target_id, $images );
	}

	/**
	 * Registers the job for MSLS's importers (by its coordinates).
	 *
	 * @param ImportCoordinates $coordinates Coordinates of the import.
	 * @return void
	 */
	public function attach( ImportCoordinates $coordinates ): void {
		self::$running[ spl_object_id( $coordinates ) ] = $this;
	}

	/**
	 * Unregisters the job.
	 *
	 * @param ImportCoordinates $coordinates Coordinates of the import.
	 * @return void
	 */
	public function detach( ImportCoordinates $coordinates ): void {
		unset( self::$running[ spl_object_id( $coordinates ) ] );
	}

	/**
	 * The job of an import, or null when the import is not a Windrose Sabir copy.
	 *
	 * @param mixed $coordinates Import coordinates.
	 * @return Job|null
	 */
	public static function of( $coordinates ): ?Job {
		return $coordinates instanceof ImportCoordinates ? ( self::$running[ spl_object_id( $coordinates ) ] ?? null ) : null;
	}

	/**
	 * Runs code on the target site, with the target site's KSES rules for the current user.
	 *
	 * @template T
	 * @param callable(): T $callback Code.
	 * @return T
	 */
	public function on_target( callable $callback ) {
		return self::on_site( $this->target_site, $callback );
	}

	/**
	 * Runs code on a site, with that site's KSES rules for the current user.
	 *
	 * @template T
	 * @param int           $site     Site ID.
	 * @param callable(): T $callback Code.
	 * @return T
	 */
	public static function on_site( int $site, callable $callback ) {
		switch_to_blog( $site );
		kses_init();
		try {
			return $callback();
		} finally {
			restore_current_blog();
			kses_init();
		}
	}

	/**
	 * Runs code on the source site.
	 *
	 * @template T
	 * @param callable(): T $callback Code.
	 * @return T
	 */
	public function on_source( callable $callback ) {
		return self::on_site( $this->source_site, $callback );
	}

	/**
	 * Adds a note to the report.
	 *
	 * @param string $note Sentence.
	 * @return void
	 */
	public function note( string $note ): void {
		$this->report['notes'][] = $note;
	}
}
