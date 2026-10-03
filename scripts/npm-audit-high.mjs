import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const DEFAULT_ATTEMPTS = 3;

// Advisories accepted on purpose. Each entry must name the advisory, say why
// it is safe, and carry a review date: after that date the exception lapses
// and the advisory fails the gate again. Keep this list as short as possible.
export const ALLOWED_ADVISORIES = [
  {
    id: 'GHSA-vfj7-8cjw-p6xm',
    reason:
      'braces <=3.0.3 stack-exhaustion DoS, no patched release yet. Reached only through ' +
      'dev tooling (stylelint, csso-cli) that never ships in the plugin ZIP.',
    reviewBy: '2026-11-03',
  },
];

function advisoryId(via) {
  const url = typeof via?.url === 'string' ? via.url : '';
  const match = url.match(/GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}/i);
  return match ? match[0].toUpperCase() : '';
}

// A finding is allowed only when every advisory behind it, directly or
// through the packages it depends on, is on the active allowlist.
function allowedPackages(vulnerabilities, activeIds) {
  const memo = new Map();
  const isAllowed = (name, seen) => {
    if (memo.has(name)) {
      return memo.get(name);
    }
    const entry = vulnerabilities[name];
    if (!entry || !Array.isArray(entry.via) || entry.via.length === 0 || seen.has(name)) {
      return false;
    }
    seen.add(name);
    const allowed = entry.via.every((via) =>
      typeof via === 'string' ? isAllowed(via, seen) : activeIds.has(advisoryId(via))
    );
    seen.delete(name);
    memo.set(name, allowed);
    return allowed;
  };
  return Object.keys(vulnerabilities).filter((name) => isAllowed(name, new Set()));
}

export function classifyAuditPayload(payload, { allowlist = ALLOWED_ADVISORIES, now = new Date() } = {}) {
  if (!payload || typeof payload !== 'object') {
    return { kind: 'transient', message: 'npm audit did not return a JSON report' };
  }

  if (payload.error) {
    const message =
      (typeof payload.error.summary === 'string' && payload.error.summary) ||
      (typeof payload.error.message === 'string' && payload.error.message) ||
      (typeof payload.error.code === 'string' && payload.error.code) ||
      'npm audit endpoint returned an error';
    return { kind: 'transient', message };
  }

  const vulnerabilities = payload.metadata?.vulnerabilities;
  if (!vulnerabilities || typeof vulnerabilities !== 'object') {
    return { kind: 'transient', message: 'npm audit JSON report is missing vulnerability metadata' };
  }

  let high = Number(vulnerabilities.high || 0);
  let critical = Number(vulnerabilities.critical || 0);
  const moderate = Number(vulnerabilities.moderate || 0);
  const low = Number(vulnerabilities.low || 0);
  let allowed = 0;

  const findings = payload.vulnerabilities;
  if ((high > 0 || critical > 0) && findings && typeof findings === 'object') {
    const today = now.toISOString().slice(0, 10);
    const activeIds = new Set(
      allowlist.filter((entry) => today <= entry.reviewBy).map((entry) => entry.id.toUpperCase())
    );
    for (const name of allowedPackages(findings, activeIds)) {
      const severity = findings[name]?.severity;
      if (severity === 'high' && high > 0) {
        high -= 1;
        allowed += 1;
      } else if (severity === 'critical' && critical > 0) {
        critical -= 1;
        allowed += 1;
      }
    }
  }

  if (high > 0 || critical > 0) {
    return { kind: 'vulnerable', high, critical, moderate, low, allowed };
  }

  return { kind: 'clean', high, critical, moderate, low, allowed };
}

function parsePayload(stdout) {
  if (typeof stdout !== 'string' || stdout.trim() === '') {
    return null;
  }
  try {
    return JSON.parse(stdout);
  } catch {
    return null;
  }
}

function defaultExec() {
  // On Windows, Node v20+ spawnSync refuses to invoke `.cmd` shims
  // directly and fails with EINVAL unless a shell wrapper is used.
  // We invoke the npm CLI shim through `cmd.exe /d /s /c` so the
  // argument list is preserved verbatim and we avoid the
  // CVE-2024-27980 surface from a blanket `shell: true`. All
  // arguments are hard-coded — no user-controlled input reaches the
  // shell — so command injection is not reachable here.
  const isWindows = process.platform === 'win32';
  const npmShim = isWindows ? 'npm.cmd' : 'npm';
  const npmArgs = ['audit', '--json', '--audit-level=high'];
  const command = isWindows ? 'cmd.exe' : npmShim;
  const args = isWindows
    ? ['/d', '/s', '/c', npmShim, ...npmArgs]
    : npmArgs;

  const result = spawnSync(command, args, {
    encoding: 'utf8',
    windowsVerbatimArguments: true,
    env: {
      ...process.env,
      npm_config_fetch_timeout: process.env.npm_config_fetch_timeout || '60000',
      npm_config_fetch_retries: process.env.npm_config_fetch_retries || '0',
      // Clear the inherited `allow-scripts` value so npm v11+ does not
      // treat a user-level `.npmrc` policy as a CLI override and reject
      // the nested invocation with EALLOWSCRIPTS.
      npm_config_allow_scripts: '',
      // Ensure lifecycle hooks from any resolved dep are skipped.
      npm_config_ignore_scripts:
        process.env.npm_config_ignore_scripts || 'true',
    },
    timeout: 90000,
  });

  return {
    status: typeof result.status === 'number' ? result.status : 1,
    stdout: result.stdout || '',
    stderr: [result.stderr || '', result.error?.message || ''].filter(Boolean).join('\n'),
  };
}

function defaultSleep(ms) {
  return new Promise((resolvePromise) => setTimeout(resolvePromise, ms));
}

export async function runAuditWithRetry({
  exec = defaultExec,
  attempts = DEFAULT_ATTEMPTS,
  sleep = defaultSleep,
  logger = console,
} = {}) {
  const maxAttempts = Math.max(1, Number.parseInt(String(attempts), 10) || DEFAULT_ATTEMPTS);
  let lastMessage = 'unknown npm audit transport failure';

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    const result = await exec(attempt);
    const payload = parsePayload(result?.stdout || '');
    const classification = classifyAuditPayload(payload);

    if (classification.kind === 'vulnerable') {
      throw new Error(
        `npm audit found high/critical vulnerabilities: high=${classification.high}, critical=${classification.critical}`
      );
    }

    if (classification.kind === 'clean') {
      logger.log(
        `npm audit high-severity gate passed (high=${classification.high}, critical=${classification.critical}, moderate=${classification.moderate}, low=${classification.low}, allowlisted=${classification.allowed}).`
      );
      return classification;
    }

    const stderr = typeof result?.stderr === 'string' ? result.stderr.trim() : '';
    lastMessage = stderr || classification.message || 'npm audit endpoint returned an unusable response';

    if (attempt < maxAttempts) {
      logger.warn(
        `npm audit transport failure on attempt ${attempt}/${maxAttempts}; retrying: ${lastMessage}`
      );
      await sleep(Math.min(5000, attempt * 1000));
    }
  }

  throw new Error(`npm audit endpoint unavailable after ${maxAttempts} attempts: ${lastMessage}`);
}

const invokedPath = process.argv[1] ? pathToFileURL(resolve(process.argv[1])).href : '';
if (invokedPath === import.meta.url) {
  runAuditWithRetry().catch((error) => {
    console.error(error instanceof Error ? error.message : String(error));
    process.exitCode = 1;
  });
}
