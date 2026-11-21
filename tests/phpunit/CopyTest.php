<?php
/**
 * Copy without Windrose Anchors: a post copied to another site of its group as a linked draft, with its
 * media copied as files, terms, allowed custom fields, rights checked on the target site, and nothing left
 * switched or half made.
 *
 * @package Windrose\Sabir
 */

use lloc\Msls\ContentImport\Importers\BaseImporter;
use Windrose\Sabir\Copy\Bulk;
use Windrose\Sabir\Copy\Copier;
use Windrose\Sabir\Copy\Media;
use Windrose\Sabir\Modules;
use Windrose\Sabir\Site_Settings;

/**
 * @covers \Windrose\Sabir\Copy\Copier
 * @covers \Windrose\Sabir\Copy\Media
 * @covers \Windrose\Sabir\Copy\Terms
 * @covers \Windrose\Sabir\Copy\Importers
 * @covers \Windrose\Sabir\Copy\Importer_Fields
 * @covers \Windrose\Sabir\Copy\Importer_Meta
 * @covers \Windrose\Sabir\Copy\Importer_Thumbnail
 * @covers \Windrose\Sabir\Copy\Bulk
 */
class CopyTest extends Sabir_TestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'windrose_sabir_copy_network_media', '__return_false' );
		$this->group( 'Windrose', array( self::$main, self::$fr, self::$ar ) );
	}

	/**
	 * An article on the current site: image block, featured image, category, tag, custom fields.
	 *
	 * @param array<string, mixed> $args Post fields.
	 * @return array{0: int, 1: int} Post ID, image ID.
	 */
	private function article( array $args = array() ): array {
		$image = $this->image();
		$post  = self::factory()->post->create(
			array_merge(
				array(
					'post_title'   => 'Spring fields',
					'post_content' => $this->image_content( $image ),
					'post_excerpt' => 'About fields',
					'post_status'  => 'publish',
				),
				$args
			)
		);
		set_post_thumbnail( $post, $image );
		return array( $post, $image );
	}

	/**
	 * Attachments on a site.
	 *
	 * @param int $site Site ID.
	 * @return list<int>
	 */
	private function attachments_on( int $site ): array {
		switch_to_blog( $site );
		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'fields'      => 'ids',
				'numberposts' => -1,
			)
		);
		restore_current_blog();
		return array_map( 'intval', $ids );
	}

	public function test_copy_makes_a_linked_draft_with_media_terms_and_fields(): void {
		$this->super_admin();
		list( $post, $image ) = $this->article();
		$category             = self::factory()->category->create( array( 'name' => 'News' ) );
		wp_set_post_categories( $post, array( $category ) );
		wp_set_post_tags( $post, array( 'Spring' ) );
		update_post_meta( $post, '_wp_page_template', 'single-wide' );
		update_post_meta( $post, 'private_note', 'not copied' );
		$before = did_action( 'msls_content_import_after_import' );

		$result = Copier::copy( $post, self::$fr );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( self::$main, get_current_blog_id(), 'The source site is current again.' );
		$this->assertFalse( ms_is_switched() );
		$this->assertSame( $before + 1, did_action( 'msls_content_import_after_import' ), 'MSLS\'s import pipeline ran.' );

		switch_to_blog( self::$fr );
		$draft = get_post( $result['post'] );
		$this->assertInstanceOf( WP_Post::class, $draft );
		$this->assertSame( 'draft', $draft->post_status );
		$this->assertSame( 'Spring fields', $draft->post_title );
		$this->assertSame( 'About fields', $draft->post_excerpt );
		$copy = (int) get_post_thumbnail_id( $draft );
		$this->assertGreaterThan( 0, $copy, 'The featured image is a copy on the French site.' );
		$this->assertStringContainsString( '"id":' . $copy, $draft->post_content );
		$this->assertStringContainsString( 'wp-image-' . $copy, $draft->post_content );
		if ( $image !== $copy ) {
			$this->assertStringNotContainsString( 'wp-image-' . $image . '"', $draft->post_content );
		}
		$this->assertStringContainsString( 'Canola in May. Photo: Jane Doe', $draft->post_content, 'Caption and credit kept.' );
		$this->assertStringContainsString( '/sites/' . self::$fr . '/', $draft->post_content, 'The image address is the copy\'s.' );
		$this->assertStringContainsString( wp_basename( (string) wp_get_attachment_image_url( $copy, 'medium' ) ), $draft->post_content, 'Same size as in the source.' );
		$this->assertSame( 'Yellow canola field', get_post_meta( $copy, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Photo: Jane Doe', get_post( $copy )->post_excerpt );
		$this->assertSame( self::$main . ':' . $image, get_post_meta( $copy, Media::SOURCE_META, true ) );
		$this->assertSame( '', get_post_meta( $copy, '_msls_linked', true ), 'Never MSLS\'s link to another site\'s file.' );
		$this->assertFileExists( (string) get_attached_file( $copy ) );
		$this->assertSame( array( 'News' ), wp_get_post_categories( $draft->ID, array( 'fields' => 'names' ) ) );
		$this->assertSame( array( 'Spring' ), wp_get_post_tags( $draft->ID, array( 'fields' => 'names' ) ) );
		$this->assertSame( 'single-wide', get_post_meta( $draft->ID, '_wp_page_template', true ) );
		$this->assertSame( '', get_post_meta( $draft->ID, 'private_note', true ), 'Only allowed custom fields.' );
		$news = get_term_by( 'name', 'News', 'category' );
		restore_current_blog();

		$this->assertSame( array( 'fr_FR' => $draft->ID ), get_blog_option( self::$main, 'msls_' . $post ) );
		$this->assertSame( array( 'en_US' => $post ), get_blog_option( self::$fr, 'msls_' . $draft->ID ) );
		$this->assertFalse( get_blog_option( self::$main, 'msls_0' ), 'Nothing saved under msls_0 (MSLS bug 3).' );
		$this->assertFalse( get_blog_option( self::$fr, 'msls_0' ) );
		$this->assertInstanceOf( WP_Term::class, $news );
		$this->assertSame( array( 'fr_FR' => $news->term_id ), get_blog_option( self::$main, 'msls_term_' . $category ), 'The new term is linked.' );
		$this->assertSame( 'publish', get_post_status( $post ), 'The source is unchanged.' );

		$this->assertCount( 1, $result['images'] );
		$this->assertSame( 'copy', $result['images'][0]['how'] );
		$this->assertSame( 'no-anchors', $result['images'][0]['reason'] );
		$hows = wp_list_pluck( $result['terms'], 'how', 'name' );
		$this->assertSame( 'created', $hows['News'] );
		$this->assertStringContainsString( '/fr/wp-admin/post.php?post=' . $draft->ID, $result['edit'] );
	}

	public function test_a_post_with_a_translation_is_not_copied_again_and_files_are_reused(): void {
		$this->super_admin();
		list( $post, $image ) = $this->article();
		$this->assertIsArray( Copier::copy( $post, self::$fr ) );
		$count = count( $this->attachments_on( self::$fr ) );

		$again = Copier::copy( $post, self::$fr );
		$this->assertWPError( $again );
		$this->assertSame( 'windrose_sabir_copy_exists', $again->get_error_code() );

		// Another post with the same image: the earlier copy is used.
		$other  = self::factory()->post->create(
			array(
				'post_title'   => 'Summer fields',
				'post_content' => $this->image_content( $image ),
			)
		);
		$result = Copier::copy( $other, self::$fr );
		$this->assertIsArray( $result );
		$this->assertSame( 'earlier', $result['images'][0]['reason'] );
		$this->assertCount( $count, $this->attachments_on( self::$fr ), 'No second copy of the file.' );
	}

	public function test_the_new_draft_joins_the_existing_translations(): void {
		$this->super_admin();
		list( $post ) = $this->article();
		switch_to_blog( self::$ar );
		$arabic = self::factory()->post->create( array( 'post_title' => 'حقول' ) );
		update_option( 'msls_' . $arabic, array( 'en_US' => $post ) );
		restore_current_blog();
		update_option( 'msls_' . $post, array( 'ar' => $arabic ) );

		$result = Copier::copy( $post, self::$fr );
		$this->assertIsArray( $result );
		$french = $result['post'];
		$this->assertSame(
			array(
				'ar'    => $arabic,
				'fr_FR' => $french,
			),
			get_blog_option( self::$main, 'msls_' . $post )
		);
		$this->assertSame(
			array(
				'ar'    => $arabic,
				'en_US' => $post,
			),
			get_blog_option( self::$fr, 'msls_' . $french )
		);
		$this->assertSame(
			array(
				'en_US' => $post,
				'fr_FR' => $french,
			),
			get_blog_option( self::$ar, 'msls_' . $arabic )
		);
	}

	public function test_rights_are_checked_on_the_target_site(): void {
		$this->super_admin();
		list( $post ) = $this->article();

		// An editor of the main site only.
		$this->user_with( array( self::$main => 'editor' ) );
		$result = Copier::copy( $post, self::$fr );
		$this->assertWPError( $result );
		$this->assertSame( 'windrose_sabir_copy_create', $result->get_error_code() );

		// A contributor on the French site may create a draft, not upload: the image keeps its address.
		$this->user_with(
			array(
				self::$main => 'editor',
				self::$fr   => 'contributor',
			)
		);
		$count  = count( $this->attachments_on( self::$fr ) );
		$result = Copier::copy( $post, self::$fr );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'keep', $result['images'][0]['how'] );
		$this->assertSame( 'no-upload', $result['images'][0]['reason'] );
		$this->assertCount( $count, $this->attachments_on( self::$fr ) );
		switch_to_blog( self::$fr );
		$draft = get_post( $result['post'] );
		restore_current_blog();
		$this->assertStringNotContainsString( '"id":', $draft->post_content );
		$this->assertStringNotContainsString( 'wp-image-', $draft->post_content );
		switch_to_blog( get_main_site_id() );
		$source_base = wp_get_upload_dir()['baseurl'];
		restore_current_blog();
		$this->assertStringContainsString( $source_base . '/', $draft->post_content, 'The address of the source file stays.' );
	}

	public function test_copied_content_passes_the_target_site_kses_rules(): void {
		$this->super_admin();
		$content = "<!-- wp:html -->\n<p>Map</p><script>alert(1)</script>\n<!-- /wp:html -->";
		$post    = self::factory()->post->create( array( 'post_content' => $content ) );
		$this->assertStringContainsString( '<script>', get_post( $post )->post_content );

		// A site administrator (no unfiltered_html on a network): the script goes.
		$this->user_with(
			array(
				self::$main => 'administrator',
				self::$fr   => 'administrator',
			)
		);
		$result = Copier::copy( $post, self::$fr );
		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<script>', get_blog_post( self::$fr, $result['post'] )->post_content );

		// A super admin has unfiltered_html everywhere: kept.
		$this->super_admin();
		$result = Copier::copy( $post, self::$ar );
		$this->assertIsArray( $result );
		$this->assertStringContainsString( '<script>', get_blog_post( self::$ar, $result['post'] )->post_content );
	}

	public function test_the_target_site_rules_apply_with_unfiltered_html_on_the_source_only(): void {
		$this->super_admin();
		$post = self::factory()->post->create( array( 'post_content' => "<!-- wp:html -->\n<p>Map</p><script>alert(1)</script>\n<!-- /wp:html -->" ) );
		// A plugin may grant unfiltered_html on one site only.
		$main  = self::$main;
		$grant = static fn( $caps, $cap ) => 'unfiltered_html' === $cap && get_current_blog_id() === $main ? array( 'unfiltered_html' ) : $caps;
		add_filter( 'map_meta_cap', $grant, 10, 2 );
		$admin = $this->user_with(
			array(
				self::$main => 'administrator',
				self::$fr   => 'administrator',
			)
		);
		$this->assertTrue( user_can( $admin, 'unfiltered_html' ), 'On the source site.' );

		$result = Copier::copy( $post, self::$fr );

		remove_filter( 'map_meta_cap', $grant, 10 );
		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<script>', get_blog_post( self::$fr, $result['post'] )->post_content, 'Stripped by the French site\'s rules.' );
		$this->assertStringContainsString( '<p>Map</p>', get_blog_post( self::$fr, $result['post'] )->post_content );
	}

	public function test_refusals(): void {
		$this->super_admin();
		list( $post ) = $this->article();

		$this->assertSame( 'windrose_sabir_copy_target', Copier::copy( $post, self::$social )->get_error_code(), 'Social is in no group.' );
		$this->assertSame( 'windrose_sabir_copy_target', Copier::copy( $post, self::$main )->get_error_code(), 'Not to the same site.' );
		$this->assertSame( 'windrose_sabir_copy_not_found', Copier::copy( 999999, self::$fr )->get_error_code() );

		update_option( Site_Settings::OPTION, array( 'post_types' => array( 'page' ) ) );
		$this->assertSame( 'windrose_sabir_copy_type', Copier::copy( $post, self::$fr )->get_error_code() );
		delete_option( Site_Settings::OPTION );

		Modules::save( array( 'copy' => false ) );
		$this->assertSame( 'windrose_sabir_copy_off', Copier::copy( $post, self::$fr )->get_error_code() );
		Modules::save( array( 'copy' => true ) );

		switch_to_blog( self::$social );
		$social_post = self::factory()->post->create();
		$this->assertSame( 'windrose_sabir_copy_no_group', Copier::copy( $social_post, self::$fr )->get_error_code() );
		restore_current_blog();
	}

	public function test_terms_without_translation_follow_the_target_setting(): void {
		$this->super_admin();
		list( $post ) = $this->article();
		wp_set_post_tags( $post, array( 'Spring', 'Fields' ) );
		// "Fields" already has a translation on the French site.
		$fields = get_term_by( 'name', 'Fields', 'post_tag' );
		switch_to_blog( self::$fr );
		$champs = self::factory()->tag->create( array( 'name' => 'Champs' ) );
		update_option( Site_Settings::OPTION, array( 'terms' => 'skip' ) );
		restore_current_blog();
		update_option( 'msls_term_' . $fields->term_id, array( 'fr_FR' => $champs ) );

		$result = Copier::copy( $post, self::$fr );
		$this->assertIsArray( $result );
		$hows = wp_list_pluck( $result['terms'], 'how', 'name' );
		$this->assertSame( 'linked', $hows['Fields'] );
		$this->assertSame( 'skipped', $hows['Spring'] );
		switch_to_blog( self::$fr );
		$this->assertSame( array( 'Champs' ), wp_get_post_tags( $result['post'], array( 'fields' => 'names' ) ) );
		$this->assertFalse( get_term_by( 'name', 'Spring', 'post_tag' ) );
		restore_current_blog();
	}

	public function test_a_page_keeps_its_translated_parent(): void {
		$this->super_admin();
		$parent      = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$child       = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent,
			)
		);
		$parent_copy = Copier::copy( $parent, self::$fr );
		$this->assertIsArray( $parent_copy );
		$child_copy = Copier::copy( $child, self::$fr );
		$this->assertIsArray( $child_copy );
		switch_to_blog( self::$fr );
		$this->assertSame( 'page', get_post_type( $child_copy['post'] ) );
		$this->assertSame( $parent_copy['post'], wp_get_post_parent_id( $child_copy['post'] ) );
		restore_current_blog();
	}

	public function test_a_failed_copy_is_undone(): void {
		$this->super_admin();
		list( $post ) = $this->article();
		$attachments  = $this->attachments_on( self::$fr );
		$fail         = static function (): void {
			throw new RuntimeException( 'Something broke' );
		};
		add_action( 'msls_content_import_after_import', $fail );

		$result = Copier::copy( $post, self::$fr );

		remove_action( 'msls_content_import_after_import', $fail );
		$this->assertWPError( $result );
		$this->assertSame( 'windrose_sabir_copy_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Something broke', $result->get_error_message() );
		$this->assertSame( self::$main, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
		$this->assertSame( $attachments, $this->attachments_on( self::$fr ), 'The file copy was deleted.' );
		switch_to_blog( self::$fr );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'title'       => 'Spring fields',
					'post_status' => 'any',
				)
			),
			'The draft was deleted.'
		);
		restore_current_blog();
		$this->assertFalse( get_option( 'msls_' . $post ), 'No link written.' );
	}

	public function test_an_importer_that_forgets_to_restore_does_not_leave_the_site_switched(): void {
		$this->super_admin();
		list( $post ) = $this->article();
		$fr           = self::$fr;
		$add          = static function ( $importers, $coordinates ) use ( $fr ) {
			$importers['unbalanced'] = new class( $coordinates, $fr ) extends BaseImporter {
				/**
				 * Site to switch to.
				 *
				 * @var int
				 */
				private int $site;

				public function __construct( $coordinates, int $site ) {
					parent::__construct( $coordinates );
					$this->site = $site;
				}

				public function import( array $data ) {
					switch_to_blog( $this->site );
					return $data;
				}
			};
			return $importers;
		};
		add_filter( 'msls_content_import_importers_map', $add, 10, 2 );

		$result = Copier::copy( $post, self::$fr );

		remove_filter( 'msls_content_import_importers_map', $add );
		$this->assertIsArray( $result );
		$this->assertSame( self::$main, get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
	}

	public function test_targets_list_the_group_with_translations_and_media(): void {
		$this->super_admin();
		list( $post ) = $this->article();
		$targets      = Copier::targets( get_post( $post ) );
		$this->assertSame( array( self::$fr, self::$ar ), wp_list_pluck( $targets, 'id' ) );
		$this->assertSame( 'Français', $targets[0]['language'] );
		$this->assertTrue( $targets[0]['can'] );
		$this->assertSame( 1, $targets[0]['media']['copy'] );
		$this->assertNull( $targets[0]['translation'] );

		Copier::copy( $post, self::$fr );
		$targets = Copier::targets( get_post( $post ) );
		$this->assertSame( 'Spring fields', $targets[0]['translation']['title'] );
		$this->assertSame( 'Draft', $targets[0]['translation']['status'] );
		$this->assertFalse( $targets[0]['can'] );
	}

	public function test_bulk_action_copies_and_counts(): void {
		$this->super_admin();
		list( $first ) = $this->article();
		$second        = self::factory()->post->create( array( 'post_title' => 'Second' ) );
		Copier::copy( $first, self::$fr );

		$actions = Bulk::actions( array() );
		$this->assertSame( 'Copy to Français as drafts', $actions[ Bulk::PREFIX . self::$fr ] );

		$redirect = Bulk::handle( 'https://example.org/wp-admin/edit.php', Bulk::PREFIX . self::$fr, array( $first, $second ) );
		$this->assertIsString( $redirect );
		parse_str( (string) wp_parse_url( $redirect, PHP_URL_QUERY ), $args );
		$this->assertSame( '1', $args['windrose_sabir_copied'] );
		$this->assertSame( '1', $args['windrose_sabir_existing'] );
		$this->assertSame( '0', $args['windrose_sabir_failed'] );
		$this->assertSame( '0', $args['windrose_sabir_more'] );
		$this->assertSame( 'unchanged', Bulk::handle( 'unchanged', 'trash', array( $first ) ) );
	}

	public function test_bulk_action_copies_ten_posts_per_run(): void {
		$this->super_admin();
		$ids = self::factory()->post->create_many( Bulk::MAX + 2 );

		parse_str( (string) wp_parse_url( (string) Bulk::handle( 'https://example.org/wp-admin/edit.php', Bulk::PREFIX . self::$fr, $ids ), PHP_URL_QUERY ), $first );
		$this->assertSame( (string) Bulk::MAX, $first['windrose_sabir_copied'] );
		$this->assertSame( '2', $first['windrose_sabir_more'] );

		// The same selection again, as a person does it: the copied ones are skipped, the rest copied.
		parse_str( (string) wp_parse_url( (string) Bulk::handle( 'https://example.org/wp-admin/edit.php', Bulk::PREFIX . self::$fr, $ids ), PHP_URL_QUERY ), $second );
		$this->assertSame( '2', $second['windrose_sabir_copied'] );
		$this->assertSame( (string) Bulk::MAX, $second['windrose_sabir_existing'] );
		$this->assertSame( '0', $second['windrose_sabir_more'] );
	}
}
