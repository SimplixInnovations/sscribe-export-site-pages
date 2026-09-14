/**
 * Playground runtime adapter — optional non-blocking compatibility lane.
 *
 * Per the NATIVE WORDPRESS E2E IMPLEMENTATION DIRECTIVE (2026-09-12) §31:
 * "Do not commit the 3.1.53 change now. Once native E2E works, decide
 * whether to retain Playground as an optional compatibility lane.
 * If retained: upgrade it in a separate commit, pin 3.1.53 or later
 * reviewed version, document that it is non-blocking."
 *
 * Until native E2E is green and the architectural decision is made, this
 * adapter preserves the existing globalSetup.ts behavior as a fallback
 * when SSCRIBE_E2E_RUNTIME=playground is explicitly requested.
 */

import { runCLI, type RunCLIServer } from '@wp-playground/cli';
import { existsSync, readFileSync, writeFileSync, mkdirSync, statSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { E2ERuntime, RuntimeMetadata } from './types';

const PLAYGROUND_PORT = 9400;
const PLAYGROUND_HOST = '127.0.0.1';
const BOOT_TIMEOUT_MS = 180_000;
const POLL_INTERVAL_MS = 500;
const EXPECTED_PAGES = 50;

const plugin_slug_check = 'sscribe-export-site-pages';

function log(line: string): void {
  console.log(`[playground ${new Date().toISOString()}] ${line}`);
}

interface GlobalSetupResult {
  baseURL: string;
  serverUrl: string;
  teardown: () => Promise<void>;
}

export class PlaygroundRuntime implements E2ERuntime {
  public readonly baseURL: string;
  public readonly metadata: RuntimeMetadata;
  private cliServer: RunCLIServer | null = null;
  private teardownFn: (() => Promise<void>) | null = null;

  constructor(opts: { sscribeZipPath: string; port?: number }) {
    this.baseURL = `http://${PLAYGROUND_HOST}:${opts.port || PLAYGROUND_PORT}`;
    this.metadata = {
      runtime: 'playground',
      wordpressVersion: 'unknown',
      dbBackend: 'sqlite-wasm',
      dbIntegrationVersion: 'wasm',
      phpVersion: '8.3',
      host: PLAYGROUND_HOST,
      port: opts.port || PLAYGROUND_PORT,
      sscribeZipPath: opts.sscribeZipPath,
      runtimeDir: '(wp-playground-wasm)',
    };
  }

  async waitForReady(): Promise<void> {
    if ( ! this.cliServer ) {
      throw new Error('PlaygroundRuntime.waitForReady called before start');
    }
    // The original globalSetup's polling logic is preserved verbatim.
    const start = Date.now();
    let lastStatus: number | null = null;
    while ( Date.now() - start < BOOT_TIMEOUT_MS ) {
      try {
        const r = await fetch(`${this.baseURL}/wp-login.php`);
        lastStatus = r.status;
        if ( r.status < 500 ) break;
      } catch {
        // not ready yet
      }
      await new Promise((res) => setTimeout(res, POLL_INTERVAL_MS));
    }
    if ( Date.now() - start >= BOOT_TIMEOUT_MS ) {
      throw new Error(`WP-Playground boot exceeded ${BOOT_TIMEOUT_MS}ms (last seen status: ${lastStatus})`);
    }
    const bootReport = await this.assertBootState();
    log(`boot-state OK: pages=${bootReport.pages} plugin_active=${bootReport.plugin_active} admin_status=${bootReport.admin_status}`);
    try {
      const cancel = await fetch(`${this.baseURL}/?ssb_test_cancel_all=1`);
      const reset = await fetch(`${this.baseURL}/?ssb_test_reset=1`);
      log(`pre-test priming: cancel-all status=${cancel.status} reset status=${reset.status}`);
    } catch (e) {
      log(`pre-test priming failed (non-fatal): ${(e as Error).message}`);
    }
  }

  async teardown(): Promise<void> {
    if ( this.teardownFn ) {
      await this.teardownFn();
      this.teardownFn = null;
      this.cliServer = null;
    }
  }

  /**
   * Start via the existing globalSetup flow. Mirrors the original
   * tests-e2e/globalSetup.ts verbatim to preserve behavior.
   */
  async start(): Promise<GlobalSetupResult> {
    const cacheDir = join(process.cwd(), 'tests-e2e', '.cache', 'playground');
    mkdirSync(cacheDir, { recursive: true });

    const distDir = join(process.cwd(), 'dist');
    const packageJson = JSON.parse(readFileSync(join(process.cwd(), 'package.json'), 'utf-8')) as { version?: string };
    const releaseVersion = String(packageJson.version || '').trim();
    if (!/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(releaseVersion)) {
      throw new Error(`Invalid package.json version: "${releaseVersion}"`);
    }

    const canonicalZipPath = join(distDir, `sscribe-export-site-pages-${releaseVersion}.zip`);
    if (!existsSync(canonicalZipPath)) {
      throw new Error(`Canonical plugin ZIP not found at ${canonicalZipPath}.`);
    }

    const pluginSlug = 'sscribe-export-site-pages';
    const stagingDir = join(tmpdir(), 'sscribe-plugin-staging');
    const stagedPluginDir = join(stagingDir, pluginSlug);
    const stagedPluginMainFile = join(stagedPluginDir, 'sscribe-export-site-pages.php');
    const zipPath = join(stagingDir, `sscribe-export-site-pages-${releaseVersion}.zip`);
    mkdirSync(stagingDir, { recursive: true });
    writeFileSync(zipPath, readFileSync(canonicalZipPath));
    const zipMtime = statSync(canonicalZipPath).mtimeMs;
    const needsReExtract = !existsSync(stagedPluginMainFile) || statSync(stagedPluginMainFile).mtimeMs < zipMtime;
    if (needsReExtract) {
      rmSync(stagedPluginDir, { recursive: true, force: true });
      mkdirSync(stagedPluginDir, { recursive: true });
      const { execFileSync } = await import('node:child_process');
      execFileSync('unzip', ['-q', '-o', zipPath, '-d', stagingDir], { stdio: ['ignore', 'pipe', 'pipe'] });
    }

    const blueprintTemplatePath = join(process.cwd(), 'tests-e2e', 'fixtures', 'blueprint.json');
    const muPluginMap: ReadonlyArray<readonly [string, string]> = [
      ['@@MU_PLUGIN_PERF_SINK@@', 'sscribe-perf-sink.php'],
      ['@@MU_PLUGIN_SSCRIBE_BOOTSTRAP@@', '00-sscribe-test-bootstrap.php'],
    ];
    let blueprintJson = readFileSync(blueprintTemplatePath, 'utf-8');
    for (const [placeholder, filename] of muPluginMap) {
      const phpPath = join(process.cwd(), 'tests-e2e', 'fixtures', 'mu-plugins', filename);
      const phpContent = readFileSync(phpPath, 'utf-8');
      const escaped = JSON.stringify(phpContent).slice(1, -1);
      blueprintJson = blueprintJson.split(placeholder).join(escaped);
    }
    const blueprint = JSON.parse(blueprintJson) as Record<string, unknown>;
    writeFileSync(join(cacheDir, 'blueprint.json'), blueprintJson, 'utf-8');

    const cliServer: RunCLIServer = await runCLI({
      command: 'server',
      blueprint,
      port: this.metadata.port,
      'mount-before-install': [
        {
          hostPath: stagedPluginDir,
          vfsPath: `wordpress/wp-content/plugins/${pluginSlug}`,
        },
      ],
      quiet: true,
      skipBrowser: true,
    });

    this.cliServer = cliServer;
    this.teardownFn = async () => {
      await cliServer[Symbol.asyncDispose]();
    };
    return {
      baseURL: this.baseURL,
      serverUrl: cliServer.serverUrl,
      teardown: this.teardownFn,
    };
  }

  private async assertBootState(baseURL: string = this.baseURL): Promise<{ pages: number; plugin_active: boolean; admin_status: number }> {
    const canaryResp = await fetch(`${baseURL}/wp-content/uploads/canary.txt`);
    if (canaryResp.status !== 200) {
      throw new Error(`boot-state: canary file missing (status ${canaryResp.status})`);
    }
    const canaryText = await canaryResp.text();
    const pagesMatch = canaryText.match(/pages=(\d+)/);
    const activeMatch = canaryText.match(/active=(.+)/);
    const pages = pagesMatch ? parseInt(pagesMatch[1], 10) : 0;
    const plugin_active = activeMatch ? activeMatch[1].includes(plugin_slug_check) : false;
    if (pages < EXPECTED_PAGES) {
      throw new Error(`boot-state: expected at least ${EXPECTED_PAGES} pages, found ${pages}`);
    }
    const adminResp = await fetch(`${baseURL}/wp-admin/admin.php?page=sscribe-export`, { redirect: 'manual' });
    const admin_status = adminResp.status;
    if (admin_status >= 500) {
      throw new Error(`boot-state: admin page returned ${admin_status}`);
    }
    return { pages, plugin_active, admin_status };
  }
}
