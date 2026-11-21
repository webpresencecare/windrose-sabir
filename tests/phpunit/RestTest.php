<?php
/**
 * REST routes windrose-sabir/v1/groups.
 *
 * @package Windrose\Sabir
 */

use Windrose\Sabir\Groups;

/**
 * @covers \Windrose\Sabir\Rest
 */
class RestTest extends Sabir_TestCase {

	private function request( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/windrose-sabir/v1' . $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_network_administrators_only(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', '/groups' )->get_status() );

		wp_set_current_user( self::$editor );
		$this->assertSame( 403, $this->request( 'GET', '/groups' )->get_status() );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->assertSame( 403, $this->request( 'GET', '/groups' )->get_status(), 'A site administrator is not a network administrator.' );
		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/groups',
				array(
					'label' => 'x',
					'sites' => array( self::$main, self::$fr ),
				)
			)->get_status()
		);
		$this->assertFalse( Groups::exist() );
	}

	public function test_get_lists_groups_and_sites(): void {
		$this->super_admin();
		update_blog_option( self::$social, 'msls', array( 'exclude_current_blog' => 1 ) );
		delete_blog_option( self::$ar, 'msls' );
		$id = $this->group( 'Windrose', array( self::$main, self::$fr ) );

		$data = $this->request( 'GET', '/groups' )->get_data();

		$this->assertSame(
			array(
				array(
					'id'    => $id,
					'label' => 'Windrose',
					'main'  => 0,
					'sites' => array( self::$main, self::$fr ),
				),
			),
			$data['groups']
		);
		$sites = array_column( $data['sites'], null, 'id' );
		$this->assertSame( array( self::$main, self::$fr, self::$ar, self::$social ), array_keys( $sites ) );
		$this->assertSame( 'fr_FR', $sites[ self::$fr ]['locale'] );
		$this->assertSame( class_exists( 'Locale' ) ? 'Français (France)' : 'fr_FR', $sites[ self::$fr ]['language'] );
		$this->assertSame( 'configured', $sites[ self::$fr ]['msls'] );
		$this->assertSame( 'missing', $sites[ self::$ar ]['msls'] );
		$this->assertSame( 'excluded', $sites[ self::$social ]['msls'] );
		$this->assertSame( $id, $sites[ self::$fr ]['group'] );
		$this->assertSame( 0, $sites[ self::$social ]['group'] );
		$this->assertStringEndsWith( '/fr/wp-admin/options-general.php?page=MslsAdmin', $sites[ self::$fr ]['settings'] );
		$this->assertSame( 'public', $sites[ self::$fr ]['status'] );
	}

	public function test_create_update_delete(): void {
		$this->super_admin();

		$created = $this->request(
			'POST',
			'/groups',
			array(
				'label' => 'Windrose',
				'sites' => array( self::$main, self::$fr ),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$id = $created->get_data()['saved'];
		$this->assertSame( array( self::$main, self::$fr ), $created->get_data()['groups'][0]['sites'] );

		$updated = $this->request(
			'PUT',
			'/groups/' . $id,
			array(
				'label' => 'example.com',
				'sites' => array( self::$main, self::$fr, self::$ar ),
				'main'  => self::$main,
			)
		);
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( self::$main, $updated->get_data()['groups'][0]['main'] );
		$this->assertSame( 'example.com', $updated->get_data()['groups'][0]['label'] );
		$this->assertSame( $id, Groups::of_site( self::$ar ) );

		$deleted = $this->request( 'DELETE', '/groups/' . $id );
		$this->assertSame( 200, $deleted->get_status() );
		$this->assertSame( array(), $deleted->get_data()['groups'] );
		$this->assertFalse( Groups::exist() );

		$this->assertSame( 404, $this->request( 'DELETE', '/groups/' . $id )->get_status() );
	}

	public function test_invalid_input_answers_400_with_the_reason(): void {
		$this->super_admin();

		$response = $this->request(
			'POST',
			'/groups',
			array(
				'label' => 'Windrose',
				'sites' => array( self::$main, self::$social ),
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'both use the language en_US', $response->get_data()['message'] );

		$this->assertSame( 400, $this->request( 'POST', '/groups', array( 'label' => 'Windrose' ) )->get_status(), 'Sites are required.' );
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/groups',
				array(
					'label' => 'Windrose',
					'sites' => 'all',
				)
			)->get_status()
		);
		$this->assertFalse( Groups::exist() );
	}
}
