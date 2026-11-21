/**
 * Windrose Sabir in the block editor: the Languages panel (Copy). It lists the other sites of the
 * site's language group with the post's translation there, or a box to copy the post there as a linked draft, and
 * says beforehand what happens to its images (network items, sharing, file copies). With Windrose Anchors, the
 * person chooses network items (default) or copies of the files.
 */
import { registerPlugin } from '@wordpress/plugins';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	ExternalLink,
	Notice,
	RadioControl,
	Spinner,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';

import './editor.scss';

const data = window.windroseSabirEditor || {};
const PATH = data.rest || '/windrose-sabir/v1/copy';

/**
 * What Copy will do with the media of the chosen sites, in sentences.
 *
 * @param {Object[]} targets Chosen sites.
 * @param {string}   images  network or copy.
 * @return {string[]} Sentences.
 */
function mediaSummary( targets, images ) {
	const total = { network: 0, share: 0, copy: 0, keep: 0 };
	targets.forEach( ( target ) => {
		const media = 'copy' === images ? target.mediaCopy : target.media;
		Object.keys( total ).forEach( ( key ) => {
			total[ key ] += media?.[ key ] ?? 0;
		} );
	} );
	const lines = [];
	if ( total.share ) {
		lines.push(
			sprintf(
				/* translators: %d: number of media items. */
				_n(
					'%d image or file will be shared with the network first.',
					'%d images or files will be shared with the network first.',
					total.share,
					'windrose-sabir'
				),
				total.share
			)
		);
	}
	if ( total.network ) {
		lines.push(
			sprintf(
				/* translators: %d: number of media items. */
				_n(
					'%d image or file will be used as a network item.',
					'%d images or files will be used as network items.',
					total.network,
					'windrose-sabir'
				),
				total.network
			)
		);
	}
	if ( total.copy ) {
		lines.push(
			sprintf(
				/* translators: %d: number of media items. */
				_n(
					'%d image or file will be copied into the other site’s media library.',
					'%d images or files will be copied into the other sites’ media libraries.',
					total.copy,
					'windrose-sabir'
				),
				total.copy
			)
		);
	}
	if ( total.keep ) {
		lines.push(
			sprintf(
				/* translators: %d: number of media items. */
				_n(
					'%d image or file cannot be copied: the content keeps its address.',
					'%d images or files cannot be copied: the content keeps their addresses.',
					total.keep,
					'windrose-sabir'
				),
				total.keep
			)
		);
	}
	return lines;
}

/**
 * The result of one copy.
 *
 * @param {Object}   props
 * @param {Object}   props.result  Result from the REST route.
 * @param {Object[]} props.targets Sites (for the language name).
 */
function Result( { result, targets } ) {
	const target = targets.find( ( site ) => site.id === result.site );
	const language = target?.language ?? String( result.site );
	if ( ! result.ok ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ sprintf(
					/* translators: 1: language name, 2: error message. */
					__( '%1$s: %2$s', 'windrose-sabir' ),
					language,
					result.error
				) }
			</Notice>
		);
	}
	const notes = [
		...result.images.map( ( image ) =>
			sprintf(
				/* translators: 1: media title, 2: what happened to it. */
				__( '%1$s: %2$s', 'windrose-sabir' ),
				image.title || __( '(no title)', 'windrose-sabir' ),
				image.text
			)
		),
		...result.notes,
	];
	const skipped = result.terms.filter( ( term ) =>
		[ 'skipped', 'denied' ].includes( term.how )
	);
	const created = result.terms.filter( ( term ) => 'created' === term.how );
	return (
		<Notice status="success" isDismissible={ false }>
			<p>
				{ sprintf(
					/* translators: %s: language name. */
					__( 'Copied to %s as a draft.', 'windrose-sabir' ),
					language
				) }{ ' ' }
				{ result.edit && (
					<ExternalLink href={ result.edit }>
						{ __( 'Edit the draft', 'windrose-sabir' ) }
					</ExternalLink>
				) }
			</p>
			{ created.length > 0 && (
				<p>
					{ sprintf(
						/* translators: %s: list of term names. */
						__(
							'New terms there, to translate: %s',
							'windrose-sabir'
						),
						created.map( ( term ) => term.name ).join( ', ' )
					) }
				</p>
			) }
			{ skipped.length > 0 && (
				<p>
					{ sprintf(
						/* translators: %s: list of term names. */
						__( 'Terms left out: %s', 'windrose-sabir' ),
						skipped.map( ( term ) => term.name ).join( ', ' )
					) }
				</p>
			) }
			{ notes.length > 0 && (
				<ul className="windrose-sabir-languages__notes">
					{ notes.map( ( note, index ) => (
						<li key={ index }>{ note }</li>
					) ) }
				</ul>
			) }
		</Notice>
	);
}

