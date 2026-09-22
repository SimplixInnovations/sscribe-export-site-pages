/**
 * Native WordPress HTTP runtime adapter.
 *
 * Per the NATIVE WORDPRESS E2E IMPLEMENTATION DIRECTIVE (2026-09-12), this
 * replaces WP-Playground as the release-critical backend.
 *
 * Lifecycle:
 *   1. destroy stale runtime
 *   2. create fresh runtime at .cache/e2e-wordpress/
 *   3. install WordPress (pinned version, sha256-checked tarball)
 *   4. install SQLite Database Integration drop-in
 *   5. install exact SScribe release ZIP (NOT a source mount)
 *   6. install test mu-plugins
 *   7. write wp-config.php with SQLite + testbed constants
 *   8. PHP bootstrap: wp_install, create admin, set permalink, seed 50 pages,
 *      activate SScribe, flush rewrite, write canary
 *   9. spawn `php -S` with router script
 *  10. readiness probe (state-based, no fixed sleeps)
 *  11. teardown: graceful stop + force-kill fallback + dir removal
 *
 * Cross-platform: spawn/terminate via Node child_process. No reliance on
 * taskkill/netstat inside the adapter itself.
 */

import { spawn, type ChildProcess, execFileSync, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
  existsSync,
  mkdirSync,
  readFileSync,
  writeFileSync,
  rmSync,
  statSync,
  createWriteStream,
  readdirSync,
  copyFileSync,
  renameSync,
} from 'node:fs';
import { join, resolve } from 'node:path';
import { tmpdir, platform } from 'node:os';
import { E2ERuntime, RuntimeMetadata } from './types';

// ---- Pinned inputs ---------------------------------------------------------------

const PINNED_WORDPRESS_VERSION = '7.1.1';
const PINNED_SQLITE_INTEGRATION_VERSION = '3.0.2';

const EXPECTED_SEED_PAGES = 50;
const BOOT_TIMEOUT_MS = 180_000;
const POLL_INTERVAL_MS = 500;
const READINESS_BODY_PROBE_TIMEOUT_MS = 30_000;

// ---- Helpers --------------------------------------------------------------------

function nowIso(): string {
  return new Date().toISOString();
}

function log(line: string): void {
  console.log(`[native-wordpress ${nowIso()}] ${line}`);
}

function sha256(buf: Buffer | string): string {
  return createHash('sha256').update(buf).digest('hex');
}

function extractTarGz(tarball: string, destDir: string): void {
  // Use Python's tarfile to be MSYS / Git-Bash safe (same idiom as
  // bin/install-wp-tests.sh). This avoids the native-tar C: host landmine
  // (see memory: git-bash-native-tar-c-host-landmine.md).
  const python = process.env.PYTHON_BIN || 'python';
  const code = spawnSync(python, ['-c', 'import sys, tarfile; tarfile.open(sys.argv[1]).extractall(sys.argv[2])', tarball, destDir], { stdio: 'inherit' }).status;
  if (code !== 0) {
    throw new Error(`tar extract failed (exit ${code}) for ${tarball}`);
  }
}

function extractZip(zipPath: string, destDir: string): void {
  // Use the system `unzip` (Git Bash ships it). Falls back to PowerShell on
  // pure Windows. `adm-zip` would be nicer but it's not a dep and we don't
  // want to add one to the release-gate toolchain just for this.
  try {
    execFileSync('unzip', ['-q', '-o', zipPath, '-d', destDir], { stdio: ['ignore', 'pipe', 'pipe'] });
  } catch {
    execFileSync('powershell', ['-NoProfile', '-Command',
      `Expand-Archive -Path "${zipPath}" -DestinationPath "${destDir}" -Force`
    ], { stdio: 'pipe' });
  }
}

function downloadTo(url: string, destPath: string): void {
  // Use curl for parity with bin/install-wp-tests.sh. Falls back to PowerShell.
  try {
    execFileSync('curl', ['-fsSL', '-o', destPath, url], { stdio: ['ignore', 'pipe', 'pipe'] });
  } catch (e) {
    try {
      execFileSync('powershell', ['-NoProfile', '-Command',
        `(New-Object System.Net.WebClient).DownloadFile('${url}', '${destPath}')`
      ], { stdio: 'pipe' });
    } catch {
      throw new Error(`Failed to download ${url}`);
    }
  }
}

