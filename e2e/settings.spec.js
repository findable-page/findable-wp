const { test, expect } = require( '@playwright/test' );
const { API, mock, rest } = require( './helpers' );

test.describe( 'Settings page', () => {
	test.beforeEach( async ( { request } ) => {
		await mock( request, '/__mock/reset', {} );
	} );

	test( 'saves endpoint, post types, pace and checkboxes, and persists them', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/admin.php?page=findable-settings' );
		await expect(
			page.getByRole( 'heading', { name: 'Findable Settings', level: 1 } )
		).toBeVisible();

		await page.fill( '#findable-endpoint', API + '/api/scan/' ); // pasted scan URL gets normalised.
		await page
			.locator( 'input[name="findable_settings[cache_ttl_days]"]' )
			.fill( '14' );
		await page
			.locator( 'input[name="findable_settings[bulk_per_minute]"]' )
			.fill( '8' );
		await page
			.locator( 'input[name="findable_settings[scan_on_publish]"]' )
			.check();
		await page
			.locator(
				'input[name="findable_settings[post_types][]"][value="page"]'
			)
			.uncheck();
		await page.click( '#submit' );

		await expect(
			page.locator( '#setting-error-settings_updated' )
		).toBeVisible();
		await expect( page.locator( '#findable-endpoint' ) ).toHaveValue( API );
		await expect(
			page.locator( 'input[name="findable_settings[cache_ttl_days]"]' )
		).toHaveValue( '14' );
		await expect(
			page.locator( 'input[name="findable_settings[bulk_per_minute]"]' )
		).toHaveValue( '8' );
		await expect(
			page.locator( 'input[name="findable_settings[scan_on_publish]"]' )
		).toBeChecked();
		await expect(
			page.locator(
				'input[name="findable_settings[post_types][]"][value="post"]'
			)
		).toBeChecked();
		await expect(
			page.locator(
				'input[name="findable_settings[post_types][]"][value="page"]'
			)
		).not.toBeChecked();

		// Restore defaults used by later specs.
		await page
			.locator( 'input[name="findable_settings[bulk_per_minute]"]' )
			.fill( '5' );
		await page
			.locator( 'input[name="findable_settings[scan_on_publish]"]' )
			.uncheck();
		await page
			.locator(
				'input[name="findable_settings[post_types][]"][value="page"]'
			)
			.check();
		await page.click( '#submit' );
		await expect(
			page.locator( 'input[name="findable_settings[scan_on_publish]"]' )
		).not.toBeChecked();
	} );

	test( 'rejects a bad endpoint and keeps the previous one', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/admin.php?page=findable-settings' );
		const before = await page.inputValue( '#findable-endpoint' );
		await page.fill( '#findable-endpoint', 'ftp://nope.example.com' );
		await page.click( '#submit' );

		await expect(
			page.locator( '.notice-error, .settings-error.error' ).first()
		).toContainText( /http or https/ );
		await expect( page.locator( '#findable-endpoint' ) ).toHaveValue(
			before
		);
	} );

	test( 'stores an API key without echoing it, and sends it as a bearer header', async ( {
		page,
		request,
	} ) => {
		await page.goto( '/wp-admin/admin.php?page=findable-settings' );
		await page.fill( '#findable-api-key', 'secret-e2e-key' );
		await page.click( '#submit' );

		await expect( page.getByText( 'A key is saved.' ) ).toBeVisible();
		await expect( page.locator( '#findable-api-key' ) ).toHaveValue( '' );
		expect( await page.content() ).not.toContain( 'secret-e2e-key' );

		await page.click( '#findable-test-connection' );
		await expect(
			page.locator( '#findable-connection-result' )
		).toContainText( 'Connected to findable' );
		const log = await mock( request, '/__mock/log' );
		const health = log.find( ( entry ) => entry.path.endsWith( 'health' ) );
		// The mock runs on plain http: the key is only sent because the private-endpoint constant is set.
		expect( health.auth ).toBe( 'Bearer secret-e2e-key' );

		await page.check(
			'input[name="findable_settings[findable_remove_key]"]'
		);
		await page.click( '#submit' );
		await expect( page.getByText( 'A key is saved.' ) ).toHaveCount( 0 );
	} );

	test( 'connection test reports success and failure', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=findable-settings' );
		await page.click( '#findable-test-connection' );
		await expect(
			page.locator( '#findable-connection-result' )
		).toHaveText( 'Connected to findable 0.9.0-mock.' );
		await expect(
			page.locator( '#findable-connection-result' )
		).toHaveClass( /ok/ );

		await page.fill( '#findable-endpoint', 'http://127.0.0.1:9' );
		await page.click( '#submit' );
		await page.click( '#findable-test-connection' );
		await expect(
			page.locator( '#findable-connection-result' )
		).toContainText( 'Could not reach the endpoint' );
		await expect(
			page.locator( '#findable-connection-result' )
		).toHaveClass( /fail/ );

		await page.fill( '#findable-endpoint', API );
		await page.click( '#submit' );
		expect(
			( await rest( page, 'GET', '/findable/v1/bulk' ) ).status
		).toBe( 200 );
	} );
} );
