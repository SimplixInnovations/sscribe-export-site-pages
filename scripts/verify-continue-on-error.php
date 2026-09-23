<?php
/**
 * Phase 31: continue-on-error contract.
 *
 * A real export run rarely fails every page — it usually fails one
 * badly-malformed post or hits a single memory spike. The batch loop
 * MUST therefore treat per-page failures as data points, not as a
 * signal to abort the whole run. Without this guarantee, one bad page
 * wastes every page already exported and every page queued after it.
 *
 * The contract is enforced across four surfaces:
 *
 *   1. includes/traits/trait-sscribe-batch-step-handler.php — the
 *      per-page try/catch around dispatch_formats, the per-page
 *      `continue` on missing-data / memory-shortage, and the per-page
 *      `do_action( 'sscribe_after_export_page', $page_id, $formats,
 *      $export_success )` so observability/audit hooks can react.
 *
 *   2. includes/class-sscribe-batch-processor.php — wires the trait
 *      into the Batch_Processor final class.
 *
 *   3. includes/class-sscribe-export-stats.php +
 *      includes/class-sscribe-activator.php — the failed_pages column
 *      is part of the schema and complete_export() updates it.
 *
 *   4. includes/traits/trait-sscribe-export-finalizer.php — at ZIP
 *      finalization, the count of failed pages is computed from the
 *      session errors array and persisted to the export_stats table.
 *
 * This verifier enforces:
 *
 *   1. The batch trait has a try/catch wrapping dispatch_formats.
 *   2. The trait uses `continue` (not `break`) when a page cannot
 *      proceed (no page data, insufficient memory).
 *   3. The trait fires `sscribe_after_export_page` with three args
 *      including an `$export_success` boolean per page.
 *   4. The schema activator declares a `failed_pages` column.
 *   5. The export_stats class accepts failed_pages and writes it.
 *   6. The export finalizer persists failed_pages via complete_export.
 *   7. No `break` statement exists inside the per-page branch that
 *      would abort the entire batch on a single failure.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );

$batch_trait   = $root_dir . '/includes/traits/trait-sscribe-batch-step-handler.php';
$finalizer     = $root_dir . '/includes/traits/trait-sscribe-export-finalizer.php';
$processor     = $root_dir . '/includes/class-sscribe-batch-processor.php';
$activator     = $root_dir . '/includes/class-sscribe-activator.php';
$export_stats  = $root_dir . '/includes/class-sscribe-export-stats.php';
$upgrader      = $root_dir . '/includes/class-sscribe-upgrader.php';

$errors = array();

foreach (
	array(
		'batch trait'   => $batch_trait,
		'finalizer'     => $finalizer,
		'processor'     => $processor,
		'activator'     => $activator,
		'export stats'  => $export_stats,
		'upgrader'      => $upgrader,
	) as $label => $path
) {
	if ( ! is_file( $path ) ) {
		$errors[] = sprintf( 'Required file missing: %s (%s).', $label, $path );
	}
}

if ( ! empty( $errors ) ) {
	echo "=== SScribe Continue-On-Error Verification ===\n\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

$trait_src    = (string) file_get_contents( $batch_trait );
$finalizer_src = (string) file_get_contents( $finalizer );
$proc_src     = (string) file_get_contents( $processor );
$activator_src = (string) file_get_contents( $activator );
$stats_src    = (string) file_get_contents( $export_stats );
$upgrader_src = (string) file_get_contents( $upgrader );

// 1. try/catch around dispatch_formats.
if ( ! preg_match( '/try\s*\{[^}]*\$this->dispatch_formats/s', $trait_src ) ) {
	$errors[] = 'Batch trait does not wrap dispatch_formats in a try/catch. A single page throw would abort the whole export.';
}
if ( ! preg_match( '/catch\s*\(\s*\\\\?Throwable[^)]*\)\s*\{/s', $trait_src ) ) {
	$errors[] = 'Batch trait does not catch \\Throwable around the per-page dispatch.';
}

// 2. `continue` (not `break`) on per-page failure paths.
// Look for the per-page branch and require that it ends with `continue`
// at the early-exit points (no page data, insufficient memory).
// We match a small, branch-unique anchor block so the verifier cannot
// silently satisfy this check by matching the `continue` of the next
// branch. The no_page_data branch is uniquely identified by
// `$page_data = $this->collector->get_page_data(...)` followed by
// `if ( ! $page_data )`. The insufficient_memory branch is uniquely
// identified by `is_memory_available( $pre_export_memory_mb )`.
$continue_required = array(
	'no_page_data'        => '/\$page_data\s*=\s*\\$this->collector->get_page_data\([^)]+\);[\s\S]{0,1500}if\s*\(\s*!\s*\\$page_data\s*\)\s*\{[\s\S]{0,1500}continue\s*;/',
	'insufficient_memory' => '/\\$this->is_memory_available\(\s*\\$pre_export_memory_mb\s*\)\s*\)\s*\{[\s\S]{0,1500}continue\s*;/',
);
foreach ( $continue_required as $label => $pattern ) {
	if ( ! preg_match( $pattern, $trait_src ) ) {
		$errors[] = sprintf(
			'Batch trait does not use `continue` on the %s failure path; a single bad page would otherwise halt the entire batch.',
			$label
		);
	}
}

// 3. sscribe_after_export_page action with 3 args + $export_success.
$action_pattern = '/do_action\\(\\s*\'sscribe_after_export_page\'\\s*,\\s*\\$page_id\\s*,\\s*\\$formats\\s*,\\s*\\$export_success\\s*\\)/';
if ( ! preg_match( $action_pattern, $trait_src ) ) {
	$errors[] = 'Batch trait does not fire do_action(sscribe_after_export_page, $page_id, $formats, $export_success). The action is the only post-page observability seam.';
}

// 4. Schema declares `failed_pages`.
if ( ! preg_match( '/failed_pages\s+INT\s+UNSIGNED/i', $activator_src ) ) {
	$errors[] = 'Activator schema does not declare `failed_pages INT UNSIGNED`. The export_stats table cannot persist the failure count.';
}
if ( ! preg_match( '/SScribe_Activator::create_database_tables\(\s*false\s*\)/', $upgrader_src ) ) {
	$errors[] = 'Upgrader does not reconcile through SScribe_Activator::create_database_tables(false). Upgrade paths must reuse the canonical dbDelta schema that declares failed_pages.';
}

// 5. export_stats writes failed_pages via complete_export AND update_progress.
//    `update_progress` accepts failed_pages as a direct parameter.
//    `complete_export` accepts it inside the $results array.
if ( ! preg_match( '/function\s+update_progress\s*\(\s*string\s+\$session_id\s*,\s*int\s+\$successful_pages\s*,\s*int\s+\$failed_pages/s', $stats_src ) ) {
	$errors[] = 'SScribe_Export_Stats::update_progress does not declare a `int $failed_pages` parameter.';
}
if ( ! preg_match( '/function\s+complete_export\s*\(\s*string\s+\$session_id\s*,\s*array\s+\$results/s', $stats_src ) ) {
	$errors[] = 'SScribe_Export_Stats::complete_export does not declare a `array $results` parameter.';
}
if ( ! preg_match( "/'failed_pages'\s*=>\s*max\s*\(\s*0\s*,\s*\(int\)\s*\(\s*\\\$results\[.failed_pages.\]/s", $stats_src ) ) {
	$errors[] = 'SScribe_Export_Stats::complete_export does not persist failed_pages from $results into the stats row.';
}
if ( ! preg_match( "/'failed_pages'\s*=>\s*\\\$failed_pages/s", $stats_src ) ) {
	$errors[] = 'SScribe_Export_Stats::update_progress does not write the failed_pages field into the stats row.';
}

// 6. Finalizer computes failed_pages from session errors and persists via complete_export.
if ( ! preg_match( "/'failed_pages'\s*=>\s*\\\$error_count/s", $finalizer_src ) ) {
	$errors[] = 'Export finalizer does not pass `failed_pages => $error_count` to complete_export(). A clean batch run silently drops the failure tally.';
}
if ( ! preg_match( '/\\$error_count\s*=\s*\(int\)\s*\(\s*\\$session\[.error_count.\][^;]*count[^;]*/s', $finalizer_src )
	&& ! preg_match( '/\\$error_count\s*=\s*\(int\)\s*\(\s*\\$session\[.error_count.\]\s*\?\?\s*count\s*\(\s*\\$session\[.errors.\]/s', $finalizer_src )
) {
	// Looser fallback: just ensure $error_count is derived from session error_count or count(errors).
	if ( ! preg_match( '/\\$error_count\s*=[^;]*count\s*\(\s*\\$session\[.errors.\]/s', $finalizer_src )
		&& ! preg_match( '/\\$error_count\s*=[^;]*\\$session\[.error_count.\]/s', $finalizer_src )
	) {
		$errors[] = 'Export finalizer does not derive $error_count from the session errors array. Failed pages cannot be tallied.';
	}
}

