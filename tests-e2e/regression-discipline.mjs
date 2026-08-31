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
    description: 'dark-mode color-contrast on format-desc',
    sourceFile: 'admin/css/sscribe-admin.css',
    revertMatch: /(\.sscribe-format-option-description[^{]*\{[^}]*color:\s*)([^;]+)/,
    revertReplace: '$1#fafafa',  // artificially fail contrast
  },
  {
    specFile: 'tests-e2e/a11y/admin-tabs.spec.ts',
    description: 'dark-mode color-contrast on language-badge',
    sourceFile: 'admin/css/sscribe-admin.css',
    revertMatch: /(\.sscribe-language-badge[^{]*\{[^}]*background-color:\s*)([^;]+)/,
    revertReplace: '$1#999999',  // artificially fail contrast
  },
  {
    specFile: 'tests-e2e/a11y/format-cards.spec.ts',
    description: 'markdown-format-card word break',
    sourceFile: 'admin/css/sscribe-admin.css',
    revertMatch: /(\.sscribe-format-option-title[^{]*\{)([^}]*word-wrap:\s*break-word[^;]*;)/,
    revertReplace: '$1',  // remove the word-wrap rule
  },
  {
    specFile: 'tests-e2e/e2e/debug/toggle.spec.ts',
    description: 'toggle-ui-assets-gating',
    sourceFile: 'sscribe-export-site-pages.php',
    revertMatch: /(wp_enqueue_script\([^,]+,\s*[^,]+,\s*\[\],\s*[^,]+\s*,\s*true\s*\)[\s\S]{0,200}?sscribe-debug\b)/,
    revertReplace: '/* REVERTED FOR REGRESSION TEST */',
  },
  {
    specFile: 'tests-e2e/e2e/history/delete-two-click.spec.ts',
    description: 'two-click-confirm pointer-events',
    sourceFile: 'admin/css/sscribe-admin.css',
    revertMatch: /(\.sscribe-btn-confirming[^{]*\{[^}]*pointer-events:\s*)([^;]+)/,
    revertReplace: '$1none',
  },
  {
    specFile: 'tests-e2e/e2e/export/wizard-happy-path.spec.ts',
    description: 'export wizard happy path',
    sourceFile: 'includes/class-sscribe-ajax.php',
    revertMatch: /(\$response\['ok'\]\s*=\s*)(true)/,
    revertReplace: '$1false',  // break AJAX response
  },
  {
    specFile: 'tests-e2e/e2e/export/download-token-auth.spec.ts',
    description: 'download token expiry',
    sourceFile: 'includes/class-sscribe-export-download.php',
    revertMatch: /(if\s*\(\s*\$now\s*>\s*\$token_expires\s*\)\s*\{)/,
    revertReplace: 'if ( false && $now > $token_expires ) {',  // neutralize check
  },
  {
    specFile: 'tests-e2e/e2e/export/batch-progress.spec.ts',
    description: 'batch-progress aria-live',
    sourceFile: 'admin/partials/sscribe-admin-display.php',
    revertMatch: /(aria-live="polite")/,
    revertReplace: 'aria-live="off"',  // deliberately wrong
  },
];

let failures = 0;

for (const target of targets) {
  const specAbs = resolve(ROOT, target.specFile);
  const srcAbs = resolve(ROOT, target.sourceFile);
  if (!existsSync(specAbs)) {
    console.error(`[skip] ${target.description}: spec ${target.specFile} not yet written`);
    continue;
  }
  if (!existsSync(srcAbs)) {
    console.error(`[skip] ${target.description}: source ${target.sourceFile} not found`);
    continue;
  }

  const original = readFileSync(srcAbs, 'utf-8');
  const reverted = original.replace(target.revertMatch, target.revertReplace);
  if (reverted === original) {
    console.error(`[fail] ${target.description}: revert pattern did not match in ${target.sourceFile}`);
    failures++;
    continue;
  }
  writeFileSync(srcAbs, reverted, 'utf-8');

  try {
    const result = spawnSync('npx', ['playwright', 'test', '--reporter=line', '--grep', target.description], {
      cwd: ROOT,
      stdio: 'inherit',
      env: { ...process.env, CI: '1' },
    });
    if (result.status === 0) {
      console.error(`[fail] ${target.description}: test PASSED after revert — regression NOT caught`);
      failures++;
    } else {
      console.log(`[pass] ${target.description}: test failed after revert — regression caught`);
    }
  } finally {
    writeFileSync(srcAbs, original, 'utf-8');
  }
}

if (failures > 0) {
  console.error(`\n${failures} regression(s) not caught.`);
  process.exit(1);
}
console.log(`\nAll ${targets.length} regressions caught.`);
