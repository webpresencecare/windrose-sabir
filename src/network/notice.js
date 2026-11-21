/**
 * Windrose Sabir network admin: a notice line that screen readers also hear, shared by the screens.
 */
import { useCallback, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { speak } from '@wordpress/a11y';

/**
 * A notice line that screen readers also hear.
 *
 * @return {Array} [ notice element, notify( status, message ) ].
 */
export function useNotice() {
	const [ notice, setNotice ] = useState( null );
	const notify = useCallback( ( status, message ) => {
		setNotice( message ? { status, message } : null );
		if ( message ) {
			speak( message, 'error' === status ? 'assertive' : 'polite' );
		}
	}, [] );
	const element = notice ? (
		<Notice
			status={ notice.status }
			onRemove={ () => setNotice( null ) }
			className="windrose-sabir__notice"
		>
			{ notice.message }
		</Notice>
	) : null;
	return [ element, notify ];
}
