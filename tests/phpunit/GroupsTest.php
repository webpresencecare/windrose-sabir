<?php
/**
 * Language groups: storage and validation.
 *
 * @package Windrose\Sabir
 */

use Windrose\Sabir\Groups;

/**
 * @covers \Windrose\Sabir\Groups
 */
class GroupsTest extends Sabir_TestCase {

	public function test_no_group_by_default(): void {
		$this->assertFalse( Groups::exist() );
		$this->assertSame( array(), Groups::all() );
		$this->assertSame( 0, Groups::of_site( self::$fr ) );
	}

	public function test_save_creates_groups_with_site_meta(): void {
		$first  = $this->group( 'Windrose', array( self::$fr, self::$main, self::$main ) );
		$second = $this->group( 'Other project', array( self::$ar, self::$social ) );

		$this->assertSame( 1, $first );
		$this->assertSame( 2, $second );
		$this->assertTrue( Groups::exist() );
		$this->assertSame(
			array(
				array(
					'id'    => 1,
					'label' => 'Windrose',
					'main'  => 0,
				),
				array(
					'id'    => 2,
					'label' => 'Other project',
					'main'  => 0,
				),
			),
			get_site_option( Groups::OPTION )
		);
		$this->assertSame( array( self::$main, self::$fr ), Groups::sites( $first ) );
		$this->assertSame( '1', get_site_meta( self::$fr, Groups::META, true ) );
		$this->assertSame( $second, Groups::of_site( self::$social ) );
	}

	public function test_save_changes_name_and_sites(): void {
		$id = $this->group( 'Windrose', array( self::$main, self::$fr, self::$ar ) );
		$this->assertSame( $id, $this->group( 'example.com', array( self::$main, self::$fr ), $id ) );

		$this->assertSame( 'example.com', Groups::get( $id )['label'] ?? '' );
		$this->assertSame( array( self::$main, self::$fr ), Groups::sites( $id ) );
		$this->assertSame( 0, Groups::of_site( self::$ar ) );
		$this->assertFalse( metadata_exists( 'blog', self::$ar, Groups::META ), 'The meta of a removed site is deleted.' );
		$this->assertCount( 1, Groups::all() );
	}

	/**
	 * @dataProvider invalid_groups
	 */
	public function test_save_refuses_invalid_groups( string $label, string $sites, string $message ): void {
		$ids    = array(
			'main'   => self::$main,
			'fr'     => self::$fr,
			'ar'     => self::$ar,
			'social' => self::$social,
			'none'   => 999,
		);
		$result = Groups::save( 0, $label, array_map( static fn( string $key ): int => $ids[ $key ], array_filter( explode( ',', $sites ) ) ) );

		$this->assertWPError( $result );
		$this->assertStringContainsString( $message, $result->get_error_message() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? 0 );
		$this->assertFalse( Groups::exist() );
		$this->assertFalse( metadata_exists( 'blog', self::$fr, Groups::META ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function invalid_groups(): array {
		return array(
			'empty name'        => array( "  <b></b>\t", 'main,fr', 'Give the language group a name' ),
			'name too long'     => array( str_repeat( 'a', 101 ), 'main,fr', 'up to 100 characters' ),
			'one site'          => array( 'Windrose', 'fr', 'at least two sites' ),
			'no site'           => array( 'Windrose', '', 'at least two sites' ),
			'unknown site'      => array( 'Windrose', 'fr,none', 'Site 999 does not exist' ),
			'two English sites' => array( 'Windrose', 'main,fr,social', 'both use the language en_US' ),
		);
	}

	public function test_a_site_is_in_one_group_only(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		$result = Groups::save( 0, 'Other', array( self::$fr, self::$ar ) );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'is already in the language group “Windrose”', $result->get_error_message() );
		$this->assertCount( 1, Groups::all() );
	}

	public function test_names_are_unique_and_cleaned(): void {
		$id = $this->group( '  <em>Windrose</em> ', array( self::$main, self::$fr ) );
		$this->assertSame( 'Windrose', Groups::get( $id )['label'] ?? '' );

		$result = Groups::save( 0, 'WINDROSE', array( self::$ar, self::$social ) );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'already called', $result->get_error_message() );

		$this->assertSame( $id, $this->group( 'windrose', array( self::$main, self::$fr ), $id ), 'A group may keep its own name in another case.' );
	}

	public function test_changing_a_missing_group_fails(): void {
		$result = Groups::save( 7, 'Windrose', array( self::$main, self::$fr ) );
		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? 0 );
		$this->assertWPError( Groups::delete( 7 ) );
	}

