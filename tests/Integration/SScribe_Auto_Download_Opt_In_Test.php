<?php
/**
 * Auto-download explicit opt-in regression contract.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_Auto_Download_Opt_In_Test extends TestCase {

	private function admin_js(): string {
		$path = dirname(__DIR__, 2) . '/admin/js/sscribe-admin.js';
		$this->assertFileExists($path);
		return (string) file_get_contents($path);
	}

	private function admin_php(): string {
		$path = dirname(__DIR__, 2) . '/admin/class-sscribe-admin.php';
		$this->assertFileExists($path);
		return (string) file_get_contents($path);
	}

	public function test_auto_download_starts_off_on_every_page_load(): void {
		$js = $this->admin_js();

		$this->assertMatchesRegularExpression(
			'/initAutoDownloadPreference:\s*function\s*\(\)\s*\{(?:(?!\n\t\t\},).)*?\$toggle\.prop\(\s*[\'"]checked[\'"]\s*,\s*false\s*\)/s',
			$js,
			'Auto-download must initialize visibly OFF on every fresh page load.'
		);
	}

	public function test_auto_download_has_no_global_or_hidden_fallback(): void {
		$js = $this->admin_js();

		$this->assertStringNotContainsString(
			'sscribe_data.auto_download',
			$js,
			'Runtime auto-download decisions must not inherit a hidden/global site default.'
		);
		$this->assertStringNotContainsString(
			'sscribe_auto_download_pref',
			$js,
			'Runtime auto-download decisions must not use legacy client-side preference storage.'
		);
	}

	public function test_auto_download_runtime_decision_requires_visible_checked_toggle(): void {
		$js = $this->admin_js();

		$this->assertMatchesRegularExpression(
			'/shouldAutoDownload:\s*function\s*\(\)\s*\{(?:(?!\n\t\t\},).)*?return\s+\$toggle\.length\s*>\s*0\s*&&\s*\$toggle\.is\(\s*[\'"]:checked[\'"]\s*\)\s*;/s',
			$js,
			'Auto-download must require the live visible checkbox to exist and be checked.'
		);
	}

	public function test_server_does_not_localize_a_global_auto_download_default(): void {
		$php = $this->admin_php();

		$this->assertStringNotContainsString(
			"'auto_download'",
			$php,
			'The admin payload must not expose a global auto-download default that can silently enable downloads.'
		);
		$this->assertStringNotContainsString(
			'sscribe_auto_download_on_complete',
			$php,
			'The legacy/global auto-download filter must not silently opt users in.'
		);
	}
}
