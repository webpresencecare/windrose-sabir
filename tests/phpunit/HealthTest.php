<?php
/**
 * Site Health test windrose_sabir_language_group, and the section on MSLS's settings page.
 *
 * @package Windrose\Sabir
 */

use Windrose\Sabir\Health;
use Windrose\Sabir\Msls_Settings;

/**
 * @covers \Windrose\Sabir\Health
 * @covers \Windrose\Sabir\Msls_Settings
 */
class HealthTest extends Sabir_TestCase {

	private function result_on( int $site ): array {
		switch_to_blog( $site );
		$result = Health::test();
		restore_current_blog();
		return $result;
	}

	public function test_registered_as_a_direct_test(): void {
		$tests = apply_filters(
			'site_status_tests',
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);
		$this->assertArrayHasKey( Health::TEST, $tests['direct'] );
		$this->assertIsCallable( $tests['direct'][ Health::TEST ]['test'] );
	}

	public function test_no_group_on_the_network(): void {
		$result = $this->result_on( self::$fr );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'No language group is set up yet', $result['label'] );
		$this->assertSame( Health::TEST, $result['test'] );
	}

	public function test_each_situation(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr, self::$ar ) );
		delete_blog_option( self::$ar, 'msls' );

		$fr = $this->result_on( self::$fr );
		$this->assertSame( 'good', $fr['status'] );
		$this->assertSame( 'This site is in the language group “Windrose”', $fr['label'] );

		$ar = $this->result_on( self::$ar );
		$this->assertSame( 'recommended', $ar['status'] );
		$this->assertStringContainsString( 'is not set up here', $ar['label'] );

		$social = $this->result_on( self::$social );
		$this->assertSame( 'recommended', $social['status'] );
		$this->assertSame( 'This site is in no language group', $social['label'] );

		update_blog_option( self::$social, 'msls', array( 'exclude_current_blog' => 1 ) );
		$social = $this->result_on( self::$social );
		$this->assertSame( 'good', $social['status'] );
		$this->assertSame( 'This site is not a language site', $social['label'] );
	}

	public function test_two_sites_of_a_group_with_the_same_language(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr, self::$ar ) );
		update_blog_option( self::$ar, 'WPLANG', 'fr_FR' );

		$ar = $this->result_on( self::$ar );
		$this->assertSame( 'critical', $ar['status'] );
		$this->assertStringContainsString( 'uses the same language as this site (fr_FR)', $ar['label'] );
		$this->assertSame( 'critical', $this->result_on( self::$fr )['status'] );
		$this->assertNotSame( 'critical', $this->result_on( self::$main )['status'] );
	}

	public function test_actions_follow_the_users_rights(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );

		wp_set_current_user( self::$editor );
		$this->assertSame( '', $this->result_on( self::$social )['actions'] );

		$this->super_admin();
		$actions = $this->result_on( self::$social )['actions'];
		$this->assertStringContainsString( 'options-general.php?page=MslsAdmin', $actions );
		$this->assertStringContainsString( 'network/admin.php?page=windrose-sabir', $actions );
	}

	public function test_msls_settings_summary(): void {
		switch_to_blog( self::$fr );
		$this->assertStringContainsString( 'no language group yet', Msls_Settings::summary() );
		restore_current_blog();

		$this->group( 'Windrose', array( self::$main, self::$fr, self::$ar ) );
		switch_to_blog( self::$fr );
		$summary = Msls_Settings::summary();
		restore_current_blog();
		$this->assertStringContainsString( '“Windrose”', $summary );
		$this->assertStringContainsString( 'العربية', $summary );
		$this->assertStringNotContainsString( 'Français', $summary, 'The site itself is not listed.' );

		switch_to_blog( self::$social );
		$this->assertStringContainsString( 'in no language group', Msls_Settings::summary() );
		restore_current_blog();
	}

	public function test_section_on_the_msls_settings_page(): void {
		global $wp_settings_sections;
		do_action( 'msls_admin_register', 'lloc\\Msls\\MslsAdmin' );
		$this->assertArrayHasKey( 'windrose_sabir_group', $wp_settings_sections['lloc\\Msls\\MslsAdmin'] ?? array() );
	}

	/**
	 * A header template part with this content, for the language item test.
	 *
	 * @param string $content Block markup.
	 * @return void
	 */
	private function header( string $content ): void {
		add_filter(
			'pre_get_block_template',
			static function ( $template, $id, $type ) use ( $content ) {
				if ( 'wp_template_part' !== $type || ! str_ends_with( (string) $id, '//header' ) ) {
					return $template;
				}
				$part          = new WP_Block_Template();
				$part->id      = (string) $id;
				$part->content = $content;
				$part->type    = 'wp_template_part';
				return $part;
			},
			10,
			3
		);
	}

	public function test_language_item_in_the_header(): void {
		$this->assertSame( 'The theme has no header template part', Health::language_item_test()['label'] );

		$this->header( '<!-- wp:site-title /-->' );
		$this->assertSame( 'The header has no Navigation block', Health::language_item_test()['label'] );

		remove_all_filters( 'pre_get_block_template' );
		$this->header( '<!-- wp:navigation {"ref":0} /-->' );
		$this->assertSame( 'The header’s navigation has no language item', Health::language_item_test()['label'] );

		// The menu core falls back to (the most recent one) holds the item; the header uses it through a pattern.
		self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- wp:windrose/language-item /-->',
			)
		);
		register_block_pattern(
			'windrose-tests/header',
			array(
				'title'   => 'Header',
				'content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:navigation /--></div><!-- /wp:group -->',
			)
		);
		remove_all_filters( 'pre_get_block_template' );
		$this->header( '<!-- wp:pattern {"slug":"windrose-tests/header"} /-->' );
		$result = Health::language_item_test();
		unregister_block_pattern( 'windrose-tests/header' );
		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( Health::ITEM_TEST, $result['test'] );
	}

	public function test_language_item_in_navigation_shapes(): void {
		$item = '<!-- wp:windrose/language-item /-->';
		$menu = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->' . $item,
			)
		);
		// A menu without the item: the sibling cases point at it, so only the sibling can make them pass.
		$plain    = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
			)
		);
		$cases    = array(
			// The block's own items: the item is there.
			'good'        => '<!-- wp:navigation --><!-- wp:navigation-link {"label":"A","url":"/a"} /-->' . $item . '<!-- /wp:navigation -->',
			// Opener and closer with nothing between: its menu is read.
			'menu'        => '<!-- wp:navigation {"ref":' . $menu . '} --><!-- /wp:navigation -->',
			// Own items without the item: the menu is not read (core shows the own items).
			'own_items'   => '<!-- wp:navigation {"ref":' . $menu . '} --><!-- wp:navigation-link {"label":"A","url":"/a"} /--><!-- /wp:navigation -->',
			// A sibling right after a Navigation block is still seen.
			'sibling'     => '<!-- wp:group --><div class="wp-block-group"><!-- wp:navigation {"ref":' . $plain . '} /-->' . $item . '</div><!-- /wp:group -->',
			'sibling_raw' => '<!-- wp:navigation {"ref":' . $plain . '} --><!-- /wp:navigation -->' . $item,
			// Control: the same without the sibling.
			'no_sibling'  => '<!-- wp:navigation {"ref":' . $plain . '} --><!-- /wp:navigation -->',
		);
		$expected = array(
			'good'        => 'good',
			'menu'        => 'good',
			'own_items'   => 'recommended',
			'sibling'     => 'good',
			'sibling_raw' => 'good',
			'no_sibling'  => 'recommended',
		);
		foreach ( $cases as $key => $content ) {
			remove_all_filters( 'pre_get_block_template' );
			$this->header( $content );
			$this->assertSame( $expected[ $key ], Health::language_item_test()['status'], $key );
		}
	}

	public function test_translation_links_test(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		$tests = apply_filters(
			'site_status_tests',
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);
		$this->assertArrayHasKey( Health::LINKS_TEST, $tests['direct'] );
		$this->assertSame( 'good', Health::links_test()['status'] );

		update_option( 'msls_77', array( 'fr_FR' => 999999 ) );
		$result = Health::links_test();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( '1 translation link no longer fits the language group', $result['label'] );
	}
}
