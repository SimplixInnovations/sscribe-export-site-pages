<?php
/**
 * Phase 41 — Coverage-threshold gate integration test.
 *
 * The coverage contract is the Phase 41 deliverable:
 *
 *   - Project-wide line coverage must be ≥ 70%.
 *   - Each of the four security-critical modules must be ≥ 90%:
 *       * includes/class-sscribe-private-storage.php
 *       * includes/class-sscribe-filesystem.php
 *       * includes/class-sscribe-security.php
 *       * includes/class-sscribe-audit-trail.php
 *
 * The verifier (`scripts/verify-coverage-thresholds.php`) reads
 * `clover.xml` and enforces both contracts. This test class
 * exercises the verifier against fixtures so we lock the contract
 * down without depending on whether a coverage driver is installed
 * on the local machine.
 *
 * Each test:
 *
 *   1. Snapshots the live `clover.xml` if present.
 *   2. Plants a fixture `clover.xml` under a temporary path.
 *   3. Invokes the verifier via proc_open with the fixture path.
 *   4. Asserts on the exit code and persisted manifest.
 *   5. Restores the snapshot in `finally`.
 *
 * The fixture paths are namespaced under sys_get_temp_dir() so
 * concurrent CI runs cannot collide.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Coverage_Thresholds_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-coverage-thresholds.php';

	/**
	 * Critical modules that must clear the 90% per-file threshold.
	 * Mirrors the array in scripts/verify-coverage-thresholds.php —
	 * duplicated here on purpose so a regression in the verifier that
	 * drops a critical file fails this test.
	 */
	private const CRITICAL_FILES = array(
		'includes/class-sscribe-private-storage.php',
		'includes/class-sscribe-filesystem.php',
		'includes/class-sscribe-security.php',
		'includes/class-sscribe-audit-trail.php',
	);

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * @param array<string,int> $file_statements Map of relative file path → total statements.
	 * @param array<string,int> $file_covered    Map of relative file path → covered statements.
	 * @param array<string,int> $extra_statements Optional extra statements that should not
	 *                                            count toward the project ratio.
	 */
	private static function build_clover_xml(
		array $file_statements,
		array $file_covered,
		array $extra_statements = array()
	): string {
		$total_statements = array_sum( $file_statements ) + array_sum( $extra_statements );
		$total_covered    = array_sum( $file_covered ) + array_sum( $extra_statements );

		$files_xml = '';
		foreach ( $file_statements as $relative => $statements ) {
			$covered = $file_covered[ $relative ] ?? 0;
			$files_xml .= sprintf(
				'    <file name="%s">%s<metrics statements="%d" coveredstatements="%d"/></file>%s',
				$relative,
				'',
				$statements,
				$covered,
				"\n"
			);
		}

		return sprintf(
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			. "<coverage generated=\"%d\" clover=\"3.2.0\">\n"
			. "  <project name=\"SScribe\">\n"
			. "%s"
			. "    <metrics files=\"%d\" statements=\"%d\" coveredstatements=\"%d\" />\n"
			. "  </project>\n"
			. "</coverage>\n",
			time(),
			$files_xml,
			count( $file_statements ),
			$total_statements,
			$total_covered
		);
	}

	/**
	 * Invoke the verifier against a fixture path. Returns
	 * [exit_code, combined_stdout_stderr, manifest_path].
	 *
	 * @return array{0:int,1:string,2:?string}
	 */
	private function run_verifier( string $clover_fixture, ?string $manifest_fixture = null ): array {
		$root = self::plugin_root();
		$args = array( PHP_BINARY, $root . '/' . self::SCRIPT_PATH, $clover_fixture );
		if ( null !== $manifest_fixture ) {
			$args[] = $manifest_fixture;
		}
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( $args, $descriptors, $pipes );
		$this::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr, $manifest_fixture );
	}

	private static function write_fixture( string $path, string $xml ): void {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $xml );
	}

	private static function snapshot_live_clover(): array {
		$live_path = self::plugin_root() . '/clover.xml';
		$backup    = $live_path . '.phase41-snapshot';
		$existed   = is_file( $live_path );
		if ( $existed ) {
			copy( $live_path, $backup );
		}
		return array( $live_path, $backup, $existed );
	}

	private static function restore_live_clover( array $snapshot ): void {
		list( $live_path, $backup, $existed ) = $snapshot;
		if ( $existed && is_file( $backup ) ) {
			copy( $backup, $live_path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			unlink( $backup );
		} else {
			if ( is_file( $live_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
				unlink( $live_path );
			}
			if ( is_file( $backup ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
				unlink( $backup );
			}
		}
	}

	private static function fixture_path( string $label ): string {
		return sys_get_temp_dir() . '/sscribe-phase41-' . $label . '-' . uniqid() . '.xml';
	}

	private static function manifest_path( string $label ): string {
		return sys_get_temp_dir() . '/sscribe-phase41-' . $label . '-' . uniqid() . '.json';
	}

	// -----------------------------------------------------------------
	// phpunit.xml wiring
	// -----------------------------------------------------------------

	public function test_phpunit_coverage_xml_has_coverage_configuration(): void {
		$root   = self::plugin_root();
		$config = (string) file_get_contents( $root . '/phpunit-coverage.xml' );
		$this::assertStringContainsString( '<coverage', $config );
		$this::assertStringContainsString( '<clover', $config );
		$this::assertStringContainsString( 'outputFile="clover.xml"', $config );
		// requireCoverageMetadata=false prevents coverage runs from
		// marking every test risky when @covers annotations are absent.
		$this::assertStringContainsString( 'requireCoverageMetadata="false"', $config );
	}

	public function test_composer_json_runs_verifier(): void {
		$root   = self::plugin_root();
		$config = (string) file_get_contents( $root . '/composer.json' );
		$this::assertStringContainsString( 'test:coverage:check', $config );
		$this::assertStringContainsString( 'verify-coverage-thresholds.php', $config );
		// test:coverage must select the dedicated coverage config, which writes
		// both clover.xml and the HTML report without slowing normal test runs.
		$this::assertStringContainsString( 'phpunit -c phpunit-coverage.xml', $config );
	}

	// -----------------------------------------------------------------
	// Verifier contract: well-formed fixtures
	// -----------------------------------------------------------------

	public function test_well_formed_clover_passes(): void {
		$statements = array();
		$covered    = array();
		// All four critical files at 100%.
		foreach ( self::CRITICAL_FILES as $critical ) {
			$statements[ $critical ] = 100;
			$covered[ $critical ]    = 100;
		}
		// Many other source files at varying coverage so the project-wide
		// ratio lands clearly above the 70% threshold.
		$statements['includes/class-sscribe-exporter.php']         = 500;
		$covered['includes/class-sscribe-exporter.php']            = 480;
		$statements['includes/class-sscribe-export-format.php']     = 120;
		$covered['includes/class-sscribe-export-format.php']        = 110;
		$statements['includes/class-sscribe-batch-processor.php']   = 300;
		$covered['includes/class-sscribe-batch-processor.php']      = 290;

		$clover    = self::fixture_path( 'pass' );
		$manifest  = self::manifest_path( 'pass' );
		self::write_fixture( $clover, self::build_clover_xml( $statements, $covered ) );

		$snapshot = self::snapshot_live_clover();
		try {
			list( $code, $output ) = $this->run_verifier( $clover, $manifest );
			$this::assertSame(
				0,
				$code,
				'Well-formed fixture must pass the gate. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'Coverage threshold gate passed', $output );

			$payload = json_decode( (string) file_get_contents( $manifest ), true );
			$this::assertIsArray( $payload );
			$this::assertTrue( $payload['passes'] );
			$this::assertEquals( 70.0, $payload['project_threshold'] );
			$this::assertGreaterThanOrEqual( 70.0, $payload['project']['coverage_percent'] );
			foreach ( self::CRITICAL_FILES as $critical ) {
				$this::assertArrayHasKey( $critical, $payload['critical_files'] );
				$this::assertTrue(
					$payload['critical_files'][ $critical ]['passes'],
					'Critical file must pass in manifest: ' . $critical
				);
			}
		} finally {
			self::restore_live_clover( $snapshot );
			@unlink( $clover );
			@unlink( $manifest );
		}
	}

	// -----------------------------------------------------------------
	// Verifier contract: rejection paths
	// -----------------------------------------------------------------

	public function test_low_project_coverage_fails(): void {
		$statements = array();
		$covered    = array();
		// Critical files at 100% so the per-file gate passes and only
		// the project-wide gate fails.
		foreach ( self::CRITICAL_FILES as $critical ) {
			$statements[ $critical ] = 50;
			$covered[ $critical ]    = 50;
		}
		// Many other files at 0% to drag the project ratio below 70%.
		$statements['includes/class-sscribe-exporter.php']       = 1000;
		$covered['includes/class-sscribe-exporter.php']          = 50;
		$statements['includes/class-sscribe-batch-processor.php'] = 1000;
		$covered['includes/class-sscribe-batch-processor.php']    = 50;

		$clover   = self::fixture_path( 'lowproject' );
		$manifest = self::manifest_path( 'lowproject' );
		self::write_fixture( $clover, self::build_clover_xml( $statements, $covered ) );

		$snapshot = self::snapshot_live_clover();
		try {
			list( $code, $output ) = $this->run_verifier( $clover, $manifest );
			$this::assertSame(
				1,
				$code,
				'Low project-wide coverage must fail the gate. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'Project-wide coverage', $output );

			$payload = json_decode( (string) file_get_contents( $manifest ), true );
			$this::assertIsArray( $payload );
			$this::assertFalse( $payload['passes'] );
			$this::assertNotEmpty( $payload['errors'] );
		} finally {
			self::restore_live_clover( $snapshot );
			@unlink( $clover );
			@unlink( $manifest );
		}
	}

	public function test_critical_module_below_threshold_fails(): void {
		$statements = array();
		$covered    = array();
		// Three critical files at 100%; the security file at 50% so
		// the per-file gate trips.
		foreach ( self::CRITICAL_FILES as $critical ) {
			$statements[ $critical ] = 100;
			$covered[ $critical ]    = 100;
		}
		$covered['includes/class-sscribe-security.php'] = 50;
		// Pad project coverage so the project gate is satisfied and
		// only the critical-module gate fires.
		$statements['includes/class-sscribe-exporter.php'] = 1000;
		$covered['includes/class-sscribe-exporter.php']    = 900;

		$clover   = self::fixture_path( 'critical-low' );
		$manifest = self::manifest_path( 'critical-low' );
		self::write_fixture( $clover, self::build_clover_xml( $statements, $covered ) );

		$snapshot = self::snapshot_live_clover();
		try {
			list( $code, $output ) = $this->run_verifier( $clover, $manifest );
			$this::assertSame(
				1,
				$code,
				'Critical module below 90% must fail the gate. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'class-sscribe-security.php', $output );

			$payload = json_decode( (string) file_get_contents( $manifest ), true );
			$this::assertIsArray( $payload );
			$this::assertFalse( $payload['critical_files']['includes/class-sscribe-security.php']['passes'] );
		} finally {
			self::restore_live_clover( $snapshot );
			@unlink( $clover );
			@unlink( $manifest );
		}
	}

	public function test_missing_critical_file_fails(): void {
		$statements = array();
		$covered    = array();
		// Deliberately omit one of the critical files from the
		// report; the verifier must treat that as a release blocker
		// because the gate cannot prove the contract.
		foreach ( self::CRITICAL_FILES as $critical ) {
			if ( 'includes/class-sscribe-private-storage.php' === $critical ) {
				continue;
			}
			$statements[ $critical ] = 100;
			$covered[ $critical ]    = 100;
		}
		// Pad coverage so the project gate is otherwise fine.
		$statements['includes/class-sscribe-exporter.php'] = 1000;
		$covered['includes/class-sscribe-exporter.php']    = 900;

		$clover   = self::fixture_path( 'missing-critical' );
		$manifest = self::manifest_path( 'missing-critical' );
		self::write_fixture( $clover, self::build_clover_xml( $statements, $covered ) );

		$snapshot = self::snapshot_live_clover();
		try {
			list( $code, $output ) = $this->run_verifier( $clover, $manifest );
			$this::assertSame(
				1,
				$code,
				'Missing critical file must fail the gate. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'class-sscribe-private-storage.php', $output );
			$this::assertStringContainsString( 'missing from', $output );
		} finally {
			self::restore_live_clover( $snapshot );
			@unlink( $clover );
			@unlink( $manifest );
		}
	}

	public function test_missing_clover_xml_fails(): void {
		$missing    = sys_get_temp_dir() . '/sscribe-phase41-not-here-' . uniqid() . '.xml';
		$snapshot   = self::snapshot_live_clover();
		// Also delete the live clover.xml so the verifier can never
		// accidentally find a real one during the test.
		$live_path  = $snapshot[0];
		$live_existed_before = $snapshot[2];
		if ( $live_existed_before && is_file( $live_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			unlink( $live_path );
		}
		try {
			list( $code, $output ) = $this->run_verifier( $missing );
			$this::assertSame(
				2,
				$code,
				'Missing clover.xml must exit with code 2 (release blocker). Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'clover.xml missing', $output );
		} finally {
			self::restore_live_clover( $snapshot );
		}
	}

	/**
	 * Build a Clover fixture that records file paths as absolute
	 * Windows paths. The verifier must strip the repo-root prefix
	 * and resolve every critical file regardless of slash direction.
	 *
	 * Mirrors the real `clover.xml` produced by PHPUnit on Windows
	 * (the project's primary local environment).
	 *
	 * @param array<string,int> $file_statements Map of file path → total statements.
	 * @param array<string,int> $file_covered    Map of file path → covered statements.
	 * @param array<string,int> $extra_statements Optional extra statements that should not
	 *                                            count toward the project ratio.
	 */
	private static function build_clover_xml_with_paths(
		array $file_statements,
		array $file_covered,
		array $extra_statements = array()
	): string {
		$total_statements = array_sum( $file_statements ) + array_sum( $extra_statements );
		$total_covered    = array_sum( $file_covered ) + array_sum( $extra_statements );

		$files_xml = '';
		foreach ( $file_statements as $path => $statements ) {
			$covered = $file_covered[ $path ] ?? 0;
			$files_xml .= sprintf(
				'    <file name="%s">%s<metrics statements="%d" coveredstatements="%d"/></file>%s',
				$path,
				'',
				$statements,
				$covered,
				"\n"
			);
		}

		return sprintf(
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			. "<coverage generated=\"%d\" clover=\"3.2.0\">\n"
			. "  <project name=\"SScribe\">\n"
			. "%s"
			. "    <metrics files=\"%d\" statements=\"%d\" coveredstatements=\"%d\" />\n"
			. "  </project>\n"
			. "</coverage>\n",
			time(),
			$files_xml,
			count( $file_statements ),
			$total_statements,
			$total_covered
		);
	}

	/**
	 * Build a Clover fixture whose `<file>` names use an absolute
	 * path rooted at the project root, normalised to forward slashes
	 * (the Windows shape PHPUnit actually emits on this project).
	 */
	public function test_absolute_windows_forward_slash_paths_resolve_critical_files(): void {
		$root = self::plugin_root();
		// Forward-slash absolute paths — what PHPUnit on Windows actually emits.
		$statements = array();
		$covered    = array();
		foreach ( self::CRITICAL_FILES as $critical ) {
			$statements[ $root . '/' . $critical ] = 100;
			$covered[ $root . '/' . $critical ]    = 100;
		}
		// Padding so the project gate would otherwise pass.
		$statements[ $root . '/includes/class-sscribe-exporter.php' ] = 500;
		$covered[ $root . '/includes/class-sscribe-exporter.php' ]    = 500;

		$clover   = self::fixture_path( 'abs-win-fwd' );
		$manifest = self::manifest_path( 'abs-win-fwd' );
		self::write_fixture( $clover, self::build_clover_xml_with_paths( $statements, $covered ) );

		$snapshot = self::snapshot_live_clover();
		try {
			list( $code, $output ) = $this->run_verifier( $clover, $manifest );
			$this::assertSame(
				0,
				$code,
				'Forward-slash Windows absolute paths must resolve. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'Coverage threshold gate passed', $output );
			$payload = json_decode( (string) file_get_contents( $manifest ), true );
			$this::assertIsArray( $payload );
			$this::assertTrue( $payload['passes'] );
			foreach ( self::CRITICAL_FILES as $critical ) {
				$this::assertTrue(
					$payload['critical_files'][ $critical ]['passes'],
					'Critical file must pass under absolute-path fixture: ' . $critical
				);
			}
		} finally {
			self::restore_live_clover( $snapshot );
			@unlink( $clover );
			@unlink( $manifest );
		}
	}

	/**
	 * Build a Clover fixture whose `<file>` names use backslash
	 * absolute paths — the second Windows shape PHPUnit emits.
	 */
	public function test_absolute_windows_backslash_paths_resolve_critical_files(): void {
		$root = self::plugin_root();
		// Backslash absolute paths.
		$statements = array();
		$covered    = array();
		foreach ( self::CRITICAL_FILES as $critical ) {
			$win_path   = str_replace( '/', '\\', $root . '/' . $critical );
			$statements[ $win_path ] = 100;
			$covered[ $win_path ]    = 100;
		}
		$win_pad = str_replace( '/', '\\', $root . '/includes/class-sscribe-exporter.php' );
		$statements[ $win_pad ] = 500;
		$covered[ $win_pad ]    = 500;

		$clover   = self::fixture_path( 'abs-win-back' );
		$manifest = self::manifest_path( 'abs-win-back' );
		self::write_fixture( $clover, self::build_clover_xml_with_paths( $statements, $covered ) );

		$snapshot = self::snapshot_live_clover();
		try {
			list( $code, $output ) = $this->run_verifier( $clover, $manifest );
			$this::assertSame(
				0,
				$code,
				'Backslash Windows absolute paths must resolve. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'Coverage threshold gate passed', $output );
			$payload = json_decode( (string) file_get_contents( $manifest ), true );
			$this::assertTrue( $payload['passes'] );
			foreach ( self::CRITICAL_FILES as $critical ) {
				$this::assertTrue(
					$payload['critical_files'][ $critical ]['passes'],
					'Critical file must pass under backslash absolute-path fixture: ' . $critical
				);
			}
		} finally {
			self::restore_live_clover( $snapshot );
			@unlink( $clover );
			@unlink( $manifest );
		}
	}

	/**
	 * Build a Clover fixture whose `<file>` names use a Linux absolute
	 * path. Critical files must still resolve under `/repo/...`.
	 */
	public function test_absolute_linux_paths_resolve_critical_files(): void {
		$root = self::plugin_root();
		$statements = array();
		$covered    = array();
		foreach ( self::CRITICAL_FILES as $critical ) {
			$statements[ $root . '/' . $critical ] = 100;
			$covered[ $root . '/' . $critical ]    = 100;
		}
		$statements[ $root . '/includes/class-sscribe-exporter.php' ] = 500;
		$covered[ $root . '/includes/class-sscribe-exporter.php' ]    = 500;

		$clover   = self::fixture_path( 'abs-linux' );
		$manifest = self::manifest_path( 'abs-linux' );
		self::write_fixture( $clover, self::build_clover_xml_with_paths( $statements, $covered ) );

		$snapshot = self::snapshot_live_clover();
		try {
			list( $code, $output ) = $this->run_verifier( $clover, $manifest );
			$this::assertSame(
				0,
				$code,
				'Linux absolute paths must resolve. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'Coverage threshold gate passed', $output );
		} finally {
			self::restore_live_clover( $snapshot );
			@unlink( $clover );
			@unlink( $manifest );
		}
	}

	/**
	 * A Clover fixture whose critical-file path lies outside the
	 * repository must NOT silently normalise to a matching key. The
	 * gate must report the file as missing and fail.
	 */
	public function test_absolute_path_outside_repository_is_refused(): void {
		// Pick a clearly-fake path that has no chance of matching the
		// real repo root.
		$foreign_root = '/var/some-other-project-that-is-not-sscribe';
		$statements   = array();
		$covered      = array();
		foreach ( self::CRITICAL_FILES as $critical ) {
			$statements[ $foreign_root . '/' . $critical ] = 100;
			$covered[ $foreign_root . '/' . $critical ]    = 100;
		}
		// Pad project coverage using an in-repo path so the only
		// failure source is the rejected foreign-root critical files.
		$root                                          = self::plugin_root();
		$statements[ $root . '/includes/exporter.php' ] = 500;
		$covered[ $root . '/includes/exporter.php' ]    = 500;

		$clover   = self::fixture_path( 'abs-foreign' );
		$manifest = self::manifest_path( 'abs-foreign' );
		self::write_fixture( $clover, self::build_clover_xml_with_paths( $statements, $covered ) );

		$snapshot = self::snapshot_live_clover();
		try {
			list( $code, $output ) = $this->run_verifier( $clover, $manifest );
			$this::assertSame(
				1,
				$code,
				'A foreign-root path must NOT silently match a critical file. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'missing from', $output );
		} finally {
			self::restore_live_clover( $snapshot );
			@unlink( $clover );
			@unlink( $manifest );
		}
	}

	public function test_phpunit_source_includes_critical_files(): void {
		// Defense in depth: even if a future refactor removes a file
		// from the critical list, the phpunit.xml `<source>` block
		// must still be configured to scan the directory that
		// contains them. Otherwise the gate would silently accept
		// missing coverage on those modules.
		$root   = self::plugin_root();
		$config = (string) file_get_contents( $root . '/phpunit.xml' );
		$this::assertStringContainsString( '<directory suffix=".php">includes', $config );
		// Activator/deactivator are excluded as bootstrap code, not
		// because they are part of the security contract.
		$this::assertStringContainsString( 'class-sscribe-activator.php', $config );
		$this::assertStringContainsString( 'class-sscribe-deactivator.php', $config );
	}
}
