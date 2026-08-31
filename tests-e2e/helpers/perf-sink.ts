import type { Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { test as base, expect } from '@playwright/test';

export interface PerfSample {
  page_id: number;
  formats: string[];
  success: boolean;
  elapsed_ms: number | null;
  peak_mem_bytes: number | null;
  ts: number;
}

export interface PerfSinkFixtures {
  perfSession: string;
  perfSamples: PerfSample[];
  pollPerfSink: (page: Page, session: string) => Promise<PerfSample[]>;
}

const JSONL_DIR = '/wp-content/uploads/sscribe-perf/';
const POLL_INTERVAL_MS = 200;

/**
 * Custom fixture that exposes a unique perf session ID per test, plus
 * helpers to poll the JSONL via fetch() (because WP-Playground's tmp dir
 * is inside the WASM sandbox — only the wp-content path is HTTP-served).
 */
export const perfTest = base.extend<PerfSinkFixtures>({
  perfSession: async ({}, use) => {
    const session = `t${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
    await use(session);
  },
  perfSamples: async ({ perfSession }, use) => {
    const samples: PerfSample[] = [];
    await use(samples);
    // Optional: clean up JSONL after the test.
  },
  pollPerfSink: async ({ baseURL }, use) => {
    const poll = async (page: Page, session: string): Promise<PerfSample[]> => {
      // NOTE: do NOT use page.request.get() here — Playwright's request
      // context caches HTTP responses, so new file writes won't be
      // visible. Use page.evaluate(fetch()) to issue a fresh request
      // through the page's own network stack every cycle.
      const url = `${JSONL_DIR}${session}.jsonl`;
      const start = Date.now();
      const timeoutMs = 300_000;
      let lastSize = -1;
      let stableCycles = 0;
      while (Date.now() - start < timeoutMs) {
        try {
          const text = await page.evaluate(async (u) => {
            const r = await fetch(u, { cache: 'no-store' });
            return r.ok ? await r.text() : '';
          }, url);
          if (text.length === lastSize) {
            stableCycles++;
            // Two consecutive polls with no growth → assume done.
            if (stableCycles >= 2) {
              return text
                .split('\n')
                .filter((l) => l.trim() !== '')
                .map((l) => JSON.parse(l) as PerfSample);
            }
          } else {
            stableCycles = 0;
            lastSize = text.length;
          }
        } catch {
          // 404 or transient — keep polling.
        }
        await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
      }
      throw new Error(`Perf sink ${url} never finished within ${timeoutMs}ms`);
    };
    await use(poll);
  },
});

export function loadBaselines(): Record<string, { seconds_per_page: number; mb_per_page: number }> {
  const path = join(process.cwd(), 'tests-e2e', 'baselines', 'format-baselines.json');
  const raw = JSON.parse(readFileSync(path, 'utf-8')) as Record<string, { seconds_per_page: number; mb_per_page: number }>;
  // Strip the leading `_` keys (comments).
  for (const k of Object.keys(raw)) {
    if (k.startsWith('_')) delete raw[k];
  }
  return raw;
}

export { expect };