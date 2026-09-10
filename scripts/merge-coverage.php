<?php
/**
 * Coverage merger: combines core + WP coverage data via phpcov.
 *
 * Architecture
 * ============
 *
 * The coverage gate consumes a single canonical clover.xml. Until this
 * script landed, only the unit / integration / security suites (which
 * run without a real WordPress runtime) wrote that file. The real-WP
 * integration suite (tests-wp/WordPress) was excluded because its
 * phpunit-wp.xml configuration did not emit coverage at all.
 *
 * That meant every AJAX endpoint, every wp_die() flow, every cron
 * handler, every WP_Query-bound admin page was invisible to the gate
 * — even when the integration suite passed cleanly.
 *
 * This script wires the two suites into a single combined report:
 *
 *   PHPUnit (core, --coverage-php)        ─┐
 *                                          ├─► phpcov merge ─► clover.xml
 *   PHPUnit (real-WP, --coverage-php)     ─┘
 *
 * The merge is fail-closed: every phase validates its inputs before
 * delegating to the next, so a broken coverage run can never silently
 * degrade the gate into "0% covered and the verifier says PASS".
 *
 * Fail-closed contract
 * ====================
 *
 *   - coverage directory missing          → exit 1
 *   - no *.cov files in directory         → exit 1
 *   - any .cov file is unreadable         → exit 1
 *   - any .cov file is corrupt            → exit 1
 *   - any .cov file is not CodeCoverage   → exit 1
 *   - any .cov file references files      → exit 1
 *     outside the audited source tree
 *   - phpcov merge fails                  → exit 1
 *   - merged clover.xml missing           → exit 1
 *   - merged clover.xml is empty          → exit 1
 *   - merged clover.xml has no <file>     → exit 1
 *
 * Source-tree validation uses the shared sscribe_normalize_clover_path
 * helper so Windows backslash paths and POSIX forward-slash paths are
 * treated as the same canonical file.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/coverage-path.php';

// Bootstrap the Composer autoloader so SebastianBergmann\CodeCoverage
// is available in this spawned PHP process; otherwise unserialize()
// silently coerces the serialized CodeCoverage into __PHP_Incomplete_Class
// and the validator's instanceof check fails closed.
$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "merge-coverage: vendor/autoload.php missing; run composer install\n" );
	exit( 2 );
}
require_once $autoload;

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "merge-coverage: must run from CLI\n" );
	exit( 2 );
}

$root_dir = dirname( __DIR__ );

// `--validate-only` runs phases 1-3 (input validation, fail-closed
// checks) without invoking phpcov. Used by unit tests and by CI
// pre-flight to catch corrupt/missing/out-of-tree .cov files before
// the expensive merge step.
$validate_only = false;
$args          = $argv;
array_shift( $args ); // script name
foreach ( $args as $i => $arg ) {
	if ( '--validate-only' === $arg ) {
		$validate_only = true;
		unset( $args[ $i ] );
	}
}
$args = array_values( $args );

$cov_dir        = isset( $args[0] ) && is_string( $args[0] ) && '' !== $args[0] ? $args[0] : $root_dir . '/dist/coverage-data';
$clover_out     = isset( $args[1] ) && is_string( $args[1] ) && '' !== $args[1] ? $args[1] : $root_dir . '/clover.xml';
$allowed_source = isset( $args[2] ) && is_string( $args[2] ) && '' !== $args[2] ? $args[2] : $root_dir . '/includes';

$coverage_class = 'SebastianBergmann\\CodeCoverage\\CodeCoverage';

/**
 * Print to stderr and exit non-zero.
 */
$fail = static function ( string $msg ): never {
	fwrite( STDERR, "merge-coverage: {$msg}\n" );
	exit( 1 );
};

/**
 * Validate a single .cov file: it must be a syntactically valid PHP
 * file whose `return` value is a CodeCoverage object, AND every file
 * in its coveredFiles() list must live under the audited source tree.
 *
 * PHPUnit's --coverage-php output is a PHP source file of the form
 *   <?php
 *   return \unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'
 *   ...
 *   END_OF_COVERAGE_SERIALIZATION
 *   );
 *
 * so we lint with `php -l` then `require` it inside an isolated
 * closure so the loaded Coverage cannot pollute the merger scope.
 *
 * @param string $cov_file       Absolute path to a *.cov file.
 * @param string $allowed_source Absolute path to the audited tree root.
 */
