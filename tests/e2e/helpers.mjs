/**
 * Shared helpers for Windrose Sabir's Playwright tests, run on a WordPress multisite test network with WP-CLI.
 * Environment: WINDROSE_E2E_HOST (the network's host), WINDROSE_E2E_USER and WINDROSE_PASS (a super admin).
 */
import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

export const HOST = process.env.WINDROSE_E2E_HOST ?? 'localhost';

/**
 * DataViews 22 draws its selection checkboxes (`@wordpress/ui` Checkbox) 16px wide with a 24px `::after` click area
 * that axe cannot see, so axe reports them under target-size although people get 24px (checked with real clicks on
 * 2026-11-10). Only those checkboxes are left out of target-size; everything else is still checked. Remove once fixed
 * upstream (Windrose dev notes: gutenberg-contrib/dataviews-checkbox-target-size.md).
 *
 * @param {import('@playwright/test').Page} page       The page.
 * @param {Array}                           violations axe violations.
 * @return {Promise<Array>} The violations to report.
 */
async function withoutDataViewsCheckboxes( page, violations ) {
	const kept = [];
	for ( const violation of violations ) {
		if ( 'target-size' !== violation.id ) {
			kept.push( violation );
			continue;
		}
		const nodes = [];
		for ( const node of violation.nodes ) {
			const skipped = await page.evaluate( ( selector ) => {
				const element = document.querySelector( selector );
				return !! element && 'checkbox' === element.getAttribute( 'role' ) && !! element.closest( '.dataviews-wrapper' );
			}, String( node.target[ 0 ] ) );
			if ( ! skipped ) {
				nodes.push( node );
			}
		}
		if ( nodes.length ) {
			kept.push( { ...violation, nodes } );
		}
	}
	return kept;
}

/** WCAG 2.2 AA checks (axe) on part of the page, once animations (a dialog fading in) have ended. */
export async function expectAccessible( page, selector ) {
	await page.waitForFunction( () => document.getAnimations().every( ( animation ) => 'running' !== animation.playState ) );
	const results = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	const violations = await withoutDataViewsCheckboxes( page, results.violations );
	expect( violations.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target ).join( ', ' ) }` ) ).toEqual( [] );
}
