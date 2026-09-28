import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../admin/js/sscribe-debug-console.js', import.meta.url), 'utf8');
function consoleWithLocale(globals = {}) {
 const calls = [];
 const jquery = () => ({ ready() {} });
 const window = { wp: { i18n: {
  __(text, domain) { assert.equal(domain, 'sscribe-export-site-pages'); return `translated<${text}>`; },
  _n(single, plural, count, domain) { assert.equal(domain, 'sscribe-export-site-pages'); calls.push(count); return `%d ${count === 1 ? 'one' : count === 2 ? 'dual' : 'many'}`; },
  sprintf(format, ...args) { let i = 0; return format.replace(/%(?:(\d+)\$)?[ds]/g, (_, pos) => args[pos ? Number(pos) - 1 : i++]); },
 } } };
 vm.runInNewContext(source, { window, jQuery: jquery, document: {}, URL, ...globals });
 return { ui: window.SScribeDebugConsole, calls, jquery, window };
}
test('entry totals use WordPress locale plural selection including dual and zero', () => {
 const { ui, calls } = consoleWithLocale();
 let rendered;
 ui.$entryCount = { text(value) { rendered = value; } };
 for (const [count, expected] of [[0, '0 many'], [1, '1 one'], [2, '2 dual'], [5, '5 many']]) {
  ui.entryCountText(count); assert.equal(rendered, expected);
 }
 assert.deepEqual(calls, [0, 1, 2, 5]);
});
test('loading and archive empty states translate and escape HTML', () => {
 const { ui } = consoleWithLocale(); let html;
 ui.$entries = { append(value) { html = value; } };
 ui.showAppendLoading();
 assert.match(html, /translated&lt;Loading more entries\.\.\.&gt;/);
 ui.$rotatedBody = { html(value) { html = value; } };
 ui.renderRotatedLogs([]);
 assert.match(html, /translated&lt;No rotated log files\.&gt;/);
});
test('context ARIA translation and substitutions stay escaped', () => {
 const { ui } = consoleWithLocale();
 const html = ui.buildLogsHtml([{ level: 'INFO', message: '<unsafe>', timestamp: '', context: { x: 1 } }]);
 assert.match(html, /translated&lt;Toggle context for: &lt;unsafe&gt;&gt;/);
 assert.doesNotMatch(html, /aria-label="[^"\n]*<unsafe>/);
});
test('opening a rotated log updates history and renders the translated archive view', () => {
 const navigations = [];
 const history = {
  state: null,
  pushState(state, _title, url) { this.state = state; navigations.push(url); },
  replaceState() { assert.fail('A newly opened archive must add a history entry'); },
 };
 const { ui, jquery, window, calls } = consoleWithLocale({
  history,
  sscribe_data: { ajaxurl: '/wp-admin/admin-ajax.php', nonce: 'test-nonce' },
 });
 window.location = { href: 'https://example.test/wp-admin/admin.php?page=sscribe&tab=debug' };
 let respond, countText, entriesHtml, bannerHtml, backHandler;
 jquery.post = (url, data, success) => {
  assert.equal(url, '/wp-admin/admin-ajax.php');
  assert.equal(data.action, 'sscribe_debug_fetch_rotated');
  assert.equal(data.nonce, 'test-nonce');
  assert.equal(data.filename, 'archive<&>.log');
  respond = success;
  return { fail() { return this; } };
 };
 ui.$entries = {
  find(selector) {
   return {
    remove() {},
    off() { return this; },
    on(event, handler) {
     assert.equal(selector, '#sscribe-back-to-current');
     assert.equal(event, 'click.sscribe');
     backHandler = handler;
    },
   };
  },
  html(value) { entriesHtml = value; },
  prepend(value) { bannerHtml = value; },
 };
 ui.$empty = { hide() {}, find() { return { text() {} }; } };
 ui.$entryCount = { text(value) { countText = value; } };
 ui.$consoleBody = { 0: { scrollTop: 80, scrollHeight: 400, clientHeight: 200 } };
 ui.viewRotatedLog('archive<&>.log');
 respond({ success: true, data: { count: 2, entries: [
  { level: 'INFO', message: 'Archived <first>', timestamp: '', context: null },
  { level: 'WARN', message: 'Archived second', timestamp: '', context: null },
 ] } });
 assert.equal(navigations.length, 1);
 const url = new URL(navigations[0]);
 assert.equal(url.searchParams.get('page'), 'sscribe');
 assert.equal(url.searchParams.get('tab'), 'debug');
 assert.equal(url.searchParams.get('view'), 'rotated');
 assert.equal(url.searchParams.get('file'), 'archive<&>.log');
 assert.equal(history.state.view, 'rotated');
 assert.equal(history.state.file, 'archive<&>.log');
 assert.equal(ui.isViewingRotated, true);
 assert.equal(ui.currentRotatedFilename, 'archive<&>.log');
 assert.equal(ui.viewRotatedRequest, null);
 assert.equal(ui.hasMoreEntries, false);
 assert.equal(countText, '2 dual');
 assert.deepEqual(calls, [2]);
 assert.match(entriesHtml, /Archived &lt;first&gt;/);
 assert.match(entriesHtml, /Archived second/);
 assert.match(bannerHtml, /translated&lt;Viewing archived log: archive&lt;&amp;&gt;\.log&gt;/);
 assert.match(bannerHtml, /translated&lt;Back to current log&gt;/);
 assert.equal(typeof backHandler, 'function');
 assert.equal(ui.$consoleBody[0].scrollTop, 0);
});
