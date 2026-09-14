/**
 * Playwright globalTeardown — stops the runtime booted by globalSetup.
 *
 * Pair of tests-e2e/globalSetup.ts. Reads the teardown function stashed on
 * globalThis by globalSetup.
 */
export default async function globalTeardown(): Promise<void> {
  const fn = (globalThis as unknown as { __sscribeTeardown__?: () => Promise<void> }).__sscribeTeardown__;
  if (fn) {
    await fn();
    (globalThis as unknown as { __sscribeTeardown__?: () => Promise<void> }).__sscribeTeardown__ = undefined;
  }
}
