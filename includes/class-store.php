<?php
/**
 * Post meta storage for scan results and the dashboard aggregate.
 *
 * @package findable-wp
 */

namespace Findable;

defined( 'ABSPATH' ) || exit;

/**
 * Class Store
 */
class Store {

	/**
	 * Register all post meta as protected.
	 */
	public static function register_meta() {
		$args_map = array(
			'_findable_result' => array( 'type' => 'array' ),
			'_findable_overall' => array( 'type' => 'integer' ),
			'_findable_scanned_at' => array( 'type' => 'integer' ),
			'_findable_keyphrase'   => array(
				'type'    => 'string',
				'default' => '',
			),
			'_findable_error' => array( 'type' => 'array' ),
			'_findable_failing_ids' => array(
				'type'    => 'string',
				'default' => '',
			),
		);
		foreach ( Settings::get( 'post_types' ) as $type ) {
			foreach ( $args_map as $key => $extra ) {
				register_post_meta(
					$type,
					$key,
					array_merge(
						$extra,
						array(
							'show_in_rest' => false,
							'single'       => true,
						)
					)
				);
			}
		}
	}

	/**
	 * Save a normalized scan result for a post.
	 *
	 * @param int   $post_id Post id.
	 * @param array $result  Normalized result from Result::normalize().
	 * @return bool
	 */
	public static function save( $post_id, $result ) {
		update_post_meta( $post_id, '_findable_result', $result );
		update_post_meta( $post_id, '_findable_overall', (int) $result['overall'] );
		update_post_meta( $post_id, '_findable_scanned_at', (int) $result['scanned_at'] );
		self::save_failing_ids( $post_id, $result );
		delete_post_meta( $post_id, '_findable_error' );
		delete_transient( 'findable_dashboard' );
		return true;
	}

	/**
	 * Persist the failing check id list used by the posts-list filter.
	 *
	 * Stored as ",id,id," so LIKE queries cannot match substrings of other ids.
	 *
	 * @param int   $post_id Post id.
	 * @param array $result  Normalized result.
	 */
	public static function save_failing_ids( $post_id, $result ) {
		$ids = array();
		foreach ( Result::failing( $result ) as $check ) {
			$ids[] = $check['id'];
		}
		update_post_meta( $post_id, '_findable_failing_ids', ',' . implode( ',', $ids ) . ',' );
	}

	/**
	 * Save the last scan error for a post.
	 *
	 * @param int       $post_id Post id.
	 * @param \WP_Error $error   Error.
	 */
	public static function save_error( $post_id, $error ) {
		update_post_meta(
			$post_id,
			'_findable_error',
			array(
				'code'    => sanitize_key( $error->get_error_code() ),
				'message' => mb_substr( sanitize_text_field( $error->get_error_message() ), 0, 300 ),
				'at'      => time(),
			)
		);
	}

	/**
	 * Stored result or null.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function get( $post_id ) {
		$result = get_post_meta( $post_id, '_findable_result', true );
		return is_array( $result ) && isset( $result['overall'] ) ? $result : null;
	}

	/**
	 * Stored error or null.
	 *
	 * @param int $post_id Post id.
	 * @return array|null array( code, message, at ).
	 */
	public static function get_error( $post_id ) {
		$error = get_post_meta( $post_id, '_findable_error', true );
		return is_array( $error ) && isset( $error['code'] ) ? $error : null;
	}

