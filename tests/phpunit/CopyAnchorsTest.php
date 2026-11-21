<?php
/**
 * Copy with Windrose Anchors: images become network items of the target site's media hub; items not
 * shared yet are shared first when the person may; any other case copies the file. Skipped when Windrose Anchors is
 * not next to this plugin (as on GitHub).
 *
 * @package Windrose\Sabir
 */

use Windrose\Anchors\Hub;
use Windrose\Anchors\Proxy;
use Windrose\Anchors\Settings;
use Windrose\Sabir\Copy\Copier;
use Windrose\Sabir\Copy\Media;

/**
 * @covers \Windrose\Sabir\Copy\Media
 * @covers \Windrose\Sabir\Copy\Network_Media
 */
class CopyAnchorsTest extends Sabir_TestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( Proxy::class ) ) {
			$this->markTestSkipped( 'Windrose Anchors is not available.' );
		}
		update_site_option( Settings::SITES_OPTION, array( self::$fr, self::$ar ) );
		Settings::flush();
		$this->group( 'Windrose', array( self::$main, self::$fr, self::$ar ) );
	}

	public function tear_down(): void {
		// Network items first: they hold usage on the hub items.
		foreach ( array( self::$fr, self::$ar ) as $site ) {
			switch_to_blog( $site );
			foreach ( get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			) as $id ) {
				wp_delete_attachment( (int) $id, true );
			}
			restore_current_blog();
		}
		parent::tear_down();
	}

	/**
	 * A published post on the current site with an image block and the featured image.
	 *
	 * @param int $image Attachment ID on the current site.
	 * @return int
	 */
	private function post_with( int $image ): int {
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'Spring fields',
				'post_content' => $this->image_content( $image ),
			)
		);
		set_post_thumbnail( $post, $image );
		return $post;
	}

	/**
	 * The draft a copy made, read on its site.
	 *
	 * @param array<string, mixed> $result Copy result.
	 * @return WP_Post
	 */
	private function draft( $result ): WP_Post {
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$post = get_blog_post( $result['site'], $result['post'] );
		$this->assertInstanceOf( WP_Post::class, $post );
		return $post;
	}

	public function test_a_shared_hub_image_becomes_a_network_item(): void {
		$this->super_admin();
		$image = $this->image();
		Hub::set_shared( $image, true );
		$src  = (string) wp_get_attachment_image_url( $image, 'medium' );
		$post = $this->post_with( $image );

		$result = Copier::copy( $post, self::$fr );
		$draft  = $this->draft( $result );

		$this->assertSame( 'network', $result['images'][0]['how'] );
		switch_to_blog( self::$fr );
		$item = (int) get_post_thumbnail_id( $draft );
		$this->assertSame( $image, Proxy::hub_of( $item ), 'A network item of the hub image.' );
		$this->assertSame( '', get_post_meta( $item, Media::SOURCE_META, true ) );
		restore_current_blog();
		$this->assertStringContainsString( '"id":' . $item, $draft->post_content );
		$this->assertStringContainsString( 'src="' . $src . '"', $draft->post_content, 'The hub file\'s address stays.' );
		$this->assertContains( self::$fr, Hub::usage( $image ) );
	}

	public function test_an_image_not_shared_yet_is_shared_first(): void {
		$this->super_admin();
		$image = $this->image();
		$post  = $this->post_with( $image );
		$this->assertSame( 1, Copier::targets( get_post( $post ) )[0]['media']['share'], 'The editor says so before copying.' );

		$result = Copier::copy( $post, self::$fr );

		$this->draft( $result );
		$this->assertSame( 'share', $result['images'][0]['how'] );
		$this->assertTrue( Hub::is_shared( $image ) );
		$this->assertContains( self::$fr, Hub::usage( $image ) );
	}

	public function test_without_rights_to_share_the_file_is_copied(): void {
		$this->super_admin();
		$image = $this->image();
		$post  = $this->post_with( $image );
		$this->user_with(
			array(
				self::$main => 'contributor',
				self::$fr   => 'editor',
			)
		);

		$result = Copier::copy( $post, self::$fr );

		$this->draft( $result );
		$this->assertSame( 'copy', $result['images'][0]['how'] );
		$this->assertSame( 'cannot-share', $result['images'][0]['reason'] );
		$this->assertFalse( Hub::is_shared( $image ), 'Sharing stays an explicit right.' );
	}

	public function test_images_of_a_draft_become_network_items_for_its_readers(): void {
		$this->super_admin();
		// A draft prepared in several languages: its new image is attached to it, not shared yet.
		$post  = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$image = $this->image( $post );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => $this->image_content( $image ),
			)
		);
		$this->assertFalse( Hub::is_listable( $image ) );

		$result = Copier::copy( $post, self::$fr );

		$draft = $this->draft( $result );
		$this->assertSame( 'share', $result['images'][0]['how'] );
		switch_to_blog( self::$fr );
		$this->assertSame( $image, Proxy::hub_of( $this->first_image( $draft ) ) );
		restore_current_blog();
		$this->assertFalse( Hub::is_listable( $image ), 'Still listed to no other site.' );
	}

	/**
	 * The attachment ID of the first image block of a post.
	 *
	 * @param WP_Post $post Post.
	 * @return int
	 */
	private function first_image( WP_Post $post ): int {
		return preg_match( '/"id":(\d+)/', $post->post_content, $found ) ? (int) $found[1] : 0;
	}

	public function test_an_image_of_an_unreadable_draft_is_copied(): void {
		$this->super_admin();
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$image = $this->image( $draft );
		Hub::set_shared( $image, true );
		// The article itself is published and uses the draft's image.
		$post = $this->post_with( $image );
		$this->user_with(
			array(
				self::$main => 'contributor',
				self::$fr   => 'editor',
			)
		);
		$this->assertFalse( current_user_can( 'read_post', $draft ) );

		$result = Copier::copy( $post, self::$fr );

		$this->draft( $result );
		$this->assertSame( 'copy', $result['images'][0]['how'] );
		$this->assertSame( 'unpublished', $result['images'][0]['reason'] );
	}

	public function test_the_person_may_choose_file_copies(): void {
		$this->super_admin();
		$image = $this->image();
		Hub::set_shared( $image, true );
		$post    = $this->post_with( $image );
		$targets = Copier::targets( get_post( $post ) );
		$this->assertSame( 1, $targets[0]['media']['network'] );
		$this->assertSame( 1, $targets[0]['mediaCopy']['copy'] );

		$result = Copier::copy( $post, self::$fr, 'copy' );

		$draft = $this->draft( $result );
		$this->assertSame( 'copy', $result['images'][0]['how'] );
		$this->assertSame( 'chosen', $result['images'][0]['reason'] );
		switch_to_blog( self::$fr );
		$copy = (int) get_post_thumbnail_id( $draft );
		$this->assertSame( 0, Proxy::hub_of( $copy ) );
		$this->assertSame( self::$main . ':' . $image, get_post_meta( $copy, Media::SOURCE_META, true ) );
		restore_current_blog();
		$this->assertNotContains( self::$fr, Hub::usage( $image ) );
	}

	public function test_a_site_without_network_media_gets_a_file_copy(): void {
		$this->super_admin();
		update_site_option( Settings::SITES_OPTION, array( self::$fr ) );
		Settings::flush();
		$image = $this->image();
		Hub::set_shared( $image, true );

		$result = Copier::copy( $this->post_with( $image ), self::$ar );

		$this->draft( $result );
		$this->assertSame( 'not-allowed', $result['images'][0]['reason'] );
	}

	public function test_a_network_item_of_the_source_becomes_one_of_the_target(): void {
		$this->super_admin();
		$image = $this->image();
		Hub::set_shared( $image, true );
		switch_to_blog( self::$fr );
		$item = Proxy::create( $image );
		$this->assertIsArray( $item );
		$post = $this->post_with( $item['id'] );

		$to_ar  = Copier::copy( $post, self::$ar );
		$to_hub = Copier::copy( $post, self::$main );
		restore_current_blog();

		$draft = $this->draft( $to_ar );
		$this->assertSame( 'network', $to_ar['images'][0]['how'] );
		switch_to_blog( self::$ar );
		$this->assertSame( $image, Proxy::hub_of( (int) get_post_thumbnail_id( $draft ) ) );
		restore_current_blog();

		// Copied to the hub itself: the hub's own item.
		$hub_draft = $this->draft( $to_hub );
		$this->assertSame( 'hub-item', $to_hub['images'][0]['how'] );
		$this->assertSame( $image, (int) get_post_thumbnail_id( $hub_draft ) );
		$this->assertStringContainsString( 'wp-image-' . $image, $hub_draft->post_content );
	}

	public function test_an_item_of_another_hub_is_copied_from_its_real_file(): void {
		$this->super_admin();
		$image = $this->image();
		Hub::set_shared( $image, true );
		// The Arabic site uses Social as its media hub.
		$this->assertTrue( Settings::set_hub( self::$social, true ) );
		$this->assertTrue( Settings::choose_hub( array( self::$ar ), self::$social ) );
		Settings::flush();
		switch_to_blog( self::$fr );
		$item = Proxy::create( $image );
		$this->assertIsArray( $item );
		$post   = $this->post_with( $item['id'] );
		$result = Copier::copy( $post, self::$ar );
		restore_current_blog();

		$draft = $this->draft( $result );
		$this->assertSame( 'copy', $result['images'][0]['how'] );
		$this->assertSame( 'other-hub', $result['images'][0]['reason'] );
		switch_to_blog( self::$ar );
		$copy = (int) get_post_thumbnail_id( $draft );
		$this->assertSame( self::$main . ':' . $image, get_post_meta( $copy, Media::SOURCE_META, true ), 'Copied from the hub\'s real file.' );
		$this->assertFileExists( (string) get_attached_file( $copy ) );
		$this->assertSame( 0, Proxy::hub_of( $copy ) );
		restore_current_blog();
	}
}
