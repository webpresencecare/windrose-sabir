/**
 * Language groups on a test network: English and French switch to each other, Social shows no switcher.
 *
 * Social is set up in Multisite Language Switcher (MSLS) for the test, as on a real network, so without a group it
 * shows a switcher too. One published page per site (English, French, Social) holds MSLS's switcher shortcode; the
 * English and French pages are linked as translations. Everything the test created is deleted afterwards (pages,
 * links, the group, Social's MSLS settings), and groups that existed before are put back.
 */
import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { expectAccessible, HOST } from './helpers.mjs';

const BASE = `https://${ HOST }`;
const GROUP = 'Sabir e2e group';
const SITES = { en: '/', fr: '/fr/', ar: '/ar/', social: '/social/' };

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

const state = { groupsBefore: null, metaBefore: {}, socialMsls: null, pages: {} };

/** The switcher's links on a page: [ { href, title } ]. */
async function switcher( request, url ) {
	const html = await ( await request.get( url ) ).text();
	const box = html.match( /<div class="sabir-e2e-switcher">([\s\S]*?)<\/div>/ );
	const links = [ ...( box ? box[ 1 ] : '' ).matchAll( /<a href="([^"]+)" title="([^"]*)"/g ) ];
	const hreflang = [ ...html.matchAll( /<link rel="alternate" href="[^"]+" hreflang="([^"]+)"/g ) ].map( ( m ) => m[ 1 ] );
	return { links: links.map( ( m ) => ( { href: m[ 1 ], title: m[ 2 ] } ) ), hreflang };
}

/** Saves the group dialog, confirming the translation link notice when one appears. */
async function saveGroup( page, dialog ) {
	await dialog.getByRole( 'button', { name: 'Save' } ).click();
	const anyway = dialog.getByRole( 'button', { name: 'Save anyway' } );
	await expect( anyway.or( page.locator( '.windrose-sabir__notice' ) ) ).toBeVisible();
	if ( await anyway.isVisible() ) {
		await anyway.click();
	}
}

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	// Groups that already exist on the test network are set aside and put back afterwards.
	state.groupsBefore = tryWp( '/', 'site', 'option', 'get', 'windrose_sabir_groups', '--format=json' );
	for ( const [ key, path ] of Object.entries( SITES ) ) {
		const id = wpAt( path, 'eval', 'echo get_current_blog_id();' );
		state.metaBefore[ key ] = { id, value: wpAt( '/', 'eval', `echo (int) get_site_meta( ${ id }, 'windrose_sabir_group', true );` ) };
	}
	tryWp( '/', 'site', 'option', 'delete', 'windrose_sabir_groups' );
	wpAt( '/', 'eval', "delete_metadata( 'blog', 0, 'windrose_sabir_group', '', true );" );

	state.socialMsls = tryWp( SITES.social, 'option', 'get', 'msls', '--format=json' );
	if ( null === state.socialMsls ) {
		wpAt( SITES.social, 'option', 'update', 'msls', JSON.stringify( { description: 'Social', output_current_blog: '1' } ), '--format=json' );
	}
	for ( const key of [ 'en', 'fr', 'social' ] ) {
		state.pages[ key ] = Number(
			wpAt(
				SITES[ key ],
				'post',
				'create',
				'--post_type=page',
				'--post_status=publish',
				`--post_title=Sabir e2e ${ key }`,
				'--post_content=<!-- wp:html --><div class="sabir-e2e-switcher">[sc_msls]</div><!-- /wp:html -->',
				'--porcelain'
			)
		);
	}
	// The English and French pages are translations of each other (MSLS stores links as msls_<post ID> options).
	wpAt( SITES.en, 'option', 'update', `msls_${ state.pages.en }`, JSON.stringify( { fr_FR: state.pages.fr } ), '--format=json' );
	wpAt( SITES.fr, 'option', 'update', `msls_${ state.pages.fr }`, JSON.stringify( { en_US: state.pages.en } ), '--format=json' );
} );

