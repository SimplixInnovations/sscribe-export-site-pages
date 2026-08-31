import { expect } from '@playwright/test';

export interface JSONResponse {
  success: boolean;
  data?: unknown;
  error?: unknown;
  [k: string]: unknown;
}

export function assertJSONOK(response: JSONResponse, expected: Record<string, unknown> = {}): void {
  expect(response, 'WP AJAX response shape').toMatchObject({ success: true, ...expected });
  expect(response.error, `WP AJAX error: ${String((response.error as { code?: unknown })?.code ?? '')}`).toBeUndefined();
}