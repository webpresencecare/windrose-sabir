<?php
/**
 * uninstall.php removes the groups and their site meta, nothing else.
 *
 * @package Windrose\Sabir
 */

use Windrose\Sabir\Groups;

/**
 * @coversNothing
 */
class UninstallTest extends Sabir_TestCase {

	public function test_uninstall_removes_sabir_data_only(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		update_site_meta( self::$fr, 'other_plugin_meta', 'kept' );
		\Windrose\Sabir\Modules::save( array( 'copy' => false ) );
		\Windrose\Sabir\Modules::save_display( 'codes' );
		update_blog_option( self::$fr, 'windrose_sabir_site', array( 'terms' => 'skip' ) );
		update_blog_option( self::$fr, 'msls_5', array( 'en_US' => 3 ) );
		switch_to_blog( self::$fr );
		$copy = self::factory()->attachment->create( array( 'post_title' => 'Copy' ) );
		update_post_meta( $copy, '_windrose_sabir_source', '1:4' );
		restore_current_blog();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'windrose-sabir/windrose-sabir.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_site_option( Groups::OPTION ) );
		$this->assertFalse( metadata_exists( 'blog', self::$fr, Groups::META ) );
		$this->assertSame( 'kept', get_site_meta( self::$fr, 'other_plugin_meta', true ) );
		$this->assertIsArray( get_blog_option( self::$fr, 'msls' ), 'MSLS settings are not touched.' );
		$this->assertSame( array( 'en_US' => 3 ), get_blog_option( self::$fr, 'msls_5' ), 'Translation links stay (they are MSLS\'s).' );
		$this->assertFalse( get_site_option( 'windrose_sabir_modules' ) );
		$this->assertFalse( get_site_option( 'windrose_sabir_display' ) );
		$this->assertFalse( get_blog_option( self::$fr, 'windrose_sabir_site' ) );
		switch_to_blog( self::$fr );
		$this->assertInstanceOf( WP_Post::class, get_post( $copy ), 'Copied media stay.' );
		$this->assertSame( '', get_post_meta( $copy, '_windrose_sabir_source', true ) );
		restore_current_blog();
	}
}