test.afterAll( () => {
	for ( const key of Object.keys( state.pages ) ) {
		tryWp( SITES[ key ], 'post', 'delete', String( state.pages[ key ] ), '--force' );
		tryWp( SITES[ key ], 'option', 'delete', `msls_${ state.pages[ key ] }` );
	}
	if ( null === state.socialMsls ) {
		tryWp( SITES.social, 'option', 'delete', 'msls' );
	}
	tryWp( '/', 'site', 'option', 'delete', 'windrose_sabir_groups' );
	wpAt( '/', 'eval', "delete_metadata( 'blog', 0, 'windrose_sabir_group', '', true );" );
	if ( state.groupsBefore ) {
		wpAt( '/', 'site', 'option', 'update', 'windrose_sabir_groups', state.groupsBefore, '--format=json' );
		for ( const { id, value } of Object.values( state.metaBefore ) ) {
			if ( '0' !== value ) {
				wpAt( '/', 'eval', `update_site_meta( ${ id }, 'windrose_sabir_group', ${ Number( value ) } );` );
			}
		}
	}
} );

test( 'without a group, MSLS links every site, Social included', async ( { request } ) => {
	const social = await switcher( request, `${ BASE }${ SITES.social }?page_id=${ state.pages.social }` );
	expect( social.links.length ).toBeGreaterThan( 1 );
	expect( social.hreflang.length ).toBeGreaterThan( 0 );
} );

test( 'create a language group in Network Admin', async ( { page } ) => {
	await page.goto( '/wp-admin/network/admin.php?page=windrose-sabir' );
	await expect( page.getByRole( 'heading', { name: 'Language groups', level: 1 } ) ).toBeVisible();
	await expect( page.getByText( 'No language group yet.' ) ).toBeVisible();
	await expect( page.getByRole( 'heading', { name: 'Sites in no language group' } ) ).toBeVisible();
	await expectAccessible( page, '.windrose-sabir' );

	await page.getByRole( 'button', { name: 'Add language group' } ).click();
	const dialog = page.getByRole( 'dialog', { name: 'Add language group' } );
	await expect( dialog ).toBeVisible();
	await expectAccessible( page, '.windrose-sabir-modal' );

	// Nothing saved without a name, nor with two sites in the same language.
	await dialog.getByRole( 'button', { name: 'Save' } ).click();
	await expect( dialog.getByText( 'Give the language group a name.' ) ).toBeVisible();
	await dialog.getByLabel( 'Name' ).fill( GROUP );
	await dialog.getByRole( 'checkbox', { name: /^\u2068Windrose\u2069:/ } ).check();
	await dialog.getByRole( 'checkbox', { name: /^\u2068Social\u2069:/ } ).check();
	await dialog.getByRole( 'button', { name: 'Save' } ).click();
	await expect( dialog.getByText( /both use the language en_US/ ) ).toBeVisible();

	await dialog.getByRole( 'checkbox', { name: /^\u2068Social\u2069:/ } ).uncheck();
	await dialog.getByRole( 'checkbox', { name: /: \u2068Français/ } ).first().check();
	await dialog.getByRole( 'checkbox', { name: /: \u2068العربية/ } ).check();
	await dialog.getByLabel( 'Main site' ).selectOption( state.metaBefore.en.id );
	await dialog.getByRole( 'button', { name: 'Save' } ).click();

	// The French page's translation link (made above) will point to the English site of the group.
	const links = dialog.locator( '.windrose-sabir__links' );
	// The count depends on the links already on the test network.
	await expect( links ).toContainText( /has \d+ translation links? to English \(United States\)/ );
	await expect( links ).toContainText( 'will point to that site' );
	await expectAccessible( page, '.windrose-sabir-modal' );
	await dialog.getByRole( 'button', { name: 'Save anyway' } ).click();

	await expect( dialog ).toBeHidden();
	await expect( page.locator( '.windrose-sabir__notice', { hasText: `Language group “${ GROUP }” added.` } ) ).toBeVisible();
	const row = page.getByRole( 'row', { name: new RegExp( GROUP ) } );
	await expect( row ).toBeVisible();
	await expect( row.getByRole( 'link' ) ).toHaveCount( 3 );
	await expect( page.locator( '.windrose-sabir__card' ).last().getByRole( 'link', { name: /^\u2068Social\u2069:/ } ) ).toBeVisible();
	await expectAccessible( page, '.windrose-sabir' );

	await expect( row ).toContainText( '(main site)' );
	expect( JSON.parse( wpAt( '/', 'site', 'option', 'get', 'windrose_sabir_groups', '--format=json' ) ) ).toEqual( [ { id: 1, label: GROUP, main: Number( state.metaBefore.en.id ) } ] );
} );

