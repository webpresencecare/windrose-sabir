<?php
/**
 * Base for Windrose Sabir tests: three sites of its own next to the main site (French, Arabic, Social), each set up
 * in Multisite Language Switcher (MSLS) as on a real network, where Social is set up too. User 1 (a super admin) is
 * a member of every site, so MSLS's reference user sees all four. The database rolls back after each test.
 *
 * @package Windrose\Sabir
 */

use lloc\Msls\Registry\Registry;
use Windrose\Sabir\Groups;

/**
 * Test case with language sites.
 */
abstract class Sabir_TestCase extends WP_UnitTestCase {

	protected static int $main   = 1;
	protected static int $fr     = 0;
	protected static int $ar     = 0;
	protected static int $social = 0;
	protected static int $editor = 0;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$fr     = $factory->blog->create(
			array(
				'path'    => '/fr/',
				'title'   => 'Français',
				'user_id' => 1,
			)
		);
		self::$ar     = $factory->blog->create(
			array(
				'path'    => '/ar/',
				'title'   => 'العربية',
				'user_id' => 1,
			)
		);
		self::$social = $factory->blog->create(
			array(
				'path'    => '/social/',
				'title'   => 'Social',
				'user_id' => 1,
			)
		);
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public static function wpTearDownAfterClass(): void {
		foreach ( array( self::$fr, self::$ar, self::$social ) as $site ) {
			wp_delete_site( $site );
		}
		self::delete_user( self::$editor );
	}

	public function set_up(): void {
		parent::set_up();
		// Core only saves a WPLANG it has language files for; the test core has none.
		add_filter( 'get_available_languages', static fn(): array => array( 'ar', 'de_DE', 'fr_FR' ) );
		update_blog_option( self::$main, 'WPLANG', '' );
		update_blog_option( self::$fr, 'WPLANG', 'fr_FR' );
		update_blog_option( self::$ar, 'WPLANG', 'ar' );
		update_blog_option( self::$social, 'WPLANG', '' );
		foreach ( array(
			self::$main   => 'English',
			self::$fr     => 'Français',
			self::$ar     => 'العربية',
			self::$social => 'Social',
		) as $site => $description ) {
			update_blog_option( $site, 'msls', array( 'description' => $description ) );
		}
		Registry::clear();
		// Windrose Anchors (when loaded): its caches outlive the database rollback.
		if ( class_exists( \Windrose\Anchors\Settings::class ) ) {
			wp_cache_add_global_groups( \Windrose\Anchors\Hub::CACHE_GROUP );
			\Windrose\Anchors\Hub::reset_context();
			\Windrose\Anchors\Settings::flush();
		}
	}

	public function tear_down(): void {
		// A failed assertion can leave the test switched to another site.
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		Registry::clear();
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * Uploads an image on the current site (a real file in the test uploads, removed after the test).
	 *
	 * @param int    $attach_to Post to attach it to.
	 * @param string $file   File in the test data.
	 * @return int Attachment ID.
	 */
	protected function image( int $attach_to = 0, string $file = 'canola.jpg' ): int {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $file, $attach_to );
		$this->assertIsInt( $id );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => 'Canola field',
				'post_excerpt' => 'Photo: Jane Doe',
				'post_content' => 'A field in May',
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', 'Yellow canola field' );
		return $id;
	}

	/**
	 * Block content with a paragraph and an image block (medium size, caption with credit).
	 *
	 * @param int $image Attachment ID on the current site.
	 * @return string
	 */
	protected function image_content( int $image ): string {
		$url = (string) wp_get_attachment_image_url( $image, 'medium' );
		return "<!-- wp:paragraph -->\n<p>Hello fields.</p>\n<!-- /wp:paragraph -->\n\n"
			. '<!-- wp:image {"id":' . $image . ',"sizeSlug":"medium","linkDestination":"none"} -->' . "\n"
			. '<figure class="wp-block-image size-medium"><img src="' . $url . '" alt="Yellow canola field" class="wp-image-' . $image . '"/><figcaption class="wp-element-caption">Canola in May. Photo: Jane Doe</figcaption></figure>' . "\n"
			. '<!-- /wp:image -->';
	}

	/**
	 * Logs in a user with roles on sites.
	 *
	 * @param array<int, string> $roles Site ID => role.
	 * @return int User ID.
	 */
	protected function user_with( array $roles ): int {
		$user = self::factory()->user->create( array( 'role' => '' ) );
		foreach ( $roles as $site => $role ) {
			add_user_to_blog( $site, $user, $role );
		}
		wp_set_current_user( $user );
		return $user;
	}

	/**
	 * The site IDs MSLS links from a site (its collection, built as in a request for that site).
	 *
	 * @param int $site Site ID.
	 * @return list<int>
	 */
	protected function collection( int $site ): array {
		switch_to_blog( $site );
		Registry::clear();
		$ids = array_map( 'intval', array_keys( msls_blog_collection()->get_objects() ) );
		Registry::clear();
		restore_current_blog();
		sort( $ids );
		return $ids;
	}

	/**
	 * Saves a group and fails the test on an error.
	 *
	 * @param string    $label Name.
	 * @param list<int> $sites Site IDs.
	 * @param int       $id    Group ID (0: new).
	 * @return int Group ID.
	 */
	protected function group( string $label, array $sites, int $id = 0 ): int {
		$saved = Groups::save( $id, $label, $sites );
		$this->assertIsInt( $saved, $saved instanceof WP_Error ? $saved->get_error_message() : '' );
		return $saved;
	}

	/**
	 * Logs in a new super admin.
	 *
	 * @return int User ID.
	 */
	protected function super_admin(): int {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $user );
		wp_set_current_user( $user );
		return $user;
	}
}
