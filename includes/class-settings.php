<?php
/**
 * Settings storage, registration and sanitization.
 *
 * @package findable-wp
 */

namespace Findable;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'endpoint'                 => FINDABLE_DEFAULT_ENDPOINT,
			'post_types'               => array( 'post', 'page' ),
			'cache_ttl_days'           => 7,
			'bulk_per_minute'          => 5,
			'scan_on_publish'          => false,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Get one setting merged over defaults.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$settings = wp_parse_args( get_option( 'findable_settings', array() ), self::defaults() );
		$settings = self::intersect_post_types( $settings );
		return $settings[ $key ];
	}

	/**
	 * Get all settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		return self::intersect_post_types( wp_parse_args( get_option( 'findable_settings', array() ), self::defaults() ) );
	}

	/**
	 * Current endpoint.
	 *
	 * @return string
	 */
	public static function endpoint() {
		$endpoint = self::get( 'endpoint' );
		return is_string( $endpoint ) && '' !== $endpoint ? $endpoint : FINDABLE_DEFAULT_ENDPOINT;
	}

	/**
	 * Drop post types that are not public or are attachments.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	private static function intersect_post_types( $settings ) {
		$public = get_post_types( array( 'public' => true ), 'names' );
		unset( $public['attachment'] );

		if ( ! is_array( $settings['post_types'] ) ) {
			$settings['post_types'] = array( 'post', 'page' );
		}
		$types                  = array_values( array_intersect( $settings['post_types'], $public ) );
		$settings['post_types'] = $types ? $types : array( 'post', 'page' );
		return $settings;
	}

	/**
	 * Sanitize callback for register_setting.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( $input ) {
		$old     = self::all();
		$clean   = $old;
		$changed = false;
		$input   = is_array( $input ) ? $input : array();

		if ( isset( $input['endpoint'] ) ) {
			$endpoint = self::sanitize_endpoint( sanitize_text_field( wp_unslash( $input['endpoint'] ) ) );
			if ( is_wp_error( $endpoint ) ) {
				add_settings_error( 'findable_settings', 'findable_endpoint', $endpoint->get_error_message() );
			} elseif ( $endpoint !== $old['endpoint'] ) {
				$clean['endpoint'] = $endpoint;
				$changed           = true;
				delete_transient( 'findable_dashboard' );
			}
		}

		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$public = get_post_types( array( 'public' => true ), 'names' );
			unset( $public['attachment'] );
			$types               = array_values( array_intersect( array_map( 'sanitize_key', wp_unslash( $input['post_types'] ) ), $public ) );
			$clean['post_types'] = $types ? $types : array( 'post', 'page' );
			$changed             = true;
		}

		if ( isset( $input['cache_ttl_days'] ) ) {
			$clean['cache_ttl_days'] = max( 1, min( 90, absint( $input['cache_ttl_days'] ) ) );
			$changed                 = true;
		}

		if ( isset( $input['bulk_per_minute'] ) ) {
			$clean['bulk_per_minute'] = max( 1, min( 8, absint( $input['bulk_per_minute'] ) ) );
			$changed                  = true;
		}

		if ( isset( $input['scan_on_publish'] ) ) {
			$clean['scan_on_publish'] = rest_sanitize_boolean( $input['scan_on_publish'] );
			$changed                  = true;
		}

		if ( isset( $input['delete_data_on_uninstall'] ) ) {
			$clean['delete_data_on_uninstall'] = rest_sanitize_boolean( $input['delete_data_on_uninstall'] );
			$changed                           = true;
		}

		self::handle_api_key( $input );
		return apply_filters( 'findable_settings_sanitized', $clean, $changed );
	}

	/**
	 * Sanitize and validate an endpoint URL.
	 *
	 * @param string $raw Raw endpoint value.
	 * @return string|\WP_Error Clean URL or error.
	 */
	public static function sanitize_endpoint( $raw ) {
		$url = trim( $raw );
		$url = rtrim( $url, '/' );
		$url = preg_replace( '#/api/scan$#i', '', $url );
		$url = untrailingslashit( trim( (string) $url ) );

		if ( '' === $url ) {
			return new \WP_Error( 'findable_endpoint', __( 'Enter an http or https URL.', 'findable' ) );
		}

		$parts = wp_parse_url( $url );
		if ( false === $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new \WP_Error( 'findable_endpoint', __( 'Enter an http or https URL.', 'findable' ) );
		}
		if ( ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'findable_endpoint', __( 'Enter an http or https URL.', 'findable' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'findable_endpoint', __( 'Do not put credentials in the endpoint URL. Use the API key field.', 'findable' ) );
		}
		if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return new \WP_Error( 'findable_endpoint', __( 'The endpoint URL must not contain a query string or fragment.', 'findable' ) );
		}

		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $clean ) {
			return new \WP_Error( 'findable_endpoint', __( 'Enter an http or https URL.', 'findable' ) );
		}
		return $clean;
	}

	/**
	 * Store or clear the separate API key option.
	 *
	 * @param array $input Raw settings input.
	 */
	private static function handle_api_key( $input ) {
		if ( defined( 'FINDABLE_API_KEY' ) ) {
			return;
		}
		if ( ! empty( $input['findable_remove_key'] ) && rest_sanitize_boolean( $input['findable_remove_key'] ) ) {
			delete_option( 'findable_api_key' );
			return;
		}
		if ( isset( $input['findable_api_key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $input['findable_api_key'] ) );
			if ( '' !== $key ) {
				update_option( 'findable_api_key', $key, false );
			}
		}
	}

	/**
	 * Register the option with the Settings API.
	 */
	public static function register() {
		register_setting(
			'findable_settings',
			'findable_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Whether the endpoint needs the private-endpoint constant.
	 *
	 * @return bool True when the endpoint port is not reachable by the safe HTTP API.
	 */
	public static function endpoint_port_unreachable() {
		if ( defined( 'FINDABLE_ALLOW_PRIVATE_ENDPOINT' ) && FINDABLE_ALLOW_PRIVATE_ENDPOINT ) {
			return false;
		}
		$parts = wp_parse_url( self::endpoint() );
		$port  = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === ( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) ? 443 : 80 );
		return ! in_array( $port, array( 80, 443, 8080 ), true );
	}
}
