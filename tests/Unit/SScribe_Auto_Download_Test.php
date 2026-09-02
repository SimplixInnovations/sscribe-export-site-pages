<?php
/**
 * Phase 22 regression: the auto-download-on-complete feature must
 * have a well-defined, opt-in data contract.
 *
 * Why this test exists:
 *
 *   Auto-download triggers an immediate browser file download the
 *   moment an export completes — no second click required. That is
 *   useful in batch workflows (CI, scheduled jobs), and convenient
 *   for admins who always want the artifact on disk. It is also a
 *   footgun: a stray download in a user's Downloads folder is a
 *   surprise. The default MUST be off, and the filter override
 *   MUST be opt-in only.
 *
 *   The PHP layer exposes two things:
 *
 *     1. `sscribe_data.auto_download` — a JS-visible boolean that
 *        tells the admin UI whether to auto-click the download
 *        anchor when an export completes.
 *     2. The `sscribe_auto_download_on_complete` filter — the
 *        single knob an integrator can pull to enable auto-download
 *        globally (default returns false).
 *
 *   This test pins four guarantees:
 *
 *     a. Default is false (no silent auto-downloads on first install).
 *     b. Filter returning true propagates to the localized JS data.
 *     c. Truthy non-bool filter returns are coerced to bool so the
 *        JS layer never sees `1` or `"yes"` in place of `true`.
 *     d. False-y filter returns are coerced to false (no leaking
 *        `null` into a JS truthy check).
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SScribe_Auto_Download_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Drop any leftover filter callbacks from a previous test.
		global $sscribe_test_filters;
		if ( is_array( $sscribe_test_filters ) ) {
			$sscribe_test_filters = array_values(
				array_filter(
					$sscribe_test_filters,
					static function ( array $entry ): bool {
						return ( $entry['hook'] ?? '' ) !== 'sscribe_auto_download_on_complete';
					}
				)
			);
		}
	}

	protected function tearDown(): void {
		global $sscribe_test_filters;
		if ( is_array( $sscribe_test_filters ) ) {
			$sscribe_test_filters = array_values(
				array_filter(
					$sscribe_test_filters,
					static function ( array $entry ): bool {
						return ( $entry['hook'] ?? '' ) !== 'sscribe_auto_download_on_complete';
					}
				)
			);
		}
		parent::tearDown();
	}

	/**
	 * Invoke the private SScribe_Admin::build_localized_data() and
	 * return the auto_download key.
	 */
	private function localized_auto_download(): mixed {
		$admin  = new \SScribe_Admin();
		$method = new \ReflectionMethod( $admin, 'build_localized_data' );
		/** @var array<string,mixed> $data */
		$data = $method->invoke( $admin );
		return $data['auto_download'] ?? null;
	}

	public function test_auto_download_defaults_to_false(): void {
		// No integrator filter present. The default MUST be false so
		// a fresh install never auto-downloads without the admin
		// explicitly opting in.
		$this->assertFalse(
			$this->localized_auto_download(),
			'auto_download must default to false; a fresh install must never auto-download without explicit opt-in'
		);
	}

	public function test_filter_returning_true_enables_auto_download(): void {
		// A returning true from the filter is the canonical opt-in.
		add_filter(
			'sscribe_auto_download_on_complete',
			static function (): bool {
				return true;
			}
		);

		$this->assertTrue(
			$this->localized_auto_download(),
			'returning true from sscribe_auto_download_on_complete must propagate as auto_download=true in the JS data'
		);
	}

	public function test_truthy_non_bool_filter_returns_are_coerced_to_true(): void {
		// Defensive: if a third-party filter returns an int or a
		// truthy string ("yes", "1"), the JS layer must still see a
		// strict bool. Otherwise the conditional `if (sscribe_data.auto_download)`
		// in JS would still work but the JS `typeof` check would not
		// be 'boolean', breaking downstream API expectations.
		add_filter(
			'sscribe_auto_download_on_complete',
			static function (): string {
				return 'yes';
			}
		);

		$value = $this->localized_auto_download();
		$this->assertTrue(
			(bool) $value,
			'truthy non-bool filter returns must coerce to true'
		);
		$this->assertIsBool(
			$value,
			'auto_download in the localized JS data must always be a strict bool, never an int or string'
		);
	}

	public function test_null_filter_return_is_coerced_to_false(): void {
		// A null filter return must NOT silently mean "enabled".
		add_filter(
			'sscribe_auto_download_on_complete',
			static function () {
				return null;
			}
		);

		$this->assertFalse(
			$this->localized_auto_download(),
			'null filter return must coerce to false; auto-download must require explicit opt-in'
		);
	}

	public function test_auto_download_key_exists_in_localized_data(): void {
		// The JS layer depends on the key being present. A refactor
		// that accidentally drops the key (e.g. a typo, a missed
		// array merge) would silently fall through to "no
		// auto-download" for everyone. This test catches the missing
		// key directly.
		$admin  = new \SScribe_Admin();
		$method = new \ReflectionMethod( $admin, 'build_localized_data' );
		/** @var array<string,mixed> $data */
		$data = $method->invoke( $admin );

		$this->assertArrayHasKey(
			'auto_download',
			$data,
			'Phase 22: auto_download must be a key in the localized JS data; removing it breaks the JS shouldAutoDownload() fallback path'
		);
	}
}
