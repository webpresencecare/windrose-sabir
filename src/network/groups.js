/**
 * Windrose Sabir network admin, Language groups: which sites are translations of each other.
 */
import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	ExternalLink,
	Modal,
	Notice,
	SearchControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';

import { useNotice } from './notice';

const data = window.windroseSabirNetwork || {};
const PATH = data.rest || '/windrose-sabir/v1/groups';
const MIN_SITES = data.minSites || 2;
const LABEL_MAX = data.labelMax || 100;

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	sort: { field: 'label', direction: 'asc' },
	titleField: 'label',
	fields: [ 'sites' ],
	layout: {},
};

/**
 * A site's name and language, for lists and checkboxes.
 *
 * @param {Object} site Site row from the REST route.
 * @return {string} Label.
 */
function siteLabel( site ) {
	// Each part isolated (U+2068 … U+2069), so a right-to-left name does not reorder its neighbour.
	return sprintf(
		/* translators: 1: site name, 2: language name (Français (France)). */
		__( '%1$s: %2$s', 'windrose-sabir' ),
		`\u2068${ site.name || site.address }\u2069`,
		`\u2068${ site.language }\u2069`
	);
}

/**
 * Notes about a site that matter for language groups.
 *
 * @param {Object} site Site row.
 * @return {string[]} Sentences.
 */
function siteNotes( site ) {
	const notes = [];
	if ( 'missing' === site.msls ) {
		notes.push(
			__(
				'Multisite Language Switcher is not set up on this site yet.',
				'windrose-sabir'
			)
		);
	} else if ( 'excluded' === site.msls ) {
		notes.push(
			__(
				'This site is excluded from output in Multisite Language Switcher.',
				'windrose-sabir'
			)
		);
	}
	if ( 'archived' === site.status ) {
		notes.push(
			__(
				'This site is archived: it is not linked while archived.',
				'windrose-sabir'
			)
		);
	} else if ( 'spam' === site.status ) {
		notes.push(
			__(
				'This site is marked as spam: it is not linked.',
				'windrose-sabir'
			)
		);
	}
	return notes;
}

/**
 * The form to create or change a group.
 *
 * @param {Object}                                 props
 * @param {Object}                                 props.group   Group being changed, or null for a new one.
 * @param {Object[]}                               props.groups  Every group.
 * @param {Object[]}                               props.sites   Every site.
 * @param {() => void}                             props.onClose Closes the form.
 * @param {(state: Object, label: string) => void} props.onSaved Receives the new state after saving.
 */
