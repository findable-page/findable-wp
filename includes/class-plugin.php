<?php
/**
 * Plugin bootstrap: hooks and lifecycle.
 *
 * @package findable-wp
 */

namespace Findable;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * Whether hooks are registered.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Singleton accessor returned by findable().
	 *
	 * @return Plugin
	 */
	public static function instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Register every hook once.
	 */
	public function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', array( Store::class, 'register_meta' ), 99 );
		add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );

		if ( is_admin() ) {
			Admin::init();
			add_action( 'init', array( Admin::class, 'add_list_columns' ), 99 );
		}

		add_action( 'admin_init', array( Migrate::class, 'maybe_migrate' ) );

		// Cron.
		add_action( Bulk_Scan::TICK, array( Bulk_Scan::class, 'tick' ) );
		add_action( Bulk_Scan::SCAN_POST, array( Bulk_Scan::class, 'scan_post_event' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );

		// The dashboard aggregate is cached; any change to the set of scannable posts invalidates it.
		add_action( 'transition_post_status', array( $this, 'flush_dashboard_cache' ) );
		add_action( 'deleted_post', array( $this, 'flush_dashboard_cache' ) );

		// Privacy policy suggestion.
		add_action( 'admin_init', array( $this, 'privacy_policy' ) );

		// i18n fix advice cache cleanup on upgrade is unnecessary (transients expire).
		register_deactivation_hook( FINDABLE_FILE, array( Bulk_Scan::class, 'deactivate' ) );
	}

	/**
	 * Scan-on-publish gate on status transitions (publish -> publish covers updates).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		Bulk_Scan::schedule_scan_post( $post->ID );
	}

	/**
	 * Drop the cached dashboard aggregate.
	 */
	public function flush_dashboard_cache() {
		delete_transient( 'findable_dashboard' );
	}

	/**
	 * Suggested privacy policy content.
	 */
	public function privacy_policy() {
		wp_add_privacy_policy_content(
			__( 'Findable', 'findable' ),
			'<p>' .
			esc_html__( 'When you scan a post, the Findable plugin sends the post’s public URL and an optional keyphrase to the configured Findable endpoint (by default https://findable.page). The endpoint fetches the public page to analyze it. Your site URL is included in the request User-Agent. If you set an API key it is sent as a request header. See the endpoint’s privacy policy: https://findable.page/privacy', 'findable' ) .
			'</p>'
		);
	}
}
