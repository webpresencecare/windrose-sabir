/**
 * Windrose Sabir network admin, Modules: one switch per module, and the language display of Multisite Language
 * Switcher's own switcher.
 */
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	RadioControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { useNotice } from './notice';

const data = window.windroseSabirNetwork || {};
const ROOT = data.root || '/windrose-sabir/v1';

const MODULES = [
	{
		id: 'copy',
		label: __( 'Copy', 'windrose-sabir' ),
		help: __(
			'Copy a post to another site of its language group as a linked draft: the Languages panel in the editor and bulk actions in the post lists.',
			'windrose-sabir'
		),
	},
	{
		id: 'site_editor',
		label: __( 'Site Editor', 'windrose-sabir' ),
		help: __(
			'The Language item block for the Navigation, the language display below, and Site Health checks of the header. When switched off, language items already placed show nothing.',
			'windrose-sabir'
		),
	},
	{
		id: 'logbook',
		label: __( 'Logbook', 'windrose-sabir' ),
		help: __(
			'Export the languages and translations of the network (JSON and CSV), also with WP-CLI.',
			'windrose-sabir'
		),
	},
];

export default function ModulesScreen() {
	const [ settings, setSettings ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, notify ] = useNotice();

	useEffect( () => {
		apiFetch( { path: `${ ROOT }/settings` } )
			.then( setSettings )
			.catch( ( error ) => notify( 'error', error.message ) );
	}, [ notify ] );

	const save = async () => {
		setBusy( true );
		try {
			setSettings(
				await apiFetch( {
					path: `${ ROOT }/settings`,
					method: 'POST',
					data: settings,
				} )
			);
			notify( 'success', __( 'Settings saved.', 'windrose-sabir' ) );
		} catch ( error ) {
			notify( 'error', error.message );
		}
		setBusy( false );
	};

	if ( ! settings ) {
		return (
			<>
				{ notice }
				<Spinner />
			</>
		);
	}

	return (
		<>
			{ notice }
			<Card className="windrose-sabir__card">
				<CardHeader>
					<h2 className="windrose-sabir__card-title">
						{ __( 'Modules', 'windrose-sabir' ) }
					</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Language groups are always on. Each other module can be switched off for the whole network.',
							'windrose-sabir'
						) }
					</p>
					{ MODULES.map( ( module ) => (
						<ToggleControl
							__nextHasNoMarginBottom
							key={ module.id }
							label={ module.label }
							help={ module.help }
							checked={ !! settings.modules[ module.id ] }
							onChange={ ( checked ) =>
								setSettings( {
									...settings,
									modules: {
										...settings.modules,
										[ module.id ]: checked,
									},
								} )
							}
						/>
					) ) }
				</CardBody>
			</Card>
			<Card className="windrose-sabir__card">
				<CardHeader>
					<h2 className="windrose-sabir__card-title">
						{ __( 'Language display', 'windrose-sabir' ) }
					</h2>
				</CardHeader>
				<CardBody>
					<RadioControl
						label={ __(
							'Languages in Multisite Language Switcher’s switcher and in the toolbar',
							'windrose-sabir'
						) }
						help={ __(
							'Applies to the switcher, widget and shortcode of Multisite Language Switcher, and to the language shown after site names in the toolbar, on every site, once the network has a language group, and with the Site Editor module on. Flags stand for countries, not languages: names are clearer for visitors. The Language item block has its own setting.',
							'windrose-sabir'
						) }
						selected={ settings.display }
						options={ [
							{
								label: __(
									'Language names (no flags)',
									'windrose-sabir'
								),
								value: 'names',
							},
							{
								label: __(
									'Language codes (no flags)',
									'windrose-sabir'
								),
								value: 'codes',
							},
							{
								label: __(
									'As set on each site (flags allowed)',
									'windrose-sabir'
								),
								value: 'flags',
							},
						] }
						onChange={ ( display ) =>
							setSettings( { ...settings, display } )
						}
					/>
				</CardBody>
			</Card>
			<Button
				__next40pxDefaultSize
				variant="primary"
				isBusy={ busy }
				disabled={ busy }
				accessibleWhenDisabled
				onClick={ save }
			>
				{ __( 'Save', 'windrose-sabir' ) }
			</Button>
		</>
	);
}
