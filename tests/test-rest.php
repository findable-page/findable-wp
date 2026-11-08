<?php
/**
 * REST routes: registration, permissions, scan and result behaviour.
 *
 * @package findable-wp
 */

use Findable\Rest;
use Findable\Result;
use Findable\Store;

class Test_Rest extends Findable_TestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'findable_settings' );
		rest_get_server(); // Fires rest_api_init, where the plugin registers routes.
	}

	private function dispatch( $method, $path, $body = array() ) {
		$request = new WP_REST_Request( $method, $path );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_every_route_has_permission_callback() {
		$routes = rest_get_server()->get_routes( Rest::NS );
		$this->assertNotEmpty( $routes );

		foreach ( $routes as $route => $handlers ) {
			if ( '/' . Rest::NS === $route ) {
				continue; // WordPress generates namespace index route, not plugin endpoint.
			}
			if ( is_array( $handlers ) && isset( $handlers['permission_callback'] ) ) {
				$handlers = array( $handlers );
			}
			foreach ( $handlers as $handler ) {
				$this->assertArrayHasKey( 'permission_callback', $handler, "Route {$route} lacks permission_callback" );
				$this->assertIsCallable( $handler['permission_callback'], "Route {$route} permission_callback not callable" );
				$this->assertNotSame( '__return_true', $handler['permission_callback'] );
			}
		}
	}

	public function test_anonymous_scan_is_forbidden() {
		wp_set_current_user( 0 );
		$post_id = $this->create_scannable_post();

		$response = $this->dispatch( 'POST', "/findable/v1/posts/{$post_id}/scan" );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_non_numeric_id_404s() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = $this->dispatch( 'POST', '/findable/v1/posts/abc/scan' );
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Permission matrix across roles for every route.
	 *
	 * @dataProvider role_provider
	 */
	public function test_permissions_per_role( $role, $scan_allowed, $result_allowed, $bulk_allowed, $connection_allowed ) {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		// Scan the author's post: only users who can edit that post pass.
		$response = $this->dispatch( 'POST', "/findable/v1/posts/{$post_id}/scan" );
		$this->assertSame( $scan_allowed ? 200 : 403, $response->get_status(), "scan as {$role}" );

		$response = $this->dispatch( 'GET', "/findable/v1/posts/{$post_id}/result" );
		$this->assertSame( $result_allowed ? 200 : 403, $response->get_status(), "result as {$role}" );

		$response = $this->dispatch( 'GET', '/findable/v1/bulk' );
		$this->assertSame( $bulk_allowed ? 200 : 403, $response->get_status(), "bulk as {$role}" );

		$response = $this->dispatch( 'POST', '/findable/v1/connection' );
		$this->assertSame( $connection_allowed ? 200 : 403, $response->get_status(), "connection as {$role}" );
	}

	public function role_provider() {
		// role, scan+result (edit_post on author's post), bulk, connection (manage_options).
		return array(
			'subscriber'    => array( 'subscriber', false, false, false, false ),
			'contributor'   => array( 'contributor', false, false, false, false ),
			'author'        => array( 'author', false, false, false, false ),
			'editor'        => array( 'editor', true, true, false, false ),
			'administrator' => array( 'administrator', true, true, true, true ),
		);
	}

	public function test_author_scans_own_post() {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( $author_id );

		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ), $calls );

		$response = $this->dispatch( 'POST', "/findable/v1/posts/{$post_id}/scan", array( 'keyphrase' => 'grinder' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $calls );
		$data = $response->get_data();
		$this->assertSame( 72, $data['result']['overall'] );
		$this->assertSame( 'grinder', $data['keyphrase'] );
		$this->assertSame( 72, (int) get_post_meta( $post_id, '_findable_overall', true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_id, '_findable_scanned_at', true ) );
	}

	public function test_double_click_returns_too_soon() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_scannable_post();
		wp_set_current_user( $admin_id );

		// Prime the lock as if a scan happened 3 seconds ago.
		set_transient( 'findable_scan_lock_' . $post_id, 1, 10 );
		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ), $calls );

		$response = $this->dispatch( 'POST', "/findable/v1/posts/{$post_id}/scan" );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'findable_too_soon', $response->get_data()['code'] ?? '' );
		$this->assertCount( 0, $calls );
	}

	public function test_api_failure_returns_old_result_plus_error() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_scannable_post();
		wp_set_current_user( $admin_id );

		Store::save( $post_id, Result::normalize( $this->scan_response() ) );
		$this->stub_http( $this->http_response( 500, wp_json_encode( array( 'error' => 'audit failed' ) ) ) );

		$response = $this->dispatch( 'POST', "/findable/v1/posts/{$post_id}/scan" );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 72, $data['result']['overall'] );
		$this->assertSame( 'findable_server', $data['error']['code'] );
		$this->assertNotNull( Store::get_error( $post_id ) );
	}

	public function test_scan_draft_returns_not_scannable() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_set_current_user( $admin_id );

		$this->stub_http( $this->http_response( 200, '{}' ), $calls );

		$response = $this->dispatch( 'POST', "/findable/v1/posts/{$draft_id}/scan" );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'findable_not_scannable', $response->as_error()->get_error_code() );
		$this->assertCount( 0, $calls );
	}

	public function test_result_route_never_scanned() {
		$author_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id   = $this->create_scannable_post();
		wp_set_current_user( $author_id );

		$response = $this->dispatch( 'GET', "/findable/v1/posts/{$post_id}/result" );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $data['result'] );
		$this->assertTrue( $data['scannable'] );
		$this->assertNull( $data['reason'] );
	}

	public function test_result_route_includes_advice_for_failing_checks() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id   = $this->create_scannable_post();
		wp_set_current_user( $editor_id );
		Store::save( $post_id, Result::normalize( $this->scan_response() ) );

		$data = $this->dispatch( 'GET', "/findable/v1/posts/{$post_id}/result" )->get_data();

		$this->assertArrayHasKey( 'title_length', $data['advice'] );
		$this->assertSame( 'Keep the title between 30 and 60 characters so search engines do not truncate it.', $data['advice']['title_length'] );
		$this->assertSame( 'https://findable.page/example.com/hello', $data['report_url'] );
	}

	public function test_connection_route_reports_health() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->stub_http(
			$this->http_response(
				200,
				wp_json_encode(
					array(
						'status'  => 'ok',
						'name'    => 'findable',
						'version' => '0.3.0',
					)
				)
			)
		);

		$data = $this->dispatch( 'POST', '/findable/v1/connection' )->get_data();

		$this->assertTrue( $data['connected'] );
		$this->assertSame( 'Connected to findable 0.3.0.', $data['message'] );
	}

	public function test_connection_route_reports_failure() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->stub_http( $this->http_response( 502, 'bad gateway' ) );

		$data = $this->dispatch( 'POST', '/findable/v1/connection' )->get_data();

		$this->assertFalse( $data['connected'] );
		$this->assertStringStartsWith( 'Could not reach the endpoint:', $data['message'] );
	}
}
