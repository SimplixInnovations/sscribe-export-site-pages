<?php
/**
 * Unit tests for scripts/lib/coverage-path.php.
 *
 * The path normalizer is the single source of truth for converting
 * Clover-reported paths to repository-relative forward-slash paths.
 * Every shape of input that has ever been observed in a real
 * clover.xml on this project must be locked down here so a future
 * OS, driver, or CI change cannot silently regress the coverage
 * gate.
 *
 * Each scenario uses a synthetic `$repo_root` because the function is
 * pure: no filesystem access, no environment reads. The integration
 * suite in tests/Integration/SScribe_Coverage_Thresholds_Test.php
 * exercises the verifier end-to-end with real fixtures.
 *
 * @package SScribe_Export_Site_Pages
 */

declare( strict_types=1 );

namespace SScribe\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/lib/coverage-path.php';

final class SScribe_Coverage_Path_Normalizer_Test extends TestCase {

	private const REPO_ROOT_LINUX   = '/home/runner/work/sscribe-export-site-pages/sscribe-export-site-pages';
	private const REPO_ROOT_WINDOWS = 'C:\\Users\\Ahmed\\Desktop\\sscribe-export-site-pages';

	public function test_already_relative_path_is_unchanged(): void {
		$this::assertSame(
			'includes/class-sscribe-security.php',
			sscribe_normalize_clover_path(
				'includes/class-sscribe-security.php',
				self::REPO_ROOT_LINUX
			)
		);
	}

	public function test_relative_path_with_leading_dot_slash_is_stripped(): void {
		$this::assertSame(
			'includes/class-sscribe-security.php',
			sscribe_normalize_clover_path(
				'./includes/class-sscribe-security.php',
				self::REPO_ROOT_LINUX
			)
		);
	}

	public function test_relative_path_with_repeated_leading_dot_slash_is_collapsed(): void {
		$this::assertSame(
			'includes/class-sscribe-security.php',
			sscribe_normalize_clover_path(
				'./././includes/class-sscribe-security.php',
				self::REPO_ROOT_LINUX
			)
		);
	}

	public function test_linux_absolute_path_strips_repo_prefix(): void {
		$this::assertSame(
			'includes/class-sscribe-security.php',
			sscribe_normalize_clover_path(
				self::REPO_ROOT_LINUX . '/includes/class-sscribe-security.php',
				self::REPO_ROOT_LINUX
			)
		);
	}

	public function test_linux_absolute_path_with_no_repo_match_returns_empty(): void {
		// Path outside the repo: must NOT silently normalise to a
		// matching critical-file key, because that would mask a
		// wrong-driver / wrong-cwd misconfiguration.
		$this::assertSame(
			'',
			sscribe_normalize_clover_path(
				'/var/other-project/includes/class-sscribe-security.php',
				self::REPO_ROOT_LINUX
			)
		);
	}

	public function test_windows_backslash_absolute_path_strips_repo_prefix(): void {
		$this::assertSame(
			'includes/class-sscribe-security.php',
			sscribe_normalize_clover_path(
				self::REPO_ROOT_WINDOWS . '\\includes\\class-sscribe-security.php',
				self::REPO_ROOT_WINDOWS
			)
		);
	}

	public function test_windows_forward_slash_absolute_path_strips_repo_prefix(): void {
		// PHP on Windows can emit either backslashes or forward slashes
		// depending on the driver; both shapes must normalise correctly.
		$this::assertSame(
			'includes/class-sscribe-security.php',
			sscribe_normalize_clover_path(
				self::REPO_ROOT_WINDOWS . '/includes/class-sscribe-security.php',
				self::REPO_ROOT_WINDOWS
			)
		);
	}

	public function test_windows_absolute_path_is_case_insensitive(): void {
		// Real Windows paths occasionally get capitalised by the driver.
		// The repo-root comparison must be case-insensitive on Windows.
		$this::assertSame(
			'includes/class-sscribe-security.php',
			sscribe_normalize_clover_path(
				'c:\\Users\\ahmed\\desktop\\SSCRIBE-EXPORT-SITE-PAGES\\includes\\class-sscribe-security.php',
				self::REPO_ROOT_WINDOWS
			)
		);
	}

	public function test_windows_absolute_path_outside_repo_returns_empty(): void {
		$this::assertSame(
			'',
			sscribe_normalize_clover_path(
				'D:\\Some\\Other\\Project\\includes\\class-sscribe-security.php',
				self::REPO_ROOT_WINDOWS
			)
		);
	}

	public function test_repo_root_with_trailing_slash_is_handled(): void {
		$this::assertSame(
			'includes/foo.php',
			sscribe_normalize_clover_path(
				self::REPO_ROOT_LINUX . '/includes/foo.php',
				self::REPO_ROOT_LINUX . '/'
			)
		);
	}

	public function test_repo_root_must_not_match_prefix_of_another_dir(): void {
		// /home/runner/work/sscribe must NOT match /home/runner/work/sscribe-other/.
		$this::assertSame(
			'',
			sscribe_normalize_clover_path(
				'/home/runner/work/sscribe-other/includes/foo.php',
				self::REPO_ROOT_LINUX
			)
		);
	}
}