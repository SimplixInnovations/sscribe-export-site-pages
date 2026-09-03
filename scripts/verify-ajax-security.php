<?php
/**
 * Phase 49: AJAX security inventory.
 *
 * The plugin exposes many `wp_ajax_sscribe_*` actions through
 * `admin-ajax.php`. Each one must:
 *
 *   1. Be registered through `SScribe_Loader::add_guarded_ajax_action`
 *      (which wraps the callback with nonce + capability checks via
 *      SScribe_AJAX_Guard::with_guard) OR be wrapped in a centralized
 *      `verify_request_authorization` that runs `check_ajax_referer`
 *      + `current_user_can` + rate limiting.
 *   2. NOT be exposed to logged-out users (zero `wp_ajax_nopriv_sscribe_*`
 *      registrations — any such action would let unauthenticated visitors
 *      invoke the export pipeline).
 *   3. Use the canonical nonce action name `sscribe_export_nonce`.
 *
 * A regression that adds an unguarded `add_action('wp_ajax_sscribe_*', ...)`
 * or wires a public `wp_ajax_nopriv_sscribe_*` action ships a release whose
 * AJAX surface can be hit by an unauthenticated visitor. This verifier
 * produces the complete action inventory and enforces the security contract.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir      = dirname( __DIR__ );
$manifest_path = $root_dir . '/dist/ajax-security-manifest.json';
$scan_dirs     = array(
	$root_dir . '/includes',
	$root_dir . '/admin',
);

$errors = array();

if ( ! is_dir( $root_dir . '/includes' ) || ! is_dir( $root_dir . '/admin' ) ) {
	fwrite( STDERR, "Plugin source tree missing expected directories.\n" );
	exit( 1 );
}

$files = array();
foreach ( $scan_dirs as $dir ) {
	$rii = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
	);
	foreach ( $rii as $info ) {
		if ( $info->isFile() && 'php' === strtolower( $info->getExtension() ) ) {
			$files[] = $info->getPathname();
		}
	}
}
sort( $files );

// Patterns. Use '#' delimiter to avoid conflict with the single-quote
// literals inside the patterns.
$pattern_raw_add_action   = '#add_action\s*\(\s*[\'"](wp_ajax_(sscribe_[a-z0-9_]+))[\'"]#';
$pattern_nopriv           = '#add_action\s*\(\s*[\'"](wp_ajax_nopriv_(sscribe_[a-z0-9_]+))[\'"]#';
$pattern_guarded_arg      = '#add_guarded_ajax_action\s*\(\s*[\'"](wp_ajax_(sscribe_[a-z0-9_]+))[\'"]#';
$pattern_verify_authz     = '#verify_request_authorization\s*\(#';
$pattern_canonical_nonce  = '#check_ajax_referer\s*\(\s*[\'"]sscribe_export_nonce[\'"]#';

$inventory          = array(); // slug => registration rows + has_local_authz + guarded_via_loader
$nopriv_seen        = array();
$canonical_nonce_ok = false;

// Pass 1 — find every wp_ajax_sscribe_* registration (raw + guarded).
foreach ( $files as $file ) {
	$source = (string) file_get_contents( $file );
	$rel    = str_replace( $root_dir . '/', '', $file );

	// Strip /** */ docblock comments so example hook names inside
	// `@example` blocks don't get counted as real registrations.
	$code_only = (string) preg_replace( '#/\*\*[\s\S]*?\*/#', '', $source );

	// Skip docblock-only files (no actual add_action call).
	if ( preg_match_all( $pattern_raw_add_action, $code_only, $m_raw ) ) {
		foreach ( $m_raw[1] as $idx => $hook ) {
			$slug = $m_raw[2][ $idx ];
			if ( ! isset( $inventory[ $slug ] ) ) {
				$inventory[ $slug ] = array(
					'action'           => $slug,
					'guarded_loader'   => false,
					'has_local_authz'  => false,
					'registration'     => array(),
				);
			}
			$inventory[ $slug ]['registration'][] = array(
				'file'              => $rel,
				'guarded_via_loader' => false,
				'raw_add_action'    => true,
			);
		}
	}

	if ( preg_match_all( $pattern_guarded_arg, $code_only, $m_g ) ) {
		foreach ( $m_g[1] as $idx => $hook ) {
			$slug = $m_g[2][ $idx ];
			if ( ! isset( $inventory[ $slug ] ) ) {
				$inventory[ $slug ] = array(
					'action'           => $slug,
					'guarded_loader'   => true,
					'has_local_authz'  => false,
					'registration'     => array(),
				);
			} else {
				$inventory[ $slug ]['guarded_loader'] = true;
			}
			$inventory[ $slug ]['registration'][] = array(
				'file'              => $rel,
				'guarded_via_loader' => true,
				'raw_add_action'    => false,
			);
		}
	}

	if ( preg_match( $pattern_nopriv, $code_only, $m_np ) ) {
		$nopriv_seen[] = $m_np[2] . ' (' . $rel . ')';
	}

	if ( preg_match( $pattern_verify_authz, $code_only ) ) {
		// Local authz pattern present — applies to every action registered
		// in this file via raw add_action().
		foreach ( $inventory as $slug => $row ) {
			foreach ( $row['registration'] as $r ) {
				if ( $r['file'] === $rel && ! $r['guarded_via_loader'] ) {
					$inventory[ $slug ]['has_local_authz'] = true;
				}
			}
		}
	}

	if ( preg_match( $pattern_canonical_nonce, $code_only ) ) {
		$canonical_nonce_ok = true;
	}
}

