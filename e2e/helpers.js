const API = 'http://127.0.0.1:9501';

/**
 * Run fn in the page with a REST nonce and a `rest(path, opts)` helper.
 * @param {import('@playwright/test').Page} page   Logged-in page.
 * @param {string}                          method HTTP method.
 * @param {string}                          route  REST route, e.g. /wp/v2/posts.
 * @param {Object}                          [data] JSON body.
 */
async function rest( page, method, route, data ) {
	await page.goto( '/wp-admin/admin.php?page=findable-dashboard' );
	return page.evaluate(
		async ( [ m, r, d ] ) => {
			const nonce = await (
				await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' )
			).text();
			const res = await fetch( '/?rest_route=' + r, {
				method: m,
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json',
				},
				body: d ? JSON.stringify( d ) : undefined,
			} );
			return { status: res.status, body: await res.json() };
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
			`/wp/v2/${ type }&per_page=100&status=any`
		);
		for ( const item of body ) {
			await rest(
				page,
				'DELETE',
				`/wp/v2/${ type }/${ item.id }&force=true`
			);
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

async function prepareEditor( page ) {
	await page.waitForSelector(
		'.editor-visual-editor, .edit-post-visual-editor',
		{ timeout: 60_000 }
	);
	await page.evaluate( () => {
		const { dispatch } = window.wp.data;
		dispatch( 'core/preferences' ).set(
			'core/edit-post',
			'welcomeGuide',
			false
		);
		dispatch( 'core/preferences' ).set(
			'core/edit-post',
			'fullscreenMode',
			false
		);
		dispatch( 'core/preferences' ).set( 'core', 'distractionFree', false );
	} );
	// On a fresh site the guide can render after the preference write; close it whenever it shows up.
	const guide = page.getByRole( 'dialog', { name: /Welcome to the editor/ } );
	await page.addLocatorHandler( guide, async () => {
		await guide.getByRole( 'button', { name: 'Close' } ).click();
	} );
}

async function openFindableSidebar( page ) {
	const body = page.locator( '.findable-sidebar-body' );
	// The editor remembers the open sidebar; clicking the toggle again would close it.
	if ( await body.isVisible() ) {
		return;
	}
	const toggle = page.getByRole( 'button', {
		name: 'Findable',
		exact: true,
	} );
	if ( await toggle.count() ) {
		await toggle.first().click();
	} else {
		await page.getByRole( 'button', { name: 'Options' } ).first().click();
		await page
			.getByRole( 'menuitemcheckbox', { name: /Findable/ } )
			.click();
	}
	await page.locator( '.findable-sidebar-body' ).waitFor();
}

module.exports = {
	API,
	rest,
	createPost,
	deleteAllPosts,
	mock,
	configureEndpoint,
	runCron,
	prepareEditor,
	openFindableSidebar,
};
