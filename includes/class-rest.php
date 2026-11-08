<?php
/**
 * REST API routes findable/v1.
 *
 * @package findable-wp
 */

namespace Findable;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rest
 */
class Rest {

	/**
	 * Namespace.
	 */
	const NS = 'findable/v1';

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_scan' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
				'args'                => array(
					'id'        => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
					'keyphrase' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && mb_strlen( $value ) <= Api_Client::MAX_KEYPHRASE;
						},
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/result',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_result' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/bulk',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_bulk_get' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_bulk_post' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'force'  => array(
							'type'              => 'boolean',
							'required'          => false,
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'action' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'start',
							'enum'              => array( 'start', 'pause', 'resume' ),
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'handle_bulk_delete' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_connection' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Permission: current user can edit the post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public static function can_edit_post( $request ) {
		$id = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to scan this post.', 'findable' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Permission: manage_options.
	 *
	 * @return bool|\WP_Error
	 */
	public static function can_manage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to manage Findable.', 'findable' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * POST /posts/{id}/scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_scan( $request ) {
		$post_id = absint( $request['id'] );

		$lock = 'findable_scan_lock_' . $post_id;
		if ( get_transient( $lock ) ) {
			return new \WP_Error(
				'findable_too_soon',
				__( 'This post was just scanned. Wait a few seconds and try again.', 'findable' ),
				array( 'status' => 429 )
			);
		}
		// Reserve the lock before the API call so a second click cannot race through.
		set_transient( $lock, 1, 10 );

		$keyphrase = $request->has_param( 'keyphrase' ) ? (string) $request['keyphrase'] : null;
		$scanned   = Store::scan_post( $post_id, $keyphrase );

		if ( is_wp_error( $scanned ) ) {
			delete_transient( $lock );
			$code = $scanned->get_error_code();
			return new \WP_Error(
				$code,
				$scanned->get_error_message(),
				array( 'status' => 'findable_not_scannable' === $code ? 400 : 500 )
			);
		}

		return rest_ensure_response( self::result_payload( $post_id, $scanned['result'], $scanned['error'] ) );
	}

	/**
	 * GET /posts/{id}/result.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_result( $request ) {
		$post_id = absint( $request['id'] );
		return rest_ensure_response( self::result_payload( $post_id, Store::get( $post_id ), Store::get_error( $post_id ) ) );
	}

	/**
	 * Build the shared result payload.
	 *
	 * @param int   $post_id Post id.
	 * @param array $result  Stored result or null.
	 * @param array $error   Stored error or null.
	 * @return array
	 */
	public static function result_payload( $post_id, $result, $error = null ) {
		$post   = get_post( $post_id );
		$advice = array();
		$labels = array();
		$fresh  = false;

		if ( null !== $result ) {
			$fresh = Store::is_fresh( $post_id, $result );
			foreach ( (array) $result['checks'] as $check ) {
				$labels[ $check['id'] ] = Fix_Advice::label( $check, get_user_locale() );
			}
			foreach ( Result::failing( $result ) as $check ) {
				$advice[ $check['id'] ] = Fix_Advice::for_check( $check['id'], get_user_locale() );
			}
		}

		return array(
			'result'      => $result,
			'fresh'       => $fresh,
			'age_seconds' => null === $result ? 0 : Store::age( $result ),
			'error'       => $error,
			'scannable'   => Store::is_scannable( $post ),
			'reason'      => Store::scan_reason( $post ),
			'report_url'  => null !== $result ? Store::report_url( $result['url'] ) : Store::report_url( (string) get_permalink( $post ) ),
			'advice'      => $advice,
			'labels'      => $labels,
			'keyphrase'   => (string) get_post_meta( $post_id, '_findable_keyphrase', true ),
			'legacy'      => null !== $result && ! empty( $result['legacy'] ),
		);
	}

	/**
	 * GET /bulk.
	 *
	 * @return \WP_REST_Response
	 */
	public static function handle_bulk_get() {
		$state                = Bulk_Scan::state();
		$state['status_text'] = Admin::bulk_status_text( $state );
		$state['eta_text']    = Admin::bulk_eta_text( $state );
		return rest_ensure_response( $state );
	}

	/**
	 * POST /bulk — start, pause or resume.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_bulk_post( $request ) {
		$action = isset( $request['action'] ) ? sanitize_key( (string) $request['action'] ) : 'start';
		$force  = ! empty( $request['force'] ) && rest_sanitize_boolean( $request['force'] );

		if ( 'pause' === $action ) {
			return rest_ensure_response( Bulk_Scan::pause() );
		}
		if ( 'resume' === $action ) {
			return rest_ensure_response( Bulk_Scan::resume() );
		}
		if ( ! empty( $request['force'] ) || 'start' === $action ) {
			$started = Bulk_Scan::start( $force );
			if ( is_wp_error( $started ) ) {
				return $started;
			}
			return rest_ensure_response( $started );
		}
		return new \WP_Error( 'rest_invalid_param', __( 'Unknown bulk action.', 'findable' ), array( 'status' => 400 ) );
	}

	/**
	 * DELETE /bulk — cancel.
	 *
	 * @return \WP_REST_Response
	 */
	public static function handle_bulk_delete() {
		return rest_ensure_response( Bulk_Scan::cancel() );
	}

	/**
	 * POST /connection.
	 *
	 * @return \WP_REST_Response
	 */
	public static function handle_connection() {
		$health = Api_Client::instance()->health();
		if ( is_wp_error( $health ) ) {
			return rest_ensure_response(
				array(
					'connected' => false,
					'message'   => sprintf(
						/* translators: %s: error message. */
						__( 'Could not reach the endpoint: %s', 'findable' ),
						$health->get_error_message()
					),
				)
			);
		}
		return rest_ensure_response(
			array(
				'connected' => true,
				/* translators: 1: service name, 2: version number. */
				'message'   => sprintf( __( 'Connected to %1$s %2$s.', 'findable' ), $health['name'], $health['version'] ),
				'name'      => $health['name'],
				'version'   => $health['version'],
			)
		);
	}
}
