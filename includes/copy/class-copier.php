<?php
/**
 * Copy: copies a post to another site of its language group as a **draft**,
 * linked as its translation in Multisite Language Switcher, so an editor there reviews (and translates) it before
 * publishing.
 *
 * Steps, from the source site:
 *
 * 1. check(): the Copy module is on; both sites are in the same language group; the post type may be copied from the
 *    source site (its Windrose Sabir settings); the person may read the post here and create posts of that type
 *    **on the target site**; the post has no translation there yet.
 * 2. A draft on the target site (title only), by the person who copies.
 * 3. Media (images, featured image): network items, sharing or file copies, as the person chose (Media).
 * 4. MSLS's content import pipeline (`ContentImporter::import_content()`) with Windrose Sabir's importers (Importers):
 *    fields, custom fields, terms, featured image. MSLS's import hooks run as usual.
 * 5. The translation links, written by Windrose Sabir (Links::link()): the new draft joins the source post's set.
 *
 * If something fails after the draft was made, the draft and the file copies made for it are deleted again; the site
 * that was current before is always restored (also after an add-on's importer that forgets to restore).
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir\Copy;

use lloc\Msls\ContentImport\ContentImporter;
use lloc\Msls\ContentImport\ImportCoordinates;
use lloc\Msls\ContentImport\ImportLogger;
use Throwable;
use Windrose\Sabir\Groups;
use Windrose\Sabir\Language_Item;
use Windrose\Sabir\Links;
use Windrose\Sabir\Modules;
use Windrose\Sabir\Site_Settings;
use WP_Error;
use WP_Post;
use WP_Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copies posts to other languages.
 *
 * @phpstan-type Result array{site: int, post: int, title: string, edit: string, images: list<array{source: int, title: string, how: string, reason: string, target: int, text: string}>, terms: list<array{name: string, taxonomy: string, how: string}>, notes: list<string>}
 */
final class Copier {

	/**
	 * Hooks the importers.
	 *
	 * @return void
	 */
	public static function init(): void {
		Importers::init();
	}

