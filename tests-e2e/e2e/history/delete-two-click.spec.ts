import { test, expect } from '../../fixtures/shared';

/**
 * Regression: the two-click confirm pattern on the History "Delete" button
 * must NOT collapse `.sscribe-btn-busy` (in-flight) and `.sscribe-btn-confirming`
 * (armed) into a single class, and the armed state MUST keep
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
 *   - `.sscribe-btn-confirming` - first-click armed state
 *   - `.sscribe-btn-busy`      - AJAX-in-flight state
 *
 * The contract this spec enforces:
 *   - `.sscribe-btn-confirming` MUST have `pointer-events: auto` (default) so
 *     the second click is delivered. The current production rule at
 *     admin/css/sscribe-admin.css:3615 sets cursor:pointer + animation but
 *     does NOT override pointer-events. The live test below performs two
 *     real clicks and asserts the second click transitions the class state.
 *
 * Note on CI ordering: this spec depends on at least one history row existing.
 * The wizard-happy-path spec populates one. We soft-skip rather than hard-fail
 * to keep the suite runnable in isolation; the regression-discipline harness
 * (regression-discipline.mjs) still validates the static contract regardless.
 */
test.describe('e2e / history / delete-two-click', () => {
  test('Delete button toggles confirming via two-click, with pointer-events preserved', async ({ adminPage }) => {
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');
    await adminPage.locator('#sscribe-tab-btn-history').click();

    // The per-row delete button class names from SELECTORS.md §10:
    // `button.sscribe-delete-btn`. If no rows are present, the spec falls
    // through with a soft skip - the regression-discipline harness still
    // validates the static pointer-events contract via a separate path.
    const deleteBtns = adminPage.locator('button.sscribe-delete-btn[data-filename]');
    const count = await deleteBtns.count();
    test.skip(count === 0, 'no history rows to delete; wizard-happy-path test populates them (run order-dependent)');

    const firstDelete = deleteBtns.first();
    await firstDelete.scrollIntoViewIfNeeded();

    // STATIC CONTRACT (regression contract): `.sscribe-btn-confirming` must
    // NOT have pointer-events: none. If a regression slips in, the second
    // click would be swallowed silently by the browser. This is the
    // assertion the regression-discipline.mjs revert is designed to break.
    const confirmingComputed = await firstDelete.evaluate((el) => {
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

    // LIVE CONTRACT: the production JS swaps .sscribe-btn-confirming into
    // .sscribe-btn-busy on the second click. We verify the transition is
    // observable. Note: the production JS at admin/js/sscribe-admin.js:3387
    // has a 3s setTimeout that auto-removes .sscribe-btn-confirming if no
    // second click arrives. We click quickly so the timeout doesn't fire.
    await firstDelete.click();
    await expect(firstDelete).toHaveClass(/sscribe-btn-confirming/);

    // Second click must transition the class out of "confirming" state.
    // The new state is either ".sscribe-btn-busy" (in-flight) or the button
    // is disabled with no confirming class (AJAX finished before our poll).
    // Either way, .sscribe-btn-confirming must be gone.
    await firstDelete.click({ timeout: 5_000 });
    await expect
      .poll(
        async () => (await firstDelete.getAttribute('class')) || '',
        { timeout: 5_000 }
      )
      .not.toMatch(/sscribe-btn-confirming/);
  });
});
