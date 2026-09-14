<?php
/**
 * E2E workflow execution contract.
 *
 * @package SScribe_Export_Site_Pages
 */

declare(strict_types=1);

namespace SScribe\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SScribe_E2E_Workflow_Test extends TestCase {

	private function workflow(): string {
		$path = dirname(__DIR__, 2) . '/.github/workflows/e2e.yml';
		$this->assertFileExists($path);
		return (string) file_get_contents($path);
	}

	public function test_e2e_installs_dev_toolchain_before_strauss_prefixing(): void {
		$workflow = $this->workflow();
		$this->assertStringNotContainsString('composer install --no-dev', $workflow);
		$this->assertStringContainsString('composer install --no-interaction --no-progress --no-scripts', $workflow);

		$install = strpos($workflow, 'composer install --no-interaction --no-progress --no-scripts');
		$prefix  = strpos($workflow, 'composer vendor:prefix');
		$this->assertIsInt($install);
		$this->assertIsInt($prefix);
		$this->assertLessThan($prefix, $install, 'Composer dev dependencies, including Strauss, must be installed before vendor:prefix executes.');
	}

	public function test_e2e_runs_locked_composer_and_fail_closed_npm_audits_before_build(): void {
		$workflow = $this->workflow();
		$this->assertStringContainsString('composer audit --locked --format=plain --abandoned=fail', $workflow);
		$this->assertStringContainsString('npm run test:audit-helper', $workflow);
		$this->assertStringContainsString('npm run audit:js', $workflow);

		$composer_audit = strpos($workflow, 'composer audit --locked --format=plain --abandoned=fail');
		$npm_audit_test = strpos($workflow, 'npm run test:audit-helper');
		$npm_audit      = strpos($workflow, 'npm run audit:js');
		$prefix         = strpos($workflow, 'composer vendor:prefix');
		$this->assertIsInt($composer_audit);
		$this->assertIsInt($npm_audit_test);
		$this->assertIsInt($npm_audit);
		$this->assertIsInt($prefix);
		$this->assertLessThan($prefix, $composer_audit);
		$this->assertLessThan($prefix, $npm_audit_test);
		$this->assertLessThan($prefix, $npm_audit);
		$this->assertLessThan($npm_audit, $npm_audit_test);
	}

	public function test_e2e_global_setup_installs_only_the_canonical_versioned_release_zip(): void {
		// Per the §22 directive, ZIP resolution moved out of globalSetup.ts into
		// runtime/runtime-config.ts. This test now asserts against the new owner.
		$path = dirname(__DIR__, 2) . '/tests-e2e/runtime/runtime-config.ts';
		$this->assertFileExists($path);
		$source = (string) file_get_contents($path);

		$this->assertStringContainsString(
			'sscribe-export-site-pages-${v}.zip',
			$source,
			'E2E must select the canonical versioned ZIP rather than whichever ZIP happens to be first in dist/.'
		);
		$this->assertStringContainsString(
			"readFileSync(join(process.cwd(), 'package.json')",
			$source,
			'E2E must read the canonical version from package.json so the ZIP path stays in lockstep.'
		);
		$this->assertStringContainsString(
			"if (!existsSync(sscribeZipPath))",
			$source,
			'E2E must fail fast when the canonical ZIP is missing rather than falling back to an arbitrary dist/ ZIP.'
		);
		$this->assertStringNotContainsString(
			"filter((f: string) => f.endsWith('.zip'))",
			$source,
			'E2E must never choose the first arbitrary ZIP from dist/.'
		);
	}

	public function test_e2e_requires_full_playwright_suite_on_pull_requests(): void {
		$workflow = $this->workflow();
		$this->assertStringContainsString('npm run test:e2e:full', $workflow);
		$this->assertStringContainsString("github.event_name == 'pull_request'", $workflow);
	}
}
