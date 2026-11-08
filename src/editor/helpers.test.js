/**
 * Jest unit tests for the pure sidebar helpers.
 */
import {
	band,
	bandLabel,
	filterChecks,
	counts,
	byFamily,
	formatAge,
} from './helpers';

describe( 'band', () => {
	it( 'maps boundaries', () => {
		expect( band( 0 ) ).toBe( 'poor' );
		expect( band( 49 ) ).toBe( 'poor' );
		expect( band( 50 ) ).toBe( 'needs_work' );
		expect( band( 79 ) ).toBe( 'needs_work' );
		expect( band( 80 ) ).toBe( 'good' );
		expect( band( 100 ) ).toBe( 'good' );
	} );

	it( 'labels boundaries', () => {
		expect( bandLabel( 49 ) ).toBe( 'Poor' );
		expect( bandLabel( 50 ) ).toBe( 'Needs work' );
		expect( bandLabel( 79 ) ).toBe( 'Needs work' );
		expect( bandLabel( 80 ) ).toBe( 'Good' );
	} );
} );

describe( 'filterChecks', () => {
	const checks = [
		{ id: 'a', f: 'seo', label: 'A', s: 'fail' },
		{ id: 'b', f: 'seo', label: 'B', s: 'pass' },
		{ id: 'c', f: 'geo', label: 'C', s: 'na' },
		{ id: 'd', f: 'aeo', label: 'D', s: 'fail' },
	];

	it( 'filters failing', () => {
		expect(
			filterChecks( checks, 'failing' ).map( ( c ) => c.id )
		).toEqual( [ 'a', 'd' ] );
	} );

	it( 'filters passing', () => {
		expect(
			filterChecks( checks, 'passing' ).map( ( c ) => c.id )
		).toEqual( [ 'b' ] );
	} );

	it( 'filters not applicable', () => {
		expect( filterChecks( checks, 'na' ).map( ( c ) => c.id ) ).toEqual( [
			'c',
		] );
	} );

	it( 'defaults to all and tolerates garbage', () => {
		expect( filterChecks( checks, 'nope' ) ).toHaveLength( 4 );
		expect( filterChecks( null, 'failing' ) ).toEqual( [] );
	} );
} );

describe( 'counts', () => {
	it( 'counts each status', () => {
		expect(
			counts( [
				{ s: 'fail' },
				{ s: 'pass' },
				{ s: 'pass' },
				{ s: 'na' },
			] )
		).toEqual( {
			failing: 1,
			passing: 2,
			na: 1,
		} );
	} );
} );

describe( 'byFamily', () => {
	it( 'groups into seo/aeo/geo and drops unknown families', () => {
		const groups = byFamily( [
			{ id: 'a', f: 'seo' },
			{ id: 'b', f: 'geo' },
			{ id: 'c', f: 'other' },
		] );
		expect( groups.seo ).toHaveLength( 1 );
		expect( groups.geo ).toHaveLength( 1 );
		expect( groups.aeo ).toHaveLength( 0 );
	} );
} );

describe( 'formatAge', () => {
	it( 'formats common ages', () => {
		expect( formatAge( 30 ) ).toBe( 'just now' );
		expect( formatAge( 60 ) ).toBe( '1 minute ago' );
		expect( formatAge( 180 ) ).toBe( '3 minutes ago' );
		expect( formatAge( 7200 ) ).toBe( '2 hours ago' );
		expect( formatAge( 172800 ) ).toBe( '2 days ago' );
	} );
} );

describe( 'byFamily with no checks', () => {
	it( 'returns empty groups for null', () => {
		expect( byFamily( null ) ).toEqual( { seo: [], aeo: [], geo: [] } );
	} );
} );
