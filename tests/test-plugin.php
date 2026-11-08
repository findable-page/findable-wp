<?php
/**
 * Plugin bootstrap, status-transition gate and privacy policy.
 *
 * @package findable-wp
 */

use Findable\Bulk_Scan;
use Findable\Plugin;
use Findable\Store;

class Test_Plugin extends Findable_TestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'findable_settings' );
		_cron_wp_clear_scan_post_events();
	}

	public function tearDown(): void {
		_cron_wp_clear_scan_post_events();
		$this->set_booted( true );
		parent::tearDown();
	}

	private function set_booted( $value ) {
		$property = new ReflectionProperty( Plugin::class, 'booted' );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}

	public function test_instance_is_a_singleton() {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_boot_registers_core_hooks() {
		$this->set_booted( false );
		Plugin::instance()->boot();

		$this->assertNotFalse( has_action( 'init', array( Store::class, 'register_meta' ) ) );
		$this->assertNotFalse( has_action( 'rest_api_init', array( Findable\Rest::class, 'register_routes' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( Findable\Migrate::class, 'maybe_migrate' ) ) );
		$this->assertNotFalse( has_action( Bulk_Scan::TICK, array( Bulk_Scan::class, 'tick' ) ) );
		$this->assertNotFalse( has_action( Bulk_Scan::SCAN_POST, array( Bulk_Scan::class, 'scan_post_event' ) ) );
		$this->assertNotFalse( has_action( 'transition_post_status', array( Plugin::instance(), 'on_transition_post_status' ) ) );
	}

	public function test_boot_registers_admin_hooks_only_in_admin() {
		$this->set_booted( false );
		set_current_screen( 'dashboard' );
		remove_all_actions( 'admin_menu' );

		Plugin::instance()->boot();

		$this->assertNotFalse( has_action( 'admin_menu', array( Findable\Admin::class, 'admin_menu' ) ) );
		$this->assertNotFalse( has_action( 'init', array( Findable\Admin::class, 'add_list_columns' ) ) );
	}

	public function test_boot_is_idempotent() {
		$this->set_booted( false );
		Plugin::instance()->boot();
		$count = count( $GLOBALS['wp_filter']['rest_api_init']->callbacks[10] );
		Plugin::instance()->boot();
		$this->assertSame( $count, count( $GLOBALS['wp_filter']['rest_api_init']->callbacks[10] ) );
	}

	public function test_publish_transition_schedules_scan_when_enabled() {
		update_option( 'findable_settings', array( 'scan_on_publish' => true ), false );
		$post_id = $this->create_scannable_post();
		_cron_wp_clear_scan_post_events();

		Plugin::instance()->on_transition_post_status( 'publish', 'draft', get_post( $post_id ) );

		$this->assertNotFalse( wp_next_scheduled( Bulk_Scan::SCAN_POST, array( $post_id ) ) );
	}

	public function test_transition_ignores_non_publish_non_posts_and_revisions() {
		update_option( 'findable_settings', array( 'scan_on_publish' => true ), false );
		$post_id = $this->create_scannable_post();
		_cron_wp_clear_scan_post_events();
		$plugin = Plugin::instance();

		$plugin->on_transition_post_status( 'draft', 'publish', get_post( $post_id ) );
		$plugin->on_transition_post_status( 'publish', 'draft', null );
		$revision_id = wp_save_post_revision( $post_id );
		if ( $revision_id ) {
			$plugin->on_transition_post_status( 'publish', 'draft', get_post( $revision_id ) );
		}
		$autosave = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post_id,
				'post_name'   => $post_id . '-autosave-v1',
				'post_status' => 'inherit',
			)
		);
		$plugin->on_transition_post_status( 'publish', 'draft', get_post( $autosave ) );

		$this->assertFalse( wp_next_scheduled( Bulk_Scan::SCAN_POST, array( $post_id ) ) );
		$this->assertFalse( wp_next_scheduled( Bulk_Scan::SCAN_POST, array( $autosave ) ) );
	}

	public function test_privacy_policy_content_is_registered() {
		global $wp_actions;
		$wp_actions['admin_init'] = 1; // wp_add_privacy_policy_content() only accepts calls during admin_init.
		set_current_screen( 'dashboard' );

		Plugin::instance()->privacy_policy();

		$policy = WP_Privacy_Policy_Content::get_suggested_policy_text();
		$texts = implode( ' ', wp_list_pluck( $policy, 'policy_text' ) );
		$this->assertContains( 'Findable', wp_list_pluck( $policy, 'plugin_name' ) );
		$this->assertStringContainsString( 'https://findable.page/privacy', $texts );
	}
}
