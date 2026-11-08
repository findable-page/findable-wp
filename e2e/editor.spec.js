const { test, expect } = require( '@playwright/test' );
const {
	mock,
	createPost,
	deleteAllPosts,
	configureEndpoint,
	prepareEditor,
	openFindableSidebar,
} = require( './helpers' );

test.describe( 'Block editor score panel', () => {
	let post;

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await configureEndpoint( page );
		await deleteAllPosts( page );
		post = await createPost( page, 'How to brew espresso at home' );
		await page.close();
	} );

	test.beforeEach( async ( { request } ) => {
		await mock( request, '/__mock/reset', {} );
	} );

	async function openEditor( page ) {
		await page.goto( `/wp-admin/post.php?post=${ post.id }&action=edit` );
		await prepareEditor( page );
		await openFindableSidebar( page );
	}

	test( 'scans a post, shows scores and the failing-check breakdown with advice', async ( {
		page,
		request,
	} ) => {
		await openEditor( page );
		await expect( page.getByText( 'Not scanned yet.' ) ).toBeVisible();

		await page.getByLabel( 'Keyphrase (optional)' ).fill( 'espresso' );
		await page.getByRole( 'button', { name: 'Scan now' } ).click();

		const body = page.locator( '.findable-sidebar-body' );
		await expect( body.locator( '.findable-score' ) ).toBeVisible();
		await expect( body.locator( '.findable-family-score' ) ).toHaveCount(
			3
		);
		await expect( body ).toContainText( /SEO: \d+ \(\d+ of \d+ passed\)/ );
		await expect( body ).toContainText( /AEO: \d+ \(\d+ of \d+ passed\)/ );
		await expect( body ).toContainText( /GEO: \d+ \(\d+ of \d+ passed\)/ );
		await expect( body ).toContainText( 'Scanned just now' );
		await expect(
			body.getByRole( 'link', { name: 'Full report on findable.page' } )
		).toHaveAttribute( 'href', /^https:\/\/findable\.page\/127\.0\.0\.1/ );

		// Failing tab is selected by default and lists advice for each failing check.
		await expect(
			body.locator( '.findable-check-fail' ).first()
		).toBeVisible();
		await expect(
			body.locator( '.findable-check-fail', { hasText: 'Title length' } )
		).toContainText( 'Keep the title between 30 and 60 characters' );

		await body.getByRole( 'button', { name: /^Passing \(/ } ).click();
		await expect(
			body.locator( '.findable-check-pass', {
				hasText: 'Title tag present',
			} )
		).toBeVisible();
		await body
			.getByRole( 'button', { name: /^Not applicable \(/ } )
			.click();
		await expect(
			body.locator( '.findable-check-na', {
				hasText: 'Authoritative outbound links',
			} )
		).toBeVisible();

		const log = await mock( request, '/__mock/log' );
		const scan = log.find( ( e ) => e.path === '/api/scan' );
		expect( scan.body.keyphrase ).toBe( 'espresso' );
		expect( scan.body.url ).toContain( '127.0.0.1' );
	} );

	test( 'the stored result and keyphrase come back on reload', async ( {
		page,
	} ) => {
		await openEditor( page );
		const body = page.locator( '.findable-sidebar-body' );
		await expect( body.locator( '.findable-score' ) ).toBeVisible();
		await expect( page.getByLabel( 'Keyphrase (optional)' ) ).toHaveValue(
			'espresso'
		);
	} );

	test( 'shows the API error and keeps the old result when the API is down', async ( {
		page,
		request,
	} ) => {
		await openEditor( page );
		await mock( request, '/__mock/mode', { mode: 'down' } );
		// REST lock: wait out the 10 second double-click guard from the previous scan.
		await page.waitForTimeout( 10_500 );
		await page.getByRole( 'button', { name: 'Scan now' } ).click();

		await expect(
			page.locator( '.findable-sidebar-body .components-notice' )
		).toContainText( 'audit failed' );
		await expect(
			page.locator( '.findable-sidebar-body .findable-score' )
		).toBeVisible();
	} );

	test( 'a draft cannot be scanned', async ( { page } ) => {
		const draftPage = await page.context().newPage();
		const draft = await createPost( draftPage, 'Work in progress', {
			status: 'draft',
		} );
		await draftPage.close();

		await page.goto( `/wp-admin/post.php?post=${ draft.id }&action=edit` );
		await prepareEditor( page );
		await openFindableSidebar( page );

		await expect( page.locator( '.findable-sidebar-body' ) ).toContainText(
			'Publish this post first'
		);
		await expect(
			page.getByRole( 'button', { name: 'Scan now' } )
		).toBeDisabled();
	} );
} );
