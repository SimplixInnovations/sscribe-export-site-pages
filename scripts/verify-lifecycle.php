<?php
/**
 * Phase 50: Activation / deactivation / uninstall lifecycle contract.
 *
 * WordPress invokes three lifecycle hooks for a plugin:
 *
 *   1. Activation  — register_activation_hook → create tables, register
 *      settings, schedule cron, create export directory.
 *   2. Deactivation — register_deactivation_hook → clear cron, remove
 *      transients. MUST NOT delete user options (that's uninstall's job).
 *   3. Uninstall    — uninstall.php → remove ALL plugin-owned data
 *      (sessions, page_ids, logs, options, capabilities, custom tables)
 *      ONLY when WP_UNINSTALL_PLUGIN is defined.
 *
 * A regression that crosses these boundaries ships a release that either:
 *   - destroys user data when the user only deactivates
 *   - leaves cruft behind after uninstall
 *   - runs uninstall code outside the WP_UNINSTALL_PLUGIN guard
 *
 * This verifier pins the lifecycle contract at the source level. The
 * integration test exercises the runtime guards.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir      = dirname( __DIR__ );
$main_file     = $root_dir . '/sscribe-export-site-pages.php';
$activator     = $root_dir . '/includes/class-sscribe-activator.php';
$deactivator   = $root_dir . '/includes/class-sscribe-deactivator.php';
$uninstall     = $root_dir . '/uninstall.php';
$manifest_path = $root_dir . '/dist/lifecycle-manifest.json';

$errors = array();

if ( ! is_file( $main_file ) ) {
	fwrite( STDERR, "Main plugin file not found.\n" );
	exit( 1 );
}
if ( ! is_file( $activator ) ) {
	fwrite( STDERR, "Activator class not found.\n" );
	exit( 1 );
}
if ( ! is_file( $deactivator ) ) {
	fwrite( STDERR, "Deactivator class not found.\n" );
	exit( 1 );
}
if ( ! is_file( $uninstall ) ) {
	$errors[] = 'uninstall.php is missing — WordPress will silently skip uninstall cleanup.';
}

$main_source     = (string) file_get_contents( $main_file );
$activ_source    = (string) file_get_contents( $activator );
$deact_source    = (string) file_get_contents( $deactivator );
$uninst_source   = is_file( $uninstall ) ? (string) file_get_contents( $uninstall ) : '';

$matrix = array();

// 1. Activation hook registered.
$reg_act = (bool) preg_match(
	"/register_activation_hook\\s*\\(\\s*__FILE__\\s*,\\s*array\\s*\\(\\s*['\"]SScribe_Activator['\"]\\s*,\\s*['\"]activate['\"]\\s*\\)\\s*\\)/",
	$main_source
);
$matrix[] = array(
	'rule'   => 'activation_hook_registered',
	'passes' => $reg_act,
	'detail' => 'Main file must call register_activation_hook(__FILE__, ["SScribe_Activator","activate"]).',
);
if ( ! $reg_act ) {
	$errors[] = 'Main file does not register activation hook to SScribe_Activator::activate.';
}

// 2. Deactivation hook registered.
$reg_deact = (bool) preg_match(
	"/register_deactivation_hook\\s*\\(\\s*__FILE__\\s*,\\s*array\\s*\\(\\s*['\"]SScribe_Deactivator['\"]\\s*,\\s*['\"]deactivate['\"]\\s*\\)\\s*\\)/",
	$main_source
);
$matrix[] = array(
	'rule'   => 'deactivation_hook_registered',
	'passes' => $reg_deact,
	'detail' => 'Main file must call register_deactivation_hook(__FILE__, ["SScribe_Deactivator","deactivate"]).',
);
if ( ! $reg_deact ) {
	$errors[] = 'Main file does not register deactivation hook to SScribe_Deactivator::deactivate.';
}

// 3. Activator has public static activate().
$act_pub = (bool) preg_match( '/public\s+static\s+function\s+activate\s*\(/', $activ_source );
$matrix[] = array(
	'rule'   => 'activator_activate_is_public_static',
	'passes' => $act_pub,
	'detail' => 'SScribe_Activator::activate must be public static so register_activation_hook can call it.',
);
if ( ! $act_pub ) {
	$errors[] = 'SScribe_Activator::activate is not public static.';
}

// 4. Deactivator has public static deactivate().
$deact_pub = (bool) preg_match( '/public\s+static\s+function\s+deactivate\s*\(/', $deact_source );
$matrix[] = array(
	'rule'   => 'deactivator_deactivate_is_public_static',
	'passes' => $deact_pub,
	'detail' => 'SScribe_Deactivator::deactivate must be public static so register_deactivation_hook can call it.',
);
if ( ! $deact_pub ) {
	$errors[] = 'SScribe_Deactivator::deactivate is not public static.';
}

// 5. Activator schedules the canonical cron hooks.
$cron_hooks = array( 'sscribe_cleanup_exports', 'sscribe_cleanup_sessions', 'sscribe_cleanup_audit_trail' );
$scheduled_hooks = array();
foreach ( $cron_hooks as $hook ) {
	$schedules = strpos( $activ_source, "wp_schedule_event( time()," ) !== false
		&& strpos( $activ_source, "'{$hook}'" ) !== false;
	$scheduled_hooks[ $hook ] = $schedules;
	$matrix[] = array(
		'rule'   => "activator_schedules_{$hook}",
		'passes' => $schedules,
		'detail' => "Activator must schedule the {$hook} cron event.",
	);
	if ( ! $schedules ) {
		$errors[] = "Activator does not schedule the {$hook} cron event.";
	}
}

// 6. Deactivator clears the same cron hooks (symmetry).
foreach ( $cron_hooks as $hook ) {
	$clears = strpos( $deact_source, "wp_clear_scheduled_hook( '{$hook}' )" ) !== false
		|| strpos( $deact_source, "wp_clear_scheduled_hook( \"{$hook}\" )" ) !== false;
	$matrix[] = array(
		'rule'   => "deactivator_clears_{$hook}",
		'passes' => $clears,
		'detail' => "Deactivator must clear the {$hook} cron event to prevent orphan tasks.",
	);
	if ( ! $clears ) {
		$errors[] = "Deactivator does not clear the {$hook} cron event.";
	}
}

// 7. Deactivator must only clean transients (prefixed _transient_ /
// _transient_timeout_ / sscribe_export_lock_ / sscribe_rate_lock_).
// A delete_option call on a user-owned plugin option crosses the
// deactivation/uninstall boundary and would destroy data on deactivation.
//
// We can't statically prove which option_name a variable holds, so the
// check is structural: a positive evidence that the delete_option call is
// preceded by a LIKE query scoped to one of the accepted transient
// prefixes. We grep for the LIKE-%s select pattern + the matching delete
// in the same deactivator body.
$deact_cleans_transients = (bool) preg_match(
	'#LIKE %s LIMIT 1000#',
	$deact_source
) && (bool) preg_match(
	'#_transient_sscribe_#',
	$deact_source
) && (bool) preg_match(
	'#_transient_timeout_sscribe_#',
	$deact_source
);
$matrix[] = array(
	'rule'   => 'deactivator_cleans_sscribe_transients',
	'passes' => $deact_cleans_transients,
	'detail' => 'Deactivator must delete _transient_sscribe_* / _transient_timeout_sscribe_* via a LIKE-bounded loop.',
);
if ( ! $deact_cleans_transients ) {
	$errors[] = 'Deactivator does not have a LIKE-bounded cleanup of sscribe_* transients.';
}

// 7b. Deactivator must not delete user options by a literal plugin-owned
// prefix that isn't a transient. We allow the literal 'sscribe_upgrade_lock'
// transient via delete_transient() but flag any delete_option() called
// with a literal user-option prefix.
$deact_user_option_call = (bool) preg_match(
	'#delete_option\s*\(\s*[\'"](sscribe_settings|sscribe_export_settings|sscribe_options)#',
	$deact_source
);
$matrix[] = array(
	'rule'   => 'deactivator_does_not_target_user_option_prefixes',
	'passes' => ! $deact_user_option_call,
	'detail' => 'Deactivator must not delete_option() a user-owned plugin option prefix (settings/options).',
);
if ( $deact_user_option_call ) {
	$errors[] = 'Deactivator calls delete_option() on a user-option prefix (settings/options). That\'s uninstall\'s job.';
}

// 8. uninstall.php is guarded by WP_UNINSTALL_PLUGIN.
$uninst_guarded = $uninst_source !== ''
	&& (bool) preg_match( '#if\s*\(\s*!\s*defined\s*\(\s*[\'"]WP_UNINSTALL_PLUGIN[\'"]\s*\)\s*\)\s*\{[^}]*exit;#', $uninst_source );
$matrix[] = array(
	'rule'   => 'uninstall_guarded_by_constant',
	'passes' => $uninst_guarded,
	'detail' => 'uninstall.php must exit when WP_UNINSTALL_PLUGIN is not defined to prevent arbitrary execution.',
);
if ( ! $uninst_guarded ) {
	$errors[] = 'uninstall.php is not guarded by WP_UNINSTALL_PLUGIN.';
}

// 9. uninstall.php deletes plugin options (sscribe_session_*, sscribe_page_ids_*, sscribe_log_*).
$uninst_option_targets = array( 'sscribe_session_', 'sscribe_page_ids_', 'sscribe_log_' );
foreach ( $uninst_option_targets as $target ) {
	$wipes = strpos( $uninst_source, "'{$target}'" ) !== false
		|| strpos( $uninst_source, "\"{$target}\"" ) !== false;
	$matrix[] = array(
		'rule'   => "uninstall_wipes_{$target}",
		'passes' => $wipes,
		'detail' => "uninstall.php must wipe all rows matching {$target}*.",
	);
	if ( ! $wipes ) {
		$errors[] = "uninstall.php does not wipe rows matching {$target}*.";
	}
}

// 10. uninstall.php removes user capabilities (best-effort, optional).
$uninst_removes_caps = strpos( $uninst_source, 'remove_cap' ) !== false || strpos( $uninst_source, 'remove_role' ) !== false;
$matrix[] = array(
	'rule'   => 'uninstall_removes_capabilities_or_roles',
	'passes' => $uninst_removes_caps,
	'detail' => 'uninstall.php should remove plugin-added capabilities or roles so uninstall is reversible.',
);
if ( ! $uninst_removes_caps ) {
	// Non-fatal: the verifier records this but does not error. A plugin
	// that adds no custom role/cap is still correct.
}

// 11. Activator has create_database_tables method.
$has_db_setup = (bool) preg_match( '/private\s+static\s+function\s+create_database_tables\s*\(/', $activ_source );
$matrix[] = array(
	'rule'   => 'activator_creates_database_tables',
	'passes' => $has_db_setup,
	'detail' => 'SScribe_Activator must have a create_database_tables() helper.',
);
if ( ! $has_db_setup ) {
	$errors[] = 'SScribe_Activator does not have create_database_tables().';
}

// 12. Activator registers settings.
$has_register_settings = (bool) preg_match( '/public\s+static\s+function\s+register_settings\s*\(/', $activ_source );
$matrix[] = array(
	'rule'   => 'activator_registers_settings',
	'passes' => $has_register_settings,
	'detail' => 'SScribe_Activator must have register_settings() so default values are seeded on activation.',
);
if ( ! $has_register_settings ) {
	$errors[] = 'SScribe_Activator::register_settings is missing.';
}

// 13. Activator creates export directory.
$has_export_dir = strpos( $activ_source, 'create_export_directory' ) !== false;
$matrix[] = array(
	'rule'   => 'activator_creates_export_directory',
	'passes' => $has_export_dir,
	'detail' => 'SScribe_Activator must create the export directory on activation.',
);
if ( ! $has_export_dir ) {
	$errors[] = 'SScribe_Activator does not create the export directory.';
}

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'        => gmdate( 'c' ),
	'rule_count'          => count( $matrix ),
	'passed_count'        => count( array_filter( $matrix, static fn( $r ) => $r['passes'] ) ),
	'scheduled_hooks'     => $scheduled_hooks,
	'matrix'              => $matrix,
	'errors_count'        => count( $errors ),
	'passes'              => 0 === count( $errors ),
	'errors'              => $errors,
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe Lifecycle Contract ===\n\n";
foreach ( $matrix as $row ) {
	$status = $row['passes'] ? '✓' : '✗';
	echo sprintf( "  %s  %-44s %s\n", $status, $row['rule'], $row['detail'] );
}
echo "\nErrors: " . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  ✗ {$error}\n";
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}
echo "✓ Lifecycle contract valid.\n";
exit( 0 );
