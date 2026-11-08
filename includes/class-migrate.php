<?php
/**
 * Migration from prototype plugin 0.2.0 (_findable_scan meta).
 *
 * @package findable-wp
 */

namespace Findable;

defined( 'ABSPATH' ) || exit;

/**
 * Class Migrate
 */
class Migrate {

	/**
	 * Batch size per admin request.
	 */
	const BATCH = 200;

	/**
	 * Hook entry: run batches while findable_db_version < 1.
	 */
	public static function maybe_migrate() {
		if ( (int) get_option( 'findable_db_version', 0 ) >= 1 ) {
			return;
		}
		$remaining = self::run_batch();
		if ( $remaining < self::BATCH ) {
			update_option( 'findable_db_version', 1, false );
			delete_transient( 'findable_dashboard' );
		}
	}

	/**
	 * Convert up to BATCH legacy scans. Idempotent: skips posts that already
	 * have _findable_result.
	 *
	 * @return int Number of posts converted in this batch.
	 */
	public static function run_batch() {
		$posts = get_posts(
			array(
				'post_type'      => array_values( get_post_types() ),
				'post_status'    => 'any',
				'posts_per_page' => self::BATCH,
				'fields'         => 'ids',
				'meta_key'       => '_findable_scan', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare'   => 'EXISTS',
			)
		);

		$converted = 0;
		foreach ( $posts as $post_id ) {
			if ( metadata_exists( 'post', $post_id, '_findable_result' ) ) {
				// Already migrated in a previous run: drop only the legacy copy.
				delete_post_meta( $post_id, '_findable_scan' );
				continue;
			}

			$legacy = get_post_meta( $post_id, '_findable_scan', true );
			if ( is_array( $legacy ) && isset( $legacy['overall'] ) ) {
				$result = self::convert( $legacy );
				update_post_meta( $post_id, '_findable_result', $result );
				update_post_meta( $post_id, '_findable_overall', (int) $result['overall'] );
				update_post_meta( $post_id, '_findable_scanned_at', (int) $result['scanned_at'] );
				Store::save_failing_ids( $post_id, $result );
			}
			delete_post_meta( $post_id, '_findable_scan' );
			++$converted;
		}
		return $converted;
	}

	/**
	 * Convert one legacy summary into the 1.0 result shape.
	 *
	 * @param array $legacy Legacy _findable_scan value.
	 * @return array
	 */
	public static function convert( $legacy ) {
		$checks = array();
		foreach ( (array) ( isset( $legacy['failing'] ) ? $legacy['failing'] : array() ) as $failing ) {
			if ( ! is_array( $failing ) || empty( $failing['label'] ) ) {
				continue;
			}
			$group    = isset( $failing['group'] ) && in_array( $failing['group'], array( 'seo', 'aeo', 'geo' ), true ) ? $failing['group'] : 'seo';
			$label    = mb_substr( sanitize_text_field( (string) $failing['label'] ), 0, Result::MAX_LABEL );
			$checks[] = array(
				'id'    => mb_substr( 'legacy_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( preg_replace( '/[\s]+/', '_', $label ) ) ), 0, 64 ),
				'f'     => $group,
				'label' => '' !== $label ? $label : 'legacy check',
				's'     => 'fail',
			);
			if ( count( $checks ) >= Result::MAX_CHECKS ) {
				break;
			}
		}

		$families = array();
		foreach ( array( 'seo', 'aeo', 'geo' ) as $family ) {
			$families[ $family ] = array(
				'score'  => isset( $legacy[ $family ] ) && is_numeric( $legacy[ $family ] ) ? max( 0, min( 100, (int) $legacy[ $family ] ) ) : 0,
				'passed' => 0,
				'total'  => 0,
			);
		}

		return array(
			'v'              => 1,
			'legacy'         => true,
			'url'            => isset( $legacy['url'] ) ? esc_url_raw( (string) $legacy['url'] ) : '',
			'overall'        => isset( $legacy['overall'] ) && is_numeric( $legacy['overall'] ) ? max( 0, min( 100, (int) $legacy['overall'] ) ) : 0,
			'families'       => $families,
			'checks'         => $checks,
			'passing'        => 0,
			'failing'        => count( $checks ),
			'keyphrase'      => '',
			'scanned_at'     => isset( $legacy['scanned_at'] ) ? (int) $legacy['scanned_at'] : time(),
			'endpoint'       => FINDABLE_DEFAULT_ENDPOINT,
			'api_scanned_at' => '',
		);
	}

	/**
	 * Whether any post still carries legacy Fix All meta.
	 *
	 * @return bool
	 */
	public static function has_legacy_fixes() {
		$found = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_findable_fixes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare'   => 'EXISTS',
			)
		);
		return ! empty( $found );
	}

	/**
	 * Dismiss the Fix All removal notice for this user.
	 */
	public static function dismiss_fix_notice() {
		update_user_meta( get_current_user_id(), 'findable_fix_notice_dismissed', 1 );
	}

	/**
	 * Whether the Fix All notice is dismissed for the current user.
	 *
	 * @return bool
	 */
	public static function fix_notice_dismissed() {
		return (bool) get_user_meta( get_current_user_id(), 'findable_fix_notice_dismissed', true );
	}
}
