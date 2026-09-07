/**
 * No-op lock stub for @php-wasm/node's bundled `fs-ext-extra-prebuilt`.
 *
 * WP-Playground's @php-wasm/node uses flockSync/fcntlSync/lockFileExSync/
 * unlockFileExSync internally. On Windows + Node v26 these throw because
 * the prebuilt native binary is missing. We hook Module._load and
 * require.cache to replace the broken module with a JS-only stub.
 *
 * Idempotent: safe to call multiple times.
 */

import Module from 'node:module';
import { createRequire } from 'node:module';
import { join } from 'node:path';

const req = createRequire(import.meta.url);

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

export function stubFsExt(): void {
  // Module._load hook — intercept require() at any depth.
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const mod = Module as unknown as { _load: any };
  const originalLoad = mod._load;
  if ((originalLoad as { __ss_fsExtPatched?: boolean }).__ss_fsExtPatched) {
    return; // already patched
  }
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const patched: any = function (request: unknown, parent: unknown, isMain: unknown) {
    if (typeof request === 'string' && (
      request === 'fs-ext-extra-prebuilt' ||
      (request as string).endsWith('/fs-ext-extra-prebuilt') ||
      (request as string).endsWith('\\fs-ext-extra-prebuilt')
    )) {
      return STUB;
    }
    return originalLoad.call(this, request, parent, isMain);
  };
  patched.__ss_fsExtPatched = true;
  mod._load = patched;

  // Also pre-populate cache for direct require() calls.
  try {
    const realResolved = req.resolve('fs-ext-extra-prebuilt');
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (req.cache as any)[realResolved] = {
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
    // eslint-disable-next-line no-console
    console.log('[stub-fs-ext] patched via Module._load hook (resolve failed:', (e as NodeJS.ErrnoException).code, ')');
  }

  // Virtual cache entry for the .cjs variant's path. Use import.meta.dirname
  // because this file is loaded as ESM by Playwright.
  const fakeResolved = join(import.meta.dirname, '__fs-ext-stub__.virtual');
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  (req.cache as any)[fakeResolved] = {
    id: fakeResolved,
    filename: fakeResolved,
    loaded: true,
    exports: STUB,
    children: [],
    paths: [],
  };
}

// Auto-stub when imported.
stubFsExt();
