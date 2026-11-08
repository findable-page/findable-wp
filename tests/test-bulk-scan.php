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

	public function test_tick_does_nothing_unless_running() {
		$this->create_scannable_post();
		$this->stub_http( $this->ok_response(), $calls );
		Bulk_Scan::tick();
		$this->assertCount( 0, $calls );
		$this->assertFalse( get_option( Bulk_Scan::LOCK ) );
	}

	public function test_expired_lock_lost_to_a_racing_tick_returns_without_scanning() {
		$this->create_scannable_post();
		$this->stub_http( $this->ok_response(), $calls );
		add_option( Bulk_Scan::LOCK, time() - 500, '', false );
		Bulk_Scan::start( false );
		// Another worker re-claims the lock between our delete and our add.
		add_action(
			'deleted_option',
			static function ( $option ) {
				if ( Bulk_Scan::LOCK === $option ) {
					add_option( Bulk_Scan::LOCK, time(), '', false );
				}
			}
		);

		Bulk_Scan::tick();

		$this->assertCount( 0, $calls );
	}

	public function test_batch_of_only_fresh_posts_followed_by_stale_ones_keeps_scanning() {
		$fresh = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$fresh[] = $this->create_scannable_post();
			Store::save( end( $fresh ), Result::normalize( $this->scan_response() ) );
		}
		$stale = $this->create_scannable_post();
		$this->stub_http( $this->ok_response(), $calls );

		Bulk_Scan::start( false );
		Bulk_Scan::tick();

		$state = Bulk_Scan::state();
		$this->assertCount( 1, $calls );
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( $stale, $state['cursor'] );
	}

	public function test_batch_of_only_fresh_posts_finishes_the_run() {
		for ( $i = 0; $i < 25; $i++ ) {
			Store::save( $this->create_scannable_post(), Result::normalize( $this->scan_response() ) );
		}
		$this->stub_http( $this->ok_response(), $calls );
		Bulk_Scan::start( false );
		Bulk_Scan::tick();
		$this->assertCount( 0, $calls );
		$this->assertSame( 'done', Bulk_Scan::state()['status'] );
	}

	public function test_post_that_stops_being_scannable_counts_as_failed() {
		$post_id = $this->create_scannable_post();
		$this->stub_http( $this->ok_response(), $calls );
		Bulk_Scan::start( true );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );

		// The id query only returns published posts, so make one appear then vanish mid-tick.
		add_filter(
			'query',
			static function ( $sql ) use ( $post_id ) {
				static $done = false;
				if ( ! $done && false !== strpos( $sql, 'SELECT ID FROM' ) ) {
					$done = true;
					return str_replace( "post_status = 'publish'", "post_status IN ('publish','draft')", $sql );
				}
				return $sql;
			}
		);
		Bulk_Scan::tick();

		$state = Bulk_Scan::state();
		$this->assertSame( 1, $state['failed'] );
		$this->assertSame( $post_id, $state['cursor'] );
		$this->assertCount( 0, $calls );
	}

	public function test_rate_limit_without_retry_after_waits_at_least_a_minute() {
		$this->create_scannable_post();
		$this->stub_http( $this->http_response( 429, 'slow' ) );
		Bulk_Scan::start( true );
		Bulk_Scan::tick();
		$this->assertGreaterThanOrEqual( time() + 59, wp_next_scheduled( Bulk_Scan::TICK ) );
	}

	public function test_non_http_failures_reset_the_consecutive_counter() {
		$a = $this->create_scannable_post();
		$b = $this->create_scannable_post();
		update_option( 'findable_bulk_consecutive', 3, false );
		$this->stub_http(
			array(
				$this->http_response( 400, wp_json_encode( array( 'error' => 'private address' ) ) ),
				$this->ok_response(),
			)
		);
		Bulk_Scan::start( true );
		update_option( 'findable_bulk_consecutive', 3, false );
		Bulk_Scan::tick();

		$state = Bulk_Scan::state();
		$this->assertSame( 1, $state['failed'] );
		$this->assertSame( 1, $state['done'] );
		$this->assertSame( 0, (int) get_option( 'findable_bulk_consecutive' ) );
		$this->assertSame( $b, $state['cursor'] );
		$this->assertNotSame( $a, $b );
	}

	public function test_scan_post_event_respects_scan_on_publish_setting() {
		$post_id = $this->create_scannable_post();
		$this->stub_http( $this->ok_response(), $calls );

		Bulk_Scan::scan_post_event( $post_id );
		$this->assertCount( 0, $calls );

		update_option( 'findable_settings', array( 'scan_on_publish' => true ), false );
		Bulk_Scan::scan_post_event( $post_id );
		$this->assertCount( 1, $calls );
		$this->assertNotNull( Store::get( $post_id ) );
	}

	public function test_schedule_scan_post_skips_unscannable_posts() {
		update_option( 'findable_settings', array( 'scan_on_publish' => true ), false );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		Bulk_Scan::schedule_scan_post( $draft );
		$this->assertFalse( wp_next_scheduled( Bulk_Scan::SCAN_POST, array( $draft ) ) );
	}

	public function test_cron_disabled_filter_and_unhealthy_detection() {
		add_filter( 'findable_cron_disabled', '__return_true' );
		$this->assertTrue( Bulk_Scan::cron_disabled() );
		$this->assertTrue( Bulk_Scan::cron_unhealthy() );
		remove_all_filters( 'findable_cron_disabled' );

		add_filter( 'findable_cron_disabled', '__return_false' );
		$this->assertFalse( Bulk_Scan::cron_unhealthy(), 'Idle with nothing scheduled is healthy.' );

		$this->create_scannable_post();
		Bulk_Scan::start( true );
		$this->assertFalse( Bulk_Scan::cron_unhealthy(), 'A tick due in seconds is healthy.' );

		wp_clear_scheduled_hook( Bulk_Scan::TICK );
		wp_schedule_single_event( time() - ( 11 * MINUTE_IN_SECONDS ), Bulk_Scan::TICK );
		$this->assertTrue( Bulk_Scan::cron_unhealthy() );

		Bulk_Scan::pause();
		wp_schedule_single_event( time() - ( 11 * MINUTE_IN_SECONDS ), Bulk_Scan::TICK );
		$this->assertFalse( Bulk_Scan::cron_unhealthy(), 'Only a running scan can be stalled.' );
	}

	public function test_deactivate_removes_pending_scan_post_events_only() {
		update_option( 'findable_settings', array( 'scan_on_publish' => true ), false );
		$a = $this->create_scannable_post();
		$b = $this->create_scannable_post();
		Bulk_Scan::schedule_scan_post( $a );
		Bulk_Scan::schedule_scan_post( $b );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'unrelated_hook' );

		Bulk_Scan::deactivate();

		$this->assertFalse( wp_next_scheduled( Bulk_Scan::SCAN_POST, array( $a ) ) );
		$this->assertFalse( wp_next_scheduled( Bulk_Scan::SCAN_POST, array( $b ) ) );
		$this->assertNotFalse( wp_next_scheduled( 'unrelated_hook' ) );
		wp_clear_scheduled_hook( 'unrelated_hook' );
	}

	public function test_deactivate_when_idle_does_not_change_status() {
		Bulk_Scan::deactivate();
		$this->assertSame( 'idle', Bulk_Scan::state()['status'] );
	}

	public function test_pause_and_resume_are_noops_in_wrong_state() {
		$this->assertSame( 'idle', Bulk_Scan::pause()['status'] );
		$this->assertSame( 'idle', Bulk_Scan::resume()['status'] );
	}

	public function test_state_reports_scheduled_next_tick_when_running() {
		$this->create_scannable_post();
		Bulk_Scan::start( true );
		$this->assertSame( wp_next_scheduled( Bulk_Scan::TICK ), Bulk_Scan::state()['next_at'] );
	}

	public function test_count_needing_scan_walks_multiple_pages() {
		for ( $i = 0; $i < 201; $i++ ) {
			$this->create_scannable_post();
		}
		$this->assertSame( 201, Bulk_Scan::count_needing_scan( true ) );
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
