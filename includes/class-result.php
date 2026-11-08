<?php
/**
 * Normalize and validate Findable scan responses. Pure, no I/O.
 *
 * @package findable-wp
 */

namespace Findable;

defined( 'ABSPATH' ) || exit;

/**
 * Class Result
 *
 * Normalizes an API scan response into the stored scan result shape and
 * validates every field. Untrusted input in, trusted array|WP_Error out.
 */
class Result {

	/**
	 * Maximum number of checks kept per result.
	 */
	const MAX_CHECKS = 400;

	/**
	 * Maximum label length in characters.
	 */
	const MAX_LABEL = 200;

	/**
	 * Check id pattern.
	 */
	const ID_PATTERN = '/^[a-z0-9_]{1,64}$/';

	/**
	 * Normalize a decoded API scan response.
	 *
	 * @param mixed $response Decoded JSON body from POST /api/scan.
	 * @return array|\WP_Error Normalized result or error findable_bad_response.
	 */
	public static function normalize( $response ) {
		if ( ! is_array( $response ) ) {
			return new \WP_Error( 'findable_bad_response', __( 'The Findable API returned an unexpected response.', 'findable' ) );
		}

		if ( ! isset( $response['overall'] ) || ! is_numeric( $response['overall'] ) ) {
			return new \WP_Error( 'findable_bad_response', __( 'The Findable API returned an unexpected response.', 'findable' ) );
		}

		$families = array();
		$checks   = array();
		$passing  = 0;
		$failing  = 0;

		foreach ( array( 'seo', 'aeo', 'geo' ) as $family ) {
			if ( ! isset( $response[ $family ] ) || ! is_array( $response[ $family ] )
				|| ! isset( $response[ $family ]['score'] ) || ! is_numeric( $response[ $family ]['score'] )
				|| ! isset( $response[ $family ]['checks'] ) || ! is_array( $response[ $family ]['checks'] ) ) {
				return new \WP_Error( 'findable_bad_response', __( 'The Findable API returned an unexpected response.', 'findable' ) );
			}

			$families[ $family ] = array(
				'score'  => self::clamp_score( (int) $response[ $family ]['score'] ),
				'passed' => 0,
				'total'  => 0,
			);

			foreach ( $response[ $family ]['checks'] as $check ) {
				if ( count( $checks ) >= self::MAX_CHECKS ) {
					break 2;
				}
				$check = self::normalize_check( $check, $family );
				if ( null === $check ) {
					continue;
				}
				$checks[] = $check;
				if ( 'na' === $check['s'] ) {
					continue;
				}
				++$families[ $family ]['total'];
				if ( 'pass' === $check['s'] ) {
					++$families[ $family ]['passed'];
					++$passing;
				} elseif ( 'fail' === $check['s'] ) {
					++$failing;
				}
			}
		}

		return array(
			'v'              => 1,
			'url'            => isset( $response['url'] ) && is_string( $response['url'] ) ? esc_url_raw( $response['url'] ) : '',
			'overall'        => self::clamp_score( (int) $response['overall'] ),
			'families'       => $families,
			'checks'         => $checks,
			'passing'        => $passing,
			'failing'        => $failing,
			'keyphrase'      => isset( $response['keyphrase'] ) && is_string( $response['keyphrase'] ) ? sanitize_text_field( $response['keyphrase'] ) : '',
			'scanned_at'     => time(),
			'endpoint'       => Settings::get( 'endpoint' ),
			'api_scanned_at' => isset( $response['scannedAt'] ) && is_string( $response['scannedAt'] ) ? sanitize_text_field( $response['scannedAt'] ) : '',
		);
	}

	/**
	 * Normalize one check object or drop it.
	 *
	 * @param mixed  $check   Decoded check object.
	 * @param string $family  Family key seo|aeo|geo.
	 * @return array|null Check row or null when invalid.
	 */
	private static function normalize_check( $check, $family ) {
		if ( ! is_array( $check ) ) {
			return null;
		}
		if ( ! isset( $check['id'] ) || ! is_string( $check['id'] ) || ! preg_match( self::ID_PATTERN, $check['id'] ) ) {
			return null;
		}
		if ( ! isset( $check['pass'] ) || ! is_bool( $check['pass'] ) ) {
			return null;
		}

		$label = isset( $check['label'] ) && is_string( $check['label'] )
			? mb_substr( sanitize_text_field( $check['label'] ), 0, self::MAX_LABEL )
			: $check['id'];

		return array(
			'id'    => $check['id'],
			'f'     => $family,
			'label' => $label,
			's'     => ( isset( $check['applicable'] ) && false === $check['applicable'] ) ? 'na' : ( $check['pass'] ? 'pass' : 'fail' ),
		);
	}

	/**
	 * Clamp a score to 0-100.
	 *
	 * @param int $score Score.
	 * @return int
	 */
	private static function clamp_score( $score ) {
		return max( 0, min( 100, (int) $score ) );
	}

	/**
	 * Map a score to its band key.
	 *
	 * @param int $score Overall score 0-100.
	 * @return string poor|needs_work|good.
	 */
	public static function band( $score ) {
		if ( $score >= 80 ) {
			return 'good';
		}
		return ( $score >= 50 ) ? 'needs_work' : 'poor';
	}

	/**
	 * Human label for a band key.
	 *
	 * @param string $band Band key from band().
	 * @return string Translated label.
	 */
	public static function band_label( $band ) {
		switch ( $band ) {
			case 'good':
				return __( 'Good', 'findable' );
			case 'needs_work':
				return __( 'Needs work', 'findable' );
			default:
				return __( 'Poor', 'findable' );
		}
	}

	/**
	 * Failing checks of a result.
	 *
	 * @param array $result Normalized result.
	 * @return array Check rows with s === fail.
	 */
	public static function failing( $result ) {
		if ( empty( $result['checks'] ) || ! is_array( $result['checks'] ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$result['checks'],
				static function ( $check ) {
					return isset( $check['s'] ) && 'fail' === $check['s'];
				}
			)
		);
	}
}
