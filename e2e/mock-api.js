/**
 * Minimal stand-in for the Findable API (POST /api/scan, GET /api/health, GET /i18n/<lang>.json).
 * Scan results are deterministic per URL so tests and screenshots are stable.
 * Control endpoints (not part of the real API):
 *   POST /__mock/mode {"mode":"ok"|"rate_limit"|"down"}  switch behaviour
 *   GET  /__mock/log                                      requests received
 *   POST /__mock/reset                                    clear log and mode
 */
const http = require( 'http' );

const CHECKS = {
	seo: [
		[ 'title_exists', 'Title tag present', true ],
		[ 'title_length', 'Title length is 30-60 characters', false ],
		[ 'meta_description', 'Meta description present', true ],
		[ 'canonical', 'Canonical URL set', true ],
		[ 'h1_single', 'Exactly one H1', false ],
		[ 'img_alt', 'Images have alt text', true ],
	],
	aeo: [
		[ 'aeo_faq', 'FAQ markup present', false ],
		[ 'aeo_direct_answer', 'Opens with a direct answer', true ],
		[ 'aeo_headings_questions', 'Question-style headings', false ],
	],
	geo: [
		[ 'geo_citations', 'Cites authoritative sources', false ],
		[ 'geo_author', 'Author and date visible', true ],
		[ 'geo_schema', 'Structured data present', true ],
		[
			'geo_authoritative_links',
			'Authoritative outbound links',
			true,
			false,
		],
	],
};

function family( key, offset ) {
	const checks = CHECKS[ key ].map( ( [ id, label, pass, applicable ] ) => {
		const flip = offset % 5 === 0 && id === 'canonical';
		const check = { id, label, pass: flip ? false : pass };
		if ( applicable === false ) {
			check.applicable = false;
		}
		return check;
	} );
	const applicable = checks.filter( ( c ) => c.applicable !== false );
	const passed = applicable.filter( ( c ) => c.pass ).length;
	return {
		score: Math.round( ( 100 * passed ) / applicable.length ),
		passed,
		total: applicable.length,
		checks,
	};
}

function scanFor( url, keyphrase ) {
	const offset = Array.from( url ).reduce(
		( n, c ) => n + c.charCodeAt( 0 ),
		0
	);
	const seo = family( 'seo', offset );
	const aeo = family( 'aeo', offset );
	const geo = family( 'geo', offset );
	return {
		url,
		overall: Math.round(
			seo.score * 0.5 + aeo.score * 0.25 + geo.score * 0.25
		),
		seo,
		aeo,
		geo,
		keyphrase: keyphrase || '',
		scannedAt: '2026-01-01T00:00:00Z',
	};
}

const I18N = {
	de: {
		title_length: {
			name: 'Titellänge',
			fix: 'Halte den Titel zwischen 30 und 60 Zeichen.',
		},
	},
};

function createMockApi() {
	let mode = 'ok';
	let log = [];

	const server = http.createServer( ( req, res ) => {
		const chunks = [];
		req.on( 'data', ( c ) => chunks.push( c ) );
		req.on( 'end', () => {
			const raw = Buffer.concat( chunks ).toString( 'utf8' );
			let body = {};
			try {
				body = raw ? JSON.parse( raw ) : {};
			} catch ( e ) {
				body = {};
			}
			const send = ( status, data, headers = {} ) => {
				res.writeHead( status, {
					'Content-Type': 'application/json',
					...headers,
				} );
				res.end( JSON.stringify( data ) );
			};
			const path = req.url.split( '?' )[ 0 ];

			if ( path === '/__mock/mode' ) {
				mode = body.mode || 'ok';
				return send( 200, { mode } );
			}
			if ( path === '/__mock/log' ) {
				return send( 200, log );
			}
			if ( path === '/__mock/reset' ) {
				mode = 'ok';
				log = [];
				return send( 200, { ok: true } );
			}

			log.push( {
				method: req.method,
				path,
				body,
				auth: req.headers.authorization || '',
			} );

			if ( path === '/api/health' || path === '/health' ) {
				return send( 200, {
					status: 'ok',
					name: 'findable',
					version: '0.9.0-mock',
				} );
			}
			if ( path.startsWith( '/i18n/' ) ) {
				const lang = path
					.replace( '/i18n/', '' )
					.replace( '.json', '' );
				return send( 200, I18N[ lang ] || {} );
			}
			if ( path === '/api/scan' && req.method === 'POST' ) {
				if ( mode === 'rate_limit' ) {
					return send(
						429,
						{ error: 'rate limit exceeded' },
						{ 'Retry-After': '60' }
					);
				}
				if ( mode === 'down' ) {
					return send( 500, { error: 'audit failed' } );
				}
				if ( ! body.url ) {
					return send( 400, { error: 'url required' } );
				}
				return send( 200, scanFor( body.url, body.keyphrase ) );
			}
			return send( 404, { error: 'not found' } );
		} );
	} );

	return {
		server,
		listen: ( port ) =>
			new Promise( ( resolve ) =>
				server.listen( port, '127.0.0.1', resolve )
			),
		close: () => new Promise( ( resolve ) => server.close( resolve ) ),
	};
}

module.exports = { createMockApi, scanFor };

if ( require.main === module ) {
	const port = Number( process.env.MOCK_API_PORT || 9501 );
	createMockApi()
		.listen( port )
		.then( () =>
			// eslint-disable-next-line no-console
			console.log( `Findable mock API on http://127.0.0.1:${ port }` )
		);
}
