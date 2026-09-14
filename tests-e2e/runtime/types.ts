/**
 * E2E runtime abstraction.
 *
 * Defines the narrow contract every WordPress HTTP runtime must satisfy
 * to host the Playwright matrix. Two adapters implement this contract:
 *
 *   - native-wordpress.ts  — release-default runtime (real PHP + real WP + SQLite)
 *   - playground.ts        — optional non-blocking compatibility lane
 *
 * Select via SSCRIBE_E2E_RUNTIME (default: "native").
 *
 * The Playwright specs, helpers, and globalSetup are runtime-agnostic —
 * they consume `baseURL` and `teardown` only.
 */

export interface RuntimeMetadata {
  /** Identifier the runtime reports back to logs/evidence ("native-wordpress" | "playground") */
  runtime: string;
  /** Pinned WordPress version (e.g. "7.1") */
  wordpressVersion: string;
  /** DB backend ("sqlite" | "mariadb" | "mysql") */
  dbBackend: string;
  /** DB integration plugin version (e.g. "2.2.4") or "none" for mysql */
  dbIntegrationVersion: string;
  /** PHP version string */
  phpVersion: string;
  /** Bind address the server is reachable at */
  host: string;
  /** TCP port the server is listening on */
  port: number;
  /** Path to the exact SScribe release ZIP that was installed */
  sscribeZipPath: string;
  /** Path to the runtime directory (debug info; varies per adapter) */
  runtimeDir: string;
}

export interface E2ERuntime {
  /** baseURL the Playwright matrix points at (http://host:port) */
  readonly baseURL: string;
  /** runtime metadata for evidence collection */
  readonly metadata: RuntimeMetadata;
  /**
   * Wait for boot completion and run all readiness assertions. Throws on
   * timeout or assertion failure — Playwright globalSetup must surface
   * the error before tests run.
   */
  waitForReady(): Promise<void>;
  /** Deterministic teardown. Idempotent. */
  teardown(): Promise<void>;
}
