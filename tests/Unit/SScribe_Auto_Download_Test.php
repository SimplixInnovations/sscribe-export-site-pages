<?php
/**
 * Phase 22 regression: the auto-download-on-complete feature must
 * have a well-defined, explicit opt-in contract.
 *
 * Why this test exists:
 *
 *   Auto-download triggers an immediate browser file download the
 *   moment an export completes — no second click required. That is
 *   useful in batch workflows (CI, scheduled jobs), and convenient
 *   for admins who always want the artifact on disk. It is also a
 *   footgun: a stray download in a user's Downloads folder is a
 *   surprise.
 *
 *   The authoritative design (Phase 68 #23 + the
 *   SScribe_Auto_Download_Opt_In_Test integration contract) is:
 *
 *     - Auto-download is opt-in ONLY via the visible admin checkbox.
 *     - The server MUST NOT localize a global auto_download default.
 *     - The server MUST NOT expose a silent `sscribe_auto_download_on_complete`
 *       filter that an integrator can pull to globally enable the feature
 *       for every user.
 *     - The JS layer reads the live visible checkbox state to decide.
 *
 *   This test pins those guarantees on the PHP layer: the localized
 *   admin data must not contain a global auto-download default, and
 *   the legacy global opt-in filter must not be reachable from the
 *   PHP admin code.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Auto_Download_Test extends TestCase {

	private function admin_php(): string {
		$path = dirname( __DIR__, 2 ) . '/admin/class-sscribe-admin.php';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path );
	}

	/**
	 * Phase 68 #23 — auto-download MUST be opt-in only. The server
	 * must not localize a global auto-download default that could
	 * silently enable downloads on a fresh install.
	 */
	public function test_admin_payload_has_no_global_auto_download_default(): void {
		$this->assertStringNotContainsString(
			"'auto_download'",
			$this->admin_php(),
			'The admin payload must not localize a global auto_download default; opt-in must be visible-only via the UI toggle.'
		);
	}

	public function test_admin_payload_does_not_expose_global_opt_in_filter(): void {
		$this->assertStringNotContainsString(
			'sscribe_auto_download_on_complete',
			$this->admin_php(),
			'The legacy/global auto-download filter must not be reachable from the admin class; opt-in must be visible-only via the UI toggle.'
		);
	}
}
