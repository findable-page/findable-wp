<?php
/**
 * Plugin Name:       Findable
 * Plugin URI:        https://findable.page
 * Description:       Score posts and pages for SEO, AEO and GEO findability with the Findable API and see what to fix.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Findable
 * Author URI:        https://findable.page
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       findable
 *
 * @package findable-wp
 */

defined( 'ABSPATH' ) || exit;

define( 'FINDABLE_VERSION', '1.0.0' );
define( 'FINDABLE_FILE', __FILE__ );
define( 'FINDABLE_DIR', plugin_dir_path( __FILE__ ) );
define( 'FINDABLE_DEFAULT_ENDPOINT', 'https://findable.page' );

require_once FINDABLE_DIR . 'includes/class-result.php';
require_once FINDABLE_DIR . 'includes/class-fix-advice.php';
require_once FINDABLE_DIR . 'includes/class-api-client.php';
require_once FINDABLE_DIR . 'includes/class-store.php';
require_once FINDABLE_DIR . 'includes/class-settings.php';
require_once FINDABLE_DIR . 'includes/class-rest.php';
require_once FINDABLE_DIR . 'includes/class-bulk-scan.php';
require_once FINDABLE_DIR . 'includes/class-admin.php';
require_once FINDABLE_DIR . 'includes/class-migrate.php';
require_once FINDABLE_DIR . 'includes/class-plugin.php';

findable_boot();

/**
 * Boot the plugin hooks once. Prefix-guarded instead of a closure so the
 * bootstrap stays a single function call.
 */
function findable_boot() {
	Findable\Plugin::instance()->boot();
}
