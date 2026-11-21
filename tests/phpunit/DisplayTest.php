<?php
/**
 * Language display of MSLS's own switcher: no flags with names or codes, once a group exists.
 *
 * @package Windrose\Sabir
 */

use lloc\Msls\Link\Link;
use lloc\Msls\Link\TextOnly;
use Windrose\Sabir\Code_Link;
use Windrose\Sabir\Display;
use Windrose\Sabir\Modules;

/**
 * @covers \Windrose\Sabir\Display
 * @covers \Windrose\Sabir\Code_Link
 * @covers \Windrose\Sabir\Modules
 */
class DisplayTest extends Sabir_TestCase {

	public function test_link_type_by_setting(): void {
		$link = new Link();
		$this->assertSame( $link, Display::link( $link, 0 ), 'No group yet: MSLS as configured.' );

		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		$this->assertSame( 'names', Modules::display() );
		$this->assertInstanceOf( TextOnly::class, Display::link( $link, 0 ) );

		Modules::save_display( 'codes' );
		$this->assertInstanceOf( Code_Link::class, Display::link( $link, 0 ) );

		Modules::save_display( 'flags' );
		$this->assertSame( $link, Display::link( $link, 0 ) );

		$this->assertFalse( Modules::save_display( 'emoji' ) );
		$this->assertSame( 'flags', Modules::display() );

		Modules::save_display( 'names' );
		Modules::save( array( 'site_editor' => false ) );
		$this->assertSame( $link, Display::link( $link, 0 ), 'Site Editor module off.' );
	}

	public function test_msls_switcher_shows_no_flag_with_names(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		$created = \lloc\Msls\Link\Link::create( 0 );
		$this->assertInstanceOf( TextOnly::class, $created, 'Through MSLS\'s own factory and filter.' );
	}

	public function test_code_link_text(): void {
		$link      = new Code_Link();
		$link->alt = 'fr_FR';
		$link->txt = 'Français';
		$this->assertSame( '<span translate="no">FR</span><span class="windrose-language-item__name"> (Français)</span>', (string) $link );
	}

	public function test_modules_default_on_and_save(): void {
		$this->assertSame(
			array(
				'copy'        => true,
				'site_editor' => true,
				'logbook'     => true,
			),
			Modules::all()
		);
		Modules::save(
			array(
				'logbook' => false,
				'unknown' => true,
			)
		);
		$this->assertFalse( Modules::on( 'logbook' ) );
		$this->assertTrue( Modules::on( 'copy' ) );
		$this->assertFalse( Modules::on( 'unknown' ) );
	}
}
