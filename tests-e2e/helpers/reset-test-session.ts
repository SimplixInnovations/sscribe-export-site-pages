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
 * WP_DEBUG + mu-plugin presence in
 * `tests-e2e/fixtures/mu-plugins/00-sscribe-test-bootstrap.php`. Never
 * reachable in production because the mu-plugin is testbed-only.
 *
 * Usage: call once before each retry-policy test's `adminPage.goto()`:
 *   await resetTestSession(request, baseURL);
 *
 * Idempotent: clears 0 or N rows per call; safe to call on a clean DB.
 */
export async function resetTestSession(
  req: APIRequestContext | { request: APIRequestContext },
  baseURL: string
): Promise<{ active_keys_cleared: number; session_keys_cleared: number } | null> {
  const apiReq = 'request' in req ? req.request : req;
  const resp = await apiReq.get(`${baseURL}/?ssb_test_reset=1`);
  if (!resp.ok()) {
    return null;
  }
  try {
    return await resp.json();
  } catch {
    return null;
  }
}
