import test from 'node:test';
import assert from 'node:assert/strict';
import {
  classifyAuditPayload,
  runAuditWithRetry,
} from '../scripts/npm-audit-high.mjs';

test('moderate-only audit report passes the high-severity gate', () => {
  const result = classifyAuditPayload({
    auditReportVersion: 2,
    metadata: {
      vulnerabilities: { info: 0, low: 0, moderate: 4, high: 0, critical: 0, total: 4 },
    },
  });
  assert.equal(result.kind, 'clean');
  assert.equal(result.high, 0);
  assert.equal(result.critical, 0);
});

test('high or critical findings fail immediately', async () => {
  let calls = 0;
  const exec = async () => {
    calls += 1;
    return {
      status: 1,
      stdout: JSON.stringify({
        auditReportVersion: 2,
        metadata: {
          vulnerabilities: { info: 0, low: 0, moderate: 0, high: 1, critical: 0, total: 1 },
        },
      }),
      stderr: '',
    };
  };

  await assert.rejects(
    () => runAuditWithRetry({ exec, attempts: 3, sleep: async () => {} }),
    /high\/critical/i
  );
  assert.equal(calls, 1, 'real vulnerability findings must never be retried away');
});

test('transient registry failures retry and eventually pass', async () => {
  const sequence = [
    { status: 1, stdout: JSON.stringify({ error: { code: 'FETCH_ERROR', summary: 'network timeout' } }), stderr: '' },
    { status: 1, stdout: '', stderr: 'npm error audit endpoint returned an error' },
    {
      status: 0,
      stdout: JSON.stringify({
        auditReportVersion: 2,
        metadata: {
          vulnerabilities: { info: 0, low: 0, moderate: 4, high: 0, critical: 0, total: 4 },
        },
      }),
      stderr: '',
    },
  ];
  let calls = 0;
  const result = await runAuditWithRetry({
    exec: async () => sequence[calls++],
    attempts: 3,
    sleep: async () => {},
  });
  assert.equal(result.kind, 'clean');
  assert.equal(calls, 3);
});

test('transient failures remain fail-closed after retry budget is exhausted', async () => {
  let calls = 0;
  const exec = async () => {
    calls += 1;
    return { status: 1, stdout: '', stderr: 'npm error audit endpoint returned an error' };
  };

  await assert.rejects(
    () => runAuditWithRetry({ exec, attempts: 3, sleep: async () => {} }),
    /unavailable after 3 attempts/i
  );
  assert.equal(calls, 3);
});

test('defaultExec subprocess env strips inherited allow-scripts overrides', async () => {
  // The Windows + npm v11+ regression: spawning `npm.cmd` from inside an
  // `npm run` script failed with EALLOWSCRIPTS when a user-level `.npmrc`
  // already set `allow-scripts=...`. The subprocess must explicitly clear
  // that env var so the nested npm invocation does not treat it as a
  // project-scoped CLI override.
  const fs = await import('node:fs');
  const source = fs.readFileSync(
    new URL('../scripts/npm-audit-high.mjs', import.meta.url),
    'utf8'
  );
  assert.match(
    source,
    /npm_config_allow_scripts:\s*''/,
    'defaultExec must explicitly clear npm_config_allow_scripts to defeat EALLOWSCRIPTS'
  );
  assert.match(
    source,
    /windowsVerbatimArguments:\s*true/,
    'defaultExec must use cmd.exe /d /s /c wrapper on Windows to avoid spawnSync EINVAL on .cmd shims'
  );
});
