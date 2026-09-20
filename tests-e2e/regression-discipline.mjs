#!/usr/bin/env node
/**
 * Regression discipline: for each of 8 targeted regressions, proves
 * that the corresponding test fails when the source fix is reverted.
 *
 * Each target has:
 *   - specFile: which test exercises the regression
 *   - sourceFile + revertMatch + revertReplace: a sed-style patch
 *   - description: human-readable
 *
 * For each target: apply revert → run spec → assert fails → restore source.
 *
 * Run on PRs only (added in Task 5's workflow).
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';

const ROOT = resolve(import.meta.dirname, '..');
const targets = [
  {
    specFile: 'tests-e2e/a11y/admin-tabs.spec.ts',
    description: 'format-desc text contrast meets WCAG AA',
    sourceFile: 'admin/css/sscribe-admin.css',
    // .sscribe-format-desc { ... color: var(--ss-text-secondary); ... }
    // Reverting color to #cccccc drops contrast from ~10.4:1 to ~1.6:1,
    // below the AA threshold the spec asserts.
    revertMatch: /(\.sscribe-format-desc\s*\{[^}]*color:\s*)([^;]+)/,
    revertReplace: '$1#cccccc',
  },
  {
    specFile: 'tests-e2e/a11y/admin-tabs.spec.ts',
    description: 'lang-name text contrast meets WCAG AA',
    sourceFile: 'admin/css/sscribe-admin.css',
    // .sscribe-lang-name { ... color: var(--ss-text-primary); ... } (in the
    // shared post-type-name/format-name/lang-name rule at line 579-583).
    // Reverting color to #cccccc drops contrast below AA.
    revertMatch:
      /(\.sscribe-post-type-name,\s*\.sscribe-format-name,\s*\.sscribe-lang-name\s*\{[^}]*color:\s*)([^;]+)/,
    revertReplace: '$1#cccccc',
  },
  {
    specFile: 'tests-e2e/a11y/format-cards.spec.ts',
    description: 'markdown-format-card word break',
    sourceFile: 'admin/css/sscribe-admin.css',
    // .sscribe-format-name { ... word-break: normal; overflow-wrap: normal; ... }
    // Reverting word-break to break-all forces mid-character breaks in
    // "Markdown" when the column is narrow, exceeding the 2-line ceiling
    // the spec asserts.
    revertMatch:
      /(\.sscribe-post-type-name,\s*\.sscribe-format-name,\s*\.sscribe-lang-name\s*\{[^}]*word-break:\s*)([^;]+)/,
    revertReplace: '$1break-all',
  },
  {
    specFile: 'tests-e2e/e2e/debug/toggle.spec.ts',
    description: 'debug console assets are enqueued for manage_options',
    sourceFile: 'admin/class-sscribe-admin.php',
    // wp_enqueue_style('sscribe-debug-console', ...) is wrapped in
    // if ( current_user_can('manage_options') ) { ... } at line 203-218.
    // Reverting by neutralizing the capability check makes the assets gate
    // on something false, dropping the <link>/<script> from the page and
    // breaking the spec's "cssLoaded/jsLoaded === true" assertion.
    revertMatch: /(if\s*\(\s*current_user_can\(\s*'manage_options'\s*\)\s*\)\s*\{)/,
    revertReplace: 'if ( false ) {',
  },
  {
    specFile: 'tests-e2e/e2e/history/delete-two-click.spec.ts',
    description: 'Delete button toggles confirming',
    sourceFile: 'admin/css/sscribe-admin.css',
    // The spec asserts `.sscribe-btn-confirming` does NOT have
    // `pointer-events: none`. There is currently no rule setting it; we
    // inject one via a new declaration that the spec then catches as a
    // regression. The revert is intentionally self-injecting: the spec's
    // contract is "no pointer-events rule on .sscribe-btn-confirming".
    revertMatch:
      /(\.sscribe-button\.sscribe-btn-confirming\s*\{[^}]*)(\})/,
    revertReplace: "$1\tpointer-events: none;\n}",
  },
  {
    specFile: 'tests-e2e/e2e/export/wizard-happy-path.spec.ts',
    description: 'export wizard happy path',
    sourceFile: 'includes/class-sscribe-session.php',
    // session_id is bin2hex(random_bytes(8)) -> 16 hex chars. Shrinking to
    // random_bytes(4) yields an 8-char session_id, breaking the spec's
    // regex /^[a-f0-9]{16}$/.
    revertMatch: /(bin2hex\(\s*random_bytes\(\s*)(\d+)(\s*\)\s*\))/,
    revertReplace: '$14$3',
  },
  {
    specFile: 'tests-e2e/e2e/export/download-token-auth.spec.ts',
    description: 'download token is single-use',
    sourceFile: 'includes/class-sscribe-zip-handler.php',
    // consume_dl_token() returns false on mismatch but ALSO rotates the
    // token regardless. The spec needs the ROTATION step to actually
    // happen. Reverting the update_option() call leaves the stored token
    // unchanged — the second request with the original token would still
    // hash_equals match, so the spec's "secondStatus === 403" would fail.
    // We match the `$row['dl_token'] = $this->generate_dl_token();` line
    // and remove it.
    revertMatch:
      /(\$row\['dl_token'\]\s*=\s*\$this->generate_dl_token\(\);\s*\n\s*\$row\['dl_token_at'\]\s*=\s*time\(\);)/,
    revertReplace: "\$row['dl_token_at'] = time();",
  },
  {
    specFile: 'tests-e2e/e2e/export/batch-progress.spec.ts',
    description: 'progress-area exposes aria-live',
    sourceFile: 'admin/partials/sscribe-admin-display.php',
    // #sscribe-progress-area's aria-live="polite" at line 692. Reverting
    // to aria-live="off" makes the spec fail the toHaveAttribute('aria-live',
    // 'polite') assertion immediately on page load.
    revertMatch:
      /(<div id="sscribe-progress-area"[^>]*aria-live=")(polite)("[^>]*>)/,
    revertReplace: '$1off$3',
  },
];

let failures = 0;
let exercised = 0;
let caught = 0;
const missingSpecs = [];
const missingSources = [];

for (const target of targets) {
  const specAbs = resolve(ROOT, target.specFile);
  const srcAbs = resolve(ROOT, target.sourceFile);
  if (!existsSync(specAbs)) {
    console.error(`[skip] ${target.description}: spec ${target.specFile} not yet written`);
    missingSpecs.push(target.description);
    continue;
  }
  if (!existsSync(srcAbs)) {
    console.error(`[skip] ${target.description}: source ${target.sourceFile} not found`);
    missingSources.push(target.description);
    continue;
  }

  const original = readFileSync(srcAbs, 'utf-8');
  const reverted = original.replace(target.revertMatch, target.revertReplace);
  if (reverted === original) {
    console.error(`[fail] ${target.description}: revert pattern did not match in ${target.sourceFile}`);
    failures++;
    continue;
  }

  // Pre-flight: confirm the description matches at least one test. Without
  // this guard, a description typo would let playwright --grep match zero
  // tests, exit 0, and the discipline script would falsely report the
  // regression as "not caught".
  const listResult = spawnSync(
    'npx',
    ['playwright', 'test', '--list', '--grep', target.description],
    {
      cwd: ROOT,
      stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...process.env, CI: '1' },
    }
  );
  const listedCount = (listResult.stdout?.toString() || '').match(/\[(e2e|a11y|perf)\]/g)?.length || 0;
  if (listedCount === 0) {
    console.error(
      `[fail] ${target.description}: description does not match any test in ${target.specFile} (filter typo?)`
    );
    failures++;
    continue;
  }

  writeFileSync(srcAbs, reverted, 'utf-8');

  // E2E deliberately boots the exact release ZIP rather than mounting source.
  // Rebuild that ZIP after each source mutation so the Playwright assertion
  // actually exercises the reverted production behavior.
  const buildResult = spawnSync(
    'php',
    ['scripts/build-release.php', '--skip-validation'],
    {
      cwd: ROOT,
      stdio: 'inherit',
      env: { ...process.env, CI: '1' },
    }
  );
  if (buildResult.status !== 0) {
    // A mutation rejected by the canonical release builder is already caught:
    // shipped-invariant/build gates are part of the release safety net this
    // discipline test is meant to prove. Distinguish that valid prevention
    // from an unrelated broken baseline by restoring the source and requiring
    // the canonical ZIP to rebuild successfully before counting the target.
    writeFileSync(srcAbs, original, 'utf-8');
    const recoveryBuild = spawnSync(
      'php',
      ['scripts/build-release.php', '--skip-validation'],
      {
        cwd: ROOT,
        stdio: 'inherit',
        env: { ...process.env, CI: '1' },
      }
    );

    exercised++;
    if (recoveryBuild.status === 0) {
      console.log(
        `[pass] ${target.description}: mutated artifact rejected by canonical build gate`
      );
      caught++;
    } else {
      console.error(
        `[fail] ${target.description}: mutated build failed and canonical artifact did not recover`
      );
      failures++;
    }
    continue;
  }

  try {
    const result = spawnSync(
      'npx',
      ['playwright', 'test', '--reporter=line', '--grep', target.description],
      {
        cwd: ROOT,
        stdio: 'inherit',
        env: { ...process.env, CI: '1' },
      }
    );
    exercised++;
    if (result.status === 0) {
      console.error(
        `[fail] ${target.description}: test PASSED after revert, regression NOT caught`
      );
      failures++;
    } else {
      console.log(
        `[pass] ${target.description}: test failed after revert, regression caught`
      );
      caught++;
    }
  } finally {
    writeFileSync(srcAbs, original, 'utf-8');
  }
}

// Restore the canonical artifact after the final mutation. Without this,
 // later E2E/performance gates would inherit the last intentionally broken ZIP.
const canonicalBuild = spawnSync(
  'php',
  ['scripts/build-release.php', '--skip-validation'],
  {
    cwd: ROOT,
    stdio: 'inherit',
    env: { ...process.env, CI: '1' },
  }
);
if (canonicalBuild.status !== 0) {
  console.error('[fail] regression-discipline: failed to rebuild canonical release ZIP');
  failures++;
}

if (failures > 0 || exercised < targets.length) {
  const total = targets.length;
  const notExercised = total - exercised;
  const reasons = [];
  if (missingSpecs.length) {
    reasons.push(`${missingSpecs.length} missing spec(s): ${missingSpecs.join(', ')}`);
  }
  if (missingSources.length) {
    reasons.push(`${missingSources.length} missing source(s): ${missingSources.join(', ')}`);
  }
  console.error(
    `\nregression-discipline: exercised ${exercised}/${total}, caught ${caught}/${exercised}.` +
      (failures > 0 ? ` ${failures} regression(s) not caught.` : '') +
      (notExercised > 0 ? ` ${notExercised} target(s) not exercised: ${reasons.join('; ')}.` : '')
  );
  process.exit(1);
}
console.log(`\nAll ${targets.length} regressions caught (${caught}/${exercised} exercised).`);
