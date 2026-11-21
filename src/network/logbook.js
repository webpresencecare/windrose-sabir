/**
 * Windrose Sabir network admin, Logbook: download the network's languages and translations.
 */
import { useEffect, useState } from '@wordpress/element';
import { Card, CardBody, CardHeader, Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { useNotice } from './notice';

const data = window.windroseSabirNetwork || {};
const ROOT = data.root || '/windrose-sabir/v1';

export default function LogbookScreen() {
	const [ state, setState ] = useState( null );
	const [ notice, notify ] = useNotice();

	useEffect( () => {
		apiFetch( { path: `${ ROOT }/logbook` } )
			.then( setState )
			.catch( ( error ) => notify( 'error', error.message ) );
	}, [ notify ] );

	return (
		<>
			{ notice }
			<Card className="windrose-sabir__card">
				<CardHeader>
					<h2 className="windrose-sabir__card-title">
						{ __( 'Export', 'windrose-sabir' ) }
					</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'The logbook holds the language groups, each site’s language and every set of translations, with their addresses. Keep it before changing groups or removing links, when moving the network, or to leave Multisite Language Switcher one day.',
							'windrose-sabir'
						) }
					</p>
					{ ! state && <Spinner /> }
					{ state && (
						<>
							<p>
								{ [
									sprintf(
										/* translators: %d: number of sites. */
										_n(
											'%d site.',
											'%d sites.',
											state.sites,
											'windrose-sabir'
										),
										state.sites
									),
									sprintf(
										/* translators: %d: number of translation sets. */
										_n(
											'%d set of translated posts.',
											'%d sets of translated posts.',
											state.posts,
											'windrose-sabir'
										),
										state.posts
									),
									sprintf(
										/* translators: %d: number of translation sets. */
										_n(
											'%d set of translated terms.',
											'%d sets of translated terms.',
											state.terms,
											'windrose-sabir'
										),
										state.terms
									),
								].join( ' ' ) }
							</p>
							<p className="windrose-sabir__buttons-row">
								<a
									className="components-button is-primary is-next-40px-default-size"
									href={ state.json }
								>
									{ __(
										'Download the logbook (JSON)',
										'windrose-sabir'
									) }
								</a>{ ' ' }
								<a
									className="components-button is-secondary is-next-40px-default-size"
									href={ state.csv }
								>
									{ __(
										'Download the address table (CSV)',
										'windrose-sabir'
									) }
								</a>
							</p>
							<p className="windrose-sabir__muted">
								{ __(
									'The address table has one column per site and one row per set of published translations: a starting point for redirects. With WP-CLI: wp windrose-sabir logbook export --file=logbook.json --csv=addresses.csv',
									'windrose-sabir'
								) }
							</p>
						</>
					) }
				</CardBody>
			</Card>
		</>
	);
}
