<?php
/**
 * PHPUnit bootstrap: WordPress test framework plus an unstubbed-HTTP guard.
 *
 * @package findable-wp
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test library not found at {$_tests_dir}. Run bin/install-wp-tests.sh first.\n";
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

// Unstubbed-HTTP guard: a request answered by a test stub (priority below
// 999, returning non-false) passes through; anything unstubbed returns an
// error instead of reaching the network.
tests_add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		return new WP_Error( 'findable_unstubbed_http', 'Unstubbed HTTP request: ' . $url );
	},
	999,
	3
);

tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/findable.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

require_once dirname( __DIR__ ) . '/tests/class-findable-testcase.php';
