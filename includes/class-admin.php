<?php
/**
 * Admin UI: menu, dashboard, settings, meta box, list column, notices.
 *
 * @package findable-wp
 */

namespace Findable;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Hook everything.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_init' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings_ui' ) );
		add_action( 'admin_notices', array( __CLASS__, 'edit_notices' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'admin_post_findable_scan', array( __CLASS__, 'handle_scan_post' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_by_failing' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'orderby_score' ) );
	}

	/**
	 * Menus: Findable > Dashboard (edit_others_posts) and Settings (manage_options).
	 */
	public static function admin_menu() {
		add_menu_page(
			__( 'Findable', 'findable' ),
			__( 'Findable', 'findable' ),
			'edit_others_posts',
			'findable-dashboard',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-search'
		);
		add_submenu_page(
			'findable-dashboard',
			__( 'Dashboard', 'findable' ),
			__( 'Dashboard', 'findable' ),
			'edit_others_posts',
			'findable-dashboard',
			array( __CLASS__, 'render_dashboard' )
		);
		add_submenu_page(
			'findable-dashboard',
			__( 'Findable Settings', 'findable' ),
			__( 'Settings', 'findable' ),
			'manage_options',
			'findable-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * Settings registration and notice dismissal.
	 */
	public static function admin_init() {
		Settings::register();

		// Dismiss the Fix All removal notice (dashboard-only link).
		if ( isset( $_GET['findable_dismiss_fix_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below before acting.
			if ( isset( $_GET['_wpnonce'] )
				&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'findable_dismiss_fix_notice' )
				&& current_user_can( 'manage_options' ) ) {
				Migrate::dismiss_fix_notice();
			}
			wp_safe_redirect( remove_query_arg( array( 'findable_dismiss_fix_notice', '_wpnonce' ) ) );
			exit;
		}
	}

	/**
	 * Enqueue per-screen assets.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( false !== strpos( (string) $hook_suffix, 'findable-dashboard' ) || false !== strpos( (string) $hook_suffix, 'findable-settings' ) ) {
			wp_enqueue_style( 'findable-admin', plugins_url( 'assets/admin.css', FINDABLE_FILE ), array(), FINDABLE_VERSION );
			wp_style_add_data( 'findable-admin', 'rtl', 'replace' );
			wp_enqueue_script( 'findable-admin', plugins_url( 'assets/admin.js', FINDABLE_FILE ), array(), FINDABLE_VERSION, true );
			wp_localize_script(
				'findable-admin',
				'findableAdmin',
				array(
					'restUrl'   => esc_url_raw( rest_url( Rest::NS ) ),
					'restNonce' => wp_create_nonce( 'wp_rest' ),
					'i18n'      => array(
						'test'       => __( 'Test connection', 'findable' ),
						'testing'    => __( 'Testing…', 'findable' ),
						'start'      => __( 'Start bulk scan', 'findable' ),
						'rescan'     => __( 'Rescan all posts', 'findable' ),
						'pause'      => __( 'Pause', 'findable' ),
						'resume'     => __( 'Resume', 'findable' ),
						'cancel'     => __( 'Cancel', 'findable' ),
						'confirm'    => __( 'Cancel the bulk scan?', 'findable' ),
						'error'      => __( 'Request failed.', 'findable' ),
						'paused'     => __( 'paused', 'findable' ),
						'scanned'    => __( 'scanned', 'findable' ),
						'failed'     => __( 'failed', 'findable' ),
						'cancelled'  => __( 'cancelled', 'findable' ),
						'idle'       => __( 'idle', 'findable' ),
					),
				)
			);
			return;
		}

		// Block editor sidebar.
		if ( $screen && in_array( $screen->base, array( 'post', 'post-new' ), true ) && self::uses_block_editor( $screen->post_type ) && in_array( $screen->post_type, Settings::get( 'post_types' ), true ) ) {
			$asset = FINDABLE_DIR . 'build/index.asset.php';
			$deps  = file_exists( $asset ) ? require $asset : array( 'dependencies' => array(), 'version' => FINDABLE_VERSION );
			wp_enqueue_script(
				'findable-editor',
				plugins_url( 'build/index.js', FINDABLE_FILE ),
				is_array( $deps ) && isset( $deps['dependencies'] ) ? $deps['dependencies'] : array(),
				is_array( $deps ) && isset( $deps['version'] ) ? $deps['version'] : FINDABLE_VERSION,
				true
			);
			wp_set_script_translations( 'findable-editor', 'findable' );
			wp_enqueue_style( 'findable-editor', plugins_url( 'assets/admin.css', FINDABLE_FILE ), array(), FINDABLE_VERSION );
			wp_style_add_data( 'findable-editor', 'rtl', 'replace' );
		}

		// Classic meta box screen CSS + scan JS.
		if ( $screen && in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			wp_enqueue_style( 'findable-admin', plugins_url( 'assets/admin.css', FINDABLE_FILE ), array(), FINDABLE_VERSION );
			wp_style_add_data( 'findable-admin', 'rtl', 'replace' );
			wp_enqueue_script( 'findable-admin', plugins_url( 'assets/admin.js', FINDABLE_FILE ), array(), FINDABLE_VERSION, true );
			wp_localize_script(
				'findable-admin',
				'findableAdmin',
				array(
					'scanUrl'  => esc_url_raw( admin_url( 'admin-post.php?action=findable_scan' ) ),
					'scanning' => __( 'Scanning…', 'findable' ),
			)
			);
		}
	}

	/**
	 * Whether a post type uses the block editor.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private static function uses_block_editor( $post_type ) {
		return function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post_type );
	}

	/**
	 * Meta boxes for enabled post types without the block editor.
	 */
	public static function add_meta_boxes() {
		$types = Settings::get( 'post_types' );
		foreach ( $types as $type ) {
			if ( self::uses_block_editor( $type ) ) {
				continue;
			}
			add_meta_box( 'findable', __( 'Findable', 'findable' ), array( __CLASS__, 'render_meta_box' ), $type, 'side', 'default' );
		}
	}

	/**
	 * Classic meta box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_meta_box( $post ) {
		$keyphrase = (string) get_post_meta( $post->ID, '_findable_keyphrase', true );
		$result    = Store::get( $post->ID );
		$fresh     = null !== $result && Store::is_fresh( $post->ID, $result );
		$error     = Store::get_error( $post->ID );
		$scannable = Store::is_scannable( $post );
		$locale    = get_user_locale();

		wp_nonce_field( 'findable_scan_' . $post->ID, 'findable_scan_nonce' );
		echo '<p>';
		if ( null !== $result ) {
			echo wp_kses_post( self::score_html( (int) $result['overall'] ) ) . ' ';
			foreach ( array( 'seo', 'aeo', 'geo' ) as $family ) {
				if ( isset( $result['families'][ $family ]['score'] ) ) {
					printf(
						'<span class="findable-family">%1$s %2$s</span> ',
						esc_html( strtoupper( $family ) ),
						esc_html( number_format_i18n( (int) $result['families'][ $family ]['score'] ) )
					);
				}
			}
			if ( ! $fresh ) {
				echo '<span class="findable-stale">' . esc_html__( '(stale)', 'findable' ) . '</span> ';
			}
			/* translators: %s: human-readable time difference. */
			echo '<span class="findable-age">' . esc_html( sprintf( __( 'Scanned %s ago.', 'findable' ), human_time_diff( (int) $result['scanned_at'], time() ) ) ) . '</span>';
			echo '</p><p><a href="' . esc_url( Store::report_url( $result['url'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Full report on findable.page', 'findable' ) . '</a></p>';

			if ( ! empty( $result['legacy'] ) ) {
				echo '<p class="description">' . esc_html__( 'Imported from findable 0.2. Scan again to see the full breakdown.', 'findable' ) . '</p>';
			} elseif ( ! empty( $result['checks'] ) ) {
				echo self::render_checks( $result, $locale ); // phpcs:ignore WordPress.Security.EscapeOutput -- every element escaped inside.
			}
		} else {
			echo '<p>' . esc_html__( 'Not scanned yet.', 'findable' ) . '</p>';
		}
		if ( ! $scannable ) {
			echo '<p class="findable-warning">' . esc_html( Store::reason_message( Store::scan_reason( $post ) ) ) . '</p>';
		}
		if ( null !== $error ) {
			printf( '<p class="findable-error">%s</p>', esc_html( $error['message'] ) );
		}

		echo '<p><label for="findable-keyphrase">' . esc_html__( 'Keyphrase (optional)', 'findable' ) . '</label>';
		printf(
			'<input type="text" class="widefat" id="findable-keyphrase" name="findable_keyphrase" value="%s" maxlength="100"></p>',
			esc_attr( $keyphrase )
		);
		submit_button( __( 'Scan now', 'findable' ), 'secondary', 'findable_scan', false, array( 'type' => 'button' ) );
		echo '<span class="spinner findable-scan-spinner" style="float:none"></span>';
		echo '<p class="description">' . esc_html__( 'Findable scans the live URL, so publish or update first.', 'findable' ) . '</p>';
	}

	/**
	 * Render failing checks first, passing collapsed in details.
	 *
	 * @param array  $result Stored result.
	 * @param string $locale Locale.
	 * @return string HTML (escaped values only).
	 */
	private static function render_checks( $result, $locale ) {
		$failing = Result::failing( $result );
		$passing = array_values(
			array_filter(
				(array) $result['checks'],
				static function ( $c ) {
					return isset( $c['s'] ) && 'pass' === $c['s'];
				}
			)
		);

		$out = '';
		if ( $failing ) {
			/* translators: %s: number of failing checks. */
			$out .= '<p><strong>' . esc_html( sprintf( __( 'Failing checks (%s)', 'findable' ), number_format_i18n( count( $failing ) ) ) ) . '</strong></p><ul class="findable-checks findable-failing">';
			foreach ( $failing as $check ) {
				$out .= '<li><span class="findable-check-label">' . esc_html( Fix_Advice::label( $check, $locale ) ) . '</span><br><span class="findable-advice">' . esc_html( Fix_Advice::for_check( $check['id'], $locale ) ) . '</span></li>';
			}
			$out .= '</ul>';
		} else {
			$out .= '<p>' . esc_html__( 'No failing checks. Nice!', 'findable' ) . '</p>';
		}

		if ( $passing ) {
			$out .= '<details><summary>';
			/* translators: %s: number of passing checks. */
			$out .= esc_html( sprintf( __( 'Passing checks (%s)', 'findable' ), number_format_i18n( count( $passing ) ) ) ) . '</summary><ul class="findable-checks">';
			foreach ( $passing as $check ) {
				$out .= '<li>' . esc_html( Fix_Advice::label( $check, $locale ) ) . '</li>';
			}
			$out .= '</ul></details>';
		}
		return $out;
	}

	/**
	 * Handle admin-post.php?action=findable_scan from the classic meta box.
	 */
	public static function handle_scan_post() {
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! $post_id ) {
			wp_die( esc_html__( 'Invalid request.', 'findable' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'findable_scan_' . $post_id, 'findable_scan_nonce' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to scan this post.', 'findable' ), '', array( 'response' => 403 ) );
		}

		$redirect = (string) wp_get_referer();
		if ( '' === $redirect ) {
			$redirect = get_edit_post_link( $post_id, 'raw' );
		}
		if ( '' === $redirect || false === $redirect ) {
			$redirect = admin_url( 'edit.php' );
		}

		// Persist keyphrase from the form.
		$keyphrase = isset( $_POST['findable_keyphrase'] ) ? sanitize_text_field( wp_unslash( $_POST['findable_keyphrase'] ) ) : null;

		$scanned = Store::scan_post( $post_id, $keyphrase );

		if ( is_wp_error( $scanned ) ) {
			$redirect = add_query_arg( 'findable_scan_error', rawurlencode( $scanned->get_error_message() ), $redirect );
		} elseif ( null !== $scanned['error'] ) {
			$redirect = add_query_arg( 'findable_scan_error', rawurlencode( $scanned['error']['message'] ), $redirect );
		} else {
			$redirect = add_query_arg( 'findable_scan_done', '1', $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Edit-screen notice after a classic meta box scan redirect.
	 */
	public static function edit_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		if ( isset( $_GET['findable_scan_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by our own redirect.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Findable scan complete.', 'findable' ) . '</p></div>';
		}
		if ( isset( $_GET['findable_scan_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- message escaped below.
			$message = sanitize_text_field( wp_unslash( $_GET['findable_scan_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Score badge HTML.
	 *
	 * @param int $score Overall score.
	 * @return string
	 */
	public static function score_html( $score ) {
		$band = Result::band( $score );
		return sprintf(
			'<span class="findable-score findable-band-%1$s">%2$s %3$s</span>',
			esc_attr( $band ),
			esc_html( number_format_i18n( $score ) ),
			esc_html( Result::band_label( $band ) )
		);
	}

	/**
	 * List table columns for each enabled post type.
	 */
	public static function add_list_columns() {
		foreach ( Settings::get( 'post_types' ) as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'posts_columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'posts_column' ), 10, 2 );
			add_filter( "manage_edit-{$type}_sortable_columns", array( __CLASS__, 'sortable_column' ) );
		}
	}

	/**
	 * Add the Findable column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function posts_columns( $columns ) {
		$columns['findable'] = __( 'Findable', 'findable' );
		return $columns;
	}

	/**
	 * Make the column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_column( $columns ) {
		$columns['findable'] = array( 'findable_score', false );
		return $columns;
	}

	/**
	 * Render the Findable column cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 */
	public static function posts_column( $column, $post_id ) {
		if ( 'findable' !== $column ) {
			return;
		}
		$result = Store::get( $post_id );
		if ( null === $result ) {
			echo '<span aria-label="' . esc_attr__( 'Not scanned', 'findable' ) . '">&mdash;</span>';
			return;
		}
		echo wp_kses_post( self::score_html( (int) $result['overall'] ) );
		if ( ! Store::is_fresh( $post_id, $result ) ) {
			echo ' <span class="findable-stale">' . esc_html__( '(stale)', 'findable' ) . '</span>';
		}
	}

	/**
	 * Sort by _findable_overall with unscanned posts last in both directions.
	 *
	 * WP_Query cannot express `ORDER BY value IS NULL, value`, so rewrite the
	 * ORDER BY on the fly; mt1 is the meta_query table alias.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function orderby_score( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'findable_score' !== $query->get( 'orderby' ) ) {
			return;
		}
		$order = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';

		// Include posts without a score via a NOT EXISTS branch.
		$query->set(
			'meta_query',
			array(
				'findable_score_cl' => array(
					'relation' => 'OR',
					array(
						'key'     => '_findable_overall',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_findable_overall',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$query->set( 'orderby', 'findable_score_cl' );
		$query->set( 'order', $order );

		$handler = static function ( $orderby_sql ) use ( $order, &$handler ) {
			remove_filter( 'posts_orderby', $handler );
			return 'ISNULL( mt1.meta_value ) ' . $order . ', CAST( mt1.meta_value AS SIGNED ) ' . $order;
		};
		add_filter( 'posts_orderby', $handler );
	}

	/**
	 * Filter posts list by failing check id (findable_failing={id}).
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function filter_by_failing( $query ) {
		$pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== $pagenow ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$raw = isset( $_GET['findable_failing'] ) ? sanitize_text_field( wp_unslash( $_GET['findable_failing'] ) ) : '';
		if ( '' === $raw ) {
			return;
		}
		if ( ! preg_match( Result::ID_PATTERN, $raw ) ) {
			return; // Invalid value: ignore the filter.
		}
		$query->set(
			'meta_query',
			array(
				array(
					'key'     => '_findable_failing_ids',
					'value'   => ',' . $raw . ',',
					'compare' => 'LIKE',
				),
			)
		);
	}

	/**
	 * Cron health hint markup (inline, Findable screens only).
	 *
	 * @return string
	 */
	public static function cron_hint() {
		if ( ! Bulk_Scan::cron_unhealthy() ) {
			return '';
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return '<div class="notice notice-warning inline findable-cron-hint"><p>' .
				sprintf(
					/* translators: %s: link to WordPress documentation about wp-cron. */
					esc_html__( 'WP-Cron is disabled. Bulk scans run only when a server cron calls wp-cron.php. %s', 'findable' ),
					'<a href="https://developer.wordpress.org/plugins/cron/hello-wp-cron/">' . esc_html__( 'Learn more', 'findable' ) . '</a>'
				) . '</p></div>';
		}
		return '<div class="notice notice-warning inline findable-cron-hint"><p>' .
			esc_html__( 'WP-Cron looks stalled: a Findable bulk tick is overdue by more than 10 minutes.', 'findable' ) . '</p></div>';
	}

	/**
	 * Render the dashboard page.
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'findable' ), '', array( 'response' => 403 ) );
		}

		$agg     = Store::aggregate();
		$can_manage = current_user_can( 'manage_options' );
		$locale  = get_user_locale();
		$state   = Bulk_Scan::state();

		echo '<div class="wrap"><h1>' . esc_html__( 'Findable', 'findable' ) . '</h1>';
		echo wp_kses_post( self::cron_hint() );

		if ( $can_manage && Migrate::has_legacy_fixes() && ! Migrate::fix_notice_dismissed() ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%1$s <a class="findable-dismiss" href="%2$s">%3$s</a></p></div>',
				esc_html__( 'The old plugin\u2019s Fix All feature was removed in 1.0. Head tags it injected are no longer printed.', 'findable' ),
				esc_url( wp_nonce_url( add_query_arg( 'findable_dismiss_fix_notice', 1 ), 'findable_dismiss_fix_notice' ) ),
				esc_html__( 'Dismiss', 'findable' )
			);
		}

		if ( 0 === $agg['scanned'] ) {
			echo '<p>' . esc_html__( 'No posts scanned yet.', 'findable' ) . '</p>';
			if ( $can_manage ) {
				echo '<button type="button" class="button button-primary" id="findable-bulk-start" data-force="false">' . esc_html__( 'Scan all posts', 'findable' ) . '</button>';
			}
			echo '</div>';
			return;
		}

		// Summary.
		echo '<h2>' . esc_html__( 'Site summary', 'findable' ) . '</h2>';
		printf(
			/* translators: 1: scanned count, 2: scannable count, 3: stale count, 4: never scanned count. */
			'<p>' . esc_html__( '%1$s of %2$s scannable posts scanned (%3$s stale, %4$s never scanned).', 'findable' ) . '</p>',
			esc_html( number_format_i18n( $agg['scanned'] ) ),
			esc_html( number_format_i18n( $agg['scannable'] ) ),
			esc_html( number_format_i18n( $agg['stale'] ) ),
			esc_html( number_format_i18n( $agg['never_scanned'] ) )
		);
		printf(
			/* translators: 1: average overall, 2: SEO average, 3: AEO average, 4: GEO average. */
			'<p>' . esc_html__( 'Average scores — Overall %1$s, SEO %2$s, AEO %3$s, GEO %4$s.', 'findable' ) . '</p>',
			esc_html( number_format_i18n( $agg['avg']['overall'] ) ),
			esc_html( number_format_i18n( $agg['avg']['seo'] ) ),
			esc_html( number_format_i18n( $agg['avg']['aeo'] ) ),
			esc_html( number_format_i18n( $agg['avg']['geo'] ) )
		);

		// Bands with text equivalents.
		echo '<table class="widefat striped findable-bands"><caption class="screen-reader-text">' . esc_html__( 'Score distribution', 'findable' ) . '</caption><thead><tr>';
		foreach ( array( 'good' => __( 'Good (80-100)', 'findable' ), 'needs_work' => __( 'Needs work (50-79)', 'findable' ), 'poor' => __( 'Poor (0-49)', 'findable' ) ) as $key => $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody><tr>';
		foreach ( array( 'good', 'needs_work', 'poor' ) as $key ) {
			printf(
				'<td class="findable-band-%1$s"><span class="findable-band-bar" aria-hidden="true" style="width:%2$s%%"></span><span>%3$s</span></td>',
				esc_attr( $key ),
				esc_attr( $agg['scanned'] ? (int) round( 100 * $agg['bands'][ $key ] / $agg['scanned'] ) : 0 ),
				esc_html( _n( '%s post', '%s posts', $agg['bands'][ $key ], 'findable' ) )
			);
		}
		echo '</tr></tbody></table>';

		// Weakest posts.
		echo '<h2>' . esc_html__( 'Weakest posts', 'findable' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Post', 'findable' ), __( 'Post type', 'findable' ), __( 'Overall score', 'findable' ), __( 'Failing checks', 'findable' ), __( 'Scan age', 'findable' ) ) as $th ) {
			echo '<th scope="col">' . esc_html( $th ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $agg['weakest'] as $row ) {
			$post = get_post( $row['id'] );
			if ( ! $post ) {
				continue;
			}
			$edit = get_edit_post_link( $row['id'] );
			echo '<tr>';
			printf( '<td>%s</td>', $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( get_the_title( $post ) ) . '</a>' : esc_html( get_the_title( $post ) ) );
			printf( '<td>%s</td>', esc_html( $post->post_type ) );
			printf( '<td>%s</td>', wp_kses_post( self::score_html( $row['score'] ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $row['failing'] ) ) );
			/* translators: %s: human-readable time difference. */
			printf( '<td>%s</td>', esc_html( sprintf( __( '%s ago', 'findable' ), human_time_diff( $row['at'], time() ) ) ) );
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Most common failing checks.
		echo '<h2>' . esc_html__( 'Most common failing checks', 'findable' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Check', 'findable' ), __( 'Family', 'findable' ), __( 'Posts affected', 'findable' ), __( 'Fix advice', 'findable' ) ) as $th ) {
			echo '<th scope="col">' . esc_html( $th ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $agg['failing'] as $row ) {
			$filter = add_query_arg( 'findable_failing', $row['id'], admin_url( 'edit.php' ) );
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( Fix_Advice::label( array( 'id' => $row['id'], 'label' => $row['label'] ), $locale ) ) );
			printf( '<td>%s</td>', esc_html( strtoupper( $row['f'] ) ) );
			printf(
				'<td><a href="%1$s">%2$s</a> <span class="findable-pct">%3$s</span></td>',
				esc_url( $filter ),
				esc_html( number_format_i18n( $row['posts'] ) ),
				esc_html( sprintf( __( '%s%% of scanned posts', 'findable' ), number_format_i18n( $agg['scanned'] ? (int) round( 100 * $row['posts'] / $agg['scanned'] ) : 0 ) ) )
			);
			printf( '<td>%s</td>', esc_html( Fix_Advice::for_check( $row['id'], $locale ) ) );
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Bulk panel.
		if ( $can_manage ) {
			echo self::render_bulk_panel( $state ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		}
		echo '</div>';
	}

	/**
	 * Bulk scan panel markup; polled by assets/admin.js while running.
	 *
	 * @param array $state Bulk state.
	 * @return string
	 */
	private static function render_bulk_panel( $state ) {
		$running = in_array( $state['status'], array( 'running', 'paused' ), true );
		$out     = '<div id="findable-bulk-panel" class="findable-bulk card" data-status="' . esc_attr( $state['status'] ) . '" data-running="' . esc_attr( $running ? '1' : '0' ) . '">';
		$out    .= '<h2>' . esc_html__( 'Bulk scan', 'findable' ) . '</h2>';
		$out    .= '<p aria-live="polite" id="findable-bulk-status">' . esc_html( self::bulk_status_text( $state ) ) . '</p>';
		$out    .= '<progress id="findable-bulk-progress" max="' . esc_attr( (string) $state['total'] ) . '" value="' . esc_attr( (string) ( $state['done'] + $state['failed'] ) ) . '"></progress> ';
		$out    .= '<p>';
		$out    .= '<button type="button" class="button button-primary" id="findable-bulk-start" data-force="false"' . ( $running ? ' disabled' : '' ) . '>' . esc_html__( 'Start bulk scan', 'findable' ) . '</button> ';
		$out    .= '<button type="button" class="button" id="findable-bulk-rescan" data-force="true"' . ( $running ? ' disabled' : '' ) . '>' . esc_html__( 'Rescan all posts', 'findable' ) . '</button> ';
		$out    .= '<button type="button" class="button" id="findable-bulk-pause"' . ( 'running' === $state['status'] ? '' : ' disabled' ) . '>' . esc_html__( 'Pause', 'findable' ) . '</button> ';
		$out    .= '<button type="button" class="button" id="findable-bulk-resume"' . ( 'paused' === $state['status'] ? '' : ' disabled' ) . '>' . esc_html__( 'Resume', 'findable' ) . '</button> ';
		$out    .= '<button type="button" class="button" id="findable-bulk-cancel"' . ( $running ? '' : ' disabled' ) . '>' . esc_html__( 'Cancel', 'findable' ) . '</button>';
		$out    .= '</p>';
		if ( '' !== (string) $state['last_error'] ) {
			$out .= '<p class="findable-error">' . esc_html( (string) $state['last_error'] ) . '</p>';
		}
		$out .= '<p class="description">' . esc_html__( 'Bulk scans run slowly by design to respect the API rate limit of 10 scans per minute per IP.', 'findable' ) . '</p>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * Human status line for the bulk panel.
	 *
	 * @param array $state Bulk state.
	 * @return string
	 */
	public static function bulk_status_text( $state ) {
		$done = $state['done'] + $state['failed'];
		switch ( $state['status'] ) {
			case 'running':
				/* translators: 1: processed count, 2: total count. */
				return sprintf( __( 'Running: %1$s of %2$s processed.', 'findable' ), number_format_i18n( $done ), number_format_i18n( $state['total'] ) );
			case 'paused':
				/* translators: 1: processed count, 2: total count. */
				return sprintf( __( 'Paused at %1$s of %2$s.', 'findable' ), number_format_i18n( $done ), number_format_i18n( $state['total'] ) );
			case 'done':
				/* translators: 1: scanned count, 2: failed count. */
				return sprintf( __( 'Bulk scan finished: %1$s scanned, %2$s failed.', 'findable' ), number_format_i18n( $state['done'] ), number_format_i18n( $state['failed'] ) );
			case 'cancelled':
				return __( 'Bulk scan cancelled.', 'findable' );
			default:
				return __( 'Idle.', 'findable' );
		}
	}

	/**
	 * Render the settings page.
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'findable' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Findable Settings', 'findable' ); ?></h1>
			<?php echo wp_kses_post( self::cron_hint() ); ?>
			<?php settings_errors( 'findable_settings' ); ?>
			<form action="options.php" method="post">
				<?php settings_fields( 'findable_settings' ); ?>
				<?php do_settings_sections( 'findable-settings' ); ?>
				<?php submit_button(); ?>
			</form>
			<h2><?php esc_html_e( 'Connection', 'findable' ); ?></h2>
			<p>
				<button type="button" class="button" id="findable-test-connection"><?php esc_html_e( 'Test connection', 'findable' ); ?></button>
				<span id="findable-connection-result" aria-live="polite"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Register settings sections and fields.
	 */
	public static function register_settings_ui() {
		add_settings_section(
			'findable_main',
			__( 'API and scanning', 'findable' ),
			'__return_false',
			'findable-settings'
		);

		$fields = array(
			'endpoint'        => __( 'Findable endpoint', 'findable' ),
			'api_key'         => __( 'API key', 'findable' ),
			'post_types'      => __( 'Post types', 'findable' ),
			'cache_ttl_days'  => __( 'Cache lifetime (days)', 'findable' ),
			'bulk_per_minute' => __( 'Bulk scan pace (scans per minute)', 'findable' ),
			'scan_on_publish' => __( 'Scan on publish', 'findable' ),
			'delete_data_on_uninstall' => __( 'Delete data on uninstall', 'findable' ),
		);
		foreach ( $fields as $key => $title ) {
			add_settings_field(
				'findable_' . $key,
				$title,
				array( __CLASS__, 'render_field_' . $key ),
				'findable-settings',
				'findable_main'
			);
		}
	}

	/**
	 * Field: endpoint.
	 */
	public static function render_field_endpoint() {
		$endpoint = Settings::endpoint();
		printf(
			'<input type="url" class="regular-text code" name="findable_settings[endpoint]" id="findable-endpoint" value="%s" placeholder="https://findable.page">',
			esc_attr( $endpoint )
		);
		echo '<p class="description">' . esc_html__( 'The hosted API is https://findable.page. You can point this at a self-hosted findable server (findable -serve).', 'findable' ) . '</p>';
		if ( Settings::endpoint_port_unreachable() ) {
			echo '<p class="description findable-warning">' . esc_html__( 'Only ports 80, 443 and 8080 are reachable. For other ports define FINDABLE_ALLOW_PRIVATE_ENDPOINT (true) in wp-config.php.', 'findable' ) . '</p>';
		}
	}

	/**
	 * Field: API key.
	 */
	public static function render_field_api_key() {
		if ( defined( 'FINDABLE_API_KEY' ) ) {
			echo '<input type="password" class="regular-text" disabled> <span class="description">' . esc_html__( 'Set in wp-config.php', 'findable' ) . '</span>';
			return;
		}
		$saved = (string) get_option( 'findable_api_key', '' );
		if ( '' !== $saved ) {
			echo '<p class="description">' . esc_html__( 'A key is saved.', 'findable' ) . '</p>';
		}
		echo '<input type="password" class="regular-text" name="findable_settings[findable_api_key]" id="findable-api-key" value="" autocomplete="new-password">';
		echo '<label><input type="checkbox" name="findable_settings[findable_remove_key]" value="1">' . esc_html__( 'Remove saved key', 'findable' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Optional. The hosted findable.page API needs no key today; it is sent as a Bearer header for future compatibility. Leave empty to keep the saved key.', 'findable' ) . '</p>';
	}

	/**
	 * Field: post types.
	 */
	public static function render_field_post_types() {
		$enabled = Settings::get( 'post_types' );
		$public  = get_post_types( array( 'public' => true ), 'objects' );
		unset( $public['attachment'] );
		echo '<fieldset>';
		foreach ( $public as $type ) {
			printf(
				'<label><input type="checkbox" name="findable_settings[post_types][]" value="%1$s"%2$s> %3$s</label><br>',
				esc_attr( $type->name ),
				checked( in_array( $type->name, $enabled, true ), true, false ),
				esc_html( $type->labels->singular_name )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Field: cache TTL.
	 */
	public static function render_field_cache_ttl_days() {
		printf(
			'<input type="number" min="1" max="90" step="1" name="findable_settings[cache_ttl_days]" value="%s">',
			esc_attr( (string) Settings::get( 'cache_ttl_days' ) )
		);
		echo '<p class="description">' . esc_html__( 'How long a stored scan result counts as fresh (1-90).', 'findable' ) . '</p>';
	}

	/**
	 * Field: bulk pace.
	 */
	public static function render_field_bulk_per_minute() {
		printf(
			'<input type="number" min="1" max="8" step="1" name="findable_settings[bulk_per_minute]" value="%s">',
			esc_attr( (string) Settings::get( 'bulk_per_minute' ) )
		);
		echo '<p class="description">' . esc_html__( 'API calls per minute during a bulk scan. The API allows 10 per minute per IP; stay under it.', 'findable' ) . '</p>';
	}

	/**
	 * Field: scan on publish.
	 */
	public static function render_field_scan_on_publish() {
		printf(
			'<label><input type="checkbox" name="findable_settings[scan_on_publish]" value="1"%s> %s</label>',
			checked( (bool) Settings::get( 'scan_on_publish' ), true, false ),
			esc_html__( 'Automatically rescan published posts about a minute after they are published or updated', 'findable' )
		);
	}

	/**
	 * Field: delete data on uninstall.
	 */
	public static function render_field_delete_data_on_uninstall() {
		printf(
			'<label><input type="checkbox" name="findable_settings[delete_data_on_uninstall]" value="1"%s> %s</label>',
			checked( (bool) Settings::get( 'delete_data_on_uninstall' ), true, false ),
			esc_html__( 'Delete all stored scan results and settings when the plugin is deleted', 'findable' )
		);
	}
}
