( function ( window, namespace ) {
	'use strict';

	if ( window[ namespace ] ) {
		return;
	}

	const state = new Map();
	const listeners = new Set();

	/**
	 * Normalises an inbound `granted` value to the contract's tristate.
	 *
	 * Anything that is not the literal `true` or `false` collapses to `null`
	 * (absent). The asymmetry is intentional — see docs/consent.md.
	 *
	 * @param {unknown} value Raw value from the dispatched event.
	 * @return {boolean|null} Normalised tristate.
	 */
	function normalise( value ) {
		if ( value === true ) {
			return true;
		}
		if ( value === false ) {
			return false;
		}
		return null;
	}

	window[ namespace ] = {
		getConsent( category ) {
			return state.has( category ) ? state.get( category ) : null;
		},

		mayProceed( category ) {
			return state.get( category ) !== false;
		},

		onConsentChanged( handler ) {
			listeners.add( handler );
			return () => listeners.delete( handler );
		},

		_setConsent( category, value ) {
			const previous = state.has( category )
				? state.get( category )
				: null;
			const normalised = normalise( value );
			if ( normalised === null ) {
				state.delete( category );
			} else {
				state.set( category, normalised );
			}
			if ( previous !== normalised ) {
				for ( const listener of listeners ) {
					try {
						listener( category, normalised );
						// eslint-disable-next-line no-unused-vars
					} catch ( error ) {
						// Listener errors must not break the dispatcher loop.
					}
				}
			}
		},
	};

	window.addEventListener( namespace + ':consent', ( event ) => {
		if ( ! event || ! event.detail ) {
			return;
		}
		window[ namespace ]._setConsent(
			event.detail.category,
			event.detail.granted
		);
	} );
} )( window, 'kntnt_gpx_blocks' );
