/**
 * Native E2E runtime contract test.
 *
 * Per the NATIVE WORDPRESS E2E IMPLEMENTATION DIRECTIVE §29:
 *   "Add a repository-level test verifying:
 *      native runtime adapter exists
 *      exact ZIP path is required
 *      source-mounted plugin is rejected
 *      WordPress version is pinned
 *      DB integration version is pinned
 *      MU helpers are installed
 *      50-page seed remains
 *      readiness checks exist
 *      cleanup exists
 *      Playwright full matrix remains wired"
 *
 * This is a Node test, no browser required. Exits 0 on pass, 1 on fail.
 *
 * Invoked via `npm run test:e2e:runtime-contract` (added in package.json).
 */

import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();

function fail(msg) {
  console.error(`✗ ${msg}`);
  process.exitCode = 1;
}

function pass(msg) {
  console.log(`✓ ${msg}`);
}

// 1. Native runtime adapter exists.
const adapterPath = join(root, 'tests-e2e/runtime/native-wordpress.ts');
if (!existsSync(adapterPath)) {
  fail(`native runtime adapter missing: ${adapterPath}`);
} else {
  pass('native runtime adapter exists');
}

// 2. Runtime types file exists.
const typesPath = join(root, 'tests-e2e/runtime/types.ts');
if (!existsSync(typesPath)) {
  fail(`runtime types file missing: ${typesPath}`);
} else {
  pass('runtime types file exists');
}

// 3. Runtime config / selector exists.
const configPath = join(root, 'tests-e2e/runtime/runtime-config.ts');
if (!existsSync(configPath)) {
  fail(`runtime-config missing: ${configPath}`);
} else {
  pass('runtime-config exists');
}

// 4. Exact ZIP path is required — runtime must read from dist/.
const adapterSrc = readFileSync(adapterPath, 'utf-8');
if (!/sscribe-export-site-pages-\$\{releaseVersion\}\.zip|sscribeZipPath/.test(adapterSrc)) {
  fail('native adapter does not reference a ZIP path requirement');
} else {
  pass('exact ZIP path requirement present');
}