	/**
	 * Whether a post may be scanned.
	 *
	 * @param \WP_Post|int|null $post Post.
	 * @return bool
	 */
	public static function is_scannable( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( ! in_array( $post->post_type, Settings::get( 'post_types' ), true ) ) {
			return false;
		}
		if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return false;
		}
		return is_post_publicly_viewable( $post );
	}

	/**
	 * Why a post cannot be scanned, or null when scannable.
	 *
	 * @param \WP_Post|int|null $post Post.
	 * @return string|null draft|password|type|gone.
	 */
	public static function scan_reason( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return 'gone';
		}
		if ( ! in_array( $post->post_type, Settings::get( 'post_types' ), true ) ) {
			return 'type';
		}
		if ( 'publish' !== $post->post_status ) {
			return 'draft';
		}
		if ( '' !== $post->post_password || ! is_post_publicly_viewable( $post ) ) {
			return 'password';
		}
		return null;
	}

	/**
	 * Whether the stored result is fresh.
	 *
	 * Fresh: scanned after the last content change, within the cache TTL and
	 * from the currently configured endpoint.
	 *
	 * @param int   $post_id Post id.
	 * @param array $result  Stored result (optional; fetched when null).
	 * @return bool
	 */
	public static function is_fresh( $post_id, $result = null ) {
		$result = null === $result ? self::get( $post_id ) : $result;
		if ( null === $result || empty( $result['scanned_at'] ) ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		$modified = strtotime( (string) $post->post_modified_gmt );
		if ( false !== $modified && (int) $result['scanned_at'] < $modified ) {
			return false;
		}

		$ttl = (int) Settings::get( 'cache_ttl_days' );
		if ( ( time() - (int) $result['scanned_at'] ) >= $ttl * DAY_IN_SECONDS ) {
			return false;
		}

		return isset( $result['endpoint'] ) && Settings::endpoint() === $result['endpoint'];
	}

	/**
	 * Age of a result in seconds.
	 *
	 * @param array $result Stored result.
	 * @return int
	 */
	public static function age( $result ) {
		return isset( $result['scanned_at'] ) ? max( 0, time() - (int) $result['scanned_at'] ) : 0;
	}

	/**
	 * Scan one post through the API and persist result or error.
	 *
	 * @param int         $post_id   Post id.
	 * @param string|null $keyphrase Keyphrase to persist and send, or null to keep the stored one.
	 * @return array|\WP_Error array( result, error ) or guard error findable_not_scannable.
	 */
	public static function scan_post( $post_id, $keyphrase = null ) {
		if ( ! self::is_scannable( $post_id ) ) {
			return new \WP_Error(
				'findable_not_scannable',
				self::reason_message( self::scan_reason( $post_id ) )
			);
		}

		if ( null !== $keyphrase ) {
			update_post_meta( $post_id, '_findable_keyphrase', mb_substr( sanitize_text_field( (string) $keyphrase ), 0, Api_Client::MAX_KEYPHRASE ) );
		}
		$keyphrase = (string) get_post_meta( $post_id, '_findable_keyphrase', true );

		$scanned = Api_Client::instance()->scan( (string) get_permalink( $post_id ), $keyphrase );
		if ( is_wp_error( $scanned ) ) {
			self::save_error( $post_id, $scanned );
			$error = array(
				'code'    => $scanned->get_error_code(),
				'message' => $scanned->get_error_message(),
				'at'      => time(),
			);
			$data  = $scanned->get_error_data();
			if ( is_array( $data ) && isset( $data['retry_after'] ) ) {
				$error['retry_after'] = (int) $data['retry_after'];
			}
			return array(
				'result' => self::get( $post_id ),
				'error'  => $error,
			);
		}

		self::save( $post_id, $scanned );
		return array(
			'result' => $scanned,
			'error'  => null,
		);
	}

	/**
	 * Human message for a scan refusal reason.
	 *
	 * @param string|null $reason Reason key.
	 * @return string
	 */
	public static function reason_message( $reason ) {
		switch ( $reason ) {
			case 'draft':
				return __( 'Publish this post first. Findable scans the live URL.', 'findable' );
			case 'password':
				return __( 'This post is not publicly viewable. Findable scans the live URL.', 'findable' );
			case 'type':
				return __( 'This post type is not enabled for Findable scans. Enable it in Findable Settings.', 'findable' );
			default:
				return __( 'This post cannot be scanned.', 'findable' );
		}
	}

	/**
	 * Full report deep link on findable.page for a scanned URL.
	 *
	 * @param string $url Scanned URL.
	 * @return string
	 */
	public static function report_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		$host  = isset( $parts['host'] ) ? $parts['host'] : wp_parse_url( home_url(), PHP_URL_HOST );
		$path  = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		return 'https://findable.page/' . $host . $path;
	}

	/**
	 * Scannable post ids above a cursor, ascending.
	 *
	 * WP_Query has no `ID >` comparison, so this one query is direct SQL
	 * prepared per WordPress.WP.PreparedSQL.
	 *
	 * @param int $cursor Last processed post id.
	 * @param int $limit  Maximum number.
	 * @return int[] Post ids.
	 */
	public static function scannable_ids_after( $cursor, $limit ) {
		global $wpdb;
		$types  = Settings::get( 'post_types' );
		$format = 'SELECT ID FROM ' . $wpdb->posts . ' WHERE post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ") AND post_status = 'publish' AND post_password = '' AND ID > %d ORDER BY ID ASC LIMIT %d";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $format is a prepared statement with dynamic IN placeholders; values all pass through prepare() below.
		$sql = $wpdb->prepare( $format, array_merge( $types, array( (int) $cursor, (int) $limit ) ) );
		return array_map( 'intval', $wpdb->get_col( $sql ) );
		// phpcs:enable
	}

	/**
	 * Count of scannable posts.
	 *
	 * @return int
	 */
	public static function count_scannable() {
		global $wpdb;
		$types  = Settings::get( 'post_types' );
		$format = 'SELECT COUNT( ID ) FROM ' . $wpdb->posts . ' WHERE post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ") AND post_status = 'publish' AND post_password = ''";
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $format is a prepared statement with dynamic IN placeholders; values all pass through prepare() below.
		$sql = $wpdb->prepare( $format, $types );
		return (int) $wpdb->get_var( $sql );
		// phpcs:enable
	}

	/**
	 * Compute or fetch the cached dashboard aggregate.
	 *
	 * Walks scanned posts in batches of 200; cached 12 h in findable_dashboard.
	 *
	 * @param bool $recompute Force recompute (used by tests).
	 * @return array Aggregate rows.
	 */
	public static function aggregate( $recompute = false ) {
		if ( ! $recompute ) {
			$cached = get_transient( 'findable_dashboard' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$types = Settings::get( 'post_types' );
		$agg   = array(
			'scannable' => self::count_scannable(),
			'scanned'   => 0,
			'stale'     => 0,
			'bands'     => array(
				'good'       => 0,
				'needs_work' => 0,
				'poor'       => 0,
			),
			'avg'       => array(
				'overall' => 0,
				'seo'     => 0,
				'aeo'     => 0,
				'geo'     => 0,
			),
			'weakest'   => array(),
			'failing'   => array(),
		);

		$sums         = array(
			'overall' => 0,
			'seo'     => 0,
			'aeo'     => 0,
			'geo'     => 0,
		);
			$failing  = array();
			$weakest  = array();
			$per_page = 200;
			$offset   = 0;

		while ( true ) {
			$batch = get_posts(
				array(
					'post_type'        => $types,
					'post_status'      => 'any',
					'posts_per_page'   => $per_page,
					'offset'           => $offset,
					'fields'           => 'ids',
					'meta_key'         => '_findable_result', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'suppress_filters' => true,
					'orderby'          => 'none',
				)
			);
			if ( empty( $batch ) ) {
					break;
			}
			foreach ( $batch as $post_id ) {
				$result = self::get( $post_id );
				if ( null === $result ) {
					continue;
				}
				++$agg['scanned'];
				$stale = ! self::is_fresh( $post_id, $result );
				if ( $stale ) {
						++$agg['stale'];
				}

				$overall          = (int) $result['overall'];
				$sums['overall'] += $overall;
				foreach ( array( 'seo', 'aeo', 'geo' ) as $family ) {
					if ( isset( $result['families'][ $family ]['score'] ) ) {
						$sums[ $family ] += (int) $result['families'][ $family ]['score'];
					}
				}
				++$agg['bands'][ Result::band( $overall ) ];

				$weakest[] = array(
					'id'      => (int) $post_id,
					'score'   => $overall,
					'at'      => isset( $result['scanned_at'] ) ? (int) $result['scanned_at'] : 0,
					'failing' => empty( $result['failing'] ) ? Result::failing( $result ) : (int) $result['failing'],
				);

				foreach ( Result::failing( $result ) as $check ) {
					$id = $check['id'];
					if ( ! isset( $failing[ $id ] ) ) {
						$failing[ $id ] = array(
							'id'    => $id,
							'f'     => $check['f'],
							'label' => $check['label'],
							'posts' => 0,
						);
					}
					++$failing[ $id ]['posts'];
				}
			}
			$offset += $per_page;
			if ( count( $batch ) < $per_page ) {
					break;
			}
		}

		if ( $agg['scanned'] > 0 ) {
			foreach ( $sums as $key => $sum ) {
					$agg['avg'][ $key ] = (int) round( $sum / $agg['scanned'] );
			}
		}

		usort(
			$weakest,
			static function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return $a['at'] - $b['at']; // Older scan first on ties.
				}
					return $a['score'] - $b['score'];
			}
		);
			$agg['weakest'] = array_slice( $weakest, 0, 10 );

		uasort(
			$failing,
			static function ( $a, $b ) {
					return $b['posts'] - $a['posts'];
			}
		);
			$agg['failing']       = array_slice( array_values( $failing ), 0, 10 );
			$agg['never_scanned'] = max( 0, $agg['scannable'] - $agg['scanned'] );

		set_transient( 'findable_dashboard', $agg, 12 * HOUR_IN_SECONDS );
		return $agg;
	}
}