function LanguagesPanel() {
	const { postId, isDirty, isSaving, isNew } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postId: editor.getCurrentPostId(),
			isDirty: editor.isEditedPostDirty(),
			// Not after autosaves: the summary plans every image for every site.
			isSaving: editor.isSavingPost() && ! editor.isAutosavingPost(),
			isNew: editor.isEditedPostNew(),
		};
	}, [] );
	const [ targets, setTargets ] = useState( null );
	const [ anchors, setAnchors ] = useState( false );
	const [ images, setImages ] = useState( 'network' );
	const [ chosen, setChosen ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ results, setResults ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const wasSaving = useRef( false );

	const load = useCallback( async () => {
		if ( ! postId || isNew ) {
			setTargets( [] );
			return;
		}
		try {
			const response = await apiFetch( {
				path: `${ PATH }/${ postId }`,
			} );
			setTargets( response.targets );
			setAnchors( !! response.anchors );
			setError( '' );
		} catch ( requestError ) {
			setTargets( [] );
			setError( requestError.message );
		}
	}, [ postId, isNew ] );

	useEffect( () => {
		load();
	}, [ load ] );

	// After each save, the media summary may have changed.
	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			load();
		}
		wasSaving.current = isSaving;
	}, [ isSaving, load ] );

	const copy = async () => {
		setBusy( true );
		setResults( [] );
		try {
			const response = await apiFetch( {
				path: `${ PATH }/${ postId }`,
				method: 'POST',
				data: { sites: chosen, images },
			} );
			setResults( response.results );
			setTargets( response.targets );
			setChosen( [] );
			const done = response.results.filter( ( r ) => r.ok ).length;
			speak(
				sprintf(
					/* translators: %d: number of drafts. */
					_n(
						'%d draft created.',
						'%d drafts created.',
						done,
						'windrose-sabir'
					),
					done
				),
				done === response.results.length ? 'polite' : 'assertive'
			);
		} catch ( requestError ) {
			setError( requestError.message );
			speak( requestError.message, 'assertive' );
		}
		setBusy( false );
	};

	const chosenTargets = ( targets ?? [] ).filter( ( target ) =>
		chosen.includes( target.id )
	);
	const mustSave = isNew || isDirty;
	const hasMedia = chosenTargets.some( ( target ) =>
		Object.values( target.media || {} ).some( ( count ) => count > 0 )
	);

	return (
		<PluginDocumentSettingPanel
			name="windrose-sabir-languages"
			title={ __( 'Languages', 'windrose-sabir' ) }
			className="windrose-sabir-languages"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ targets === null && <Spinner /> }
			{ targets !== null && ! targets.length && ! error && (
				<p>
					{ isNew
						? __(
								'Save the post to copy it to other languages.',
								'windrose-sabir'
							)
						: __(
								'No other site in this site’s language group.',
								'windrose-sabir'
							) }
				</p>
			) }
			{ targets?.map( ( target ) =>
				target.translation ? (
					<p
						key={ target.id }
						className="windrose-sabir-languages__row"
					>
						<strong>{ target.language }</strong>
						{ ': ' }
						{ target.translation.edit ? (
							<a href={ target.translation.edit }>
								{ target.translation.title ||
									__( '(no title)', 'windrose-sabir' ) }
							</a>
						) : (
							// No status: a draft or private post the current user may not read on that site.
							( target.translation.status &&
								target.translation.title ) ||
							( ! target.translation.status &&
								__(
									'A translation you may not read.',
									'windrose-sabir'
								) )
						) }{ ' ' }
						{ target.translation.status && (
							<span className="windrose-sabir-languages__status">
								{ sprintf(
									/* translators: %s: post status (Draft). */
									__( '(%s)', 'windrose-sabir' ),
									target.translation.status
								) }
							</span>
						) }
					</p>
				) : (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ target.id }
						label={ sprintf(
							/* translators: %s: language name (Français). */
							__( 'Copy to %s', 'windrose-sabir' ),
							target.language
						) }
						help={ target.can ? target.name : target.reason }
						checked={ chosen.includes( target.id ) }
						disabled={ ! target.can || busy }
						onChange={ ( checked ) =>
							setChosen(
								checked
									? [ ...chosen, target.id ]
									: chosen.filter(
											( id ) => id !== target.id
										)
							)
						}
					/>
				)
			) }
			{ anchors && hasMedia && (
				<RadioControl
					label={ __( 'Images', 'windrose-sabir' ) }
					selected={ images }
					options={ [
						{
							label: __(
								'Use network items (one file for every language)',
								'windrose-sabir'
							),
							value: 'network',
						},
						{
							label: __(
								'Copy the files to each site',
								'windrose-sabir'
							),
							value: 'copy',
						},
					] }
					onChange={ setImages }
					disabled={ busy }
				/>
			) }
			{ chosenTargets.length > 0 &&
				mediaSummary( chosenTargets, images ).map( ( line ) => (
					<p key={ line } className="windrose-sabir-languages__media">
						{ line }
					</p>
				) ) }
			{ targets?.some( ( target ) => ! target.translation ) && (
				<>
					{ mustSave && ! isNew && (
						<p className="windrose-sabir-languages__hint">
							{ __(
								'Save your changes first: Copy uses the saved version.',
								'windrose-sabir'
							) }
						</p>
					) }
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isBusy={ busy }
						disabled={ busy || mustSave || ! chosen.length }
						accessibleWhenDisabled
						onClick={ copy }
					>
						{ __( 'Copy as drafts', 'windrose-sabir' ) }
					</Button>
				</>
			) }
			<div aria-live="polite">
				{ results.map( ( result ) => (
					<Result
						key={ result.site }
						result={ result }
						targets={ targets ?? [] }
					/>
				) ) }
			</div>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'windrose-sabir-languages', { render: LanguagesPanel } );
