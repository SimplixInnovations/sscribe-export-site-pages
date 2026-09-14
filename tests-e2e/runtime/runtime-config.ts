/**
 * Runtime selector.
 *
 * Per the NATIVE WORDPRESS E2E IMPLEMENTATION DIRECTIVE §5:
 *   "Use an environment selector such as SSCRIBE_E2E_RUNTIME=native.
 *    Possible values: native | playground.
 *    Default for release certification: native.
 *    Playground becomes optional/non-blocking compatibility coverage."
 */

import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { E2ERuntime } from './types';
import { NativeWordpressRuntime, createNativeWordpressRuntime } from './native-wordpress';
import { PlaygroundRuntime } from './playground';

export type RuntimeChoice = 'native' | 'playground';

export function resolveRuntimeChoice(): RuntimeChoice {
  const raw = (process.env.SSCRIBE_E2E_RUNTIME || '').toLowerCase().trim();
  if (raw === 'native') return 'native';
  if (raw === 'playground') return 'playground';
  if (raw && raw !== '') {
    // Unknown value: explicit override wins, but surface loudly.
    console.warn(`[runtime-config] unknown SSCRIBE_E2E_RUNTIME="${raw}", expected "native" or "playground", falling back to default "native"`);
  }
  return 'native';
}

export interface RuntimePaths {
  cacheRoot: string;
  sscribeZipPath: string;
}

export function resolveRuntimePaths(): RuntimePaths {
  const cacheRoot = join(process.cwd(), 'tests-e2e', '.cache', 'e2e-wordpress');
  const distDir = join(process.cwd(), 'dist');
  // Read version from package.json so the ZIP path follows the canonical
  // release artifact (matches globalSetup's contract).
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
 * Boot the configured runtime and return a ready-to-use adapter.
 */
export async function bootRuntime(choice?: RuntimeChoice): Promise<E2ERuntime> {
  const which = choice || resolveRuntimeChoice();
  const paths = resolveRuntimePaths();
  console.log(`[runtime-config] booting runtime="${which}" zip=${paths.sscribeZipPath}`);

  if (which === 'native') {
    const runtime = await createNativeWordpressRuntime({
      cacheRoot: paths.cacheRoot,
      sscribeZipPath: paths.sscribeZipPath,
    });
    console.log(`[runtime-config] native-wordpress ready baseURL=${runtime.baseURL}`);
    return runtime;
  }

  // Playground fallback.
  const runtime = new PlaygroundRuntime({ sscribeZipPath: paths.sscribeZipPath });
  await runtime.start();
  await runtime.waitForReady();
  console.log(`[runtime-config] playground ready baseURL=${runtime.baseURL}`);
  return runtime;
}
