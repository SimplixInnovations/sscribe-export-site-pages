import { stubFsExt } from './helpers/stub-fs-ext.ts';
import { spawn, ChildProcess } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync, readFileSync, statSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';

const PLAYGROUND_PORT = 9400;
const PLAYGROUND_HOST = '127.0.0.1';
const BOOT_TIMEOUT_MS = 180_000;
const POLL_INTERVAL_MS = 500;

interface GlobalSetupResult {
  baseURL: string;
  teardown: () => Promise<void>;
}

/**
 * Boots WP-Playground once per Playwright run.
 *
 * Strategy:
 *   1. Resolve the canonical release ZIP from dist/ (matches package.json
 *      version). Bail loudly if missing.
 *   2. Extract the ZIP into a host staging directory.
 *   3. Mount that staging directory to `/wordpress/wp-content/plugins/<slug>`
 *      so the plugin is visible inside the WASM WordPress filesystem.
 *      WordPress auto-detects plugins in the standard wp-content/plugins
 *      directory on first admin load.
 *   4. Boot the locally installed @wp-playground/cli. Inject NODE_OPTIONS
 *      so the CLI child loads our fs-ext stub before its main module
 *      evaluates (Windows + Node v26 native prebuilt missing).
 *   5. Poll `/wp-login.php` until Playground answers sub-500.
 */
export default async function globalSetup(): Promise<GlobalSetupResult> {
  stubFsExt();

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

  writeFileSync(runtimeBlueprintPath, blueprintJson, 'utf-8');

  // Build the child env with NODE_OPTIONS forcing our fs-ext stub.
  const stubPath = join(process.cwd(), 'tests-e2e', 'helpers', 'stub-fs-ext.cjs');
  const childEnv = {
    ...process.env,
    NODE_OPTIONS: `--require ${stubPath}${process.env.NODE_OPTIONS ? ' ' + process.env.NODE_OPTIONS : ''}`,
  } as NodeJS.ProcessEnv;

  // Use the locally installed CLI — `@wp-playground/cli` is declared in
  // package.json devDependencies and pinned in package-lock.json. Going
  // through `npx -y @wp-playground/cli@<version>` re-downloads the same
  // package every run and risks version drift.
  const cliEntry = join(process.cwd(), 'node_modules', '@wp-playground', 'cli', 'wp-playground.js');
  if (!existsSync(cliEntry)) {
    throw new Error(
      `WP-Playground CLI not installed at ${cliEntry}. Run \`npm ci\` (or \`npm install\`) before \`npm run test:e2e\`.`
    );
  }

  // WP-Playground mounts use yargs path quoting — args must be passed as
  // separate strings, not joined. Use array form (no shell) to avoid
  // Git-Bash path-mangling (the C:/... prefix issue from memory).
  const vfsMountPath = `wordpress/wp-content/plugins/${pluginSlug}`;
  const child = spawn(
    process.execPath,
    [
      cliEntry,
      'server',
      `--blueprint=${runtimeBlueprintPath}`,
      `--port=${PLAYGROUND_PORT}`,
      // Mount BEFORE WP install so the plugin file is visible during
      // WP-Playground's worker spawn. After-install mounts in WP-Playground
      // are re-applied per-worker via applyPostInstallMountsToAllWorkers,
      // but in practice the file mount on Windows doesn't propagate to
      // subsequent worker requests reliably. A before-install mount lands
      // in the initial VFS state and stays.
      `--mount-dir-before-install`,
      stagedPluginDir,
      vfsMountPath,
    ],
    { stdio: ['ignore', 'pipe', 'pipe'], env: childEnv }
  ) as ChildProcess;

  // Forward child output to this process so the boot log captures
  // install-progress and any crash output.
  child.stdout?.on('data', (d) => process.stdout.write(`[wppg] ${d}`));
  child.stderr?.on('data', (d) => process.stderr.write(`[wppg] ${d}`));

  const baseURL = `http://${PLAYGROUND_HOST}:${PLAYGROUND_PORT}`;
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
    child.kill('SIGTERM');
    throw new Error(`WP-Playground boot exceeded ${BOOT_TIMEOUT_MS}ms (last seen status: ${lastStatus})`);
  }
  // eslint-disable-next-line no-console
  console.log(`[globalSetup] WP-Playground booted in ${Date.now() - start}ms (last status: ${lastStatus})`);

  return {
    baseURL,
    teardown: async () => {
      child.kill('SIGTERM');
      await new Promise((res) => setTimeout(res, 5000));
      if (!child.killed) child.kill('SIGKILL');
    },
  };
}
