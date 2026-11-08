const { test, expect } = require( '@playwright/test' );
const {
	mock,
	rest,
	createPost,
	deleteAllPosts,
	configureEndpoint,
	runCron,
} = require( './helpers' );

test.describe( 'Bulk scan', () => {
	// WP_CRON_LOCK_TIMEOUT is 1 in playwright.config.js so direct wp-cron.php calls are not locked out.
	test.setTimeout( 200_000 );

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await configureEndpoint( page );
		await deleteAllPosts( page );
		for ( const title of [ 'Bulk one', 'Bulk two', 'Bulk three' ] ) {
			await createPost( page, title );
		}
		await page.close();
	} );

	test.beforeEach( async ( { page, request } ) => {
		await mock( request, '/__mock/reset', {} );
		// Never inherit a running/paused run from an earlier test.
		await rest( page, 'DELETE', '/findable/v1/bulk' );
	} );

	test( 'starts from the dashboard, ticks via WP-Cron and finishes with every post scanned', async ( {
		page,
		request,
	} ) => {
		await page.goto( '/wp-admin/admin.php?page=findable-dashboard' );
		const panel = page.locator( '#findable-bulk-panel' );
		await expect(
			panel.getByRole( 'button', { name: 'Start bulk scan' } )
		).toBeEnabled();

		await panel.getByRole( 'button', { name: 'Start bulk scan' } ).click();
		await expect( page.locator( '#findable-bulk-status' ) ).toContainText(
			'Running: 0 of 3 processed.'
		);
		await expect( page.locator( '#findable-bulk-eta' ) ).toContainText(
			'About 1 minute remaining.'
		);
		await expect(
			panel.getByRole( 'button', { name: 'Start bulk scan' } )
		).toBeDisabled();
		await expect( panel.locator( '#findable-bulk-pause' ) ).toBeEnabled();

		// The first tick is scheduled about 5 seconds out; fire cron until the run completes.
		await expect
			.poll(
				async () => {
					await runCron( page );
					return ( await rest( page, 'GET', '/findable/v1/bulk' ) )
						.body.status;
				},
				{ timeout: 120_000, intervals: [ 3_000 ] }
			)
			.toBe( 'done' );

		const state = ( await rest( page, 'GET', '/findable/v1/bulk' ) ).body;
		expect( state.done ).toBe( 3 );
		expect( state.failed ).toBe( 0 );
		const scans = ( await mock( request, '/__mock/log' ) ).filter(
			( e ) => e.path === '/api/scan'
		);
		expect( scans ).toHaveLength( 3 );

		await page.goto( '/wp-admin/admin.php?page=findable-dashboard' );
		await expect( page.locator( '#findable-bulk-status' ) ).toContainText(
			'Bulk scan finished: 3 scanned, 0 failed.'
		);
		await expect(
			page.getByText( /3 of 3 scannable posts scanned/ )
		).toBeVisible();
	} );

	test( 'second run only scans stale posts; rescan all forces every post', async ( {
		page,
		request,
	} ) => {
		await page.goto( '/wp-admin/admin.php?page=findable-dashboard' );
		await page.getByRole( 'button', { name: 'Rescan all posts' } ).click();
		await expect( page.locator( '#findable-bulk-status' ) ).toContainText(
			'Running: 0 of 3 processed.'
		);

		await expect
			.poll(
				async () => {
					await runCron( page );
					return ( await rest( page, 'GET', '/findable/v1/bulk' ) )
						.body.status;
				},
				{ timeout: 120_000, intervals: [ 3_000 ] }
			)
			.toBe( 'done' );
		const scans = ( await mock( request, '/__mock/log' ) ).filter(
			( e ) => e.path === '/api/scan'
		);
		expect( scans ).toHaveLength( 3 );
	} );

	test( 'pause, resume and cancel from the panel', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=findable-dashboard' );
		const panel = page.locator( '#findable-bulk-panel' );
		await panel.getByRole( 'button', { name: 'Rescan all posts' } ).click();
		await expect( page.locator( '#findable-bulk-status' ) ).toContainText(
			'Running'
		);

		await page.locator( '#findable-bulk-pause' ).click();
		await expect( page.locator( '#findable-bulk-status' ) ).toContainText(
			'Paused at 0 of 3.'
		);
		await expect( page.locator( '#findable-bulk-resume' ) ).toBeEnabled();

		await page.locator( '#findable-bulk-resume' ).click();
		await expect( page.locator( '#findable-bulk-status' ) ).toContainText(
			'Running'
		);

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.locator( '#findable-bulk-cancel' ).click();
		await expect( page.locator( '#findable-bulk-status' ) ).toContainText(
			'Bulk scan cancelled.'
		);
		await expect(
			panel.getByRole( 'button', { name: 'Start bulk scan' } )
		).toBeEnabled();
	} );

	test( 'a rate limit keeps the run alive and shows the API message', async ( {
		page,
		request,
	} ) => {
		await mock( request, '/__mock/mode', { mode: 'rate_limit' } );
		await page.goto( '/wp-admin/admin.php?page=findable-dashboard' );
		await page.getByRole( 'button', { name: 'Rescan all posts' } ).click();
		await expect( page.locator( '#findable-bulk-status' ) ).toContainText(
			'Running'
		);

		await expect
			.poll(
				async () => {
					await runCron( page );
					return ( await rest( page, 'GET', '/findable/v1/bulk' ) )
						.body.last_error;
				},
				{ timeout: 120_000, intervals: [ 3_000 ] }
			)
			.toContain( 'rate limit' );
		expect(
			( await rest( page, 'GET', '/findable/v1/bulk' ) ).body.status
		).toBe( 'running' );

		await rest( page, 'DELETE', '/findable/v1/bulk' );
	} );
} );
