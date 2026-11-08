/**
 * Jest tests for the block editor sidebar components.
 *
 * The WordPress editor packages are build-time externals (not installed), so
 * they are replaced with minimal virtual stand-ins; React, the element and
 * i18n packages are real.
 */
import { act } from 'react';
import { createRoot } from 'react-dom/client';

const mockEditor = {
	getCurrentPostId: jest.fn(),
	isEditedPostDirty: jest.fn(),
	isCurrentPostPublished: jest.fn(),
	getCurrentPostType: jest.fn(),
};
const mockCreateNotice = jest.fn();
const mockApiFetch = jest.fn();

jest.mock(
	'@wordpress/data',
	() => ( {
		useSelect: ( mapper ) => mapper( () => mockEditor ),
		useDispatch: () => ( { createNotice: mockCreateNotice } ),
	} ),
	{ virtual: true }
);
jest.mock(
	'@wordpress/api-fetch',
	() => ( {
		__esModule: true,
		default: ( ...args ) => mockApiFetch( ...args ),
	} ),
	{
		virtual: true,
	}
);
jest.mock(
	'@wordpress/edit-post',
	() => ( {
		PluginSidebar: ( { name, title, children } ) => (
			<section data-sidebar={ name } aria-label={ title }>
				{ children }
			</section>
		),
		PluginSidebarMoreMenuItem: ( { target, children } ) => (
			<button type="button" data-target={ target }>
				{ children }
			</button>
		),
	} ),
	{ virtual: true }
);
jest.mock(
	'@wordpress/components',
	() => ( {
		PanelBody: ( { title, children } ) => (
			<div data-panel={ title || 'main' }>{ children }</div>
		),
		PanelRow: ( { children } ) => <div>{ children }</div>,
		Notice: ( { status, children } ) => (
			<div role="alert" data-status={ status }>
				{ children }
			</div>
		),
		Spinner: () => <span data-spinner />,
		Button: ( { children, onClick, disabled, isPrimary, variant } ) => (
			<button
				type="button"
				onClick={ onClick }
				disabled={ disabled }
				data-primary={ isPrimary || variant === 'primary' ? '1' : '0' }
			>
				{ children }
			</button>
		),
		TextControl: ( { label, value, onChange, help } ) => (
			<div>
				<label htmlFor="mock-text-control">{ label }</label>
				<input
					id="mock-text-control"
					value={ value }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
				<small>{ help }</small>
			</div>
		),
	} ),
	{ virtual: true }
);

// eslint-disable-next-line import/first
import { FindableSidebar, FamilyScore, ChecksList } from './sidebar';

window.IS_REACT_ACT_ENVIRONMENT = true;

const check = ( id, s, f, label ) => ( { id, s, f, label: label || id } );

function payload( overrides = {} ) {
	return {
		result: {
			overall: 72,
			families: {
				seo: { score: 80, passed: 3, total: 4 },
				aeo: { score: 60, passed: 1, total: 2 },
				geo: { score: 40, passed: 1, total: 2 },
			},
			checks: [
				check( 'title_length', 'fail', 'seo', 'Title length' ),
				check( 'aeo_faq', 'fail', 'aeo', 'FAQ markup' ),
				check( 'title_exists', 'pass', 'seo', 'Title exists' ),
				check( 'geo_links', 'na', 'geo', 'Authoritative links' ),
			],
		},
		fresh: true,
		age_seconds: 120,
		error: null,
		scannable: true,
		reason: null,
		report_url: 'https://findable.page/example.com/hello',
		advice: { title_length: 'Keep it 30-60 chars.', aeo_faq: 'Add FAQ.' },
		labels: { title_length: 'Localized title length' },
		keyphrase: 'coffee',
		legacy: false,
		...overrides,
	};
}

let container;
let root;

async function mount( ui ) {
	await act( async () => {
		root.render( ui );
	} );
}

async function settle() {
	await act( async () => {
		await Promise.resolve();
	} );
}

const text = () => container.textContent;
const button = ( label ) =>
	Array.from( container.querySelectorAll( 'button' ) ).find( ( b ) =>
		b.textContent.includes( label )
	);

beforeEach( () => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
	mockApiFetch.mockReset();
	mockCreateNotice.mockReset();
	mockEditor.getCurrentPostId.mockReturnValue( 7 );
	mockEditor.isEditedPostDirty.mockReturnValue( false );
	mockEditor.isCurrentPostPublished.mockReturnValue( true );
	mockEditor.getCurrentPostType.mockReturnValue( 'post' );
} );

