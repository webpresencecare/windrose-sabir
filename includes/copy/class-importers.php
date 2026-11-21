<?php
/**
 * Windrose Sabir's importers for Multisite Language Switcher's content import pipeline, one per import type, used only
 * for Windrose Sabir's own copies (Importers::init() hooks `msls_content_import_<type>_importer`; any other import
 * keeps MSLS's importers). They replace MSLS's own importers because those switch sites without restoring (also in
 * the post meta and terms importers) and read the post type from the request, and Copy places the featured image
 * itself. Add-ons still run their importers through
 * `msls_content_import_importers_map`, and MSLS's before and after import actions and data filters run as usual.
 *
 * | Type             | Importer                                                                 |
 * |------------------|--------------------------------------------------------------------------|
 * | `post-fields`    | Importer_Fields: title, content (media moved), excerpt, parent, order, discussion |
 * | `post-meta`      | Importer_Meta: allow list (`windrose_sabir_copy_meta_keys`)               |
 * | `terms`          | Importer_Terms: Terms::apply()                                            |
 * | `post-thumbnail` | Importer_Thumbnail: the featured image as moved by Media                  |
 * | `attachments`    | MSLS's base importer (does nothing: media are handled with the content)   |
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir\Copy;

use lloc\Msls\ContentImport\Importers\BaseImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importer registration.
 */
final class Importers {

	/** Import types and Windrose Sabir's importer for each. */
	public const TYPES = array(
		'post-fields'    => Importer_Fields::class,
		'post-meta'      => Importer_Meta::class,
		'terms'          => Importer_Terms::class,
		'post-thumbnail' => Importer_Thumbnail::class,
		'attachments'    => BaseImporter::class,
	);

	/**
	 * Hooks the importer filters and silences MSLS's admin notice log for Windrose Sabir's copies.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'msls_content_import_post-fields_importer', array( __CLASS__, 'importer_post_fields' ), 10, 2 );
		add_filter( 'msls_content_import_post-meta_importer', array( __CLASS__, 'importer_post_meta' ), 10, 2 );
		add_filter( 'msls_content_import_terms_importer', array( __CLASS__, 'importer_terms' ), 10, 2 );
		add_filter( 'msls_content_import_post-thumbnail_importer', array( __CLASS__, 'importer_post_thumbnail' ), 10, 2 );
		add_filter( 'msls_content_import_attachments_importer', array( __CLASS__, 'importer_attachments' ), 10, 2 );
		add_filter( 'msls_content_import_log_writer', array( __CLASS__, 'log_writer' ), 10, 2 );
	}

	/**
	 * Windrose Sabir's importer for a type, or the value unchanged when the import is not a Windrose Sabir copy.
	 *
	 * @param string $type        Import type.
	 * @param mixed  $importer    Importer so far (null: MSLS chooses).
	 * @param mixed  $coordinates Import coordinates.
	 * @return mixed
	 */
	private static function importer( string $type, $importer, $coordinates ) {
		if ( null === Job::of( $coordinates ) || ! $coordinates instanceof \lloc\Msls\ContentImport\ImportCoordinates ) {
			return $importer;
		}
		$class = self::TYPES[ $type ];
		return new $class( $coordinates );
	}

	/**
	 * Filter for `post-fields`.
	 *
	 * @param mixed $importer    Importer so far.
	 * @param mixed $coordinates Import coordinates.
	 * @return mixed
	 */
	public static function importer_post_fields( $importer, $coordinates ) {
		return self::importer( 'post-fields', $importer, $coordinates );
	}

	/**
	 * Filter for `post-meta`.
	 *
	 * @param mixed $importer    Importer so far.
	 * @param mixed $coordinates Import coordinates.
	 * @return mixed
	 */
	public static function importer_post_meta( $importer, $coordinates ) {
		return self::importer( 'post-meta', $importer, $coordinates );
	}

	/**
	 * Filter for `terms`.
	 *
	 * @param mixed $importer    Importer so far.
	 * @param mixed $coordinates Import coordinates.
	 * @return mixed
	 */
	public static function importer_terms( $importer, $coordinates ) {
		return self::importer( 'terms', $importer, $coordinates );
	}

	/**
	 * Filter for `post-thumbnail`.
	 *
	 * @param mixed $importer    Importer so far.
	 * @param mixed $coordinates Import coordinates.
	 * @return mixed
	 */
	public static function importer_post_thumbnail( $importer, $coordinates ) {
		return self::importer( 'post-thumbnail', $importer, $coordinates );
	}

	/**
	 * Filter for `attachments`.
	 *
	 * @param mixed $importer    Importer so far.
	 * @param mixed $coordinates Import coordinates.
	 * @return mixed
	 */
	public static function importer_attachments( $importer, $coordinates ) {
		return self::importer( 'attachments', $importer, $coordinates );
	}

	/**
	 * No MSLS admin notice log for Windrose Sabir's copies: Copy reports to the person itself.
	 *
	 * @param mixed $writer      Log writer.
	 * @param mixed $coordinates Import coordinates.
	 * @return mixed
	 */
	public static function log_writer( $writer, $coordinates ) {
		return null === Job::of( $coordinates ) ? $writer : false;
	}
}
