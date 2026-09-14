/**
 * Runtime selector — native WordPress only.
 *
 * WP-Playground was removed as the release E2E backend. The native
 * WordPress HTTP runtime (real PHP + real WP + SQLite) is the sole
 * release-certification runtime. Historical Playground investigation
 * remains in repository history.
 *
 * Select via SSCRIBE_E2E_RUNTIME=native (default; only accepted value).
 */

import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { E2ERuntime } from './types';
import { createNativeWordpressRuntime } from './native-wordpress';

export interface RuntimePaths {
  cacheRoot: string;
  sscribeZipPath: string;
}

export function resolveRuntimePaths(): RuntimePaths {
  const cacheRoot = join(process.cwd(), 'tests-e2e', '.cache', 'e2e-wordpress');
  const distDir = join(process.cwd(), 'dist');
  const pkg = JSON.parse(readFileSync(join(process.cwd(), 'package.json'), 'utf-8')) as { version?: string };
  const v = String(pkg.version || '').trim();
  if (!/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(v)) {
    throw new Error(`Invalid package.json version for E2E runtime: "${v}"`);
  }
  const sscribeZipPath = join(distDir, `sscribe-export-site-pages-${v}.zip`);
  if (!existsSync(sscribeZipPath)) {
    throw new Error(
      `Canonical SScribe release ZIP not found at ${sscribeZipPath}. ` +
      `Run 'composer release:prepare' before 'npm run test:e2e'.`
    );
  }
  return { cacheRoot, sscribeZipPath };
}

/**
 * Boot the native WordPress runtime and return a ready-to-use adapter.
 *
 * SSCRIBE_E2E_RUNTIME is accepted for backward compatibility but only
 * "native" is recognized. Any other value logs a warning and proceeds
 * with native.
 */
export async function bootRuntime(): Promise<E2ERuntime> {
  const raw = (process.env.SSCRIBE_E2E_RUNTIME || '').toLowerCase().trim();
  if (raw && raw !== 'native') {
    console.warn(
      `[runtime-config] SSCRIBE_E2E_RUNTIME="${raw}" is not recognized. ` +
      `WP-Playground was removed; using native runtime.`
    );
  }

  const paths = resolveRuntimePaths();
  console.log(`[runtime-config] booting runtime="native" zip=${paths.sscribeZipPath}`);

  const runtime = await createNativeWordpressRuntime({
    cacheRoot: paths.cacheRoot,
    sscribeZipPath: paths.sscribeZipPath,
  });
  console.log(`[runtime-config] native-wordpress ready baseURL=${runtime.baseURL}`);
  return runtime;
}
