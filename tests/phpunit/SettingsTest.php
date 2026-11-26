<?php
/**
 * Per-site settings (Site_Settings) on MSLS's settings page, and the network settings, links and logbook routes.
 *
 * @package Windrose\Sabir
 */

use Windrose\Sabir\Modules;
use Windrose\Sabir\Msls_Settings;
use Windrose\Sabir\Site_Settings;

/**
 * @covers \Windrose\Sabir\Site_Settings
 * @covers \Windrose\Sabir\Msls_Settings
 * @covers \Windrose\Sabir\Rest
 * @covers \Windrose\Sabir\Copy\Editor
 */
class SettingsTest extends Sabir_TestCase {

	private function request( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/windrose-sabir/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $body );
		} elseif ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_site_settings_defaults_and_sanitising(): void {
		$this->assertSame(
			array(
				'post_types' => null,
				'terms'      => 'create',
			),
			Site_Settings::get()
		);
		$this->assertContains( 'post', Site_Settings::copy_post_types() );
		$this->assertContains( 'page', Site_Settings::copy_post_types() );
		$this->assertNotContains( 'attachment', Site_Settings::copy_post_types() );

		$this->assertSame(
			array(
				'post_types' => array( 'page' ),
				'terms'      => 'skip',
			),
			Site_Settings::sanitize(
				array(
					'form'       => '1',
					'post_types' => array( 'page', 'nonsense', 'attachment' ),
					'terms'      => 'skip',
				)
			)
		);
		update_option( Site_Settings::OPTION, array( 'post_types' => array( 'page' ) ) );
		$this->assertSame( array( 'page' ), Site_Settings::copy_post_types() );
		$this->assertSame( array( 'page' ), Site_Settings::sanitize( null )['post_types'], 'MSLS\'s form without our fields keeps what is stored.' );
		$this->assertSame(
			'create',
			Site_Settings::sanitize(
				array(
					'form'  => '1',
					'terms' => 'bogus',
				)
			)['terms']
		);
		$this->assertSame( array(), Site_Settings::sanitize( array( 'form' => '1' ) )['post_types'], 'Form shown, no box ticked.' );
		$once = Site_Settings::sanitize(
			array(
				'form'  => '1',
				'terms' => 'skip',
			)
		);
		$this->assertSame( $once, Site_Settings::sanitize( $once ), 'Cleaning twice changes nothing (first save).' );

		// The first save through the form, as options.php does it.
		delete_option( Site_Settings::OPTION );
		do_action( 'msls_admin_register', 'lloc\\Msls\\MslsAdmin' );
		update_option(
			Site_Settings::OPTION,
			array(
				'form'       => '1',
				'post_types' => array( 'post' ),
				'terms'      => 'skip',
			)
		);
		$this->assertSame(
			array(
				'post_types' => array( 'post' ),
				'terms'      => 'skip',
			),
			get_option( Site_Settings::OPTION )
		);
	}

	public function test_copy_section_on_the_msls_settings_page(): void {
		global $wp_settings_sections, $wp_settings_fields, $wp_registered_settings;
		$page = 'lloc\\Msls\\MslsAdmin';
		do_action( 'msls_admin_register', $page );
		$this->assertArrayNotHasKey( 'windrose_sabir_copy', $wp_settings_sections[ $page ] ?? array(), 'Not in a group: no Copy section.' );
		$this->assertArrayHasKey( Site_Settings::OPTION, $wp_registered_settings );

		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		do_action( 'msls_admin_register', $page );
		$this->assertArrayHasKey( 'windrose_sabir_copy', $wp_settings_sections[ $page ] );
		$this->assertArrayHasKey( 'windrose_sabir_post_types', $wp_settings_fields[ $page ]['windrose_sabir_copy'] );

		ob_start();
		Msls_Settings::copy_section();
		Msls_Settings::post_types_field();
		Msls_Settings::terms_field();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="windrose_sabir_site[form]"', $html );
		$this->assertStringContainsString( 'name="windrose_sabir_site[post_types][]" value="post" checked', $html );
		$this->assertStringContainsString( 'name="windrose_sabir_site[terms]" value="create" checked', $html );
	}

	public function test_settings_route(): void {
		wp_set_current_user( self::$editor );
		$this->assertSame( 403, $this->request( 'GET', '/settings' )->get_status() );

		$this->super_admin();
		$data = $this->request( 'GET', '/settings' )->get_data();
		$this->assertSame( 'names', $data['display'] );
		$this->assertTrue( $data['modules']['copy'] );

		$response = $this->request(
			'POST',
			'/settings',
			array(
				'modules' => array( 'copy' => false ),
				'display' => 'codes',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( Modules::on( 'copy' ) );
		$this->assertTrue( Modules::on( 'logbook' ) );
		$this->assertSame( 'codes', Modules::display() );
		$this->assertSame( 400, $this->request( 'POST', '/settings', array( 'display' => 'emoji' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/settings', array( 'modules' => array( 'unknown' => true ) ) )->get_status() );
	}

	public function test_the_links_tab_names_posts_also_in_the_trash(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		switch_to_blog( self::$main );
		$live  = self::factory()->post->create( array( 'post_title' => 'Harbour' ) );
		$trash = self::factory()->post->create( array( 'post_title' => 'Old harbour' ) );
		wp_trash_post( $trash );
		restore_current_blog();
		update_blog_option( self::$main, 'msls_' . $live, array( 'fr_FR' => 999999 ) );
		update_blog_option( self::$main, 'msls_' . $trash, array( 'fr_FR' => 999998 ) );

		$this->super_admin();
		$items  = $this->request( 'GET', '/links' )->get_data()['items'];
		$titles = array_column( $items, 'title', 'id' );
		$edits  = array_column( $items, 'edit', 'id' );
		$this->assertSame( 'Harbour', $titles[ $live ] ?? null );
		$this->assertSame( 'Old harbour', $titles[ $trash ] ?? null );
		$this->assertNotSame( '', $edits[ $live ] ?? '' );
		$this->assertSame( '', $edits[ $trash ] ?? null, 'No edit link for a post in the trash.' );
	}

	public function test_links_routes(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		update_blog_option( self::$main, 'msls_77', array( 'fr_FR' => 999999 ) );

		wp_set_current_user( self::$editor );
		$this->assertSame( 403, $this->request( 'GET', '/links' )->get_status() );
		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/links/remove',
				array(
					'items' => array(
						array(
							'site'   => 1,
							'kind'   => 'post',
							'id'     => 77,
							'locale' => 'fr_FR',
						),
					),
				)
			)->get_status()
		);

		$this->super_admin();
		$data = $this->request( 'GET', '/links' )->get_data();
		$this->assertTrue( $data['groups'] );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( 'missing', $data['items'][0]['reason'] );
		$this->assertSame( 'Français (France)', $data['items'][0]['language'] );
		$this->assertSame( '(no title, ID 77)', $data['items'][0]['title'] );

		$item     = $data['items'][0];
		$response = $this->request(
			'POST',
			'/links/remove',
			array(
				'items' => array(
					array(
						'site'   => $item['site'],
						'kind'   => $item['kind'],
						'id'     => $item['id'],
						'locale' => $item['locale'],
					),
				),
			)
		);
		$this->assertSame( 1, $response->get_data()['removed'] );
		$this->assertSame( array(), $response->get_data()['items'] );
		$this->assertFalse( get_blog_option( self::$main, 'msls_77' ) );
	}

	public function test_logbook_route(): void {
		$this->super_admin();
		$data = $this->request( 'GET', '/logbook' )->get_data();
		$this->assertSame( 4, $data['sites'] );
		$this->assertStringContainsString( 'admin-post.php?action=windrose_sabir_logbook&format=csv&_wpnonce=', $data['csv'], 'A plain address, not HTML-escaped.' );
		$this->assertStringContainsString( '_wpnonce=', $data['json'] );

		Modules::save( array( 'logbook' => false ) );
		$this->assertSame( 403, $this->request( 'GET', '/logbook' )->get_status() );
	}

	public function test_copy_routes(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		$this->super_admin();
		$post    = self::factory()->post->create( array( 'post_title' => 'Fields' ) );
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', '/copy/' . $post )->get_status() );
		$this->user_with( array( self::$main => 'subscriber' ) );
		$this->assertSame( 403, $this->request( 'GET', '/copy/' . $private )->get_status(), 'Only readable posts.' );

		$this->super_admin();
		$targets = $this->request( 'GET', '/copy/' . $post )->get_data()['targets'];
		$this->assertSame( array( self::$fr ), wp_list_pluck( $targets, 'id' ) );

		$response = $this->request( 'POST', '/copy/' . $post, array( 'sites' => array( self::$fr, self::$social ) ) );
		$this->assertSame( 200, $response->get_status() );
		$results = $response->get_data()['results'];
		$this->assertTrue( $results[0]['ok'] );
		$this->assertFalse( $results[1]['ok'] );
		$this->assertSame( 'windrose_sabir_copy_target', $results[1]['code'] );
		$this->assertSame( 'Fields', $response->get_data()['targets'][0]['translation']['title'] );
		$this->user_with( array( self::$main => 'subscriber' ) );
		$hidden = $this->request( 'GET', '/copy/' . $post )->get_data()['targets'][0]['translation'];
		$this->assertGreaterThan( 0, $hidden['id'], 'The translation exists, so Copy stays refused.' );
		$this->assertSame( array( '', '' ), array( $hidden['title'], $hidden['status'] ), 'No title or status of a French draft for a reader without rights there.' );
		$this->super_admin();
		switch_to_blog( self::$fr );
		wp_update_post(
			array(
				'ID'          => $hidden['id'],
				'post_status' => 'publish',
			)
		);
		restore_current_blog();
		$this->user_with( array( self::$main => 'editor' ) );
		$this->assertSame( 'Fields', $this->request( 'GET', '/copy/' . $post )->get_data()['targets'][0]['translation']['title'], 'A published translation: readable by anyone, member of the French site or not.' );
		$this->super_admin();
		$this->assertSame( 400, $this->request( 'POST', '/copy/' . $post, array( 'sites' => array() ) )->get_status() );
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/copy/' . $post,
				array(
					'sites'  => array( self::$fr ),
					'images' => 'both',
				)
			)->get_status()
		);
		$this->assertArrayHasKey( 'anchors', $this->request( 'GET', '/copy/' . $post )->get_data() );

		Modules::save( array( 'copy' => false ) );
		$this->assertSame( 403, $this->request( 'GET', '/copy/' . $post )->get_status() );
	}
}
