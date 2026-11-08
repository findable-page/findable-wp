const { test, expect } = require( '@playwright/test' );
const {
	mock,
	rest,
	createPost,
	deleteAllPosts,
	configureEndpoint,
} = require( './helpers' );

test.describe( 'Site dashboard', () => {
	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await configureEndpoint( page );
		await deleteAllPosts( page );
		await page.close();
	} );

	test.beforeEach( async ( { request } ) => {
		await mock( request, '/__mock/reset', {} );
	} );

	test( 'empty site shows the no-scan message and the bulk panel', async ( {
		page,
	} ) => {
		await deleteAllPosts( page );
		await page.goto( '/wp-admin/admin.php?page=findable-dashboard' );
		await expect( page.getByText( 'No posts scanned yet.' ) ).toBeVisible();
		await expect( page.locator( '#findable-bulk-panel' ) ).toBeVisible();
	} );

	test( 'aggregates scanned posts: summary, bands, weakest posts and common failing checks', async ( {
		page,
	} ) => {
		const titles = [
			'Coffee grinders compared',
			'Tea ceremony guide',
			'Cold brew basics',
			'Moka pot tips',
		];
		const posts = [];
		for ( const title of titles ) {
			posts.push( await createPost( page, title ) );
		}
		await createPost( page, 'Never scanned post' );
		for ( const post of posts ) {
			const res = await rest(
				page,
				'POST',
				`/findable/v1/posts/${ post.id }/scan`,
				{}
			);
			expect( res.status ).toBe( 200 );
		}

		await page.goto( '/wp-admin/admin.php?page=findable-dashboard' );
		await expect(
			page.getByRole( 'heading', { name: 'Site summary' } )
		).toBeVisible();
		await expect(
			page.getByText( /4 of 5 scannable posts scanned/ )
		).toBeVisible();
		await expect( page.getByText( /1 never scanned/ ) ).toBeVisible();
		await expect( page.getByText( /Average scores/ ) ).toBeVisible();

		const bands = page.locator( 'table.findable-bands' );
		await expect( bands ).toContainText( 'Good (80-100)' );
		await expect( bands ).toContainText( 'Needs work (50-79)' );
		await expect( bands ).toContainText( 'Poor (0-49)' );

		const weakest = page.locator(
			'h2:has-text("Weakest posts") + table tbody tr'
		);
		await expect( weakest ).toHaveCount( 4 );
		await expect( weakest.first().locator( 'a' ) ).toHaveAttribute(
			'href',
			/post\.php\?post=\d+&action=edit/
		);

		const common = page.locator(
			'h2:has-text("Most common failing checks") + table tbody tr'
		);
		await expect( common.first() ).toContainText( /4/ );
		// Clicking the count filters the posts list by that failing check.
		await common.first().locator( 'a' ).click();
		await expect( page ).toHaveURL( /edit\.php.*findable_failing=/ );
		await expect( page.locator( '#the-list tr.iedit' ) ).toHaveCount( 4 );
		await expect( page.locator( '#the-list' ) ).not.toContainText(
			'Never scanned post'
		);
	} );

	test( 'posts list has a sortable Findable column with unscanned posts last', async ( {
		page,
	} ) => {
		await page.goto(
			'/wp-admin/edit.php?orderby=findable_score&order=asc'
		);
		await expect( page.locator( 'th#findable' ) ).toBeVisible();
		const rows = page.locator( '#the-list tr.iedit' );
		await expect( rows.last() ).toContainText( 'Never scanned post' );

		await page.goto(
			'/wp-admin/edit.php?orderby=findable_score&order=desc'
		);
		await expect(
			page.locator( '#the-list tr.iedit' ).last()
		).toContainText( 'Never scanned post' );
	} );

	test( 'editors see the dashboard but not the bulk panel', async ( {
		page,
		browser,
	} ) => {
		const editorPage = await browser.newPage();
		await editorPage.goto( '/wp-login.php' );
		const created = await rest( page, 'POST', '/wp/v2/users', {
			username: 'editor1',
			password: 'editor-pass-1',
			email: 'editor1@example.com',
			roles: [ 'editor' ],
		} );
		expect( [ 201, 500 ] ).toContain( created.status );
		await editorPage.fill( '#user_login', 'editor1' );
		await editorPage.fill( '#user_pass', 'editor-pass-1' );
		await editorPage.click( '#wp-submit' );
		await editorPage.goto( '/wp-admin/admin.php?page=findable-dashboard' );
		await expect(
			editorPage.getByRole( 'heading', { name: 'Site summary' } )
		).toBeVisible();
		await expect(
			editorPage.locator( '#findable-bulk-panel' )
		).toHaveCount( 0 );
		await editorPage.goto( '/wp-admin/admin.php?page=findable-settings' );
		await expect(
			editorPage.getByText( /not allowed to access this page/i )
		).toBeVisible();
		await editorPage.close();
	} );
} );