function copyDirRecursive(src: string, dest: string): void {
  mkdirSync(dest, { recursive: true });
  // robocopy on Windows, cp -r elsewhere. Both treat dest as "copy into".
  if (platform() === 'win32') {
    // robocopy returns non-zero exit codes even on success (1=files copied,
    // 2=extras, etc. — codes 0-7 are success). Treat any exit <= 7 as OK.
    const result = spawnSync('robocopy', [src, dest, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NC', '/NS'], { stdio: 'pipe' });
    if (result.status === null || result.status >= 8) {
      throw new Error(`robocopy failed (exit ${result.status}) copying ${src} -> ${dest}: ${result.stderr?.toString() || ''}`);
    }
  } else {
    execFileSync('cp', ['-r', src + '/.', dest + '/'], { stdio: 'pipe' });
  }
}

// ---- Bootstrap script (PHP) -----------------------------------------------------

const PHP_BOOTSTRAP = `<?php
/**
 * Native WordPress E2E bootstrap. Loaded via wp-config.php's
 * SSCRIBE_E2E_BOOTSTRAP_FILE include. Runs ONCE on the first request
 * after install. Subsequent requests short-circuit at the top.
 *
 * Responsibilities (per directive §10):
 *   - wp_install (if not yet installed)
 *   - create admin user (admin / password)
 *   - set permalink_structure to /%postname%/
 *   - seed 50 published pages (post_type=page)
 *   - activate SScribe plugin
 *   - flush rewrite rules
 *   - write canary file to wp-content/uploads/canary.txt
 */

// Bootstrap guard: skip on subsequent requests via a runtime option check
// (constant definitions can't be unset between requests in the PHP CLI
// server's worker reuse, so we use an option as the persistence layer).
if ( get_option( 'sscribe_e2e_bootstrap_done' ) === '1' ) {
    return;
}

@file_put_contents( __DIR__ . '/wp-content/.sscribe-bootstrap.log', "[0] bootstrap entered " . gmdate('c') . PHP_EOL, FILE_APPEND );

// wp-settings.php loaded with WP_INSTALLING=true so wp_not_installed()
// would short-circuit. The flag also caused wp-settings.php to skip
// loading active plugins — so SScribe isn't loaded yet. We turn the
// flag off here so subsequent WP calls (activate_plugin, update_option,
// wp_count_posts, etc.) don't take the "installing" branch.
if ( function_exists( 'wp_installing' ) ) {
    wp_installing( false );
}

// At this point WordPress has loaded (require ABSPATH . 'wp-load.php' is
// done before the include). We can call all WP functions.

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// 1. Install if not installed.
file_put_contents( __DIR__ . '/wp-content/.sscribe-bootstrap.log', "[1] before is_blog_installed " . gmdate('c') . PHP_EOL, FILE_APPEND );
if ( ! is_blog_installed() ) {
    file_put_contents( __DIR__ . '/wp-content/.sscribe-bootstrap.log', "[2] before wp_install " . gmdate('c') . PHP_EOL, FILE_APPEND );
    wp_install(
        'E2E WordPress',
        'admin',
        'admin@e2e.local',
        true,                  // public
        '',                    // deprecated
        'password'             // admin password
    );
    file_put_contents( __DIR__ . '/wp-content/.sscribe-bootstrap.log', "[3] after wp_install " . gmdate('c') . PHP_EOL, FILE_APPEND );
}

// 2. Confirm admin user (admin / password).
$user = get_user_by('login', 'admin');
if ( ! $user ) {
    $user_id = wp_insert_user([
        'user_login'   => 'admin',
        'user_pass'    => 'password',
        'user_email'   => 'admin@e2e.local',
        'role'         => 'administrator',
        'user_nicename'=> 'admin',
    ]);
    if ( is_wp_error( $user_id ) ) {
        throw new RuntimeException( 'admin user creation failed: ' . $user_id->get_error_message() );
    }
} else {
    // Reset password (idempotent across runs).
    wp_set_password( 'password', $user->ID );
}

// 3. Permalinks: pretty.
update_option( 'permalink_structure', '/%postname%/' );
$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
$GLOBALS['wp_rewrite']->flush_rules( true );

// 4. Seed 50 pages (only if we don't already have them).
$existing = wp_count_posts( 'page' );
$have = isset( $existing->publish ) ? (int) $existing->publish : 0;
if ( $have < 50 ) {
    for ( $i = 1; $i <= 50; $i++ ) {
        wp_insert_post( [
            'post_title'   => sprintf( 'Sample Page %02d', $i ),
            'post_content' => 'Seeded page ' . $i . '.',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => $user ? $user->ID : 1,
        ] );
    }
}

// 5. Activate SScribe plugin.
$plugin_slug = 'sscribe-export-site-pages/sscribe-export-site-pages.php';
$active = (array) get_option( 'active_plugins', [] );
if ( ! in_array( $plugin_slug, $active, true ) ) {
    // Manually include the plugin file first — wp-settings.php skipped
    // loading active plugins during WP_INSTALLING=true. Without this,
    // activate_plugin() can't run the plugin's load hook.
    $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_slug;
    if ( file_exists( $plugin_file ) ) {
        include_once $plugin_file;
    }
    $result = activate_plugin( $plugin_slug );
    if ( is_wp_error( $result ) ) {
        throw new RuntimeException( 'SScribe activation failed: ' . $result->get_error_message() );
    }
}

// 6. Flush rewrite rules again (post-activation).
$GLOBALS['wp_rewrite']->flush_rules( true );

// 7. Write canary with boot-state assertions (consumed by readiness probe).
file_put_contents( __DIR__ . '/wp-content/.sscribe-bootstrap.log', "[7] before canary write " . gmdate('c') . PHP_EOL, FILE_APPEND );
$canary_pages = wp_count_posts( 'page' );
$canary_active = get_option( 'active_plugins', [] );
$canary = [
    'pages=' . (int) $canary_pages->publish,
    'active=' . json_encode( $canary_active ),
    'sscribe_active=' . ( in_array( $plugin_slug, (array) $canary_active, true ) ? '1' : '0' ),
    'bootstrap_time=' . gmdate( 'c' ),
];
$upload_dir = wp_upload_dir();
$canary_path = $upload_dir['basedir'] . '/canary.txt';
if ( ! is_dir( $upload_dir['basedir'] ) ) {
    mkdir( $upload_dir['basedir'], 0777, true );
}
$write_ok = @file_put_contents( $canary_path, implode( "\n", $canary ) . "\n" );
file_put_contents( __DIR__ . '/wp-content/.sscribe-bootstrap.log', "[7.1] canary write path={$canary_path} bytes=" . var_export($write_ok, true) . " upload_dir=" . json_encode($upload_dir) . PHP_EOL, FILE_APPEND );

// 8. Mark done.
update_option( 'sscribe_e2e_bootstrap_done', '1' );
file_put_contents( __DIR__ . '/wp-content/.sscribe-bootstrap.log', "[8] bootstrap done " . gmdate('c') . PHP_EOL, FILE_APPEND );
`;

// ---- PHP router for built-in server ---------------------------------------------

const PHP_ROUTER = `<?php
/**
 * Router for PHP's built-in HTTP server. Serves existing files directly,
 * routes all non-existing paths to index.php (WordPress front controller).
 *
 * Required because PHP's built-in server does not implement Apache
 * .htaccess, which is what WordPress's pretty permalinks rely on.
 *
 * Per directive §16: preserve WordPress pretty-permalink behavior.
 *
 * The WordPress docroot is passed in via SSCRIBE_E2E_WP_ROOT (absolute
 * path) because PHP's built-in server sets the router's __DIR__ to its
 * own containing dir, not to the -t docroot.
 */

$root = getenv( 'SSCRIBE_E2E_WP_ROOT' );
if ( ! $root || ! is_dir( $root ) ) {
    fwrite( STDERR, "[sscribe-router] SSCRIBE_E2E_WP_ROOT is missing or not a directory: " . var_export( $root, true ) . PHP_EOL );
    http_response_code( 500 );
    echo 'router misconfigured';
    return true;
}
$method      = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'UNKNOWN';
$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
fwrite( STDERR, '[sscribe-router] START method=' . $method . ' uri=' . $request_uri . PHP_EOL );

$path = parse_url( $request_uri, PHP_URL_PATH );
$file = $root . $path;

// Don't serve the router script itself.
if ( strpos( $path, '/sscribe-router.php' ) === 0 ) {
    http_response_code( 404 );
    return true;
}

if ( $path !== '/' && file_exists( $file ) && ! is_dir( $file ) ) {
    return false; // let the server serve the file directly
}

// Otherwise route through WordPress. When the path points at a directory
// (e.g. /wp-admin/) or a specific .php file under a directory, set
// SCRIPT_NAME/SCRIPT_FILENAME to match — otherwise PHP CLI server leaves
// them at /index.php for every request, WP's front controller then tries
// to parse /wp-admin/ as a post URL, falls through to redirect logic, and
// /wp-admin/ loops back to itself (the browser sees ERR_TOO_MANY_REDIRECTS
// after the post-login 302 lands on /wp-admin/).
//
// Apache resolves /wp-admin/ to wp-admin/index.php transparently; we mirror
// that here by detecting the directory case and routing through the right
// index.php.
$dir_index = $file . '/index.php';
if ( is_dir( $file ) && file_exists( $dir_index ) ) {
    $_SERVER['SCRIPT_NAME']     = $path . 'index.php';
    $_SERVER['SCRIPT_FILENAME'] = $dir_index;
    $_SERVER['PHP_SELF']        = $path . 'index.php';
    $_SERVER['DOCUMENT_ROOT']   = $root;
    require $dir_index;
    return true;
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['DOCUMENT_ROOT']   = $root;
require $root . '/index.php';
`;

// ---- Adapter --------------------------------------------------------------------

export class NativeWordpressRuntime implements E2ERuntime {
  public readonly baseURL: string;
  public readonly metadata: RuntimeMetadata;
  private readonly runtimeDir: string;
  private readonly wpDir: string;
  private readonly wpContentDir: string;
  private readonly pluginSlug = 'sscribe-export-site-pages';
  private readonly cacheRoot: string;
  private readonly sscribeZipPath: string;
  private readonly host = '127.0.0.1';
  private readonly port = 9400;
  private readonly phpBinary: string;
  private phpProcess: ChildProcess | null = null;
  private bootstrapDone = false;

  constructor(opts: {
    cacheRoot: string;
    sscribeZipPath: string;
    wordpressVersion?: string;
    sqliteIntegrationVersion?: string;
    host?: string;
    port?: number;
  }) {
    this.cacheRoot = opts.cacheRoot;
    this.sscribeZipPath = opts.sscribeZipPath;
    const wpVersion = opts.wordpressVersion || PINNED_WORDPRESS_VERSION;
    const sqliteVersion = opts.sqliteIntegrationVersion || PINNED_SQLITE_INTEGRATION_VERSION;
    this.port = opts.port || 9400;
    this.runtimeDir = join(this.cacheRoot, 'runtime');
    this.wpDir = join(this.runtimeDir, 'wordpress');
    this.wpContentDir = join(this.wpDir, 'wp-content');
    // Resolve PHP binary: SSCRIBE_E2E_PHP_BINARY env var, or fallback to 'php'.
    this.phpBinary = (process.env.SSCRIBE_E2E_PHP_BINARY || 'php').trim();
    this.baseURL = `http://${this.host}:${this.port}`;
    this.metadata = {
      runtime: 'native-wordpress',
      wordpressVersion: wpVersion,
      dbBackend: 'sqlite',
      dbIntegrationVersion: sqliteVersion,
      phpVersion: '', // filled at boot
      host: this.host,
      port: this.port,
      sscribeZipPath: this.sscribeZipPath,
      runtimeDir: this.runtimeDir,
    };
  }

  /**
   * Build the runtime on disk: download WP, install SQLite drop-in,
   * install SScribe, write wp-config.php + bootstrap + router.
   * Idempotent — uses content hashes to skip already-installed pieces.
   */
  async install(): Promise<void> {
    log(`install: start (cacheRoot=${this.cacheRoot})`);
    this.assertScribeZip();

    // 1. Reset runtime directory.
    if ( existsSync( this.runtimeDir ) ) {
      log(`install: destroying stale runtime at ${this.runtimeDir}`);
      rmSync( this.runtimeDir, { recursive: true, force: true } );
    }
    mkdirSync( this.runtimeDir, { recursive: true } );

    // 2. Download + extract WordPress.
    await this.installWordPress();

    // 3. Install SQLite Database Integration plugin (must come BEFORE
    //    generating the db.php drop-in so we can read the canonical db.copy).
    await this.installSqliteIntegration();

    // 4. Write the router + wp-config.php + bootstrap.
    this.writeRouter();
    this.writeWpConfig();
    this.writeBootstrap();

    // 5. Install mu-plugins.
    this.installMuPlugins();

    // 6. Install SScribe plugin (extract exact release ZIP).
    this.installSscribePlugin();

    // 7. Resolve PHP version string (best-effort).
    try {
      const out = execFileSync(this.phpBinary, ['-r', 'echo PHP_VERSION;'], { encoding: 'utf-8' });
      this.metadata.phpVersion = (out || '').trim();
    } catch {
      this.metadata.phpVersion = 'unknown';
    }
    log(`install: done (php=${this.metadata.phpVersion} binary=${this.phpBinary}, wp=${this.metadata.wordpressVersion})`);
  }

  /**
   * Start the PHP built-in HTTP server and wait for readiness.
   */
  async start(): Promise<void> {
    log(`start: spawning ${this.phpBinary} -S ${this.host}:${this.port}`);
    const logPath = join(this.runtimeDir, 'php-server.log');

    // Use 'pipe' (default) for stdout/stderr so we can read the PHP server
    // log ourselves — createWriteStream's fd isn't transferable to stdio.
    // The streams are drained into php-server.log by the .on('data',…)
    // handlers below.
    const phpServerEnv = { ...process.env };
    delete phpServerEnv.PHP_CLI_SERVER_WORKERS;

    this.phpProcess = spawn(
      this.phpBinary,
      [
        '-d', 'max_execution_time=300',
        '-S',
        `${this.host}:${this.port}`,
        '-t',
        this.wpDir,
        join(this.runtimeDir, 'sscribe-router.php'),
      ],
      {
        cwd: this.wpDir,
        stdio: ['ignore', 'pipe', 'pipe'],
        detached: false,
        env: {
          ...phpServerEnv,
          SSCRIBE_E2E_TESTBED: '1',
          SSCRIBE_E2E_WP_ROOT: this.wpDir,
          // PHP_CLI_SERVER_WORKERS is explicitly removed above. The built-in
          // server is single-process/single-threaded by default; inheriting
          // that variable would opt into PHP's experimental forked-worker
          // mode and reintroduce concurrent access to this fixture's SQLite DB.
        },
      }
    );

    const logFd = createWriteStream(logPath, { flags: 'a' });
    this.phpProcess.stdout?.on('data', (chunk) => logFd.write(chunk));
    this.phpProcess.stderr?.on('data', (chunk) => logFd.write(chunk));

    this.phpProcess.on('exit', (code, signal) => {
      log(`php server exited code=${code} signal=${signal}`);
      logFd.end();
    });

    // Spawn can race — give the OS a moment to bind the socket. We still
    // gate on /wp-login.php status afterwards, so this is just hygiene.
    await new Promise((r) => setTimeout(r, 200));

    // Poll /wp-login.php until it responds (any HTTP status, including 302).
    const start = Date.now();
    let lastStatus: number | null = null;
    while ( Date.now() - start < BOOT_TIMEOUT_MS ) {
      try {
        const r = await fetch( `${this.baseURL}/wp-login.php`, { redirect: 'manual' } );
        lastStatus = r.status;
        if ( lastStatus > 0 ) break;
      } catch {
        // not yet listening
      }
      await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
    }
    if ( Date.now() - start >= BOOT_TIMEOUT_MS ) {
      throw new Error(`native WordPress failed to bind ${this.baseURL}/wp-login.php within ${BOOT_TIMEOUT_MS}ms (last status: ${lastStatus})`);
    }
    log(`start: HTTP listening (first status=${lastStatus}, elapsed=${Date.now() - start}ms)`);
  }

  /**
   * Run state-based readiness probes (no fixed sleeps).
   * Throws if any probe fails.
   */
  async waitForReady(): Promise<void> {
    log(`waitForReady: probing boot state`);
    const start = Date.now();
    let last: BootReport | null = null;
    while ( Date.now() - start < BOOT_TIMEOUT_MS ) {
      let report: BootReport | null = null;
      try {
        report = await this.probeBootState();
      } catch ( e ) {
        log(`waitForReady: probe threw: ${(e as Error).message}`);
        await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
        continue;
      }
      last = report;
      if ( this.bootstrapDone ) {
        if (
          report.canary_status === 200 &&
          report.pages >= EXPECTED_SEED_PAGES &&
          report.plugin_active &&
          report.admin_status < 500
        ) {
          log(`waitForReady: PASS pages=${report.pages} plugin_active=${report.plugin_active} admin=${report.admin_status} elapsed=${Date.now() - start}ms`);
          // Prime testbed (cancel-all + reset) like the Playground globalSetup did.
          try {
            await fetch( `${this.baseURL}/?ssb_test_cancel_all=1` );
            await fetch( `${this.baseURL}/?ssb_test_reset=1` );
          } catch (e) {
            log(`waitForReady: priming failed (non-fatal): ${(e as Error).message}`);
          }
          return;
        }
        // bootstrapDone but criteria not met yet — keep polling.
        if ( Date.now() - start > READINESS_BODY_PROBE_TIMEOUT_MS ) {
          log(`waitForReady: bootstrap done but criteria not met (last=${JSON.stringify(last)})`);
        }
      }
      await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
    }
    throw new Error(`native WordPress readiness probe exceeded ${BOOT_TIMEOUT_MS}ms (last=${JSON.stringify(last)})`);
  }

  /**
   * Graceful stop. Force-kill fallback if PHP doesn't exit within 5s.
   */
  async teardown(): Promise<void> {
    log(`teardown: stopping`);
    if ( this.phpProcess && this.phpProcess.exitCode === null ) {
      try {
        this.phpProcess.kill( 'SIGTERM' );
      } catch {}
      const exitedAt = Date.now();
      while ( Date.now() - exitedAt < 5000 ) {
        if ( this.phpProcess.exitCode !== null ) break;
        await new Promise((r) => setTimeout(r, 100));
      }
      if ( this.phpProcess.exitCode === null ) {
        try {
          this.phpProcess.kill( 'SIGKILL' );
        } catch {}
      }
    }
    this.phpProcess = null;
    log(`teardown: done`);
  }

  // ---- Internals ---------------------------------------------------------------

  private assertScribeZip(): void {
    if ( ! existsSync( this.sscribeZipPath ) ) {
      throw new Error(
        `Canonical SScribe release ZIP not found at ${this.sscribeZipPath}. ` +
        `Run 'composer release:prepare' before 'npm run test:e2e'.`
      );
    }
    const stat = statSync( this.sscribeZipPath );
    if ( stat.size < 100_000 ) {
      throw new Error(`SScribe release ZIP too small (${stat.size} bytes) at ${this.sscribeZipPath}`);
    }
  }

  private async installWordPress(): Promise<void> {
    const version = this.metadata.wordpressVersion;
    const tarballName = `wordpress-${version}.tar.gz`;
    const tarballPath = join(this.cacheRoot, 'downloads', tarballName);
    mkdirSync( join(this.cacheRoot, 'downloads'), { recursive: true } );

    if ( ! existsSync( tarballPath ) ) {
      const url = `https://wordpress.org/${tarballName}`;
      log(`installWordPress: downloading ${url}`);
      downloadTo( url, tarballPath );
    } else {
      log(`installWordPress: using cached tarball ${tarballPath}`);
    }
    const tarballBuf = readFileSync( tarballPath );
    log(`installWordPress: sha256=${sha256(tarballBuf)} bytes=${tarballBuf.length}`);

    const extractRoot = join(this.runtimeDir, 'extract');
    mkdirSync( extractRoot, { recursive: true } );
    extractTarGz( tarballPath, extractRoot );

    const extractedWp = join(extractRoot, 'wordpress');
    if ( ! existsSync( extractedWp ) ) {
      throw new Error(`WordPress tarball did not produce 'wordpress/' directory at ${extractedWp}`);
    }
    // If wpDir already exists from a stale run, remove it first.
    if ( existsSync( this.wpDir ) ) {
      rmSync( this.wpDir, { recursive: true, force: true } );
    }
    // Move wordpress/ -> runtimeDir/wordpress/. On Windows, both fs.rename
    // and cmd /c move can race with the parent dir's lock after rmSync
    // (and tarball extract holds the source open briefly). Retry with
    // a brief settle delay, then fall back to per-file copy if the move
    // still fails.
    let moved = false;
    for ( let attempt = 0; attempt < 5 && ! moved; attempt++ ) {
      try {
        renameSync( extractedWp, this.wpDir );
        moved = true;
      } catch ( e ) {
        log(`installWordPress: renameSync attempt ${attempt} failed: ${(e as Error).message}; retrying after ${100 * (attempt + 1)}ms`);
        await new Promise((r) => setTimeout(r, 100 * (attempt + 1)));
      }
    }
    if ( ! moved ) {
      // Last resort: robocopy the directory contents into a fresh wpDir
      // (only used if both rename and cmd-move consistently fail).
      log(`installWordPress: renameSync exhausted retries, falling back to robocopy/cp -r`);
      mkdirSync( this.wpDir, { recursive: true } );
      copyDirRecursive( extractedWp, this.wpDir );
      rmSync( extractedWp, { recursive: true, force: true } );
    }
    log(`installWordPress: extracted to ${this.wpDir}`);
  }

  private installSqliteIntegration(): Promise<void> {
    // Resolve via the existing tests-wp SQLite helper if present, otherwise
    // download from wordpress.org/plugins. Reuse bin/install-wp-tests.sh's
    // path if possible (per directive §8: reuse common mechanics).
    const version = this.metadata.dbIntegrationVersion;
    const tarballName = `sqlite-database-integration-${version}.zip`;
    const tarballPath = join(this.cacheRoot, 'downloads', tarballName);

    if ( ! existsSync( tarballPath ) ) {
      // Try the plugin's GitHub release asset first.
      const url = `https://downloads.wordpress.org/plugin/sqlite-database-integration.${version}.zip`;
      log(`installSqliteIntegration: downloading ${url}`);
      downloadTo( url, tarballPath );
    } else {
      log(`installSqliteIntegration: using cached ${tarballPath}`);
    }
    const buf = readFileSync( tarballPath );
    log(`installSqliteIntegration: sha256=${sha256(buf)} bytes=${buf.length}`);

    const extractRoot = join(this.runtimeDir, 'extract-sqlite');
    mkdirSync( extractRoot, { recursive: true } );
    extractZip( tarballPath, extractRoot );

    const pluginSrc = join(extractRoot, 'sqlite-database-integration');
    const pluginDst = join(this.wpContentDir, 'plugins', 'sqlite-database-integration');
    if ( ! existsSync( pluginSrc ) ) {
      throw new Error(`SQLite plugin zip did not produce sqlite-database-integration/ at ${pluginSrc}`);
    }
    copyDirRecursive( pluginSrc, pluginDst );
    log(`installSqliteIntegration: installed to ${pluginDst}`);

    // Generate the canonical db.php drop-in from db.copy.
    // The drop-in has a {SQLITE_IMPLEMENTATION_FOLDER_PATH} placeholder that
    // must be replaced with the absolute plugin path; we also substitute
    // {SQLITE_PLUGIN} placeholder if any. This is the documented
    // install-time transformation the SQLite plugin performs when activated
    // via the WP admin — we do it once here so the testbed never has to
    // visit wp-admin/plugins to enable SQLite.
    const dbCopyPath = join(pluginDst, 'db.copy');
    if ( ! existsSync( dbCopyPath ) ) {
      throw new Error(`SQLite plugin missing db.copy at ${dbCopyPath}`);
    }
    let dropIn = readFileSync( dbCopyPath, 'utf-8' );
    // The placeholder is the only one that must be substituted; we use
    // forward-slashes to stay portable (the plugin's runtime realpath()
    // call accepts either on Windows).
    dropIn = dropIn.replace( '{SQLITE_IMPLEMENTATION_FOLDER_PATH}', pluginDst.replace( /\\/g, '/' ) );
    const dropInPath = join(this.wpContentDir, 'db.php');
    writeFileSync( dropInPath, dropIn );
    log(`installSqliteIntegration: db.php drop-in written (${dropIn.length} bytes)`);

    return Promise.resolve();
  }

  private installSscribePlugin(): void {
    const pluginsDir = join(this.wpContentDir, 'plugins');
    mkdirSync( pluginsDir, { recursive: true } );
    const dst = join(pluginsDir, this.pluginSlug);
    if ( existsSync( dst ) ) {
      rmSync( dst, { recursive: true, force: true } );
    }
    extractZip( this.sscribeZipPath, pluginsDir );

    // Sanity: the plugin's main file must exist after extract.
    const main = join(dst, 'sscribe-export-site-pages.php');
    if ( ! existsSync( main ) ) {
      throw new Error(`SScribe ZIP did not place main file at ${main}`);
    }
    log(`installSscribePlugin: installed to ${dst}`);
  }

  private installMuPlugins(): void {
    const muSrc = join(process.cwd(), 'tests-e2e', 'fixtures', 'mu-plugins');
    if ( ! existsSync( muSrc ) ) {
      throw new Error(`mu-plugins directory not found at ${muSrc}`);
    }
    const muDst = join(this.wpContentDir, 'mu-plugins');
    mkdirSync( muDst, { recursive: true } );

    // Copy each .php file from muSrc to muDst (mirrors Blueprint's writeFile steps).
    for ( const name of readdirSync( muSrc ) ) {
      if ( ! name.endsWith( '.php' ) ) continue;
      copyFileSync( join(muSrc, name), join(muDst, name) );
      log(`installMuPlugins: copied ${name}`);
    }
  }

  private writeWpConfig(): void {
    const salts = this.generateSalts();
    const config = `<?php
// Native WordPress E2E config — generated at runtime, deterministic per boot.
// DB credentials intentionally non-secret: localhost SQLite only.

// Canonical DB_* constants. The SQLite drop-in ignores the values (it routes
// every wpdb call to a local .sqlite file) but WP core code paths reached
// during admin requests read these directly. Concretely
// wp-includes/update.php:124 (wp_version_check() MyISAM-engine probe)
// interpolates DB_NAME into a SELECT against information_schema.TABLES;
// missing the constant throws an E_ERROR and kills the admin render — leaving
// /wp-admin/index.php returning empty 500s and /wp-admin/ bouncing in a
// self-redirect loop. Mirrors the PHPUnit-WP testbench's wp-tests-config.php
// pattern so both runtimes share the same WP core code paths.
define( 'DB_NAME', 'sscribe_e2e' );
define( 'DB_USER', 'sscribe_e2e' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );

// SQLite Database Integration settings.
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', 'sscribe-e2e.sqlite' );
// WAL journal mode prevents SQLITE_BUSY / SQLITE_LOCKED when wp_widgets_init()
// runs 15+ back-to-back INSERT ... ON DUPLICATE KEY UPDATE on the same worker.
// Default journal_mode=DELETE holds an exclusive lock during commit; WAL
// allows concurrent readers + a single writer without lock contention. The
// SQLite Database Integration plugin reads this constant (translator:425-427).
define( 'SQLITE_JOURNAL_MODE', 'WAL' );

// Salts.
${salts}

// Testbed constants.
define( 'SSCRIBE_E2E_TESTBED', true );
define( 'SSCRIBE_E2E_BOOTSTRAP_FILE', __DIR__ . '/bootstrap.php' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );

// Standard WP debug policy for tests.
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISALLOW_FILE_MODS', true );

// Suppress the PHP 8.5 SQLite deprecation flood from
// PDO::sqliteCreateFunction(). This upstream deprecation fires on every
// database query, generating excessive log lines. On PHP 8.4 this
// deprecation does not fire. The targeted handler below suppresses only
// this specific deprecation — all other errors/deprecations pass through
// to WP_DEBUG_LOG normally.
set_error_handler( function ( $errno, $errstr ) {
    if ( $errno === E_DEPRECATED && strpos( $errstr, 'sqliteCreateFunction' ) !== false ) {
        return true; // suppress
    }
    return false; // let PHP's default handler process it
}, E_DEPRECATED );

// Raise max_execution_time to avoid the preflight "execution time warning"
// dialog that blocks the export flow in E2E tests. Primary setting is via
// -d max_execution_time=300 in the PHP spawn command; this ini_set is a
// fallback for environments where the spawn flag is not honored.
@ini_set( 'max_execution_time', '300' );

// Table prefix.
$table_prefix = 'wp_';

// Load WordPress.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
@file_put_contents( __DIR__ . '/wp-content/.sscribe-bootstrap.log', "[wp-config] before wp-settings " . gmdate('c') . PHP_EOL, FILE_APPEND );

// Capture fatal errors so we see them in the bootstrap log.
register_shutdown_function( function() {
    \$err = error_get_last();
    if ( \$err && in_array( \$err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true ) ) {
        @file_put_contents( __DIR__ . '/wp-content/.sscribe-bootstrap.log', "[fatal] " . print_r(\$err, true) . PHP_EOL, FILE_APPEND );
    }
} );

// Headless-install gate: define WP_INSTALLING so wp-settings.php's
// wp_not_installed() short-circuits on the very first request, letting our
// bootstrap.php (loaded below) call wp_install() instead of being killed
// by the redirect-to-install.php die() that fires when the DB is empty.
// After wp_install() the static wp_installing() flag is reset to false
// and plugin loading resumes normally for every subsequent request.
//
// We only set WP_INSTALLING when the DB doesn't yet have siteurl set; once
// the bootstrap has run, the option exists and we go through the normal
// wp-settings.php path with all plugins loaded.

// Don't load wpdb here — wp-load.php requires wp-config.php (this file)
// which means wp-config.php must be self-contained for its own pre-flight
// check. We can't use the wpdb class without first loading the full WP
// environment, which would loop back to wp-settings.php and trigger the
// very wp_not_installed() we are trying to bypass.
//
// Instead, peek at the SQLite file directly via PDO (no wpdb dep).
\$__sscribe_db_file = DB_DIR . '/' . DB_FILE;
\$__sscribe_installed = false;
if ( file_exists( \$__sscribe_db_file ) && filesize( \$__sscribe_db_file ) > 0 ) {
    // Use SQLite directly to check siteurl option.
    try {
        \$__pdo = new PDO( 'sqlite:' . \$__sscribe_db_file );
        \$__pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        \$__stmt = \$__pdo->query( "SELECT option_value FROM wp_options WHERE option_name='siteurl' LIMIT 1" );
        \$__row = \$__stmt ? \$__stmt->fetch( PDO::FETCH_ASSOC ) : null;
        if ( \$__row && ! empty( \$__row['option_value'] ) ) {
            \$__sscribe_installed = true;
        }
    } catch ( Throwable \$e ) {
        // DB unreadable — assume fresh.
    }
}
if ( ! \$__sscribe_installed ) {
    define( 'WP_INSTALLING', true );
}

require_once ABSPATH . 'wp-settings.php';
@file_put_contents( __DIR__ . '/wp-content/.sscribe-bootstrap.log', "[wp-config] after wp-settings (installing=" . ( defined('WP_INSTALLING') && WP_INSTALLING ? '1' : '0' ) . ") " . gmdate('c') . PHP_EOL, FILE_APPEND );

// Run the E2E bootstrap (install + seed + activate) on first request.
if ( file_exists( SSCRIBE_E2E_BOOTSTRAP_FILE ) ) {
    @file_put_contents( __DIR__ . '/wp-content/.sscribe-bootstrap.log', "[wp-config] before bootstrap include " . gmdate('c') . PHP_EOL, FILE_APPEND );
    require_once SSCRIBE_E2E_BOOTSTRAP_FILE;
    @file_put_contents( __DIR__ . '/wp-content/.sscribe-bootstrap.log', "[wp-config] after bootstrap include " . gmdate('c') . PHP_EOL, FILE_APPEND );
}
`;
    writeFileSync( join(this.wpDir, 'wp-config.php'), config );
    log(`writeWpConfig: done`);
  }

  private writeBootstrap(): void {
    // Write into wpDir so wp-config.php's SSCRIBE_E2E_BOOTSTRAP_FILE
    // (which is __DIR__ . '/bootstrap.php' from wpDir) resolves correctly.
    writeFileSync( join(this.wpDir, 'bootstrap.php'), PHP_BOOTSTRAP );
    log(`writeBootstrap: done`);
  }

  private writeRouter(): void {
    // Write router to runtimeDir, then inject the actual WordPress docroot
    // via the SSCRIBE_E2E_WP_ROOT env var so the router doesn't depend on
    // its own location (PHP -S sets the router's __DIR__ to its own
    // containing dir, NOT to the -t docroot).
    writeFileSync( join(this.runtimeDir, 'sscribe-router.php'), PHP_ROUTER );
    log(`writeRouter: done`);
  }

  private generateSalts(): string {
    // Deterministic-but-unique per boot. We don't need cryptographic
    // security — this is a localhost-only ephemeral runtime.
    const seed = `${this.runtimeDir}-${process.pid}-${Date.now()}`;
    const h = createHash('sha256').update(seed).digest('hex');
    const lines: string[] = [];
    const names = [
      'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
      'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
    ];
    names.forEach((n, i) => {
      // 64-char deterministic salt per constant.
      const salt = (h + h).substr(i * 8, 64);
      lines.push(`define('${n}', '${salt}');`);
    });
    return lines.join('\n');
  }

  private async probeBootState(): Promise<BootReport> {
    // /wp-login.php is the cheapest warm-up signal: any HTTP response
    // (302 to install, 200 to login form, etc.) means the PHP server is
    // alive AND WordPress loaded without fatal. If this hangs, the server
    // is wedged and there's no point hitting the heavier probes.
    let loginStatus = 0;
    try {
      const loginResp = await fetch(`${this.baseURL}/wp-login.php`, { redirect: 'manual' });
      loginStatus = loginResp.status;
    } catch ( e ) {
      log(`probeBootState: /wp-login.php fetch failed: ${(e as Error).message}`);
    }
    let canary_status = 0;
    let pages = 0;
    let plugin_active = false;
    try {
      const canaryResp = await fetch(`${this.baseURL}/wp-content/uploads/canary.txt`);
      canary_status = canaryResp.status;
      if ( canary_status === 200 ) {
        this.bootstrapDone = true;
        const text = await canaryResp.text();
        const pagesMatch = text.match(/pages=(\d+)/);
        const activeMatch = text.match(/active=(.+)/);
        pages = pagesMatch ? parseInt(pagesMatch[1], 10) : 0;
        plugin_active = activeMatch ? activeMatch[1].includes(this.pluginSlug) : false;
      }
    } catch ( e ) {
      log(`probeBootState: canary fetch failed: ${(e as Error).message}`);
    }
    let admin_status = 0;
    try {
      const adminResp = await fetch(`${this.baseURL}/wp-admin/admin.php?page=sscribe-export`, { redirect: 'manual' });
      admin_status = adminResp.status;
    } catch ( e ) {
      log(`probeBootState: admin fetch failed: ${(e as Error).message}`);
    }
    return {
      canary_status,
      pages,
      plugin_active,
      admin_status,
      login_status: loginStatus,
    };
  }
}

interface BootReport {
  canary_status: number;
  pages: number;
  plugin_active: boolean;
  admin_status: number;
  login_status: number;
}

// Factory exported for globalSetup.
export async function createNativeWordpressRuntime(opts: {
  cacheRoot: string;
  sscribeZipPath: string;
}): Promise<NativeWordpressRuntime> {
  const runtime = new NativeWordpressRuntime(opts);
  await runtime.install();
  await runtime.start();
  await runtime.waitForReady();
  return runtime;
}
