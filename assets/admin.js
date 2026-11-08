/**
 * Findable admin JS: connection test, bulk panel polling, classic meta box scan.
 * No build step needed.
 */
( function () {
	'use strict';

	function restBase() {
		return window.findableAdmin && window.findableAdmin.restUrl;
	}

	function api( path, options ) {
		options = options || {};
		options.headers = options.headers || {};
		options.headers[ 'X-WP-Nonce' ] = window.findableAdmin
			? window.findableAdmin.restNonce
			: '';
		options.credentials = 'same-origin';
		return fetch( restBase() + path, options ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, data };
			} );
		} );
	}

	/* Connection test on the settings page. */
	const testButton = document.getElementById( 'findable-test-connection' );
	if ( testButton ) {
		testButton.addEventListener( 'click', function () {
			const out = document.getElementById( 'findable-connection-result' );
			testButton.disabled = true;
			out.textContent = window.findableAdmin.i18n.testing;
			out.className = '';
			api( '/connection', { method: 'POST' } )
				.then( function ( res ) {
					out.textContent =
						res.data && res.data.message
							? res.data.message
							: window.findableAdmin.i18n.error;
					out.className =
						res.ok && res.data.connected ? 'ok' : 'fail';
				} )
				.catch( function () {
					out.textContent = window.findableAdmin.i18n.error;
					out.className = 'fail';
				} )
				.then( function () {
					testButton.disabled = false;
				} );
		} );
	}

	/* Bulk scan panel on the dashboard. */
	const panel = document.getElementById( 'findable-bulk-panel' );
	if ( panel ) {
		let pollTimer = null;
		const strings = window.findableAdmin.i18n;

		function setBusy( busy ) {
			[ 'findable-bulk-start', 'findable-bulk-rescan' ].forEach(
				function ( id ) {
					const el = document.getElementById( id );
					if ( el ) {
						el.disabled = busy;
					}
				}
			);
		}

		function start( force ) {
			api( '/bulk', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { force: !! force } ),
			} ).then( function ( res ) {
				if ( ! res.ok ) {
					// Deliberate blocking alert: the start action failed.
					/* eslint-disable no-alert */
					window.alert(
						res.data && res.data.message
							? res.data.message
							: strings.error
					);
					/* eslint-enable no-alert */
				}
				refresh();
			} );
		}

		function action( method, body ) {
			api(
				'/bulk',
				method === 'DELETE'
					? { method }
					: {
							method,
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify( body || {} ),
					  }
			).then( refresh );
		}

		function refresh() {
			api( '/bulk' ).then( function ( res ) {
				if ( ! res.ok ) {
					return;
				}
				const state = res.data;
				const statusEl = document.getElementById(
					'findable-bulk-status'
				);
				const progress = document.getElementById(
					'findable-bulk-progress'
				);
				const eta = document.getElementById( 'findable-bulk-eta' );
				const done = ( state.done || 0 ) + ( state.failed || 0 );
				if ( statusEl ) {
					statusEl.textContent = state.status_text || strings.error;
				}
				if ( eta ) {
					eta.textContent = state.eta_text || '';
				}
				if ( progress ) {
					progress.max = state.total || 1;
					progress.value = done;
				}
				const running =
					state.status === 'running' || state.status === 'paused';
				setBusy( running );
				[ 'findable-bulk-pause', 'findable-bulk-cancel' ].forEach(
					function ( id ) {
						const el = document.getElementById( id );
						if ( el ) {
							el.disabled = ! running;
						}
					}
				);
				const resume = document.getElementById(
					'findable-bulk-resume'
				);
				if ( resume ) {
					resume.disabled = state.status !== 'paused';
				}
				panel.setAttribute( 'data-running', running ? '1' : '0' );
				if ( running ) {
					schedulePoll();
				} else {
					stopPoll();
				}
			} );
		}

		function schedulePoll() {
			if ( pollTimer === null && ! document.hidden ) {
				pollTimer = window.setInterval( function () {
					if ( document.hidden ) {
						return; // Visibility pause.
					}
					refresh();
				}, 10000 );
			}
		}

		function stopPoll() {
			if ( pollTimer !== null ) {
				window.clearInterval( pollTimer );
				pollTimer = null;
			}
		}

		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				// Poll loop pauses itself while hidden.
				return;
			}
			if ( panel.getAttribute( 'data-running' ) === '1' ) {
				schedulePoll();
			}
		} );

		function bind( id, handler ) {
			const target = document.getElementById( id );
			if ( target ) {
				target.addEventListener( 'click', handler );
			}
		}
		bind( 'findable-bulk-start', function () {
			start( false );
		} );
		bind( 'findable-bulk-rescan', function () {
			start( true );
		} );
		bind( 'findable-bulk-pause', function () {
			action( 'POST', { action: 'pause' } );
		} );
		bind( 'findable-bulk-resume', function () {
			action( 'POST', { action: 'resume' } );
		} );
		document
			.querySelectorAll( '.findable-bulk-resume' )
			.forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					action( 'POST', { action: 'resume' } );
				} );
			} );
		bind( 'findable-bulk-cancel', function () {
			// Deliberate blocking confirm before cancelling a run.
			/* eslint-disable no-alert */
			if ( window.confirm( strings.confirm ) ) {
				/* eslint-enable no-alert */
				action( 'DELETE' );
			}
		} );
		refresh();
	}

	/* Classic meta box scan: build a standalone form and submit it to admin-post.php. */
	const scanButton = document.getElementById( 'findable_scan' );
	if ( scanButton && window.findableAdmin && window.findableAdmin.scanUrl ) {
		scanButton.addEventListener( 'click', function () {
			const box = document.getElementById( 'findable' );
			const nonce = box
				? box.querySelector( 'input[name="findable_scan_nonce"]' )
				: null;
			const postId = box
				? box.querySelector( 'input[name="findable_post_id"]' )
				: null;
			if ( ! nonce || ! postId ) {
				return;
			}
			const keyphrase = document.getElementById( 'findable-keyphrase' );
			const form = document.createElement( 'form' );
			form.method = 'post';
			form.action = window.findableAdmin.scanUrl;
			form.style.display = 'none';
			[
				[ 'action', 'findable_scan' ],
				[ 'findable_scan_nonce', nonce.value ],
				[ 'post', postId.value ],
				[ 'findable_keyphrase', keyphrase ? keyphrase.value : '' ],
			].forEach( function ( pair ) {
				const input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = pair[ 0 ];
				input.value = pair[ 1 ];
				form.appendChild( input );
			} );
			document.body.appendChild( form );
			scanButton.disabled = true;
			const spinner = scanButton.parentNode.querySelector(
				'.findable-scan-spinner'
			);
			if ( spinner ) {
				spinner.classList.add( 'is-active' );
			}
			form.submit();
		} );
	}
} )();
