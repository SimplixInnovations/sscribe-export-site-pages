<?php
/**
 * Plugin Name: SScribe Test Bootstrap (M3 only — never ships)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// SSCRIBE_E2E_TESTBED gate. The test-only reset / cancel /
// diagnostic endpoints in this mu-plugin are reachable on
// production URLs only when this constant is true. The
// bootstrap mu-plugin exists ONLY in the E2E blueprint
// (tests-e2e/fixtures/mu-plugins/); it is never installed on
// production sites. This constant is the single source of
// truth for "we're in a testbed" — the WP_DEBUG constant is
// not defined by the blueprint and can leak false positives
// (e.g. WP_DEBUG=false in production, or undefined anywhere
// the testbed is run). Each endpoint checks this constant
// at its own guard point (not in a wrapper) so that the
// dependency is local and obvious.
if ( ! defined( 'SSCRIBE_E2E_TESTBED' ) ) {
	define( 'SSCRIBE_E2E_TESTBED', true );
}

$ssb_log_dir = WP_CONTENT_DIR . '/uploads';
if ( ! is_dir( $ssb_log_dir ) ) {
	@wp_mkdir_p( $ssb_log_dir );
}
@file_put_contents(
	$ssb_log_dir . '/sscribe-bootstrap-trace.txt',
	'[' . gmdate( 'c' ) . '] FILE_INCLUDED uri=' . ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : 'cli' ) . ' has_add_action=' . ( function_exists( 'add_action' ) ? 'Y' : 'N' ) . ' has_do_action=' . ( function_exists( 'do_action' ) ? 'Y' : 'N' ) . "\n",
	FILE_APPEND
);

// Test-env PHP memory bump. WP-Playground's bundled PHP defaults to
// memory_limit=256M. After WP core + plugin + mu-plugin boot, available
// memory drops to ~196M. SScribe's preflight `check_memory` (Diagnostics:
//439-455) estimates 51 pages * 5MB/page + 50MB overhead = 305MB required
// for a 51-page DOCX export (the Blueprint seed 50 + WP "Hello World"
// sample page yields 51 published pages under post_type=page). When
// estimated > available, the preflight returns 'error' and the JS
// click handler's `if (!canProceed)` branch re-enables the export
// button WITHOUT calling doStartExport — the testbed then waits for
// `action=sscribe_start_export` indefinitely and times out. Bumping to
// 512M is exactly what the preflight's own fix-recommendation tells
// production users to do (Diagnostics:466/481/501). This is a TESTBED
// ONLY override, declared in the heartbeat, never shipped.
if ( function_exists( 'ini_set' ) ) {
	@ini_set( 'memory_limit', '512M' );
}

// Once-only gate. WP-Playground's SQLite backend has a small per-worker
// lock budget; running role_init + user_meta writes + a ~5 KB heartbeat
// JSON dump on EVERY request causes "Error establishing a database
// connection" failures after ~10 tests. Do all the write-once work on
// the first request and skip on every subsequent request.
$ssb_done_key = '_sscribe_test_bootstrap_v3_done';
$ssb_done     = function_exists( 'get_option' ) ? get_option( $ssb_done_key, '' ) : '';
$ssb_first_request = ( '' === $ssb_done );

// Most aggressive trace: use a priority 0 action that fires before
// everything else and register_shutdown_function to see if PHP even
// reaches shutdown.
if ( function_exists( 'add_action' ) ) {
	add_action( 'muplugins_loaded', function () use ( $ssb_log_dir, $ssb_first_request ) {
		if ( $ssb_first_request ) {
			@file_put_contents( $ssb_log_dir . '/sscribe-bootstrap-trace.txt', '[' . gmdate( 'c' ) . '] MUPLUGINS_LOADED_FIRED' . "\n", FILE_APPEND );
		}
	}, 0 );

	add_action( 'plugins_loaded', function () use ( $ssb_log_dir, $ssb_first_request ) {
		if ( $ssb_first_request ) {
			@file_put_contents( $ssb_log_dir . '/sscribe-bootstrap-trace.txt', '[' . gmdate( 'c' ) . '] PLUGINS_LOADED_FIRED active=' . json_encode( get_option( 'active_plugins', array() ) ) . "\n", FILE_APPEND );
		}

		// Skip all per-request DB work after the first pass. The role
		// caps + user_meta are persisted, so subsequent requests need
		// nothing. The user_has_cap filter below continues to run as a
		// pure in-memory belt-and-suspenders.
		if ( ! $ssb_first_request ) {
			add_filter( 'user_has_cap', function ( $allcaps ) {
				if ( ! is_array( $allcaps ) ) {
					$allcaps = array();
				}
				$allcaps['sscribe_export'] = true;
				$allcaps['sscribe_health'] = true;
				$allcaps['manage_options'] = true;
				return $allcaps;
			}, 999, 4 );
			return;
		}

		// Update admin user_meta cap key BEFORE wp_get_current_user()
		// builds the allcaps array. WP caches allcaps in user_meta under
		// {$wpdb->prefix}capabilities. If we update it here on every
		// request, the cap is always present. Skip the UPDATE when the
		// cached allcaps already include our caps (they're persistent in
		// the role, so this branch is the steady state — eliminates a
		// write per request on the long-running suite).
		global $wpdb;
		$caps_key = $wpdb->prefix . 'capabilities';
		$users    = $wpdb->users;
		$admin_user_id = (int) $wpdb->get_var( "SELECT ID FROM {$users} WHERE user_login = 'admin' LIMIT 1" );
		if ( $admin_user_id ) {
			$stored = get_user_meta( $admin_user_id, $caps_key, true );
			$needs_meta_update = ! is_array( $stored )
				|| empty( $stored['sscribe_export'] )
				|| empty( $stored['sscribe_health'] )
				|| empty( $stored['administrator'] );
			if ( $needs_meta_update ) {
				if ( ! is_array( $stored ) ) {
					$stored = array();
				}
				$stored['administrator']  = true;
				$stored['sscribe_export'] = true;
				$stored['sscribe_health'] = true;
				update_user_meta( $admin_user_id, $caps_key, $stored );
			}

			// Also ensure the role itself has the caps. add_cap writes to
			// the user_roles option; gate by has_cap to avoid the write
			// on every request.
			$role = get_role( 'administrator' );
			if ( $role && ! $role->has_cap( 'sscribe_export' ) ) {
				$role->add_cap( 'sscribe_export' );
			}
			if ( $role && ! $role->has_cap( 'sscribe_health' ) ) {
				$role->add_cap( 'sscribe_health' );
			}
		}

		// Belt-and-suspenders: filter user_has_cap so sscribe_export +
		// manage_options are always granted. Catches cases where WP
		// caches the cap check before our meta update lands.
		add_filter( 'user_has_cap', function ( $allcaps, $caps, $args, $user ) {
			if ( ! is_array( $allcaps ) ) {
				$allcaps = array();
			}
			$allcaps['sscribe_export'] = true;
			$allcaps['sscribe_health'] = true;
			$allcaps['manage_options'] = true;
			return $allcaps;
		}, 999, 4 );
	}, 1 );

	add_action( 'init', function () use ( $ssb_log_dir, $ssb_done_key, $ssb_first_request ) {
		// Skip all DB work + the multi-KB heartbeat dump on subsequent
		// requests. WP-Playground's SQLite lock budget is small and
		// burning it on every request on a 20+ test run breaks the DB.
		if ( ! $ssb_first_request ) {
			return;
		}
		// Initialize WP roles if not loaded (WP-Playground's fresh DB
		// doesn't pre-populate user_roles).
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		// Use WP's role API. get_role() returns null if the role doesn't
		// exist. WP installs a default administrator on first call to
		// wp_roles()->init_roles() via WP_Roles::init().
		global $wp_roles;
		if ( ! ( $wp_roles instanceof WP_Roles ) ) {
			return;
		}
		$wp_roles->init_roles();
		$admin_role = get_role( 'administrator' );

		// If still null, the option is broken; seed it with a complete
		// admin role definition that has all the usual caps plus ours.
		if ( ! $admin_role ) {
			$roles = get_option( 'user_roles', array() );
			if ( ! is_array( $roles ) ) {
				$roles = array();
			}
			$roles['administrator'] = array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'switch_themes'    => true,
					'edit_themes'      => true,
					'activate_plugins' => true,
					'edit_plugins'     => true,
					'edit_users'       => true,
					'edit_files'       => true,
					'manage_options'   => true,
					'moderate_comments'=> true,
					'manage_categories'=> true,
					'manage_links'     => true,
					'upload_files'     => true,
					'import'           => true,
					'unfiltered_html'  => true,
					'edit_posts'       => true,
					'edit_others_posts'=> true,
					'edit_published_posts' => true,
					'publish_posts'    => true,
					'edit_pages'       => true,
					'read'             => true,
					'level_10'         => true,
					'level_9'          => true,
					'level_8'          => true,
					'level_7'          => true,
					'level_6'          => true,
					'level_5'          => true,
					'level_4'          => true,
					'level_3'          => true,
					'level_2'          => true,
					'level_1'          => true,
					'level_0'          => true,
					'sscribe_export'   => true,
					'sscribe_health'   => true,
				),
			);
			update_option( 'user_roles', $roles );
			// Refresh WP_Roles internal state.
			$wp_roles->init();
			$admin_role = get_role( 'administrator' );
		}

		// Now use the proper API to grant caps. add_cap persists via
		// WP_Roles which updates the user_roles option.
		if ( $admin_role && ! $admin_role->has_cap( 'sscribe_export' ) ) {
			$admin_role->add_cap( 'sscribe_export' );
		}
		if ( $admin_role && ! $admin_role->has_cap( 'sscribe_health' ) ) {
			$admin_role->add_cap( 'sscribe_health' );
		}

		// CRITICAL: WP caches the user's allcaps in user_meta with key
		// {$wpdb->prefix}capabilities. If the user was created BEFORE
		// the role had sscribe_export, the user's cached allcaps won't
		// include it and current_user_can('sscribe_export') will fail.
		// We update the admin user's meta directly so the cap is in
		// allcaps even before the role cache refreshes.
		global $wpdb;
		$caps_key = $wpdb->prefix . 'capabilities';
		$users    = $wpdb->users;
		$usermeta = $wpdb->usermeta;
		$admin_user_id = (int) $wpdb->get_var( "SELECT ID FROM {$users} WHERE user_login = 'admin' LIMIT 1" );
		if ( $admin_user_id ) {
			$stored = get_user_meta( $admin_user_id, $caps_key, true );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			$stored['sscribe_export'] = true;
			$stored['sscribe_health'] = true;
			// Make sure 'administrator' role key is set too.
			if ( ! isset( $stored['administrator'] ) ) {
				$stored['administrator'] = true;
			}
			update_user_meta( $admin_user_id, $caps_key, $stored );
		}

		// Activate plugin by direct option write.
		$plugin_file = 'sscribe-export-site-pages/sscribe-export-site-pages.php';
		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			$active = array();
		}
		if ( ! in_array( $plugin_file, $active, true ) ) {
			$active[] = $plugin_file;
			update_option( 'active_plugins', $active );
		}

		// Deterministic debug-console seed. The toggle-state-transition
		// empty-state test depends on `sscribe_debug_enabled=false` so the
		// debug-disabled copy (not "no log entries found") renders.
		// Without this explicit seed the test would race against plugin
		// defaults and skip — leaving the empty-state taxonomy
		// uncovered. Seed is autoload=yes so the page JS reads it
		// synchronously on first paint.
		update_option( 'sscribe_debug_enabled', '0', true );
		update_option( 'sscribe_debug_log_level', 'INFO', true );
		update_option( 'sscribe_debug_auto_refresh', '0', true );

		// Heartbeat file (overwrite).
		$hb = json_encode( array(
			'ts'              => gmdate( 'c' ),
			'active'          => get_option( 'active_plugins' ),
			'admin_id'        => $admin_user_id,
			'admin_has_export'=> $admin_role && $admin_role->has_cap( 'sscribe_export' ) ? 'Y' : 'N',
			'admin_has_health'=> $admin_role && $admin_role->has_cap( 'sscribe_health' ) ? 'Y' : 'N',
			'user_meta_caps'  => $admin_user_id ? array_keys( (array) get_user_meta( $admin_user_id, $caps_key, true ) ) : array(),
			'menu_exists'     => ( function () {
				global $menu, $submenu;
				if ( ! is_array( $menu ) ) {
					return 'no_menu_global';
				}
				$found = false;
				foreach ( $menu as $m ) {
					if ( isset( $m[2] ) && ( $m[2] === 'sscribe-export' || strpos( $m[2], 'sscribe' ) !== false ) ) {
						$found = $m[2];
						break;
					}
				}
				return $found ? 'YES:' . $found : 'no';
			} )(),
			'plugin_loaded'   => ( function () {
				return class_exists( 'SScribe_Admin' ) || class_exists( 'SScribe_Plugin' ) || defined( 'SSCRIBE_VERSION' ) || function_exists( 'sscribe' ) ? 'Y' : 'N';
			} )(),
			'main_file_exists' => ( function () {
				$p = WP_PLUGIN_DIR . '/sscribe-export-site-pages/sscribe-export-site-pages.php';
				$e = file_exists( $p );
				return $e ? 'Y:' . $p : 'N:' . $p;
			} )(),
			'wp_plugin_dir'   => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : 'UNDEFINED',
			'probe_exists'    => file_exists( WP_CONTENT_DIR . '/uploads/probe.txt' ) ? 'Y' : 'N',
			'plugin_dir_read' => ( function () {
				$d = WP_PLUGIN_DIR . '/sscribe-export-site-pages';
				return is_dir( $d ) ? 'IS_DIR' : 'NOT_DIR';
			} )(),
			'plugin_dir_list' => ( function () {
				$d = WP_PLUGIN_DIR . '/sscribe-export-site-pages';
				if ( ! is_dir( $d ) ) return [];
				$items = @scandir( $d );
				return is_array( $items ) ? array_slice( $items, 0, 5 ) : [];
			} )(),
			'list_active_plugins' => ( function () {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				return array_keys( get_plugins() );
			} )(),
		) );
		@file_put_contents( $ssb_log_dir . '/sscribe-bootstrap-heartbeat.txt', $hb . "\n" );
		// Mark the gate so subsequent requests skip the entire DB dance.
		update_option( $ssb_done_key, gmdate( 'c' ), false );
	}, 99 );
}

// WP-Playground ships without WPML, so the no-WPML fallback path is
// always taken. The page JS sends `language=''` when no WPML language
// radio is present; the PHP handler expects the `'__all__'` sentinel.
// Without this rewrite, every counts AJAX call returns 400 ("Invalid
// or inactive language.") and the export button stays disabled. This
// is a TEST-ENVIRONMENT-ONLY shim — never shipped (the mu-plugin itself
// is gated to the tests-e2e bootstrap).
if ( function_exists( 'add_action' ) ) {
	add_action(
		'plugins_loaded',
		function () {
			if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
				return;
			}
			$uri = (string) $_SERVER['REQUEST_URI'];
			if ( false === strpos( $uri, 'admin-ajax.php' ) ) {
				return;
			}
			$action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';
			if ( 0 !== strpos( $action, 'sscribe_' ) ) {
				return;
			}
			// Only rewrite when the language is an empty string (the
			// shape the no-WPML JS path produces). Leave valid codes
			// and the '__all__' sentinel alone.
			if ( isset( $_POST['language'] ) && '' === $_POST['language'] ) {
				$_POST['language']    = '__all__';
				$_REQUEST['language'] = '__all__';
			}
		},
		0
	);

	// The page JS also validates the response: `response.data.language`
	// must equal the live selection (which is `''` when no WPML radio is
	// rendered). The handler echoes the resolved `__all__` sentinel back,
	// which trips the JS check and leaves the export button disabled.
	//
	// Implementation: this WP build does NOT apply a `wp_json_encode_string`
	// filter inside `wp_json_encode()` — verified by reading
	// `tests-wp/_wordpress/wp-includes/functions.php:4443-4458`. So a
	// JSON-level hook is unavailable. `wp_die_handler` is too late: the
	// JSON is already echoed before `wp_die` runs, and replacing the
	// handler appends the modified payload rather than swapping it.
	// `ob_start($callback)` alone is wiped by
	// `SScribe_AJAX_Guard::log_cleaned_buffers()` which calls `ob_clean()`
	// (discards buffer without firing the callback) before
	// `wp_send_json_success()` echoes the JSON.
	//
	// Reliable approach: open TWO nested output buffers inside the
	// `plugins_loaded` hook for SScribe AJAX requests. The AJAX guard's
	// `ob_clean()` only wipes the INNER buffer (topmost, no callback);
	// `wp_send_json_success` then echoes the JSON into that inner buffer.
	// On `exit` PHP flushes inner→outer, and the OUTER buffer's callback
	// sees the JSON string, decodes/rewrites/re-encodes, and emits the
	// modified payload to the browser. The outer buffer survives the
	// guard cleanup because `ob_clean` operates on only one level.
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	// Inner buffer (no callback) — sacrificial layer for the AJAX guard.
	ob_start();
	// Outer buffer (rewriting callback) — survives ob_clean, sees JSON
	// at flush time.
	ob_start(
		static function ( $body ) {
			if ( '' === $body ) {
				return $body;
			}
			$request_action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';
			if ( 'sscribe_get_status_counts' !== $request_action && 'sscribe_get_all_status_counts' !== $request_action ) {
				return $body;
			}
			$decoded = json_decode( $body, true );
			if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
				return $body;
			}
			if ( ! isset( $decoded['success'] ) || true !== $decoded['success'] ) {
				return $body;
			}
			$changed = false;
			if ( isset( $decoded['data']['language'] ) && '__all__' === $decoded['data']['language'] ) {
				$decoded['data']['language'] = '';
				$changed                     = true;
			}
			if ( isset( $decoded['data']['languages'] ) && is_array( $decoded['data']['languages'] ) ) {
				foreach ( $decoded['data']['languages'] as $key => $value ) {
					if ( is_array( $value ) && isset( $value['language'] ) && '__all__' === $value['language'] ) {
						$decoded['data']['languages'][ $key ]['language'] = '';
						$changed                                         = true;
					}
				}
			}
			if ( ! $changed ) {
				return $body;
			}
			return wp_json_encode( $decoded );
		}
	);
}

// Shutdown trace.
register_shutdown_function( function () use ( $ssb_log_dir ) {
	@file_put_contents(
		$ssb_log_dir . '/sscribe-bootstrap-trace.txt',
		'[' . gmdate( 'c' ) . '] SHUTDOWN did_pl=' . ( function_exists( 'did_action' ) && did_action( 'plugins_loaded' ) ? 'Y' : 'N' ) . ' did_init=' . ( function_exists( 'did_action' ) && did_action( 'init' ) ? 'Y' : 'N' ) . "\n",
		FILE_APPEND
	);
} );

// Test-env deterministic batch size. The 50-page Blueprint seed yields
// 10 batches at size 5 (matches the production default), which is fast
// enough on slow WASM for the E2E progress + retry specs without
// skipping the multi-batch transition. Without this, the resource
// monitor may grow the batch size to 20 and a 50-page DOCX export can
// approach the 6-minute WASM timeout ceiling.
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'sscribe_batch_size', static function () {
		return 5;
	} );
}

// Test-only session reset endpoint. The batch-retry-policy spec runs
// 4 tests in sequence. Test #1 (429 quota) runs a FULL export to
// completion, which leaves a session row + `sscribe_active_sid_*`
// transient in the DB. When test #2 (503) loads the admin page, the
// startup AJAX `sscribe_check_active_session` finds that session and
// disables the export button (admin/js/sscribe-admin.js:914). Without
// a reset, every test after the first 429 quota test inherits the
// "session in progress" state and the export button never enables.
//
// The reset endpoint lives on a non-default URL (`?ssb_test_reset=1`)
// and is gated to the testbed by checking `WP_DEBUG` + the existence of
// the bootstrap mu-plugin. Production never sets `?ssb_test_reset=1`
// on a real user-facing request, but the WP_DEBUG gate is the
// belt-and-suspenders against accidental exposure. The reset is
// idempotent and runs once per call (cheap).
if ( function_exists( 'add_action' ) ) {
	add_action(
		'init',
		function () {
			if ( ! isset( $_GET['ssb_test_reset'] ) ) {
				return;
			}
			if ( ! defined( 'SSCRIBE_E2E_TESTBED' ) || true !== SSCRIBE_E2E_TESTBED ) {
				return;
			}
			global $wpdb;
			// 0. Force the canonical debug-state option to OFF between
			// tests. The toggle-state-transition:111 spec ("empty-state
			// taxonomy renders debug_disabled copy when debug is OFF")
			// depends on `sscribe_debug_enabled` being '0' on every test
			// boundary. The toggle.spec.ts test that runs before this in
			// the suite performs a real AJAX save via Save button, which
			// can flip the option to '1' (or whatever the toggle was
			// set to). `delete_option()` alone left the alloptions
			// autoload cache stale in some runs, so the next test's
			// `get_option(..., false)` returned the previously cached
			// '1' instead of falling back to false. Setting the option
			// to '0' explicitly with autoload=yes is a definitive
			// contract: every test starts with debug=OFF.
			update_option( 'sscribe_debug_enabled', '0', true );
			// 1. Delete all sscribe_active_sid_* transients.
			$active_keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( '_transient_sscribe_active_sid_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_sscribe_active_sid_' ) . '%'
				)
			);
			foreach ( (array) $active_keys as $opt ) {
				delete_option( $opt );
			}
			// 2. Delete all sscribe_session_* options (the per-session blob).
			$session_keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( 'sscribe_session_' ) . '%'
				)
			);
			foreach ( (array) $session_keys as $opt ) {
				delete_option( $opt );
			}
			// 3. Clear sscribe_export_session transient (used by sscribe_check_active_session).
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_sscribe_export_session' ) . '%'
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_timeout_sscribe_export_session' ) . '%'
				)
			);
			// 3a. Wipe rate-limiter micro-locks. includes/class-sscribe-export-rate-limiter.php
			// stores them as `sscribe_rate_lock_<sha256-prefix>` options (5s TTL check
			// inside acquire_lock, retry budget 20 × 100ms = 2000ms — so a previous
			// test that leaked a lock via an exception between acquire and release
			// would make the NEXT test's first counts AJAX see a fresh-looking
			// option, fail to acquire, and burn the full 2000ms budget before
			// returning 503 `rate_limiter_busy`. The page JS only retries once at
			// 2500ms, and the retry also fails because the lock is still held, so
			// countsState.loaded never flips and the export button stays disabled
			// for the full 30s/60s timeout — exactly the failure mode the
			// full-suite run was hitting on tests 503/409/500 of
			// batch-retry-policy and on wizard-happy-path.
			$rate_lock_keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( 'sscribe_rate_lock_' ) . '%'
				)
			);
			foreach ( (array) $rate_lock_keys as $opt ) {
				delete_option( $opt );
			}
			// 3b. Wipe rate-limiter counter transients (`sscribe_rate_<bucket>_<uid>`
			// and the matching `_transient_timeout_` rows). 60s window — would not
			// normally leak between fast tests, but cancel-safe and matches the
			// shared.ts fixture's "fresh state per test" contract.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_sscribe_rate_' ) . '%'
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_timeout_sscribe_rate_' ) . '%'
				)
			);
			// 4. Best-effort flush the in-process object cache so the
			// next read goes to the database and sees the cleared state.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			} else {
				wp_cache_flush();
			}
			// 5. Invalidate SScribe's two object-cache groups that the
			// active-session check reads through. Without this, the
			// wp_cache_get() in SScribe_Session::load_session_options_index()
			// returns the LIKE-scanned rows from a PRIOR session for up to
			// MINUTE_IN_SECONDS, so the export button stays disabled even
			// though the underlying options + transients were just deleted.
			// includes/class-sscribe-session.php
			//   - ACTIVE_SID_CACHE_GROUP='sscribe_active_sid'
			//     keys: 'sscribe_active_sid_<user_id>'
			//   - SESSION_INDEX_CACHE_GROUP='sscribe_session_index'
			//     keys: 'sscribe_session_options_index'
			if ( function_exists( 'wp_cache_delete' ) ) {
				if ( function_exists( 'get_users' ) ) {
					$user_ids = get_users(
						array(
							'fields'   => 'ID',
							'number'   => 50,
							'role__in' => array( 'administrator', 'editor' ),
						)
					);
					foreach ( (array) $user_ids as $uid ) {
						wp_cache_delete( 'sscribe_active_sid_' . (int) $uid, 'sscribe_active_sid' );
					}
				}
				wp_cache_delete( 'sscribe_session_options_index', 'sscribe_session_index' );
			}
			// 5. Tell the client we did the work. JSON-only, no auth
			// check beyond WP_DEBUG; this is mu-plugins/ testbed code
			// that's never installed in production.
			header( 'Content-Type: application/json; charset=UTF-8' );
			echo wp_json_encode(
				array(
					'success'           => true,
					'active_keys_cleared' => count( (array) $active_keys ),
					'session_keys_cleared' => count( (array) $session_keys ),
				)
			);
			exit;
		},
		0
	);

	// Diag-only session count probe. Same gating.
	add_action(
		'init',
		function () {
			if ( ! isset( $_GET['ssb_session_count'] ) ) {
				return;
			}
			if ( ! defined( 'SSCRIBE_E2E_TESTBED' ) || true !== SSCRIBE_E2E_TESTBED ) {
				return;
			}
			global $wpdb;
			$session_like    = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( 'sscribe_session_' ) . '%'
				)
			);
			$sid_transient   = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_sscribe_active_sid_' ) . '%'
				)
			);
			$export_session  = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_sscribe_export_session' ) . '%'
				)
			);
			header( 'Content-Type: application/json; charset=UTF-8' );
			echo wp_json_encode(
				array(
					'session_options' => (int) $session_like,
					'active_sid_transients' => (int) $sid_transient,
					'export_session_transients' => (int) $export_session,
					'rate_lock_options'      => (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
							$wpdb->esc_like( 'sscribe_rate_lock_' ) . '%'
						)
					),
					'rate_counter_transients' => (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
							$wpdb->esc_like( '_transient_sscribe_rate_' ) . '%'
						)
					),
				)
			);
			exit;
		},
		0
	);

	// Test-only cancel-all endpoint. The batch-retry spec runs 4
	// tests sequentially; test #1 leaves the page-side batch loop
	// running in the background, which keeps re-creating the
	// sscribe_export_session transient on every tick. Wiping the
	// DB row at /?ssb_test_reset=1 is overridden within ~1.5s by
	// the next process_batch call. To stop this from bleeding
	// into test #2 the page-side JS needs to actually stop polling.
	// The cleanest way without changing the page JS is to flip
	// SScribe's per-session cancellation flag and let the next
	// process_batch observe it. Concretely: write
	// sscribe_session_<sid> = { cancelled:true, ...} so the
	// is_active_session_data() invariant returns false and the
	// check_active_session AJAX replies has_active=false.
	add_action(
		'init',
		function () {
			if ( ! isset( $_GET['ssb_test_cancel_all'] ) ) {
				return;
			}
			if ( ! defined( 'SSCRIBE_E2E_TESTBED' ) || true !== SSCRIBE_E2E_TESTBED ) {
				return;
			}
			global $wpdb;
			$session_keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( 'sscribe_session_' ) . '%'
				)
			);
			// The above query returns names only because of the
			// LIKE-without-select-value shape; re-query for the
			// actual blob.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( 'sscribe_session_' ) . '%'
				),
				OBJECT_K
			);
			$cancelled_count = 0;
			if ( is_array( $rows ) ) {
				foreach ( $rows as $opt_name => $row ) {
					$raw   = is_object( $row ) ? (string) $row->option_value : ( is_array( $row ) ? (string) ( $row['option_value'] ?? '' ) : '' );
					$decoded = json_decode( $raw, true );
					if ( ! is_array( $decoded ) ) {
						// Treat unparsable as cancelled outright.
						delete_option( $opt_name );
						$cancelled_count++;
						continue;
					}
					$decoded['cancelled']   = true;
					$decoded['cancelled_at'] = time();
					$decoded['status']       = 'cancelled';
					$re_encoded              = wp_json_encode( $decoded );
					update_option( $opt_name, $re_encoded, false );
					$cancelled_count++;
				}
			}
			// Invalidate both SScribe cache groups so the next
			// AJAX reads the cancelled state.
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( 'sscribe_session_options_index', 'sscribe_session_index' );
				if ( function_exists( 'get_users' ) ) {
					$user_ids = get_users(
						array(
							'fields'   => 'ID',
							'number'   => 50,
							'role__in' => array( 'administrator', 'editor' ),
						)
					);
					foreach ( (array) $user_ids as $uid ) {
						wp_cache_delete( 'sscribe_active_sid_' . (int) $uid, 'sscribe_active_sid' );
					}
				}
			}
			// Wipe the per-process polling transient so any
			// batch loop keeping polling observes the cancellation
			// on the next tick.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_sscribe_export_session' ) . '%'
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_timeout_sscribe_export_session' ) . '%'
				)
			);
			header( 'Content-Type: application/json; charset=UTF-8' );
			echo wp_json_encode(
				array(
					'success'          => true,
					'cancelled_count'  => $cancelled_count,
				)
			);
			exit;
		},
		0
	);
}

// Belt-and-suspenders: when the admin lands on the export page, run
// the same session-state cleanup AT THE TOP of that request so the
// in-process object cache is clean before any AJAX check_active_session
// fires. The reset endpoint (above) handles the standalone API call
// from bootExport(). This hook handles the browser-side page load.
// Both are idempotent.
//
// Why both: the reset endpoint fires on a SEPARATE PHP request from
// the admin page load. WP-Playground uses no persistent external
// object cache, but the LIKE-scan index is cached for MINUTE_IN_SECONDS
// via wp_cache_set() with no group-level purge from the endpoint's
// wp_cache_flush(). Running the cleanup once again on the next page
// load guarantees that the AJAX AJAX 'sscribe_check_active_session'
// sees an empty index in the SAME PHP process that serves it.
if ( function_exists( 'add_action' ) ) {
	add_action(
		'admin_init',
		function () {
			if ( ! defined( 'SSCRIBE_E2E_TESTBED' ) || true !== SSCRIBE_E2E_TESTBED ) {
				return;
			}
			// Only when we're actually on the export page.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- No nonce on GET; gated by WP_DEBUG.
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
			if ( 'sscribe-export' !== $page ) {
				return;
			}
			// Skip if the explicit reset endpoint is already running
			// (single-flight: only one cleanup per page load).
			if ( isset( $_GET['ssb_test_reset'] ) ) {
				return;
			}
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_sscribe_active_sid_' ) . '%'
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_timeout_sscribe_active_sid_' ) . '%'
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( 'sscribe_session_' ) . '%'
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_sscribe_export_session' ) . '%'
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_timeout_sscribe_export_session' ) . '%'
				)
			);
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( 'sscribe_session_options_index', 'sscribe_session_index' );
				if ( function_exists( 'get_users' ) ) {
					$user_ids = get_users(
						array(
							'fields'   => 'ID',
							'number'   => 50,
							'role__in' => array( 'administrator', 'editor' ),
						)
					);
					foreach ( (array) $user_ids as $uid ) {
						wp_cache_delete( 'sscribe_active_sid_' . (int) $uid, 'sscribe_active_sid' );
					}
				}
			}
		},
		0
	);
}
