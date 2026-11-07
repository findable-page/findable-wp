<?php
/**
 * Shared test case with HTTP stubbing helpers.
 *
 * @package findable-wp
 */

/**
 * Base test case.
 */
class Findable_TestCase extends WP_UnitTestCase {

	/**
	 * Stub pre_http_request and capture calls.
	 *
	 * @param array|\WP_Error $responses Single response, error, or list of responses popped per call.
	 * @param array           $calls      Captured calls (by reference): url + args per request.
	 * @return array Captured calls container.
	 */
	protected function stub_http( $responses, &$calls = array() ) {
		$calls = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $responses, &$calls ) {
				$calls[] = array(
					'url'  => $url,
					'args' => $args,
				);
				if ( is_array( $responses ) && isset( $responses[0] ) && is_array( $responses[0] ) && ! isset( $responses['response'] ) && ! isset( $responses['body'] ) ) {
					// List of responses: pop the first.
					$response = array_shift( $responses );
				} else {
					$response = $responses;
				}
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				return $response;
			},
			10,
			3
		);
		return $calls;
	}

	/**
	 * Build a WP_HTTP response array.
	 *
	 * @param int    $code    Status code.
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 * @return array
	 */
	protected function http_response( $code, $body, $headers = array() ) {
		// Header names lowercased, as wp_remote_retrieve_header expects them.
		$processed = array();
		foreach ( (array) $headers as $name => $value ) {
			$processed[ strtolower( (string) $name ) ] = $value;
		}
		return array(
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'body'     => $body,
			'headers'  => $processed,
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A minimal valid scan response body.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	protected function scan_response( $overrides = array() ) {
		$check = static function ( $id, $pass, $applicable = null ) {
			$check = array(
				'id'    => $id,
				'label' => 'Check ' . $id,
				'pass'  => $pass,
			);
			if ( null !== $applicable ) {
				$check['applicable'] = $applicable;
			}
			return $check;
		};

		return array_merge(
			array(
				'url'       => 'https://example.com/hello/',
				'overall'   => 72,
				'seo'       => array(
					'score'  => 80,
					'passed' => 3,
					'total'  => 4,
					'checks' => array(
						$check( 'title_exists', true ),
						$check( 'title_length', false ),
						$check( 'desc_exists', true ),
						$check( 'geo_authoritative_links', true, false ),
					),
				),
				'aeo'       => array(
					'score'  => 60,
					'passed' => 1,
					'total'  => 2,
					'checks' => array(
						$check( 'aeo_faq', false ),
						$check( 'aeo_summary', true ),
					),
				),
				'geo'       => array(
					'score'  => 40,
					'passed' => 1,
					'total'  => 2,
					'checks' => array(
						$check( 'geo_citations', false ),
						$check( 'geo_trust', true ),
					),
				),
				'scannedAt' => '2026-01-01T00:00:00Z',
			),
			$overrides
		);
	}

	/**
	 * Create a published post of an enabled type.
	 *
	 * @param string $post_type Post type.
	 * @return int Post id.
	 */
	protected function create_scannable_post( $post_type = 'post' ) {
		return $this->factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => 'Hello',
			)
		);
	}
}
