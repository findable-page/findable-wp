<?php
/**
 * Bootstrap for the constants suite: defines wp-config.php style constants
 * (which cannot be undefined) before the normal test bootstrap loads the plugin.
 *
 * @package findable-wp
 */

define( 'FINDABLE_API_KEY', 'const-key-123' );
define( 'FINDABLE_ALLOW_PRIVATE_ENDPOINT', true );

require __DIR__ . '/bootstrap.php';
