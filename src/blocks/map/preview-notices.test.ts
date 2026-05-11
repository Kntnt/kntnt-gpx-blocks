/**
 * Jest tests for the GPX Map preview's Notice-detection helper.
 *
 * Pure-function tests covering the four combinations of
 * unknown-id-or-not × missing-key-or-not, plus the two edge cases the
 * helper documents (empty saved id; missing fallback provider).
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
};
const renamedOsm: PreviewNoticeProvider = {
	label: 'Open Maps (renamed)',
	requiresKey: false,
};
const paid: PreviewNoticeProvider = {
	label: 'Mapbox Streets',
	requiresKey: true,
};

describe( 'detectPreviewNotices', () => {
	it( 'returns no notices when the saved id is in the registry and the provider needs no key', () => {
		const flags = detectPreviewNotices(
			FALLBACK_ID,
			'',
			{ [ FALLBACK_ID ]: osm },
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBeNull();
		expect( flags.missingKey ).toBe( false );
	} );

	it( 'returns no notices when the saved id is in the registry and a key is supplied', () => {
		const flags = detectPreviewNotices(
			'mapbox-streets',
			'pk.eyJ.fake-token',
			{
				[ FALLBACK_ID ]: osm,
				'mapbox-streets': paid,
			},
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBeNull();
		expect( flags.missingKey ).toBe( false );
	} );

	it( 'flags an unknown provider id and surfaces the registry label for the fallback', () => {
		const flags = detectPreviewNotices(
			'thunderforest-outdoors',
			'',
			{ [ FALLBACK_ID ]: osm },
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBe( 'OpenStreetMap' );
		expect( flags.missingKey ).toBe( false );
	} );

	it( 'uses the registry label even when OSM has been renamed, never a hardcoded literal', () => {
		const flags = detectPreviewNotices(
			'thunderforest-outdoors',
			'',
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
			'',
			{},
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBe( 'OpenStreetMap' );
	} );

	it( 'flags a missing key when the resolved provider requires one and the key is empty', () => {
		const flags = detectPreviewNotices(
			'mapbox-streets',
			'',
			{
				[ FALLBACK_ID ]: osm,
				'mapbox-streets': paid,
			},
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBeNull();
		expect( flags.missingKey ).toBe( true );
	} );

	it( 'flags both notices simultaneously when the id is unknown AND the fallback (which happens to require a key) has none', () => {
		const flags = detectPreviewNotices(
			'thunderforest-outdoors',
			'',
			{ [ FALLBACK_ID ]: paid },
			FALLBACK_ID
		);
		expect( flags.unknownProviderFallbackLabel ).toBe( 'Mapbox Streets' );
		expect( flags.missingKey ).toBe( true );
	} );

	it( 'does not flag missing-key when the resolved provider does not require one, even if the key is empty', () => {
		const flags = detectPreviewNotices(
			FALLBACK_ID,
			'',
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
		// fallback in this fixture happens to *not* require a key, so even
		// though the saved id might have referred to a paid provider in a
		// past life, the missing-key flag tracks the effective provider.
		const flags = detectPreviewNotices(
			'thunderforest-outdoors',
			'',
			{ [ FALLBACK_ID ]: osm },
			FALLBACK_ID
		);
		expect( flags.missingKey ).toBe( false );
	} );

	it( 'does not flag missing-key when the resolved provider has its apiKey managed externally (issue #113)', () => {
		// PHP path engaged: the provider's apiKey is supplied by a
		// site-builder PHP filter callback, not by the per-block
		// attribute. The editor's API-key TextControl is hidden, and
		// the missing-key Notice must not fire — any misconfiguration
		// surfaces in `Plugin::warning()` logs, not in the editor UI.
		const externalPaid: PreviewNoticeProvider = {
			label: 'Thunderforest (PHP key)',
			requiresKey: true,
			apiKeyManagedExternally: true,
		};
		const flags = detectPreviewNotices(
			'thunderforest-external',
			'',
			{
				[ FALLBACK_ID ]: osm,
				'thunderforest-external': externalPaid,
			},
			FALLBACK_ID
		);
		expect( flags.missingKey ).toBe( false );
	} );
} );
