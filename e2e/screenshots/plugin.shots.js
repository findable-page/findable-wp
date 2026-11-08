/**
 * Captures the WordPress.org screenshots against a real WordPress (Playground)
 * and the mock Findable API. Writes .wordpress-org/screenshot-N.png and
 * docs/screenshots/screenshot-N.png; numbering matches readme.txt "== Screenshots ==".
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const {
	rest,
	createPost,
	deleteAllPosts,
	configureEndpoint,
	primeEditorPreferences,
	prepareEditor,
	openFindableSidebar,
} = require( '../helpers' );

const ROOT = path.join( __dirname, '..', '..' );
const OUT = [
	path.join( ROOT, '.wordpress-org' ),
	path.join( ROOT, 'docs', 'screenshots' ),
];

async function save( target, n ) {
	const buffer = await target.screenshot( { animations: 'disabled' } );
	for ( const dir of OUT ) {
		fs.mkdirSync( dir, { recursive: true } );
		fs.writeFileSync( path.join( dir, `screenshot-${ n }.png` ), buffer );
	}
}

const TITLES = [
	'How to brew espresso at home',
	'Coffee grinders compared: burr vs blade',
	'Cold brew basics for beginners',
	'The complete moka pot guide',
	'Choosing a pour-over kettle',
	'Milk frothing without a machine',
];

test.describe.configure( { mode: 'serial' } );

test.describe( 'Screenshots', () => {
	test.setTimeout( 180_000 );
	let posts;

	test.beforeAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await configureEndpoint( page );
		await deleteAllPosts( page );
		await rest( page, 'DELETE', '/findable/v1/bulk' );
		await primeEditorPreferences( page );
		posts = [];
		for ( const title of TITLES ) {
			posts.push( await createPost( page, title ) );
		}
		// Scan all but the last two so the dashboard has data and the bulk scan has work left.
		for ( const post of posts.slice( 0, -2 ) ) {
			await rest( page, 'POST', `/findable/v1/posts/${ post.id }/scan`, {
				keyphrase: 'coffee',
			} );
		}
		await page.close();
	} );

	test( '1 settings page', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=findable-settings' );
		await page.click( '#findable-test-connection' );
		await expect(
			page.locator( '#findable-connection-result' )
		).toHaveClass( /ok/ );
		await save( page, 1 );
	} );

	test( '2 editor score sidebar with breakdown', async ( { page } ) => {
		await page.goto(
			`/wp-admin/post.php?post=${ posts[ 0 ].id }&action=edit`
		);
		await prepareEditor( page );
		await openFindableSidebar( page );
		await page.getByRole( 'button', { name: 'Scan now' } ).click();
		const body = page.locator( '.findable-sidebar-body' );
		await expect( body.locator( '.findable-family-score' ) ).toHaveCount(
			3
		);
		await expect(
			body.locator( '.findable-check-fail' ).first()
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Scan now' } )
		).toBeEnabled();
		// Show the scores at the top of the panel, not the scrolled-to button.
		await body.locator( '.findable-score' ).scrollIntoViewIfNeeded();
		await page.mouse.move( 0, 0 );
		await save( page, 2 );
	} );

	test( '3 site dashboard', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=findable-dashboard' );
		await expect(
			page.getByRole( 'heading', { name: 'Site summary' } )
		).toBeVisible();
		await save( page, 3 );
	} );

	test( '4 bulk scan in progress', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=findable-dashboard' );
		const panel = page.locator( '#findable-bulk-panel' );
		await panel.getByRole( 'button', { name: 'Start bulk scan' } ).click();
		await expect( page.locator( '#findable-bulk-status' ) ).toContainText(
			'Running'
		);
		await panel.scrollIntoViewIfNeeded();
		await save( panel, 4 );
		await rest( page, 'DELETE', '/findable/v1/bulk' );
	} );

	test( '5 posts list score column', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php' );
		await expect( page.locator( '.wp-list-table' ) ).toBeVisible();
		await save( page, 5 );
	} );
} );