function GroupModal( { group, groups, sites, onClose, onSaved } ) {
	const [ label, setLabel ] = useState( group?.label ?? '' );
	const [ chosen, setChosen ] = useState( group?.sites ?? [] );
	const [ main, setMain ] = useState( group?.main ?? 0 );
	// Translation links the change affects: shown once, then Save confirms.
	const [ warnings, setWarnings ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const groupLabel = ( id ) =>
		groups.find( ( item ) => item.id === id )?.label ?? '';

	const shown = sites.filter( ( site ) => {
		if ( ! search ) {
			return true;
		}
		const needle = search.toLowerCase();
		return [ site.name, site.address, site.language, site.locale ].some(
			( value ) => String( value ).toLowerCase().includes( needle )
		);
	} );

	const fail = ( message ) => {
		setError( message );
		speak( message, 'assertive' );
	};

	const submit = async ( event ) => {
		event.preventDefault();
		if ( ! label.trim() ) {
			fail( __( 'Give the language group a name.', 'windrose-sabir' ) );
			return;
		}
		if ( chosen.length < MIN_SITES ) {
			fail(
				__(
					'Choose at least two sites: a language group links sites that are translations of each other.',
					'windrose-sabir'
				)
			);
			return;
		}
		const byLocale = {};
		for ( const id of chosen ) {
			const site = sites.find( ( item ) => item.id === id );
			if ( site && byLocale[ site.locale ] ) {
				fail(
					sprintf(
						/* translators: 1: site name, 2: other site name, 3: locale code (fr_FR). */
						__(
							'%1$s and %2$s both use the language %3$s. A language group holds one site per language.',
							'windrose-sabir'
						),
						byLocale[ site.locale ].name,
						site.name,
						site.locale
					)
				);
				return;
			}
			if ( site ) {
				byLocale[ site.locale ] = site;
			}
		}
		setBusy( true );
		setError( '' );
		try {
			if ( null === warnings ) {
				const preview = await apiFetch( {
					path: `${ PATH }/preview`,
					method: 'POST',
					data: { id: group?.id ?? 0, sites: chosen },
				} );
				if ( preview.messages?.length ) {
					setWarnings( preview.messages );
					setBusy( false );
					speak(
						__(
							'Translation links will change. Read the list, then save again to confirm.',
							'windrose-sabir'
						),
						'assertive'
					);
					return;
				}
			}
			const state = await apiFetch( {
				path: group ? `${ PATH }/${ group.id }` : PATH,
				method: 'POST',
				data: {
					label: label.trim(),
					sites: chosen,
					main: chosen.includes( main ) ? main : 0,
				},
			} );
			onSaved( state, label.trim() );
		} catch ( requestError ) {
			setBusy( false );
			fail( requestError.message );
		}
	};

	const toggle = ( id, checked ) => {
		setWarnings( null );
		setChosen( ( current ) =>
			checked
				? [ ...current, id ]
				: current.filter( ( item ) => item !== id )
		);
		if ( ! checked && id === main ) {
			setMain( 0 );
		}
	};

	return (
		<Modal
			title={
				group
					? __( 'Edit language group', 'windrose-sabir' )
					: __( 'Add language group', 'windrose-sabir' )
			}
			onRequestClose={ onClose }
			className="windrose-sabir-modal"
		>
			<form onSubmit={ submit } noValidate>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Name', 'windrose-sabir' ) }
					help={ __(
						'For you only, for example the name of the project or of the main site.',
						'windrose-sabir'
					) }
					value={ label }
					maxLength={ LABEL_MAX }
					onChange={ setLabel }
					required
				/>
				<fieldset>
					<legend>{ __( 'Sites', 'windrose-sabir' ) }</legend>
					<p className="windrose-sabir__muted">
						{ __(
							'The sites that are translations of each other: at least two, one per language. A site can be in one language group only.',
							'windrose-sabir'
						) }
					</p>
					{ sites.length > 10 && (
						<SearchControl
							__nextHasNoMarginBottom
							label={ __( 'Search sites', 'windrose-sabir' ) }
							value={ search }
							onChange={ setSearch }
						/>
					) }
					{ shown.map( ( site ) => {
						const other =
							site.group && site.group !== group?.id
								? site.group
								: 0;
						const notes = [
							other
								? sprintf(
										/* translators: %s: group name. */
										__(
											'Already in the language group “%s”.',
											'windrose-sabir'
										),
										groupLabel( other )
									)
								: '',
							...siteNotes( site ),
						].filter( Boolean );
						const help = (
							<>
								{ site.address }
								{ notes.map( ( note ) => (
									<span key={ note }>
										<br />
										{ note }
									</span>
								) ) }
							</>
						);
						return (
							<div
								key={ site.id }
								className="windrose-sabir__site-option"
							>
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ siteLabel( site ) }
									help={ help }
									checked={ chosen.includes( site.id ) }
									disabled={ !! other }
									onChange={ ( checked ) =>
										toggle( site.id, checked )
									}
								/>
							</div>
						);
					} ) }
				</fieldset>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Main site', 'windrose-sabir' ) }
					help={
						data.anchors ? (
							<>
								{ __(
									'Optional: the site of the source language. Content is usually copied from it.',
									'windrose-sabir'
								) }{ ' ' }
								{ createInterpolateElement(
									__(
										'To make it the media hub of the group’s sites, turn on “Follow language groups” in <a>Windrose Anchors, Sites</a>.',
										'windrose-sabir'
									),
									{
										// The link text comes from the sentence.
										// eslint-disable-next-line jsx-a11y/anchor-has-content
										a: <a href={ data.anchors } />,
									}
								) }
							</>
						) : (
							__(
								'Optional: the site of the source language. Content is usually copied from it.',
								'windrose-sabir'
							)
						)
					}
					value={ String( chosen.includes( main ) ? main : 0 ) }
					options={ [
						{ value: '0', label: __( 'None', 'windrose-sabir' ) },
						...sites
							.filter( ( site ) => chosen.includes( site.id ) )
							.map( ( site ) => ( {
								value: String( site.id ),
								label: siteLabel( site ),
							} ) ),
					] }
					onChange={ ( value ) => setMain( Number( value ) ) }
				/>
				{ warnings && (
					<Notice
						status="warning"
						isDismissible={ false }
						className="windrose-sabir__links"
					>
						<p>
							{ __(
								'Multisite Language Switcher links translations by language, not by site. With this change:',
								'windrose-sabir'
							) }
						</p>
						<ul>
							{ warnings.map( ( message ) => (
								<li key={ message }>{ message }</li>
							) ) }
						</ul>
						<p>
							{ __(
								'Translation links are kept as they are. Save again to confirm, or change the sites.',
								'windrose-sabir'
							) }
						</p>
					</Notice>
				) }
				<div className="windrose-sabir__buttons">
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ onClose }
					>
						{ __( 'Cancel', 'windrose-sabir' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						type="submit"
						isBusy={ busy }
						accessibleWhenDisabled
						disabled={ busy }
					>
						{ warnings
							? __( 'Save anyway', 'windrose-sabir' )
							: __( 'Save', 'windrose-sabir' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

/**
 * Confirms deleting a group.
 *
 * @param {Object}                  props
 * @param {Object}                  props.group     Group.
 * @param {() => void}              props.onClose   Closes the dialog.
 * @param {(state: Object) => void} props.onDeleted Receives the new state.
 */
function DeleteModal( { group, onClose, onDeleted } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const remove = async () => {
		setBusy( true );
		try {
			const state = await apiFetch( {
				path: `${ PATH }/${ group.id }`,
				method: 'DELETE',
			} );
			onDeleted( state );
		} catch ( requestError ) {
			setBusy( false );
			setError( requestError.message );
			speak( requestError.message, 'assertive' );
		}
	};
	return (
		<div className="windrose-sabir-delete">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<p>
				{ sprintf(
					/* translators: %s: group name. */
					__(
						'Delete the language group “%s”? Its sites then show no language switcher and no translation links while other language groups exist. Translation links already made stay in Multisite Language Switcher and come back when the sites are grouped again.',
						'windrose-sabir'
					),
					group.label
				) }
			</p>
			<div className="windrose-sabir__buttons">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onClose }
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
					{ __( 'Delete', 'windrose-sabir' ) }
				</Button>
			</div>
		</div>
	);
}

export default function GroupsScreen() {
	const [ state, setState ] = useState( null );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ editing, setEditing ] = useState( null );
	const [ notice, notify ] = useNotice();

	const load = useCallback( async () => {
		try {
			setState( await apiFetch( { path: PATH } ) );
		} catch ( error ) {
			setState( { groups: [], sites: [] } );
			notify( 'error', error.message );
		}
	}, [ notify ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const sites = useMemo( () => state?.sites ?? [], [ state ] );
	const groups = useMemo( () => state?.groups ?? [], [ state ] );
	const siteById = useMemo(
		() => Object.fromEntries( sites.map( ( site ) => [ site.id, site ] ) ),
		[ sites ]
	);
	const ungrouped = sites.filter( ( site ) => ! site.group );

	const fields = useMemo(
		() => [
			{
				id: 'label',
				type: 'text',
				label: __( 'Name', 'windrose-sabir' ),
				enableHiding: false,
				enableGlobalSearch: true,
			},
			{
				id: 'sites',
				label: __( 'Sites', 'windrose-sabir' ),
				enableSorting: false,
				getValue: ( { item } ) =>
					item.sites
						.map( ( id ) => siteById[ id ]?.name ?? '' )
						.join( ' ' ),
				render: ( { item } ) => (
					<ul className="windrose-sabir__sites">
						{ item.sites.map( ( id ) => {
							const site = siteById[ id ];
							return site ? (
								<li key={ id }>
									<ExternalLink href={ site.url }>
										{ siteLabel( site ) }
									</ExternalLink>
									{ id === item.main && (
										<span className="windrose-sabir__muted">
											{ ' ' }
											{ __(
												'(main site)',
												'windrose-sabir'
											) }
										</span>
									) }
									{ siteNotes( site ).map( ( note ) => (
										<span
											key={ note }
											className="windrose-sabir__muted"
										>
											{ ' ' }
											{ note }
										</span>
									) ) }
								</li>
							) : null;
						} ) }
					</ul>
				),
			},
		],
		[ siteById ]
	);

	const { data: shown, paginationInfo } = useMemo(
		() => filterSortAndPaginate( groups, view, fields ),
		[ groups, view, fields ]
	);

	const saved = ( newState, label ) => {
		const isNew = editing === 'new';
		setEditing( null );
		setState( newState );
		const message = isNew
			? sprintf(
					/* translators: %s: group name. */
					__( 'Language group “%s” added.', 'windrose-sabir' ),
					label
				)
			: sprintf(
					/* translators: %s: group name. */
					__( 'Language group “%s” saved.', 'windrose-sabir' ),
					label
				);
		notify( 'success', message );
	};

	const actions = [
		{
			id: 'edit',
			label: __( 'Edit', 'windrose-sabir' ),
			isPrimary: true,
			callback: ( [ item ] ) => setEditing( item ),
		},
		{
			id: 'delete',
			label: __( 'Delete', 'windrose-sabir' ),
			isDestructive: true,
			modalHeader: __( 'Delete language group', 'windrose-sabir' ),
			RenderModal: ( { items, closeModal } ) => (
				<DeleteModal
					group={ items[ 0 ] }
					onClose={ closeModal }
					onDeleted={ ( newState ) => {
						closeModal();
						setState( newState );
						notify(
							'success',
							sprintf(
								/* translators: %s: group name. */
								__(
									'Language group “%s” deleted.',
									'windrose-sabir'
								),
								items[ 0 ].label
							)
						);
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
						{ __( 'Language groups', 'windrose-sabir' ) }
					</h2>
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ () => setEditing( 'new' ) }
						disabled={ state === null }
						accessibleWhenDisabled
					>
						{ __( 'Add language group', 'windrose-sabir' ) }
					</Button>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'A language group holds the sites that are translations of each other, one per language. Multisite Language Switcher then links only the sites of the same group: the language switcher, the hreflang links for search engines and the translation boxes in the editor. Sites in no group, such as a social wall, show none of them.',
							'windrose-sabir'
						) }
					</p>
					<p>
						{ __(
							'Each site’s language is set in its Settings, Multisite Language Switcher.',
							'windrose-sabir'
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
						getItemId={ ( item ) => String( item.id ) }
						isLoading={ state === null }
						searchLabel={ __(
							'Search language groups',
							'windrose-sabir'
						) }
						empty={
							<p>
								{ __(
									'No language group yet. Until one is saved, Multisite Language Switcher links the sites of its reference user, as without Windrose Sabir.',
									'windrose-sabir'
								) }
							</p>
						}
					/>
				</CardBody>
			</Card>
			{ state !== null && (
				<Card className="windrose-sabir__card">
					<CardHeader>
						<h2 className="windrose-sabir__card-title">
							{ __(
								'Sites in no language group',
								'windrose-sabir'
							) }
						</h2>
					</CardHeader>
					<CardBody>
						<p>
							{ groups.length
								? __(
										'These sites show no language switcher and no translation links.',
										'windrose-sabir'
									)
								: __(
										'Once a language group is saved, sites outside every group show no language switcher and no translation links.',
										'windrose-sabir'
									) }
						</p>
						{ ungrouped.length ? (
							<ul className="windrose-sabir__sites">
								{ ungrouped.map( ( site ) => (
									<li key={ site.id }>
										<ExternalLink href={ site.url }>
											{ siteLabel( site ) }
										</ExternalLink>
										{ siteNotes( site ).map( ( note ) => (
											<span
												key={ note }
												className="windrose-sabir__muted"
											>
												{ ' ' }
												{ note }
											</span>
										) ) }
									</li>
								) ) }
							</ul>
						) : (
							<p>
								{ sprintf(
									/* translators: %d: number of sites. */
									_n(
										'%d site is in a language group.',
										'All %d sites are in a language group.',
										sites.length,
										'windrose-sabir'
									),
									sites.length
								) }
							</p>
						) }
					</CardBody>
				</Card>
			) }
			{ editing && (
				<GroupModal
					group={ editing === 'new' ? null : editing }
					groups={ groups }
					sites={ sites }
					onClose={ () => setEditing( null ) }
					onSaved={ saved }
				/>
			) }
		</>
	);
}
