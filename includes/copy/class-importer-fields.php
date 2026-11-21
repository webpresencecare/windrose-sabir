<?php
/**
 * Post fields of a copy: title, content (media references moved, Content), excerpt, parent (its translation on the
 * target site, when linked), menu order, comment and ping status, password. Written on the target site with the
 * target site's KSES rules for the person who copies (Job::on_target()).
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir\Copy;

use lloc\Msls\ContentImport\Importers\BaseImporter;
use Windrose\Sabir\Links;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post fields importer.
 */
final class Importer_Fields extends BaseImporter {

	/**
	 * Copies the fields.
	 *
	 * @param array<string, mixed> $data MSLS's post data (returned unchanged: the target post is written directly).
	 * @return array<string, mixed>
	 */
	public function import( array $data ) {
		$job = Job::of( $this->import_coordinates );
		if ( null === $job ) {
			return $data;
		}
		$source  = $job->source;
		$content = Content::rewrite( $source->post_content, $job->media->map, $job->media->files );

		$parent = 0;
		if ( $source->post_parent > 0 ) {
			$linked = Links::read( $job->source_site, Links::POST, $source->post_parent )[ $job->target_locale ] ?? 0;
			if ( $linked > 0 ) {
				$parent = (int) $job->on_target(
					static function () use ( $linked, $source ): int {
						$post = get_post( $linked );
						return $post instanceof WP_Post && $post->post_type === $source->post_type ? $linked : 0;
					}
				);
			}
		}

		$fields = array(
			'ID'             => $job->target_id,
			'post_title'     => $source->post_title,
			'post_content'   => $content,
			'post_excerpt'   => $source->post_excerpt,
			'post_parent'    => $parent,
			'menu_order'     => $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'post_password'  => $source->post_password,
		);
		$result = $job->on_target( static fn() => wp_update_post( wp_slash( $fields ), true ) );
		if ( is_wp_error( $result ) ) {
			$this->logger->log_error( 'post-field/added', array( 'post' => $result->get_error_message() ) );
		} else {
			$this->logger->log_success( 'post-field/added', array( 'post' => $job->target_id ) );
		}
		return $data;
	}
}
