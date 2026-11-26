<?php
/**
 * Images and other media of a copied post (featured image and media blocks).
 *
 * For each media item of the source post, Copy chooses one way (classify(), read only, also used for the editor's
 * preview), then makes it happen (apply()):
 *
 * | Way        | When                                                                                 | Result on the target site |
 * |------------|--------------------------------------------------------------------------------------|---------------------------|
 * | `hub-item` | The target site is the item's media hub                                               | The hub's own item         |
 * | `network`  | Windrose Anchors: the target site uses network media, the item is a shared item of its hub (or a network item of that hub) | A network item (existing or new) |
 * | `share`    | Same, but the hub item is not shared yet and the person may upload on the hub         | Shared, then a network item |
 * | `copy`     | Chosen by the person ("Copy the files"), or no network item is possible (no Anchors, another hub, not allowed, an unpublished post the person may not read there) | A copy of the file in the site's media library, or an earlier copy of the same file |
 * | `keep`     | The person may not upload on the target site, or the file cannot be found            | No media item: the content keeps the file's address |
 *
 * An item attached to a post not published yet on the hub (a draft prepared in several languages) becomes a network
 * item too, with Windrose Anchors 0.5.0 or later, when the person may read that post on the hub.
 *
 * A file copy stores where it came from (`_windrose_sabir_source` = "<site ID>:<attachment ID>" of the real file), so
 * a later copy of the same image to the same site reuses it. Only files inside the source site's uploads folder are
 * copied, through core's sideload (the target site's file types, sizes and KSES rules apply). MSLS's own `_msls_linked`
 * is never written on a copy (MSLS reads it as a link to another site's file).
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir\Copy;

use Windrose\Common\Files;
use Windrose\Sabir\Groups;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media of a copy.
 *
 * @phpstan-type Plan array{source: int, title: string, how: string, reason: string, owner_site: int, owner_id: int, hub_site: int, target: int, message: string, unpublished: bool}
 */
final class Media {

	public const SOURCE_META = '_windrose_sabir_source';

	/**
	 * Ways by source attachment ID.
	 *
	 * @var array<int, array{source: int, title: string, how: string, reason: string, owner_site: int, owner_id: int, hub_site: int, target: int, message: string, unpublished: bool}>
	 */
	private array $plans = array();

	/**
	 * Source attachment ID => target attachment ID (0: reference removed). Filled by apply().
	 *
	 * @var array<int, int>
	 */
	public array $map = array();

	/**
	 * Source attachment ID => [ source file name => target file URL ], for file copies. Filled by apply().
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $files = array();

	/**
	 * File copies made by this copy (deleted again if the copy fails).
	 *
	 * @var list<int>
	 */
	public array $created = array();

	/**
	 * The address of the site the request is for (core builds upload URLs from it, also while switched).
	 *
	 * @var string
	 */
	private string $request_siteurl;

	/**
	 * Media of one copy.
	 *
	 * @param int    $source_site Source site ID.
	 * @param int    $target_site Target site ID.
	 * @param int    $target_post The draft on the target site (0 for a preview).
	 * @param string $images      `network` (network items where possible, default) or `copy` (copy the files).
	 */
	public function __construct( private int $source_site, private int $target_site, private int $target_post, private string $images = 'network' ) {
		$this->request_siteurl = untrailingslashit( (string) get_option( 'siteurl' ) );
	}

	/**
	 * Chooses the way of each media item (read only).
	 *
	 * @param int[] $ids Attachment IDs on the source site.
	 * @return array<int, array{source: int, title: string, how: string, reason: string, owner_site: int, owner_id: int, hub_site: int, target: int, message: string, unpublished: bool}>
	 */
	public function classify( array $ids ): array {
		foreach ( $ids as $id ) {
			if ( $id > 0 && ! isset( $this->plans[ $id ] ) ) {
				$this->plans[ $id ] = $this->plan( $id );
			}
		}
		return $this->plans;
	}

