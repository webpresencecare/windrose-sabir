/**
 * Windrose Sabir network admin, Translation links: links that no longer fit a site's language group, and
 * their removal. The list is the dry run; nothing changes until "Remove" is confirmed, and the server checks each
 * link again before removing it.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ExternalLink,
	Modal,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { useNotice } from './notice';

const data = window.windroseSabirNetwork || {};
const ROOT = data.root || '/windrose-sabir/v1';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 50,
	sort: { field: 'siteName', direction: 'asc' },
	titleField: 'title',
	fields: [ 'siteName', 'language', 'targetName', 'reasonText' ],
	layout: {},
};

/**
 * Confirms and removes links.
 *
 * @param {Object}                   props
 * @param {Object[]}                 props.items     Links to remove.
 * @param {() => void}               props.onClose   Closes the dialog.
 * @param {(result: Object) => void} props.onRemoved Receives the server's answer.
 */
function RemoveModal( { items, onClose, onRemoved } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const remove = async () => {
		setBusy( true );
		setError( '' );
		try {
			onRemoved(
				await apiFetch( {
					path: `${ ROOT }/links/remove`,
					method: 'POST',
					data: {
						items: items.map( ( item ) => ( {
							site: item.site,
							kind: item.kind,
							id: item.id,
							locale: item.locale,
						} ) ),
					},
				} )
			);
		} catch ( requestError ) {
			setError( requestError.message );
			setBusy( false );
		}
	};
	return (
		<div className="windrose-sabir-modal">
			<p>
				{ sprintf(
					/* translators: %d: number of links. */
					_n(
						'Remove %d translation link? The posts and terms stay; only the link between them goes.',
						'Remove %d translation links? The posts and terms stay; only the links between them go.',
						items.length,
						'windrose-sabir'
					),
					items.length
				) }
			</p>
			<p>
				{ __(
					'This cannot be undone. Export the logbook first if you may need the links again.',
					'windrose-sabir'
				) }
			</p>
			{ error && (
				<p className="windrose-sabir__error" role="alert">
					{ error }
				</p>
			) }
			<div className="windrose-sabir__buttons">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onClose }
					disabled={ busy }
				>
					{ __( 'Cancel', 'windrose-sabir' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive
					isBusy={ busy }
					accessibleWhenDisabled
					disabled={ busy }
					onClick={ remove }
				>
					{ __( 'Remove', 'windrose-sabir' ) }
				</Button>
			</div>
		</div>
	);
}

export default function LinksScreen() {
	const [ state, setState ] = useState( null );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ removing, setRemoving ] = useState( null );
	const [ notice, notify ] = useNotice();

	const load = useCallback( async () => {
		try {
			setState( await apiFetch( { path: `${ ROOT }/links` } ) );
		} catch ( error ) {
			setState( { groups: false, items: [] } );
			notify( 'error', error.message );
		}
	}, [ notify ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const items = useMemo( () => state?.items ?? [], [ state ] );
	const reasons = useMemo(
		() =>
			[ ...new Set( items.map( ( item ) => item.reasonText ) ) ].map(
				( text ) => ( { value: text, label: text } )
			),
		[ items ]
	);
	const siteNames = useMemo(
		() =>
			[ ...new Set( items.map( ( item ) => item.siteName ) ) ].map(
				( name ) => ( { value: name, label: name } )
			),
		[ items ]
	);

	const fields = useMemo(
		() => [
			{
				id: 'title',
				type: 'text',
				label: __( 'Post or term', 'windrose-sabir' ),
				enableHiding: false,
				enableGlobalSearch: true,
				render: ( { item } ) =>
					item.edit ? (
						<a href={ item.edit }>{ item.title }</a>
					) : (
						item.title
					),
			},
			{
				id: 'siteName',
				type: 'text',
				label: __( 'Site', 'windrose-sabir' ),
				elements: siteNames,
				filterBy: { operators: [ 'isAny' ] },
				enableGlobalSearch: true,
			},
			{
				id: 'language',
				type: 'text',
				label: __( 'Link to', 'windrose-sabir' ),
				render: ( { item } ) =>
					sprintf(
						/* translators: 1: language name, 2: post or term ID. */
						__( '%1$s, ID %2$d', 'windrose-sabir' ),
						item.language || item.locale,
						item.target
					),
			},
			{
				id: 'targetName',
				type: 'text',
				label: __( 'Site of that language', 'windrose-sabir' ),
				render: ( { item } ) =>
					item.targetName || __( 'None', 'windrose-sabir' ),
			},
			{
				id: 'reasonText',
				type: 'text',
				label: __( 'Problem', 'windrose-sabir' ),
				elements: reasons,
				filterBy: { operators: [ 'isAny' ] },
			},
		],
		[ reasons, siteNames ]
	);

	const { data: shown, paginationInfo } = useMemo(
		() => filterSortAndPaginate( items, view, fields ),
		[ items, view, fields ]
	);

	const removed = ( result ) => {
		setRemoving( null );
		setState( result );
		const parts = [
			sprintf(
				/* translators: %d: number of links. */
				_n(
					'%d translation link removed.',
					'%d translation links removed.',
					result.removed,
					'windrose-sabir'
				),
				result.removed
			),
		];
		if ( result.kept ) {
			parts.push(
				sprintf(
					/* translators: %d: number of links. */
					_n(
						'%d link was kept: it fits its group now.',
						'%d links were kept: they fit their group now.',
						result.kept,
						'windrose-sabir'
					),
					result.kept
				)
			);
		}
		notify( 'success', parts.join( ' ' ) );
	};

	const actions = [
		{
			id: 'remove',
			label: __( 'Remove link', 'windrose-sabir' ),
			isDestructive: true,
			supportsBulk: true,
			modalHeader: __( 'Remove translation links', 'windrose-sabir' ),
			RenderModal: ( { items: chosen, closeModal } ) => (
				<RemoveModal
					items={ chosen }
					onClose={ closeModal }
					onRemoved={ ( result ) => {
						closeModal();
						removed( result );
					} }
				/>
			),
		},
	];

	return (
		<>
			{ notice }
			<Card className="windrose-sabir__card">
				<CardHeader>
					<h2 className="windrose-sabir__card-title">
						{ __(
							'Links that no longer fit their group',
							'windrose-sabir'
						) }
					</h2>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isDestructive
						onClick={ () => setRemoving( items ) }
						disabled={ ! items.length }
						accessibleWhenDisabled
					>
						{ __( 'Remove all listed links', 'windrose-sabir' ) }
					</Button>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Multisite Language Switcher links translations by language, not by site. When a site joins, leaves or changes language group, or a post is deleted, some links lead to the wrong page or to nothing. This list shows them; nothing changes until you remove them.',
							'windrose-sabir'
						) }
					</p>
					<p>
						{ __(
							'Only sites in a language group are checked: the links of a site in no group are kept for when it joins one.',
							'windrose-sabir'
						) }{ ' ' }
						{ data.urls?.groups && (
							<ExternalLink href={ data.urls.groups }>
								{ __( 'Language groups', 'windrose-sabir' ) }
							</ExternalLink>
						) }
					</p>
				</CardBody>
				<CardBody className="windrose-sabir__table">
					<DataViews
						data={ shown }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						actions={ actions }
						paginationInfo={ paginationInfo }
						defaultLayouts={ { table: {} } }
						getItemId={ ( item ) => item.key }
						isLoading={ state === null }
						searchLabel={ __(
							'Search translation links',
							'windrose-sabir'
						) }
						empty={
							<p>
								{ state && ! state.groups
									? __(
											'This network has no language group: there is nothing to check.',
											'windrose-sabir'
										)
									: __(
											'Every translation link fits its language group.',
											'windrose-sabir'
										) }
							</p>
						}
					/>
				</CardBody>
			</Card>
			{ removing && (
				<Modal
					title={ __( 'Remove translation links', 'windrose-sabir' ) }
					onRequestClose={ () => setRemoving( null ) }
					className="windrose-sabir-modal"
				>
					<RemoveModal
						items={ removing }
						onClose={ () => setRemoving( null ) }
						onRemoved={ removed }
					/>
				</Modal>
			) }
		</>
	);
}