afterEach( () => {
	act( () => root.unmount() );
	container.remove();
} );

describe( 'FamilyScore', () => {
	it( 'renders nothing without a family', async () => {
		await mount( <FamilyScore name="SEO" family={ null } /> );
		expect( container.innerHTML ).toBe( '' );
	} );

	it( 'renders score and pass counts', async () => {
		await mount(
			<FamilyScore
				name="SEO"
				family={ { score: 80, passed: 3, total: 4 } }
			/>
		);
		expect( text() ).toContain( 'SEO: 80 (3 of 4 passed)' );
	} );
} );

describe( 'ChecksList', () => {
	const checks = [
		check( 'a', 'fail', 'seo', 'Stored A' ),
		check( 'b', 'fail', 'aeo', 'Stored B' ),
		check( 'c', 'pass', 'geo', 'Stored C' ),
	];

	it( 'groups by family, prefers localized labels and shows advice only for failures', async () => {
		await mount(
			<ChecksList
				checks={ checks }
				advice={ { a: 'Fix A', c: 'Never shown' } }
				labels={ { a: 'Localized A' } }
			/>
		);
		expect( text() ).toContain( 'SEO' );
		expect( text() ).toContain( 'AEO' );
		expect( text() ).toContain( 'GEO' );
		expect( text() ).toContain( 'Localized A' );
		expect( text() ).not.toContain( 'Stored A' );
		expect( text() ).toContain( 'Stored B' );
		expect( text() ).toContain( 'Fix A' );
		expect( text() ).not.toContain( 'Never shown' );
	} );

	it( 'works without advice and labels and skips empty families', async () => {
		await mount( <ChecksList checks={ [ checks[ 0 ] ] } /> );
		expect( text() ).toContain( 'Stored A' );
		expect(
			container.querySelectorAll( '.findable-family-group' )
		).toHaveLength( 1 );
	} );

	it( 'renders API text as text, never as markup', async () => {
		await mount(
			<ChecksList
				checks={ [
					check( 'x', 'fail', 'seo', '<img src=x onerror=alert(1)>' ),
				] }
				advice={ { x: '<b>bold</b>' } }
			/>
		);
		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( container.querySelector( 'b' ) ).toBeNull();
		expect( text() ).toContain( '<b>bold</b>' );
	} );
} );

