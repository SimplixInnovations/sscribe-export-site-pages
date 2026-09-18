<?php
/**
 * Phase 58 — Count acceptance matrix integration test.
 *
 * The acceptance matrix (dist/acceptance-matrix.json) is the
 * auditable list of "every numeric check must hold" for the
 * release. This test pins the matrix contract at the PHPUnit
 * boundary: the matrix JSON exists, declares >= 20 cells, every
 * cell's ci_command is wired to composer.json, every cell's
 * ci_step is wired to ci.yml, and the enforcement chain
 * (verifier + PHPUnit + audit + docs) is intact.
 *
 * A regression that breaks the matrix (drops a check, lowers
 * a floor, removes a ci.yml step) fails this test before it
 * reaches the release gate.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Acceptance_Matrix_Test extends TestCase {

	private const VERIFIER_PATH = 'scripts/verify-acceptance-matrix.php';
	private const MANIFEST_PATH = 'dist/acceptance-matrix-manifest.json';
	private const MATRIX_PATH   = 'docs/ACCEPTANCE_MATRIX_v2.0.0.json';
	private const COMPOSER_PATH = 'composer.json';
	private const CI_PATH       = '.github/workflows/ci.yml';
	private const AUDIT_SCRIPT  = 'scripts/release-audit.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root        = self::plugin_root();
		$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( array( PHP_BINARY, $root . '/' . self::VERIFIER_PATH ), $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not spawn verifier subprocess.' );
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	public function test_live_matrix_passes_verifier(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live dist/acceptance-matrix.json must satisfy the Phase 58 contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'Acceptance matrix contract valid', $output );
	}

	public function test_manifest_records_passing_rules(): void {
		list( $code ) = $this->run_verifier();
		$this::assertSame( 0, $code );

		$payload = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MANIFEST_PATH ), true );
		$this::assertIsArray( $payload );
		$this::assertTrue( $payload['passes'] );
		$this::assertGreaterThanOrEqual( 20, $payload['cell_count'] );
		$this::assertGreaterThanOrEqual( 50, $payload['rule_count'] );
		$this::assertSame( $payload['rule_count'], $payload['passed_count'] );
		$this::assertSame( 0, $payload['errors_count'] );
	}

	public function test_matrix_json_declares_twenty_or_more_cells(): void {
		$matrix = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MATRIX_PATH ), true );
		$this::assertIsArray( $matrix['matrix'] );
		$this::assertGreaterThanOrEqual( 20, count( $matrix['matrix'] ) );
	}

	public function test_every_cell_has_well_formed_shape(): void {
		$matrix = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MATRIX_PATH ), true );
		foreach ( $matrix['matrix'] as $name => $cell ) {
			$this::assertArrayHasKey( 'ci_command', $cell, "Cell `{$name}` missing ci_command." );
			$this::assertArrayHasKey( 'ci_step', $cell, "Cell `{$name}` missing ci_step." );
			$this::assertArrayHasKey( 'operator', $cell, "Cell `{$name}` missing operator." );
			$this::assertContains( $cell['operator'], array( '>=', '<=', '==' ), "Cell `{$name}` has invalid operator." );
			$this::assertTrue(
				isset( $cell['floor'] ) || isset( $cell['ceiling'] ) || isset( $cell['expected'] ),
				"Cell `{$name}` missing floor/ceiling/expected."
			);
		}
	}

	public function test_every_cell_command_is_wired_in_composer(): void {
		$matrix     = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MATRIX_PATH ), true );
		$composer   = (string) file_get_contents( self::plugin_root() . '/' . self::COMPOSER_PATH );

		foreach ( $matrix['matrix'] as $name => $cell ) {
			$cmd          = $cell['ci_command'];
			$first_token  = trim( explode( ' ', $cmd )[0] );
			$this::assertStringContainsString(
				$first_token,
				$composer,
				"Cell `{$name}` command `{$cmd}` (token `{$first_token}`) is missing from composer.json."
			);
		}
	}

	public function test_every_cell_step_is_wired_in_ci_yml(): void {
		$matrix = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MATRIX_PATH ), true );
		$ci     = (string) file_get_contents( self::plugin_root() . '/' . self::CI_PATH );

		foreach ( $matrix['matrix'] as $name => $cell ) {
			$step = $cell['ci_step'];
			$this::assertStringContainsString(
				$step,
				$ci,
				"Cell `{$name}` step `{$step}` is missing from ci.yml."
			);
		}
	}

	public function test_release_audit_invokes_acceptance_matrix(): void {
		$audit = (string) file_get_contents( self::plugin_root() . '/' . self::AUDIT_SCRIPT );
		$this::assertMatchesRegularExpression(
			'/acceptance-matrix|Acceptance-Matrix|verify-acceptance-matrix/i',
			$audit,
			'scripts/release-audit.php must invoke the acceptance matrix verifier.'
		);
	}

	public function test_matrix_declares_pass_criteria_and_enforcement_chain(): void {
		$matrix = json_decode( (string) file_get_contents( self::plugin_root() . '/' . self::MATRIX_PATH ), true );
		$this::assertArrayHasKey( 'pass_criteria', $matrix );
		$this::assertNotEmpty( $matrix['pass_criteria'] );
		$this::assertArrayHasKey( 'enforcement', $matrix );
		$this::assertGreaterThanOrEqual( 2, count( $matrix['enforcement'] ) );
	}
}