	/**
	 * Number of media items by way.
	 *
	 * @return array{network: int, share: int, copy: int, keep: int}
	 */
	public function counts(): array {
		$counts = array(
			'network' => 0,
			'share'   => 0,
			'copy'    => 0,
			'keep'    => 0,
		);
		foreach ( $this->plans as $plan ) {
			if ( 'hub-item' === $plan['how'] || 'network' === $plan['how'] ) {
				++$counts['network'];
			} elseif ( 'share' === $plan['how'] ) {
				++$counts['share'];
			} elseif ( 'copy' === $plan['how'] ) {
				++$counts['copy'];
			} else {
				++$counts['keep'];
			}
		}
		return $counts;
	}

	/**
	 * The way of one media item.
	 *
	 * @param int $id Attachment ID on the source site.
	 * @return array{source: int, title: string, how: string, reason: string, owner_site: int, owner_id: int, hub_site: int, target: int, message: string, unpublished: bool}
	 */
	private function plan( int $id ): array {
		$plan = array(
			'source'      => $id,
			'title'       => '',
			'how'         => 'keep',
			'reason'      => 'missing',
			'owner_site'  => $this->source_site,
			'owner_id'    => $id,
			'hub_site'    => 0,
			'target'      => 0,
			'message'     => '',
			'unpublished' => false,
		);
		$item = Job::on_site( $this->source_site, static fn(): ?array => self::read( $id ) );
		if ( null === $item ) {
			return $plan;
		}
		$plan['title'] = $item['title'];
		if ( $item['hub_id'] > 0 ) {
			// A network item on the source site: its file belongs to its hub.
			$plan['owner_site'] = $item['hub_site'];
			$plan['owner_id']   = $item['hub_id'];
		}

		$can_upload = Job::on_site( $this->target_site, static fn(): bool => current_user_can( 'upload_files' ) );
		$reason     = $this->network_way( $plan, $item, $can_upload );
		if ( '' === $reason ) {
			return $plan;
		}
		return $this->fallback( $plan, $reason, $can_upload );
	}

	/**
	 * Tries a network item (Windrose Anchors). Fills the plan and returns '' when one fits, else the reason for a
	 * file copy.
	 *
	 * @param array<string, mixed>                             $plan       Plan so far.
	 * @phpstan-param Plan $plan
	 * @param array{title: string, hub_id: int, hub_site: int} $item       Source item.
	 * @param bool                                             $can_upload Whether the person may upload on the target site.
	 * @return string
	 */
	private function network_way( array &$plan, array $item, bool $can_upload ): string {
		if ( ! Network_Media::available() ) {
			return 'no-anchors';
		}
		$hub = Network_Media::hub_for( $this->target_site );
		if ( $hub === $this->target_site ) {
			if ( $item['hub_id'] > 0 && $item['hub_site'] === $hub ) {
				$plan['how']      = 'hub-item';
				$plan['reason']   = '';
				$plan['hub_site'] = $hub;
				$plan['target']   = $item['hub_id'];
				return '';
			}
			return 'other-hub';
		}
		if ( 'copy' === $this->images ) {
			return 'chosen';
		}
		if ( ! Network_Media::site_allowed( $this->target_site ) ) {
			return 'not-allowed';
		}
		if ( $item['hub_id'] > 0 && $item['hub_site'] === $hub ) {
			$hub_id = $item['hub_id'];
		} elseif ( 0 === $item['hub_id'] && $this->source_site === $hub ) {
			$hub_id = $plan['source'];
		} else {
			return 'other-hub';
		}
		if ( ! $can_upload ) {
			return 'no-upload';
		}
		$plan['hub_site'] = $hub;
		$plan['owner_id'] = $hub_id;
		$existing         = Job::on_site( $this->target_site, static fn(): int => Network_Media::find( $hub_id, $hub ) );
		if ( $existing > 0 ) {
			$plan['how']    = 'network';
			$plan['reason'] = 'existing';
			$plan['target'] = $existing;
			return '';
		}
		if ( ! Network_Media::is_listable( $hub_id, $hub ) ) {
			// Attached to a post not published yet (a draft prepared in several languages): a network item still,
			// when Anchors allows it and the person may read that post on the hub.
			if ( ! Network_Media::unpublished_ok( $hub_id, $hub ) ) {
				return 'unpublished';
			}
			$plan['unpublished'] = true;
		}
		if ( Network_Media::is_shared( $hub_id, $hub ) ) {
			$plan['how']    = 'network';
			$plan['reason'] = '';
			return '';
		}
		if ( Job::on_site( $hub, static fn(): bool => current_user_can( 'upload_files' ) ) ) {
			$plan['how']    = 'share';
			$plan['reason'] = '';
			return '';
		}
		return 'cannot-share';
	}

