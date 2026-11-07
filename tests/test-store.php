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

		$this->assertFalse( Store::is_fresh( $post_id ) );
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
}
