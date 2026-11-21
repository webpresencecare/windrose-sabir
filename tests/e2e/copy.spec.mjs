/**
 * Copy, end to end: an article copied English to French as a linked draft with network items,
 * captions and credits; the language item correct in the French header. Also the bulk action, the Copy settings on
 * MSLS's settings page and the Site Health checks.
 *
 * Runs on two throwaway sites ("/sabir-e2e-en/", "/sabir-e2e-fr/"), created and deleted by the test with everything on
 * them, in a language group of their own; the English one is their media hub (Windrose Anchors). Windrose Sabir's
 * groups and Windrose Anchors' settings are put back as they were.
 */
import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { expectAccessible, HOST } from './helpers.mjs';

const BASE = `https://${ HOST }`;
const EN = '/sabir-e2e-en/';
const FR = '/sabir-e2e-fr/';
const IMAGE = '/var/www/html/tools/wp-tests/tests/phpunit/data/images/canola.jpg';
const OPTIONS = [ 'windrose_sabir_groups', 'windrose_anchors_sites', 'windrose_anchors_hubs', 'windrose_anchors_site_hubs' ];

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

const state = { before: {}, sabirMeta: [], en: 0, fr: 0, image: 0, post: 0, second: 0, draft: 0, drafts: {} };

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	for ( const option of OPTIONS ) {
		state.before[ option ] = tryWp( '/', 'site', 'option', 'get', option, '--format=json' );
	}
	state.sabirMeta = JSON.parse(
		wpAt( '/', 'eval', "global $wpdb; echo wp_json_encode( $wpdb->get_results( \"SELECT blog_id, meta_value FROM {$wpdb->blogmeta} WHERE meta_key = 'windrose_sabir_group'\", ARRAY_A ) );" )
	);
	state.en = Number( wpAt( '/', 'site', 'create', '--slug=sabir-e2e-en', '--title=Sabir e2e English', '--porcelain' ) );
	state.fr = Number( wpAt( '/', 'site', 'create', '--slug=sabir-e2e-fr', '--title=Sabir e2e Français', '--porcelain' ) );
	wpAt( FR, 'option', 'update', 'WPLANG', 'fr_FR' );
	wpAt( EN, 'option', 'update', 'msls', JSON.stringify( { description: 'English', output_current_blog: '1' } ), '--format=json' );
	wpAt( FR, 'option', 'update', 'msls', JSON.stringify( { description: 'Français', output_current_blog: '1' } ), '--format=json' );
	wpAt( '/', 'eval', `$r = Windrose\\Sabir\\Groups::save( 0, 'Sabir e2e copy', array( ${ state.en }, ${ state.fr } ), ${ state.en } ); if ( is_wp_error( $r ) ) { WP_CLI::error( $r->get_error_message() ); }` );
	// The English site is the French site's media hub, whether or not hubs follow language groups.
	const allowed = state.before.windrose_anchors_sites ? JSON.parse( state.before.windrose_anchors_sites ) : [];
	wpAt( '/', 'site', 'option', 'update', 'windrose_anchors_sites', JSON.stringify( [ ...allowed, state.fr ] ), '--format=json' );
	const hubs = state.before.windrose_anchors_hubs ? JSON.parse( state.before.windrose_anchors_hubs ) : [];
	wpAt( '/', 'site', 'option', 'update', 'windrose_anchors_hubs', JSON.stringify( [ ...hubs, state.en ] ), '--format=json' );
	const siteHubs = state.before.windrose_anchors_site_hubs ? JSON.parse( state.before.windrose_anchors_site_hubs ) : {};
	wpAt( '/', 'site', 'option', 'update', 'windrose_anchors_site_hubs', JSON.stringify( { ...siteHubs, [ state.fr ]: state.en } ), '--format=json' );

	// The article: image block with caption and credit, featured image; the image is not shared yet.
	state.image = Number( wpAt( EN, 'media', 'import', IMAGE, '--title=Canola field', '--alt=Yellow canola field', '--caption=Photo: Jane Doe', '--porcelain' ) );
	const src = wpAt( EN, 'eval', `echo wp_get_attachment_image_url( ${ state.image }, 'medium' );` );
	const content =
		'<!-- wp:paragraph -->\n<p>Canola flowers in May.</p>\n<!-- /wp:paragraph -->\n\n' +
		`<!-- wp:image {"id":${ state.image },"sizeSlug":"medium","linkDestination":"none"} -->\n` +
		`<figure class="wp-block-image size-medium"><img src="${ src }" alt="Yellow canola field" class="wp-image-${ state.image }"/><figcaption class="wp-element-caption">Canola in May. Photo: Jane Doe</figcaption></figure>\n` +
		'<!-- /wp:image -->';
	state.post = Number( wpAt( EN, 'post', 'create', '--post_status=publish', '--post_title=Spring fields', `--post_content=${ content }`, '--porcelain' ) );
	wpAt( EN, 'post', 'meta', 'update', String( state.post ), '_thumbnail_id', String( state.image ) );
	// Two drafts prepared in several languages, each with a new image attached to it (not shared, not published).
	for ( const key of [ 'network', 'copy' ] ) {
		const draft = Number( wpAt( EN, 'post', 'create', '--post_status=draft', `--post_title=Draft ${ key }`, '--porcelain' ) );
		const image = Number( wpAt( EN, 'media', 'import', IMAGE, `--post_id=${ draft }`, `--title=Draft image ${ key }`, '--porcelain' ) );
		const url = wpAt( EN, 'eval', `echo wp_get_attachment_image_url( ${ image }, 'medium' );` );
		wpAt(
			EN,
			'post',
			'update',
			String( draft ),
			`--post_content=<!-- wp:image {"id":${ image },"sizeSlug":"medium"} -->\n<figure class="wp-block-image size-medium"><img src="${ url }" alt="" class="wp-image-${ image }"/></figure>\n<!-- /wp:image -->`
		);
		state.drafts[ key ] = { post: draft, image };
	}
	state.second = Number( wpAt( EN, 'post', 'create', '--post_status=publish', '--post_title=Summer fields', '--post_content=<!-- wp:paragraph --><p>Summer.</p><!-- /wp:paragraph -->', '--porcelain' ) );

	// The French header's menu (core falls back to the most recent one) holds a language item.
	wpAt(
		FR,
		'post',
		'create',
		'--post_type=wp_navigation',
		'--post_status=publish',
		'--post_title=Menu',
		`--post_content=<!-- wp:navigation-link {"label":"Accueil","url":"${ BASE }${ FR }","kind":"custom"} /--><!-- wp:windrose/language-item /-->`
	);
} );