	/**
	 * A file copy, an earlier copy, or nothing.
	 *
	 * @param array<string, mixed> $plan       Plan so far.
	 * @phpstan-param Plan $plan
	 * @param string               $reason     Why no network item.
	 * @param bool                 $can_upload Whether the person may upload on the target site.
	 * @return array{source: int, title: string, how: string, reason: string, owner_site: int, owner_id: int, hub_site: int, target: int, message: string, unpublished: bool}
	 */
	private function fallback( array $plan, string $reason, bool $can_upload ): array {
		$plan['hub_site'] = 0;
		$plan['target']   = 0;
		if ( ! $can_upload ) {
			$plan['how']    = 'keep';
			$plan['reason'] = 'no-upload';
			return $plan;
		}
		// The file of a network item belongs to its hub (owner_id was set to the hub item above).
		$owner_site = $plan['owner_site'];
		$owner_id   = $plan['owner_id'];
		$earlier    = Job::on_site( $this->target_site, static fn(): int => self::earlier_copy( $owner_site, $owner_id ) );
		if ( $earlier > 0 ) {
			$plan['how']    = 'copy';
			$plan['reason'] = 'earlier';
			$plan['target'] = $earlier;
			return $plan;
		}
		if ( '' === self::source_file( $owner_site, $owner_id )['path'] ) {
			$plan['how']    = 'keep';
			$plan['reason'] = 'no-file';
			return $plan;
		}
		$plan['how']    = 'copy';
		$plan['reason'] = $reason;
		return $plan;
	}

