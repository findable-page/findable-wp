/**
 * Admin script: connection test, bulk panel, classic meta box scan.
 * The script is an IIFE that binds on load, so every test builds its DOM first
 * and then requires a fresh copy.
 */

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
// Microtask-only flush, usable under fake timers.
const ticks = async () => {
	for ( let i = 0; i < 10; i++ ) {
		await Promise.resolve();
	}
};

function respond( ok, data ) {
	return Promise.resolve( { ok, json: () => Promise.resolve( data ) } );
}

function load( html, admin ) {
	document.body.innerHTML = html;
	if ( admin === undefined ) {
		window.findableAdmin = {
			restUrl: '/wp-json/findable/v1',
			restNonce: 'nonce-1',
			scanUrl: '/wp-admin/admin-post.php',
			i18n: { testing: 'Testing…', error: 'Error', confirm: 'Sure?' },
		};
	} else {
		window.findableAdmin = admin;
	}
	jest.isolateModules( () => require( './admin' ) );
}

const BULK_HTML = `
	<div id="findable-bulk-panel">
		<p id="findable-bulk-status"></p>
		<progress id="findable-bulk-progress"></progress>
		<span id="findable-bulk-eta"></span>
		<button id="findable-bulk-start"></button>
		<button id="findable-bulk-rescan"></button>
		<button id="findable-bulk-pause"></button>
		<button id="findable-bulk-resume"></button>
		<button id="findable-bulk-cancel"></button>
		<button class="findable-bulk-resume" id="notice-resume"></button>
	</div>`;

let hidden;
let docListeners;

beforeEach( () => {
	hidden = false;
	Object.defineProperty( document, 'hidden', {
		configurable: true,
		get: () => hidden,
	} );
	global.fetch = jest.fn();
	window.alert = jest.fn();
	window.confirm = jest.fn();
	jest.spyOn( window, 'setInterval' );
	jest.spyOn( window, 'clearInterval' );
	// Track document listeners so one test's panel cannot react to another's events.
	docListeners = [];
	const add = document.addEventListener.bind( document );
	jest.spyOn( document, 'addEventListener' ).mockImplementation(
		( type, fn ) => {
			docListeners.push( [ type, fn ] );
			add( type, fn );
		}
	);
} );

afterEach( () => {
	docListeners.forEach( ( [ type, fn ] ) =>
		document.removeEventListener( type, fn )
	);
	jest.restoreAllMocks();
	jest.useRealTimers();
	delete window.findableAdmin;
} );

describe( 'nothing to bind', () => {
	it( 'does nothing on pages without findable elements', () => {
		load( '<p>other page</p>' );
		expect( fetch ).not.toHaveBeenCalled();
	} );
} );

describe( 'connection test', () => {
	const HTML =
		'<button id="findable-test-connection"></button><span id="findable-connection-result"></span>';

	it( 'shows the server message and ok class on success', async () => {
		load( HTML );
		fetch.mockReturnValue(
			respond( true, { connected: true, message: 'Connected.' } )
		);
		const button = document.getElementById( 'findable-test-connection' );
		const out = document.getElementById( 'findable-connection-result' );
		button.click();
		expect( button.disabled ).toBe( true );
		expect( out.textContent ).toBe( 'Testing…' );
		await flush();
		expect( fetch ).toHaveBeenCalledWith(
			'/wp-json/findable/v1/connection',
			{
				method: 'POST',
				headers: { 'X-WP-Nonce': 'nonce-1' },
				credentials: 'same-origin',
			}
		);
		expect( out.textContent ).toBe( 'Connected.' );
		expect( out.className ).toBe( 'ok' );
		expect( button.disabled ).toBe( false );
	} );

	it( 'marks fail when not connected and falls back to the generic error', async () => {
		load( HTML );
		fetch.mockReturnValue( respond( true, { connected: false } ) );
		document.getElementById( 'findable-test-connection' ).click();
		await flush();
		const out = document.getElementById( 'findable-connection-result' );
		expect( out.textContent ).toBe( 'Error' );
		expect( out.className ).toBe( 'fail' );
	} );

	it( 'marks fail on an HTTP error', async () => {
		load( HTML );
		fetch.mockReturnValue(
			respond( false, { connected: true, message: 'Bad key' } )
		);
		document.getElementById( 'findable-test-connection' ).click();
		await flush();
		expect(
			document.getElementById( 'findable-connection-result' ).className
		).toBe( 'fail' );
	} );

	it( 'marks fail on a network error and re-enables the button', async () => {
		load( HTML );
		fetch.mockReturnValue( Promise.reject( new Error( 'offline' ) ) );
		const button = document.getElementById( 'findable-test-connection' );
		button.click();
		await flush();
		await flush();
		const out = document.getElementById( 'findable-connection-result' );
		expect( out.textContent ).toBe( 'Error' );
		expect( out.className ).toBe( 'fail' );
		expect( button.disabled ).toBe( false );
	} );
} );

