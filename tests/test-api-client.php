<?php
/**
 * Api_Client tests via pre_http_request.
 *
 * @package findable-wp
 */

use Findable\Api_Client;

class Test_Api_Client extends Findable_TestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'findable_settings' );
		delete_option( 'findable_api_key' );
	}

	public function test_scan_body_without_keyphrase_is_exact() {
		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ), $calls );

		$result = Api_Client::instance()->scan( 'https://example.com/hello/' );

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'https://findable.page/api/scan', $calls[0]['url'] );
		$this->assertSame( '{"url":"https:\/\/example.com\/hello\/"}', $calls[0]['args']['body'] );
		$this->assertSame( 'application/json', $calls[0]['args']['headers']['Content-Type'] );
		$this->assertSame( 'https://example.com/hello/', $result['url'] );
	}

	public function test_scan_body_trims_keyphrase() {
		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ), $calls );

		Api_Client::instance()->scan( 'https://example.com/hello/', '  coffee grinder ' );

		$body = json_decode( $calls[0]['args']['body'], true );
		$this->assertSame( 'coffee grinder', $body['keyphrase'] );
	}

	public function test_overlong_keyphrase_makes_no_request() {
		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ), $calls );

		$error = Api_Client::instance()->scan( 'https://example.com/hello/', str_repeat( 'a', 101 ) );

		$this->assertWPError( $error );
		$this->assertSame( 'findable_invalid_keyphrase', $error->get_error_code() );
		$this->assertCount( 0, $calls );
	}

	public function test_user_agent_and_limits() {
		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ), $calls );

		Api_Client::instance()->scan( 'https://example.com/hello/' );

		$this->assertSame( 30, $calls[0]['args']['timeout'] );
		$this->assertSame( 0, $calls[0]['args']['redirection'] );
		$this->assertTrue( $calls[0]['args']['reject_unsafe_urls'] );
		$this->assertSame( MB_IN_BYTES, $calls[0]['args']['limit_response_size'] );
		$this->assertSame( 'Findable-WP/1.0.0; ' . home_url(), $calls[0]['args']['user-agent'] );
	}

	public function test_transport_error_maps_to_findable_http() {
		$this->stub_http( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );

		$this->assertWPError( $error );
		$this->assertSame( 'findable_http', $error->get_error_code() );
	}

	public function test_429_default_retry_after_60() {
		$this->stub_http( $this->http_response( 429, wp_json_encode( array( 'error' => 'too many scans, try again in a minute' ) ) ) );

		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );

		$this->assertWPError( $error );
		$this->assertSame( 'findable_rate_limited', $error->get_error_code() );
		$this->assertSame( 60, $error->get_error_data()['retry_after'] );
	}

	public function test_429_retry_after_header_clamped() {
		$this->stub_http( $this->http_response( 429, wp_json_encode( array( 'error' => 'slow down' ) ), array( 'Retry-After' => '9000' ) ) );

		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );
		$this->assertSame( 600, $error->get_error_data()['retry_after'] );

		$this->stub_http( $this->http_response( 429, '', array( 'Retry-After' => '120' ) ) );
		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );
		$this->assertSame( 120, $error->get_error_data()['retry_after'] );

		$this->stub_http( $this->http_response( 429, '', array( 'Retry-After' => '1' ) ) );
		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );
		$this->assertSame( 30, $error->get_error_data()['retry_after'] );

		$this->stub_http( $this->http_response( 429, '', array( 'Retry-After' => '-5' ) ) );
		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );
		$this->assertSame( 60, $error->get_error_data()['retry_after'] );
	}

	public function test_401_and_403_map_to_unauthorized() {
		foreach ( array( 401, 403 ) as $code ) {
			$this->stub_http( $this->http_response( $code, wp_json_encode( array( 'error' => 'bad key' ) ) ) );
			$error = Api_Client::instance()->scan( 'https://example.com/hello/' );
			$this->assertSame( 'findable_unauthorized', $error->get_error_code() );
			$this->assertSame( 'The Findable API rejected the API key.', $error->get_error_message() );
		}
	}

	public function test_400_json_error_maps_to_blocked_sanitized() {
		$this->stub_http( $this->http_response( 400, wp_json_encode( array( 'error' => 'target resolves to a private address <b>x</b>' ) ) ) );

		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );

		$this->assertSame( 'findable_blocked', $error->get_error_code() );
		$this->assertStringContainsString( 'target resolves to a private address x', $error->get_error_message() );
		$this->assertStringNotContainsString( '<b>', $error->get_error_message() );
	}

	public function test_500_maps_to_server() {
		$this->stub_http( $this->http_response( 500, wp_json_encode( array( 'error' => 'audit failed' ) ) ) );

		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );
		$this->assertSame( 'findable_server', $error->get_error_code() );
	}

	public function test_html_body_maps_to_bad_response() {
		$this->stub_http( $this->http_response( 200, '<html><body>gateway</body></html>' ) );

		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );
		$this->assertSame( 'findable_bad_response', $error->get_error_code() );
	}

	public function test_health_ok() {
		$this->stub_http(
			$this->http_response(
				200,
				wp_json_encode(
					array(
						'status'  => 'ok',
						'name'    => 'findable',
						'version' => '0.3.0',
					)
				)
			),
			$calls
		);

		$health = Api_Client::instance()->health();

		$this->assertSame(
			array(
				'name'    => 'findable',
				'version' => '0.3.0',
			),
			$health
		);
		$this->assertSame( 'https://findable.page/health', $calls[0]['url'] );
	}

	public function test_health_wrong_service() {
		$this->stub_http( $this->http_response( 200, wp_json_encode( array( 'hello' => 'world' ) ) ) );

		$this->assertWPError( Api_Client::instance()->health() );
		$this->assertSame( 'findable_bad_response', Api_Client::instance()->health()->get_error_code() );
	}

	public function test_check_text_unsupported_language_no_request() {
		$this->stub_http( $this->http_response( 200, '{}' ), $calls );

		$result = Api_Client::instance()->check_text( 'fr' );

		$this->assertSame( array(), $result );
		$this->assertCount( 0, $calls );
	}

	public function test_check_text_sanitizes_fields() {
		$body = wp_json_encode(
			array(
				'title_exists' => array(
					'name' => 'Titel <b>x</b>',
					'fix'  => 'Füge einen Titel hinzu.',
				),
				'garbage'      => 'not-an-object',
			)
		);
		$this->stub_http( $this->http_response( 200, $body ), $calls );

		$map = Api_Client::instance()->check_text( 'de' );

		$this->assertSame( 'https://findable.page/i18n/de.json', $calls[0]['url'] );
		$this->assertSame( 'Titel x', $map['title_exists']['name'] );
		$this->assertSame( 'Füge einen Titel hinzu.', $map['title_exists']['fix'] );
		$this->assertArrayNotHasKey( 'garbage', $map );
	}

	public function test_api_key_sent_over_https_only() {
		update_option( 'findable_api_key', 'sekrit123', false );

		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ), $https_calls );
		Api_Client::instance()->scan( 'https://example.com/hello/' );
		$this->assertSame( 'Bearer sekrit123', $https_calls[0]['args']['headers']['Authorization'] );

		update_option( 'findable_settings', array( 'endpoint' => 'http://scan.example.com' ), false );
		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ), $http_calls );
		Api_Client::instance()->scan( 'http://example.com/hello/' );
		$this->assertArrayNotHasKey( 'Authorization', $http_calls[0]['args']['headers'] );
	}

	public function test_error_message_masks_api_key() {
		update_option( 'findable_api_key', 'sekrit123', false );
		$this->stub_http( new WP_Error( 'http_request_failed', 'header Authorization: Bearer sekrit123 rejected' ) );

		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );

		$this->assertSame( 'findable_http', $error->get_error_code() );
		$this->assertStringNotContainsString( 'sekrit123', $error->get_error_message() );
		$this->assertStringContainsString( '***', $error->get_error_message() );
	}

	public function test_5xx_without_json_uses_generic_message() {
		$this->stub_http( $this->http_response( 503, 'oops' ) );
		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );
		$this->assertSame( 'findable_server', $error->get_error_code() );
		$this->assertSame( 'The Findable API had a server error. Try again later.', $error->get_error_message() );
	}

	public function test_unexpected_status_maps_to_bad_response() {
		$this->stub_http( $this->http_response( 418, wp_json_encode( array( 'error' => 'teapot' ) ) ) );
		$this->assertSame( 'teapot', Api_Client::instance()->scan( 'https://example.com/hello/' )->get_error_message() );

		$this->stub_http( $this->http_response( 302, '' ) );
		$this->assertSame( 'findable_bad_response', Api_Client::instance()->scan( 'https://example.com/hello/' )->get_error_code() );
	}

	public function test_rate_limit_without_body_uses_default_message() {
		$this->stub_http( $this->http_response( 429, 'slow down' ) );
		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );
		$this->assertStringContainsString( '10 scans per minute', $error->get_error_message() );
	}

	public function test_check_text_propagates_errors_and_returns_map() {
		$this->stub_http( $this->http_response( 500, 'down' ) );
		$this->assertWPError( Api_Client::instance()->check_text( 'de' ) );

		$this->stub_http( $this->http_response( 200, wp_json_encode( array( 'BAD ID' => array( 'name' => 'x' ), 'ok_id' => 'not-array', 'good_id' => array( 'name' => 'N', 'fix' => 'F' ) ) ) ) );
		$this->assertSame( array( 'good_id' => array( 'name' => 'N', 'fix' => 'F' ) ), Api_Client::instance()->check_text( 'de' ) );
	}

	public function test_check_text_ignores_non_string_fields() {
		$this->stub_http( $this->http_response( 200, wp_json_encode( array( 'good_id' => array( 'name' => 5, 'fix' => array() ) ) ) ) );
		$this->assertSame( array( 'good_id' => array( 'name' => '', 'fix' => '' ) ), Api_Client::instance()->check_text( 'de' ) );
	}

	public function test_non_string_stored_key_is_ignored() {
		update_option( 'findable_api_key', array( 'x' ), false );
		$this->assertSame( '', Api_Client::instance()->api_key() );
	}

	public function test_json_array_body_with_200_but_scalar_is_bad_response() {
		$this->stub_http( $this->http_response( 200, '"just a string"' ) );
		$this->assertSame( 'findable_bad_response', Api_Client::instance()->scan( 'https://example.com/hello/' )->get_error_code() );
	}

	public function test_get_requests_use_safe_get_by_default() {
		$this->stub_http( $this->http_response( 200, wp_json_encode( array( 'status' => 'ok', 'name' => 'findable', 'version' => '1' ) ) ), $calls );
		Api_Client::instance()->health();
		$this->assertSame( 'GET', $calls[0]['args']['method'] );
		$this->assertTrue( $calls[0]['args']['reject_unsafe_urls'] );
	}
}
