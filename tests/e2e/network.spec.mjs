/**
 * Network Admin, Windrose Sabir: Translation links (dry run, then removal), Logbook (export) and Modules
 * (switches, language display).
 *
 * Runs on two throwaway sites ("/sabir-e2e-net-a/", "/sabir-e2e-net-b/") in a language group of their own; only the
 * links the test planted are removed (the test network's own links are left alone). Modules and the language display
 * are put back as they were.
 */
import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { expectAccessible, HOST } from './helpers.mjs';

const BASE = `https://${ HOST }`;
const A = '/sabir-e2e-net-a/';
const B = '/sabir-e2e-net-b/';
const OPTIONS = [ 'windrose_sabir_groups', 'windrose_sabir_modules', 'windrose_sabir_display' ];

function wpAt( path, ...args ) {
	return execFileSync( 'wp', [ ...args, '--path=/var/www/html/web', `--url=${ BASE }${ path }` ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

function tryWp( path, ...args ) {
	try {
		return wpAt( path, ...args );
	} catch {
		return null;
	}
}

const state = { before: {}, sabirMeta: [], a: 0, b: 0, post: 0, translation: 0, stray: 0 };

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	for ( const option of OPTIONS ) {
		state.before[ option ] = tryWp( '/', 'site', 'option', 'get', option, '--format=json' );
	}
	state.sabirMeta = JSON.parse(
		wpAt( '/', 'eval', "global $wpdb; echo wp_json_encode( $wpdb->get_results( \"SELECT blog_id, meta_value FROM {$wpdb->blogmeta} WHERE meta_key = 'windrose_sabir_group'\", ARRAY_A ) );" )
	);
	state.a = Number( wpAt( '/', 'site', 'create', '--slug=sabir-e2e-net-a', '--title=Sabir e2e A', '--porcelain' ) );
	state.b = Number( wpAt( '/', 'site', 'create', '--slug=sabir-e2e-net-b', '--title=Sabir e2e B', '--porcelain' ) );
	wpAt( B, 'option', 'update', 'WPLANG', 'fr_FR' );
	wpAt( A, 'option', 'update', 'msls', JSON.stringify( { description: 'English', output_current_blog: '1' } ), '--format=json' );
	wpAt( B, 'option', 'update', 'msls', JSON.stringify( { description: 'Français', output_current_blog: '1' } ), '--format=json' );
	wpAt( '/', 'eval', `$r = Windrose\\Sabir\\Groups::save( 0, 'Sabir e2e network', array( ${ state.a }, ${ state.b } ) ); if ( is_wp_error( $r ) ) { WP_CLI::error( $r->get_error_message() ); }` );
	// A fitting pair, and a stray post whose link points to a post that links elsewhere (as after a group change).
	state.post = Number( wpAt( A, 'post', 'create', '--post_status=publish', '--post_title=Stray link source', '--porcelain' ) );
	state.translation = Number( wpAt( B, 'post', 'create', '--post_status=publish', '--post_title=Traduction', '--porcelain' ) );
	state.stray = Number( wpAt( A, 'post', 'create', '--post_status=publish', '--post_title=Fitting source', '--porcelain' ) );
	wpAt( A, 'option', 'update', `msls_${ state.stray }`, JSON.stringify( { fr_FR: state.translation } ), '--format=json' );
	wpAt( B, 'option', 'update', `msls_${ state.translation }`, JSON.stringify( { en_US: state.stray } ), '--format=json' );
	wpAt( A, 'option', 'update', `msls_${ state.post }`, JSON.stringify( { fr_FR: state.translation, ar: 4 } ), '--format=json' );
} );

test.afterAll( () => {
	for ( const id of [ state.a, state.b ] ) {
		if ( id ) {
			tryWp( '/', 'site', 'delete', String( id ), '--yes' );
		}
	}
	for ( const option of OPTIONS ) {
		tryWp( '/', 'site', 'option', 'delete', option );
		if ( null !== state.before[ option ] ) {
			wpAt( '/', 'site', 'option', 'update', option, state.before[ option ], '--format=json' );
		}
	}
	wpAt( '/', 'eval', "delete_metadata( 'blog', 0, 'windrose_sabir_group', '', true );" );
	for ( const row of state.sabirMeta ) {
		wpAt( '/', 'eval', `update_site_meta( ${ Number( row.blog_id ) }, 'windrose_sabir_group', ${ Number( row.meta_value ) } );` );
	}
} );

test( 'Translation links: the dry run lists what no longer fits, removal is confirmed', async ( { page } ) => {
	await page.goto( '/wp-admin/network/admin.php?page=windrose-sabir-links' );
	await expect( page.getByRole( 'heading', { name: 'Translation links', level: 1 } ) ).toBeVisible();
	const rows = page.getByRole( 'row' ).filter( { hasText: 'Stray link source' } );
	await expect( rows ).toHaveCount( 2 );
	await expect( rows.filter( { hasText: 'No site of the group uses this language' } ) ).toHaveCount( 1 );
	await expect( rows.filter( { hasText: 'It does not link back' } ) ).toHaveCount( 1 );
	await expect( page.getByRole( 'row' ).filter( { hasText: 'Fitting source' } ) ).toHaveCount( 0 );
	await expectAccessible( page, '.windrose-sabir' );

	// Nothing changed by looking.
	expect( JSON.parse( wpAt( A, 'option', 'get', `msls_${ state.post }`, '--format=json' ) ) ).toEqual( { fr_FR: state.translation, ar: 4 } );

	for ( const row of await rows.all() ) {
		await row.getByRole( 'checkbox' ).check();
	}
	await page.getByRole( 'button', { name: /Remove link/ } ).first().click();
	const dialog = page.getByRole( 'dialog' );
	await expect( dialog ).toContainText( 'Remove 2 translation links?' );
	await expectAccessible( page, '[role="dialog"]' );
	await dialog.getByRole( 'button', { name: 'Remove', exact: true } ).click();
	await expect( page.locator( '.windrose-sabir__notice' ) ).toContainText( '2 translation links removed.' );
	await expect( page.getByRole( 'row' ).filter( { hasText: 'Stray link source' } ) ).toHaveCount( 0 );
	expect( tryWp( A, 'option', 'get', `msls_${ state.post }`, '--format=json' ) ).toBeNull();
	expect( JSON.parse( wpAt( A, 'option', 'get', `msls_${ state.stray }`, '--format=json' ) ) ).toEqual( { fr_FR: state.translation } );
} );

test( 'Logbook: counts and the downloads', async ( { page } ) => {
	await page.goto( '/wp-admin/network/admin.php?page=windrose-sabir-logbook' );
	await expect( page.getByRole( 'heading', { name: 'Logbook', level: 1 } ) ).toBeVisible();
	const json = page.getByRole( 'link', { name: 'Download the logbook (JSON)' } );
	await expect( json ).toBeVisible();
	await expectAccessible( page, '.windrose-sabir' );

	const logbook = await ( await page.request.get( await json.getAttribute( 'href' ) ) ).json();
	expect( logbook.schema ).toBe( 1 );
	expect( logbook.sites.map( ( site ) => site.id ) ).toEqual( expect.arrayContaining( [ state.a, state.b ] ) );
	const pair = logbook.sets.find( ( set ) => set.members.some( ( member ) => member.site === state.a && member.id === state.stray ) );
	expect( pair.members.map( ( member ) => member.title ) ).toEqual( [ 'Fitting source', 'Traduction' ] );

	const csv = await page.request.get( await page.getByRole( 'link', { name: 'Download the address table (CSV)' } ).getAttribute( 'href' ) );
	expect( csv.headers()[ 'content-type' ] ).toContain( 'text/csv' );
	expect( await csv.text() ).toContain( '"set","kind"' );

	// Without a nonce, nothing.
	const plain = await page.request.get( `${ BASE }/wp-admin/admin-post.php?action=windrose_sabir_logbook&format=json` );
	expect( plain.status() ).toBeGreaterThanOrEqual( 400 );
} );

test( 'Modules: switches and the language display', async ( { page, request } ) => {
	await page.goto( '/wp-admin/network/admin.php?page=windrose-sabir-modules' );
	await expect( page.getByRole( 'heading', { name: 'Modules', level: 1 } ) ).toBeVisible();
	await expect( page.getByRole( 'checkbox', { name: 'Copy' } ) ).toBeChecked();
	await expectAccessible( page, '.windrose-sabir' );

	await page.getByRole( 'radio', { name: 'Language codes (no flags)' } ).check();
	await page.getByRole( 'checkbox', { name: 'Logbook' } ).uncheck();
	await page.getByRole( 'button', { name: 'Save' } ).click();
	await expect( page.locator( '.windrose-sabir__notice' ) ).toContainText( 'Settings saved.' );
	expect( JSON.parse( wpAt( '/', 'site', 'option', 'get', 'windrose_sabir_display', '--format=json' ) ) ).toBe( 'codes' );
	expect( JSON.parse( wpAt( '/', 'site', 'option', 'get', 'windrose_sabir_modules', '--format=json' ) ).logbook ).toBe( false );

	// The Logbook screen is gone from the menu; MSLS's own switcher shows codes, no flag.
	await page.reload();
	await expect( page.locator( '#adminmenu' ).getByRole( 'link', { name: 'Logbook' } ) ).toHaveCount( 0 );
	const page_id = wpAt( A, 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Switcher', '--post_content=<!-- wp:html --><div class="sabir-e2e-switcher">[sc_msls]</div><!-- /wp:html -->', '--porcelain' );
	const html = await ( await request.get( `${ BASE }${ A }?page_id=${ page_id }` ) ).text();
	const box = html.match( /<div class="sabir-e2e-switcher">([\s\S]*?)<\/div>/ )?.[ 1 ] ?? '';
	expect( box ).toContain( '<span translate="no">EN</span>' );
	expect( box ).not.toContain( '<img' );

	// The toolbar follows the same choice: the code after the site name, no flag.
	await page.goto( `${ A }?page_id=${ page_id }` );
	const siteName = page.locator( '#wp-admin-bar-site-name > .ab-item' );
	await expect( siteName.locator( '.windrose-sabir-toolbar-language' ) ).toHaveText( '(EN)' );
	await expect( page.locator( '#wpadminbar .flag-icon' ) ).toHaveCount( 0 );
} );
