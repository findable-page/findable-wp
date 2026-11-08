const { defineConfig } = require( '@playwright/test' );

const WP_PORT = 9400;
const API_PORT = 9501;

module.exports = defineConfig( {
	testDir: './e2e',
	testMatch: '**/*.spec.js',
	timeout: 90_000,
	expect: { timeout: 20_000 },
	// One shared WordPress site, tests build on each other's state in file order.
	workers: 1,
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : 'list',
	use: {
		baseURL: `http://127.0.0.1:${ WP_PORT }`,
		viewport: { width: 1280, height: 800 },
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	outputDir: './e2e/.results',
	webServer: [
		{
			command: `MOCK_API_PORT=${ API_PORT } node e2e/mock-api.js`,
			url: `http://127.0.0.1:${ API_PORT }/api/health`,
			reuseExistingServer: ! process.env.CI,
		},
		{
			// Real WordPress (WASM PHP + SQLite) via WordPress Playground, plugin mounted from this checkout.
			command: `npx wp-playground-cli server --blueprint=e2e/blueprint.json --mount="$PWD":/wordpress/wp-content/plugins/findable --port=${ WP_PORT } --define-bool FINDABLE_ALLOW_PRIVATE_ENDPOINT true --define-number WP_CRON_LOCK_TIMEOUT 1`,
			// Playground 302-redirects every PHP route until a cookie is set, so probe a static file.
			url: `http://127.0.0.1:${ WP_PORT }/readme.html`,
			reuseExistingServer: ! process.env.CI,
			timeout: 240_000,
		},
	],
} );
