import Module from 'node:module';

/**
 * No-op lock stub for @php-wasm/node's bundled `fs-ext-extra-prebuilt`.
 *
 * WP-Playground's @php-wasm/node uses flockSync/fcntlSync/lockFileExSync/
 * unlockFileExSync internally. On Windows + Node v26 these throw EBADF or
 * ENOSYS because Windows flock semantics differ. We replace them with
 * no-ops so the lock calls succeed but do nothing.
 *
 * The `constants` object keeps the integer codes (F_RDLCK/F_WRLCK/F_UNLCK,
 * F_SETLK/F_SETLKW, LOCKFILE_EXCLUSIVE_LOCK/LOCKFILE_FAIL_IMMEDIATELY) so
 * any consumer that imports `constants` doesn't NPE.
 *
 * Idempotent: safe to call multiple times.
 */
export function stubFsExt(): void {
  const candidates = [
    '@php-wasm/node/node_modules/fs-ext-extra-prebuilt',
    'fs-ext-extra-prebuilt',
  ];
  for (const name of candidates) {
    try {
      const mod = require(name);
      const stubbed = {
        flockSync: () => 0,
        fcntlSync: () => 0,
        lockFileExSync: () => 0,
        unlockFileExSync: () => 0,
        constants: mod.constants ?? {
          F_RDLCK: 0,
          F_WRLCK: 1,
          F_UNLCK: 2,
          F_SETLK: 6,
          F_SETLKW: 7,
          LOCKFILE_EXCLUSIVE_LOCK: 2,
          LOCKFILE_FAIL_IMMEDIATELY: 1,
        },
      };
      const resolved = require.resolve(name);
      require.cache[resolved] = {
        id: resolved,
        filename: resolved,
        loaded: true,
        exports: stubbed,
        children: [],
        paths: [],
      } as NodeJS.Module;
    } catch {
      // Module not loaded yet (or not present at all). Idempotent: skip.
    }
  }
}

// Auto-stub when imported.
stubFsExt();