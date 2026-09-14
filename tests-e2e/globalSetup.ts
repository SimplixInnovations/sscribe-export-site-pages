/**
 * Playwright globalSetup — orchestration only.
 *
 * Per the NATIVE WORDPRESS E2E IMPLEMENTATION DIRECTIVE §22:
 *   "Refactor tests-e2e/globalSetup.ts into orchestration only.
 *    It should roughly:
 *      resolve exact ZIP
 *      start runtime
 *      assert readiness
 *      prime test state
 *      return teardown"
 *
 * The runtime is in tests-e2e/runtime/native-wordpress.ts.
 * SSCRIBE_E2E_RUNTIME=native is the only supported value.
 */

import { bootRuntime, resolveRuntimePaths } from './runtime/runtime-config';

export default async function globalSetup(): Promise<void> {
  // Touch paths early so the canonical ZIP gate fires before we boot a runtime.
  const paths = resolveRuntimePaths();
  console.log(`[globalSetup] zip=${paths.sscribeZipPath}`);

  const runtime = await bootRuntime();

  // Wire teardown.
  const teardown = async (): Promise<void> => {
    console.log(`[globalSetup] teardown runtime=${runtime.metadata.runtime}`);
    await runtime.teardown();
  };
  // Playwright calls teardownFn() automatically when globalTeardown is exported.
  (globalThis as unknown as { __sscribeTeardown__?: () => Promise<void> }).__sscribeTeardown__ = teardown;

  console.log(`[globalSetup] ready baseURL=${runtime.baseURL} runtime=${runtime.metadata.runtime} wp=${runtime.metadata.wordpressVersion} db=${runtime.metadata.dbBackend}`);
}

// Playwright's globalSetup is a plain function; teardown is a separate export.
export async function teardown(): Promise<void> {
  const fn = (globalThis as unknown as { __sscribeTeardown__?: () => Promise<void> }).__sscribeTeardown__;
  if (fn) await fn();
}