	public function test_delete_removes_the_group_and_its_site_meta(): void {
		$first  = $this->group( 'Windrose', array( self::$main, self::$fr ) );
		$second = $this->group( 'Other', array( self::$ar, self::$social ) );

		$this->assertTrue( Groups::delete( $first ) );
		$this->assertFalse( metadata_exists( 'blog', self::$fr, Groups::META ) );
		$this->assertSame( array( $second ), array_column( Groups::all(), 'id' ) );

		$this->assertTrue( Groups::delete( $second ) );
		$this->assertFalse( get_site_option( Groups::OPTION ), 'The option is removed with the last group.' );
		$this->assertFalse( Groups::exist() );
	}

	public function test_meta_naming_a_missing_group_counts_as_no_group(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		update_site_meta( self::$ar, Groups::META, 42 );

		$this->assertSame( 0, Groups::of_site( self::$ar ) );
	}

	public function test_deleted_and_archived_sites(): void {
		$extra = self::factory()->blog->create(
			array(
				'path'    => '/de/',
				'user_id' => 1,
				'options' => array( 'WPLANG' => 'de_DE' ),
			)
		);
		$id    = $this->group( 'Windrose', array( self::$main, self::$fr, $extra ) );

		update_blog_status( $extra, 'archived', '1' );
		$this->assertSame( array( self::$main, self::$fr, $extra ), Groups::sites( $id ) );
		$this->assertSame( array( self::$main, self::$fr ), Groups::sites( $id, true ), 'Archived sites are left out of the public list.' );

		wp_delete_site( $extra );
		$this->assertSame( array( self::$main, self::$fr ), Groups::sites( $id ), 'Core deletes the site meta with the site.' );
	}

	public function test_site_meta_is_registered_for_rest_with_a_manage_sites_check(): void {
		// The test library unregisters meta keys after each test; register them as on init.
		Groups::register_meta();
		$keys = get_registered_meta_keys( 'blog' );
		$this->assertArrayHasKey( Groups::META, $keys );
		$this->assertTrue( $keys[ Groups::META ]['show_in_rest'] );
		$this->assertSame( 'integer', $keys[ Groups::META ]['type'] );

		wp_set_current_user( self::$editor );
		$this->assertFalse( call_user_func( $keys[ Groups::META ]['auth_callback'] ) );
		$this->super_admin();
		$this->assertTrue( call_user_func( $keys[ Groups::META ]['auth_callback'] ) );
	}

	public function test_locale_and_site_label(): void {
		$this->assertSame( 'en_US', Groups::locale( self::$main ) );
		$this->assertSame( 'fr_FR', Groups::locale( self::$fr ) );
		$this->assertMatchesRegularExpression( '#^Français \(.+/fr\)$#u', Groups::site_label( self::$fr ) );
		$this->assertSame( 'Site 999', Groups::site_label( 999 ) );
	}

	public function test_main_site(): void {
		$id = $this->group( 'Windrose', array( self::$main, self::$fr, self::$ar ) );
		$this->assertSame( 0, Groups::main_site( self::$fr ), 'No main site by default.' );

		$this->assertSame( $id, Groups::save( $id, 'Windrose', array( self::$main, self::$fr, self::$ar ), self::$main ) );
		$this->assertSame( self::$main, Groups::get( $id )['main'] ?? 0 );
		$this->assertSame( self::$main, Groups::main_site( self::$ar ) );
		$this->assertSame( 0, Groups::main_site( self::$social ), 'A site in no group has none.' );

		$refused = Groups::save( $id, 'Windrose', array( self::$main, self::$fr ), self::$ar );
		$this->assertWPError( $refused );
		$this->assertStringContainsString( 'must be one of the sites', $refused->get_error_message() );

		// A main site that leaves the group no longer counts.
		$this->assertSame( $id, Groups::save( $id, 'Windrose', array( self::$fr, self::$ar ), 0 ) );
		update_site_option(
			Groups::OPTION,
			array(
				array(
					'id'    => $id,
					'label' => 'Windrose',
					'main'  => self::$main,
				),
			)
		);
		$this->assertSame( 0, Groups::main_site( self::$fr ) );
	}

	public function test_media_hubs_for_windrose_anchors(): void {
		$this->assertSame( array(), apply_filters( 'windrose_anchors_group_hubs', array() ), 'No group, no hub.' );

		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		$this->assertSame( array(), apply_filters( 'windrose_anchors_group_hubs', array() ), 'A group without main site gives none.' );

		$other = $this->group( 'Other', array( self::$ar, self::$social ) );
		Groups::save( $other, 'Other', array( self::$ar, self::$social ), self::$ar );
		$this->assertSame(
			array(
				self::$ar     => self::$ar,
				self::$social => self::$ar,
			),
			apply_filters( 'windrose_anchors_group_hubs', array() )
		);
	}
}
