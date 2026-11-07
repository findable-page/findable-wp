<?php
/**
 * Uninstall cleanup tests (single site).
 *
 * @package findable-wp
 */

class Test_Uninstall extends WP_UnitTestCase {

	private function prime_options_and_meta( $delete_data ) {
		update_option( 'findable_settings', array( 'delete_data_on_uninstall' => $delete_data ), false );
		update_option( 'findable_api_key', 'sekrit123', false );
		update_option( 'findable_bulk', array( 'status' => 'done' ), false );
		update_option( 'findable_db_version', 1, false );
		update_option( 'findable_bulk_consecutive', 2, false );
		set_transient( 'findable_check_text_de', array( 'x' => 1 ) );
		set_transient( 'findable_dashboard', array( 'y' => 1 ) );
		set_transient( 'findableXdashboard', 'keep' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_findable_result', array( 'overall' => 50 ) );
		update_post_meta( $post_id, '_findable_overall', 50 );
		update_post_meta( $post_id, '_findable_scanned_at', time() );
		update_post_meta( $post_id, '_findable_error', array( 'code' => 'x' ) );
		update_post_meta( $post_id, '_findable_keyphrase', 'k' );
		update_post_meta( $post_id, '_findable_failing_ids', ',x,' );
		update_post_meta( $post_id, '_findable_scan', array( 'legacy' => true ) );
		update_post_meta( $post_id, '_findable_fixes', array( 'description' ) );
		update_post_meta( $post_id, '_findable_fixes_backup', array() );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'findable_bulk_tick' );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'findable_scan_post', array( $post_id ) );
		return $post_id;
	}

	public function test_uninstall_keeping_data() {
		$post_id = $this->prime_options_and_meta( false );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', dirname( __DIR__ ) . '/findable.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( 'findable_settings' ) );
		$this->assertFalse( get_option( 'findable_api_key' ) );
		$this->assertFalse( get_option( 'findable_bulk' ) );
		$this->assertFalse( get_option( 'findable_db_version' ) );
		$this->assertFalse( get_transient( 'findable_check_text_de' ) );
		$this->assertFalse( get_transient( 'findable_dashboard' ) );
		$this->assertSame( 'keep', get_transient( 'findableXdashboard' ) );
		$this->assertFalse( wp_next_scheduled( 'findable_bulk_tick' ) );
		$this->assertFalse( wp_next_scheduled( 'findable_scan_post', array( $post_id ) ) );

		// Legacy meta always removed…
		$this->assertSame( '', (string) get_post_meta( $post_id, '_findable_scan', true ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, '_findable_fixes', true ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, '_findable_fixes_backup', true ) );

		// …but scan results kept.
		$this->assertSame( '50', get_post_meta( $post_id, '_findable_overall', true ) );
		$this->assertSame( array( 'overall' => 50 ), get_post_meta( $post_id, '_findable_result', true ) );
		$this->assertSame( 'k', get_post_meta( $post_id, '_findable_keyphrase', true ) );
	}

	public function test_uninstall_deleting_data() {
		// Re-prime: uninstall.php only runs once per process, so emulate its site
		// routine directly for the delete branch.
		$post_id = $this->prime_options_and_meta( true );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', dirname( __DIR__ ) . '/findable.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		foreach ( array( '_findable_result', '_findable_overall', '_findable_scanned_at', '_findable_error', '_findable_keyphrase', '_findable_failing_ids' ) as $key ) {
			$this->assertSame( '', (string) get_post_meta( $post_id, $key, true ), "meta {$key} must be deleted" );
		}
		$this->assertFalse( get_option( 'findable_bulk_consecutive' ) );
	}
}