describe( 'bulk panel', () => {
	const running = {
		status: 'running',
		status_text: 'Scanning 1 of 4',
		eta_text: '2 min',
		total: 4,
		done: 1,
		failed: 1,
	};
	const idle = { status: 'idle' };
	const $ = ( id ) => document.getElementById( id );

	it( 'renders a running state and starts polling', async () => {
		fetch.mockReturnValue( respond( true, running ) );
		load( BULK_HTML );
		await flush();
		expect( fetch ).toHaveBeenCalledWith(
			'/wp-json/findable/v1/bulk',
			expect.any( Object )
		);
		expect( $( 'findable-bulk-status' ).textContent ).toBe(
			'Scanning 1 of 4'
		);
		expect( $( 'findable-bulk-eta' ).textContent ).toBe( '2 min' );
		expect( $( 'findable-bulk-progress' ).max ).toBe( 4 );
		expect( $( 'findable-bulk-progress' ).value ).toBe( 2 );
		expect( $( 'findable-bulk-start' ).disabled ).toBe( true );
		expect( $( 'findable-bulk-rescan' ).disabled ).toBe( true );
		expect( $( 'findable-bulk-pause' ).disabled ).toBe( false );
		expect( $( 'findable-bulk-cancel' ).disabled ).toBe( false );
		expect( $( 'findable-bulk-resume' ).disabled ).toBe( true );
		expect(
			$( 'findable-bulk-panel' ).getAttribute( 'data-running' )
		).toBe( '1' );
		expect( window.setInterval ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'renders idle defaults and does not poll', async () => {
		fetch.mockReturnValue( respond( true, idle ) );
		load( BULK_HTML );
		await flush();
		expect( $( 'findable-bulk-status' ).textContent ).toBe( 'Error' );
		expect( $( 'findable-bulk-eta' ).textContent ).toBe( '' );
		expect( $( 'findable-bulk-progress' ).max ).toBe( 1 );
		expect( $( 'findable-bulk-progress' ).value ).toBe( 0 );
		expect( $( 'findable-bulk-start' ).disabled ).toBe( false );
		expect( $( 'findable-bulk-pause' ).disabled ).toBe( true );
		expect(
			$( 'findable-bulk-panel' ).getAttribute( 'data-running' )
		).toBe( '0' );
		expect( window.setInterval ).not.toHaveBeenCalled();
	} );

	it( 'enables resume when paused', async () => {
		fetch.mockReturnValue( respond( true, { status: 'paused' } ) );
		load( BULK_HTML );
		await flush();
		expect( $( 'findable-bulk-resume' ).disabled ).toBe( false );
	} );

	it( 'ignores a failed status request', async () => {
		fetch.mockReturnValue( respond( false, { message: 'nope' } ) );
		load( BULK_HTML );
		await flush();
		expect( $( 'findable-bulk-status' ).textContent ).toBe( '' );
	} );

	it( 'tolerates a minimal panel without optional elements', async () => {
		fetch.mockReturnValue( respond( true, running ) );
		load( '<div id="findable-bulk-panel"></div>' );
		await flush();
		expect(
			$( 'findable-bulk-panel' ).getAttribute( 'data-running' )
		).toBe( '1' );
	} );

	it( 'polls every 10s, skips while hidden, stops when finished', async () => {
		jest.useFakeTimers();
		const setSpy = jest.spyOn( window, 'setInterval' );
		const clearSpy = jest.spyOn( window, 'clearInterval' );
		fetch.mockReturnValue( respond( true, running ) );
		load( BULK_HTML );
		await ticks();
		expect( setSpy ).toHaveBeenCalledTimes( 1 );
		expect( fetch ).toHaveBeenCalledTimes( 1 );

		hidden = true;
		jest.advanceTimersByTime( 10000 );
		expect( fetch ).toHaveBeenCalledTimes( 1 );

		hidden = false;
		fetch.mockReturnValue( respond( true, idle ) );
		jest.advanceTimersByTime( 10000 );
		expect( fetch ).toHaveBeenCalledTimes( 2 );
		await ticks();
		expect( clearSpy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not start polling while the tab is hidden, resumes on visibility', async () => {
		hidden = true;
		fetch.mockReturnValue( respond( true, running ) );
		load( BULK_HTML );
		await flush();
		expect( window.setInterval ).not.toHaveBeenCalled();

		document.dispatchEvent( new Event( 'visibilitychange' ) );
		expect( window.setInterval ).not.toHaveBeenCalled();

		hidden = false;
		document.dispatchEvent( new Event( 'visibilitychange' ) );
		expect( window.setInterval ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'visibility change does not poll an idle run', async () => {
		fetch.mockReturnValue( respond( true, idle ) );
		load( BULK_HTML );
		await flush();
		window.setInterval.mockClear();
		document.dispatchEvent( new Event( 'visibilitychange' ) );
		expect( window.setInterval ).not.toHaveBeenCalled();
	} );

	it( 'start and rescan POST the force flag', async () => {
		fetch.mockReturnValue( respond( true, idle ) );
		load( BULK_HTML );
		await flush();
		$( 'findable-bulk-start' ).click();
		await flush();
		$( 'findable-bulk-rescan' ).click();
		await flush();
		const posts = fetch.mock.calls.filter(
			( c ) => c[ 1 ].method === 'POST'
		);
		expect( posts.map( ( c ) => c[ 1 ].body ) ).toEqual( [
			'{"force":false}',
			'{"force":true}',
		] );
		expect( posts[ 0 ][ 1 ].headers[ 'Content-Type' ] ).toBe(
			'application/json'
		);
		expect( window.alert ).not.toHaveBeenCalled();
	} );

	it( 'alerts the server message, or the generic error, when start fails', async () => {
		fetch.mockReturnValue( respond( true, idle ) );
		load( BULK_HTML );
		await flush();
		fetch.mockReturnValueOnce(
			respond( false, { message: 'Add an API key first.' } )
		);
		$( 'findable-bulk-start' ).click();
		await flush();
		expect( window.alert ).toHaveBeenLastCalledWith(
			'Add an API key first.'
		);

		fetch.mockReturnValueOnce( respond( false, {} ) );
		$( 'findable-bulk-start' ).click();
		await flush();
		expect( window.alert ).toHaveBeenLastCalledWith( 'Error' );
	} );

	it( 'pause, resume (button and notice) and cancel call the bulk endpoint', async () => {
		// Paused: pause, resume and cancel are all enabled (disabled buttons ignore clicks).
		fetch.mockReturnValue( respond( true, { status: 'paused' } ) );
		load( BULK_HTML );
		await flush();
		$( 'findable-bulk-pause' ).click();
		$( 'findable-bulk-resume' ).click();
		$( 'notice-resume' ).click();
		window.confirm.mockReturnValueOnce( false );
		$( 'findable-bulk-cancel' ).click();
		window.confirm.mockReturnValueOnce( true );
		$( 'findable-bulk-cancel' ).click();
		await flush();
		const calls = fetch.mock.calls
			.filter( ( c ) => c[ 1 ].method )
			.map( ( c ) => [ c[ 1 ].method, c[ 1 ].body ] );
		expect( calls ).toEqual( [
			[ 'POST', '{"action":"pause"}' ],
			[ 'POST', '{"action":"resume"}' ],
			[ 'POST', '{"action":"resume"}' ],
			[ 'DELETE', undefined ],
		] );
		expect( window.confirm ).toHaveBeenCalledWith( 'Sure?' );
	} );
} );

describe( 'classic meta box scan', () => {
	const BOX = `
		<div id="findable">
			<input name="findable_scan_nonce" value="n1">
			<input name="findable_post_id" value="42">
			<p><button id="findable_scan"></button><span class="findable-scan-spinner"></span></p>
		</div>
		<input id="findable-keyphrase" value="blue widgets">`;

	beforeEach( () => {
		jest.spyOn(
			window.HTMLFormElement.prototype,
			'submit'
		).mockImplementation( () => {} );
	} );

	it( 'submits a hidden form with nonce, post id and keyphrase', () => {
		load( BOX );
		const button = document.getElementById( 'findable_scan' );
		button.click();
		const form = document.body.querySelector( 'form' );
		expect( form.action ).toContain( '/wp-admin/admin-post.php' );
		expect( form.method ).toBe( 'post' );
		const data = Object.fromEntries( new FormData( form ) );
		expect( data ).toEqual( {
			action: 'findable_scan',
			findable_scan_nonce: 'n1',
			post: '42',
			findable_keyphrase: 'blue widgets',
		} );
		expect( button.disabled ).toBe( true );
		expect(
			document
				.querySelector( '.findable-scan-spinner' )
				.classList.contains( 'is-active' )
		).toBe( true );
		expect( window.HTMLFormElement.prototype.submit ).toHaveBeenCalledTimes(
			1
		);
	} );

	it( 'sends an empty keyphrase and copes without a spinner', () => {
		load(
			`<div id="findable"><input name="findable_scan_nonce" value="n1"><input name="findable_post_id" value="42"><button id="findable_scan"></button></div>`
		);
		document.getElementById( 'findable_scan' ).click();
		const data = Object.fromEntries(
			new FormData( document.body.querySelector( 'form' ) )
		);
		expect( data.findable_keyphrase ).toBe( '' );
	} );

	it( 'does nothing when the nonce or post id is missing', () => {
		load(
			'<div id="findable"><input name="findable_post_id" value="42"></div><button id="findable_scan"></button>'
		);
		document.getElementById( 'findable_scan' ).click();
		expect( document.body.querySelector( 'form' ) ).toBeNull();
	} );

	it( 'does nothing when the meta box is missing', () => {
		load( '<button id="findable_scan"></button>' );
		document.getElementById( 'findable_scan' ).click();
		expect( document.body.querySelector( 'form' ) ).toBeNull();
	} );

	it( 'is not bound without a scan URL', () => {
		load( BOX, { i18n: {} } );
		document.getElementById( 'findable_scan' ).click();
		expect( document.body.querySelector( 'form' ) ).toBeNull();
	} );

	it( 'is not bound without localized data', () => {
		load( BOX, null );
		document.getElementById( 'findable_scan' ).click();
		expect( document.body.querySelector( 'form' ) ).toBeNull();
	} );
} );
