<?php
/**
 * Single HTTP client for the Findable API. The only class allowed to call wp_remote_*.
 *
 * @package findable-wp
 */

namespace Findable;

defined( 'ABSPATH' ) || exit;

/**
 * Class Api_Client
 */
class Api_Client {

	/**
	 * Singleton instance.
	 *
	 * @var Api_Client|null
	 */
	private static $instance = null;

	/**
	 * Keyphrase length limit enforced by the API.
	 */
	const MAX_KEYPHRASE = 100;

	/**
	 * Supported check-text languages.
	 *
	 * @var array
	 */
	const I18N_LANGS = array( 'de', 'es', 'ru', 'tr' );

	/**
	 * Instance accessor.
	 *
	 * @return Api_Client
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Scan one URL.
	 *
	 * @param string $url        Public URL to scan.
	 * @param string $keyphrase  Optional keyphrase.
	 * @return array|\WP_Error Normalized result or error.
	 */
	public function scan( $url, $keyphrase = '' ) {
		$keyphrase = trim( (string) $keyphrase );
		if ( mb_strlen( $keyphrase ) > self::MAX_KEYPHRASE ) {
			return new \WP_Error( 'findable_invalid_keyphrase', __( 'The keyphrase is too long. Use at most 100 characters.', 'findable' ) );
		}

		$body = array( 'url' => $url );
		if ( '' !== $keyphrase ) {
			$body['keyphrase'] = $keyphrase;
		}

		$response = $this->request(
			'/api/scan',
			array(
				'method'  => 'POST',
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return Result::normalize( $response );
	}

	/**
	 * Health check.
	 *
	 * @return array|\WP_Error array( name, version ) or error.
	 */
	public function health() {
		$response = $this->request( '/health', array( 'method' => 'GET' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! is_array( $response ) || ! isset( $response['status'] ) || 'ok' !== $response['status'] ) {
			return new \WP_Error( 'findable_bad_response', __( 'The Findable API returned an unexpected response.', 'findable' ) );
		}
		return array(
			'name'    => isset( $response['name'] ) && is_string( $response['name'] ) ? sanitize_text_field( $response['name'] ) : '',
			'version' => isset( $response['version'] ) && is_string( $response['version'] ) ? sanitize_text_field( $response['version'] ) : '',
		);
	}

	/**
	 * Fetch localized check text.
	 *
	 * @param string $lang Two-letter language code.
	 * @return array|\WP_Error Map of check id => array( name, fix ), or error.
	 */
	public function check_text( $lang ) {
		$lang = sanitize_key( (string) $lang );
		if ( ! in_array( $lang, self::I18N_LANGS, true ) ) {
			return array();
		}

		$response = $this->request( '/i18n/' . $lang . '.json', array( 'method' => 'GET' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! is_array( $response ) ) {
			return new \WP_Error( 'findable_bad_response', __( 'The Findable API returned an unexpected response.', 'findable' ) );
		}

		$map = array();
		foreach ( $response as $id => $text ) {
			if ( ! is_string( $id ) || ! preg_match( Result::ID_PATTERN, $id ) || ! is_array( $text ) ) {
				continue;
			}
			$map[ $id ] = array(
				'name' => isset( $text['name'] ) && is_string( $text['name'] ) ? mb_substr( sanitize_text_field( $text['name'] ), 0, Result::MAX_LABEL ) : '',
				'fix'  => isset( $text['fix'] ) && is_string( $text['fix'] ) ? mb_substr( sanitize_text_field( $text['fix'] ), 0, 500 ) : '',
			);
		}
		return $map;
	}

	/**
	 * Perform one HTTP request and map errors.
	 *
	 * @param string $path Endpoint-relative path.
	 * @param array  $args Request args (method, headers, body).
	 * @return array|\WP_Error Decoded JSON body or error.
	 */
	private function request( $path, $args ) {
		$url      = Settings::endpoint() . $path;
		$defaults = array(
			'timeout'             => 30,
			'redirection'         => 0,
			'limit_response_size' => MB_IN_BYTES,
			'user-agent'          => 'Findable-WP/' . FINDABLE_VERSION . '; ' . home_url(),
		);
		$args     = wp_parse_args( $args, $defaults );

		$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$key     = $this->api_key();
		if ( '' !== $key && $this->key_allowed() ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		if ( $headers ) {
			$args['headers'] = $headers;
		}

		$private = defined( 'FINDABLE_ALLOW_PRIVATE_ENDPOINT' ) && FINDABLE_ALLOW_PRIVATE_ENDPOINT;

		if ( 'GET' === strtoupper( (string) $args['method'] ) ) {
			$response = $private ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
		} else {
			$response = $private ? wp_remote_post( $url, $args ) : wp_safe_remote_post( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'findable_http', $this->mask_key( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			return is_array( $body ) ? $body : new \WP_Error( 'findable_bad_response', __( 'The Findable API returned an unexpected response.', 'findable' ) );
		}

		$api_error = '';
		if ( is_array( $body ) && isset( $body['error'] ) && is_string( $body['error'] ) ) {
			$api_error = mb_substr( sanitize_text_field( $body['error'] ), 0, 300 );
		}

		if ( 429 === $code ) {
			$retry   = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$retry   = max( 30, min( 600, $retry > 0 ? $retry : 60 ) );
			$message = __( 'The Findable API is rate limiting this site (10 scans per minute). Try again shortly.', 'findable' );
			return new \WP_Error(
				'findable_rate_limited',
				$api_error ? $this->mask_key( $api_error ) : $message,
				array( 'retry_after' => $retry )
			);
		}

		if ( 401 === $code || 403 === $code ) {
			return new \WP_Error( 'findable_unauthorized', __( 'The Findable API rejected the API key.', 'findable' ) );
		}

		if ( 400 === $code && '' !== $api_error ) {
			return new \WP_Error( 'findable_blocked', $this->mask_key( $api_error ) );
		}

		if ( $code >= 500 && $code < 600 ) {
			return new \WP_Error( 'findable_server', $api_error ? $this->mask_key( $api_error ) : __( 'The Findable API had a server error. Try again later.', 'findable' ) );
		}

		return new \WP_Error( 'findable_bad_response', $api_error ? $this->mask_key( $api_error ) : __( 'The Findable API returned an unexpected response.', 'findable' ) );
	}

	/**
	 * The configured API key, constant wins.
	 *
	 * @return string
	 */
	public function api_key() {
		if ( defined( 'FINDABLE_API_KEY' ) && is_string( FINDABLE_API_KEY ) ) {
			return FINDABLE_API_KEY;
		}
		$key = get_option( 'findable_api_key', '' );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Whether the key may be sent to this endpoint.
	 *
	 * @return bool
	 */
	private function key_allowed() {
		if ( 'https' === wp_parse_url( Settings::endpoint(), PHP_URL_SCHEME ) ) {
			return true;
		}
		return defined( 'FINDABLE_ALLOW_PRIVATE_ENDPOINT' ) && FINDABLE_ALLOW_PRIVATE_ENDPOINT;
	}

	/**
	 * Replace the API key with *** in an error message.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function mask_key( $message ) {
		$key = $this->api_key();
		if ( '' !== $key && false !== strpos( $message, $key ) ) {
			$message = str_replace( $key, '***', $message );
		}
		return $message;
	}
}
