<?php
/**
 * Copy from the post lists (Posts, Pages): one bulk action per other site of the group, "Copy to <language> as
 * drafts". Each post is checked and copied as in the editor (Copier); the result is shown in a notice.
 *
 * @package Windrose\Sabir
 */

declare( strict_types=1 );

namespace Windrose\Sabir\Copy;

use Windrose\Sabir\Groups;
use Windrose\Sabir\Modules;
use Windrose\Sabir\Site_Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk actions.
 */
final class Bulk {

	public const PREFIX = 'windrose_sabir_copy_';

	/** Posts copied per request: images are copied as files without Windrose Anchors, which takes time on shared hosting. */
	public const MAX = 10;

	/**
	 * Hooks the list screen.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'load-edit.php', array( __CLASS__, 'load' ) );
	}

	/**
	 * Adds the actions on the list of a post type that may be copied.
	 *
	 * @return void
	 */
	public static function load(): void {
		$screen = get_current_screen();
		$type   = null === $screen ? '' : (string) $screen->post_type;
		if ( '' === $type || ! Modules::on( 'copy' ) || 0 === Groups::of_site( get_current_blog_id() ) || ! in_array( $type, Site_Settings::copy_post_types(), true ) ) {
			return;
		}
		add_filter( 'bulk_actions-edit-' . $type, array( __CLASS__, 'actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . $type, array( __CLASS__, 'handle' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * The actions: one per other site of the group.
	 *
	 * @param mixed $actions Bulk actions.
	 * @return mixed
	 */
	public static function actions( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		foreach ( Copier::targets( null ) as $target ) {
			$actions[ self::PREFIX . $target['id'] ] = sprintf(
				/* translators: %s: language name (Français). */
				__( 'Copy to %s as drafts', 'windrose-sabir' ),
				$target['language']
			);
		}
		return $actions;
	}

	/**
	 * Runs a copy action (core checked the list's nonce before).
	 *
	 * @param mixed  $redirect Address to go back to.
	 * @param string $action   Action name.
	 * @param mixed  $post_ids Selected posts.
	 * @return mixed
	 */
	public static function handle( $redirect, $action, $post_ids ) {
		if ( ! is_string( $redirect ) || ! str_starts_with( (string) $action, self::PREFIX ) ) {
			return $redirect;
		}
		$site     = (int) substr( (string) $action, strlen( self::PREFIX ) );
		$counts   = array(
			'copied'   => 0,
			'existing' => 0,
			'failed'   => 0,
		);
		$errors   = array();
		$post_ids = is_array( $post_ids ) ? array_values( array_map( 'intval', $post_ids ) ) : array();
		$more     = 0;
		foreach ( $post_ids as $index => $post_id ) {
			// MAX copies per run; the rest waits for the next run, which skips posts already copied.
			if ( $counts['copied'] >= self::MAX ) {
				$more = count( $post_ids ) - $index;
				break;
			}
			$result = Copier::copy( $post_id, $site );
			if ( ! $result instanceof WP_Error ) {
				++$counts['copied'];
			} elseif ( 'windrose_sabir_copy_exists' === $result->get_error_code() ) {
				++$counts['existing'];
			} else {
				++$counts['failed'];
				$errors[] = sprintf(
					/* translators: 1: post title, 2: error message. */
					__( '%1$s: %2$s', 'windrose-sabir' ),
					get_the_title( $post_id ),
					$result->get_error_message()
				);
			}
		}//end foreach
		set_transient( self::transient(), array_slice( array_values( array_unique( $errors ) ), 0, 10 ), MINUTE_IN_SECONDS );
		return add_query_arg(
			array(
				'windrose_sabir_site'     => $site,
				'windrose_sabir_copied'   => $counts['copied'],
				'windrose_sabir_existing' => $counts['existing'],
				'windrose_sabir_failed'   => $counts['failed'],
				'windrose_sabir_more'     => $more,
			),
			remove_query_arg( array( 'windrose_sabir_site', 'windrose_sabir_copied', 'windrose_sabir_existing', 'windrose_sabir_failed', 'windrose_sabir_more' ), $redirect )
		);
	}

	/**
	 * The current person's error list (one minute).
	 *
	 * @return string
	 */
	private static function transient(): string {
		return 'windrose_sabir_bulk_' . get_current_user_id();
	}

	/**
	 * The result notice after a bulk copy.
	 *
	 * @return void
	 */
	public static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only: counts the action handler put in the address.
		if ( ! isset( $_GET['windrose_sabir_site'], $_GET['windrose_sabir_copied'] ) ) {
			return;
		}
		$site     = absint( $_GET['windrose_sabir_site'] );
		$copied   = absint( $_GET['windrose_sabir_copied'] );
		$existing = isset( $_GET['windrose_sabir_existing'] ) ? absint( $_GET['windrose_sabir_existing'] ) : 0;
		$failed   = isset( $_GET['windrose_sabir_failed'] ) ? absint( $_GET['windrose_sabir_failed'] ) : 0;
		$more     = isset( $_GET['windrose_sabir_more'] ) ? absint( $_GET['windrose_sabir_more'] ) : 0;
		// phpcs:enable
		$lines = array();
		if ( $copied > 0 ) {
			$lines[] = sprintf(
				/* translators: 1: number of posts, 2: site name and address. */
				_n( '%1$d post copied to %2$s as a draft.', '%1$d posts copied to %2$s as drafts.', $copied, 'windrose-sabir' ),
				$copied,
				Groups::site_label( $site )
			);
		}
		if ( $existing > 0 ) {
			$lines[] = sprintf(
				/* translators: %d: number of posts. */
				_n( '%d post already has a translation there and was not copied.', '%d posts already have a translation there and were not copied.', $existing, 'windrose-sabir' ),
				$existing
			);
		}
		if ( $failed > 0 ) {
			$lines[] = sprintf(
				/* translators: %d: number of posts. */
				_n( '%d post could not be copied:', '%d posts could not be copied:', $failed, 'windrose-sabir' ),
				$failed
			);
		}
		if ( $more > 0 ) {
			$lines[] = sprintf(
				/* translators: 1: number of posts not copied yet, 2: number of posts copied per run. */
				_n( '%1$d more post was selected: posts are copied %2$d at a time, so run the action again (posts already copied are skipped).', '%1$d more posts were selected: posts are copied %2$d at a time, so run the action again (posts already copied are skipped).', $more, 'windrose-sabir' ),
				$more,
				self::MAX
			);
		}
		$errors = get_transient( self::transient() );
		delete_transient( self::transient() );
		$list = '';
		if ( is_array( $errors ) && array() !== $errors ) {
			$list = '<ul>' . implode( '', array_map( static fn( $error ): string => '<li>' . esc_html( (string) $error ) . '</li>', $errors ) ) . '</ul>';
		}
		wp_admin_notice(
			'<p>' . esc_html( implode( ' ', $lines ) ) . '</p>' . $list,
			array(
				'type'           => $failed > 0 ? 'warning' : 'success',
				'dismissible'    => true,
				'paragraph_wrap' => false,
			)
		);
	}
}
