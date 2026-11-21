/**
 * The language item block in the editor (the front end is rendered by PHP, Language_Item). The preview lists the
 * languages of the site's group without links; core's Navigation block accepts it as a child.
 */
import { registerBlockType } from '@wordpress/blocks';
import { addFilter } from '@wordpress/hooks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { language } from '@wordpress/icons';

import metadata from '../../blocks/language-item/block.json';
import './editor.scss';

const NAME = metadata.name;

// Core's Navigation block takes the language item as a child (the server adds it to its allowed blocks too).
addFilter(
	'blocks.registerBlockType',
	'windrose-sabir/navigation-language-item',
	( settings, name ) => {
		if ( 'core/navigation' !== name ) {
			return settings;
		}
		const allowed = settings.allowedBlocks || [];
		return allowed.includes( NAME )
			? settings
			: { ...settings, allowedBlocks: [ ...allowed, NAME ] };
	}
);

/**
 * The text of one language, as the block shows it.
 *
 * @param {Object} item    Language.
 * @param {string} display name, code or label.
 * @return {string} Text.
 */
function text( item, display ) {
	if ( 'code' === display ) {
		return item.code;
	}
	if ( 'label' === display ) {
		return item.label || item.name;
	}
	return item.name;
}

function Edit( { attributes, setAttributes, context } ) {
	const { display, showCurrent, hideMissing } = attributes;
	const inNavigation = context?.maxNestingLevel !== undefined;
	const items = ( window.windroseSabirLanguages || [] ).filter(
		( item ) => showCurrent || ! item.current
	);
	const blockProps = useBlockProps( {
		className: inNavigation
			? 'wp-block-navigation-item windrose-language-item-editor'
			: 'windrose-language-item windrose-language-item-editor',
	} );

	const controls = (
		<InspectorControls>
			<PanelBody title={ __( 'Settings', 'windrose-sabir' ) }>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Show', 'windrose-sabir' ) }
					value={ display }
					options={ [
						{
							label: __(
								'Language names, in their own language',
								'windrose-sabir'
							),
							value: 'name',
						},
						{
							label: __( 'Language codes', 'windrose-sabir' ),
							value: 'code',
						},
						{
							label: __(
								'Labels from Multisite Language Switcher',
								'windrose-sabir'
							),
							value: 'label',
						},
					] }
					onChange={ ( value ) =>
						setAttributes( { display: value } )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Show the current language',
						'windrose-sabir'
					) }
					checked={ showCurrent }
					onChange={ ( value ) =>
						setAttributes( { showCurrent: value } )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Hide languages without a translation of the page',
						'windrose-sabir'
					) }
					help={ __(
						'Otherwise those languages link to their site’s home page.',
						'windrose-sabir'
					) }
					checked={ hideMissing }
					onChange={ ( value ) =>
						setAttributes( { hideMissing: value } )
					}
				/>
			</PanelBody>
		</InspectorControls>
	);

	const empty = __(
		'Language item: this site is in no language group, so it shows nothing.',
		'windrose-sabir'
	);

	if ( inNavigation ) {
		return (
			<>
				{ controls }
				<li { ...blockProps }>
					{ items.length
						? items.map( ( item ) => (
								<span
									key={ item.locale }
									className="wp-block-navigation-item__content"
									lang={ item.tag }
									aria-current={
										item.current ? 'page' : undefined
									}
								>
									{ text( item, display ) }
								</span>
							) )
						: empty }
				</li>
			</>
		);
	}
	return (
		<>
			{ controls }
			<ul { ...blockProps }>
				{ items.length ? (
					items.map( ( item ) => (
						<li
							key={ item.locale }
							className="windrose-language-item__item"
							lang={ item.tag }
						>
							{ text( item, display ) }
						</li>
					) )
				) : (
					<li>{ empty }</li>
				) }
			</ul>
		</>
	);
}

registerBlockType( metadata, {
	icon: language,
	edit: Edit,
	save: () => null,
} );
