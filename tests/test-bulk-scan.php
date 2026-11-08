<?php
/**
 * Bulk scan engine tests.
 *
 * @package findable-wp
 */

use Findable\Bulk_Scan;
use Findable\Result;
use Findable\Store;

class Test_Bulk_Scan extends Findable_TestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'findable_settings' );
		delete_option( 'findable_bulk' );
		delete_option( 'findable_bulk_consecutive' );
		delete_option( Bulk_Scan::LOCK );
		delete_transient( 'findable_dashboard' );
		wp_clear_scheduled_hook( Bulk_Scan::TICK );
		_cron_wp_clear_scan_post_events();
	}

	public function tearDown(): void {
		_cron_wp_clear_scan_post_events();
		wp_clear_scheduled_hook( Bulk_Scan::TICK );
		parent::tearDown();
	}

	private function ok_response() {
		return $this->http_response( 200, wp_json_encode( $this->scan_response() ) );
	}

	public function test_start_schedules_tick_within_5s() {
		$posts = array( $this->create_scannable_post(), $this->create_scannable_post() );

		$state = Bulk_Scan::start( false );

		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 0, $state['cursor'] );
		$this->assertSame( 2, $state['total'] );
		$next = wp_next_scheduled( Bulk_Scan::TICK );
		$this->assertNotFalse( $next );
		$this->assertLessThanOrEqual( time() + 5, $next );
	}

	public function test_start_and_resume_reset_consecutive_failures() {
		$this->create_scannable_post();
		update_option( 'findable_bulk_consecutive', 4, false );
		Bulk_Scan::start();
		$this->assertFalse( get_option( 'findable_bulk_consecutive' ) );
		Bulk_Scan::pause();
		update_option( 'findable_bulk_consecutive', 4, false );
		Bulk_Scan::resume();
		$this->assertFalse( get_option( 'findable_bulk_consecutive' ) );
	}

	public function test_start_while_running_is_409() {
		Bulk_Scan::start( false );
		$error = Bulk_Scan::start( false );

		$this->assertWPError( $error );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( 'findable_bulk_running', $error->get_error_code() );
	}

	public function test_cancel_unschedules_and_keeps_results() {
		$post_id = $this->create_scannable_post();
		Store::save( $post_id, Result::normalize( $this->scan_response() ) );
		Bulk_Scan::start( false );

		$state = Bulk_Scan::cancel();

		$this->assertSame( 'cancelled', $state['status'] );
		$this->assertFalse( wp_next_scheduled( Bulk_Scan::TICK ) );
		$this->assertNotNull( Store::get( $post_id ) );
	}

	public function test_tick_scans_up_to_pace_posts() {
		update_option( 'findable_settings', array( 'bulk_per_minute' => 3 ), false );
		$posts = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$posts[] = $this->create_scannable_post();
		}

		// 6 ok responses available; tick must stop after 3 calls.
		$this->stub_http(
			array( $this->ok_response(), $this->ok_response(), $this->ok_response(), $this->ok_response(), $this->ok_response(), $this->ok_response() ),
			$calls
		);

		Bulk_Scan::start( false );
		Bulk_Scan::tick();

		$this->assertCount( 3, $calls );
		$state = Bulk_Scan::state();
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 3, $state['done'] );
		$this->assertSame( $posts[2], $state['cursor'] );
		$this->assertFalse( get_option( Bulk_Scan::LOCK ), 'Completed tick must release lock before the next tick.' );
		Bulk_Scan::tick();
		$this->assertSame( 6, Bulk_Scan::state()['done'] );
	}

	public function test_tick_skips_fresh_post_without_api_call() {
		$post_id = $this->create_scannable_post();
		Store::save( $post_id, Result::normalize( $this->scan_response() ) );

		$this->stub_http( $this->ok_response(), $calls );

		Bulk_Scan::start( false );
		$state = Bulk_Scan::state();
		$this->assertSame( 0, $state['total'], 'fresh post must not count as needing scan' );

		Bulk_Scan::tick();
		$this->assertCount( 0, $calls );
		$state = Bulk_Scan::state();
		$this->assertSame( 'done', $state['status'] );
	}

	public function test_tick_429_backoff_keeps_cursor() {
		$post_id  = $this->create_scannable_post();
		$post_id2 = $this->create_scannable_post();

		$this->stub_http(
			array(
				$this->http_response( 429, wp_json_encode( array( 'error' => 'slow' ) ), array( 'Retry-After' => '120' ) ),
			),
			$calls
		);

		Bulk_Scan::start( true ); // Force so fresh rules do not matter.
		Bulk_Scan::tick();

		$state = Bulk_Scan::state();
		$this->assertSame( 'running', $state['status'] );
		// Cursor must still be below the limited post so it is retried first.
		$this->assertLessThan( $post_id, $state['cursor'] );
		$next = wp_next_scheduled( Bulk_Scan::TICK );
		$this->assertGreaterThanOrEqual( time() + 120, $next );
		$this->assertNotSame( '', $state['last_error'] );
	}

	public function test_tick_per_post_failure_advances_cursor() {
		$post_id = $this->create_scannable_post();
		$this->stub_http( $this->http_response( 400, wp_json_encode( array( 'error' => 'target resolves to a private address' ) ) ), $calls );

		Bulk_Scan::start( true );
		Bulk_Scan::tick();

		$state = Bulk_Scan::state();
		$this->assertSame( 1, $state['failed'] );
		$this->assertSame( 0, $state['done'] );
		$this->assertSame( $post_id, $state['cursor'] );
		$this->assertSame( 'findable_blocked', Store::get_error( $post_id )['code'] );
	}

	public function test_five_consecutive_server_failures_pause() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_scannable_post();
		}
		$this->stub_http( $this->http_response( 500, wp_json_encode( array( 'error' => 'audit failed' ) ) ) );

		Bulk_Scan::start( true );
		Bulk_Scan::tick(); // Pace 5: all five posts fail in one tick.

		$state = Bulk_Scan::state();
		$this->assertSame( 'paused', $state['status'] );
		$this->assertFalse( wp_next_scheduled( Bulk_Scan::TICK ) );
		$this->assertSame( 'Bulk scan paused: the Findable API is not responding.', $state['last_error'] );
		$this->assertSame( 5, $state['failed'] );
	}

	public function test_completion_sets_done_and_clears_transient() {
		$this->create_scannable_post();
		set_transient( 'findable_dashboard', array( 'stale' => 1 ) );
		$this->stub_http( $this->ok_response(), $calls );

		Bulk_Scan::start( false );
		Bulk_Scan::tick();

		$state = Bulk_Scan::state();
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( 1, $state['done'] );
		$this->assertGreaterThan( 0, $state['finished_at'] );
		$this->assertFalse( wp_next_scheduled( Bulk_Scan::TICK ) );
		$this->assertFalse( get_transient( 'findable_dashboard' ) );
	}

	public function test_lock_prevents_overlapping_ticks() {
		$this->create_scannable_post();
		$this->stub_http( $this->ok_response(), $calls );

		add_option( Bulk_Scan::LOCK, time(), '', false );
		Bulk_Scan::start( false );
		Bulk_Scan::tick();

		$this->assertCount( 0, $calls );
	}

	public function test_expired_lock_is_recovered() {
		$this->create_scannable_post();
		$this->stub_http( $this->ok_response(), $calls );
		add_option( Bulk_Scan::LOCK, time() - 121, '', false );

		Bulk_Scan::start( false );
		Bulk_Scan::tick();

		$this->assertCount( 1, $calls );
		$this->assertFalse( get_option( Bulk_Scan::LOCK ) );
	}

	public function test_new_post_with_higher_id_joins_run() {
		$this->create_scannable_post();
		$this->stub_http( array( $this->ok_response(), $this->ok_response() ), $calls );

		Bulk_Scan::start( false );
		$new_id = $this->create_scannable_post();
		Bulk_Scan::tick();

		$state = Bulk_Scan::state();
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( 2, $state['done'] );
		$this->assertSame( $new_id, $state['cursor'] );
	}

	public function test_scan_on_publish_dedupes_one_event() {
		update_option( 'findable_settings', array( 'scan_on_publish' => true ), false );
		$post_id = $this->create_scannable_post();

		Bulk_Scan::schedule_scan_post( $post_id );
		Bulk_Scan::schedule_scan_post( $post_id );

		$count = 0;
		foreach ( _get_cron_array() as $events ) {
			if ( isset( $events[ Bulk_Scan::SCAN_POST ] ) ) {
				foreach ( $events[ Bulk_Scan::SCAN_POST ] as $event ) {
					if ( in_array( $post_id, $event['args'], true ) ) {
						++$count;
					}
				}
			}
		}
		$this->assertSame( 1, $count );
	}

	public function test_scan_on_publish_off_schedules_nothing() {
		$post_id = $this->create_scannable_post();
		Bulk_Scan::schedule_scan_post( $post_id );
		$this->assertFalse( wp_next_scheduled( Bulk_Scan::SCAN_POST, array( $post_id ) ) );
	}

	public function test_deactivate_unschedules_and_pauses() {
		$this->create_scannable_post();
		Bulk_Scan::start( false );

		Bulk_Scan::deactivate();

		$this->assertFalse( wp_next_scheduled( Bulk_Scan::TICK ) );
		$this->assertSame( 'paused', Bulk_Scan::state()['status'] );
	}
}

/**
 * Test helper: clear all findable_scan_post events.
 */
function _cron_wp_clear_scan_post_events() {
	$crons = _get_cron_array();
	if ( is_array( $crons ) ) {
		foreach ( $crons as $timestamp => $hooks ) {
			if ( isset( $hooks['findable_scan_post'] ) ) {
				foreach ( $hooks['findable_scan_post'] as $event ) {
					wp_unschedule_event( $timestamp, 'findable_scan_post', isset( $event['args'] ) ? $event['args'] : array() );
				}
			}
		}
	}
}
