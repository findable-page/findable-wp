<?php
/**
 * Settings sanitize tests.
 *
 * @package findable-wp
 */

use Findable\Settings;

class Test_Settings extends Findable_TestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'findable_settings' );
		delete_option( 'findable_api_key' );
	}

	public function test_defaults() {
		$this->assertSame( 'https://findable.page', Settings::get( 'endpoint' ) );
		$this->assertSame( array( 'post', 'page' ), Settings::get( 'post_types' ) );
		$this->assertSame( 7, Settings::get( 'cache_ttl_days' ) );
		$this->assertSame( 5, Settings::get( 'bulk_per_minute' ) );
		$this->assertFalse( Settings::get( 'scan_on_publish' ) );
		$this->assertFalse( Settings::get( 'delete_data_on_uninstall' ) );
	}

	public function test_pasted_scan_url_stripped() {
		$this->assertSame( 'https://findable.page', Settings::sanitize_endpoint( 'https://findable.page/api/scan/' ) );
	}

	public function test_self_hosted_with_port_kept() {
		$this->assertSame( 'https://findable.example.com:8080', Settings::sanitize_endpoint( 'https://findable.example.com:8080' ) );
	}

	public function test_bad_scheme_rejected() {
		foreach ( array( 'javascript:alert(1)', 'ftp://host' ) as $bad ) {
			$error = Settings::sanitize_endpoint( $bad );
			$this->assertWPError( $error );
			$this->assertSame( 'Enter an http or https URL.', $error->get_error_message() );
		}
	}

	public function test_credentials_rejected() {
		$error = Settings::sanitize_endpoint( 'https://user:pass@scan.example.com' );
		$this->assertWPError( $error );
		$this->assertSame( 'Do not put credentials in the endpoint URL. Use the API key field.', $error->get_error_message() );
	}

	public function test_sanitize_clamps_out_of_range() {
		$settings = Settings::sanitize(
			array(
				'bulk_per_minute' => 50,
				'cache_ttl_days'  => 0,
			)
		);
		$this->assertSame( 8, $settings['bulk_per_minute'] );
		$this->assertSame( 1, $settings['cache_ttl_days'] );
	}

	public function test_unknown_post_type_dropped() {
		$settings = Settings::sanitize(
			array(
				'post_types' => array( 'post', 'page', 'nav_menu_item' ),
			)
		);
		$this->assertSame( array( 'post', 'page' ), $settings['post_types'] );
	}

	public function test_array_endpoint_keeps_previous_value() {
		$settings = Settings::sanitize( array( 'endpoint' => array( 'https://invalid.example' ) ) );
		$this->assertSame( 'https://findable.page', $settings['endpoint'] );
		$this->assertNotEmpty( get_settings_errors( 'findable_settings', false ) );
	}

	public function test_invalid_endpoint_keeps_previous_value() {
		update_option( 'findable_settings', array( 'endpoint' => 'https://findable.page' ), false );
		$settings = Settings::sanitize( array( 'endpoint' => 'gopher://nope' ) );
		$this->assertSame( 'https://findable.page', $settings['endpoint'] );
		$errors = get_settings_errors( 'findable_settings', false );
		$this->assertNotEmpty( $errors );
	}

	public function test_api_key_empty_submit_keeps_key() {
		update_option( 'findable_api_key', 'sekrit123', false );
		Settings::sanitize( array( 'findable_api_key' => '' ) );
		$this->assertSame( 'sekrit123', get_option( 'findable_api_key' ) );
	}

	public function test_api_key_remove_checkbox_deletes() {
		update_option( 'findable_api_key', 'sekrit123', false );
		Settings::sanitize( array( 'findable_remove_key' => '1' ) );
		$this->assertFalse( get_option( 'findable_api_key' ) );
	}

	public function test_api_key_new_value_saved() {
		Settings::sanitize( array( 'findable_api_key' => ' new-key ' ) );
		$this->assertSame( 'new-key', get_option( 'findable_api_key' ) );
	}

	public function test_api_key_saved_even_when_endpoint_invalid() {
		Settings::sanitize( array( 'endpoint' => 'ftp://invalid', 'findable_api_key' => 'new-key' ) );
		$this->assertSame( 'new-key', get_option( 'findable_api_key' ) );
	}

	public function test_endpoint_change_clears_dashboard_transient() {
		update_option( 'findable_settings', array( 'endpoint' => 'https://findable.page' ), false );
		set_transient( 'findable_dashboard', array( 'x' => 1 ) );
		Settings::sanitize( array( 'endpoint' => 'https://scan.example.com' ) );
		$this->assertFalse( get_transient( 'findable_dashboard' ) );
	}

	public function test_form_submit_can_clear_all_checkboxes_and_post_types() {
		update_option( 'findable_settings', array_merge( Settings::defaults(), array( 'scan_on_publish' => true, 'delete_data_on_uninstall' => true ) ), false );
		$settings = Settings::sanitize( array( '_form' => '1' ) );
		$this->assertFalse( $settings['scan_on_publish'] );
		$this->assertFalse( $settings['delete_data_on_uninstall'] );
		$this->assertSame( array(), $settings['post_types'] );
	}

	public function test_post_type_change_clears_dashboard_transient() {
		set_transient( 'findable_dashboard', array( 'x' => 1 ) );
		Settings::sanitize( array( '_form' => '1', 'post_types' => array( 'post' ) ) );
		$this->assertFalse( get_transient( 'findable_dashboard' ) );
	}

	public function test_endpoint_port_unreachable_flag() {
		update_option( 'findable_settings', array( 'endpoint' => 'https://findable.example.com:8443' ), false );
		$this->assertTrue( Settings::endpoint_port_unreachable() );

		update_option( 'findable_settings', array( 'endpoint' => 'https://findable.example.com:8080' ), false );
		$this->assertFalse( Settings::endpoint_port_unreachable() );
	}

	public function test_endpoint_rejects_query_fragment_and_unparseable_values() {
		foreach ( array( 'https://x.example.com?a=1', 'https://x.example.com#frag' ) as $url ) {
			$this->assertSame( 'findable_endpoint', Settings::sanitize_endpoint( $url )->get_error_code(), $url );
		}
		foreach ( array( '', '   ', '/api/scan', 'not a url', 'http://' ) as $url ) {
			$this->assertWPError( Settings::sanitize_endpoint( $url ), $url );
		}
		$this->assertWPError( Settings::sanitize_endpoint( 'https://user:pw@x.example.com' ) );
	}

	public function test_non_array_input_changes_nothing() {
		$this->assertSame( Settings::all(), Settings::sanitize( 'garbage' ) );
	}

	public function test_non_array_stored_post_types_fall_back_to_defaults() {
		update_option( 'findable_settings', array( 'post_types' => 'garbage' ), false );
		$this->assertSame( array( 'post', 'page' ), Settings::get( 'post_types' ) );
	}

	public function test_register_exposes_option_with_sanitizer() {
		Settings::register();
		$registered = get_registered_settings();
		$this->assertSame( array( Settings::class, 'sanitize' ), $registered['findable_settings']['sanitize_callback'] );
		$this->assertFalse( $registered['findable_settings']['show_in_rest'] );
	}

	public function test_endpoint_port_defaults_for_http_and_private_constant_in_other_tests() {
		update_option( 'findable_settings', array( 'endpoint' => 'http://findable.example.com' ), false );
		$this->assertFalse( Settings::endpoint_port_unreachable() );
	}

	public function test_endpoint_with_characters_that_escape_to_nothing_is_rejected() {
		$this->assertSame( 'findable_endpoint', Settings::sanitize_endpoint( 'http://<>' )->get_error_code() );
		$this->assertSame( 'findable_endpoint', Settings::sanitize_endpoint( 'https://"' )->get_error_code() );
	}
}
