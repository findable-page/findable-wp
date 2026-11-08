/**
 * Screenshots for WordPress.org and docs: `npm run screenshots`.
 * Same servers as the e2e suite, 2x device scale for crisp PNGs.
 */
const base = require( './playwright.config.js' );

module.exports = {
	...base,
	testDir: './e2e/screenshots',
	testMatch: '**/*.shots.js',
	retries: 0,
	reporter: 'list',
	use: {
		...base.use,
		viewport: { width: 1440, height: 900 },
		deviceScaleFactor: 2,
	},
};
