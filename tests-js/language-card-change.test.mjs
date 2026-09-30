import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../admin/js/sscribe-admin.js', import.meta.url), 'utf8');
// Language cards are <label>s wrapping their radio. A click handler that set
// `checked` itself ran before the browser's own label activation, so the radio
// was already checked when the browser clicked it and no change event fired.
// The counts never refreshed for the new language, the page kept showing the
// old numbers, and Generate stayed disabled after the next status click.
test('language cards leave radio checking to the browser so change fires', () => {
 assert.doesNotMatch(source, /\.sscribe-lang-card-label['"]\)\.on\(\s*['"]click/);
 assert.doesNotMatch(source, /input\[type="radio"\]'\)\.prop\('checked',\s*true\)/);
 assert.match(source, /input\[name="sscribe_language"\]'\)\.on\('change\.sscribe',\s*\$\.proxy\(this\.onLanguageChange/);
});
