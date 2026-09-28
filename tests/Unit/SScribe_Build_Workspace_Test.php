<?php
/** Build tooling must preserve source checkouts and certification evidence. */
declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/lib/build-workspace.php';

final class SScribe_Build_Workspace_Test extends TestCase {
	private string $fixture;
	private ?string $workspace = null;

	protected function setUp(): void {
		$this->fixture = sys_get_temp_dir() . '/sscribe-build-fixture-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->fixture, 0700 );
	}

	protected function tearDown(): void {
		if ( null !== $this->workspace ) {
			sscribe_remove_build_path( $this->workspace );
		}
		sscribe_remove_build_path( $this->fixture );
	}

	public function test_release_cleanup_preserves_evidence_and_prior_packages(): void {
		$dist = $this->fixture . '/dist';
		$preserved = array( 'evidence/raw.log', 'evidence-bundle-current/plugin.zip', 'evidence-bundle-previous-fb6654db/plugin.zip', 'sscribe-export-site-pages-2.0.3.zip' );
		$removed = array( 'sscribe-export-site-pages/stale.php', 'sscribe-export-site-pages-2.0.4.zip', 'sscribe-export-site-pages-2.0.4.sha256' );
		foreach ( array_merge( $preserved, $removed ) as $name ) {
			if ( ! is_dir( dirname( $dist . '/' . $name ) ) ) {
				mkdir( dirname( $dist . '/' . $name ), 0700, true );
			}
			file_put_contents( $dist . '/' . $name, 'preserve these bytes' );
		}
		chmod( $dist . '/sscribe-export-site-pages/stale.php', 0444 );
		sscribe_clean_release_output( $dist, '2.0.4' );
		foreach ( $preserved as $name ) {
			self::assertSame( 'preserve these bytes', file_get_contents( $dist . '/' . $name ) );
		}
		foreach ( $removed as $name ) {
			self::assertFileDoesNotExist( $dist . '/' . $name );
		}
	}

	private function commit_source(): string {
		file_put_contents( $this->fixture . '/source.php', '<?php return 42;' );
		file_put_contents( $this->fixture . '/.gitignore', "/dist/\n/vendor/\n/vendor-prefixed/\n" );
		sscribe_build_git( $this->fixture, array( 'init', '-q' ) );
		sscribe_build_git( $this->fixture, array( 'add', '.' ) );
		sscribe_build_git( $this->fixture, array( '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'Fixture' ) );
		return sscribe_build_git( $this->fixture, array( 'rev-parse', 'HEAD' ) );
	}

	public function test_build_workspace_uses_committed_source_and_leaves_original_outputs_intact(): void {
		$sha = $this->commit_source();
		foreach ( array( 'dist', 'vendor', 'vendor-prefixed' ) as $directory ) {
			mkdir( $this->fixture . '/' . $directory );
			file_put_contents( $this->fixture . '/' . $directory . '/keep.txt', 'original output' );
		}
		$result = sscribe_create_build_workspace( $this->fixture );
		$this->workspace = $result['directory'];
		self::assertSame( $sha, $result['source_sha'] );
		self::assertSame( $sha, sscribe_build_git( $result['root'], array( 'rev-parse', 'HEAD' ) ) );
		self::assertSame( '<?php return 42;', file_get_contents( $result['root'] . '/source.php' ) );
		file_put_contents( $result['root'] . '/source.php', 'changed only in disposable copy' );
		self::assertSame( '<?php return 42;', file_get_contents( $this->fixture . '/source.php' ) );
		foreach ( array( 'dist', 'vendor', 'vendor-prefixed' ) as $directory ) {
			self::assertSame( 'original output', file_get_contents( $this->fixture . '/' . $directory . '/keep.txt' ) );
			self::assertDirectoryDoesNotExist( $result['root'] . '/' . $directory );
		}
	}

	public function test_dirty_tracked_source_is_not_certified(): void {
		$this->commit_source();
		file_put_contents( $this->fixture . '/source.php', 'uncommitted change' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Commit tracked changes' );
		sscribe_create_build_workspace( $this->fixture );
	}

	public function test_failed_evidence_write_is_rejected(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Cannot persist build evidence' );
		sscribe_write_build_json( $this->fixture . '/missing/result.json', array( 'status' => 'PASS' ) );
	}
}
