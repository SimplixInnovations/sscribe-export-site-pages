<?php
/** Generated root metadata must not depend on an old install or branch name. */
declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/lib/release-metadata.php';

final class SScribe_Release_Metadata_Test extends TestCase {

	private function fixture( string $branch, string $reference ): string {
		return "<?php return array (\n  'root' => \n  array (\n"
			. "    'name' => 'simplix-innovations/sscribe-export-site-pages',\n"
			. "    'pretty_version' => '$branch',\n    'version' => '$branch',\n"
			. "    'reference' => '$reference',\n    'type' => 'wordpress-plugin',\n"
			. "    'install_path' => __DIR__ . '/../',\n    'aliases' => array (),\n    'dev' => true,\n  ),\n"
			. "  'versions' => array (\n    'example/dependency' => array (\n"
			. "      'reference' => '39b7c1c92375260cc7646fa5e61caf1b87ca5aca',\n"
			. "      'install_path' => __DIR__ . '/../example/dependency',\n    ),\n  ),\n);\n";
	}

	public function test_stale_and_clean_installs_produce_identical_release_metadata(): void {
		$sha = '0e7294af9a3d936102902ca71b1210268af21e95';
		$stale = $this->fixture( 'dev-main', '39b7c1c92375260cc7646fa5e61caf1b87ca5aca' );
		$clean = $this->fixture( 'dev-cert/closeout-2.0.4', $sha );
		$actual = sscribe_release_composer_metadata( $stale, '2.0.4', $sha );
		self::assertSame( $actual, sscribe_release_composer_metadata( $clean, '2.0.4', $sha ) );
		self::assertSame( $actual, sscribe_release_composer_metadata( $actual, '2.0.4', $sha ) );
		self::assertStringContainsString( "'reference' => '$sha'", $actual );
		self::assertStringContainsString( "'pretty_version' => '2.0.4'", $actual );
		self::assertStringContainsString( "'version' => '2.0.4.0'", $actual );
		// Compare the complete dependency section, including a matching stale SHA.
		self::assertSame( substr( $stale, strpos( $stale, "  'versions' =>" ) ), substr( $actual, strpos( $actual, "  'versions' =>" ) ) );
	}

	public function test_packaging_updates_only_the_staged_file(): void {
		$dir = sys_get_temp_dir() . '/sscribe-metadata-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir );
		$source = $this->fixture( 'dev-main', str_repeat( 'a', 40 ) );
		file_put_contents( $dir . '/source.php', $source );
		copy( $dir . '/source.php', $dir . '/stage.php' );
		try {
			sscribe_write_release_composer_metadata( $dir . '/stage.php', '2.0.4', str_repeat( 'b', 40 ) );
			self::assertSame( $source, file_get_contents( $dir . '/source.php' ) );
			$data = require $dir . '/stage.php';
			self::assertSame( str_repeat( 'b', 40 ), $data['root']['reference'] );
			self::assertSame( str_replace( '\\', '/', $dir . '/../' ), str_replace( '\\', '/', $data['root']['install_path'] ) );
			self::assertSame( '39b7c1c92375260cc7646fa5e61caf1b87ca5aca', $data['versions']['example/dependency']['reference'] );
		} finally {
			unlink( $dir . '/source.php' );
			unlink( $dir . '/stage.php' );
			rmdir( $dir );
		}
	}

	public function test_unknown_metadata_layout_is_rejected(): void {
		$this->expectException( \RuntimeException::class );
		sscribe_release_composer_metadata( '<?php return array();', '2.0.4', str_repeat( 'a', 40 ) );
	}

	public function test_other_root_package_is_rejected(): void {
		$this->expectException( \RuntimeException::class );
		sscribe_release_composer_metadata( str_replace( 'simplix-innovations/sscribe-export-site-pages', 'another/package', $this->fixture( 'dev-main', str_repeat( 'a', 40 ) ) ), '2.0.4', str_repeat( 'b', 40 ) );
	}

	public function test_invalid_source_identity_is_rejected(): void {
		$this->expectException( \RuntimeException::class );
		sscribe_release_composer_metadata( $this->fixture( 'dev-main', str_repeat( 'a', 40 ) ), '2.0.4', 'HEAD' );
	}

	public function test_invalid_version_is_rejected(): void {
		$this->expectException( \RuntimeException::class );
		sscribe_release_composer_metadata( $this->fixture( 'dev-main', str_repeat( 'a', 40 ) ), "2.0.4';", str_repeat( 'b', 40 ) );
	}

	public function test_missing_metadata_is_rejected(): void {
		$this->expectException( \RuntimeException::class );
		sscribe_write_release_composer_metadata( sys_get_temp_dir() . '/missing-' . bin2hex( random_bytes( 8 ) ), '2.0.4', str_repeat( 'a', 40 ) );
	}
}
