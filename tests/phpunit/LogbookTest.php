<?php
/**
 * Logbook export: groups, sites, translation sets from MSLS's links, and the address table.
 *
 * @package Windrose\Sabir
 */

use Windrose\Sabir\Logbook;
use Windrose\Sabir\Modules;

/**
 * @covers \Windrose\Sabir\Logbook
 */
class LogbookTest extends Sabir_TestCase {

	/**
	 * A published post on a site.
	 *
	 * @param int    $site  Site ID.
	 * @param string $title Title.
	 * @return int
	 */
	private function post_on( int $site, string $title ): int {
		switch_to_blog( $site );
		$id = self::factory()->post->create( array( 'post_title' => $title ) );
		restore_current_blog();
		return $id;
	}

	public function test_build_lists_groups_sites_and_sets(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr, self::$ar ) );
		$en = $this->post_on( self::$main, 'Fields' );
		$fr = $this->post_on( self::$fr, 'Champs' );
		$ar = $this->post_on( self::$ar, 'حقول' );
		update_blog_option(
			self::$main,
			'msls_' . $en,
			array(
				'fr_FR' => $fr,
				'ar'    => $ar,
			)
		);
		update_blog_option( self::$fr, 'msls_' . $fr, array( 'en_US' => $en ) );
		// A link to a deleted post makes no set.
		$lonely = $this->post_on( self::$main, 'Lonely' );
		update_blog_option( self::$main, 'msls_' . $lonely, array( 'fr_FR' => 999999 ) );
		$category = self::factory()->category->create( array( 'name' => 'News' ) );
		switch_to_blog( self::$fr );
		$categorie = self::factory()->category->create( array( 'name' => 'Actualités' ) );
		restore_current_blog();
		update_blog_option( self::$main, 'msls_term_' . $category, array( 'fr_FR' => $categorie ) );

		$data = Logbook::build();

		$this->assertSame( 1, $data['schema'] );
		$this->assertSame( array( self::$main, self::$fr, self::$ar ), wp_list_pluck( $data['sites'], 'id' ), 'Sites in a group only.' );
		$this->assertSame( array( 'en-US', 'fr-FR', 'ar' ), wp_list_pluck( $data['sites'], 'language' ) );
		$this->assertSame( 'Windrose', $data['groups'][0]['label'] );
		$this->assertCount( 2, $data['sets'] );
		$post_set = $data['sets'][0];
		$this->assertSame( 'post', $post_set['kind'] );
		$this->assertSame( array( $en, $fr, $ar ), wp_list_pluck( $post_set['members'], 'id' ) );
		$this->assertSame( 'Champs', $post_set['members'][1]['title'] );
		$this->assertSame( get_blog_permalink( self::$fr, $fr ), $post_set['members'][1]['url'] );
		$this->assertSame( 'term', $data['sets'][1]['kind'] );
		$this->assertSame( 'category', $data['sets'][1]['members'][0]['type'] );
		$this->assertSame( get_home_url( self::$fr, '?cat=' . $categorie ), $data['sets'][1]['members'][1]['url'], 'Plain address: the French site redirects it with its own rewrite rules.' );

		$csv   = Logbook::csv( $data );
		$lines = explode( "\r\n", trim( $csv ) );
		$this->assertCount( 3, $lines, 'Header, the post set, the term set.' );
		$this->assertStringStartsWith( '"set","kind","en-US http', $lines[0] );
		$this->assertStringContainsString( '"' . get_blog_permalink( self::$fr, $fr ) . '"', $lines[1] );

		$this->assertJson( Logbook::json( $data ) );
	}

	public function test_posts_of_types_the_main_site_lacks_are_listed_with_plain_addresses(): void {
		$this->group( 'Windrose', array( self::$main, self::$fr ) );
		$en = $this->post_on( self::$main, 'Recipe' );
		switch_to_blog( self::$fr );
		// A type registered on the French site only (never on the main site, where the export runs).
		$fr = wp_insert_post(
			array(
				'post_type'   => 'sabir_recette',
				'post_status' => 'publish',
				'post_title'  => 'Recette',
			)
		);
		restore_current_blog();
		update_blog_option( self::$main, 'msls_' . $en, array( 'fr_FR' => $fr ) );

		$set = Logbook::build()['sets'][0];
		$this->assertSame( array( $en, $fr ), wp_list_pluck( $set['members'], 'id' ) );
		$this->assertSame( 'sabir_recette', $set['members'][1]['type'] );
		$this->assertSame( get_home_url( self::$fr, '?post_type=sabir_recette&p=' . $fr ), $set['members'][1]['url'] );
	}

	public function test_without_groups_the_msls_sites_are_listed(): void {
		$data = Logbook::build();
		$this->assertSame( array( self::$main, self::$fr, self::$ar, self::$social ), wp_list_pluck( $data['sites'], 'id' ) );
		$this->assertSame( array(), $data['groups'] );
	}

	public function test_csv_cells_are_quoted_and_never_formulas(): void {
		$this->assertSame( '"say ""hi"""', Logbook::csv_cell( 'say "hi"' ) );
		$this->assertSame( "\"'=1+1\"", Logbook::csv_cell( '=1+1' ) );
		$this->assertSame( "\"'@SUM(A1)\"", Logbook::csv_cell( '@SUM(A1)' ) );
		$this->assertSame( '""', Logbook::csv_cell( array() ) );
	}

	public function test_download_needs_a_network_administrator(): void {
		$this->user_with( array( self::$main => 'administrator' ) );
		$this->expectException( WPDieException::class );
		Logbook::download();
	}

	public function test_download_needs_the_module(): void {
		$this->super_admin();
		Modules::save( array( 'logbook' => false ) );
		$this->expectException( WPDieException::class );
		Logbook::download();
	}
}
