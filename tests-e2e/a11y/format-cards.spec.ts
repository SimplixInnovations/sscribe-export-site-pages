import { test, expect } from '../fixtures/shared';

/**
 * Regression: 5-up format-cards grid must not break "Markdown" mid-character.
 *
 * Background (memory `markdown-format-card-word-break.md`):
 * The format-cards wrapper uses `grid-template-columns: repeat(5, minmax(0, 1fr))`
 * (admin/css/sscribe-admin.css:3882) and the `.sscribe-format-meta` child has
 * `min-width: 0` (line 570) which allows the flex column to shrink below
 * intrinsic content width. With `word-break: normal` and `overflow-wrap: normal`
 * on `.sscribe-format-name` (lines 584-585), the long word "Markdown" is
 * expected to either fit on one line or wrap as a whole word — never break
 * mid-character like "Mark-" / "down".
 *
 * If a future change flips `word-break: break-all` (or removes `overflow-wrap:
 * normal`), the word will clip at the column boundary and the spec fails.
 */
test.describe('a11y / format-cards', () => {
  test('markdown-format-card word break: format-name must not break mid-character', async ({ adminPage }) => {
    // 5-up grid + min-width:0 surfaces this regression only when the grid is
    // narrower than ~880px. Resize before navigation so the layout is in its
    // "problem" state from the first paint.
    await adminPage.setViewportSize({ width: 880, height: 800 });
    await adminPage.goto('/wp-admin/admin.php?page=sscribe-export');

    const formatCards = adminPage.locator('#sscribe-format-cards');
    await expect(formatCards).toBeVisible();

    // Pin the markdown card by its radio value (phantom selector
    // .sscribe-format-card[data-format="markdown"] is wrong; SELECTORS.md §3).
    const markdownRadio = adminPage.locator('input[name="sscribe_format"][value="markdown"]');
    await expect(markdownRadio).toBeAttached();
    const markdownLabel = markdownRadio.locator('xpath=ancestor::label[contains(@class,"sscribe-format-card-label")]').first();
    const formatName = markdownLabel.locator('.sscribe-format-name');
    await expect(formatName).toHaveText(/^Markdown$/);

    // Production contract: word-break:normal + overflow-wrap:normal means the
    // browser can NEVER break "Markdown" between letters — only between the
    // boundary that exists at the end of the column. Check the computed style.
    const computed = await formatName.evaluate((el) => {
      const cs = window.getComputedStyle(el);
      return {
        wordBreak: cs.wordBreak,
        overflowWrap: cs.overflowWrap,
        hyphens: cs.hyphens,
      };
    });

    expect(computed.wordBreak, '.sscribe-format-name word-break must be "normal" (or "break-word")').toMatch(
      /^(normal|break-word|break-spaces)$/
    );
    // overflow-wrap: anywhere or break-word would force mid-character breaks
    // on a word that already fits on its own line. We require normal so the
    // browser can only break at whitespace boundaries.
    expect(computed.overflowWrap, '.sscribe-format-name overflow-wrap must be "normal"').toBe('normal');

    // Visual check: even with the small viewport, "Markdown" must render as
    // one or two whole-word lines — never as a single character clipped at
    // the column edge. We compare the visible width of the text element to
    // the height it actually takes. If the word were broken per-character,
    // height would be ≥ 4 lines (one glyph per row); the contract is ≤ 2.
    const dims = await formatName.evaluate((el) => {
      const r = el.getBoundingClientRect();
      const lineHeight = parseFloat(window.getComputedStyle(el).lineHeight);
      return {
        width: r.width,
        height: r.height,
        lineHeight,
        approxLines: Math.round(r.height / lineHeight),
        text: el.textContent || '',
      };
    });
    // 8 chars × ~9px ≈ 72px; on a 152px-wide card with padding the text fits
    // in 1 line. If the layout collapses the column past ~50px (very narrow
    // viewports), 2 lines is the maximum — anything higher means per-char
    // breaking, which is the regression.
    expect(dims.approxLines, 'Markdown must not break mid-character').toBeLessThanOrEqual(2);
    expect(dims.text.trim()).toBe('Markdown');
  });
});
