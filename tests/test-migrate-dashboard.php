<?php
/**
 * Migration from 0.2.0 and dashboard aggregate tests.
 *
 * @package findable-wp
 */

use Findable\Migrate;
use Findable\Result;
use Findable\Store;

class Test_Migrate extends Findable_TestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'findable_db_version' );
		delete_transient( 'findable_dashboard' );
	}

	private function legacy_scan( $overrides = array() ) {
		return array_merge(
			array(
				'overall'    => 64,
				'seo'        => 70,
				'aeo'        => 55,
				'geo'        => 45,
				'failing'    => array(
					array(
						'group'    => 'seo',
						'label'    => 'Title length',
						'severity' => 'warn',
					),
					array(
						'group'    => 'aeo',
						'label'    => 'FAQ markup',
						'severity' => 'warn',
					),
				),
				'scanned_at' => time() - DAY_IN_SECONDS,
				'url'        => 'https://example.com/hello/',
			),
			$overrides
		);
	}

	public function test_migration_converts_legacy_scan() {
		$post_id = $this->create_scannable_post();
		update_post_meta( $post_id, '_findable_scan', $this->legacy_scan() );
		update_post_meta( $post_id, '_findable_keyphrase', 'coffee' );

		Migrate::run_batch();

		$result = Store::get( $post_id );
		$this->assertNotNull( $result );
		$this->assertSame( 64, $result['overall'] );
		$this->assertTrue( $result['legacy'] );
		$this->assertSame( 64, (int) get_post_meta( $post_id, '_findable_overall', true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_id, '_findable_scanned_at', true ) );
		$this->assertSame( 'coffee', get_post_meta( $post_id, '_findable_keyphrase', true ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, '_findable_scan', true ) );

		$checks = $result['checks'];
		$this->assertCount( 2, $checks );
		foreach ( $checks as $check ) {
			$this->assertSame( 'fail', $check['s'] );
			$this->assertMatchesRegularExpression( '/^[a-z0-9_]{1,64}$/', $check['id'] );
		}
	}

	public function test_migration_includes_post_types_excluded_from_search() {
		register_post_type( 'findable_hidden', array( 'public' => true, 'exclude_from_search' => true ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'findable_hidden', 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_findable_scan', $this->legacy_scan() );
		Migrate::maybe_migrate();
		$this->assertNotNull( Store::get( $post_id ) );
		unregister_post_type( 'findable_hidden' );
	}

	public function test_migration_is_idempotent() {
		$post_id = $this->create_scannable_post();
		update_post_meta( $post_id, '_findable_scan', $this->legacy_scan( array( 'overall' => 64 ) ) );

		Migrate::run_batch();
		$first = Store::get( $post_id );

		// Simulate old plugin still writing older data after migration.
		update_post_meta( $post_id, '_findable_scan', $this->legacy_scan( array( 'overall' => 10 ) ) );
		Migrate::run_batch();

		$second = Store::get( $post_id );
		$this->assertSame( $first['overall'], $second['overall'], 'second run must not overwrite with older data' );
		$this->assertSame( '', (string) get_post_meta( $post_id, '_findable_scan', true ) );
	}

	public function test_maybe_migrate_sets_db_version_when_done() {
		$this->create_scannable_post();
		Migrate::maybe_migrate();
		$this->assertSame( 1, (int) get_option( 'findable_db_version' ) );
	}

	public function test_maybe_migrate_skips_when_current() {
		update_option( 'findable_db_version', 1, false );
		$post_id = $this->create_scannable_post();
		update_post_meta( $post_id, '_findable_scan', $this->legacy_scan() );
		Migrate::maybe_migrate();
		$this->assertNotNull( get_post_meta( $post_id, '_findable_scan', true ) );
	}

	public function test_fix_all_notice_visibility() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$post_id = $this->create_scannable_post();
		update_post_meta( $post_id, '_findable_fixes', array( 'description' ) );

		$this->assertTrue( Migrate::has_legacy_fixes() );
		$this->assertFalse( Migrate::fix_notice_dismissed() );

		Migrate::dismiss_fix_notice();
		$this->assertTrue( Migrate::fix_notice_dismissed() );
	}
}

