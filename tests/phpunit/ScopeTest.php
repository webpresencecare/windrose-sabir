<?php
/**
 * Language groups applied to Multisite Language Switcher (MSLS): its collection, post types and taxonomies.
 *
 * @package Windrose\Sabir
 */

use Windrose\Sabir\Groups;

/**
 * @covers \Windrose\Sabir\Scope
 */
class ScopeTest extends Sabir_TestCase {

	public function test_without_a_group_msls_is_unchanged(): void {
		$all = array( self::$main, self::$fr, self::$ar, self::$social );
		$this->assertSame( $all, $this->collection( self::$fr ) );
		$this->assertSame( $all, $this->collection( self::$social ) );

		switch_to_blog( self::$social );
		$this->assertContains( 'post', msls_post_type()::get() );
		$this->assertContains( 'category', msls_taxonomy()::get() );
		restore_current_blog();
	}

	public function test_a_group_limits_msls_to_its_sites(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr, self::$ar ) );

		$group = array( self::$main, self::$fr, self::$ar );
		$this->assertSame( $group, $this->collection( self::$main ) );
		$this->assertSame( $group, $this->collection( self::$fr ) );
		$this->assertSame( $group, $this->collection( self::$ar ) );
		$this->assertSame( array(), $this->collection( self::$social ), 'A site in no group links nothing.' );
	}

	public function test_sites_in_no_group_get_no_post_types_or_taxonomies(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );

		switch_to_blog( self::$social );
		$this->assertSame( array(), msls_post_type()::get() );
		$this->assertSame( array(), msls_taxonomy()::get() );
		restore_current_blog();

		switch_to_blog( self::$fr );
		$this->assertSame( array( 'post', 'page' ), array_slice( msls_post_type()::get(), 0, 2 ) );
		$this->assertContains( 'post_tag', msls_taxonomy()::get() );
		restore_current_blog();
	}

	public function test_two_groups_stay_apart(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		$this->group( 'Other', array( self::$ar, self::$social ) );

		$this->assertSame( array( self::$main, self::$fr ), $this->collection( self::$fr ) );
		$this->assertSame( array( self::$ar, self::$social ), $this->collection( self::$social ) );
	}

	public function test_the_group_is_the_site_list_not_the_reference_user(): void {
		$extra = self::factory()->blog->create(
			array(
				'path'    => '/de/',
				'options' => array(
					'WPLANG' => 'de_DE',
					'msls'   => array( 'description' => 'Deutsch' ),
				),
			)
		);
		$this->assertNotContains( $extra, $this->collection( self::$fr ), 'The reference user is no member of the new site.' );

		$this->group( 'Windrose', array( self::$main, self::$fr, $extra ) );
		$this->assertSame( array( self::$main, self::$fr, $extra ), $this->collection( self::$fr ) );

		wp_delete_site( $extra );
	}

	public function test_msls_still_skips_archived_sites_and_sites_without_its_settings(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr, self::$ar ) );

		update_blog_status( self::$ar, 'archived', '1' );
		$this->assertSame( array( self::$main, self::$fr ), $this->collection( self::$fr ) );
		update_blog_status( self::$ar, 'archived', '0' );

		delete_blog_option( self::$ar, 'msls' );
		$this->assertSame( array( self::$main, self::$fr ), $this->collection( self::$fr ) );

		update_blog_option( self::$ar, 'msls', array( 'exclude_current_blog' => 1 ) );
		$this->assertSame( array( self::$main, self::$fr ), $this->collection( self::$fr ) );
	}

	public function test_collection_objects_have_the_shape_msls_expects(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		switch_to_blog( self::$main );
		lloc\Msls\Registry\Registry::clear();
		$blog = msls_blog_collection()->get_object( self::$fr );
		restore_current_blog();

		$this->assertNotNull( $blog );
		$this->assertSame( self::$fr, $blog->userblog_id );
		$this->assertSame( 'Français', $blog->blogname );
		$this->assertSame( 'fr_FR', $blog->get_language() );
		$this->assertSame( 'Français', $blog->get_description() );
		$this->assertStringEndsWith( '/fr/', (string) $blog->path );
	}

	public function test_deleting_the_last_group_gives_msls_back_its_reference_user(): void {
		$id = $this->group( 'Windrose', array( self::$main, self::$fr ) );
		$this->assertSame( array(), $this->collection( self::$social ) );

		Groups::delete( $id );
		$this->assertSame( array( self::$main, self::$fr, self::$ar, self::$social ), $this->collection( self::$social ) );
	}
}