test( 'done when: English and French switch to each other, Social shows no switcher', async ( { request } ) => {
	const enUrl = `${ BASE }/?page_id=${ state.pages.en }`;
	const frUrl = `${ BASE }/fr/?page_id=${ state.pages.fr }`;

	const en = await switcher( request, enUrl );
	const fr = await switcher( request, frUrl );
	const social = await switcher( request, `${ BASE }/social/?page_id=${ state.pages.social }` );

	const enFrench = en.links.find( ( link ) => link.title === 'Français' );
	const frEnglish = fr.links.find( ( link ) => link.title === 'English' );
	expect( enFrench?.href ).toMatch( new RegExp( `/fr/(\\?page_id=${ state.pages.fr }|.*sabir-e2e-fr)` ) );
	expect( frEnglish?.href ).toMatch( new RegExp( `^${ BASE }/(\\?page_id=${ state.pages.en }|.*sabir-e2e-en)` ) );
	for ( const result of [ en, fr ] ) {
		expect( result.links.map( ( link ) => link.title ) ).not.toContain( 'Social' );
		expect( result.hreflang ).not.toContain( 'en-US' );
	}
	expect( en.hreflang.sort() ).toEqual( [ 'en', 'fr' ] );

	expect( social.links ).toEqual( [] );
	expect( social.hreflang ).toEqual( [] );

	// The French page's link to English answers.
	expect( ( await request.get( frEnglish.href ) ).status() ).toBe( 200 );
} );

test( 'Site Health and MSLS settings say what the group means', async ( { page } ) => {
	await page.goto( '/social/wp-admin/site-health.php' );
	await expect( page.getByRole( 'button', { name: /This site is in no language group/ } ) ).toBeVisible( { timeout: 30000 } );

	await page.goto( '/social/wp-admin/options-general.php?page=MslsAdmin' );
	await expect( page.locator( '.notice-info', { hasText: 'This site is in no language group' } ) ).toBeVisible();

	// Arabic site (RTL admin): the section names the group and the other sites.
	await page.goto( '/ar/wp-admin/options-general.php?page=MslsAdmin' );
	await expect( page.locator( 'html' ) ).toHaveAttribute( 'dir', 'rtl' );
	const section = page.getByRole( 'heading', { name: 'Language group (Windrose Sabir)' } );
	await expect( section ).toBeVisible();
	await expect( page.getByText( `This site is in the language group “${ GROUP }”` ) ).toBeVisible();
	await expectAccessible( page, '#wpbody-content' );
} );

test( 'edit and delete the group', async ( { page, request } ) => {
	await page.goto( '/wp-admin/network/admin.php?page=windrose-sabir' );
	const row = page.getByRole( 'row', { name: new RegExp( GROUP ) } );
	await row.getByRole( 'button', { name: 'Edit' } ).click();
	const dialog = page.getByRole( 'dialog', { name: 'Edit language group' } );
	await dialog.getByRole( 'checkbox', { name: /: \u2068العربية/ } ).uncheck();
	await saveGroup( page, dialog );
	await expect( page.locator( '.windrose-sabir__notice', { hasText: `Language group “${ GROUP }” saved.` } ) ).toBeVisible();
	await expect( row.getByRole( 'link' ) ).toHaveCount( 2 );

	await row.getByRole( 'button', { name: 'Actions' } ).click();
	await page.getByRole( 'menuitem', { name: 'Delete' } ).click();
	const confirm = page.getByRole( 'dialog', { name: 'Delete language group' } );
	await expectAccessible( page, '[role="dialog"]' );
	await confirm.getByRole( 'button', { name: 'Delete' } ).click();
	await expect( page.locator( '.windrose-sabir__notice', { hasText: `Language group “${ GROUP }” deleted.` } ) ).toBeVisible();
	await expect( page.getByText( 'No language group yet.' ) ).toBeVisible();

	// No group left: MSLS links Social again, as without Windrose Sabir.
	const social = await switcher( request, `${ BASE }/social/?page_id=${ state.pages.social }` );
	expect( social.links.length ).toBeGreaterThan( 1 );
} );
