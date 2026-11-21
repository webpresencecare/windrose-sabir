<?php
/**
 * Categories, tags and other terms of a copied post.
 *
 * For each term of the source post, on the target site:
 *
 * 1. its translation linked in Multisite Language Switcher, when that term exists there (`linked`);
 * 2. otherwise, by the target site's setting "Terms without a translation" (Site_Settings):
 *    - `create` (default): a term with the same name, if the site has one (`existing`), else a new term (`created`),
 *      linked to the source term as its translation (when neither has a translation in the other's language yet);
 *    - `skip`: not assigned (`skipped`).
 *
 * Rights on the target site: `assign_terms` of the taxonomy, and `edit_terms` to create one (`denied` otherwise). The
 * post format is copied as it is. Parents of new hierarchical terms are not copied (one level, as MSLS does).
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir\Copy;

use Windrose\Sabir\Links;
use Windrose\Sabir\Site_Settings;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Terms of a copy.
 */
final class Terms {

	/**
	 * Copies the terms of the job's post.
	 *
	 * @param Job $job Copy.
	 * @return void
	 */
	public static function apply( Job $job ): void {
		$source     = $job->source;
		$taxonomies = array_values( array_diff( get_object_taxonomies( $source->post_type ), array( 'post_format' ) ) );

		$terms  = $job->on_source(
			static function () use ( $source, $taxonomies ): array {
				$found = array() === $taxonomies ? array() : wp_get_object_terms( $source->ID, $taxonomies );
				return is_array( $found ) ? array_values( array_filter( $found, static fn( $term ): bool => $term instanceof WP_Term ) ) : array();
			}
		);
		$format = $job->on_source( static fn() => get_post_format( $source->ID ) );

		$setting = Site_Settings::of_site( $job->target_site )['terms'];
		$by_tax  = array();
		foreach ( $terms as $term ) {
			$by_tax[ $term->taxonomy ][] = $term;
		}
		foreach ( $by_tax as $taxonomy => $list ) {
			$ids = array();
			foreach ( $list as $term ) {
				$result                 = self::target_term( $job, $term, $setting );
				$job->report['terms'][] = array(
					'name'     => $term->name,
					'taxonomy' => $taxonomy,
					'how'      => $result['how'],
				);
				if ( $result['id'] > 0 ) {
					$ids[] = $result['id'];
				}
			}
			$target_id = $job->target_id;
			$job->on_target(
				static function () use ( $ids, $taxonomy, $target_id ): void {
					$tax = get_taxonomy( $taxonomy );
					if ( false !== $tax && current_user_can( $tax->cap->assign_terms ) ) {
						wp_set_object_terms( $target_id, $ids, $taxonomy );
					}
				}
			);
		}//end foreach
		if ( is_string( $format ) && '' !== $format ) {
			$target_id = $job->target_id;
			$job->on_target( static fn() => set_post_format( $target_id, $format ) );
		}
	}

	/**
	 * The target site's term for a source term.
	 *
	 * @param Job     $job     Copy.
	 * @param WP_Term $term    Source term.
	 * @param string  $setting create or skip.
	 * @return array{id: int, how: string}
	 */
	private static function target_term( Job $job, WP_Term $term, string $setting ): array {
		$taxonomy = $term->taxonomy;
		$linked   = Links::read( $job->source_site, Links::TERM, $term->term_id )[ $job->target_locale ] ?? 0;
		return $job->on_target(
			static function () use ( $job, $term, $taxonomy, $linked, $setting ): array {
				$tax = get_taxonomy( $taxonomy );
				if ( false === $tax || ! current_user_can( $tax->cap->assign_terms ) ) {
					return array(
						'id'  => 0,
						'how' => 'denied',
					);
				}
				if ( $linked > 0 && get_term( $linked, $taxonomy ) instanceof WP_Term ) {
					return array(
						'id'  => $linked,
						'how' => 'linked',
					);
				}
				if ( 'skip' === $setting ) {
					return array(
						'id'  => 0,
						'how' => 'skipped',
					);
				}
				$existing = get_term_by( 'name', $term->name, $taxonomy );
				if ( $existing instanceof WP_Term ) {
					$id  = $existing->term_id;
					$how = 'existing';
				} else {
					if ( ! current_user_can( $tax->cap->edit_terms ) ) {
						return array(
							'id'  => 0,
							'how' => 'denied',
						);
					}
					$created = wp_insert_term( wp_slash( $term->name ), $taxonomy, wp_slash( array( 'description' => $term->description ) ) );
					if ( ! is_array( $created ) ) {
						return array(
							'id'  => 0,
							'how' => 'denied',
						);
					}
					$id  = (int) $created['term_id'];
					$how = 'created';
				}
				// Link the two terms as translations, unless one of them already has one in the other's language.
				$back = Links::read( $job->target_site, Links::TERM, $id )[ $job->source_locale ] ?? 0;
				if ( 0 === $back ) {
					Links::link(
						Links::TERM,
						array(
							$job->source_locale => array(
								'site' => $job->source_site,
								'id'   => $term->term_id,
							),
							$job->target_locale => array(
								'site' => $job->target_site,
								'id'   => $id,
							),
						)
					);
				}
				return array(
					'id'  => $id,
					'how' => $how,
				);
			}
		);
	}
}