	/**
	 * Reads a source attachment (on its site).
	 *
	 * @param int $id Attachment ID.
	 * @return array{title: string, hub_id: int, hub_site: int}|null
	 */
	private static function read( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}
		$hub_id   = max( 0, (int) get_post_meta( $id, Network_Media::HUB_META, true ) );
		$link     = get_post_meta( $id, '_msls_linked', true );
		$hub_site = 0;
		if ( $hub_id > 0 ) {
			$hub_site = is_array( $link ) && (int) ( $link['blog'] ?? 0 ) > 0 ? (int) $link['blog'] : (int) get_main_site_id();
		}
		return array(
			'title'    => $post->post_title,
			'hub_id'   => $hub_id,
			'hub_site' => $hub_site,
		);
	}

	/**
	 * An earlier file copy of the same file on the current site.
	 *
	 * @param int $owner_site Site of the real file.
	 * @param int $owner_id   Its attachment ID.
	 * @return int
	 */
	private static function earlier_copy( int $owner_site, int $owner_id ): int {
		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'fields'      => 'ids',
				'numberposts' => 1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'meta_key'    => self::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only while copying a post.
				'meta_value'  => $owner_site . ':' . $owner_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same.
			)
		);
		return array() === $ids ? 0 : (int) $ids[0];
	}

	/**
	 * The real file of an attachment (its original for images), checked to be inside its site's uploads folder.
	 *
	 * @param int $site Site ID.
	 * @param int $id   Attachment ID.
	 * @return array{path: string, names: array<string, string>, alt: string, caption: string, description: string, title: string}
	 */
	private static function source_file( int $site, int $id ): array {
		return Job::on_site(
			$site,
			static function () use ( $id ): array {
				$empty = array(
					'path'        => '',
					'names'       => array(),
					'alt'         => '',
					'caption'     => '',
					'description' => '',
					'title'       => '',
				);
				$post  = get_post( $id );
				if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
					return $empty;
				}
				$attached = (string) get_attached_file( $id, true );
				$original = wp_attachment_is_image( $id ) ? wp_get_original_image_path( $id, true ) : false;
				$path     = is_string( $original ) && '' !== $original && is_readable( $original ) ? $original : $attached;
				$base     = realpath( (string) wp_upload_dir( null, false )['basedir'] );
				$real     = '' === $path ? false : realpath( $path );
				if ( false === $base || false === $real || ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) {
					return $empty;
				}
				// File names of the source (attached file, original, sizes) by size name, for the address map.
				$names = array( '' => wp_basename( $attached ) );
				$meta  = wp_get_attachment_metadata( $id, true );
				if ( is_array( $meta ) ) {
					if ( is_string( $meta['original_image'] ?? null ) ) {
						$names['original'] = $meta['original_image'];
					}
					foreach ( is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array() as $size => $data ) {
						if ( is_array( $data ) && is_string( $data['file'] ?? null ) ) {
							$names[ 'size:' . $size ] = $data['file'];
						}
					}
				}
				return array(
					'path'        => $real,
					'names'       => $names,
					'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
					'caption'     => $post->post_excerpt,
					'description' => $post->post_content,
					'title'       => $post->post_title,
				);
			}
		);
	}

	/**
	 * Makes every chosen way happen: network items, sharing, file copies. A way that fails falls back to a file copy
	 * (or to keeping the address), and the report says why.
	 *
	 * @return void
	 */
	public function apply(): void {
		foreach ( $this->plans as $id => $plan ) {
			$plan               = $this->apply_one( $plan );
			$this->plans[ $id ] = $plan;
			$this->map[ $id ]   = 'keep' === $plan['how'] ? 0 : $plan['target'];
		}
	}

	/**
	 * Makes one way happen.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @phpstan-param Plan $plan
	 * @return array{source: int, title: string, how: string, reason: string, owner_site: int, owner_id: int, hub_site: int, target: int, message: string, unpublished: bool}
	 */
	private function apply_one( array $plan ): array {
		if ( 'share' === $plan['how'] ) {
			$hub_id = $plan['owner_id'];
			$shared = Job::on_site( $plan['hub_site'], static fn() => current_user_can( 'upload_files' ) ? Network_Media::share( $hub_id, (int) get_current_blog_id() ) : new WP_Error( 'windrose_sabir_copy_share', __( 'You may not share media on the media hub.', 'windrose-sabir' ) ) );
			if ( $shared instanceof WP_Error ) {
				$plan['message'] = $shared->get_error_message();
				return $this->apply_copy( $plan, 'network-failed' );
			}
		}
		if ( 'share' === $plan['how'] || ( 'network' === $plan['how'] && 0 === $plan['target'] ) ) {
			$hub_id      = $plan['owner_id'];
			$parent      = $this->target_post;
			$unpublished = $plan['unpublished'];
			$result      = Job::on_site( $this->target_site, static fn() => Network_Media::create( $hub_id, $parent, $unpublished ) );
			if ( $result instanceof WP_Error ) {
				$plan['message'] = $result->get_error_message();
				return $this->apply_copy( $plan, 'network-failed' );
			}
			$plan['target'] = $result['id'];
			return $plan;
		}
		if ( 'copy' === $plan['how'] ) {
			if ( $plan['target'] > 0 ) {
				$this->files[ $plan['source'] ] = $this->file_map( $plan['owner_site'], $plan['owner_id'], $plan['target'] );
				return $plan;
			}
			return $this->apply_copy( $plan, $plan['reason'] );
		}
		return $plan;
	}

	/**
	 * Copies the file into the target site's media library.
	 *
	 * @param array<string, mixed> $plan   Plan.
	 * @phpstan-param Plan $plan
	 * @param string               $reason Why a copy.
	 * @return array{source: int, title: string, how: string, reason: string, owner_site: int, owner_id: int, hub_site: int, target: int, message: string, unpublished: bool}
	 */
	private function apply_copy( array $plan, string $reason ): array {
		$plan['how']    = 'copy';
		$plan['reason'] = $reason;
		$plan['target'] = 0;
		if ( ! Job::on_site( $this->target_site, static fn(): bool => current_user_can( 'upload_files' ) ) ) {
			$plan['how']    = 'keep';
			$plan['reason'] = 'no-upload';
			return $plan;
		}
		$owner_site = $plan['owner_site'];
		$owner_id   = $plan['owner_id'];
		$earlier    = Job::on_site( $this->target_site, static fn(): int => self::earlier_copy( $owner_site, $owner_id ) );
		if ( $earlier > 0 ) {
			$plan['target']                 = $earlier;
			$plan['reason']                 = 'earlier';
			$this->files[ $plan['source'] ] = $this->file_map( $owner_site, $owner_id, $earlier );
			return $plan;
		}
		$file = self::source_file( $owner_site, $owner_id );
		if ( '' === $file['path'] ) {
			$plan['how']    = 'keep';
			$plan['reason'] = 'no-file';
			return $plan;
		}
		// Core's sideload functions are admin includes, not loaded for REST or WP-CLI requests.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$name = wp_basename( $file['path'] );
		$tmp  = wp_tempnam( $name );
		if ( '' === $tmp || ! Files::copy( $file['path'], $tmp, true ) ) {
			$plan['how']    = 'keep';
			$plan['reason'] = 'no-file';
			return $plan;
		}
		$parent = $this->target_post;
		$id     = Job::on_site(
			$this->target_site,
			static function () use ( $file, $name, $tmp, $parent, $owner_site, $owner_id ) {
				$id = media_handle_sideload(
					array(
						'name'     => $name,
						'tmp_name' => $tmp,
					),
					$parent,
					null,
					wp_slash(
						array(
							'post_title'   => $file['title'],
							'post_excerpt' => $file['caption'],
							'post_content' => $file['description'],
						)
					)
				);
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				if ( '' !== $file['alt'] ) {
					update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( wp_strip_all_tags( $file['alt'], true ) ) );
				}
				update_post_meta( $id, self::SOURCE_META, $owner_site . ':' . $owner_id );
				return $id;
			}
		);
		if ( is_file( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		if ( $id instanceof WP_Error ) {
			$plan['how']     = 'keep';
			$plan['reason']  = 'copy-failed';
			$plan['message'] = $id->get_error_message();
			return $plan;
		}
		$this->created[]                = $id;
		$plan['target']                 = $id;
		$this->files[ $plan['source'] ] = $this->file_map( $owner_site, $owner_id, $id );
		return $plan;
	}

	/**
	 * Source file name => URL of the matching file of the copy (same size name, else the full file).
	 *
	 * @param int $owner_site Site of the real file.
	 * @param int $owner_id   Its attachment ID.
	 * @param int $copy       The copy on the target site.
	 * @return array<string, string>
	 */
	private function file_map( int $owner_site, int $owner_id, int $copy ): array {
		$names           = self::source_file( $owner_site, $owner_id )['names'];
		$request_siteurl = $this->request_siteurl;
		$target          = Job::on_site(
			$this->target_site,
			static function () use ( $copy, $request_siteurl ): array {
				$url  = (string) wp_get_attachment_url( $copy );
				$meta = wp_get_attachment_metadata( $copy );
				// Core builds upload URLs from the address of the site the request is for, also while switched.
				$own = untrailingslashit( (string) get_option( 'siteurl' ) );
				if ( '' !== $url && $own !== $request_siteurl && str_starts_with( $url, $request_siteurl . '/' ) ) {
					$url = $own . substr( $url, strlen( $request_siteurl ) );
				}
				$folder = trailingslashit( dirname( $url ) );
				$files  = array(
					''         => $url,
					'original' => is_array( $meta ) && is_string( $meta['original_image'] ?? null ) ? $folder . $meta['original_image'] : $url,
				);
				foreach ( is_array( $meta ) && is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array() as $size => $data ) {
					if ( is_array( $data ) && is_string( $data['file'] ?? null ) ) {
						$files[ 'size:' . $size ] = $folder . $data['file'];
					}
				}
				return $files;
			}
		);
		$map             = array();
		foreach ( $names as $key => $name ) {
			$map[ $name ] = $target[ $key ] ?? $target[''];
		}
		return array_filter( $map, static fn( string $url, string $name ): bool => '' !== $url && '' !== $name, ARRAY_FILTER_USE_BOTH );
	}

	/**
	 * The report: one line per media item.
	 *
	 * @return list<array{source: int, title: string, how: string, reason: string, target: int, text: string}>
	 */
	public function report(): array {
		$lines = array();
		foreach ( $this->plans as $plan ) {
			$lines[] = array(
				'source' => $plan['source'],
				'title'  => $plan['title'],
				'how'    => $plan['how'],
				'reason' => $plan['reason'],
				'target' => $plan['target'],
				'text'   => $this->describe( $plan ),
			);
		}
		return $lines;
	}

	/**
	 * One media item's way, in a sentence.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @phpstan-param Plan $plan
	 * @return string
	 */
	private function describe( array $plan ): string {
		$hub = $plan['hub_site'] > 0 ? Groups::site_label( $plan['hub_site'] ) : '';
		switch ( $plan['how'] ) {
			case 'hub-item':
				return __( 'The media hub’s own item.', 'windrose-sabir' );
			case 'network':
				/* translators: %s: media hub site name and address. */
				return sprintf( __( 'Network item from %s.', 'windrose-sabir' ), $hub );
			case 'share':
				/* translators: %s: media hub site name and address. */
				return sprintf( __( 'Shared with the network on %s, then added as a network item.', 'windrose-sabir' ), $hub );
			case 'copy':
				$reasons = array(
					'earlier'        => __( 'An earlier copy of the same file is used.', 'windrose-sabir' ),
					'chosen'         => __( 'Copied as a file, as chosen.', 'windrose-sabir' ),
					'no-anchors'     => __( 'Copied as a file.', 'windrose-sabir' ),
					'not-allowed'    => __( 'Copied as a file: this site does not use network media.', 'windrose-sabir' ),
					'other-hub'      => __( 'Copied as a file: it is not an item of this site’s media hub.', 'windrose-sabir' ),
					'unpublished'    => __( 'Copied as a file: it is attached to a post that is not published on the media hub, which you may not read there (or Windrose Anchors is older than 0.5.0).', 'windrose-sabir' ),
					'cannot-share'   => __( 'Copied as a file: it is not shared with the network, and you may not share it.', 'windrose-sabir' ),
					/* translators: %s: error message. */
					'network-failed' => sprintf( __( 'Copied as a file: the network item could not be added (%s).', 'windrose-sabir' ), $plan['message'] ),
				);
				return $reasons[ $plan['reason'] ] ?? __( 'Copied as a file.', 'windrose-sabir' );
			default:
				$reasons = array(
					'no-upload'   => __( 'Not copied: you may not upload files on this site. The content keeps the file’s address on the other site.', 'windrose-sabir' ),
					'no-file'     => __( 'Not copied: its file was not found. The content keeps the file’s address.', 'windrose-sabir' ),
					'missing'     => __( 'Not copied: this media item no longer exists. The content keeps the file’s address.', 'windrose-sabir' ),
					/* translators: %s: error message. */
					'copy-failed' => sprintf( __( 'Not copied (%s). The content keeps the file’s address.', 'windrose-sabir' ), $plan['message'] ),
				);
				return $reasons[ $plan['reason'] ] ?? '';
		}//end switch
	}
}
