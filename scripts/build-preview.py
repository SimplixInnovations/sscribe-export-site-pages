#!/usr/bin/env python
"""Build a self-contained HTML preview of the sScribe admin UI for visual QA.

This assembles the actual shipped CSS plus a representative markup so the user
can open it in a browser and confirm the visual state.
"""
import os
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
            <span class="sscribe-hero-version">v1.1.2</span>
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
