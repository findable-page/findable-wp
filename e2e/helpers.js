const API = `http://127.0.0.1:${ process.env.E2E_API_PORT || 9501 }`;

/**
 * Ensure the browser context is logged into WordPress admin.
 * @param {import('@playwright/test').Page} page Logged-in page.
 * @param {string}                          [username] Username.
 * @param {string}                          [password] Password.
 */
async function login( page, username = 'admin', password = 'password' ) {
	await page.goto( '/' );
	if ( page.url().includes( 'wp-login.php' ) ) {
		const loginInput = page.locator( '#user_login' );
		if ( await loginInput.isVisible() ) {
			await loginInput.fill( username );
			await page.fill( '#user_pass', password );
			await page.click( '#wp-submit' );
			await page.waitForURL( /wp-admin/ );
		}
	}
}

/**
 * Run fn in the page with a REST nonce and a `rest(path, opts)` helper.
 * @param {import('@playwright/test').Page} page   Logged-in page.
 * @param {string}                          method HTTP method.
 * @param {string}                          route  REST route, e.g. /wp/v2/posts.
 * @param {Object}                          [data] JSON body.
 */
async function rest( page, method, route, data ) {
	await page.goto( '/wp-admin/admin.php?page=findable-dashboard' );
	if ( page.url().includes( 'wp-login.php' ) ) {
		await login( page );
		await page.goto( '/wp-admin/admin.php?page=findable-dashboard' );
	}
	return page.evaluate(
		async ( [ m, r, d ] ) => {
			const nonce =
				window.findableAdmin?.restNonce ||
				( window.wpApiSettings ? window.wpApiSettings.nonce : '' );
			const path = r.startsWith( '/' ) ? r : '/' + r;
			const url = path.startsWith( '/?' )
				? path
				: '/?rest_route=' + path.replace( '?', '&' );
			const res = await fetch( url, {
				method: m,
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json',
				},
				body: d ? JSON.stringify( d ) : undefined,
			} );
			let body = {};
			try {
				body = await res.json();
			} catch ( e ) {}
			return { status: res.status, body };
		},
		[ method, route, data ]
	);
}

async function createPost( page, title, extra = {} ) {
	const { body } = await rest( page, 'POST', '/wp/v2/posts', {
		title,
		content: `<!-- wp:paragraph --><p>${ title } body text.</p><!-- /wp:paragraph -->`,
		status: 'publish',
		...extra,
	} );
	return body;
}

async function deleteAllPosts( page ) {
	// Posts and pages are both scannable by default, so clear both for exact counts.
	for ( const type of [ 'posts', 'pages' ] ) {
		const { body } = await rest(
			page,
			'GET',
			`/wp/v2/${ type }?per_page=100&status=any`
		);
		if ( Array.isArray( body ) ) {
			for ( const item of body ) {
				await rest(
					page,
					'DELETE',
					`/wp/v2/${ type }/${ item.id }?force=true`
				);
			}
		}
	}
}

async function mock( request, path, data ) {
	const res = await request.fetch( API + path, {
		method: data ? 'POST' : 'GET',
		data,
	} );
	return res.json();
}

/**
 * Point the plugin at the mock API through the real settings form.
 * @param {import('@playwright/test').Page} page Logged-in page.
 */
async function configureEndpoint( page ) {
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=findable-settings' );
	await page.fill( '#findable-endpoint', API );
	await page.click( '#submit' );
	await page.waitForSelector(
		'#setting-error-settings_updated, .notice-success'
	);
}

/**
 * Run WP-Cron the way a real request would.
 * @param {import('@playwright/test').Page} page Logged-in page.
 */
async function runCron( page ) {
	await page.request.get( '/wp-cron.php?doing_wp_cron' );
}

/**
 * Persist editor preferences server-side so the editor boots without the
 * welcome guide and with the Findable sidebar open. Writing them from inside a
 * loaded editor races its own boot (guide re-opens, sidebar state flips).
 *
 * @param {import('@playwright/test').Page} page Logged-in page.
 */
async function primeEditorPreferences( page ) {
	await rest( page, 'POST', '/wp/v2/users/me', {
		meta: {
			persisted_preferences: {
				'core/edit-post': {
					welcomeGuide: false,
					fullscreenMode: false,
				},
				core: {
					distractionFree: false,
					isComplementaryAreaVisible: true,
				},
				'core/interface': {
					complementaryArea: {
						'core/edit-post': 'findable/findable-sidebar',
					},
				},
				_modified: new Date().toISOString(),
			},
		},
	} );
}

async function prepareEditor( page ) {
	await page.waitForSelector(
		'.editor-visual-editor, .edit-post-visual-editor',
		{ timeout: 60_000 }
	);
}

async function openFindableSidebar( page ) {
	const body = page.locator( '.findable-sidebar-body' );
	if ( ! ( await body.isVisible() ) ) {
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/edit-post' )
				.openGeneralSidebar( 'findable/findable-sidebar' )
		);
	}
	await body.waitFor();
}

module.exports = {
	API,
	login,
	rest,
	createPost,
	deleteAllPosts,
	mock,
	configureEndpoint,
	runCron,
	primeEditorPreferences,
	prepareEditor,
	openFindableSidebar,
};
