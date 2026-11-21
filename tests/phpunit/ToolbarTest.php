<?php
/**
 * The toolbar follows the language display: MSLS's flags after site names become language names or
 * codes, once a group exists.
 *
 * @package Windrose\Sabir
 */

use Windrose\Sabir\Modules;
use Windrose\Sabir\Toolbar;

/**
 * @covers \Windrose\Sabir\Toolbar
 */
class ToolbarTest extends Sabir_TestCase {

	private const FLAG = 'Windrose FR <span class="msls-icon-wrapper flag"><span class="flag-icon flag-icon-fr">fr_FR</span></span>';

	public function test_title_names_codes_and_untouched(): void {
		$this->assertSame(
			'Windrose FR <span class="windrose-sabir-toolbar-language" lang="fr-FR">(Français)</span>',
			Toolbar::title( self::FLAG, 'fr_FR', 'Français', 'names' )
		);
		$this->assertSame(
			'Windrose FR <span class="windrose-sabir-toolbar-language" lang="fr-FR">(FR)</span>',
			Toolbar::title( self::FLAG, 'fr_FR', 'Français', 'codes' )
		);
		$this->assertStringContainsString( '(Français (France))', (string) Toolbar::title( self::FLAG, 'fr_FR', 'fr_FR', 'names' ), 'No MSLS description: the name in its own language.' );
		$this->assertStringContainsString( '(&lt;b&gt;)', (string) Toolbar::title( self::FLAG, 'fr_FR', '<b>', 'names' ), 'Escaped.' );
		$this->assertNull( Toolbar::title( 'Windrose FR', 'fr_FR', 'Français', 'names' ), 'MSLS added no icon: untouched.' );
	}

	public function test_toolbar_after_msls(): void {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$this->group( 'Windrose', array( self::$main, self::$fr ) );

		$build = static function (): WP_Admin_Bar {
			$bar = new WP_Admin_Bar();
			$bar->add_node(
				array(
					'id'    => 'site-name',
					'title' => 'Main site',
				)
			);
			( new \lloc\Msls\Admin\Bar( msls_options(), msls_blog_collection() ) )->update_admin_bar( $bar );
			Toolbar::update( $bar );
			return $bar;
		};

		$title = (string) $build()->get_node( 'site-name' )->title;
		$this->assertStringNotContainsString( 'flag-icon', $title );
		$this->assertStringContainsString( 'windrose-sabir-toolbar-language', $title );

		Modules::save_display( 'flags' );
		$this->assertStringNotContainsString( 'windrose-sabir-toolbar-language', (string) $build()->get_node( 'site-name' )->title, 'Flags: MSLS as configured.' );

		Modules::save_display( 'names' );
		Modules::save( array( 'site_editor' => false ) );
		$this->assertStringNotContainsString( 'windrose-sabir-toolbar-language', (string) $build()->get_node( 'site-name' )->title, 'Site Editor module off.' );
	}
}
