<?php
/**
 * Store: save/get, freshness, scannable rule, scan_post.
 *
 * @package findable-wp
 */

use Findable\Store;
use Findable\Result;

class Test_Store extends Findable_TestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'findable_settings' );
	}

	private function scan_and_save( $post_id ) {
		$result = Result::normalize( $this->scan_response() );
		Store::save( $post_id, $result );
		return $result;
	}

	public function test_save_and_get_roundtrip() {
		$post_id = $this->create_scannable_post();
		$result  = $this->scan_and_save( $post_id );

		$stored = Store::get( $post_id );
		$this->assertSame( $result['overall'], $stored['overall'] );
		$this->assertSame( 72, (int) get_post_meta( $post_id, '_findable_overall', true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_id, '_findable_scanned_at', true ) );
		$this->assertSame( ',', substr( (string) get_post_meta( $post_id, '_findable_failing_ids', true ), 0, 1 ) );
		$this->assertStringContainsString( 'title_length', (string) get_post_meta( $post_id, '_findable_failing_ids', true ) );
	}

	public function test_fresh_then_edited_post_is_stale() {
		$post_id = $this->create_scannable_post();
		$this->scan_and_save( $post_id );

		$this->assertTrue( Store::is_fresh( $post_id ) );

		// wp_update_post overwrites post_modified_gmt with current time; set a later timestamp directly.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 100 ) ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		$this->assertFalse( Store::is_fresh( $post_id ) );
	}

	public function test_ttl_expiry_makes_stale() {
		$post_id              = $this->create_scannable_post();
		$result               = $this->scan_and_save( $post_id );
		$result['scanned_at'] = time() - 8 * DAY_IN_SECONDS;
		update_post_meta( $post_id, '_findable_result', $result );
		// Post last changed before the scan, so only the TTL can make it stale.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS ) ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		$this->assertFalse( Store::is_fresh( $post_id ) );
		$result['scanned_at'] = time() - 6 * DAY_IN_SECONDS;
		$this->assertTrue( Store::is_fresh( $post_id, $result ) );
	}

	public function test_endpoint_change_makes_stale() {
		$post_id = $this->create_scannable_post();
		$this->scan_and_save( $post_id );
		$this->assertTrue( Store::is_fresh( $post_id ) );

		update_option( 'findable_settings', array( 'endpoint' => 'https://scan.example.com' ), false );
		$this->assertFalse( Store::is_fresh( $post_id ) );
	}

	public function test_draft_not_scannable() {
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertFalse( Store::is_scannable( $draft_id ) );
		$this->assertSame( 'draft', Store::scan_reason( $draft_id ) );
	}

	public function test_password_post_not_scannable() {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'pw',
			)
		);
		$this->assertFalse( Store::is_scannable( $post_id ) );
		$this->assertSame( 'password', Store::scan_reason( $post_id ) );
	}

	public function test_disabled_post_type_not_scannable() {
		register_post_type( 'findable_product', array( 'public' => true ) );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'findable_product',
				'post_status' => 'publish',
			)
		);
		$this->assertFalse( Store::is_scannable( $post_id ) );
		$this->assertSame( 'type', Store::scan_reason( $post_id ) );
		_unregister_post_type( 'findable_product' );
	}

	public function test_scan_post_refuses_draft_without_http() {
		$this->stub_http( $this->http_response( 200, '{}' ), $calls );
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$error = Store::scan_post( $draft_id, '' );

		$this->assertWPError( $error );
		$this->assertSame( 'findable_not_scannable', $error->get_error_code() );
		$this->assertCount( 0, $calls );
	}

	public function test_scan_post_saves_result_and_keyphrase() {
		$post_id = $this->create_scannable_post();
		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ), $calls );

		$scanned = Store::scan_post( $post_id, 'coffee grinder' );

		$this->assertNotWPError( $scanned );
		$this->assertNull( $scanned['error'] );
		$this->assertSame( 1, count( $calls ) );
		$this->assertSame( 'coffee grinder', get_post_meta( $post_id, '_findable_keyphrase', true ) );
		$this->assertSame( 72, (int) get_post_meta( $post_id, '_findable_overall', true ) );
	}

	public function test_scan_post_failure_keeps_old_result_and_saves_error() {
		$post_id = $this->create_scannable_post();
		$this->scan_and_save( $post_id ); // Old result from "yesterday".

		$this->stub_http( $this->http_response( 500, wp_json_encode( array( 'error' => 'audit failed' ) ) ) );

		$scanned = Store::scan_post( $post_id, '' );

		$this->assertNotNull( $scanned['error'] );
		$this->assertSame( 'findable_server', $scanned['error']['code'] );
		$this->assertSame( 72, (int) Store::get( $post_id )['overall'] );
		$this->assertSame( 'findable_server', Store::get_error( $post_id )['code'] );
	}

	public function test_result_deleting_post_removes_meta_via_core() {
		$post_id = $this->create_scannable_post();
		$this->scan_and_save( $post_id );
		wp_delete_post( $post_id, true );
		$this->assertNull( Store::get( $post_id ) );
	}

	public function test_meta_not_exposed_in_core_rest() {
		$post_id = $this->create_scannable_post();
		$this->scan_and_save( $post_id );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		foreach ( array_keys( $data ) as $key ) {
			$this->assertStringStartsNotWith( '_findable', (string) $key );
			$this->assertStringStartsNotWith( 'findable', (string) $key );
		}
		$this->assertArrayHasKey( 'meta', $data );
		foreach ( array_keys( $data['meta'] ) as $key ) {
			$this->assertStringStartsNotWith( '_findable', (string) $key );
			$this->assertStringStartsNotWith( 'findable', (string) $key );
		}
	}

	public function test_register_meta_registers_every_key_for_enabled_types() {
		Store::register_meta();
		foreach ( array( '_findable_result', '_findable_overall', '_findable_scanned_at', '_findable_keyphrase', '_findable_error', '_findable_failing_ids' ) as $key ) {
			$this->assertTrue( registered_meta_key_exists( 'post', $key, 'post' ), $key );
			$this->assertTrue( registered_meta_key_exists( 'post', $key, 'page' ), $key );
		}
	}

	public function test_missing_post_is_not_scannable_and_reason_is_gone() {
		$this->assertFalse( Store::is_scannable( 999999 ) );
		$this->assertSame( 'gone', Store::scan_reason( 999999 ) );
	}

	public function test_scan_reason_covers_type_draft_password_and_none() {
		$post_id = $this->create_scannable_post();
		$this->assertNull( Store::scan_reason( $post_id ) );

		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertSame( 'draft', Store::scan_reason( $draft ) );

		$locked = self::factory()->post->create( array( 'post_status' => 'publish', 'post_password' => 'pw' ) );
		$this->assertSame( 'password', Store::scan_reason( $locked ) );

		update_option( 'findable_settings', array( 'post_types' => array( 'page' ) ), false );
		$this->assertSame( 'type', Store::scan_reason( $post_id ) );
	}

	public function test_reason_messages() {
		$this->assertStringContainsString( 'Publish this post first', Store::reason_message( 'draft' ) );
		$this->assertStringContainsString( 'not publicly viewable', Store::reason_message( 'password' ) );
		$this->assertStringContainsString( 'not enabled', Store::reason_message( 'type' ) );
		$this->assertSame( 'This post cannot be scanned.', Store::reason_message( 'gone' ) );
		$this->assertSame( 'This post cannot be scanned.', Store::reason_message( null ) );
	}

	public function test_is_fresh_edge_cases() {
		$this->assertFalse( Store::is_fresh( 999999 ) );

		$post_id = $this->create_scannable_post();
		$result  = Result::normalize( $this->scan_response() );
		Store::save( $post_id, $result );
		$this->assertTrue( Store::is_fresh( $post_id ) );

		// Result without scanned_at is never fresh.
		$this->assertFalse( Store::is_fresh( $post_id, array_merge( $result, array( 'scanned_at' => 0 ) ) ) );

		// Result for a post that no longer exists.
		$this->assertFalse( Store::is_fresh( 999999, $result ) );

		// Result without an endpoint is stale.
		unset( $result['endpoint'] );
		$this->assertFalse( Store::is_fresh( $post_id, $result ) );
	}

	public function test_report_url_variants() {
		$this->assertSame( 'https://findable.page/example.com/a/b', Store::report_url( 'https://example.com/a/b/' ) );
		$this->assertSame( 'https://findable.page/example.com', Store::report_url( 'https://example.com' ) );
		$this->assertStringStartsWith( 'https://findable.page/', Store::report_url( '' ) );
	}

	public function test_no_enabled_post_types_means_nothing_scannable() {
		$this->create_scannable_post();
		update_option( 'findable_settings', array( '_x' => 1, 'post_types' => array() ), false );
		$this->assertSame( array(), Store::scannable_ids_after( 0, 10 ) );
		$this->assertSame( 0, Store::count_scannable() );
	}

	public function test_scannable_ids_after_respects_cursor_and_limit() {
		$ids = array( $this->create_scannable_post(), $this->create_scannable_post(), $this->create_scannable_post() );
		$this->assertSame( array( $ids[1], $ids[2] ), Store::scannable_ids_after( $ids[0], 10 ) );
		$this->assertSame( array( $ids[0] ), Store::scannable_ids_after( 0, 1 ) );
		$this->assertSame( 3, Store::count_scannable() );
	}

	public function test_scan_post_unscannable_saves_error() {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$error = Store::scan_post( $draft );
		$this->assertWPError( $error );
		$this->assertSame( 'findable_not_scannable', Store::get_error( $draft )['code'] );
	}

	public function test_scan_post_rate_limit_error_carries_retry_after() {
		$post_id = $this->create_scannable_post();
		$this->stub_http( $this->http_response( 429, '{}', array( 'Retry-After' => '90' ) ) );
		$scanned = Store::scan_post( $post_id );
		$this->assertSame( 'findable_rate_limited', $scanned['error']['code'] );
		$this->assertSame( 90, $scanned['error']['retry_after'] );
	}

	public function test_get_ignores_malformed_meta() {
		$post_id = $this->create_scannable_post();
		update_post_meta( $post_id, '_findable_result', 'garbage' );
		update_post_meta( $post_id, '_findable_error', array( 'no_code' => 1 ) );
		$this->assertNull( Store::get( $post_id ) );
		$this->assertNull( Store::get_error( $post_id ) );
	}
}
