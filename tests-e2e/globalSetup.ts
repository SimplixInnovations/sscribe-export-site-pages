import { stubFsExt } from './helpers/stub-fs-ext.ts';
import { spawn, ChildProcess } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const PLAYGROUND_PORT = 9400;
const PLAYGROUND_HOST = '127.0.0.1';
const BOOT_TIMEOUT_MS = 90_000;
const POLL_INTERVAL_MS = 500;

interface GlobalSetupResult {
  baseURL: string;
  teardown: () => Promise<void>;
}

/**
 * Mu-plugin placeholder map: blueprint template marker → real .php file.
 * Each marker MUST match a `@@MU_PLUGIN_*@@` token in blueprint.json
 * and MUST resolve to an existing .php file on disk. Throws at boot
 * if any file is missing — fail loud.
 *
 * Only mu-plugins with an actual .php file on disk are listed. Test
 * scaffolding for download-token / batch / exec flows is pending — see
 * tests-e2e/SELECTORS.md §23 for the spec roadmap. When those specs land,
 * add their mu-plugin files here AND the matching `writeFile` step in
 * blueprint.json.
 */
const MU_PLUGIN_MAP: ReadonlyArray<readonly [string, string]> = [
  ['@@MU_PLUGIN_PERF_SINK@@', 'sscribe-perf-sink.php'],
];

/**
 * Boots WP-Playground once per Playwright run. Returns the baseURL +
 * a teardown handle. Seed posts via the bundled wp-cli so the perf
 * suite has 50 sample pages to drain.
 */
export default async function globalSetup(): Promise<GlobalSetupResult> {
  stubFsExt();

  const cacheDir = join(process.cwd(), 'tests-e2e', '.cache', 'playground');
  mkdirSync(cacheDir, { recursive: true });
  mkdirSync(join(process.cwd(), 'tests-e2e', '.cache', 'perf'), { recursive: true });

  // Cross-platform staging dir (uses Node's tmpdir() — Windows-safe).
  const stagingDir = join(tmpdir(), 'sscribe-build');
  mkdirSync(stagingDir, { recursive: true });

  // Install exactly the canonical release artifact built for this package
  // version. Never pick the first arbitrary ZIP from dist/, and always
  // overwrite the staging copy so a prior local/CI run cannot leak stale bytes.
  const distDir = join(process.cwd(), 'dist');
  const packageJson = JSON.parse(readFileSync(join(process.cwd(), 'package.json'), 'utf-8')) as {
    version?: string;
  };
  const releaseVersion = String(packageJson.version || '').trim();
  if (!/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(releaseVersion)) {
    throw new Error(`Invalid or missing package.json version for E2E release fixture: "${releaseVersion}"`);
  }

  const canonicalZipPath = join(
    distDir,
    `sscribe-export-site-pages-${releaseVersion}.zip`
  );
  if (!existsSync(canonicalZipPath)) {
    throw new Error(
      `Canonical plugin ZIP not found at ${canonicalZipPath}. Run \`composer release:prepare\` before \`npm run test:e2e\`.`
    );
  }

  const zipPath = join(stagingDir, 'sscribe-export-site-pages.zip');
  writeFileSync(zipPath, readFileSync(canonicalZipPath));

  // Generate the runtime blueprint by substituting mu-plugin placeholders
  // with the real PHP content from disk. This is the ONLY way to get
  // arbitrary PHP into the WP-Playground WASM filesystem (blueprint's
  // `writeFile` data field is the delivery channel; local copyFileSync
  // writes to the host fs which is OUTSIDE the WASM).
  const blueprintTemplatePath = join(process.cwd(), 'tests-e2e', 'fixtures', 'blueprint.json');
  const runtimeBlueprintPath = join(process.cwd(), 'tests-e2e', '.cache', 'blueprint.json');
  let blueprintJson = readFileSync(blueprintTemplatePath, 'utf-8');
  for (const [placeholder, filename] of MU_PLUGIN_MAP) {
    const phpPath = join(process.cwd(), 'tests-e2e', 'fixtures', 'mu-plugins', filename);
    if (!existsSync(phpPath)) {
      throw new Error(
        `globalSetup: mu-plugin ${filename} not found at ${phpPath}. ` +
        `Each mu-plugin listed in MU_PLUGIN_MAP must exist on disk before this testbed runs.`
      );
    }
    const phpContent = readFileSync(phpPath, 'utf-8');
    // JSON.stringify escapes the PHP correctly; we substitute as a raw
    // string and re-parse. WP-Playground's blueprint parser accepts either.
    const escaped = JSON.stringify(phpContent).slice(1, -1); // strip outer quotes
    blueprintJson = blueprintJson.split(placeholder).join(escaped);
  }
  // Also update the installPlugin path to the staging dir.
  blueprintJson = blueprintJson.replace('/sscribe-build/sscribe-export-site-pages.zip', zipPath);
  writeFileSync(runtimeBlueprintPath, blueprintJson, 'utf-8');

  const child = spawn(
    'npx',
    [
      '-y',
      '@wp-playground/cli@3.1.44',
      'serve',
      `--blueprint=${runtimeBlueprintPath}`,
      `--port=${PLAYGROUND_PORT}`,
    ],
    { stdio: ['ignore', 'pipe', 'pipe'], shell: true }
  ) as ChildProcess;

  const baseURL = `http://${PLAYGROUND_HOST}:${PLAYGROUND_PORT}`;
  const start = Date.now();
  while (Date.now() - start < BOOT_TIMEOUT_MS) {
    try {
      const r = await fetch(`${baseURL}/wp-login.php`);
      if (r.status < 500) break;
    } catch {
      // not ready yet
    }
    await new Promise((res) => setTimeout(res, POLL_INTERVAL_MS));
  }
  if (Date.now() - start >= BOOT_TIMEOUT_MS) {
    child.kill('SIGTERM');
    throw new Error(`WP-Playground boot exceeded ${BOOT_TIMEOUT_MS}ms`);
  }

  // Seed 50 sample posts via wp-cli (5 × 10 format combos for perf drain).
  await seedPosts(baseURL, child);

  return {
    baseURL,
    teardown: async () => {
      child.kill('SIGTERM');
      // Give the child up to 5s to exit cleanly.
      await new Promise((res) => setTimeout(res, 5000));
      if (!child.killed) child.kill('SIGKILL');
    },
  };
}

async function seedPosts(baseURL: string, child: ChildProcess): Promise<void> {
  const titles = [
    'Sample Post Alpha', 'Sample Post Beta', 'Sample Post Gamma',
    'Sample Post Delta', 'Sample Post Epsilon',
  ];
  for (const t of titles) {
    for (let i = 0; i < 10; i++) {
      const safeTitle = `${t} ${i + 1}`;
      const cmd = `wp post create --post_title="${safeTitle}" --post_status=publish --post_type=page --porcelain`;
      await new Promise<void>((resolve, reject) => {
        const proc = spawn('npx', ['-y', '@wp-playground/cli@3.1.44', 'cli', `--command=${cmd}`], {
          stdio: ['ignore', 'pipe', 'pipe'],
          shell: true,
        });
        proc.on('exit', (code) => (code === 0 ? resolve() : reject(new Error(`wp-cli exit ${code}`))));
        proc.on('error', reject);
      });
    }
  }
}