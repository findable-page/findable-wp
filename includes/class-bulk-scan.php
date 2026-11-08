<?php
/**
 * WP-Cron bulk scan engine: cursor walk, pacing, backoff, lock.
 *
 * @package findable-wp
 */

namespace Findable;

defined( 'ABSPATH' ) || exit;

/**
 * Class Bulk_Scan
 */
class Bulk_Scan {

	/**
	 * Cron hook for one tick.
	 */
	const TICK = 'findable_bulk_tick';

	/**
	 * Cron hook for scan-on-publish.
	 */
	const SCAN_POST = 'findable_scan_post';

	/**
	 * Atomic option lock (transients do not provide an atomic claim).
	 */
	const LOCK = 'findable_bulk_tick_lock';

	/**
	 * Default state.
	 *
	 * @return array
	 */
	public static function default_state() {
		return array(
			'status'      => 'idle', // idle|running|paused|done|cancelled.
			'cursor'      => 0,
			'done'        => 0,
			'failed'      => 0,
			'total'       => 0,
			'started_at'  => 0,
			'finished_at' => 0,
			'force'       => false,
			'next_at'     => 0,
			'last_error'  => '',
		);
	}

	/**
	 * Read bulk state.
	 *
	 * @return array
	 */
	public static function state() {
		$state                = wp_parse_args( get_option( 'findable_bulk', array() ), self::default_state() );
		$state['cursor']      = (int) $state['cursor'];
		$state['done']        = (int) $state['done'];
		$state['failed']      = (int) $state['failed'];
		$state['total']       = (int) $state['total'];
		$state['started_at']  = (int) $state['started_at'];
		$state['finished_at'] = (int) $state['finished_at'];
		$state['next_at']     = (int) $state['next_at'];
		$state['force']       = (bool) $state['force'];

		$next = wp_next_scheduled( self::TICK );
		if ( $next && 'running' === $state['status'] ) {
			$state['next_at'] = $next;
		}
		return $state;
	}

	/**
	 * Persist state.
	 *
	 * @param array $state State.
	 */
	private static function save_state( $state ) {
		update_option( 'findable_bulk', $state, false );
	}

