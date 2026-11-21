<?php
/**
 * Media references in blocks (Copy\Content): found and moved, other content untouched.
 *
 * @package Windrose\Sabir
 */

use Windrose\Sabir\Copy\Content;

/**
 * @covers \Windrose\Sabir\Copy\Content
 */
class ContentTest extends WP_UnitTestCase {

	private const SRC = 'https://example.org/wp-content/uploads/2026/10/field-300x225.jpg';

	public function test_finds_every_kind_of_reference(): void {
		$content = '<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="' . self::SRC . '" class="wp-image-5"/></figure><!-- /wp:image -->'
			. '<!-- wp:gallery {"ids":[6,7]} --><figure class="wp-block-gallery"><!-- wp:image {"id":8} --><figure class="wp-block-image"><img src="x.jpg" data-id="8" class="wp-image-8"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->'
			. '<!-- wp:cover {"id":9,"url":"https://example.org/c.jpg"} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-9" src="https://example.org/c.jpg"/></div><!-- /wp:cover -->'
			. '<!-- wp:media-text {"mediaId":10} --><div class="wp-block-media-text"><figure><img src="m.jpg" class="wp-image-10 size-full"/></figure></div><!-- /wp:media-text -->'
			. '<!-- wp:video {"id":11} --><figure class="wp-block-video"><video src="v.mp4"></video></figure><!-- /wp:video -->'
			. '<!-- wp:file {"id":12,"href":"https://example.org/f.pdf"} --><div class="wp-block-file"><a href="https://example.org/f.pdf">f</a></div><!-- /wp:file -->'
			. '<p>Classic <img src="z.jpg" class="alignleft wp-image-13" /></p>';
		$this->assertSame( array( 5, 6, 7, 8, 9, 10, 11, 12, 13 ), Content::attachment_ids( $content ) );
	}

	public function test_moves_ids_classes_and_copied_file_addresses(): void {
		$content = '<!-- wp:image {"id":5,"sizeSlug":"medium"} -->' . "\n" . '<figure class="wp-block-image size-medium"><img src="' . self::SRC . '" alt="" class="wp-image-5"/><figcaption>Credit: Jane</figcaption></figure>' . "\n" . '<!-- /wp:image -->';
		$new     = Content::rewrite(
			$content,
			array( 5 => 42 ),
			array( 5 => array( 'field-300x225.jpg' => 'https://example.org/fr/wp-content/uploads/sites/2/2026/10/field-300x225.jpg' ) )
		);
		$this->assertStringContainsString( '"id":42', $new );
		$this->assertStringContainsString( 'class="wp-image-42"', $new );
		$this->assertStringContainsString( 'src="https://example.org/fr/wp-content/uploads/sites/2/2026/10/field-300x225.jpg"', $new );
		$this->assertStringContainsString( 'Credit: Jane', $new );
		$parsed = parse_blocks( $new );
		$this->assertSame( 'core/image', $parsed[0]['blockName'] );
	}

	public function test_a_reference_mapped_to_zero_is_removed(): void {
		$content = '<!-- wp:gallery {"ids":[6,7]} --><figure class="wp-block-gallery"><!-- wp:image {"id":6} --><figure class="wp-block-image"><img src="' . self::SRC . '" data-id="6" class="wp-image-6"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->';
		$new     = Content::rewrite(
			$content,
			array(
				6 => 0,
				7 => 70,
			),
			array()
		);
		$this->assertStringContainsString( '{"ids":[70]}', $new );
		$this->assertStringNotContainsString( 'wp-image-6', $new );
		$this->assertStringNotContainsString( 'data-id', $new );
		$this->assertStringContainsString( self::SRC, $new, 'The file address stays.' );
		$this->assertStringContainsString( '<!-- wp:image -->', $new, 'No id attribute left.' );
	}

	public function test_classic_images_and_block_attributes(): void {
		$classic = '<p>Hello <img class="alignleft wp-image-13" src="https://example.org/wp-content/uploads/2026/10/z.jpg" /> and <img src="https://example.org/other.jpg" /></p>';
		$new     = Content::rewrite( $classic, array( 13 => 31 ), array( 13 => array( 'z.jpg' => 'https://example.org/fr/z.jpg' ) ) );
		$this->assertSame( '<p>Hello <img class="alignleft wp-image-31" src="https://example.org/fr/z.jpg" /> and <img src="https://example.org/other.jpg" /></p>', $new );

		$cover = '<!-- wp:cover {"id":9,"url":"https://example.org/wp-content/uploads/c.jpg"} --><div class="wp-block-cover"><img class="wp-image-9" src="https://example.org/wp-content/uploads/c.jpg"/></div><!-- /wp:cover -->';
		$new   = Content::rewrite( $cover, array( 9 => 90 ), array( 9 => array( 'c.jpg' => 'https://example.org/fr/c.jpg' ) ) );
		$this->assertStringContainsString( '"url":"https://example.org/fr/c.jpg"', $new );
		$this->assertStringContainsString( 'wp-image-90', $new );
	}

	public function test_content_without_change_is_returned_as_it_was(): void {
		$content = "<!-- wp:paragraph -->\n<p>Unusual   spacing &amp; \u{00e9}</p>\n<!-- /wp:paragraph -->";
		$this->assertSame( $content, Content::rewrite( $content, array(), array() ) );
		$this->assertSame( $content, Content::rewrite( $content, array( 5 => 5 ), array() ), 'Same ID on both sites: nothing to do.' );
		$this->assertSame( 'a', Content::replace_urls( 'a', array() ) );
	}
}
