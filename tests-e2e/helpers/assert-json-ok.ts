import { expect } from '@playwright/test';

export interface JSONResponse {
  ok: boolean;
  data?: unknown;
  error?: { code: string; message: string };
  [k: string]: unknown;
}

export function assertJSONOK(response: JSONResponse, expected: Record<string, unknown> = {}): void {
  expect(response, 'WP AJAX response shape').toMatchObject({ ok: true, ...expected });
  expect(response.error, `WP AJAX error: ${response.error?.code ?? ''}`).toBeUndefined();
}