class Test_Dashboard_Aggregate extends Findable_TestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'findable_settings' );
		delete_transient( 'findable_dashboard' );
	}

	private function save_result( $post_id, $overall, $scanned_at = null, $modified_later = false ) {
		$result = Result::normalize( $this->scan_response( array( 'overall' => $overall ) ) );
		if ( null !== $scanned_at ) {
			$result['scanned_at'] = $scanned_at;
		}
		update_post_meta( $post_id, '_findable_result', $result );
		update_post_meta( $post_id, '_findable_overall', (int) $result['overall'] );
		update_post_meta( $post_id, '_findable_scanned_at', (int) $result['scanned_at'] );
		Store::save_failing_ids( $post_id, $result );
		if ( $modified_later ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 100 ) ), array( 'ID' => $post_id ) );
			clean_post_cache( $post_id );
		}
		return $result;
	}

	public function test_mixed_site_scenario_numbers() {
		$scanned = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$scanned[] = $this->create_scannable_post();
		}
		$this->save_result( $scanned[0], 90 );
		$this->save_result( $scanned[1], 85 );
		$this->save_result( $scanned[2], 60 );
		$this->save_result( $scanned[3], 55 );
		$this->save_result( $scanned[4], 40 );
		$this->save_result( $scanned[5], 30, time(), true ); // This one stale.

		// 4 more scannable, never scanned.
		for ( $i = 0; $i < 4; $i++ ) {
			$this->create_scannable_post();
		}

		$agg = Store::aggregate( true );

		$this->assertSame( 10, $agg['scannable'] );
		$this->assertSame( 6, $agg['scanned'] );
		$this->assertSame( 1, $agg['stale'] );
		$this->assertSame( 4, $agg['never_scanned'] );
		$this->assertSame( 60, $agg['avg']['overall'] );
		$this->assertSame( 2, $agg['bands']['good'] );
		$this->assertSame( 2, $agg['bands']['needs_work'] );
		$this->assertSame( 2, $agg['bands']['poor'] );
	}

	public function test_zero_failing_checks_is_integer_in_weakest_rows() {
		$post_id = $this->create_scannable_post();
		$result = $this->save_result( $post_id, 100 );
		$result['failing'] = 0;
		update_post_meta( $post_id, '_findable_result', $result );
		$this->assertSame( 0, Store::aggregate( true )['weakest'][0]['failing'] );
	}

	public function test_weakest_posts_oldest_scan_first_on_tie() {
		$a = $this->create_scannable_post();
		$b = $this->create_scannable_post();
		$this->save_result( $a, 40, time() - DAY_IN_SECONDS );
		$this->save_result( $b, 40, time() - HOUR_IN_SECONDS );

		$agg = Store::aggregate( true );

		$this->assertSame( $a, $agg['weakest'][0]['id'] );
		$this->assertSame( $b, $agg['weakest'][1]['id'] );
	}

	public function test_common_failing_checks_ranked() {
		$a = $this->create_scannable_post();
		$b = $this->create_scannable_post();
		$this->save_result( $a, 40 );
		$this->save_result( $b, 40 );

		$agg = Store::aggregate( true );

		$this->assertNotEmpty( $agg['failing'] );
		$top = $agg['failing'][0];
		$this->assertSame( 2, $top['posts'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9_]{1,64}$/', $top['id'] );
	}

	public function test_aggregate_cached_and_invalidated_by_save() {
		$post_id = $this->create_scannable_post();
		$this->save_result( $post_id, 40 );

		Store::aggregate( true );
		$this->assertNotFalse( get_transient( 'findable_dashboard' ) );

		// A new scan invalidates the cache.
		Store::save( $post_id, Result::normalize( $this->scan_response( array( 'overall' => 90 ) ) ) );
		$this->assertFalse( get_transient( 'findable_dashboard' ) );
	}

	public function test_disabled_post_type_excluded_from_aggregate() {
		$post_id = $this->create_scannable_post( 'page' );
		$this->save_result( $post_id, 40 );

		update_option( 'findable_settings', array( 'post_types' => array( 'post' ) ), false );
		$agg = Store::aggregate( true );

		$this->assertSame( 0, $agg['scanned'] );
		// Meta still exists.
		$this->assertNotNull( Store::get( $post_id ) );
	}
}
