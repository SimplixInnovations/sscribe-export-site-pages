/**
 * Plain CommonJS shim — required via NODE_OPTIONS=--require for WP-Playground
 * subprocesses on Windows + Node v26 where fs-ext-extra-prebuilt has no
 * prebuilt binary. Replaces the broken module with no-op implementations.
 *
 * Loaded before the CLI's main module via NODE_OPTIONS so the stub wins
 * over the broken fs-ext-extra-prebuilt native resolution.
 *
 * Strategy: hook Module._load so any require('fs-ext-extra-prebuilt') returns
 * our stub object. We do NOT pre-call require() on the real module because
 * loading it would throw on Windows + Node v26 (the failure we're working
 * around).
 */
'use strict';

const Module = require('node:module');
const path = require('node:path');

const STUB = {
  flock: () => 0,
  flockSync: () => 0,
  fcntl: () => 0,
  fcntlSync: () => 0,
  seek: () => 0,
  seekSync: () => 0,
  statVFS: () => 0,
  lockFileEx: () => 0,
  lockFileExSync: () => 0,
  unlockFileEx: () => 0,
  unlockFileExSync: () => 0,
  useNativeModule: () => {},
  getNativeModuleSource: () => 'stub',
  constants: {
    LOCK_SH: 1,
    LOCK_EX: 2,
    LOCK_NB: 4,
    LOCK_UN: 8,
    F_GETFD: 1,
    F_SETFD: 2,
    F_SETLK: 6,
    F_SETLKW: 7,
    F_GETLK: 5,
    LOCKFILE_EXCLUSIVE_LOCK: 2,
    LOCKFILE_FAIL_IMMEDIATELY: 1,
  },
  __esModule: true,
  default: {},
};

const originalLoad = Module._load;
Module._load = function patchedLoad(request, parent, isMain) {
  if (typeof request === 'string' && (
    request === 'fs-ext-extra-prebuilt' ||
    request.endsWith('/fs-ext-extra-prebuilt') ||
    request.endsWith('\\fs-ext-extra-prebuilt')
  )) {
    return STUB;
  }
  return originalLoad.call(this, request, parent, isMain);
};

// Also pre-populate require.cache so direct require.cache[resolved] = ... wins
// for callers that bypass Module._load via deps already in cache.
const fakeResolved = path.join(__dirname, '__fs-ext-stub__.virtual');
require.cache[fakeResolved] = {
  id: fakeResolved,
  filename: fakeResolved,
  loaded: true,
  exports: STUB,
  children: [],
  paths: [],
};

// Best-effort: try to also patch the real resolved path so any later
// require() that hits the cache returns our stub instead of bombing out.
try {
  const realResolved = require.resolve('fs-ext-extra-prebuilt');
  require.cache[realResolved] = {
    id: realResolved,
    filename: realResolved,
    loaded: true,
    exports: STUB,
    children: [],
    paths: [],
  };
  // eslint-disable-next-line no-console
  console.log('[stub-fs-ext] patched real path:', realResolved);
} catch (e) {
  // Module can't be resolved — fine, the _load hook will still win.
  // eslint-disable-next-line no-console
  console.log('[stub-fs-ext] patched via Module._load hook (resolve failed:', e.code, ')');
}
