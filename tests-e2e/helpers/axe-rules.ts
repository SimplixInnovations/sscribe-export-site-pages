import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect } from '@playwright/test';

export const AXE_TAGS = [
  'wcag2a',
  'wcag2aa',
  'wcag21a',
  'wcag21aa',
  'wcag22a',
  'wcag22aa',
  'best-practice',
] as const;

export interface AxeRunOptions {
  excludeSelectors?: string[];
  includeSelectors?: string[];
}

/**
 * Runs @axe-core/playwright against the current page with the WCAG 2.2 AA
 * + best-practice ruleset. Fails the test on any violation ≥ minor impact.
 */
export async function runAxe(page: Page, opts: AxeRunOptions = {}): Promise<void> {
  let builder = new AxeBuilder({ page }).withTags([...AXE_TAGS]);
  for (const sel of opts.includeSelectors ?? []) {
    builder = builder.include(sel);
  }
  for (const sel of opts.excludeSelectors ?? []) {
    builder = builder.exclude(sel);
  }
  const results = await builder.analyze();
  const violations = results.violations.filter(
    (v) => v.impact === 'minor' || v.impact === 'moderate' || v.impact === 'serious' || v.impact === 'critical'
  );
  if (violations.length > 0) {
    const formatted = violations
      .map(
        (v) =>
          `[${v.impact}] ${v.id} (${v.help}): ${v.nodes.length} node(s)\n  ${v.nodes
            .map((n) => n.html)
            .slice(0, 3)
            .join('\n  ')}`
      )
      .join('\n');
    expect(violations, `axe violations:\n${formatted}`).toHaveLength(0);
  }
}