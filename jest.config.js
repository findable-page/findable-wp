const defaults = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...defaults,
	collectCoverageFrom: [
		'src/editor/**/*.js',
		'assets/*.js',
		'!**/*.test.js',
	],
	coverageDirectory: 'coverage/js',
	coverageThreshold: { global: { lines: 95 } },
};
