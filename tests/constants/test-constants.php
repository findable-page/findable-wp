<?php
/**
 * Behaviour that depends on wp-config.php constants. Runs in its own PHPUnit
 * process (phpunit-constants.xml.dist) because constants cannot be undefined.
 *
 * @package findable-wp
 */

use Findable\Admin;
use Findable\Api_Client;
use Findable\Settings;

class Test_Constants extends Findable_TestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'findable_settings' );
		delete_option( 'findable_api_key' );
	}

	public function test_constant_api_key_overrides_saved_option() {
		update_option( 'findable_api_key', 'saved-key', false );
		$this->assertSame( 'const-key-123', Api_Client::instance()->api_key() );
	}

	public function test_constant_key_is_sent_over_plain_http_when_private_endpoints_allowed() {
		update_option( 'findable_settings', array( 'endpoint' => 'http://localhost:3000' ), false );
		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ), $calls );

		Api_Client::instance()->scan( 'https://example.com/hello/' );

		$this->assertSame( 'Bearer const-key-123', $calls[0]['args']['headers']['Authorization'] );
	}

	public function test_private_endpoint_constant_uses_non_safe_transport() {
		update_option( 'findable_settings', array( 'endpoint' => 'http://localhost:3000' ), false );
		$this->stub_http( $this->http_response( 200, wp_json_encode( $this->scan_response() ) ), $calls );

		Api_Client::instance()->scan( 'https://example.com/hello/' );
		$this->assertArrayNotHasKey( 'reject_unsafe_urls', array_filter( $calls[0]['args'] ) );

		$this->stub_http( $this->http_response( 200, wp_json_encode( array( 'status' => 'ok', 'name' => 'findable', 'version' => '1' ) ) ), $calls );
		Api_Client::instance()->health();
		$this->assertSame( 'GET', $calls[0]['args']['method'] );
	}

	public function test_error_message_never_leaks_constant_key() {
		$this->stub_http( $this->http_response( 400, wp_json_encode( array( 'error' => 'bad const-key-123 value' ) ) ) );
		$error = Api_Client::instance()->scan( 'https://example.com/hello/' );
		$this->assertStringNotContainsString( 'const-key-123', $error->get_error_message() );
		$this->assertStringContainsString( '***', $error->get_error_message() );
	}

	public function test_saving_settings_does_not_store_a_key_when_constant_defined() {
		Settings::sanitize( array( 'findable_api_key' => 'typed-key' ) );
		$this->assertFalse( get_option( 'findable_api_key' ) );

		update_option( 'findable_api_key', 'saved-key', false );
		Settings::sanitize( array( 'findable_remove_key' => '1' ) );
		$this->assertSame( 'saved-key', get_option( 'findable_api_key' ) );
	}

	public function test_private_endpoint_constant_suppresses_port_warning() {
		update_option( 'findable_settings', array( 'endpoint' => 'http://localhost:3000' ), false );
		$this->assertFalse( Settings::endpoint_port_unreachable() );
	}

	public function test_settings_field_shows_constant_notice_instead_of_input() {
		ob_start();
		Admin::render_field_api_key();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Set in wp-config.php', $html );
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( 'findable_api_key', $html );
	}
}