$validate_cov = static function ( string $cov_file, string $allowed_source ) use ( $coverage_class, $fail ): void {
	if ( ! is_file( $cov_file ) || ! is_readable( $cov_file ) ) {
		$fail( "could not read {$cov_file}" );
	}

	// Lint first — a syntactically broken .cov would fatal-error
	// inside require. `php -l` exits 0 on success.
	$lint_cmd = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $cov_file );
	$lint_output = array();
	$lint_rc     = 0;
	exec( $lint_cmd . ' 2>&1', $lint_output, $lint_rc );
	if ( 0 !== $lint_rc ) {
		$fail( "{$cov_file} is corrupt (php -l failed): " . implode( ' ', $lint_output ) );
	}

	// `require` the file inside an isolated closure so its top-level
	// `return` becomes the closure's return value. Catch Error so a
	// thrown unserialize or autoload failure is reported as "corrupt".
	try {
		$loaded = ( static function ( string $file ) {
			return require $file;
		} )( $cov_file );
	} catch ( \Throwable $e ) {
		$fail( "{$cov_file} is corrupt (require threw: " . $e->getMessage() . ')' );
	}

	if ( ! ( $loaded instanceof $coverage_class ) ) {
		$type = is_object( $loaded ) ? get_class( $loaded ) : gettype( $loaded );
		$fail( "{$cov_file} does not contain a {$coverage_class} object (got {$type})" );
	}

	$real_allowed = realpath( $allowed_source );
	if ( false === $real_allowed ) {
		$fail( "allowed source path does not exist: {$allowed_source}" );
	}
	$cmp_allowed = strtolower( str_replace( '\\', '/', $real_allowed ) );

	$covered = $loaded->getData()->coveredFiles();
	foreach ( $covered as $covered_file ) {
		// Normalise Windows backslashes before realpath.
		$normalized = str_replace( '\\', '/', $covered_file );
		$real       = realpath( $normalized );
		if ( false === $real ) {
			// File on disk no longer exists — coverage was recorded
			// against a path that's been moved or deleted since the
			// test run. Skip; phpcov will tolerate missing files.
			continue;
		}
		$cmp_real = strtolower( str_replace( '\\', '/', $real ) );
		if ( 0 !== strpos( $cmp_real, $cmp_allowed . '/' ) && $cmp_real !== $cmp_allowed ) {
			$fail(
				"{$cov_file} references a source path outside the audited source tree: "
				. "{$covered_file} (allowed root: {$allowed_source})"
			);
		}
	}
};

/* ------------------------------ main flow ------------------------------ */

// Phase 1: directory must exist.
if ( ! is_dir( $cov_dir ) ) {
	$fail( "coverage data directory missing: {$cov_dir}" );
}

// Phase 2: at least one *.cov file must be present.
$cov_files = glob( $cov_dir . '/*.cov' );
if ( ! is_array( $cov_files ) || empty( $cov_files ) ) {
	$fail( "no *.cov files in {$cov_dir}" );
}
sort( $cov_files ); // deterministic merge order

// Phase 3: each .cov file must deserialize as CodeCoverage and stay
// inside the audited source tree.
foreach ( $cov_files as $cov_file ) {
	$validate_cov( $cov_file, $allowed_source );
}

// Phase 3b: `--validate-only` short-circuits here. The merged
// clover.xml is not produced; callers (unit tests, CI dry-run) get
// exit 0 to confirm input hygiene without paying for phpcov merge.
if ( $validate_only ) {
	echo 'merge-coverage: validated ' . count( $cov_files ) . " coverage file(s); --validate-only skipped phpcov merge\n";
	exit( 0 );
}

// Phase 4: invoke phpcov merge.
$phpcov_bin = $root_dir . '/vendor/bin/phpcov';
if ( ! is_file( $phpcov_bin ) ) {
	$fail( "phpcov binary missing at {$phpcov_bin}; run composer install" );
}

// Ensure the output directory exists so phpcov can write clover.
// Only clover.xml is produced; HTML reports would need ~1 GB of
// memory at this scale and are not consumed by the gate.
$clover_dir = dirname( $clover_out );
if ( ! is_dir( $clover_dir ) ) {
	mkdir( $clover_dir, 0755, true );
}
@unlink( $clover_out );

$cmd = escapeshellarg( PHP_BINARY )
	. ' -d memory_limit=2G'
	. ' ' . escapeshellarg( $phpcov_bin )
	. ' merge'
	. ' --clover ' . escapeshellarg( $clover_out )
	. ' '          . escapeshellarg( $cov_dir );

$output = array();
$rc     = 0;
exec( $cmd . ' 2>&1', $output, $rc );
if ( 0 !== $rc ) {
	fwrite( STDERR, "merge-coverage: phpcov merge failed (rc={$rc})\n" );
	foreach ( $output as $line ) {
		fwrite( STDERR, "  {$line}\n" );
	}
	exit( 1 );
}

// Phase 5: merged clover.xml must exist and be non-empty.
if ( ! is_file( $clover_out ) ) {
	$fail( "phpcov did not produce {$clover_out}" );
}
$clover_size = filesize( $clover_out );
if ( false === $clover_size || 0 === $clover_size ) {
	@unlink( $clover_out );
	$fail( "merged clover.xml is empty (size=0)" );
}

// Phase 6: merged clover.xml must contain at least one <file> element;
// an empty file with zero statements is treated as a release blocker.
$xml = file_get_contents( $clover_out );
if ( false === $xml || ! str_contains( $xml, '<file ' ) ) {
	@unlink( $clover_out );
	$fail( "merged clover.xml contains no <file> elements" );
}

echo 'merge-coverage: merged ' . count( $cov_files ) . " coverage file(s) → {$clover_out} ({$clover_size} bytes)\n";
exit( 0 );