// 7. No `break` inside the per-page branch on a single-page failure.
// The catch block + the early-failure paths use `continue`. A `break`
// inside the per-page branch would abort the whole batch.
$per_page_branch = '';
if ( preg_match(
	'/foreach\s*\([^)]*page_ids[\s\S]*?(?=\}\s*catch\s*\(\s*\\\\?Throwable|\}\s*finally|$)/',
	$trait_src,
	$matches
) ) {
	$per_page_branch = $matches[0];
}
if ( '' !== $per_page_branch ) {
	// Strip the inner `break` inside `if (cancelled) { break; }` — that's
	// the legitimate mid-batch cancellation sentinel.
	$cancel_break_stripped = preg_replace(
		'/if\s*\([^)]*cancelled[^)]*\)\s*\{[^}]*break\s*;[^}]*\}/s',
		'',
		$per_page_branch
	);
	// Count `break` statements remaining.
	$remaining_breaks = preg_match_all( '/\bbreak\s*;/', (string) $cancel_break_stripped );
	if ( $remaining_breaks > 0 ) {
		$errors[] = "Batch loop contains {$remaining_breaks} `break` statement(s) outside the cancellation sentinel. A single page failure would abort the entire export.";
	}
}

// 8. Processor wires the trait (sanity: the batch handler trait is `use`d).
if ( ! preg_match( '/use\s+SScribe_Batch_Step_Handler\s*;/', $proc_src ) ) {
	$errors[] = 'SScribe_Batch_Processor does not `use SScribe_Batch_Step_Handler`. The continue-on-error trait is not active in the production class.';
}
if ( ! preg_match( '/use\s+SScribe_Export_Finalizer\s*;/', $proc_src ) ) {
	$errors[] = 'SScribe_Batch_Processor does not `use SScribe_Export_Finalizer`. The failed_pages persistence is not active in the production class.';
}

echo "=== SScribe Continue-On-Error Verification ===\n\n";
echo 'Batch trait:                 ' . ( is_file( $batch_trait ) ? 'present' : 'missing' ) . "\n";
echo 'Finalizer trait:             ' . ( is_file( $finalizer ) ? 'present' : 'missing' ) . "\n";
echo 'Batch processor:             ' . ( is_file( $processor ) ? 'present' : 'missing' ) . "\n";
echo 'Activator schema:            ' . ( is_file( $activator ) ? 'present' : 'missing' ) . "\n";
echo 'Export stats:                ' . ( is_file( $export_stats ) ? 'present' : 'missing' ) . "\n\n";

if ( ! empty( $errors ) ) {
	echo "Errors:\n";
	foreach ( $errors as $error ) {
		echo "  ✗ {$error}\n";
	}
	echo "\n";
	exit( 1 );
}

echo "✓ Continue-on-error contract holds.\n";
exit( 0 );