	/**
	 * Count scannable posts that need a scan.
	 *
	 * @param bool $force When true count every scannable post.
	 * @return int
	 */
	public static function count_needing_scan( $force = false ) {
		$count  = 0;
		$cursor = 0;
		while ( true ) {
			$ids = Store::scannable_ids_after( $cursor, 200 );
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				if ( $force || ! Store::is_fresh( $id ) ) {
					++$count;
				}
			}
			$cursor = end( $ids );
			if ( count( $ids ) < 200 ) {
				break;
			}
		}
		return $count;
	}

	/**
	 * Start a bulk run.
	 *
	 * @param bool $force Rescan even fresh results.
	 * @return array|\WP_Error State or 409 error.
	 */
	public static function start( $force = false ) {
		$state = self::state();
		if ( in_array( $state['status'], array( 'running', 'paused' ), true ) ) {
			return new \WP_Error(
				'findable_bulk_running',
				__( 'A bulk scan is already in progress.', 'findable' ),
				array( 'status' => 409 )
			);
		}

		$state = array_merge(
			self::default_state(),
			array(
				'status'     => 'running',
				'total'      => self::count_needing_scan( $force ),
				'started_at' => time(),
				'force'      => (bool) $force,
			)
		);
		self::save_state( $state );
		self::schedule_next_tick( time() + 5 );
		return self::state();
	}

	/**
	 * Pause a running scan.
	 *
	 * @return array State.
	 */
	public static function pause() {
		$state = self::state();
		if ( 'running' === $state['status'] ) {
			$state['status'] = 'paused';
			self::save_state( $state );
		}
		wp_clear_scheduled_hook( self::TICK );
		return self::state();
	}

	/**
	 * Resume a paused scan.
	 *
	 * @return array State.
	 */
	public static function resume() {
		$state = self::state();
		if ( 'paused' === $state['status'] ) {
			$state['status'] = 'running';
			self::save_state( $state );
			self::schedule_next_tick( time() + 5 );
		}
		return self::state();
	}

	/**
	 * Cancel a run; saved results are kept.
	 *
	 * @return array State.
	 */
	public static function cancel() {
		$state           = self::state();
		$state['status'] = 'cancelled';
		self::save_state( $state );
		wp_clear_scheduled_hook( self::TICK );
		return self::state();
	}

	/**
	 * Schedule the next single tick.
	 *
	 * @param int $timestamp Unix time.
	 * @return bool
	 */
	private static function schedule_next_tick( $timestamp ) {
		wp_clear_scheduled_hook( self::TICK );
		return wp_schedule_single_event( $timestamp, self::TICK );
	}

	/**
	 * One tick: scan up to the pace limit within the wall budget.
	 */
	public static function tick() {
		$state = self::state();
		if ( 'running' !== $state['status'] ) {
			return;
		}

		// add_option is atomic across concurrent requests; an expired claim is recoverable.
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			$claimed_at = (int) get_option( self::LOCK );
			if ( $claimed_at > time() - 120 ) {
				return;
			}
			delete_option( self::LOCK );
			if ( ! add_option( self::LOCK, time(), '', false ) ) {
				return;
			}
		}

		$state       = self::state();
		$started     = microtime( true );
		$pace        = (int) Settings::get( 'bulk_per_minute' );
		$calls       = 0;
		$consecutive = (int) get_option( 'findable_bulk_consecutive', 0 );

		try {
			while ( $calls < $pace && ( microtime( true ) - $started ) < 40 ) {
				$ids = Store::scannable_ids_after( $state['cursor'], 25 );
				if ( empty( $ids ) ) {
					$state['status']      = 'done';
					$state['finished_at'] = time();
					$state['next_at']     = 0;
					self::save_state( $state );
					delete_transient( 'findable_dashboard' );
					delete_option( 'findable_bulk_consecutive' );
					wp_clear_scheduled_hook( self::TICK );
					return;
				}

				// Skip fresh posts without spending API calls.
				$post_id = 0;
				foreach ( $ids as $id ) {
					if ( $state['force'] || ! Store::is_fresh( $id ) ) {
						$post_id = $id;
						break;
					}
					$state['cursor'] = $id;
				}
				if ( 0 === $post_id ) {
					// Batch was all fresh: advance past it and recheck for completion.
					$state['cursor'] = end( $ids );
					$remaining       = Store::scannable_ids_after( $state['cursor'], 1 );
					if ( empty( $remaining ) ) {
						$state['status']      = 'done';
						$state['finished_at'] = time();
						$state['next_at']     = 0;
						self::save_state( $state );
						delete_transient( 'findable_dashboard' );
						delete_option( 'findable_bulk_consecutive' );
						wp_clear_scheduled_hook( self::TICK );
						return;
					}
					continue;
				}

				$scanned = Store::scan_post( $post_id );

				if ( is_wp_error( $scanned ) ) {
					// Not scannable any more: count and move on.
					$state['cursor'] = $post_id;
					++$state['failed'];
				} elseif ( null !== $scanned['error'] ) {
					$code = $scanned['error']['code'];
					if ( 'findable_rate_limited' === $code ) {
						$retry_after         = isset( $scanned['error']['retry_after'] ) ? (int) $scanned['error']['retry_after'] : 60;
						$state['last_error'] = $scanned['error']['message'];
						self::save_state( $state );
						self::schedule_next_tick( time() + max( 60, $retry_after ) );
						return;
					}
					$state['cursor'] = $post_id;
					++$state['failed'];
					if ( in_array( $code, array( 'findable_http', 'findable_server', 'findable_unauthorized' ), true ) ) {
						++$consecutive;
						update_option( 'findable_bulk_consecutive', $consecutive, false );
						if ( $consecutive >= 5 ) {
							$state['status']     = 'paused';
							$state['last_error'] = __( 'Bulk scan paused: the Findable API is not responding.', 'findable' );
							self::save_state( $state );
							wp_clear_scheduled_hook( self::TICK );
							return;
						}
					} else {
						update_option( 'findable_bulk_consecutive', 0, false );
						$consecutive = 0;
					}
				} else {
					$state['cursor'] = $post_id;
					++$state['done'];
					update_option( 'findable_bulk_consecutive', 0, false );
					$consecutive = 0;
				}

				++$calls;
			}
		} finally {
			self::save_state( $state );
			delete_option( self::LOCK );
		}

		self::schedule_next_tick( (int) $started + 60 );
	}

	/**
	 * Scan-on-publish handler: single delayed scan for one post.
	 *
	 * @param int $post_id Post id.
	 */
	public static function scan_post_event( $post_id ) {
		Store::scan_post( (int) $post_id );
	}

	/**
	 * Schedule scan-on-publish with per-post dedup.
	 *
	 * @param int $post_id Post id.
	 */
	public static function schedule_scan_post( $post_id ) {
		if ( ! Settings::get( 'scan_on_publish' ) ) {
			return;
		}
		if ( ! Store::is_scannable( $post_id ) ) {
			return;
		}
		$args = array( (int) $post_id );
		if ( wp_next_scheduled( self::SCAN_POST, $args ) ) {
			return;
		}
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::SCAN_POST, $args );
	}

	/**
	 * Publish/update hook gate: skip autosaves and revisions.
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_publish( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::schedule_scan_post( $post_id );
	}

	/**
	 * Whether cron looks unhealthy for bulk scans.
	 *
	 * @return bool
	 */
	public static function cron_unhealthy() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return true;
		}
		$next  = wp_next_scheduled( self::TICK );
		$state = self::state();
		if ( $next && 'running' === $state['status'] && ( $next + 10 * MINUTE_IN_SECONDS ) < time() ) {
			return true;
		}
		return false;
	}

	/**
	 * Deactivation cleanup: unschedule everything, pause a running scan.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::TICK );
		// Remove all pending scan-on-publish events.
		$crons = _get_cron_array();
		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				if ( isset( $hooks[ self::SCAN_POST ] ) ) {
					foreach ( $hooks[ self::SCAN_POST ] as $key => $event ) {
						wp_unschedule_event( $timestamp, self::SCAN_POST, isset( $event['args'] ) ? $event['args'] : array() );
					}
				}
			}
		}

		$state = self::state();
		if ( 'running' === $state['status'] ) {
			$state['status'] = 'paused';
			self::save_state( $state );
		}
		delete_option( self::LOCK );
	}
}
