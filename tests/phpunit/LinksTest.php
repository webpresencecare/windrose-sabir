<?php
/**
 * Translation links and group changes: what a site's links will point to.
 *
 * @package Windrose\Sabir
 */

use Windrose\Sabir\Groups;
use Windrose\Sabir\Links;

/**
 * @covers \Windrose\Sabir\Links
 */
class LinksTest extends Sabir_TestCase {

	public function test_links_of_a_site_by_language(): void {
		switch_to_blog( self::$fr );
		update_option(
			'msls_5',
			array(
				'en_US' => 3,
				'ar'    => 7,
			)
		);
		update_option(
			'msls_9',
			array(
				'en_US' => 4,
				'fr_FR' => 9,
			)
		);
		update_option( 'msls_term_2', array( 'en_US' => 4 ) );
		update_option( 'msls_unrelated', array( 'en_US' => 1 ) );
		restore_current_blog();

		$this->assertSame(
			array(
				'ar'    => 1,
				'en_US' => 3,
			),
			Links::of_site( self::$fr ),
			'Posts and terms count; the site\'s own language, MSLS settings and other options do not.'
		);
		$this->assertSame( array(), Links::of_site( self::$social ) );
	}

	public function test_preview_says_where_links_will_point(): void {
		switch_to_blog( self::$ar );
		update_option( 'msls_73', array( 'en_US' => 1 ) );
		update_option( 'msls_74', array( 'fr_FR' => 2 ) );
		restore_current_blog();

		$messages = Links::preview( 0, array( self::$ar, self::$social ) );
		$this->assertCount( 2, $messages );
		$this->assertStringContainsString( 'has 1 translation link to English (United States)', $messages[0] );
		$this->assertStringContainsString( 'Social (', $messages[0] );
		$this->assertStringContainsString( 'will point to that site', $messages[0] );
		$this->assertStringContainsString( 'will lead nowhere', $messages[1] );
	}

	public function test_preview_for_sites_already_in_the_group_and_sites_leaving_it(): void {
		switch_to_blog( self::$ar );
		update_option( 'msls_73', array( 'en_US' => 1 ) );
		restore_current_blog();
		$id = $this->group( 'Windrose', array( self::$main, self::$ar ) );

		$this->assertSame( array(), Links::preview( $id, array( self::$main, self::$ar ) ), 'Nothing changes for sites already in the group.' );

		$messages = Links::preview( $id, array( self::$main, self::$fr ) );
		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'leaves the group: its 1 translation link is kept', $messages[0] );
	}

	public function test_rest_preview(): void {
		switch_to_blog( self::$ar );
		update_option( 'msls_73', array( 'en_US' => 1 ) );
		restore_current_blog();

		wp_set_current_user( self::$editor );
		$request = new WP_REST_Request( 'POST', '/windrose-sabir/v1/groups/preview' );
		$request->set_param( 'sites', array( self::$main, self::$ar ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		$this->super_admin();
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data()['messages'] );
		$this->assertFalse( Groups::exist(), 'A preview saves nothing.' );
	}

	/**
	 * A published post on a site.
	 *
	 * @param int $site Site ID.
	 * @return int
	 */
	private function post_on( int $site ): int {
		switch_to_blog( $site );
		$id = self::factory()->post->create();
		restore_current_blog();
		return $id;
	}

	public function test_link_writes_every_member_and_merges(): void {
		$en = $this->post_on( self::$main );
		$fr = $this->post_on( self::$fr );
		$ar = $this->post_on( self::$ar );
		update_blog_option( self::$main, 'msls_' . $en, array( 'de_DE' => 7 ) );

		Links::link(
			Links::POST,
			array(
				'en_US' => array(
					'site' => self::$main,
					'id'   => $en,
				),
				'fr_FR' => array(
					'site' => self::$fr,
					'id'   => $fr,
				),
				'ar'    => array(
					'site' => self::$ar,
					'id'   => $ar,
				),
			)
		);

		$this->assertSame(
			array(
				'ar'    => $ar,
				'de_DE' => 7,
				'fr_FR' => $fr,
			),
			get_blog_option( self::$main, 'msls_' . $en ),
			'Merged with what it held; its own language never stored.'
		);
		$this->assertSame(
			array(
				'ar'    => $ar,
				'en_US' => $en,
			),
			get_blog_option( self::$fr, 'msls_' . $fr )
		);
		Links::write( self::$ar, Links::POST, $ar, array() );
		$this->assertFalse( get_blog_option( self::$ar, 'msls_' . $ar ), 'No links: no option.' );
	}

	public function test_audit_lists_links_that_no_longer_fit(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		$en      = $this->post_on( self::$main );
		$fr      = $this->post_on( self::$fr );
		$stray   = $this->post_on( self::$fr );
		$fitting = $this->post_on( self::$main );
		$back    = $this->post_on( self::$fr );
		update_blog_option( self::$fr, 'msls_' . $back, array( 'en_US' => $fitting ) );
		update_blog_option( self::$main, 'msls_' . $fitting, array( 'fr_FR' => $back ) );
		update_blog_option(
			self::$main,
			'msls_' . $en,
			array(
				'fr_FR' => $stray,
				'ar'    => 12,
				'en_US' => $en,
			)
		);
		update_blog_option( self::$main, 'msls_77', array( 'fr_FR' => 999999 ) );
		update_blog_option( self::$main, 'msls_78', array( 'fr_FR' => 0 ) );
		update_blog_option( self::$fr, 'msls_' . $fr, array( 'ar' => 5 ) );
		update_blog_option( self::$social, 'msls_3', array( 'fr_FR' => 999999 ) );

		$found = array();
		foreach ( Links::audit() as $problem ) {
			$found[ $problem['site'] . ':' . $problem['id'] . ':' . $problem['locale'] ] = $problem['reason'];
		}
		ksort( $found );
		$expected = array(
			self::$main . ':' . $en . ':ar'    => 'no-site',
			self::$main . ':' . $en . ':en_US' => 'own-language',
			self::$main . ':' . $en . ':fr_FR' => 'not-linked-back',
			self::$main . ':77:fr_FR'          => 'missing',
			self::$main . ':78:fr_FR'          => 'invalid',
			self::$fr . ':' . $fr . ':ar'      => 'no-site',
		);
		ksort( $expected );
		$this->assertSame( $expected, $found, 'Fitting links and sites in no group are not listed.' );
		$this->assertCount( 1, Links::audit( self::$fr ) );
	}

	public function test_remove_checks_again_and_keeps_the_rest(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		$en = $this->post_on( self::$main );
		$fr = $this->post_on( self::$fr );
		update_blog_option(
			self::$main,
			'msls_' . $en,
			array(
				'fr_FR' => $fr,
				'ar'    => 12,
			)
		);
		update_blog_option( self::$fr, 'msls_' . $fr, array( 'en_US' => $en ) );

		$result = Links::remove(
			array(
				array(
					'site'   => self::$main,
					'kind'   => Links::POST,
					'id'     => $en,
					'locale' => 'ar',
				),
				array(
					'site'   => self::$main,
					'kind'   => Links::POST,
					'id'     => $en,
					'locale' => 'fr_FR',
				),
			)
		);

		$this->assertSame(
			array(
				'removed' => 1,
				'kept'    => 1,
			),
			$result,
			'The French link fits: kept.'
		);
		$this->assertSame( array( 'fr_FR' => $fr ), get_blog_option( self::$main, 'msls_' . $en ) );
		$this->assertSame( array(), Links::audit() );
	}
}