ksort( $inventory );

// Every registered action must be either guarded via the loader OR have
// local authorization in the file.
foreach ( $inventory as $slug => $row ) {
	if ( ! $row['guarded_loader'] && ! $row['has_local_authz'] ) {
		$files_list = implode( ', ', array_unique( array_column( $row['registration'], 'file' ) ) );
		$errors[]   = sprintf(
			'%s is registered via raw add_action() in %s without a verify_request_authorization() guard.',
			$slug,
			$files_list
		);
	}
}

if ( ! $canonical_nonce_ok ) {
	$errors[] = 'Canonical nonce action "sscribe_export_nonce" is not referenced anywhere in includes/ or admin/.';
}

if ( ! empty( $nopriv_seen ) ) {
	foreach ( $nopriv_seen as $entry ) {
		$errors[] = sprintf(
			'wp_ajax_nopriv_sscribe_* registration found for %s — public AJAX actions are forbidden.',
			$entry
		);
	}
}

// Manifest rows.
$rows = array();
foreach ( $inventory as $slug => $row ) {
	$rows[] = array(
		'action'          => $slug,
		'guarded_loader'  => $row['guarded_loader'],
		'local_authz'     => $row['has_local_authz'],
		'passes'          => $row['guarded_loader'] || $row['has_local_authz'],
		'registration'    => $row['registration'],
	);
}

$action_count    = count( $rows );
$guarded_count   = count( array_filter( $rows, static fn( $r ) => $r['guarded_loader'] ) );
$local_count     = count( array_filter( $rows, static fn( $r ) => $r['local_authz'] && ! $r['guarded_loader'] ) );

// Persist manifest.
$manifest_dir = dirname( $manifest_path );
if ( ! is_dir( $manifest_dir ) ) {
	mkdir( $manifest_dir, 0755, true );
}
$manifest = array(
	'generated_at'        => gmdate( 'c' ),
	'action_count'        => $action_count,
	'guarded_count'       => $guarded_count,
	'local_authz_count'   => $local_count,
	'nopriv_count'        => count( $nopriv_seen ),
	'canonical_nonce'     => $canonical_nonce_ok,
	'rows'                => $rows,
	'errors_count'        => count( $errors ),
	'passes'              => 0 === count( $errors ),
	'errors'              => $errors,
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents(
	$manifest_path,
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

echo "=== SScribe AJAX Security Inventory ===\n\n";
echo sprintf( "Actions discovered:    %d\n", $action_count );
echo sprintf( "Guarded via loader:    %d\n", $guarded_count );
echo sprintf( "Local authz pattern:   %d\n", $local_count );
echo sprintf( "wp_ajax_nopriv_:       %d (must be 0)\n", count( $nopriv_seen ) );
echo sprintf( "Canonical nonce used:  %s\n", $canonical_nonce_ok ? 'yes' : 'NO' );
echo "\n";
foreach ( $rows as $row ) {
	$status = $row['passes'] ? '✓' : '✗';
	$mode   = $row['guarded_loader'] ? 'guarded' : ( $row['local_authz'] ? 'local' : 'UNGUARDED' );
	echo sprintf( "  %s  %-44s [%s]\n", $status, $row['action'], $mode );
}
echo "\nErrors: " . count( $errors ) . "\n";
foreach ( $errors as $error ) {
	echo "  ✗ {$error}\n";
}
echo "\nManifest persisted to: {$manifest_path}\n";

if ( ! empty( $errors ) ) {
	exit( 1 );
}
echo "✓ AJAX security inventory contract valid.\n";
exit( 0 );
