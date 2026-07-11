#!/usr/bin/env python
"""Build a self-contained HTML preview of the sScribe admin UI for visual QA.

This assembles the actual shipped CSS plus a representative markup so the user
can open it in a browser and confirm the visual state.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
css_path = ROOT / 'admin' / 'css' / 'sscribe-admin.css'
out_path = ROOT / 'docs' / 'ui-preview.html'

with css_path.open('r', encoding='utf-8') as fh:
    css = fh.read()

icon_doc = (
    '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" '
    'stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 '
    '2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
    '<polyline points="14 2 14 8 20 8"/></svg>'
)
icon_settings = (
    '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" '
    'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
    'stroke-linejoin="round"><circle cx="12" cy="12" r="3"/></svg>'
)
icon_clock = (
    '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" '
    'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
    'stroke-linejoin="round"><circle cx="12" cy="12" r="10"/>'
    '<polyline points="12 6 12 12 16 14"/></svg>'
)
icon_info = (
    '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" '
    'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
    'stroke-linejoin="round"><circle cx="12" cy="12" r="10"/>'
    '<line x1="12" y1="16" x2="12" y2="12"/>'
    '<line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
)
icon_check = (
    '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" '
    'stroke="currentColor" stroke-width="3" stroke-linecap="round" '
    'stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
)
icon_download = (
    '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" '
    'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
    'stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 '
    '1-2-2v-4"/><polyline points="7 10 12 15 17 10"/>'
    '<line x1="12" y1="15" x2="12" y2="3"/></svg>'
)

html = f'''<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1200">
<title>sScribe Admin Visual Preview</title>
<style>{css}

body.toplevel_page_sscribe-export {{
  background: var(--ss-bg-subtle);
}}
.faux-admin-bar {{
  height: 32px; background: #1d2327; color: #c3c4c7;
  display: flex; align-items: center; padding: 0 16px;
  font-size: 13px;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}}
.faux-admin-bar span {{ margin-right: 16px; opacity: 0.85; }}
#wpcontent {{ background: var(--ss-bg-subtle); min-height: 100vh; }}
#wpbody-content {{ padding: 0 20px 32px; box-sizing: border-box; }}
.sscribe-component-row {{
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding: 14px 0 22px;
  border-bottom: 1px dashed var(--ss-color-border, #e4e4e7);
}}
.sscribe-component-row:last-child {{ border-bottom: none; }}
.sscribe-component-row h3 {{
  flex: 0 0 100%;
  margin: 0 0 4px;
  font-weight: 600;
  color: var(--ss-color-fg-primary, #09090b);
}}
.sscribe-switch {{
  display: inline-flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  font: 500 13px/1.4 var(--ss-font, -apple-system, sans-serif);
  color: var(--ss-color-fg-secondary, #3f3f46);
  margin: 0;
}}
.sscribe-switch input {{ position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; }}
.sscribe-switch-track {{
  position: relative;
  width: 36px;
  height: 20px;
  background: var(--ss-color-bg-inset, #e4e4e7);
  border: 1px solid var(--ss-color-border, #d4d4d8);
  border-radius: 9999px;
  transition: background 120ms cubic-bezier(0.2, 0, 0, 1), border-color 120ms cubic-bezier(0.2, 0, 0, 1);
  flex-shrink: 0;
}}
.sscribe-switch-knob {{
  position: absolute;
  top: 1px;
  left: 1px;
  width: 16px;
  height: 16px;
  background: var(--ss-color-fg-tertiary, #71717a);
  border-radius: 50%;
  transition: transform 120ms cubic-bezier(0.2, 0, 0, 1), background 120ms cubic-bezier(0.2, 0, 0, 1);
}}
.sscribe-switch input:checked + .sscribe-switch-track {{
  background: var(--ss-color-accent, #3d7a5a);
  border-color: var(--ss-color-accent, #3d7a5a);
}}
.sscribe-switch input:checked + .sscribe-switch-track .sscribe-switch-knob {{
  transform: translateX(16px);
  background: #ffffff;
}}
.sscribe-progress {{
  width: 220px;
  height: 6px;
  background: var(--ss-color-bg-skeleton, #e4e4e7);
  border-radius: 9999px;
  overflow: hidden;
  display: block;
}}
.sscribe-progress-fill {{
  display: block;
  height: 100%;
  width: var(--p, 0%);
  background: var(--ss-color-accent, #3d7a5a);
  border-radius: inherit;
  transition: width 120ms cubic-bezier(0.2, 0, 0, 1);
}}
.sscribe-switch-with-label {{
  display: inline-flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  font: 500 13px/1.4 var(--ss-font, -apple-system, sans-serif);
  color: var(--ss-color-fg-secondary, #3f3f46);
  margin: 0;
}}
</style>
</head>
<body class="toplevel_page_sscribe-export">
<div class="faux-admin-bar">
  <span>WordPress Admin</span>
  <span>sScribe Export Site Pages</span>
</div>
<div id="wpcontent">
<div id="wpbody">
<div id="wpbody-content">

<a class="sscribe-skip-link screen-reader-text" href="#main">Skip to export</a>
<div class="sscribe-master-container">

  <header class="sscribe-hero">
    <div class="sscribe-hero-content">
      <div class="sscribe-hero-left">
        <div class="sscribe-hero-logo">{icon_doc}</div>
        <div class="sscribe-hero-text">
          <span class="sscribe-hero-eyebrow">SSCRIBE &middot; ENTERPRISE EXPORT</span>
          <div class="sscribe-hero-title-row">
            <h1 class="sscribe-hero-title">SScribe</h1>
            <span class="sscribe-hero-version">v1.1.3</span>
          </div>
          <p class="sscribe-hero-subtitle">Export every page into beautifully formatted documents with multilingual support, SEO meta, and secure ZIP download.</p>
        </div>
      </div>
    </div>
    <div class="sscribe-hero-stats">
      <span class="sscribe-hero-stat">{icon_clock}<strong>247</strong>&nbsp;pages</span>
      <span class="sscribe-hero-stat">{icon_doc}<strong>18</strong>&nbsp;exports</span>
    </div>
  </header>

  <div class="sscribe-workspace sscribe-flat-workspace" id="sscribe-main-content" role="main">

    <nav class="sscribe-tabs-nav" role="tablist">
      <button type="button" class="sscribe-tab-btn sscribe-tab-active" role="tab" aria-selected="true">{icon_settings}Export</button>
      <button type="button" class="sscribe-tab-btn" role="tab" aria-selected="false">{icon_clock}History</button>
      <button type="button" class="sscribe-tab-btn" role="tab" aria-selected="false">{icon_info}Support</button>
    </nav>

    <div class="sscribe-tab-content sscribe-tab-active">

      <section class="sscribe-panel sscribe-config-panel">
        <div class="sscribe-panel-header">
          <div class="sscribe-panel-title">{icon_settings}<h2>Export Configuration</h2></div>
        </div>
        <div class="sscribe-panel-body sscribe-flat-body">
          <div class="sscribe-config-grid">

            <div class="sscribe-config-section">
              <div class="sscribe-section-title"><span class="sscribe-step-badge">1</span>Content Type</div>
              <div class="sscribe-post-type-cards sscribe-cards-compact">
                <label class="sscribe-post-type-card">
                  <input type="radio" name="pt" checked>
                  <div class="sscribe-post-type-card-inner">
                    <div class="sscribe-post-type-icon">{icon_doc}</div>
                    <div class="sscribe-post-type-meta">
                      <span class="sscribe-post-type-name">Pages</span>
                      <span class="sscribe-post-type-count">247 items</span>
                    </div>
                    <div class="sscribe-post-type-selector">{icon_check}</div>
                  </div>
                </label>
                <label class="sscribe-post-type-card">
                  <input type="radio" name="pt">
                  <div class="sscribe-post-type-card-inner">
                    <div class="sscribe-post-type-icon">{icon_info}</div>
                    <div class="sscribe-post-type-meta">
                      <span class="sscribe-post-type-name">Posts</span>
                      <span class="sscribe-post-type-count">152 items</span>
                    </div>
                    <div class="sscribe-post-type-selector">{icon_check}</div>
                  </div>
                </label>
                <label class="sscribe-post-type-card">
                  <input type="radio" name="pt">
                  <div class="sscribe-post-type-card-inner">
                    <div class="sscribe-post-type-icon">{icon_clock}</div>
                    <div class="sscribe-post-type-meta">
                      <span class="sscribe-post-type-name">Both</span>
                      <span class="sscribe-post-type-count">399 items</span>
                    </div>
                    <div class="sscribe-post-type-selector">{icon_check}</div>
                  </div>
                </label>
              </div>
            </div>

            <div class="sscribe-config-section">
              <div class="sscribe-section-title"><span class="sscribe-step-badge">2</span>Status Filter</div>
              <div class="sscribe-status-cards sscribe-cards-compact">
                <label class="sscribe-status-card-label">
                  <input type="radio" name="st" value="published" checked>
                  <div class="sscribe-status-card-inner">
                    <div class="sscribe-status-icon">{icon_check}</div>
                    <div class="sscribe-status-meta">
                      <span class="sscribe-status-name">Published</span>
                      <span class="sscribe-status-count">198</span>
                    </div>
                  </div>
                </label>
                <label class="sscribe-status-card-label">
                  <input type="radio" name="st" value="draft">
                  <div class="sscribe-status-card-inner">
                    <div class="sscribe-status-icon">{icon_clock}</div>
                    <div class="sscribe-status-meta">
                      <span class="sscribe-status-name">Drafts</span>
                      <span class="sscribe-status-count">32</span>
                    </div>
                  </div>
                </label>
                <label class="sscribe-status-card-label">
                  <input type="radio" name="st" value="private">
                  <div class="sscribe-status-card-inner">
                    <div class="sscribe-status-icon">{icon_info}</div>
                    <div class="sscribe-status-meta">
                      <span class="sscribe-status-name">Private</span>
                      <span class="sscribe-status-count">17</span>
                    </div>
                  </div>
                </label>
              </div>
            </div>

            <div class="sscribe-config-section">
              <div class="sscribe-section-title"><span class="sscribe-step-badge">3</span>Output Format</div>
              <div class="sscribe-format-cards sscribe-cards-compact">
                <label class="sscribe-format-card-label">
                  <input type="radio" name="fmt" value="docx" checked>
                  <div class="sscribe-format-card-inner">
                    <div class="sscribe-format-icon">{icon_doc}</div>
                    <div class="sscribe-format-meta">
                      <span class="sscribe-format-name">Word Document</span>
                      <span class="sscribe-format-desc">.docx &middot; editable</span>
                    </div>
                  </div>
                </label>
                <label class="sscribe-format-card-label">
                  <input type="radio" name="fmt" value="pdf">
                  <div class="sscribe-format-card-inner">
                    <div class="sscribe-format-icon">{icon_doc}</div>
                    <div class="sscribe-format-meta">
                      <span class="sscribe-format-name">PDF</span>
                      <span class="sscribe-format-desc">.pdf &middot; printable</span>
                    </div>
                  </div>
                </label>
                <label class="sscribe-format-card-label">
                  <input type="radio" name="fmt" value="html">
                  <div class="sscribe-format-card-inner">
                    <div class="sscribe-format-icon">{icon_doc}</div>
                    <div class="sscribe-format-meta">
                      <span class="sscribe-format-name">HTML</span>
                      <span class="sscribe-format-desc">.html &middot; web-ready</span>
                    </div>
                  </div>
                </label>
                <label class="sscribe-format-card-label">
                  <input type="radio" name="fmt" value="md">
                  <div class="sscribe-format-card-inner">
                    <div class="sscribe-format-icon">{icon_doc}</div>
                    <div class="sscribe-format-meta">
                      <span class="sscribe-format-name">Markdown</span>
                      <span class="sscribe-format-desc">.md &middot; plain text</span>
                    </div>
                  </div>
                </label>
              </div>
            </div>

          </div>
        </div>
      </section>

      <div class="sscribe-status-alert sscribe-status-success">
        <div class="sscribe-alert-icon">{icon_check}</div>
        <div class="sscribe-alert-body">
          <span class="sscribe-status-eyebrow">READY &middot; ALL CHECKS PASSED</span>
          <h3 class="sscribe-status-heading">Configuration complete and ready to export</h3>
          <p class="sscribe-status-desc">Your selection includes 247 pages filtered by publication status, formatted as Word Documents.</p>
        </div>
      </div>

      <div class="sscribe-export-bar">
        <div class="sscribe-config-summary">
          <span class="sscribe-summary-label">SUMMARY</span>
          <span class="sscribe-summary-chip">Pages</span>
          <span class="sscribe-summary-chip">Published</span>
          <span class="sscribe-summary-chip">DOCX</span>
          <span class="sscribe-summary-sep">&rarr;</span>
          <span class="sscribe-summary-chip sscribe-summary-pages">247 pages</span>
          <span class="sscribe-summary-sep">&middot;</span>
          <span class="sscribe-summary-chip sscribe-summary-time">~8m</span>
        </div>
        <div class="sscribe-export-bar-actions">
          <button type="button" class="sscribe-button sscribe-button-outline">{icon_info}Preview</button>
          <button type="button" class="sscribe-button sscribe-button-secondary">Save Profile</button>
          <button type="button" class="sscribe-button sscribe-button-primary">{icon_download}Export Now</button>
        </div>
      </div>

    </div>

    <section class="sscribe-panel" style="margin-top: 24px;">
      <div class="sscribe-panel-header">
        <div class="sscribe-panel-title">{icon_info}<h2>Component Library (v3)</h2></div>
      </div>
      <div class="sscribe-panel-body">

        <nav class="sscribe-breadcrumb" aria-label="Breadcrumb">
          <a href="#">Export</a>
          <span class="sscribe-breadcrumb-sep" aria-hidden="true">&rsaquo;</span>
          <a href="#">History</a>
          <span class="sscribe-breadcrumb-sep" aria-hidden="true">&rsaquo;</span>
          <span aria-current="page">2026-07-07-fidelity-package</span>
        </nav>

        <div class="sscribe-component-row">
          <h3 class="sscribe-type-h3">Buttons</h3>
          <button type="button" class="sscribe-button sscribe-button-primary">{icon_download}Export Now</button>
          <button type="button" class="sscribe-button sscribe-button-secondary">Save Profile</button>
          <button type="button" class="sscribe-button sscribe-button-outline">Preview</button>
          <button type="button" class="sscribe-button sscribe-button-ghost">Cancel</button>
          <button type="button" class="sscribe-button sscribe-button-success">{icon_check}Done</button>
          <button type="button" class="sscribe-button sscribe-button-danger">Delete</button>
          <button type="button" class="sscribe-button sscribe-button-cancel">Stop</button>
        </div>

        <div class="sscribe-component-row">
          <h3 class="sscribe-type-h3">Sizes &amp; icons</h3>
          <button type="button" class="sscribe-button sscribe-button-primary ss-btn-sm">Small</button>
          <button type="button" class="sscribe-button sscribe-button-primary">Default</button>
          <button type="button" class="sscribe-button sscribe-button-primary ss-btn-lg">Large</button>
          <button type="button" class="sscribe-button-icon" aria-label="Refresh">{icon_clock}</button>
          <button type="button" class="sscribe-button-icon sscribe-button-icon-danger" aria-label="Delete">{icon_info}</button>
        </div>

        <div class="sscribe-component-row">
          <h3 class="sscribe-type-h3">Inputs</h3>
          <input type="text" class="sscribe-input" placeholder="Export name" />
          <select class="sscribe-select" style="width: 200px;">
            <option>English</option>
            <option>Arabic</option>
          </select>
          <textarea class="sscribe-textarea" rows="2" style="width: 280px;" placeholder="Description"></textarea>
          <input type="text" class="sscribe-input" inputmode="numeric" value="247" style="width: 100px;" />
        </div>

        <div class="sscribe-component-row">
          <h3 class="sscribe-type-h3">Chips &amp; badges</h3>
          <span class="sscribe-chip">Pages</span>
          <span class="sscribe-chip sscribe-chip-solid">247</span>
          <span class="sscribe-chip sscribe-chip-success">Ready</span>
          <span class="sscribe-chip sscribe-chip-warning">Pending</span>
          <span class="sscribe-chip sscribe-chip-error">Failed</span>
          <span class="sscribe-chip sscribe-chip-info">Info</span>
          <span class="sscribe-badge">NEW</span>
          <span class="sscribe-badge sscribe-badge-success">OK</span>
        </div>

        <div class="sscribe-component-row">
          <h3 class="sscribe-type-h3">Code &amp; kbd</h3>
          <code class="sscribe-code">sscribe_export_options_pdf</code>
          <kbd class="sscribe-kbd">Ctrl</kbd>
          <span>+</span>
          <kbd class="sscribe-kbd">S</kbd>
          <span>to save</span>
        </div>

        <div class="sscribe-component-row">
          <h3 class="sscribe-type-h3">Switch, segmented &amp; progress</h3>
          <label class="sscribe-switch-with-label">
            <span class="sscribe-switch">
              <input type="checkbox" checked />
              <span class="sscribe-switch-track"><span class="sscribe-switch-knob"></span></span>
            </span>
            <span>Include drafts</span>
          </label>
          <div class="sscribe-segmented" role="radiogroup" aria-label="Format family">
            <button type="button" class="sscribe-segmented-item sscribe-segmented-item-active" role="radio" aria-checked="true">All</button>
            <button type="button" class="sscribe-segmented-item" role="radio" aria-checked="false">Document</button>
            <button type="button" class="sscribe-segmented-item" role="radio" aria-checked="false">Web</button>
          </div>
          <div class="sscribe-progress" style="--p: 64%;" role="progressbar" aria-valuenow="64" aria-valuemin="0" aria-valuemax="100">
            <div class="sscribe-progress-fill"></div>
          </div>
        </div>

        <div class="sscribe-component-row">
          <h3 class="sscribe-type-h3">Tooltip &amp; empty state</h3>
          <button type="button" class="sscribe-button sscribe-button-outline" data-tooltip="Run with English + Arabic in parallel">Multi-language</button>
          <div class="sscribe-empty-state" style="background: var(--ss-color-bg-surface); border: 1px solid var(--ss-color-border); border-radius: var(--ss-radius-lg, 12px); margin-top: 12px;">
            <div class="sscribe-empty-state-icon">{icon_doc}</div>
            <div class="sscribe-empty-state-title">No exports yet</div>
            <p class="sscribe-empty-state-body">Pick a content type, choose a format, and click Export Now. The result will appear here within minutes.</p>
            <button type="button" class="sscribe-button sscribe-button-primary">Start first export</button>
          </div>
        </div>

        <div class="sscribe-component-row">
          <h3 class="sscribe-type-h3">Form rows</h3>
          <div class="sscribe-form-grid">
            <div class="sscribe-form-row">
              <label class="sscribe-form-label">Export name</label>
              <input type="text" class="sscribe-input" value="2026-07-07-fidelity-package" />
              <span class="sscribe-form-helper">Lowercase, dash-separated. Used in the ZIP file name.</span>
            </div>
            <div class="sscribe-form-row">
              <label class="sscribe-form-label">Owner email</label>
              <input type="email" class="sscribe-input" value="compliance@example.com" />
              <span class="sscribe-form-helper">Notified when the export completes.</span>
            </div>
          </div>
          <fieldset class="sscribe-fieldset">
            <legend>Advanced</legend>
            <label class="sscribe-switch-with-label">
              <span class="sscribe-switch">
                <input type="checkbox" />
                <span class="sscribe-switch-track"><span class="sscribe-switch-knob"></span></span>
              </span>
              <span>Embed external images</span>
            </label>
          </fieldset>
        </div>

        <div class="sscribe-component-row">
          <h3 class="sscribe-type-h3">Toasts</h3>
          <div class="sscribe-toast sscribe-toast-success">{icon_check}Export completed in 3m 41s.</div>
          <div class="sscribe-toast sscribe-toast-warning">{icon_info}Background fetch encountered a soft retry.</div>
          <div class="sscribe-toast sscribe-toast-error">{icon_info}Permission denied on attachment 4.</div>
        </div>

      </div>
    </section>

  </div>
</div>

  <div style="height: 40px;"></div>
</div>
</div>
</div>
</div>
</body>
</html>'''

out_path.parent.mkdir(parents=True, exist_ok=True)
out_path.write_text(html, encoding='utf-8')
print(f'Wrote {out_path} ({out_path.stat().st_size:,} bytes)')
