<?php
/**
 * Release leakage scanner behavior.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_AI_Artifact_Scanner_Test extends TestCase {

	private string $tmp_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp_dir = sys_get_temp_dir() . '/sscribe-leakage-' . bin2hex(random_bytes(6));
		$this->assertTrue(mkdir($this->tmp_dir, 0700, true));
	}

	protected function tearDown(): void {
		$this->remove_tree($this->tmp_dir);
		parent::tearDown();
	}

	private function root(): string {
		return dirname(__DIR__, 2);
	}

	private function remove_tree(string $path): void {
		if (! is_dir($path)) {
			return;
		}
		$items = scandir($path);
		if (false === $items) {
			return;
		}
		foreach ($items as $item) {
			if ('.' === $item || '..' === $item) {
				continue;
			}
			$child = $path . DIRECTORY_SEPARATOR . $item;
			if (is_dir($child) && ! is_link($child)) {
				$this->remove_tree($child);
			} else {
				@unlink($child);
			}
		}
		@rmdir($path);
	}

	/** @return array{0:int,1:string} */
	private function run_scanner(): array {
		$command = array(
			PHP_BINARY,
			$this->root() . '/scripts/check-ai-artifacts.php',
			'--root',
			$this->tmp_dir,
		);
		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$process = proc_open($command, $descriptors, $pipes);
		$this->assertIsResource($process);
		fclose($pipes[0]);
		$stdout = (string) stream_get_contents($pipes[1]);
		$stderr = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);
		return array($code, $stdout . $stderr);
	}

	public function test_professional_unicode_punctuation_is_not_treated_as_release_leakage(): void {
		file_put_contents(
			$this->tmp_dir . '/fixture.php',
			"<?php\n// Professional copy — en–dash, “quotes”, ‘apostrophe’, and ellipsis… are valid text.\n"
		);

		list($code, $output) = $this->run_scanner();
		$this->assertSame(0, $code, $output);
	}

	public function test_zero_width_source_character_fails(): void {
		file_put_contents($this->tmp_dir . '/fixture.php', "<?php\n// hidden\xE2\x80\x8Bcharacter\n");

		list($code, $output) = $this->run_scanner();
		$this->assertSame(1, $code, $output);
		$this->assertStringContainsString('ZERO_WIDTH', $output);
	}

	public function test_committed_assistant_workspace_directory_fails(): void {
		$workspace = $this->tmp_dir . '/.claude';
		$this->assertTrue(mkdir($workspace, 0700, true));
		file_put_contents($workspace . '/session.md', "internal workspace transcript\n");

		list($code, $output) = $this->run_scanner();
		$this->assertSame(1, $code, $output);
		$this->assertStringContainsString('ASSISTANT_WORKSPACE', $output);
	}

	public function test_explicit_assistant_boilerplate_fails(): void {
		file_put_contents(
			$this->tmp_dir . '/fixture.php',
			"<?php\n// As an AI language model, I cannot execute this code.\n"
		);

		list($code, $output) = $this->run_scanner();
		$this->assertSame(1, $code, $output);
		$this->assertStringContainsString('ASSISTANT_BOILERPLATE', $output);
	}
}
