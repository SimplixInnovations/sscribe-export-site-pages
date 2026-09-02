<?php
/**
 * SScribe Security Scanner Integration Test
 *
 * @package SScribe_Export_Site_Pages
 *
 * Phase 32: locks the security scanner contract.
 *
 * The shipped plugin code is the attack surface that runs on every
 * WordPress install that uses SScribe. A single line of `eval(
 * $_POST['code'] )` or `$wpdb->query( "DELETE FROM ... WHERE id = " .
 * $_GET['id'] )` is a remote-code-execution / SQL-injection
 * vulnerability that ships to every site the moment it is published.
 *
 * This integration test runs scripts/verify-security-scan.php against
 * the live repo and against a series of synthetic regressions: an
 * injected eval(), an unserialize() without the allowed_classes
 * whitelist, an extract($_POST), a variable-variable indirection on
 * user input, and a bare wp_ajax_* registration without a nonce +
 * capability check. A regression that:
 *
 *   - silently accepts an eval() in shipped code,
 *   - silently accepts an unserialize() without allowed_classes,
 *   - silently accepts an extract($_POST) / $$_GET indirection,
 *   - silently accepts a bare wp_ajax_* registration,
 *
 * ...fails the suite immediately.
 */

declare( strict_types=1 );

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Security_Scan_Test extends TestCase {

	private const SCRIPT_PATH = 'scripts/verify-security-scan.php';

	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Run the security scanner against a mutation of an admin file.
	 *
	 * @param string $mutation_class_name Class name to inject (must be unique).
	 * @param string $mutation_body       Body of the injected class.
	 * @return array{0:int,1:string}
	 */
	private function run_with_admin_mutation( string $mutation_class_name, string $mutation_body ): array {
		$path   = self::plugin_root() . '/admin/class-sscribe-admin.php';
		$backup = file_get_contents( $path );

		// Inject the mutation at end of file. We append a synthetic class
		// outside the existing class scope; PHP allows multiple classes
		// per file when the file is loaded directly. The security scanner
		// only reads source text, so this works even though `<?php` opens
		// only at the top of the file (we're appending raw PHP class
		// text — the scanner doesn't try to lint).
		$payload = "\nclass {$mutation_class_name} {\n{$mutation_body}\n}\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $backup . $payload );

		try {
			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);
			$process = proc_open(
				array( PHP_BINARY, self::plugin_root() . '/' . self::SCRIPT_PATH ),
				$descriptors,
				$pipes
			);
			$this::assertIsResource( $process );
			$stdout = (string) stream_get_contents( $pipes[1] );
			$stderr = (string) stream_get_contents( $pipes[2] );
			$code   = proc_close( $process );
			return array( (int) $code, $stdout . $stderr );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $path, $backup );
		}
	}

	public function test_live_repo_passes(): void {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open(
			array( PHP_BINARY, self::plugin_root() . '/' . self::SCRIPT_PATH ),
			$descriptors,
			$pipes
		);
		$this::assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		$code   = proc_close( $process );
		$this::assertSame(
			0,
			$code,
			'Live repo must be security-scan clean. Output:' . "\n" . $stdout . $stderr
		);
		$this::assertStringContainsString( 'Security scan clean', $stdout );
	}

	public function test_eval_in_shipped_code_fails(): void {
		list( $code, $output ) = $this->run_with_admin_mutation(
			'SScribe_Regression_Eval',
			"\tpublic function leak(): void { eval( \$_POST['code'] ); }"
		);
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'eval(', $output );
		$this::assertStringContainsString( 'class-sscribe-admin.php', $output );
	}

	public function test_unserialize_without_allowed_classes_fails(): void {
		list( $code, $output ) = $this->run_with_admin_mutation(
			'SScribe_Regression_Unserialize',
			"\tpublic function leak(): void { \$x = unserialize( \$_POST['blob'] ); }"
		);
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'unserialize() without', $output );
		$this::assertStringContainsString( 'class-sscribe-admin.php', $output );
	}

	public function test_unserialize_with_allowed_classes_passes(): void {
		// Sanity: a properly-guarded unserialize does NOT trip the rule.
		list( $code, $output ) = $this->run_with_admin_mutation(
			'SScribe_Regression_UnserializeSafe',
			"\tpublic function leak(): void { \$x = unserialize( \$_POST['blob'], array( 'allowed_classes' => false ) ); }"
		);
		$this::assertSame(
			0,
			$code,
			'unserialize() with allowed_classes whitelist must be allowed. Output:' . "\n" . $output
		);
	}

	public function test_extract_post_fails(): void {
		list( $code, $output ) = $this->run_with_admin_mutation(
			'SScribe_Regression_Extract',
			"\tpublic function leak(): void { extract( \$_POST ); }"
		);
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'extract($', $output );
		$this::assertStringContainsString( 'class-sscribe-admin.php', $output );
	}

	public function test_variable_variable_user_input_fails(): void {
		list( $code, $output ) = $this->run_with_admin_mutation(
			'SScribe_Regression_VarVar',
			"\tpublic function leak(): void { \$x = \$\$_GET; }"
		);
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'variable-variable', $output );
		$this::assertStringContainsString( 'class-sscribe-admin.php', $output );
	}

	public function test_bare_wp_ajax_without_auth_fails(): void {
		list( $code, $output ) = $this->run_with_admin_mutation(
			'SScribe_Regression_BareAjax',
			"\tpublic function register_hooks(): void {\n\t\tadd_action( 'wp_ajax_sscribe_evil_no_auth_test', array( \$this, 'evil_handler' ) );\n\t}\n\tpublic function evil_handler(): void {\n\t\twp_die( 'no auth' );\n\t}"
		);
		$this::assertSame( 1, $code );
		$this::assertStringContainsString( 'evil_handler', $output );
		$this::assertStringContainsString( 'class-sscribe-admin.php', $output );
	}
}
