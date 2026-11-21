/**
 * Windrose Sabir network admin screens: Language groups, Translation links, Logbook, Modules.
 */
import { createRoot } from '@wordpress/element';

import GroupsScreen from './groups';
import LinksScreen from './links';
import LogbookScreen from './logbook';
import ModulesScreen from './modules';
import './network.scss';

const SCREENS = {
	groups: GroupsScreen,
	links: LinksScreen,
	logbook: LogbookScreen,
	modules: ModulesScreen,
};

const container = document.getElementById( 'windrose-sabir-app' );
if ( container ) {
	const Screen =
		SCREENS[ window.windroseSabirNetwork?.screen ] || GroupsScreen;
	createRoot( container ).render( <Screen /> );
}
