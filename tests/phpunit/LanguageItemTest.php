<?php
/**
 * The language item block: MSLS's links for the current page, names in their own language, lang and
 * hreflang, aria-current, inside and outside a Navigation block.
 *
 * @package Windrose\Sabir
 */

use lloc\Msls\Registry\Registry;
use Windrose\Sabir\Language_Item;

/**
 * @covers \Windrose\Sabir\Language_Item
 */
class LanguageItemTest extends Sabir_TestCase {

	private int $en      = 0;
	private int $fr_post = 0;

	public function set_up(): void {
		parent::set_up();
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		$this->en = self::factory()->post->create( array( 'post_title' => 'Fields' ) );
		switch_to_blog( self::$fr );
		$this->fr_post = self::factory()->post->create( array( 'post_title' => 'Champs' ) );
		update_option( 'msls_' . $this->fr_post, array( 'en_US' => $this->en ) );
		restore_current_blog();
		update_option( 'msls_' . $this->en, array( 'fr_FR' => $this->fr_post ) );
	}

	/**
	 * Renders the block for the page of a post.
	 *
	 * @param int                  $post    Post ID on the main site.
	 * @param array<string, mixed> $attrs   Attributes.
	 * @param array<string, mixed> $context Block context (a Navigation block gives maxNestingLevel).
	 * @return string
	 */
	private function render_for( int $post, array $attrs = array(), array $context = array() ): string {
		$this->go_to( get_permalink( $post ) );
		Registry::clear();
		$block = new WP_Block(
			array(
				'blockName'    => Language_Item::NAME,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			$context
		);
		return $block->render();
	}

	public function test_links_to_the_translation_with_lang_and_hreflang(): void {
		$html = $this->render_for( $this->en );
		$fr   = get_blog_permalink( self::$fr, $this->fr_post );

		$this->assertStringStartsWith( '<ul class="windrose-language-item wp-block-windrose-language-item">', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $this->en ) ) . '" hreflang="en" lang="en" aria-current="page"><span translate="no">English</span>', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $fr ) . '" hreflang="fr" lang="fr"><span translate="no">Français</span>', $html );
	}

	public function test_inside_a_navigation_block_each_language_is_an_item(): void {
		$html = $this->render_for( $this->en, array(), array( 'maxNestingLevel' => 5 ) );
		$this->assertStringStartsWith( '<li class="wp-block-navigation-item windrose-language-item__item is-current"><a class="wp-block-navigation-item__content"', $html );
		$this->assertSame( 2, substr_count( $html, '<li class="wp-block-navigation-item' ) );
		$this->assertStringContainsString( '<span class="wp-block-navigation-item__label">', $html );
		$this->assertStringNotContainsString( '<ul', $html );
	}

	public function test_codes_labels_and_options(): void {
		$html = $this->render_for( $this->en, array( 'display' => 'code' ) );
		$this->assertStringContainsString( '<span translate="no">FR</span><span class="windrose-language-item__name"> (Français)</span>', $html );

		$html = $this->render_for( $this->en, array( 'display' => 'label' ) );
		$this->assertStringContainsString( '>Français</a>', $html, 'MSLS\'s label of the site.' );

		$html = $this->render_for( $this->en, array( 'showCurrent' => false ) );
		$this->assertStringNotContainsString( 'aria-current', $html );
		$this->assertStringContainsString( 'hreflang="fr"', $html );

		$alone = self::factory()->post->create();
		$this->assertStringContainsString( 'href="' . esc_url( get_home_url( self::$fr, '/' ) ) . '" hreflang="fr"', $this->render_for( $alone ), 'Without a translation: the site\'s home page.' );
		$this->assertStringNotContainsString( 'hreflang="fr"', $this->render_for( $alone, array( 'hideMissing' => true ) ) );
	}

	public function test_nothing_on_a_site_in_no_group(): void {
		switch_to_blog( self::$social );
		$post = self::factory()->post->create();
		$html = $this->render_for( $post );
		restore_current_blog();
		$this->assertSame( '', $html );
	}

	public function test_names_and_tags(): void {
		$this->assertSame( 'Français', Language_Item::name( 'fr_FR', array( 'en_US', 'fr_FR' ) ) );
		$this->assertSame( 'العربية', Language_Item::name( 'ar', array( 'ar', 'en_US' ) ) );
		$this->assertStringContainsString( 'United Kingdom', Language_Item::name( 'en_GB', array( 'en_US', 'en_GB' ) ), 'Two Englishes: with the region.' );
		$this->assertSame( 'fr', Language_Item::tag( 'fr_FR', array( 'en_US', 'fr_FR' ) ) );
		$this->assertSame( 'en-GB', Language_Item::tag( 'en_GB', array( 'en_US', 'en_GB' ) ) );
		$this->assertSame( 'de-CH', Language_Item::tag( 'de_CH_informal', array( 'de_DE', 'de_CH_informal' ) ) );
	}

	public function test_core_navigation_accepts_the_block(): void {
		$navigation = WP_Block_Type_Registry::get_instance()->get_registered( 'core/navigation' );
		$this->assertContains( Language_Item::NAME, $navigation->allowed_blocks );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( Language_Item::NAME ) );
	}
}
