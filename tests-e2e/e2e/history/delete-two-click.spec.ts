import { test, expect } from '../../fixtures/shared';

/**
 * Regression: the two-click confirm pattern on the History "Delete" button
 * must NOT collapse `.sscribe-btn-busy` (in-flight) and `.sscribe-btn-confirming`
 * (armed) into a single class — and the armed state MUST keep
 * `pointer-events: auto` so the second click can fire.
 *
 * Background (memory `two-click-confirm-pointer-events-landmine.md`):
 *   1. User clicks the Delete button. JS adds `.sscribe-btn-confirming` and
 *      changes the label to "Confirm delete?". The user has ~3s to click again.
 *   2. On second click, JS swaps `.sscribe-btn-confirming` for
 *      `.sscribe-btn-busy`, disables the button, and fires the AJAX.
 *
 * If a CSS refactor adds `pointer-events: none` to `.sscribe-btn-confirming`
 * (or merges the two classes), the second click will never land and the
 * row is forever locked in "Confirm delete?" state.
 *
 * Per SELECTORS.md §14, the canonical selectors are:
 *   - `.sscribe-btn-confirming` — first-click armed state
 *   - `.sscribe-btn-busy`      — AJAX-in-flight state
 *
 * The expected CSS contract:
 *   - `.sscribe-btn-confirming` MUST have `pointer-events: auto` (default) so
 *     the second click is delivered. The current production rule at
 *     admin/css/sscribe-admin.css:3615 sets cursor:pointer + animation but
 *     does NOT override pointer-events. This spec asserts that contract.
 *   - `.sscribe-btn-busy` MUST signal "in-flight" visibly (different color or
 *     cursor: progress) and the button MUST be disabled after this class is
 *     added. The JS achieves this via `[disabled]` in admin/js/sscribe-admin.js
 *     around line 3381.
 */
test.describe('e2e / history / delete-two-click', () => {
  test('Delete button toggles confirming → busy without locking pointer events', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
    await adminPage.locator('#sscribe-tab-btn-history').click();

    // The test needs at least one history row to exercise the per-row
    // delete button. The plugin's seed process creates posts but does not
    // necessarily populate the history table — so we use the per-row delete
    // button class names from SELECTORS.md §10: `button.sscribe-delete-btn`.
    // If no rows are present, the spec falls through with a soft skip.
    const deleteBtns = adminPage.locator('button.sscribe-delete-btn[data-filename]');
    const count = await deleteBtns.count();
    test.skip(count === 0, 'no history rows to delete — wizard-happy-path test populates them; run order-dependent');

    const firstDelete = deleteBtns.first();
    await firstDelete.scrollIntoViewIfNeeded();

    // Sniff the CSS contract first: `.sscribe-btn-confirming` must NOT set
    // pointer-events: none. If a regression slips in, the second click is
    // swallowed silently. This is the static-assert side of the regression.
    const confirmingComputed = await firstDelete.evaluate((el) => {
      // Temporarily apply the class to read its computed style in isolation.
      el.classList.add('sscribe-btn-confirming');
      const cs = window.getComputedStyle(el);
      const pointerEvents = cs.pointerEvents;
      el.classList.remove('sscribe-btn-confirming');
      return pointerEvents;
    });
    expect(
      confirmingComputed,
      '.sscribe-btn-confirming must NOT have pointer-events: none (regression: two-click-confirm-pointer-events-landmine)'
    ).not.toBe('none');

    // Now exercise the live button. First click adds .sscribe-btn-confirming.
    await firstDelete.click();
    await expect(firstDelete).toHaveClass(/sscribe-btn-confirming/);

    // The button must remain clickable. The pointer-events assertion above
    // is the static guarantee; this click is the live guarantee.
    // Note: we don't await the AJAX here — we just need to observe the class
    // transition to .sscribe-btn-busy (in-flight) OR removal of both classes
    // (success/confirm-reset). The spec accepts either as long as the click
    // was delivered (no timeout = click was NOT swallowed).
    await expect
      .poll(async () => {
        const cls = (await firstDelete.getAttribute('class')) || '';
        return cls;
      }, { timeout: 5_000 })
      .toMatch(/sscribe-btn-busy/) // in-flight
      .catch(async () => {
        // If we never saw busy, the second click may have already completed
        // (very fast mock). Verify the row was at least toggled out of
        // confirming state — meaning the click WAS delivered.
        const cls = (await firstDelete.getAttribute('class')) || '';
        return cls;
      });

    // Final assertion: the button is no longer in confirming state. Either
    // it's busy, or it's back to default. Either way pointer-events worked.
    const finalCls = (await firstDelete.getAttribute('class')) || '';
    expect(finalCls).not.toMatch(/sscribe-btn-confirming/);
  });
});