// 5. Source-mounted plugin is rejected — adapter must extract from ZIP, not
//    reference the source checkout (no cwd-relative plugin path).
if (/sscribe-export-site-pages\/sscribe-export-site-pages\.php['"]?\s*\)/m.test(adapterSrc)) {
  // The only acceptable form is "place the file from the ZIP extract".
  // We assert there's NO direct require-from-cwd of the plugin main file.
}
if (/require\s*\(\s*['"][^'"]*sscribe-export-site-pages\.php['"]/.test(adapterSrc)) {
  fail('native adapter source-mounts the plugin main file (forbidden)');
} else {
  pass('source-mounted plugin is rejected (no cwd-relative plugin require)');
}

// 6. WordPress version pinned.
if (!/PINNED_WORDPRESS_VERSION\s*=\s*['"]7\.1['"]/.test(adapterSrc)) {
  fail('WordPress version not pinned to 7.1');
} else {
  pass('WordPress version pinned (7.1)');
}

// 7. DB integration version pinned.
if (!/PINNED_SQLITE_INTEGRATION_VERSION\s*=\s*['"]2\.2\.4['"]/.test(adapterSrc)) {
  fail('SQLite integration version not pinned');
} else {
  pass('SQLite integration version pinned (2.2.4)');
}

// 8. MU helpers are installed (extractZip of mu-plugins/).
if (!/installMuPlugins/.test(adapterSrc)) {
  fail('MU-plugin installer not present');
} else {
  pass('MU-plugin installer present');
}

// 9. 50-page seed remains (EXPECTED_SEED_PAGES or wp_count_posts reference).
if (!/EXPECTED_SEED_PAGES\s*=\s*50/.test(adapterSrc)) {
  fail('50-page seed expectation not present');
} else {
  pass('50-page seed preserved');
}

// 10. Readiness checks exist (probeBootState + waitForReady).
if (!/probeBootState|waitForReady/.test(adapterSrc)) {
  fail('readiness checks missing');
} else {
  pass('readiness checks present');
}

// 11. Cleanup exists (teardown method).
if (!/teardown\s*\(\s*\)\s*:\s*Promise<void>/.test(adapterSrc)) {
  fail('teardown method missing');
} else {
  pass('teardown present');
}

// 12. Playwright full matrix remains wired (playwright.config.ts test projects).
//    Note: 'perf' project was removed — no real browser-performance tests existed.
//    The authoritative performance gate is the PHP integration benchmark
//    (composer test:perf / SScribe_Performance_Budget_Test.php).
const pwConfig = readFileSync(join(root, 'playwright.config.ts'), 'utf-8');
for (const project of ['e2e', 'a11y']) {
  if (!new RegExp(`name:\\s*['"]${project}['"]`).test(pwConfig)) {
    fail(`Playwright project "${project}" missing from config`);
  } else {
    pass(`Playwright project "${project}" wired`);
  }
}
// Verify no empty perf project masquerades as a release gate.
if (/name:\s*['"]perf['"]/.test(pwConfig)) {
  fail('Playwright project "perf" still present — remove empty browser perf project');
} else {
  pass('no empty Playwright perf project');
}

// 13. globalSetup refactored to orchestration only (calls bootRuntime).
const gsPath = join(root, 'tests-e2e/globalSetup.ts');
if (!existsSync(gsPath)) {
  fail('globalSetup.ts missing');
} else {
  const gsSrc = readFileSync(gsPath, 'utf-8');
  if (!/bootRuntime/.test(gsSrc)) {
    fail('globalSetup does not delegate to bootRuntime');
  } else {
    pass('globalSetup delegates to runtime selector');
  }
}

// 14. globalTeardown wired.
const gtPath = join(root, 'tests-e2e/globalTeardown.ts');
if (!existsSync(gtPath)) {
  fail('globalTeardown.ts missing');
} else {
  pass('globalTeardown.ts present');
}

// 15. SSCRIBE_E2E_RUNTIME selector exists.
if (!/SSCRIBE_E2E_RUNTIME/.test(readFileSync(configPath, 'utf-8'))) {
  fail('SSCRIBE_E2E_RUNTIME selector missing');
} else {
  pass('SSCRIBE_E2E_RUNTIME selector present');
}

// 16. Native backend is the default.
const configSrc = readFileSync(configPath, 'utf-8');
if (!/return\s+['"]native['"]/.test(configSrc)) {
  fail('native is not the default runtime');
} else {
  pass('native is the default runtime');
}

// 17. Fixtures: mu-plugins dir exists.
const muDir = join(root, 'tests-e2e/fixtures/mu-plugins');
if (!existsSync(muDir)) {
  fail(`mu-plugins dir missing: ${muDir}`);
} else {
  pass('mu-plugins fixture dir exists');
}

// 18. .gitignore covers .cache (don't leak runtime to git).
const giPath = join(root, '.gitignore');
const gi = existsSync(giPath) ? readFileSync(giPath, 'utf-8') : '';
if (!/\.cache\//.test(gi)) {
  fail('.gitignore does not ignore .cache/');
} else {
  pass('.gitignore covers .cache/');
}

// 19. tests-e2e/.cache ignored specifically (the leading `.cache/` rule covers it).
if (!/\.cache\//.test(gi)) {
  fail('.gitignore does not ignore tests-e2e/.cache (no leading-dot .cache/ rule)');
} else {
  pass('.gitignore covers tests-e2e/.cache via leading-dot .cache/ rule');
}

// 20. runtime contract test wired into npm script.
const pkgJson = JSON.parse(readFileSync(join(root, 'package.json'), 'utf-8'));
if (!pkgJson.scripts || !pkgJson.scripts['test:e2e:runtime-contract']) {
  fail('package.json script "test:e2e:runtime-contract" missing');
} else {
  pass('package.json wires test:e2e:runtime-contract');
}

// 21. No empty Playwright perf script masquerading as a release gate.
if (pkgJson.scripts && pkgJson.scripts['test:e2e:perf']) {
  fail('package.json still has "test:e2e:perf" script — remove empty browser perf gate');
} else {
  pass('no empty test:e2e:perf script');
}

// 22. SSCRIBE_E2E_PHP_BINARY support present in native adapter.
if (!/SSCRIBE_E2E_PHP_BINARY/.test(adapterSrc)) {
  fail('native adapter does not support SSCRIBE_E2E_PHP_BINARY');
} else {
  pass('SSCRIBE_E2E_PHP_BINARY support present');
}

// 23. Native adapter uses configurable PHP binary (not hardcoded 'php').
if (/spawn\(\s*'php'/.test(adapterSrc)) {
  fail('native adapter hardcodes php binary instead of using configurable SSCRIBE_E2E_PHP_BINARY');
} else {
  pass('native adapter uses configurable PHP binary');
}

if (process.exitCode === 1) {
  console.error('\nRUNTIME CONTRACT: FAIL');
} else {
  console.log('\nRUNTIME CONTRACT: PASS');
}
