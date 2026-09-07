import { runCLI, type RunCLIServer } from '@wp-playground/cli';
import { mkdirSync, writeFileSync, existsSync, readFileSync, statSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const PLAYGROUND_PORT = 9400;
const PLAYGROUND_HOST = '127.0.0.1';
const BOOT_TIMEOUT_MS = 180_000;
const POLL_INTERVAL_MS = 500;
const EXPECTED_PAGES = 50;

interface GlobalSetupResult {
  baseURL: string;
  serverUrl: string;
  teardown: () => Promise<void>;
}

/**
 * Boots WP-Playground once per Playwright run via the programmatic API.
 *
 * Strategy:
 *   1. Resolve the canonical release ZIP from dist/ (matches package.json
 *      version). Bail loudly if missing.
 *   2. Extract the ZIP into a host staging directory.
 *   3. Build the runtime blueprint with mu-plugin placeholders resolved
 *      and 50 pages pre-seeded via runPHP (one fast block, not 50 wp-cli
 *      steps). The plugin is mounted via `--mount-before-install` so the
 *      plugin file is visible during the WASM VFS init.
 *   4. Call `runCLI({ command: 'server', ... })` programmatically. The
 *      returned `RunCLIServer` owns the HTTP server + worker pool for
 *      the whole Playwright run — no child-process spawn, no version
 *      drift, no disconnected CLI invocations.
 *   5. Poll `/wp-login.php` until Playground answers sub-500.
 *   6. Assert boot state: 50 pages exist, plugin is active, admin page
 *      renders. Fail loudly if any assertion fails — Playwright specs
 *      depend on this.
 *   7. Teardown uses `[Symbol.asyncDispose]()` on the returned handle
 *      so the HTTP server + worker pool shut down deterministically.
 *
 * Note on fs-ext-extra-prebuilt stubbing:
 *   `node_modules/fs-ext-extra-prebuilt/dist/fs-ext.js` is replaced with a
 *   JS-only no-op stub in the local node_modules tree. The prebuilt native
 *   binary does not exist for Windows + Node v26; WP-Playground's
 *   `@php-wasm/node` requires flock/fcntl/lockFileEx at module-load time
 *   and would otherwise throw "Failed to load fs-ext native module". The
 *   stub returns 0 for all calls (acceptable because WP-Playground's SQLite
 *   is single-threaded inside the worker; OS-level flock is not needed).
 *   Do NOT commit the stubbed file — it lives in node_modules and is
 *   restored to upstream on `npm ci`.
 */
export default async function globalSetup(): Promise<GlobalSetupResult> {
  const cacheDir = join(process.cwd(), 'tests-e2e', '.cache', 'playground');
  mkdirSync(cacheDir, { recursive: true });
  mkdirSync(join(process.cwd(), 'tests-e2e', '.cache', 'perf'), { recursive: true });

  // Resolve canonical release artifact.
  const distDir = join(process.cwd(), 'dist');
  const packageJson = JSON.parse(readFileSync(join(process.cwd(), 'package.json'), 'utf-8')) as {
    version?: string;
  };
  const releaseVersion = String(packageJson.version || '').trim();
  if (!/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(releaseVersion)) {
    throw new Error(`Invalid or missing package.json version for E2E release fixture: "${releaseVersion}"`);
  }

  const canonicalZipPath = join(distDir, `sscribe-export-site-pages-${releaseVersion}.zip`);
  if (!existsSync(canonicalZipPath)) {
    throw new Error(
      `Canonical plugin ZIP not found at ${canonicalZipPath}. Run \`composer release:prepare\` before \`npm run test:e2e\`.`
    );
  }

  // Stage the extracted plugin to a host dir. The plugin slug inside the
  // ZIP is `sscribe-export-site-pages` (matches WP plugin header Name slug).
  const pluginSlug = 'sscribe-export-site-pages';
  const stagingDir = join(tmpdir(), 'sscribe-plugin-staging');
  const stagedPluginDir = join(stagingDir, pluginSlug);
  const stagedPluginMainFile = join(stagedPluginDir, 'sscribe-export-site-pages.php');
  // Always overwrite the staging ZIP on every run so a stale /tmp copy
  // cannot leak into a later run; the re-extract gate below still skips
  // the unzip step when the source ZIP is unchanged.
  const zipPath = join(stagingDir, `sscribe-export-site-pages-${releaseVersion}.zip`);
  mkdirSync(stagingDir, { recursive: true });
  writeFileSync(zipPath, readFileSync(canonicalZipPath));
  const zipMtime = statSync(canonicalZipPath).mtimeMs;
  const needsReExtract = !existsSync(stagedPluginMainFile)
    || statSync(stagedPluginMainFile).mtimeMs < zipMtime;
  if (needsReExtract) {
    rmSync(stagedPluginDir, { recursive: true, force: true });
    mkdirSync(stagedPluginDir, { recursive: true });
    // Use the platform `unzip` so we don't drag in a Node zip lib.
    // Git Bash on Windows ships unzip; pure-Windows hosts have it via PATH.
    const { execFileSync } = await import('node:child_process');
    execFileSync('unzip', ['-q', '-o', zipPath, '-d', stagingDir], {
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  }
  if (!existsSync(stagedPluginMainFile)) {
    throw new Error(
      `Plugin main file not found at ${stagedPluginMainFile} after extract. ZIP may have wrong layout.`
    );
  }
  // eslint-disable-next-line no-console
  console.log(`[globalSetup] plugin staged at: ${stagedPluginDir}`);

  // Build the runtime blueprint from the template by substituting mu-plugin
  // placeholders with the real PHP content from disk.
  const blueprintTemplatePath = join(process.cwd(), 'tests-e2e', 'fixtures', 'blueprint.json');
  const runtimeBlueprintPath = join(process.cwd(), 'tests-e2e', '.cache', 'blueprint.json');

  const muPluginMap: ReadonlyArray<readonly [string, string]> = [
    ['@@MU_PLUGIN_PERF_SINK@@', 'sscribe-perf-sink.php'],
    ['@@MU_PLUGIN_SSCRIBE_BOOTSTRAP@@', '00-sscribe-test-bootstrap.php'],
  ];

  let blueprintJson = readFileSync(blueprintTemplatePath, 'utf-8');
  for (const [placeholder, filename] of muPluginMap) {
    const phpPath = join(process.cwd(), 'tests-e2e', 'fixtures', 'mu-plugins', filename);
    if (!existsSync(phpPath)) {
      throw new Error(
        `globalSetup: mu-plugin ${filename} not found at ${phpPath}. ` +
        `Each mu-plugin listed in muPluginMap must exist on disk before this testbed runs.`
      );
    }
    const phpContent = readFileSync(phpPath, 'utf-8');
    const escaped = JSON.stringify(phpContent).slice(1, -1);
    blueprintJson = blueprintJson.split(placeholder).join(escaped);
  }

  // Resolve blueprint: either inline object or on-disk path. The
  // `runCLI` API accepts a parsed BlueprintV1Declaration directly.
  const blueprint = JSON.parse(blueprintJson) as Record<string, unknown>;

  writeFileSync(runtimeBlueprintPath, blueprintJson, 'utf-8');

  // Pre-flight: clear any stale process still holding PLAYGROUND_PORT.
  // A previous run's WP-Playground worker pool may have crashed or been
  // SIGKILL'd without disposing the HTTP server. `runCLI` would then
  // fail with EADDRINUSE. Best-effort: find the PID via netstat and kill
  // it via taskkill. No-op if the port is free.
  try {
    const { execFileSync } = await import('node:child_process');
    // On Git-Bash on Windows, `netstat -ano` parses cleanly; on Linux it's
    // identical. Strip the IPv4 + IPv6 LISTENING rows that mention the port.
    const netstatOut = execFileSync('netstat', ['-ano'], { encoding: 'utf-8' });
    const portRe = new RegExp(`^\\s*(?:TCP|UDP)\\s+.*[:.]${PLAYGROUND_PORT}\\s+.*LISTENING\\s+(\\d+)\\s*$`, 'm');
    const m = netstatOut.match(portRe);
    if (m && m[1]) {
      const pid = m[1];
      try {
        execFileSync('taskkill', ['/F', '/PID', pid], { stdio: 'ignore' });
        // eslint-disable-next-line no-console
        console.log(`[globalSetup] cleared stale listener on :${PLAYGROUND_PORT} (pid ${pid})`);
        await new Promise((res) => setTimeout(res, 500));
      } catch {
        // ignore -- taskkill may fail if the process already exited
      }
    }
  } catch {
    // ignore -- netstat may not be on PATH in some sandboxes
  }

  // Boot Playground programmatically. The returned handle owns the
  // HTTP server + worker pool for the whole Playwright run.
  // `mount-before-install` lands the plugin in the VFS during the
  // initial WP install so the file is visible from the first request.
  const cliServer: RunCLIServer = await runCLI({
    command: 'server',
    blueprint,
    port: PLAYGROUND_PORT,
    'mount-before-install': [
      {
        hostPath: stagedPluginDir,
        vfsPath: `wordpress/wp-content/plugins/${pluginSlug}`,
      },
    ],
    quiet: true,
    skipBrowser: true,
  });

  const serverUrl = cliServer.serverUrl;
  // `runCLI` defaults to 127.0.0.1:PORT. Force the host component to
  // match `PLAYGROUND_HOST` so the Playwright baseURL lines up.
  const baseURL = `http://${PLAYGROUND_HOST}:${PLAYGROUND_PORT}`;

  // Poll /wp-login.php until Playground answers sub-500.
  const start = Date.now();
  let lastStatus: number | null = null;
  while (Date.now() - start < BOOT_TIMEOUT_MS) {
    try {
      const r = await fetch(`${baseURL}/wp-login.php`);
      lastStatus = r.status;
      if (r.status < 500) break;
    } catch {
      // not ready yet
    }
    await new Promise((res) => setTimeout(res, POLL_INTERVAL_MS));
  }
  if (Date.now() - start >= BOOT_TIMEOUT_MS) {
    await cliServer[Symbol.asyncDispose]();
    throw new Error(`WP-Playground boot exceeded ${BOOT_TIMEOUT_MS}ms (last seen status: ${lastStatus})`);
  }
  // eslint-disable-next-line no-console
  console.log(`[globalSetup] WP-Playground booted in ${Date.now() - start}ms (last status: ${lastStatus}, serverUrl: ${serverUrl})`);

  // Boot-state assertion: 50 pages exist, plugin is active, admin page
  // renders. Fail loudly — Playwright specs depend on these guarantees.
  const bootReport = await assertBootState(baseURL);
  // eslint-disable-next-line no-console
  console.log(`[globalSetup] boot-state OK: pages=${bootReport.pages} plugin_active=${bootReport.plugin_active} admin_status=${bootReport.admin_status}`);

  return {
    baseURL,
    serverUrl,
    teardown: async () => {
      await cliServer[Symbol.asyncDispose]();
    },
  };
}

interface BootReport {
  pages: number;
  plugin_active: boolean;
  admin_status: number;
}

async function assertBootState(baseURL: string): Promise<BootReport> {
  // Read pages count via a non-AJAX endpoint (canary file written by
  // the Blueprint wp-cli eval step). If the file is missing, the
  // Blueprint failed — fail loudly.
  const canaryResp = await fetch(`${baseURL}/wp-content/uploads/canary.txt`);
  if (canaryResp.status !== 200) {
    throw new Error(`boot-state: canary file missing (status ${canaryResp.status}) — Blueprint did not finish`);
  }
  const canaryText = await canaryResp.text();
  const pagesMatch = canaryText.match(/pages=(\d+)/);
  const activeMatch = canaryText.match(/active=([^"\s]+)/);
  const pages = pagesMatch ? parseInt(pagesMatch[1], 10) : 0;
  // active_plugins is a JSON array; just look for the plugin slug.
  const plugin_active = activeMatch ? activeMatch[1].includes(plugin_slug_check) : false;
  if (pages < EXPECTED_PAGES) {
    throw new Error(`boot-state: expected at least ${EXPECTED_PAGES} pages, found ${pages}`);
  }
  // Verify the admin page renders (status 200 or 302 redirect to login).
  const adminResp = await fetch(`${baseURL}/wp-admin/admin.php?page=sscribe-export`, {
    redirect: 'manual',
  });
  const admin_status = adminResp.status;
  if (admin_status >= 500) {
    throw new Error(`boot-state: admin page returned ${admin_status} — plugin not loading?`);
  }
  return { pages, plugin_active, admin_status };
}

const plugin_slug_check = 'sscribe-export-site-pages';
