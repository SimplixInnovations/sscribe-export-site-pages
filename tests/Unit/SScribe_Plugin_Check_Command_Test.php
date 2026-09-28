<?php
/** Exercise the actual strict verifier process with isolated checkout and evidence. */
declare( strict_types=1 );
namespace SScribe\Tests\Unit;
use PHPUnit\Framework\TestCase;
final class SScribe_Plugin_Check_Command_Test extends TestCase {
	/** Remove only this test's owned tree, closing directory handles before deletion. */
	private static function remove_fixture_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			// Git objects are read-only on Windows; unlink alone cannot remove them.
			if ( ! is_link( $path ) && ! chmod( $path, 0600 ) ) {
				throw new \RuntimeException( 'Cannot make fixture writable: ' . $path );
			}
			if ( ! unlink( $path ) ) {
				throw new \RuntimeException( 'Cannot remove fixture file: ' . $path );
			}
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		if ( false === $entries ) {
			throw new \RuntimeException( 'Cannot enumerate fixture: ' . $path );
		}
		foreach ( $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::remove_fixture_tree( $path . '/' . $entry );
			}
		}
		if ( ! rmdir( $path ) ) {
			throw new \RuntimeException( 'Cannot remove fixture directory: ' . $path );
		}
	}

	public function test_fixture_cleanup_removes_read_only_git_objects(): void {
		$root = sys_get_temp_dir() . '/sscribe-pc-cleanup-' . bin2hex( random_bytes( 6 ) );
		mkdir( $root . '/.git/objects/ab', 0700, true );
		$object = $root . '/.git/objects/ab/object';
		file_put_contents( $object, 'read-only Git fixture' );
		chmod( $object, 0444 );
		self::remove_fixture_tree( $root );
		self::assertDirectoryDoesNotExist( $root );
	}

	public function test_strict_verifier_requires_complete_current_evidence(): void {
		$root = sys_get_temp_dir() . '/sscribe-pc-command-' . bin2hex( random_bytes( 6 ) );
		mkdir( $root, 0700 );
		$run = static function ( array $args, array $env = array() ) use ( $root ): int {
			$log = $root . '/command-output.log';
			$proc = proc_open( $args, array( 1 => array( 'file', $log, 'w' ), 2 => array( 'file', $log, 'a' ) ), $pipes, $root, array_merge( getenv(), $env ) );
			return is_resource( $proc ) ? proc_close( $proc ) : 127;
		};
		try {
			foreach ( array( 'scripts/verify-plugin-check-triage.php', 'scripts/lib/plugin-check-report.php', 'docs/PLUGIN_CHECK_WARNINGS_v2.0.0.md', 'tests/Integration/SScribe_Plugin_Check_Triage_Test.php' ) as $file ) {
				if ( ! is_dir( dirname( $root . '/' . $file ) ) ) { mkdir( dirname( $root . '/' . $file ), 0755, true ); }
				copy( dirname( __DIR__, 2 ) . '/' . $file, $root . '/' . $file );
			}
			self::assertSame( 0, $run( array( 'git', 'init', '-q' ) ) );
			self::assertSame( 0, $run( array( 'git', 'add', '.' ) ) );
			self::assertSame( 0, $run( array( 'git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'Fixture' ) ) );
			// command-output.log was staged; stop tracking it before checks.
			$run( array( 'git', 'rm', '--cached', 'command-output.log' ) );
			$run( array( 'git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'Ignore command output' ) );
			$run( array( 'git', 'rev-parse', 'HEAD' ) );
			$sha = trim( (string) file_get_contents( $root . '/command-output.log' ) );
			$report = $root . '/report.log'; $zip = $root . '/release.zip'; $sidecar = $root . '/evidence.json';
			file_put_contents( $report, 'Success: Checks complete. No errors found.' );
			file_put_contents( $zip, 'artifact fixture bytes' );
			$identity = array( 'source_sha' => $sha, 'zip_sha256' => hash_file( 'sha256', $zip ), 'report_sha256' => hash_file( 'sha256', $report ), 'exit_code' => 0 );
			$env = array( 'SSCRIBE_RELEASE_CERTIFICATION' => '1', 'SSCRIBE_SOURCE_SHA' => $sha, 'SSCRIBE_RELEASE_ZIP' => $zip, 'SSCRIBE_PLUGIN_CHECK_REPORT' => $report, 'SSCRIBE_PLUGIN_CHECK_EVIDENCE' => $sidecar );
			$verify = static fn( array $overrides = array() ): int => $run( array( PHP_BINARY, $root . '/scripts/verify-plugin-check-triage.php' ), array_merge( $env, $overrides ) );
			file_put_contents( $sidecar, json_encode( $identity ) );
			self::assertSame( 0, $verify(), (string) file_get_contents( $root . '/command-output.log' ) );
			foreach ( array( 'source_sha' => str_repeat( 'a', 40 ), 'zip_sha256' => str_repeat( 'b', 64 ), 'report_sha256' => str_repeat( 'c', 64 ), 'exit_code' => 1 ) as $key => $bad ) {
				file_put_contents( $sidecar, json_encode( array_replace( $identity, array( $key => $bad ) ) ) );
				self::assertSame( 1, $verify(), $key );
			}
			file_put_contents( $sidecar, json_encode( $identity ) );
			self::assertSame( 1, $verify( array( 'SSCRIBE_SOURCE_SHA' => str_repeat( 'a', 40 ) ) ) );
			unlink( $sidecar ); self::assertSame( 1, $verify() );
			$warning = array( 'line' => 1, 'column' => 0, 'type' => 'WARNING', 'code' => 'WordPress.DB.DirectDatabaseQuery.DirectQuery', 'message' => 'Fixture', 'docs' => '' );
			foreach ( array( array( 'includes/class-sscribe-audit-trail.php', 'WARNING', 0 ), array( 'includes/wrong.php', 'WARNING', 1 ), array( 'includes/class-sscribe-audit-trail.php', 'ERROR', 1 ) ) as $case ) {
				$warning['type'] = $case[1];
				file_put_contents( $report, 'FILE: ' . $case[0] . "\n" . json_encode( array( $warning ) ) );
				file_put_contents( $sidecar, json_encode( array_replace( $identity, array( 'report_sha256' => hash_file( 'sha256', $report ) ) ) ) );
				self::assertSame( $case[2], $verify(), $case[0] . ':' . $case[1] );
			}

			foreach ( array( '{}', '{"success":false}', 'FILE: missing.php', '[{"type":"ERROR","code":"bad"}]', '' ) as $raw ) {
				file_put_contents( $report, $raw );
				file_put_contents( $sidecar, json_encode( array_replace( $identity, array( 'report_sha256' => hash_file( 'sha256', $report ) ) ) ) );
				self::assertSame( 1, $verify(), $raw );
			}
		} finally {
			self::remove_fixture_tree( $root );
		}
	}
}