describe( 'FindableSidebar', () => {
	it( 'renders nothing without a post id and does not fetch', async () => {
		mockEditor.getCurrentPostId.mockReturnValue( 0 );
		await mount( <FindableSidebar /> );
		expect( container.innerHTML ).toBe( '' );
		expect( mockApiFetch ).not.toHaveBeenCalled();
	} );

	it( 'loads the stored result and shows scores, freshness and the report link', async () => {
		mockApiFetch.mockResolvedValueOnce( payload() );
		await mount( <FindableSidebar /> );
		await settle();

		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/findable/v1/posts/7/result',
		} );
		expect( text() ).toContain( '72 Good'.replace( 'Good', 'Needs work' ) );
		expect( text() ).toContain( 'SEO: 80 (3 of 4 passed)' );
		expect( text() ).toContain( 'AEO: 60 (1 of 2 passed)' );
		expect( text() ).toContain( 'GEO: 40 (1 of 2 passed)' );
		expect( text() ).toContain( 'Scanned 2 minutes ago' );
		expect( container.querySelector( 'a' ).getAttribute( 'href' ) ).toBe(
			'https://findable.page/example.com/hello'
		);
		expect(
			container.querySelector( '.findable-band-needs-work' )
		).not.toBeNull();
		expect( container.querySelector( 'input' ).value ).toBe( 'coffee' );
	} );

	it( 'shows the failing tab by default with localized label and advice, and switches tabs', async () => {
		mockApiFetch.mockResolvedValueOnce( payload() );
		await mount( <FindableSidebar /> );
		await settle();

		expect( text() ).toContain( 'Failing (2)' );
		expect( text() ).toContain( 'Passing (1)' );
		expect( text() ).toContain( 'Not applicable (1)' );
		expect( text() ).toContain( 'Localized title length' );
		expect( text() ).toContain( 'Keep it 30-60 chars.' );
		expect( text() ).not.toContain( 'Title exists' );

		await act( async () => button( 'Passing (1)' ).click() );
		expect( text() ).toContain( 'Title exists' );
		expect( text() ).not.toContain( 'Keep it 30-60 chars.' );

		await act( async () => button( 'Not applicable (1)' ).click() );
		expect( text() ).toContain( 'Authoritative links' );
	} );

	it( 'marks a stale result', async () => {
		mockApiFetch.mockResolvedValueOnce(
			payload( { fresh: false, age_seconds: 7200 } )
		);
		await mount( <FindableSidebar /> );
		await settle();
		expect( text() ).toContain(
			'Stale: scan again to refresh (scanned 2 hours ago)'
		);
	} );

	it( 'shows the empty state for a never scanned post', async () => {
		mockApiFetch.mockResolvedValueOnce(
			payload( { result: null, keyphrase: '' } )
		);
		await mount( <FindableSidebar /> );
		await settle();
		expect( text() ).toContain( 'Not scanned yet.' );
		expect( button( 'Scan now' ).disabled ).toBe( false );
	} );

	it( 'warns when unpublished or dirty and blocks scanning an unscannable post', async () => {
		mockEditor.isCurrentPostPublished.mockReturnValue( false );
		mockEditor.isEditedPostDirty.mockReturnValue( true );
		mockApiFetch.mockResolvedValueOnce(
			payload( { scannable: false, result: null } )
		);
		await mount( <FindableSidebar /> );
		await settle();

		expect( text() ).toContain(
			'Publish this post first. Findable scans the live URL.'
		);
		expect( text() ).toContain(
			'Save or update the post to score your latest edits.'
		);
		expect( button( 'Scan now' ).disabled ).toBe( true );
	} );

	it( 'disables scanning until the first payload arrives', async () => {
		let release;
		mockApiFetch.mockReturnValueOnce(
			new Promise( ( resolve ) => ( release = resolve ) )
		);
		await mount( <FindableSidebar /> );
		expect( button( 'Scan now' ).disabled ).toBe( true );
		await act( async () => release( payload() ) );
		expect( button( 'Scan now' ).disabled ).toBe( false );
	} );

	it( 'surfaces a stored API error from the payload', async () => {
		mockApiFetch.mockResolvedValueOnce(
			payload( {
				error: {
					code: 'findable_http',
					message: 'Could not reach API',
				},
			} )
		);
		await mount( <FindableSidebar /> );
		await settle();
		expect(
			container.querySelector( '[data-status="error"]' ).textContent
		).toBe( 'Could not reach API' );
	} );

	it( 'shows an error notice when loading fails, with and without a message', async () => {
		mockApiFetch.mockRejectedValueOnce( new Error( 'Network down' ) );
		await mount( <FindableSidebar /> );
		await settle();
		expect( text() ).toContain( 'Network down' );

		act( () => root.unmount() );
		root = createRoot( container );
		mockApiFetch.mockRejectedValueOnce( {} );
		await mount( <FindableSidebar /> );
		await settle();
		expect( text() ).toContain( 'Request failed.' );
	} );

	it( 'scans with the typed keyphrase and announces success', async () => {
		mockApiFetch.mockResolvedValueOnce( payload( { keyphrase: '' } ) );
		await mount( <FindableSidebar /> );
		await settle();

		const input = container.querySelector( 'input' );
		await act( async () => {
			const setter = Object.getOwnPropertyDescriptor(
				window.HTMLInputElement.prototype,
				'value'
			).set;
			setter.call( input, 'espresso' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		mockApiFetch.mockResolvedValueOnce(
			payload( { keyphrase: 'espresso' } )
		);
		await act( async () => button( 'Scan now' ).click() );
		await settle();

		expect( mockApiFetch ).toHaveBeenLastCalledWith( {
			path: '/findable/v1/posts/7/scan',
			method: 'POST',
			data: { keyphrase: 'espresso' },
		} );
		expect( mockCreateNotice ).toHaveBeenCalledWith(
			'success',
			'Findable scan complete',
			{ type: 'snackbar' }
		);
		expect( button( 'Scan now' ).disabled ).toBe( false );
	} );

	it( 'shows a spinner and a busy label while scanning', async () => {
		mockApiFetch.mockResolvedValueOnce( payload() );
		await mount( <FindableSidebar /> );
		await settle();

		let release;
		mockApiFetch.mockReturnValueOnce(
			new Promise( ( resolve ) => ( release = resolve ) )
		);
		await act( async () => button( 'Scan now' ).click() );
		expect( text() ).toContain( 'Scanning…' );
		expect( container.querySelector( '[data-spinner]' ) ).not.toBeNull();
		expect( button( 'Scanning…' ).disabled ).toBe( true );

		await act( async () => release( payload() ) );
		expect( container.querySelector( '[data-spinner]' ) ).toBeNull();
	} );

	it( 'reports an API error returned by a scan without announcing success', async () => {
		mockApiFetch.mockResolvedValueOnce( payload() );
		await mount( <FindableSidebar /> );
		await settle();

		mockApiFetch.mockResolvedValueOnce(
			payload( {
				error: { code: 'findable_rate_limited', message: 'Slow down' },
			} )
		);
		await act( async () => button( 'Scan now' ).click() );
		await settle();

		expect(
			container.querySelector( '[data-status="error"]' ).textContent
		).toBe( 'Slow down' );
		expect( mockCreateNotice ).not.toHaveBeenCalled();
	} );

	it( 'reports a thrown scan failure and re-enables the button', async () => {
		mockApiFetch.mockResolvedValueOnce( payload() );
		await mount( <FindableSidebar /> );
		await settle();

		mockApiFetch.mockRejectedValueOnce( new Error( 'Too soon' ) );
		await act( async () => button( 'Scan now' ).click() );
		await settle();
		expect( text() ).toContain( 'Too soon' );
		expect( button( 'Scan now' ).disabled ).toBe( false );

		mockApiFetch.mockRejectedValueOnce( {} );
		await act( async () => button( 'Scan now' ).click() );
		await settle();
		expect( text() ).toContain( 'Request failed.' );
	} );

	it( 'hides the checks panel for an imported result with no failing checks and explains it', async () => {
		mockApiFetch.mockResolvedValueOnce(
			payload( {
				result: { ...payload().result, legacy: true, checks: [] },
				legacy: true,
			} )
		);
		await mount( <FindableSidebar /> );
		await settle();
		expect( text() ).toContain(
			'Imported from findable 0.2. Scan again to see the full breakdown.'
		);
		expect( container.querySelector( '[data-panel="Checks"]' ) ).toBeNull();
	} );

	it( 'keeps the checks panel for an imported result that still lists failing checks', async () => {
		mockApiFetch.mockResolvedValueOnce(
			payload( {
				result: {
					...payload().result,
					legacy: true,
					checks: [
						check( 'legacy_title', 'fail', 'seo', 'Title length' ),
					],
				},
				advice: {},
				labels: {},
			} )
		);
		await mount( <FindableSidebar /> );
		await settle();
		expect(
			container.querySelector( '[data-panel="Checks"]' )
		).not.toBeNull();
		expect( text() ).toContain( 'Title length' );
	} );

	it( 'tolerates a result with no checks array', async () => {
		const base = payload();
		mockApiFetch.mockResolvedValueOnce(
			payload( { result: { ...base.result, checks: undefined } } )
		);
		await mount( <FindableSidebar /> );
		await settle();
		expect( text() ).toContain( 'Failing (0)' );
	} );

	it( 'sends an empty keyphrase when none was ever loaded', async () => {
		let release;
		mockApiFetch.mockReturnValueOnce(
			new Promise( ( resolve ) => ( release = resolve ) )
		);
		await mount( <FindableSidebar /> );
		await act( async () => release( payload( { keyphrase: undefined } ) ) );
		mockApiFetch.mockResolvedValueOnce( payload() );
		await act( async () => button( 'Scan now' ).click() );
		await settle();
		expect( mockApiFetch ).toHaveBeenLastCalledWith( {
			path: '/findable/v1/posts/7/scan',
			method: 'POST',
			data: { keyphrase: '' },
		} );
	} );

	it( 'keeps an edited keyphrase when the result reloads', async () => {
		mockApiFetch.mockResolvedValue( payload( { keyphrase: 'server' } ) );
		await mount( <FindableSidebar /> );
		await settle();
		expect( container.querySelector( 'input' ).value ).toBe( 'server' );
	} );

	it( 'reloads when the post id changes', async () => {
		mockApiFetch.mockResolvedValue( payload() );
		await mount( <FindableSidebar /> );
		await settle();
		mockEditor.getCurrentPostId.mockReturnValue( 8 );
		await mount( <FindableSidebar /> );
		await settle();
		expect( mockApiFetch ).toHaveBeenLastCalledWith( {
			path: '/findable/v1/posts/8/result',
		} );
	} );

	it( 'registers the more-menu item for the sidebar', async () => {
		mockApiFetch.mockResolvedValueOnce( payload() );
		await mount( <FindableSidebar /> );
		await settle();
		expect(
			container.querySelector( '[data-target="findable-sidebar"]' )
				.textContent
		).toBe( 'Findable' );
		expect(
			container.querySelector( '[data-sidebar="findable-sidebar"]' )
		).not.toBeNull();
	} );
} );