	/**
	 * Whether the person may copy a post to a site, and why not.
	 *
	 * @param WP_Post $post   Post on the current site.
	 * @param int     $target Target site ID.
	 * @return true|WP_Error
	 */
	public static function check( WP_Post $post, int $target ): bool|WP_Error {
		if ( ! Modules::on( 'copy' ) ) {
			return new WP_Error( 'windrose_sabir_copy_off', __( 'Copy is switched off on this network.', 'windrose-sabir' ), array( 'status' => 403 ) );
		}
		$source = get_current_blog_id();
		$group  = Groups::of_site( $source );
		if ( 0 === $group ) {
			return new WP_Error( 'windrose_sabir_copy_no_group', __( 'This site is in no language group.', 'windrose-sabir' ), array( 'status' => 400 ) );
		}
		if ( $target === $source || ! in_array( $target, Groups::sites( $group ), true ) ) {
			return new WP_Error( 'windrose_sabir_copy_target', __( 'This site is not in the language group of this site.', 'windrose-sabir' ), array( 'status' => 400 ) );
		}
		$site = get_site( $target );
		if ( ! $site instanceof WP_Site || '1' === (string) $site->archived || '1' === (string) $site->spam || '1' === (string) $site->deleted ) {
			return new WP_Error( 'windrose_sabir_copy_target', __( 'This site is archived or deleted.', 'windrose-sabir' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $post->post_type, Site_Settings::copy_post_types(), true ) ) {
			return new WP_Error( 'windrose_sabir_copy_type', __( 'This content type is not copied to other languages on this site.', 'windrose-sabir' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'windrose_sabir_copy_read', __( 'You may not read this post.', 'windrose-sabir' ), array( 'status' => 403 ) );
		}
		$type = get_post_type_object( $post->post_type );
		if ( null === $type || ! Job::on_site( $target, static fn(): bool => current_user_can( $type->cap->create_posts ) ) ) {
			return new WP_Error( 'windrose_sabir_copy_create', __( 'You may not create this kind of content on that site.', 'windrose-sabir' ), array( 'status' => 403 ) );
		}
		$existing = self::translation( $post, $target );
		if ( null !== $existing ) {
			return new WP_Error(
				'windrose_sabir_copy_exists',
				__( 'This post already has a translation on that site.', 'windrose-sabir' ),
				array(
					'status' => 409,
					'post'   => $existing['id'],
				)
			);
		}
		return true;
	}

	/**
	 * The post's translation on a site, when it is linked and exists there.
	 *
	 * @param WP_Post $post   Post on the current site.
	 * @param int     $target Site ID.
	 * @return array{id: int, title: string, status: string, edit: string, view: string}|null
	 */
	public static function translation( WP_Post $post, int $target ): ?array {
		$id = Links::read( get_current_blog_id(), Links::POST, $post->ID )[ Groups::locale( $target ) ] ?? 0;
		if ( $id <= 0 ) {
			return null;
		}
		return Job::on_site(
			$target,
			static function () use ( $id ): ?array {
				$found = get_post( $id );
				if ( ! $found instanceof WP_Post || in_array( $found->post_status, array( 'trash', 'auto-draft' ), true ) ) {
					return null;
				}
				// Title and status only for people who may read the translation on its own site (drafts, private
				// posts); a published one anyone can read on the web.
				$status   = get_post_status_object( $found->post_status );
				$can_read = ( null !== $status && $status->public ) || current_user_can( 'read_post', $id );
				return array(
					'id'     => $id,
					'title'  => $can_read ? get_the_title( $found ) : '',
					'status' => ! $can_read ? '' : ( null === $status ? $found->post_status : (string) $status->label ),
					'edit'   => current_user_can( 'edit_post', $id ) ? (string) get_edit_post_link( $id, 'raw' ) : '',
					'view'   => 'publish' === $found->post_status ? (string) get_permalink( $found ) : '',
				);
			}
		);
	}

	/**
	 * What a copy would do with the post's media (read only), for the editor.
	 *
	 * @param WP_Post $post   Post on the current site.
	 * @param int     $target Target site ID.
	 * @param string  $images network or copy.
	 * @return array{network: int, share: int, copy: int, keep: int}
	 */
	public static function preview( WP_Post $post, int $target, string $images = 'network' ): array {
		$media = new Media( get_current_blog_id(), $target, 0, $images );
		$media->classify( self::media_ids( $post ) );
		return $media->counts();
	}

	/**
	 * Attachment IDs a post uses: featured image and media blocks.
	 *
	 * @param WP_Post $post Post on the current site.
	 * @return list<int>
	 */
	private static function media_ids( WP_Post $post ): array {
		$ids       = Content::attachment_ids( $post->post_content );
		$thumbnail = (int) get_post_thumbnail_id( $post );
		if ( $thumbnail > 0 ) {
			array_unshift( $ids, $thumbnail );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Copies a post of the current site to another site of its group, as a linked draft.
	 *
	 * @param int    $post_id Post ID on the current site.
	 * @param int    $target  Target site ID.
	 * @param string $images  `network`: network items where possible (default); `copy`: copy the files.
	 * @return array<string, mixed>|WP_Error What was made, with a report.
	 * @phpstan-return Result|WP_Error
	 */
	public static function copy( int $post_id, int $target, string $images = 'network' ): array|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'windrose_sabir_copy_not_found', __( 'This post does not exist.', 'windrose-sabir' ), array( 'status' => 404 ) );
		}
		$allowed = self::check( $post, $target );
		if ( $allowed instanceof WP_Error ) {
			return $allowed;
		}

		$source        = get_current_blog_id();
		$source_locale = Groups::locale( $source );
		$target_locale = Groups::locale( $target );
		$depth         = self::depth();

		$draft = Job::on_site(
			$target,
			static fn() => wp_insert_post(
				wp_slash(
					array(
						'post_type'   => $post->post_type,
						'post_status' => 'draft',
						'post_title'  => $post->post_title,
						'post_author' => get_current_user_id(),
					)
				),
				true
			)
		);
		if ( $draft instanceof WP_Error ) {
			return $draft;
		}

		$job         = new Job( $post, $source, $source_locale, $target, $target_locale, (int) $draft, 'copy' === $images ? 'copy' : 'network' );
		$coordinates = new ImportCoordinates();

		$coordinates->source_blog_id = $source;
		$coordinates->source_post_id = $post->ID;
		$coordinates->dest_blog_id   = $target;
		$coordinates->dest_post_id   = (int) $draft;
		$coordinates->source_post    = $post;
		$coordinates->source_lang    = $source_locale;
		$coordinates->dest_lang      = $target_locale;
		foreach ( Importers::TYPES as $type => $class ) {
			// MSLS ignores its own default when called from code: name every importer.
			$coordinates->set_importer_for( $type, 'windrose-sabir' );
		}

		try {
			$job->media->classify( self::media_ids( $post ) );
			$job->media->apply();

			$job->attach( $coordinates );
			$importer = new ContentImporter();
			$importer->set_logger( new ImportLogger( $coordinates ) );
			$importer->set_relations( new Relations( $coordinates ) );
			$importer->import_content( $coordinates, array() );
			$job->detach( $coordinates );
			self::restore( $depth, $source );

			self::link( $post, $source, $source_locale, $target, $target_locale, (int) $draft );
		} catch ( Throwable $error ) {
			$job->detach( $coordinates );
			self::restore( $depth, $source );
			$created = $job->media->created;
			Job::on_site(
				$target,
				static function () use ( $draft, $created ): void {
					foreach ( $created as $attachment ) {
						wp_delete_attachment( $attachment, true );
					}
					wp_delete_post( (int) $draft, true );
				}
			);
			return new WP_Error(
				'windrose_sabir_copy_failed',
				/* translators: %s: error message. */
				sprintf( __( 'The copy failed and was undone: %s', 'windrose-sabir' ), $error->getMessage() ),
				array( 'status' => 500 )
			);
		}//end try

		$made = self::translation( $post, $target );
		return array(
			'site'   => $target,
			'post'   => (int) $draft,
			'title'  => null === $made ? $post->post_title : $made['title'],
			'edit'   => null === $made ? '' : $made['edit'],
			'images' => $job->media->report(),
			'terms'  => $job->report['terms'],
			'notes'  => $job->report['notes'],
		);
	}

	/**
	 * Links the new draft into the source post's set of translations (only members that exist in the group).
	 *
	 * @param WP_Post $post          Source post.
	 * @param int     $source        Source site ID.
	 * @param string  $source_locale Source language.
	 * @param int     $target        Target site ID.
	 * @param string  $target_locale Target language.
	 * @param int     $draft         New post ID.
	 * @return void
	 */
	private static function link( WP_Post $post, int $source, string $source_locale, int $target, string $target_locale, int $draft ): void {
		$sites   = Links::sites_by_locale( Groups::of_site( $source ) );
		$members = array(
			$source_locale => array(
				'site' => $source,
				'id'   => $post->ID,
			),
		);
		foreach ( Links::read( $source, Links::POST, $post->ID ) as $locale => $id ) {
			if ( $locale === $source_locale || $locale === $target_locale || ! isset( $sites[ $locale ] ) ) {
				continue;
			}
			$exists = Job::on_site( $sites[ $locale ], static fn(): bool => get_post( $id ) instanceof WP_Post );
			if ( $exists ) {
				$members[ $locale ] = array(
					'site' => $sites[ $locale ],
					'id'   => $id,
				);
			}
		}
		$members[ $target_locale ] = array(
			'site' => $target,
			'id'   => $draft,
		);
		Links::link( Links::POST, $members );
	}

	/**
	 * How many site switches are open.
	 *
	 * @return int
	 */
	private static function depth(): int {
		return count( is_array( $GLOBALS['_wp_switched_stack'] ?? null ) ? $GLOBALS['_wp_switched_stack'] : array() );
	}

	/**
	 * Restores the site that was current before the copy, whatever an importer left switched.
	 *
	 * @param int $depth  Switch depth before the copy.
	 * @param int $source Site that was current.
	 * @return void
	 */
	private static function restore( int $depth, int $source ): void {
		$current = self::depth();
		while ( $current > $depth ) {
			restore_current_blog();
			$current = self::depth();
		}
		if ( get_current_blog_id() !== $source ) {
			switch_to_blog( $source );
		}
		kses_init();
	}

	/**
	 * The other sites of the current site's group, for the editor and the post lists.
	 *
	 * @param WP_Post|null $post Post (null for the site list only).
	 * @return list<array{id: int, name: string, locale: string, language: string, translation: array{id: int, title: string, status: string, edit: string, view: string}|null, can: bool, reason: string, media: array{network: int, share: int, copy: int, keep: int}, mediaCopy: array{network: int, share: int, copy: int, keep: int}}>
	 */
	public static function targets( ?WP_Post $post ): array {
		$current = get_current_blog_id();
		$list    = array();
		$sites   = Groups::sites( Groups::of_site( $current ), true );
		$locales = array_map( array( Groups::class, 'locale' ), $sites );
		foreach ( $sites as $site ) {
			if ( $site === $current ) {
				continue;
			}
			$locale = Groups::locale( $site );
			$row    = array(
				'id'          => $site,
				'name'        => Groups::site_label( $site ),
				'locale'      => $locale,
				'language'    => Language_Item::name( $locale, $locales ),
				'translation' => null,
				'can'         => false,
				'reason'      => '',
				'media'       => array(
					'network' => 0,
					'share'   => 0,
					'copy'    => 0,
					'keep'    => 0,
				),
				'mediaCopy'   => array(
					'network' => 0,
					'share'   => 0,
					'copy'    => 0,
					'keep'    => 0,
				),
			);
			if ( null !== $post ) {
				$row['translation'] = self::translation( $post, $site );
				if ( null === $row['translation'] ) {
					$check         = self::check( $post, $site );
					$row['can']    = true === $check;
					$row['reason'] = $check instanceof WP_Error ? $check->get_error_message() : '';
					if ( $row['can'] ) {
						$row['media']     = self::preview( $post, $site );
						$row['mediaCopy'] = self::preview( $post, $site, 'copy' );
					}
				}
			}
			$list[] = $row;
		}//end foreach
		return $list;
	}
}
