import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const DEFAULT_ATTEMPTS = 3;

export function classifyAuditPayload(payload) {
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

  const high = Number(vulnerabilities.high || 0);
  const critical = Number(vulnerabilities.critical || 0);
  const moderate = Number(vulnerabilities.moderate || 0);
  const low = Number(vulnerabilities.low || 0);

  if (high > 0 || critical > 0) {
    return { kind: 'vulnerable', high, critical, moderate, low };
  }

  return { kind: 'clean', high, critical, moderate, low };
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
  const npmExecutable = process.platform === 'win32' ? 'npm.cmd' : 'npm';
  const result = spawnSync(npmExecutable, ['audit', '--json', '--audit-level=high'], {
    encoding: 'utf8',
    env: {
      ...process.env,
      npm_config_fetch_timeout: process.env.npm_config_fetch_timeout || '60000',
      npm_config_fetch_retries: process.env.npm_config_fetch_retries || '0',
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
        `npm audit high-severity gate passed (high=${classification.high}, critical=${classification.critical}, moderate=${classification.moderate}, low=${classification.low}).`
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