test.afterAll( () => {
	// Deleting the sites removes their content and uploads; the French network items go with their site.
	for ( const id of [ state.fr, state.en ] ) {
		if ( id ) {
			tryWp( '/', 'site', 'delete', String( id ), '--yes' );
			execFileSync( 'rm', [ '-rf', `/var/www/html/web/wp-content/uploads/sites/${ id }` ] );
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

/** Opens a post in the editor with the settings sidebar and the Languages panel open. */
async function openLanguagesPanel( page, path, post ) {
	await page.goto( `${ path }wp-admin/post.php?post=${ post }&action=edit` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' );
	} );
	const toggle = page.getByRole( 'button', { name: 'Languages', exact: true } );
	await expect( toggle ).toBeVisible();
	if ( 'false' === ( await toggle.getAttribute( 'aria-expanded' ) ) ) {
		await toggle.click();
	}
	return page.locator( '.windrose-sabir-languages' );
}

test( 'Languages panel: copy the article to French as a linked draft', async ( { page } ) => {
	const panel = await openLanguagesPanel( page, EN, state.post );
	const box = panel.getByRole( 'checkbox', { name: 'Copy to Français' } );
	await expect( box ).toBeVisible();
	await box.check();
	await expect( panel ).toContainText( '1 image or file will be shared with the network first.' );
	await expectAccessible( page, '.windrose-sabir-languages' );

	const copied = page.waitForResponse( ( r ) => r.url().includes( `windrose-sabir/v1/copy/${ state.post }` ) && r.request().method() === 'POST' );
	await panel.getByRole( 'button', { name: 'Copy as drafts' } ).click();
	const result = ( await ( await copied ).json() ).results[ 0 ];
	expect( result.ok ).toBe( true );
	state.draft = result.post;

	await expect( panel ).toContainText( 'Copied to Français as a draft.' );
	await expect( panel ).toContainText( 'Shared with the network' );
	await expect( panel.getByRole( 'link', { name: /Edit the draft/ } ) ).toHaveAttribute( 'href', new RegExp( `${ FR }wp-admin/post\\.php\\?post=${ state.draft }` ) );
	// The panel now lists the translation instead of the box.
	await expect( panel.getByRole( 'link', { name: 'Spring fields' } ) ).toBeVisible();
	await expect( panel.getByRole( 'checkbox', { name: 'Copy to Français' } ) ).toHaveCount( 0 );
	await expectAccessible( page, '.windrose-sabir-languages' );
} );

for ( const [ key, label, expected ] of [
	[ 'network', 'Use network items (one file for every language)', '1 image or file will be shared with the network first.' ],
	[ 'copy', 'Copy the files to each site', '1 image or file will be copied into the other site’s media library.' ],
] ) {
	test( `a draft prepared in several languages: images choice "${ key }"`, async ( { page } ) => {
		const { post, image } = state.drafts[ key ];
		const panel = await openLanguagesPanel( page, EN, post );
		await panel.getByRole( 'checkbox', { name: 'Copy to Français' } ).check();
		const choice = panel.getByRole( 'radiogroup', { name: 'Images' } );
		await expect( choice ).toBeVisible();
		await choice.getByRole( 'radio', { name: label } ).check();
		await expect( panel ).toContainText( expected );
		await expectAccessible( page, '.windrose-sabir-languages' );

		const copied = page.waitForResponse( ( r ) => r.url().includes( `windrose-sabir/v1/copy/${ post }` ) && r.request().method() === 'POST' );
		await panel.getByRole( 'button', { name: 'Copy as drafts' } ).click();
		const result = ( await ( await copied ).json() ).results[ 0 ];
		expect( result.ok, result.error ).toBe( true );
		const content = JSON.parse( wpAt( FR, 'post', 'get', String( result.post ), '--format=json' ) ).post_content;
		const item = Number( content.match( /"id":(\d+)/ )[ 1 ] );
		const hub = tryWp( FR, 'post', 'meta', 'get', String( item ), '_windrose_anchors_hub' );
		if ( 'network' === key ) {
			expect( Number( hub ) ).toBe( image );
			expect( result.images[ 0 ].how ).toBe( 'share' );
		} else {
			expect( hub ).toBeFalsy();
			expect( result.images[ 0 ].reason ).toBe( 'chosen' );
			expect( wpAt( FR, 'post', 'meta', 'get', String( item ), '_windrose_sabir_source' ) ).toBe( `${ state.en }:${ image }` );
		}
		// The English draft is still a draft: nothing was published.
		expect( wpAt( EN, 'post', 'get', String( post ), '--field=post_status' ) ).toBe( 'draft' );
	} );
}

test( 'the French draft: network items, caption and credit, linked both ways', async ( { request } ) => {
	const draft = JSON.parse( wpAt( FR, 'post', 'get', String( state.draft ), '--format=json' ) );
	expect( draft.post_status ).toBe( 'draft' );
	expect( draft.post_title ).toBe( 'Spring fields' );
	const item = Number( wpAt( FR, 'post', 'meta', 'get', String( state.draft ), '_thumbnail_id' ) );
	expect( Number( wpAt( FR, 'post', 'meta', 'get', String( item ), '_windrose_anchors_hub' ) ) ).toBe( state.image );
	expect( draft.post_content ).toContain( `"id":${ item }` );
	expect( draft.post_content ).toContain( `wp-image-${ item }` );
	expect( draft.post_content ).toContain( 'Canola in May. Photo: Jane Doe' );
	expect( wpAt( FR, 'eval', `echo get_post_meta( ${ item }, '_wp_attachment_image_alt', true );` ) ).toBe( 'Yellow canola field' );
	expect( wpAt( EN, 'post', 'meta', 'get', String( state.image ), '_windrose_anchors_shared' ) ).toBe( '1' );
	expect( JSON.parse( wpAt( EN, 'option', 'get', `msls_${ state.post }`, '--format=json' ) ) ).toEqual( { fr_FR: state.draft } );
	expect( JSON.parse( wpAt( FR, 'option', 'get', `msls_${ state.draft }`, '--format=json' ) ) ).toEqual( { en_US: state.post } );

	// Published, the French post shows the hub's file (answering) with the French caption block.
	wpAt( FR, 'post', 'update', String( state.draft ), '--post_status=publish' );
	const url = wpAt( FR, 'eval', `echo get_permalink( ${ state.draft } );` );
	const html = await ( await request.get( url ) ).text();
	const src = html.match( new RegExp( `<img[^>]+class="wp-image-${ item }"[^>]*>` ) )?.[ 0 ].match( /src="([^"]+)"/ )?.[ 1 ];
	expect( src ).toContain( `/uploads/sites/${ state.en }/` );
	expect( ( await request.get( src ) ).status() ).toBe( 200 );
	expect( html ).toContain( 'Canola in May. Photo: Jane Doe' );
} );

test( 'done when: the language item is correct in the French header', async ( { request } ) => {
	const enUrl = wpAt( EN, 'eval', `echo get_permalink( ${ state.post } );` );
	const frUrl = wpAt( FR, 'eval', `echo get_permalink( ${ state.draft } );` );
	const html = await ( await request.get( frUrl ) ).text();
	const header = html.match( /<header[\s\S]*?<\/header>/ )?.[ 0 ] ?? html;
	const items = [ ...header.matchAll( /<li class="wp-block-navigation-item windrose-language-item__item[^"]*"><a class="wp-block-navigation-item__content" href="([^"]+)" hreflang="([^"]+)" lang="[^"]+"( aria-current="page")?><span class="wp-block-navigation-item__label"><span translate="no">([^<]+)<\/span>/g ) ].map(
		( m ) => ( { href: m[ 1 ], hreflang: m[ 2 ], current: !! m[ 3 ], name: m[ 4 ] } )
	);
	// Ipsum's header holds the menu twice (the navigation and its mobile overlay): the same items each time.
	const distinct = [ ...new Map( items.map( ( item ) => [ JSON.stringify( item ), item ] ) ).values() ];
	expect( distinct ).toEqual( [
		{ href: enUrl, hreflang: 'en', current: false, name: 'English' },
		{ href: frUrl, hreflang: 'fr', current: true, name: 'Français' },
	] );
	expect( ( await request.get( enUrl ) ).status() ).toBe( 200 );
	// MSLS's hreflang in the head names both languages.
	const hreflang = [ ...html.matchAll( /<link rel="alternate" href="[^"]+" hreflang="([^"]+)"/g ) ].map( ( m ) => m[ 1 ] );
	expect( hreflang ).toContain( 'en' );
	expect( hreflang ).toContain( 'fr' );
} );

test( 'the language item block in the editor and Site Health on the French site', async ( { page } ) => {
	await page.goto( `${ FR }wp-admin/site-health.php` );
	// Good results are in the "Passed tests" list (the French admin translates the button, not our text).
	await expect( page.locator( '.site-health-view-passed' ) ).toBeVisible( { timeout: 30000 } );
	await expect( page.locator( '#health-check-issues-good' ).getByText( 'The header has a language item' ) ).toBeAttached( { timeout: 30000 } );
	await page.locator( '.site-health-view-passed' ).click();
	await expect( page.locator( '#health-check-issues-good' ).getByText( 'The header has a language item' ) ).toBeVisible();
	await expect( page.locator( '#health-check-issues-good' ).getByText( 'Translation links fit the language group' ) ).toBeVisible();

	// The block offers its settings and previews the group's languages.
	await page.goto( `${ FR }wp-admin/post-new.php` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	const id = await page.evaluate( () => {
		window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		const block = window.wp.blocks.createBlock( 'windrose/language-item' );
		window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
		window.wp.data.dispatch( 'core/block-editor' ).selectBlock( block.clientId );
		window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/block' );
		return window.wp.data.select( 'core/editor' ).getCurrentPostId();
	} );
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await expect( canvas.locator( '.windrose-language-item-editor' ) ).toContainText( 'English' );
	await expect( canvas.locator( '.windrose-language-item-editor' ) ).toContainText( 'Français' );
	await page.getByRole( 'combobox', { name: /^(Show|Afficher)$/ } ).selectOption( 'code' );
	await expect( canvas.locator( '.windrose-language-item-editor' ) ).toContainText( 'EN' );
	await expectAccessible( page, '.block-editor-block-inspector' );
	tryWp( FR, 'post', 'delete', String( id ), '--force' );
} );

test( 'bulk action and the Copy settings on the MSLS settings page', async ( { page } ) => {
	await page.goto( `${ EN }wp-admin/edit.php` );
	await page.getByRole( 'checkbox', { name: 'Select Summer fields' } ).check();
	await page.locator( '#bulk-action-selector-top' ).selectOption( `windrose_sabir_copy_${ state.fr }` );
	await page.locator( '#doaction' ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( '1 post copied to Sabir e2e Français' );
	await expectAccessible( page, '.notice-success' );
	const links = JSON.parse( wpAt( EN, 'option', 'get', `msls_${ state.second }`, '--format=json' ) );
	expect( Object.keys( links ) ).toEqual( [ 'fr_FR' ] );

	await page.goto( `${ FR }wp-admin/options-general.php?page=MslsAdmin` );
	const section = page.getByRole( 'heading', { name: 'Copy (Windrose Sabir)' } );
	await expect( section ).toBeVisible();
	await page.getByRole( 'radio', { name: /Leave them out/ } ).check();
	await page.locator( 'input[name="Submit"]' ).click();
	await expect( page.getByRole( 'radio', { name: /Leave them out/ } ) ).toBeChecked();
	expect( JSON.parse( wpAt( FR, 'option', 'get', 'windrose_sabir_site', '--format=json' ) ).terms ).toBe( 'skip' );
	await expectAccessible( page, '#wpbody-content' );
} );
