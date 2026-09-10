<?php
/**
 * Coverage merger wrapper regression test.
 *
 * Exercises scripts/merge-coverage.php to lock down the fail-closed
 * contract that protects the canonical clover.xml from being silently
 * accepted by the gate when:
 *
 *   - the coverage-data directory is missing
 *   - the directory contains no *.cov files
 *   - any *.cov file is corrupt or the wrong class
 *   - any *.cov file references a source path outside the audited tree
 *
 * These tests run with `--validate-only` so they execute phases 1-3
 * of the merger (input validation + source-tree check) without
 * invoking phpcov. That keeps the unit suite driver-independent
 * (Coverage's Driver property is readonly and cannot be initialized
 * from outside the declaring class, so reflection-built fixtures
 * cannot survive a real phpcov merge).
 *
 * The phpcov-dependent merge semantics live in
 * tests/Integration/SScribe_Coverage_Merger_Integration_Test.php
 * which uses real PHPUnit-generated .cov files.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

if ( ! class_exists( CodeCoverage::class, false ) ) {
	require_once dirname( __DIR__, 2 ) . '/vendor/phpunit/php-code-coverage/src/CodeCoverage.php';
}

final class SScribe_Coverage_Merger_Test extends TestCase {

	private string $tmp_dir;
	private string $script;
	private string $clover_out;
	private string $cov_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp_dir    = sys_get_temp_dir() . '/sscribe-merger-' . bin2hex( random_bytes( 6 ) );
		$this->cov_dir    = $this->tmp_dir . '/coverage-data';
		$this->clover_out = $this->tmp_dir . '/clover.xml';
		mkdir( $this->cov_dir, 0755, true );
		$this->script = dirname( __DIR__, 2 ) . '/scripts/merge-coverage.php';
	}

	protected function tearDown(): void {
		$this->rmrf( $this->tmp_dir );
		parent::tearDown();
	}

	private function rmrf( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rmrf( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	private function run_merger( string $cov_dir, string $clover_out, string $allowed_source, bool $validate_only = true ): array {
		$cmd = sprintf(
			'%s %s %s %s %s %s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( $this->script ),
			$validate_only ? '--validate-only ' : '',
			escapeshellarg( $cov_dir ),
			escapeshellarg( $clover_out ),
			escapeshellarg( $allowed_source )
		);
		$output = array();
		$rc     = 0;
		exec( $cmd, $output, $rc );
		return array(
			'rc'     => $rc,
			'output' => implode( "\n", $output ),
		);
	}

	/**
	 * Build a CodeCoverage instance via reflection that contains a
	 * single covered file with one executed line. The Driver / Filter
	 * readonly typed properties stay uninitialized (PHP 8.1+ blocks
	 * reflection-based writes from outside the declaring class), so
	 * the resulting object can survive unserialize + getData() but
	 * cannot survive a real phpcov merge. That is acceptable for the
	 * validator-only contract these tests exercise.
	 */
	private function build_coverage_with_file( string $file_path, int $line ): CodeCoverage {
		$ref = new ReflectionClass( CodeCoverage::class );
		/** @var CodeCoverage $coverage */
		$coverage = $ref->newInstanceWithoutConstructor();

		// getData() short-circuits addUncoveredFilesFromFilter() when
		// includeUncoveredFiles is false, so the uninitialised Filter
		// property is never touched.
		$inc_prop = $ref->getProperty( 'includeUncoveredFiles' );
		$inc_prop->setAccessible( true );
		$inc_prop->setValue( $coverage, false );

		$raw = \SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData::fromXdebugWithoutPathCoverage(
			array(
				$file_path => array(
					$line => 1,
				),
			)
		);
		$data = new ProcessedCodeCoverageData();
		$data->initializeUnseenData( $raw );
		$coverage->setData( $data );
		return $coverage;
	}

	private function write_cov( string $file_name, $coverage ): void {
		// PHPUnit's --coverage-php output is a PHP source file of the
		// form
		//   <?php
		//   return \unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'
		//   ...
		//   END_OF_COVERAGE_SERIALIZATION
		//   );
		// Mirror that shape exactly so the merger's lint + require
		// pipeline accepts the fixture.
		$payload = "<?php\nreturn \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\n"
			. serialize( $coverage )
			. "\nEND_OF_COVERAGE_SERIALIZATION\n);\n";
		file_put_contents( $this->cov_dir . '/' . $file_name, $payload );
	}

	private function write_raw( string $file_name, string $contents ): void {
		file_put_contents( $this->cov_dir . '/' . $file_name, $contents );
	}

	/* ------------------------------------------------------------------ */
	/* Fail-closed: input validation                                      */
	/* ------------------------------------------------------------------ */

	public function test_fails_when_coverage_directory_missing(): void {
		$result = $this->run_merger(
			$this->tmp_dir . '/does-not-exist',
			$this->clover_out,
			$this->tmp_dir
		);
		$this::assertNotSame( 0, $result['rc'], "merger must fail when cov dir is missing; output: {$result['output']}" );
		$this::assertStringContainsString( 'missing', strtolower( $result['output'] ) );
		$this::assertFileDoesNotExist( $this->clover_out );
	}

	public function test_fails_when_directory_has_no_cov_files(): void {
		$result = $this->run_merger(
			$this->cov_dir,
			$this->clover_out,
			$this->tmp_dir
		);
		$this::assertNotSame( 0, $result['rc'], "merger must fail when no .cov files exist; output: {$result['output']}" );
		$this::assertStringContainsString( '.cov', strtolower( $result['output'] ) );
		$this::assertFileDoesNotExist( $this->clover_out );
	}

	public function test_fails_when_cov_file_is_corrupt(): void {
		// Deliberately broken PHP syntax so `php -l` rejects it.
		$this->write_raw( 'core.cov', '<?php this is not valid PHP syntax @@' );
		$result = $this->run_merger(
			$this->cov_dir,
			$this->clover_out,
			$this->tmp_dir
		);
		$this::assertNotSame( 0, $result['rc'], "merger must fail on corrupt .cov; output: {$result['output']}" );
		$this::assertStringContainsString( 'corrupt', strtolower( $result['output'] ) );
		$this::assertFileDoesNotExist( $this->clover_out );
	}

	public function test_fails_when_cov_file_is_wrong_class(): void {
		// Valid PHP file but its `return` is a stdClass, not a Coverage.
		$this->write_raw(
			'core.cov',
			"<?php\nreturn \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\n"
			. serialize( new \stdClass() )
			. "\nEND_OF_COVERAGE_SERIALIZATION\n);\n"
		);
		$result = $this->run_merger(
			$this->cov_dir,
			$this->clover_out,
			$this->tmp_dir
		);
		$this::assertNotSame( 0, $result['rc'], "merger must fail on wrong-class .cov; output: {$result['output']}" );
		$this::assertStringContainsString( 'codecoverage', strtolower( $result['output'] ) );
		$this::assertFileDoesNotExist( $this->clover_out );
	}

	/* ------------------------------------------------------------------ */
	/* Fail-closed: source-tree containment                                */
	/* ------------------------------------------------------------------ */

	public function test_fails_when_cov_references_unrelated_source_tree(): void {
		// Create the out-of-tree file on disk so realpath() succeeds;
		// the merger must still reject it because it falls outside the
		// audited source tree.
		$unrelated_dir = $this->tmp_dir . '/unrelated-project/src';
		mkdir( $unrelated_dir, 0755, true );
		$out_of_tree = $unrelated_dir . '/secret.php';
		file_put_contents( $out_of_tree, "<?php\nclass Secret {}\n" );

		$audited = $this->tmp_dir . '/audited';
		mkdir( $audited, 0755, true );

		$this->write_cov(
			'core.cov',
			$this->build_coverage_with_file( $out_of_tree, 1 )
		);
		$result = $this->run_merger(
			$this->cov_dir,
			$this->clover_out,
			$audited
		);
		$this::assertNotSame( 0, $result['rc'], "merger must reject .cov with out-of-tree paths; output: {$result['output']}" );
		$this::assertStringContainsString( 'source tree', strtolower( $result['output'] ) );
		$this::assertFileDoesNotExist( $this->clover_out );
	}

	public function test_accepts_cov_when_referenced_file_outside_tree_no_longer_exists(): void {
		// Coverage data references a path that was deleted since the
		// test run — common when a file is moved or git-rm'd. realpath()
		// returns false for missing files and the merger must skip
		// rather than reject.
		$deleted_path = $this->tmp_dir . '/deleted/file.php';
		$this->write_cov(
			'core.cov',
			$this->build_coverage_with_file( $deleted_path, 1 )
		);
		$audited = $this->tmp_dir . '/audited';
		mkdir( $audited, 0755, true );
		$result = $this->run_merger(
			$this->cov_dir,
			$this->clover_out,
			$audited
		);
		// --validate-only path: validator must accept because realpath
		// failed and we skip the missing-file check.
		$this::assertSame( 0, $result['rc'], "merger must accept .cov when referenced file is gone; output: {$result['output']}" );
	}

	public function test_normalizes_windows_paths_in_source_tree_check(): void {
		// Create a real file inside the allowed source tree, then
		// serialize a Coverage object whose coveredFiles() references
		// the same file via Windows-style backslashes. The merger must
		// normalize the path before the containment check and accept.
		$allowed  = $this->tmp_dir . '/audited';
		$includes = $allowed . '/includes';
		mkdir( $includes, 0755, true );
		$target   = $includes . '/class.php';
		file_put_contents( $target, "<?php\nclass C {}\n" );

		// Backslashed variant of the same real path.
		$win_path = str_replace( '/', '\\', $target );
		$this->write_cov( 'core.cov', $this->build_coverage_with_file( $win_path, 1 ) );

		$result = $this->run_merger( $this->cov_dir, $this->clover_out, $allowed );
		$this::assertSame( 0, $result['rc'], "merger must accept Windows-style paths after normalization; output: {$result['output']}" );
	}

	/* ------------------------------------------------------------------ */
	/* Happy path: validation passes for in-tree cov files                 */
	/* ------------------------------------------------------------------ */

	public function test_validate_only_passes_for_in_tree_cov_files(): void {
		$allowed  = $this->tmp_dir . '/audited';
		$includes = $allowed . '/includes';
		mkdir( $includes, 0755, true );
		$file_a = $allowed . '/includes/a.php';
		$file_b = $allowed . '/includes/b.php';
		file_put_contents( $file_a, "<?php\nfunction a(){}\n" );
		file_put_contents( $file_b, "<?php\nfunction b(){}\n" );

		$this->write_cov( 'core.cov', $this->build_coverage_with_file( $file_a, 1 ) );
		$this->write_cov( 'wp.cov',   $this->build_coverage_with_file( $file_b, 1 ) );

		$result = $this->run_merger( $this->cov_dir, $this->clover_out, $allowed );
		$this::assertSame( 0, $result['rc'], "validate-only must succeed with valid in-tree .cov files; output: {$result['output']}" );
		$this::assertStringContainsString( 'validated', strtolower( $result['output'] ) );
		// Validate-only must NOT produce a clover.xml (that requires phpcov).
		$this::assertFileDoesNotExist( $this->clover_out );
	}
}
