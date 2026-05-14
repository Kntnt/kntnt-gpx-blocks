/**
 * Jest tests for the GPX Map preview's Notice-detection helper.
 *
 * Pure-function tests covering the four combinations of
 * unknown-id-or-not × missing-key-or-not, plus the documented edge
 * cases (empty saved id; missing fallback provider; PHP-engaged
 * provider suppression).
 *
 * After issue #149 the helper takes a `(providerId, styleId)` pair and
 * inspects the resolved style URL for a residual `{KEY}` placeholder
 * to compute the missing-key flag — the editor JS no longer holds any
 * API-key value; the server-side `Editor_Data_Enqueuer` pre-substitutes
 * `{KEY}` from whichever layer (PHP or site-wide option) supplied a
 * non-empty value.
 *
 * @since 1.0.0
 */

import {
	detectPreviewNotices,
	type PreviewNoticeProvider,
} from './preview-notices';

const FALLBACK_ID = 'osm-standard';

const osm: PreviewNoticeProvider = {
	label: 'OpenStreetMap',
	requiresKey: false,
	default: 'mapnik',
	styles: {
		mapnik: {
			url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
		},
	},
};
const renamedOsm: PreviewNoticeProvider = {
	label: 'Open Maps (renamed)',
	requiresKey: false,
	default: 'mapnik',
	styles: {
		mapnik: { url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' },
	},
};

// Paid provider whose URL still contains `{KEY}` — the fail-closed
// signal. Used in tests that verify the missing-key Notice fires.
const paidEmpty: PreviewNoticeProvider = {
	label: 'Mapbox Streets',
	requiresKey: true,
	default: 'streets',
	styles: {
		streets: {
			url: 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/{z}/{x}/{y}?access_token={KEY}',
		},
	},
};

// Paid provider whose URL has had `{KEY}` substituted server-side. Used
// in tests that verify the missing-key Notice is suppressed when a
// usable key is available.
const paidSubstituted: PreviewNoticeProvider = {
	label: 'Mapbox Streets',
	requiresKey: true,
	default: 'streets',
	styles: {
		streets: {
			url: 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/{z}/{x}/{y}?access_token=PRESUBSTITUTED',
		},
	},
};

describe( 'detectPreviewNotices', () => {
	it( 'returns no notices when the saved id is in the registry and the provider needs no key', () => {
		const flags = detectPreviewNotices(
			FALLBACK_ID,
			'mapnik',
			{ [ FALLBACK_ID ]: osm },
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBeNull();
		expect( flags.missingKey ).toBe( false );
	} );

	it( 'returns no notices when the saved id is in the registry and the URL is pre-substituted', () => {
		const flags = detectPreviewNotices(
			'mapbox-streets',
			'streets',
			{
				[ FALLBACK_ID ]: osm,
				'mapbox-streets': paidSubstituted,
			},
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBeNull();
		expect( flags.missingKey ).toBe( false );
	} );

	it( 'flags an unknown provider id and surfaces the registry label for the fallback', () => {
		const flags = detectPreviewNotices(
			'thunderforest-outdoors',
			'whatever',
			{ [ FALLBACK_ID ]: osm },
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBe( 'OpenStreetMap' );
		expect( flags.missingKey ).toBe( false );
	} );

	it( 'uses the registry label even when OSM has been renamed, never a hardcoded literal', () => {
		const flags = detectPreviewNotices(
			'thunderforest-outdoors',
			'whatever',
			{ [ FALLBACK_ID ]: renamedOsm },
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBe(
			'Open Maps (renamed)'
		);
	} );

	it( 'falls back to the literal "OpenStreetMap" when the registry has no fallback entry at all', () => {
		const flags = detectPreviewNotices(
			'thunderforest-outdoors',
			'whatever',
			{},
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBe( 'OpenStreetMap' );
	} );

	it( 'flags a missing key when the resolved provider requires one and the URL still contains {KEY}', () => {
		const flags = detectPreviewNotices(
			'mapbox-streets',
			'streets',
			{
				[ FALLBACK_ID ]: osm,
				'mapbox-streets': paidEmpty,
			},
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBeNull();
		expect( flags.missingKey ).toBe( true );
	} );

	it( 'flags both notices simultaneously when the id is unknown AND the fallback (which happens to require a key) has no key', () => {
		const flags = detectPreviewNotices(
			'thunderforest-outdoors',
			'streets',
			{ [ FALLBACK_ID ]: paidEmpty },
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBe( 'Mapbox Streets' );
		expect( flags.missingKey ).toBe( true );
	} );

	it( 'does not flag missing-key when the resolved provider does not require one, even with a {KEY}-looking URL', () => {
		const flags = detectPreviewNotices(
			FALLBACK_ID,
			'mapnik',
			{ [ FALLBACK_ID ]: osm },
			FALLBACK_ID
		);
		expect( flags.missingKey ).toBe( false );
	} );

	it( 'treats an empty saved id as pre-default state, not as an unknown id', () => {
		const flags = detectPreviewNotices(
			'',
			'',
			{ [ FALLBACK_ID ]: osm },
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBeNull();
	} );

	it( 'computes missing-key against the resolved provider, not the stale saved id', () => {
		// Saved id is unknown → resolved provider is the fallback. The
		// fallback in this fixture does not require a key, so even
		// though the saved id might have referred to a paid provider
		// in a past life, the missing-key flag tracks the effective
		// provider.
		const flags = detectPreviewNotices(
			'thunderforest-outdoors',
			'whatever',
			{ [ FALLBACK_ID ]: osm },
			FALLBACK_ID
		);
		expect( flags.missingKey ).toBe( false );
	} );

	it( 'does not flag missing-key when the resolved provider has its apiKey managed externally', () => {
		// PHP path engaged: the provider's apiKey is supplied by a
		// site-builder PHP filter callback. The editor's settings page
		// renders the field disabled and the missing-key Notice must
		// not fire — any misconfiguration surfaces in
		// `Plugin::warning()` logs, not in the editor UI.
		const externalPaid: PreviewNoticeProvider = {
			label: 'Thunderforest (PHP key)',
			requiresKey: true,
			apiKeyManagedExternally: true,
			default: 'outdoor',
			styles: {
				// Even when `{KEY}` remains (PHP key was empty,
				// fail-closed) the Notice still must not fire — the
				// editor stays out of the way by design.
				outdoor: {
					url: 'https://tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey={KEY}',
				},
			},
		};
		const flags = detectPreviewNotices(
			'thunderforest-external',
			'outdoor',
			{
				[ FALLBACK_ID ]: osm,
				'thunderforest-external': externalPaid,
			},
			FALLBACK_ID
		);
		expect( flags.missingKey ).toBe( false );
	} );

	it( 'falls back to the provider default style when the saved style id is unknown', () => {
		const flags = detectPreviewNotices(
			'mapbox-streets',
			'no-such-style',
			{
				[ FALLBACK_ID ]: osm,
				'mapbox-streets': paidEmpty,
			},
			FALLBACK_ID
		);
		// Style fall-back resolves to `streets` whose URL contains
		// `{KEY}`; the missing-key Notice fires against the resolved
		// style, not against the orphan style id.
		expect( flags.missingKey ).toBe( true );
	} );
} );
