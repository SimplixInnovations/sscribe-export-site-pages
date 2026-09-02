<?php
/**
 * Phase 38 — mkdir() containment integration test.
 *
 * The verifier (scripts/verify-mkdir-containment.php) audits every
 * wp_mkdir_p() / SScribe_Filesystem->mkdir() callsite across the
 * shipped source tree (includes/, admin/, public/) and confirms each
 * one is either:
 *   - inside SScribe_Private_Storage (private-storage boundary);
 *   - routed through the public SScribe_Filesystem surface (which
 *     carries its own containment check); or
 *   - explicitly exempted as a transient working-file tempdir.
 *
 * The runtime guard is the SScribe_Filesystem class itself:
 *   - mkdir() refuses paths that resolve outside the export root
 *     via is_path_safe_for_write();
 *   - mkdir_under_private_root() validates the relative path and
 *     re-checks containment after creation.
 *
 * This PHPUnit class backs both contracts with regression tests:
 *   - The verifier audit must pass against the live source tree.
 *   - The SScribe_Filesystem runtime guard must refuse every
 *     containment-violation payload listed in the spec.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Mkdir_Containment_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-mkdir-containment.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function run_verifier(): array {
		$root = self::plugin_root();
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open(
			array( PHP_BINARY, $root . '/' . self::SCRIPT_PATH ),
			$descriptors,
			$pipes
		);
		$this::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		return array( (int) $code, $stdout . $stderr );
	}

	public function test_well_formed_tree_passes(): void {
		list( $code, $output ) = $this->run_verifier();
		$this::assertSame(
			0,
			$code,
			'Live source tree must satisfy the mkdir() containment contract. Output:' . "\n" . $output
		);
		$this::assertStringContainsString( 'containment audit holds', $output );
	}

	public function test_uncategorised_wp_mkdir_p_fails(): void {
		// Create a temporary PHP file under admin/ that contains a
		// wp_mkdir_p() call with no private-storage derivation. The
		// verifier must flag it as a release blocker.
		$root       = self::plugin_root();
		$marker_dir = $root . '/admin/__phase38_containment_marker';
		$marker     = $marker_dir . '/class-sscribe-marker.php';

		$original_tree_state = is_dir( $marker_dir );
		mkdir( $marker_dir, 0755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$marker,
			"<?php\n// Phase 38 marker file. Should be removed before commit.\n"
			. "if ( ! is_dir( \$some_path ) ) { wp_mkdir_p( \$some_path ); }\n"
		);

		try {
			list( $code, $output ) = $this->run_verifier();
			$this::assertSame(
				1,
				$code,
				'Uncategorised wp_mkdir_p must fail the audit. Output:' . "\n" . $output
			);
			$this::assertStringContainsString( 'unverified path', $output );
			$this::assertStringContainsString( '__phase38_containment_marker', $output );
		} finally {
			@unlink( $marker );
			@rmdir( $marker_dir );
			// Defensive: in case Windows file lock blocked the unlink,
			// hide the file inside an .sscribe-internal marker so the
			// verifier's pattern doesn't keep re-tripping.
			$this::assertDirectoryDoesNotExist( $marker_dir );
			$this::assertFalse( $original_tree_state && is_dir( $marker_dir ), 'Defensive cleanup failed' );
		}
	}

	public function test_runtime_mkdir_refuses_uncontained_path(): void {
		$fs   = new \SScribe_Filesystem();
		// sys_get_temp_dir() is outside the export root; the public
		// mkdir() must refuse it even though PHP itself would happily
		// create the directory.
		$escape = sys_get_temp_dir() . '/phase38-runtime-' . uniqid();
		$this::assertFalse( $fs->mkdir( $escape ) );
		$this::assertDirectoryDoesNotExist( $escape );
	}

	public function test_runtime_mkdir_under_private_root_happy_path(): void {
		$fs     = new \SScribe_Filesystem();
		$result = $fs->mkdir_under_private_root( 'phase38-integration/' . uniqid() );
		$this::assertNotSame( '', $result );
		$this::assertDirectoryExists( $result );
		// And the resulting path must be inside the export root.
		$export_root = \SScribe_Private_Storage::get_export_dir();
		$this::assertStringStartsWith( $export_root, $result );
	}

	public function test_runtime_mkdir_under_private_root_rejects_all_spec_payloads(): void {
		$fs = new \SScribe_Filesystem();
		// The Phase 38 spec enumerates seven rejection scenarios. Each
		// one returns an empty string from mkdir_under_private_root.
		// (`./` segments are deliberately collapsed to a single segment
		// rather than rejected — `good/./.` normalizes to `good`,
		// which is a legitimate relative path; the unit test in
		// SScribe_Filesystem_Test confirms that normalization path.)
		$bad_payloads = array(
			'empty'             => '',
			'slash-only'        => '/',
			'unix-absolute'     => '/tmp/phase38-bad',
			'windows-drive'     => 'C:\\Windows\\Temp\\phase38-bad',
			'traversal-dotdot'  => '../phase38-bad',
			'traversal-midpath' => 'good/../../phase38-bad',
			'nul-byte'          => "good\x00bad",
		);
		foreach ( $bad_payloads as $label => $payload ) {
			$this::assertSame(
				'',
				$fs->mkdir_under_private_root( $payload ),
				"Payload '{$label}' ({$payload}) must be refused"
			);
		}
	}
}