<?php
/**
 * Uninstall cleanup for the Findable plugin.
 *
 * @package findable-wp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'findable_uninstall_site' ) ) {
	/**
	 * Clean one site: options, transients, cron events and (optionally) post meta.
	 */
	function findable_uninstall_site() {
		$delete_data = false;
		$settings    = get_option( 'findable_settings' );
		if ( is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] ) ) {
			$delete_data = true;
		}

		delete_option( 'findable_settings' );
		delete_option( 'findable_api_key' );
		delete_option( 'findable_bulk' );
		delete_option( 'findable_db_version' );
		delete_option( 'findable_bulk_consecutive' );
		delete_option( 'findable_bulk_tick_lock' );
		delete_metadata( 'user', 0, 'findable_fix_notice_dismissed', '', true );

		// All findable_* transients.
		global $wpdb;
		$base_pattern    = $wpdb->esc_like( '_transient_findable_' ) . '%';
		$timeout_pattern = $wpdb->esc_like( '_transient_timeout_findable_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall cleanup of our own prefixed transients; caching is irrelevant.
		$transients = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$base_pattern,
				$timeout_pattern
			)
		);
		foreach ( (array) $transients as $name ) {
			delete_transient( str_replace( '_transient_timeout_', '', str_replace( '_transient_', '', $name ) ) );
		}
		// Catch stragglers delete_transient cannot reach (timeouts without bases).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall cleanup of our own prefixed transients; caching is irrelevant.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$base_pattern,
				$timeout_pattern
			)
		);

		// Cron events.
		wp_clear_scheduled_hook( 'findable_bulk_tick' );
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

		// Legacy 0.2.0 meta always removed.
		foreach ( array( '_findable_scan', '_findable_fixes', '_findable_fixes_backup' ) as $key ) {
			delete_post_meta_by_key( $key );
		}

		if ( $delete_data ) {
			foreach ( array( '_findable_result', '_findable_overall', '_findable_scanned_at', '_findable_error', '_findable_keyphrase', '_findable_failing_ids' ) as $key ) {
				delete_post_meta_by_key( $key );
			}
		}
	}
}

if ( is_multisite() ) {
	$findable_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $findable_site_ids as $findable_site_id ) {
		switch_to_blog( (int) $findable_site_id );
		findable_uninstall_site();
		restore_current_blog();
	}
} else {
	findable_uninstall_site();
}
