import { request, type APIRequestContext } from '@playwright/test';

/**
 * Test-only helper: clears any in-flight SScribe export session in the
 * WordPress DB so the NEXT page load sees a clean slate.
 *
 * Why this exists: the batch-retry-policy spec runs 4 tests in sequence.
 * Test #1 (429 quota) runs a FULL export to completion, which leaves a
 * session row + `sscribe_active_sid_*` transient in the DB. When test
 * #2 (503) loads the admin page, the startup AJAX
 * `sscribe_check_active_session` finds that session and disables the
 * export button (admin/js/sscribe-admin.js:914). Every subsequent test
 * in the file then inherits the "session in progress" state and the
 * export button never enables.
 *
 * The reset endpoint lives at `?ssb_test_reset=1` on any URL, gated by
 * the `SSCRIBE_E2E_TESTBED` constant defined in
 * `tests-e2e/fixtures/mu-plugins/00-sscribe-test-bootstrap.php`. Never
 * reachable in production because the mu-plugin is testbed-only.
 *
 * FAIL LOUD: a failed reset must fail the test setup at the reset call,
 * not manifest 30 seconds later as a disabled Export button. The helper
 * throws on:
 *   - non-2xx status
 *   - non-JSON Content-Type
 *   - malformed JSON body
 *   - JSON body where success !== true
 *
 * The thrown error carries status, content-type, and a body prefix
 * (first 400 chars) so a CI failure log points at the exact cause.
 *
 * Usage: call once before each retry-policy test's `adminPage.goto()`:
 *   await resetTestSession(request, baseURL);
 *
 * Idempotent: clears 0 or N rows per call; safe to call on a clean DB.
 */
export async function resetTestSession(
  req: APIRequestContext | { request: APIRequestContext },
  baseURL: string
): Promise<{ active_keys_cleared: number; session_keys_cleared: number }> {
  const apiReq = 'request' in req ? req.request : req;
  const url = `${baseURL}/?ssb_test_reset=1`;
  const resp = await apiReq.get(url, { headers: { Accept: 'application/json' } });
  if (!resp.ok()) {
    throw new Error(
      `[resetTestSession] HTTP ${resp.status()} from ${url} (expected 2xx). ` +
      `content-type=${resp.headers()['content-type'] || 'none'}`
    );
  }
  const ct = (resp.headers()['content-type'] || '').toLowerCase();
  if (!ct.includes('application/json')) {
    const body = (await resp.text()).slice(0, 400);
    throw new Error(
      `[resetTestSession] non-JSON response from ${url}. ` +
      `content-type=${ct} body=${JSON.stringify(body)}`
    );
  }
  let body: unknown;
  try {
    body = await resp.json();
  } catch (e) {
    const raw = await resp.text().catch(() => '<unreadable>');
    throw new Error(
      `[resetTestSession] malformed JSON from ${url}: ${(e as Error).message}. ` +
      `body_prefix=${JSON.stringify(raw.slice(0, 400))}`
    );
  }
  const ok = typeof body === 'object' && body !== null && (body as { success?: unknown }).success === true;
  if (!ok) {
    throw new Error(
      `[resetTestSession] success !== true from ${url}. body=${JSON.stringify(body).slice(0, 400)}`
    );
  }
  return body as { active_keys_cleared: number; session_keys_cleared: number };
}

/**
 * Test-only helper: cancel every in-flight SScribe export session
 * across all users via the testbed cancel-all endpoint. The endpoint
 * marks each session as `cancelled=true` in the WP DB.
 *
 * FAIL LOUD contract: same as resetTestSession. See the JSDoc above for
 * the throw conditions and rationale.
 *
 * Usage: call before resetTestSession when a prior test left a live
 * batch loop running that re-creates the transient row on every tick.
 */
export async function cancelAllSessions(
  req: APIRequestContext | { request: APIRequestContext },
  baseURL: string
): Promise<{ success: boolean; cancelled_count: number }> {
  const apiReq = 'request' in req ? req.request : req;
  const url = `${baseURL}/?ssb_test_cancel_all=1`;
  const resp = await apiReq.get(url, { headers: { Accept: 'application/json' } });
  if (!resp.ok()) {
    throw new Error(
      `[cancelAllSessions] HTTP ${resp.status()} from ${url} (expected 2xx). ` +
      `content-type=${resp.headers()['content-type'] || 'none'}`
    );
  }
  const ct = (resp.headers()['content-type'] || '').toLowerCase();
  if (!ct.includes('application/json')) {
    const body = (await resp.text()).slice(0, 400);
    throw new Error(
      `[cancelAllSessions] non-JSON response from ${url}. ` +
      `content-type=${ct} body=${JSON.stringify(body)}`
    );
  }
  let body: unknown;
  try {
    body = await resp.json();
  } catch (e) {
    const raw = await resp.text().catch(() => '<unreadable>');
    throw new Error(
      `[cancelAllSessions] malformed JSON from ${url}: ${(e as Error).message}. ` +
      `body_prefix=${JSON.stringify(raw.slice(0, 400))}`
    );
  }
  const ok = typeof body === 'object' && body !== null && (body as { success?: unknown }).success === true;
  if (!ok) {
    throw new Error(
      `[cancelAllSessions] success !== true from ${url}. body=${JSON.stringify(body).slice(0, 400)}`
    );
  }
  return body as { success: boolean; cancelled_count: number };
}
