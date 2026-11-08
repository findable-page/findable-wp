<?php
/**
 * Admin UI: menus, assets, meta box, scan handler, list table, dashboard, settings.
 *
 * @package findable-wp
 */

use Findable\Admin;
use Findable\Bulk_Scan;
use Findable\Migrate;
use Findable\Result;
use Findable\Settings;
use Findable\Store;

class Test_Admin extends Findable_TestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'findable_settings' );
		delete_option( 'findable_bulk' );
		delete_option( 'findable_db_version' );
		delete_transient( 'findable_dashboard' );
		wp_clear_scheduled_hook( Bulk_Scan::TICK );
		$_GET  = array();
		$_POST = array();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		unset( $GLOBALS['pagenow'] );
		wp_clear_scheduled_hook( Bulk_Scan::TICK );
		parent::tearDown();
	}

	/**
	 * Make wp_redirect throw so code ending in exit can be asserted.
	 */
	private function trap_redirect() {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Exception( 'redirect:' . $location );
			}
		);
	}

	private function render( $callable, $args = array() ) {
		ob_start();
		call_user_func_array( $callable, $args );
		return ob_get_clean();
	}

	private function scanned_post( $overall = 72, $post_type = 'post' ) {
		$post_id = $this->create_scannable_post( $post_type );
		Store::save( $post_id, Result::normalize( $this->scan_response( array( 'overall' => $overall ) ) ) );
		return $post_id;
	}

	// ---------------------------------------------------------------- init / menu.

	public function test_init_registers_hooks() {
		Admin::init();
		$this->assertNotFalse( has_action( 'admin_menu', array( Admin::class, 'admin_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( Admin::class, 'admin_init' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_findable_scan', array( Admin::class, 'handle_scan_post' ) ) );
		$this->assertNotFalse( has_action( 'pre_get_posts', array( Admin::class, 'orderby_score' ) ) );
	}

	public function test_admin_menu_registers_pages() {
		global $submenu;
		Admin::admin_menu();
		$slugs = wp_list_pluck( $submenu['findable-dashboard'], 2 );
		$this->assertContains( 'findable-dashboard', $slugs );
		$this->assertContains( 'findable-settings', $slugs );
	}

	public function test_admin_init_registers_setting() {
		Admin::admin_init();
		$this->assertArrayHasKey( 'findable_settings', get_registered_settings() );
	}

	public function test_dismiss_notice_requires_nonce() {
		$this->trap_redirect();
		$_GET['findable_dismiss_fix_notice'] = '1';
		try {
			Admin::admin_init();
			$this->fail( 'Expected redirect.' );
		} catch ( Exception $e ) {
			$this->assertStringStartsWith( 'redirect:', $e->getMessage() );
		}
		$this->assertFalse( Migrate::fix_notice_dismissed() );
	}

	public function test_dismiss_notice_with_valid_nonce() {
		$this->trap_redirect();
		$_GET['findable_dismiss_fix_notice'] = '1';
		$_GET['_wpnonce']                    = wp_create_nonce( 'findable_dismiss_fix_notice' );
		try {
			Admin::admin_init();
		} catch ( Exception $e ) {
			$this->assertStringStartsWith( 'redirect:', $e->getMessage() );
		}
		$this->assertTrue( Migrate::fix_notice_dismissed() );
	}

	// ---------------------------------------------------------------- assets.

	public function test_enqueue_assets_dashboard_and_settings() {
		foreach ( array( 'toplevel_page_findable-dashboard', 'findable_page_findable-settings' ) as $hook ) {
			wp_dequeue_script( 'findable-admin' );
			wp_deregister_script( 'findable-admin' );
			Admin::enqueue_assets( $hook );
			$this->assertTrue( wp_script_is( 'findable-admin', 'enqueued' ), $hook );
			$data = wp_scripts()->get_data( 'findable-admin', 'data' );
			$this->assertStringContainsString( 'restNonce', $data );
			$this->assertStringContainsString( '"testing"', $data );
		}
	}

	public function test_enqueue_assets_block_editor_sidebar() {
		set_current_screen( 'post' );
		$GLOBALS['current_screen']->post_type = 'post';
		Admin::enqueue_assets( 'post.php' );
		$this->assertTrue( wp_script_is( 'findable-editor', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'findable-editor', 'enqueued' ) );
	}

	public function test_enqueue_assets_editor_uses_asset_file_when_built() {
		$asset = FINDABLE_DIR . 'build/index.asset.php';
		$built = file_exists( $asset );
		if ( ! $built ) {
			wp_mkdir_p( dirname( $asset ) );
			file_put_contents( $asset, "<?php return array( 'dependencies' => array( 'wp-element' ), 'version' => 'abc123' );" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		set_current_screen( 'post' );
		$GLOBALS['current_screen']->post_type = 'post';
		Admin::enqueue_assets( 'post.php' );
		$this->assertTrue( wp_script_is( 'findable-editor', 'enqueued' ) );
		if ( ! $built ) {
			unlink( $asset ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	public function test_enqueue_assets_editor_fallback_without_build_file() {
		$asset  = FINDABLE_DIR . 'build/index.asset.php';
		$backup = null;
		if ( file_exists( $asset ) ) {
			$backup = file_get_contents( $asset ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			unlink( $asset ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		set_current_screen( 'post' );
		$GLOBALS['current_screen']->post_type = 'post';
		wp_dequeue_script( 'findable-editor' );
		wp_deregister_script( 'findable-editor' );
		Admin::enqueue_assets( 'post.php' );
		$this->assertSame( FINDABLE_VERSION, wp_scripts()->registered['findable-editor']->ver );
		if ( null !== $backup ) {
			file_put_contents( $asset, $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	public function test_enqueue_assets_classic_post_type() {
		register_post_type( 'findable_classic', array( 'public' => true, 'show_in_rest' => false ) );
		update_option( 'findable_settings', array( 'post_types' => array( 'findable_classic' ) ), false );
		set_current_screen( 'post' );
		$GLOBALS['current_screen']->post_type = 'findable_classic';
		wp_dequeue_script( 'findable-admin' );
		wp_deregister_script( 'findable-admin' );
		wp_dequeue_script( 'findable-editor' );

		Admin::enqueue_assets( 'post.php' );

		$this->assertFalse( wp_script_is( 'findable-editor', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'findable-admin', 'enqueued' ) );
		$this->assertStringContainsString( 'scanUrl', wp_scripts()->get_data( 'findable-admin', 'data' ) );
		unregister_post_type( 'findable_classic' );
	}

	public function test_enqueue_assets_ignores_other_screens() {
		set_current_screen( 'dashboard' );
		wp_dequeue_script( 'findable-editor' );
		Admin::enqueue_assets( 'index.php' );
		$this->assertFalse( wp_script_is( 'findable-editor', 'enqueued' ) );
	}

	public function test_enqueue_assets_without_screen() {
		unset( $GLOBALS['current_screen'] );
		wp_dequeue_script( 'findable-editor' );
		Admin::enqueue_assets( 'index.php' );
		$this->assertFalse( wp_script_is( 'findable-editor', 'enqueued' ) );
	}

	// ---------------------------------------------------------------- meta box.

	public function test_meta_box_only_for_classic_types() {
		global $wp_meta_boxes;
		register_post_type( 'findable_classic', array( 'public' => true, 'show_in_rest' => false ) );
		update_option( 'findable_settings', array( 'post_types' => array( 'post', 'findable_classic' ) ), false );
		set_current_screen( 'findable_classic' );
		$wp_meta_boxes = array();

		Admin::add_meta_boxes();

		$this->assertArrayHasKey( 'findable', $wp_meta_boxes['findable_classic']['side']['default'] );
		$this->assertArrayNotHasKey( 'post', $wp_meta_boxes );
		unregister_post_type( 'findable_classic' );
	}

	public function test_meta_box_never_scanned_draft() {
		$post = get_post( self::factory()->post->create( array( 'post_status' => 'draft' ) ) );
		$html = $this->render( array( Admin::class, 'render_meta_box' ), array( $post ) );
		$this->assertStringContainsString( 'Not scanned yet.', $html );
		$this->assertStringContainsString( 'findable-warning', $html );
		$this->assertStringContainsString( 'findable_scan_nonce', $html );
		$this->assertStringContainsString( 'name="findable_post_id"', $html );
		$this->assertStringContainsString( 'id="findable_scan"', $html );
	}

	public function test_meta_box_with_fresh_result() {
		$post_id = $this->scanned_post();
		update_post_meta( $post_id, '_findable_keyphrase', 'coffee' );
		$html = $this->render( array( Admin::class, 'render_meta_box' ), array( get_post( $post_id ) ) );
		$this->assertStringContainsString( 'findable-band-needs-work', str_replace( '_', '-', $html ) );
		$this->assertStringContainsString( 'SEO 80', $html );
		$this->assertStringContainsString( 'Failing checks (3)', $html );
		$this->assertStringContainsString( 'Passing checks (', $html );
		$this->assertStringContainsString( 'value="coffee"', $html );
		$this->assertStringNotContainsString( '(stale)', $html );
		$this->assertStringContainsString( 'https://findable.page/example.com/hello', $html );
	}

	public function test_meta_box_stale_and_error() {
		$post_id = $this->scanned_post();
		update_post_meta( $post_id, '_findable_scanned_at', time() - ( 30 * DAY_IN_SECONDS ) );
		$result               = Store::get( $post_id );
		$result['scanned_at'] = time() - ( 30 * DAY_IN_SECONDS );
		update_post_meta( $post_id, '_findable_result', $result );
		Store::save_error( $post_id, new WP_Error( 'findable_http', 'Boom happened' ) );

		$html = $this->render( array( Admin::class, 'render_meta_box' ), array( get_post( $post_id ) ) );

		$this->assertStringContainsString( '(stale)', $html );
		$this->assertStringContainsString( 'Boom happened', $html );
	}

	public function test_meta_box_legacy_result_without_checks() {
		$post_id = $this->create_scannable_post();
		$legacy  = Migrate::convert( array( 'overall' => 40, 'seo' => 40, 'aeo' => 40, 'geo' => 40, 'failing' => array() ) );
		update_post_meta( $post_id, '_findable_result', $legacy );
		update_post_meta( $post_id, '_findable_scanned_at', time() );

		$html = $this->render( array( Admin::class, 'render_meta_box' ), array( get_post( $post_id ) ) );

		$this->assertStringContainsString( 'Imported from findable 0.2', $html );
		$this->assertStringNotContainsString( 'findable-checks', $html );
	}

	public function test_meta_box_all_checks_pass_message() {
		$post_id = $this->create_scannable_post();
		$result  = Result::normalize( $this->scan_response() );
		foreach ( $result['checks'] as $i => $check ) {
			$result['checks'][ $i ]['s'] = 'pass';
		}
		update_post_meta( $post_id, '_findable_result', $result );

		$html = $this->render( array( Admin::class, 'render_meta_box' ), array( get_post( $post_id ) ) );

		$this->assertStringContainsString( 'No failing checks. Nice!', $html );
	}

	public function test_meta_box_only_failing_checks_has_no_passing_details() {
		$post_id = $this->create_scannable_post();
		$result  = Result::normalize( $this->scan_response() );
		foreach ( $result['checks'] as $i => $check ) {
			$result['checks'][ $i ]['s'] = 'fail';
		}
		update_post_meta( $post_id, '_findable_result', $result );

		$html = $this->render( array( Admin::class, 'render_meta_box' ), array( get_post( $post_id ) ) );

		$this->assertStringNotContainsString( '<details>', $html );
	}

	// ---------------------------------------------------------------- scan handler.

	private function scan_request( $post_id, $extra = array() ) {
		$_POST = array_merge(
			array(
				'post'                => (string) $post_id,
				'findable_scan_nonce' => wp_create_nonce( 'findable_scan_' . $post_id ),
			),
			$extra
		);
		$_REQUEST = $_POST;
	}

	private function run_scan_handler() {
		$this->trap_redirect();
		try {
			Admin::handle_scan_post();
		} catch ( Exception $e ) {
			return $e->getMessage();
		}
		$this->fail( 'Expected a redirect.' );
	}

	public function test_scan_handler_rejects_missing_post() {
		$this->expectException( WPDieException::class );
		Admin::handle_scan_post();
	}

	public function test_scan_handler_requires_edit_capability() {
		$post_id = $this->create_scannable_post();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->scan_request( $post_id );
		$this->expectException( WPDieException::class );
		Admin::handle_scan_post();
	}

	public function test_scan_handler_success_redirects_with_done_flag() {
		$post_id = $this->create_scannable_post();
		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ), $calls );
		$this->scan_request( $post_id, array( 'findable_keyphrase' => ' coffee ' ) );
		$_SERVER['HTTP_REFERER'] = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

		$location = $this->run_scan_handler();

		$this->assertStringContainsString( 'findable_scan_done=1', $location );
		$this->assertStringContainsString( 'post=' . $post_id, $location );
		$this->assertSame( 'coffee', get_post_meta( $post_id, '_findable_keyphrase', true ) );
		$this->assertCount( 1, $calls );
		unset( $_SERVER['HTTP_REFERER'] );
	}

	public function test_scan_handler_api_error_stores_notice_server_side() {
		$post_id = $this->create_scannable_post();
		$this->stub_http( $this->http_response( 500, wp_json_encode( array( 'error' => 'audit failed' ) ) ) );
		$this->scan_request( $post_id );

		$location = $this->run_scan_handler();

		$this->assertStringContainsString( 'findable_scan_error=1', $location );
		$this->assertStringNotContainsString( 'audit', $location );
		$this->assertSame( 'audit failed', get_transient( 'findable_scan_notice_' . get_current_user_id() . '_' . $post_id ) );
	}

	public function test_scan_handler_unscannable_post_reports_error_and_uses_edit_link_fallback() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->scan_request( $post_id );

		$location = $this->run_scan_handler();

		$this->assertStringContainsString( 'findable_scan_error=1', $location );
		$this->assertStringContainsString( 'post.php', $location );
	}

	public function test_scan_handler_falls_back_to_posts_list_without_edit_link() {
		$post_id = $this->create_scannable_post();
		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ) );
		$this->scan_request( $post_id );
		add_filter( 'get_edit_post_link', '__return_empty_string' );

		$location = $this->run_scan_handler();

		$this->assertStringContainsString( 'edit.php', $location );
	}

	// ---------------------------------------------------------------- notices.

	public function test_edit_notices_ignores_other_screens() {
		set_current_screen( 'dashboard' );
		$_GET['findable_scan_done'] = '1';
		$this->assertSame( '', $this->render( array( Admin::class, 'edit_notices' ) ) );
		unset( $GLOBALS['current_screen'] );
		$this->assertSame( '', $this->render( array( Admin::class, 'edit_notices' ) ) );
	}

	public function test_edit_notices_success() {
		set_current_screen( 'post' );
		$_GET['findable_scan_done'] = '1';
		$this->assertStringContainsString( 'Findable scan complete.', $this->render( array( Admin::class, 'edit_notices' ) ) );
	}

	public function test_edit_notices_error_reads_transient_once() {
		set_current_screen( 'post' );
		$_GET['findable_scan_error'] = '1';
		$_GET['post']                = '55';
		set_transient( 'findable_scan_notice_' . get_current_user_id() . '_55', 'Nope <b>x</b>' );

		$first = $this->render( array( Admin::class, 'edit_notices' ) );
		$again = $this->render( array( Admin::class, 'edit_notices' ) );

		$this->assertStringContainsString( 'notice-error', $first );
		$this->assertStringContainsString( 'Nope &lt;b&gt;x&lt;/b&gt;', $first );
		$this->assertSame( '', $again );
	}

	public function test_edit_notices_error_without_post_id_prints_nothing() {
		set_current_screen( 'post' );
		$_GET['findable_scan_error'] = '1';
		$this->assertSame( '', $this->render( array( Admin::class, 'edit_notices' ) ) );
	}

	// ---------------------------------------------------------------- list table.

	public function test_score_html_bands() {
		$this->assertStringContainsString( 'findable-band-good', Admin::score_html( 90 ) );
		$this->assertStringContainsString( 'findable-band-needs_work', Admin::score_html( 60 ) );
		$this->assertStringContainsString( 'findable-band-poor', Admin::score_html( 10 ) );
	}

	public function test_list_columns_registered_for_enabled_types() {
		Admin::add_list_columns();
		$this->assertNotFalse( has_filter( 'manage_post_posts_columns', array( Admin::class, 'posts_columns' ) ) );
		$this->assertNotFalse( has_filter( 'manage_edit-page_sortable_columns', array( Admin::class, 'sortable_column' ) ) );
		$this->assertSame( 'Findable', Admin::posts_columns( array() )['findable'] );
		$this->assertSame( array( 'findable_score', false ), Admin::sortable_column( array() )['findable'] );
	}

	public function test_posts_column_variants() {
		$this->assertSame( '', $this->render( array( Admin::class, 'posts_column' ), array( 'title', 1 ) ) );

		$unscanned = $this->create_scannable_post();
		$this->assertStringContainsString( '&mdash;', $this->render( array( Admin::class, 'posts_column' ), array( 'findable', $unscanned ) ) );

		$fresh = $this->scanned_post();
		$html  = $this->render( array( Admin::class, 'posts_column' ), array( 'findable', $fresh ) );
		$this->assertStringContainsString( '72', $html );
		$this->assertStringNotContainsString( '(stale)', $html );

		$result               = Store::get( $fresh );
		$result['scanned_at'] = time() - ( 30 * DAY_IN_SECONDS );
		update_post_meta( $fresh, '_findable_result', $result );
		$this->assertStringContainsString( '(stale)', $this->render( array( Admin::class, 'posts_column' ), array( 'findable', $fresh ) ) );
	}

	private function admin_list_query( $vars ) {
		set_current_screen( 'edit-post' );
		$GLOBALS['pagenow'] = 'edit.php';
		Admin::init();
		$query                    = new WP_Query();
		$GLOBALS['wp_the_query']  = $query;
		$GLOBALS['wp_query']      = $query;
		$query->query( array_merge( array( 'post_type' => 'post', 'fields' => 'ids', 'posts_per_page' => -1 ), $vars ) );
		return $query;
	}

	public function test_orderby_score_sorts_unscanned_last_both_directions() {
		$low  = $this->scanned_post( 20 );
		$high = $this->scanned_post( 95 );
		$none = $this->create_scannable_post();

		$desc = $this->admin_list_query( array( 'orderby' => 'findable_score', 'order' => 'DESC' ) );
		$this->assertSame( array( $high, $low, $none ), array_map( 'intval', $desc->posts ) );

		$asc = $this->admin_list_query( array( 'orderby' => 'findable_score', 'order' => 'ASC' ) );
		$this->assertSame( array( $low, $high, $none ), array_map( 'intval', $asc->posts ) );
	}

	public function test_orderby_score_preserves_existing_meta_query() {
		$a = $this->scanned_post( 20 );
		$b = $this->scanned_post( 95 );
		update_post_meta( $a, 'tag', 'x' );

		$query = $this->admin_list_query(
			array(
				'orderby'    => 'findable_score',
				'meta_query' => array( array( 'key' => 'tag', 'value' => 'x' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$this->assertSame( array( $a ), array_map( 'intval', $query->posts ) );
		$this->assertNotContains( $b, array_map( 'intval', $query->posts ) );
	}

	public function test_orderby_score_ignores_other_queries() {
		$query = $this->admin_list_query( array( 'orderby' => 'date' ) );
		$this->assertSame( 'date', $query->get( 'orderby' ) );

		$secondary = new WP_Query();
		$secondary->query( array( 'orderby' => 'findable_score', 'post_type' => 'post' ) );
		$this->assertSame( 'findable_score', $secondary->get( 'orderby' ) );
	}

	public function test_filter_by_failing_limits_list() {
		$scanned   = $this->scanned_post();
		$unscanned = $this->create_scannable_post();
		$_GET['findable_failing'] = 'title_length';

		$query = $this->admin_list_query( array() );

		$ids = array_map( 'intval', $query->posts );
		$this->assertContains( $scanned, $ids );
		$this->assertNotContains( $unscanned, $ids );
	}

	public function test_filter_by_failing_ignores_invalid_empty_and_other_screens() {
		$scanned = $this->scanned_post();
		$none    = $this->create_scannable_post();

		$_GET['findable_failing'] = 'Not Valid!';
		$ids                      = array_map( 'intval', $this->admin_list_query( array() )->posts );
		$this->assertContains( $none, $ids );

		$_GET['findable_failing'] = '';
		$ids                      = array_map( 'intval', $this->admin_list_query( array() )->posts );
		$this->assertContains( $none, $ids );

		$_GET['findable_failing'] = 'title_length';
		$query                    = new WP_Query();
		$GLOBALS['pagenow']       = 'index.php';
		Admin::filter_by_failing( $query );
		$this->assertSame( '', $query->get( 'meta_query' ) );
		$this->assertContains( $scanned, array( $scanned ) );
	}

	// ---------------------------------------------------------------- cron hint.

	public function test_cron_hint_healthy_is_empty() {
		add_filter( 'findable_cron_disabled', '__return_false' );
		$this->assertSame( '', Admin::cron_hint() );
	}

	public function test_cron_hint_disabled_and_stalled() {
		add_filter( 'findable_cron_disabled', '__return_true' );
		$this->assertStringContainsString( 'WP-Cron is disabled', Admin::cron_hint() );
		remove_all_filters( 'findable_cron_disabled' );

		add_filter( 'findable_cron_disabled', '__return_false' );
		update_option(
			'findable_bulk',
			array_merge( Bulk_Scan::default_state(), array( 'status' => 'running' ) ),
			false
		);
		wp_schedule_single_event( time() - ( 20 * MINUTE_IN_SECONDS ), Bulk_Scan::TICK );
		$this->assertStringContainsString( 'looks stalled', Admin::cron_hint() );
	}

	// ---------------------------------------------------------------- dashboard.

	public function test_dashboard_forbidden_for_contributor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->expectException( WPDieException::class );
		Admin::render_dashboard();
	}

	public function test_dashboard_empty_state_shows_bulk_panel_for_admin_only() {
		add_filter( 'findable_cron_disabled', '__return_false' );
		$html = $this->render( array( Admin::class, 'render_dashboard' ) );
		$this->assertStringContainsString( 'No posts scanned yet.', $html );
		$this->assertStringContainsString( 'findable-bulk-panel', $html );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$html = $this->render( array( Admin::class, 'render_dashboard' ) );
		$this->assertStringContainsString( 'No posts scanned yet.', $html );
		$this->assertStringNotContainsString( 'findable-bulk-panel', $html );
	}

	public function test_dashboard_with_data() {
		add_filter( 'findable_cron_disabled', '__return_false' );
		$this->scanned_post( 90 );
		$this->scanned_post( 55 );
		$this->scanned_post( 30 );
		$this->create_scannable_post();

		$html = $this->render( array( Admin::class, 'render_dashboard' ) );

		$this->assertStringContainsString( '3 of 4 scannable posts scanned', $html );
		$this->assertStringContainsString( 'Score distribution', $html );
		$this->assertStringContainsString( 'Weakest posts', $html );
		$this->assertStringContainsString( 'Most common failing checks', $html );
		$this->assertStringContainsString( 'findable_failing=', $html );
		$this->assertStringContainsString( 'findable-bulk-panel', $html );
	}

	public function test_dashboard_editor_sees_no_bulk_panel_and_missing_post_row_is_skipped() {
		$post_id = $this->scanned_post( 40 );
		$agg     = Store::aggregate( true );
		$agg['weakest'][] = array( 'id' => 999999, 'score' => 1, 'at' => time(), 'failing' => 1 );
		set_transient( 'findable_dashboard', $agg, HOUR_IN_SECONDS );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$html = $this->render( array( Admin::class, 'render_dashboard' ) );

		$this->assertStringNotContainsString( 'findable-bulk-panel', $html );
		$this->assertStringNotContainsString( '999999', $html );
		$this->assertStringContainsString( 'post.php?post=' . $post_id, $html );
	}

	public function test_dashboard_row_without_edit_link_is_plain_text() {
		$this->scanned_post( 40 );
		add_filter( 'get_edit_post_link', '__return_empty_string' );
		$html = $this->render( array( Admin::class, 'render_dashboard' ) );
		$this->assertStringNotContainsString( 'post.php?post=', $html );
		$this->assertStringContainsString( 'Hello', $html );
	}

	public function test_dashboard_shows_fix_all_notice_until_dismissed() {
		$post_id = $this->create_scannable_post();
		update_post_meta( $post_id, '_findable_fixes', array( 'description' ) );

		$html = $this->render( array( Admin::class, 'render_dashboard' ) );
		$this->assertStringContainsString( 'Fix All feature was removed', $html );
		$this->assertStringContainsString( 'findable_dismiss_fix_notice', $html );

		Migrate::dismiss_fix_notice();
		$this->assertStringNotContainsString( 'Fix All feature was removed', $this->render( array( Admin::class, 'render_dashboard' ) ) );
	}

	// ---------------------------------------------------------------- bulk panel text.

	public function test_bulk_status_text_for_every_state() {
		$base = Bulk_Scan::default_state();
		$this->assertSame( 'Idle.', Admin::bulk_status_text( $base ) );
		$this->assertSame( 'Running: 3 of 10 processed.', Admin::bulk_status_text( array_merge( $base, array( 'status' => 'running', 'done' => 2, 'failed' => 1, 'total' => 10 ) ) ) );
		$this->assertSame( 'Paused at 3 of 10.', Admin::bulk_status_text( array_merge( $base, array( 'status' => 'paused', 'done' => 2, 'failed' => 1, 'total' => 10 ) ) ) );
		$this->assertSame( 'Bulk scan finished: 8 scanned, 2 failed.', Admin::bulk_status_text( array_merge( $base, array( 'status' => 'done', 'done' => 8, 'failed' => 2 ) ) ) );
		$this->assertSame( 'Bulk scan cancelled.', Admin::bulk_status_text( array_merge( $base, array( 'status' => 'cancelled' ) ) ) );
	}

	public function test_bulk_eta_text_uses_pace() {
		update_option( 'findable_settings', array( 'bulk_per_minute' => 5 ), false );
		$state = array_merge( Bulk_Scan::default_state(), array( 'total' => 11, 'done' => 1 ) );
		$this->assertSame( 'About 2 minutes remaining.', Admin::bulk_eta_text( $state ) );
		$state['total'] = 6;
		$this->assertSame( 'About 1 minute remaining.', Admin::bulk_eta_text( $state ) );
		$state['done'] = 99;
		$this->assertSame( 'About 0 minutes remaining.', Admin::bulk_eta_text( $state ) );
	}

	public function test_bulk_panel_paused_with_error_shows_resume_inline() {
		add_filter( 'findable_cron_disabled', '__return_false' );
		update_option(
			'findable_bulk',
			array_merge( Bulk_Scan::default_state(), array( 'status' => 'paused', 'last_error' => 'API down', 'total' => 4 ) ),
			false
		);
		$html = $this->render( array( Admin::class, 'render_dashboard' ) );
		$this->assertStringContainsString( 'findable-bulk-resume', $html );
		$this->assertStringContainsString( 'API down', $html );
		$this->assertSame( 1, substr_count( $html, 'API down' ) );
	}

	public function test_bulk_panel_running_disables_start_buttons_and_shows_error_once() {
		add_filter( 'findable_cron_disabled', '__return_false' );
		update_option(
			'findable_bulk',
			array_merge( Bulk_Scan::default_state(), array( 'status' => 'running', 'last_error' => 'Slow down', 'total' => 4 ) ),
			false
		);
		$html = $this->render( array( Admin::class, 'render_dashboard' ) );
		$this->assertMatchesRegularExpression( '/id="findable-bulk-start"[^>]* disabled/', $html );
		$this->assertSame( 1, substr_count( $html, 'Slow down' ) );
		$this->assertStringContainsString( 'data-running="1"', $html );
	}

	// ---------------------------------------------------------------- settings page.

	public function test_settings_page_forbidden_without_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( WPDieException::class );
		Admin::render_settings();
	}

	public function test_settings_page_renders_all_fields() {
		Admin::admin_init();
		Admin::register_settings_ui();
		update_option( 'findable_api_key', 'abc', false );
		$html = $this->render( array( Admin::class, 'render_settings' ) );

		foreach ( array( 'findable-endpoint', 'findable-api-key', 'findable_settings[post_types][]', 'cache_ttl_days', 'bulk_per_minute', 'scan_on_publish', 'delete_data_on_uninstall', 'findable_settings[_form]', 'findable-test-connection' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, $needle );
		}
		$this->assertStringContainsString( 'A key is saved.', $html );
	}

	public function test_endpoint_field_warns_about_unreachable_port() {
		update_option( 'findable_settings', array( 'endpoint' => 'https://scan.example.com:8443' ), false );
		$html = $this->render( array( Admin::class, 'render_field_endpoint' ) );
		$this->assertStringContainsString( 'Only ports 80, 443 and 8080', $html );

		update_option( 'findable_settings', array( 'endpoint' => 'https://findable.page' ), false );
		$this->assertStringNotContainsString( 'Only ports', $this->render( array( Admin::class, 'render_field_endpoint' ) ) );
	}

	public function test_api_key_field_without_saved_key() {
		delete_option( 'findable_api_key' );
		$html = $this->render( array( Admin::class, 'render_field_api_key' ) );
		$this->assertStringNotContainsString( 'A key is saved.', $html );
		$this->assertStringContainsString( 'findable_remove_key', $html );
	}

	public function test_checkbox_fields_reflect_saved_values() {
		update_option( 'findable_settings', array( 'scan_on_publish' => true, 'delete_data_on_uninstall' => true, 'post_types' => array( 'page' ) ), false );
		$this->assertStringContainsString( 'checked', $this->render( array( Admin::class, 'render_field_scan_on_publish' ) ) );
		$this->assertStringContainsString( 'checked', $this->render( array( Admin::class, 'render_field_delete_data_on_uninstall' ) ) );
		$html = $this->render( array( Admin::class, 'render_field_post_types' ) );
		$this->assertMatchesRegularExpression( '/value="page" checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="post" checked/', $html );
		$this->assertStringContainsString( 'value="7"', $this->render( array( Admin::class, 'render_field_cache_ttl_days' ) ) );
		$this->assertStringContainsString( 'value="5"', $this->render( array( Admin::class, 'render_field_bulk_per_minute' ) ) );
	}

	public function test_settings_page_shows_core_saved_notice_and_plugin_errors() {
		Admin::admin_init();
		Admin::register_settings_ui();
		add_settings_error( 'general', 'settings_updated', 'Settings saved.', 'success' );
		add_settings_error( 'findable_settings', 'findable_endpoint', 'Enter an http or https URL.' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		$_GET['settings-updated'] = 'true';

		$html = $this->render( array( Admin::class, 'render_settings' ) );

		$this->assertStringContainsString( 'Settings saved.', $html );
		$this->assertStringContainsString( 'Enter an http or https URL.', $html );
	}
}
