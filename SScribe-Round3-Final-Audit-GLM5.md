# SScribe — Final Pre-Submission Audit (Round 3)
## WordPress.org Submission Clearance Report

**Plugin:** `sscribe-export-site-pages` v1.2.0  
**Audit Type:** Final pre-submission gate — zero tolerance  
**Verdict:** ⚠️ NOT READY — 2 blockers, 5 bugs, 4 quality issues must be resolved first  

---

## Confirmed ✅ — Everything from Rounds 1 & 2 Is Verified Fixed

- Cover page title double-encode: ✅ fixed (`safe_text()` used correctly)
- Transient deleted before ZIP check: ✅ fixed (correct order)
- Content-Disposition header injection: ✅ fixed (RFC 5987 + nosniff)
- Path traversal in `url_to_local_path()`: ✅ fixed (realpath + extension whitelist)
- `setup_postdata()` added: ✅ fixed
- RTL section-level `bidi` flag: ✅ added
- DOCX filename collision (page ID prefix): ✅ fixed
- Unicode word count fallback: ✅ fixed
- N+1 `get_page_count_only()`: ✅ fixed
- `the_content` re-entry guard: ✅ fixed
- `[H1]` prefix removed from headings: ✅ fixed
- `batch_size` filter: ✅ added
- `sscribe_export_capability` filter: ✅ added
- `wp_upload_dir()` caching: ✅ fixed
- WPML `wpml_get_active_languages()` function: ✅ fixed
- Dead `$export_url` variable: ✅ removed
- `Throwable` instead of `Exception`: ✅ fixed
- `WP_DEBUG` guard on error_log: ✅ fixed

---

## Round 3 Findings: 11 Issues Remaining

| # | Severity | File | Issue |
|---|----------|------|-------|
| 1 | 🔴 BLOCKER | `screenshots/` | Screenshots folder does not exist — WP.org submission requires physical image files |
| 2 | 🔴 BLOCKER | `class-sscribe-exporter.php` | RTL `bidi` only set at section level — NOT at paragraph level — Arabic text still renders LTR in Word |
| 3 | 🟠 HIGH | `class-sscribe-exporter.php` | `render_table()` passes `null` to `addCell()` for content tables — breaks column widths in Google Docs & LibreOffice |
| 4 | 🟠 HIGH | `class-sscribe-batch-processor.php` | `wp_send_json_error()` after capability check has no `return` — on PHP setups where die() is suppressed, code continues |
| 5 | 🟠 HIGH | `class-sscribe-batch-processor.php` | `$language` POST parameter not validated against actual WPML language codes — any string passed to WPML `wpml_switch_language` |
| 6 | 🟡 MEDIUM | `class-sscribe-content-parser.php` | `$is_header = true` declared but never used in `parse_table()` — dead code, PHPStan warning |
| 7 | 🟡 MEDIUM | `class-sscribe-content-parser.php` | Shortcode regex strips non-shortcode bracket content (e.g. price ranges, math formulas) |
| 8 | 🟡 MEDIUM | `readme.txt` | `Tested up to: 6.9` — must match the actual latest released WP version at time of submission |
| 9 | 🟡 MEDIUM | `class-sscribe-exporter.php` | `is_rtl` not passed to `add_page_info_table()`, `add_seo_section()`, `add_breadcrumbs()`, `add_cover_page()` — paragraph alignment not set for RTL |
| 10 | 🟡 MEDIUM | `includes/class-sscribe.php` | Leading space before `$this->loader->run()` on line 89 — indentation inconsistency |
| 11 | ℹ️ LOW | `class-sscribe-content-parser.php` | `libxml_use_internal_errors()` state not restored after parsing — other plugins' XML parsing affected |

---

## Detailed Fix Instructions

---

### ISSUE #1 — 🔴 BLOCKER: Screenshots Folder Missing

**WordPress.org requirement:** The `readme.txt` declares 6 screenshots (lines under `== Screenshots ==`) but NO `screenshots/` folder or any `.png` files exist in the repository. WordPress.org plugin reviewers will reject the submission or the directory listing will show broken image placeholders.

**Fix:** Create the `screenshots/` directory at the plugin root and add the 6 PNG files referenced in `readme.txt`. Each file must be named `screenshot-N.png` where N matches the number in the readme.

```
screenshots/
├── screenshot-1.png   (800×500 minimum — Admin panel with language selection)
├── screenshot-2.png   (Progress bar during batch export)
├── screenshot-3.png   (DOCX cover page example)
├── screenshot-4.png   (DOCX content page with headings and tables)
├── screenshot-5.png   (SEO section in DOCX)
└── screenshot-6.png   (Download complete UI with ZIP link)
```

**If you cannot add real screenshots yet**, temporarily reduce the screenshots section in `readme.txt` to only 1 entry with a single placeholder image. Listing screenshots that don't exist is worse than listing fewer.

---

### ISSUE #2 — 🔴 BLOCKER: RTL Incomplete — Paragraph-Level `bidi` Missing

**File:** `includes/class-sscribe-exporter.php`  
**Problem:** RTL is correctly set at the **section level** (`$settings['bidi'] = true`), but in Microsoft Word and PHPWord, individual paragraphs also need `'bidi' => true` in their paragraph style to actually render right-to-left. The section `bidi` flag only sets the section default paper direction — it does NOT automatically make every paragraph RTL. Arabic text in paragraph body, tables, lists, and info rows will still render left-to-right.

**Fix — store `$is_rtl` as a class property so all methods can access it:**

**Step A — Add `$is_rtl` as a class property:**
```php
/**
 * Whether the current document is RTL.
 *
 * @var bool
 */
private $is_rtl = false;
```

**Step B — Set it in `generate_docx()` before calling other methods:**
```php
$this->is_rtl = $this->is_rtl_document( $page_data );
```

**Step C — Add a helper that returns a paragraph style with optional RTL:**
```php
/**
 * Get paragraph style with optional RTL bidirectional flag.
 *
 * @param array $base_style Base paragraph style array.
 * @return array Paragraph style with bidi if needed.
 */
private function get_para_style( $base_style = array() ) {
    if ( $this->is_rtl ) {
        $base_style['bidi']      = true;
        $base_style['alignment'] = Jc::END; // RTL "right" = END in OOXML
    }
    return $base_style;
}
```

**Step D — Apply to every `addText()`, `addTextRun()`, and `addListItem()` paragraph style argument throughout the class.** Key locations:

In `render_paragraph()`:
```php
// Replace:
$text_run = $section->addTextRun();
// With:
$text_run = $section->addTextRun( $this->get_para_style() );
```

In `add_breadcrumbs()`:
```php
// Add to the paragraph style array:
$this->get_para_style( array(
    'name'   => $this->font_name,
    'size'   => 9,
    'italic' => true,
    'color'  => $this->colors['body'],
) ),
// Note: get_para_style() should handle paragraph style, not font style.
// Pass paragraph style separately to addText():
$section->addText(
    ...,
    array( /* font style */ ),
    $this->get_para_style() // paragraph style
);
```

In `define_styles()` for the Blockquote and CodeBlock paragraph styles, and for the default paragraph style in `set_default_styles()`, pass `$this->get_para_style()` as the base.

In `render_list()`, `addListItem()` 4th argument:
```php
$section->addListItem(
    $this->safe_text( $item['content'] ),
    $depth,
    array( 'name' => $this->font_name, 'size' => $this->font_size, 'color' => $this->colors['body'] ),
    array_merge( array( 'listType' => $list_type ), $this->get_para_style() )
);
```

---

### ISSUE #3 — 🟠 HIGH: `render_table()` Passes `null` Cell Width

**File:** `includes/class-sscribe-exporter.php`, `render_table()`  
**Problem:** `$table->addCell( null, $cell_style )` passes `null` as the cell width. PHPWord then emits no `w:tcW` attribute in the XML, which causes:
- Google Docs: all columns collapse to equal-width automatically (ignores content)
- LibreOffice: sometimes renders cells at zero width
- Microsoft Word: usually works but can produce inconsistent column widths

**Fix — calculate even column widths based on number of columns:**

```php
private function render_table( $section, $element ) {
    if ( empty( $element['rows'] ) ) {
        return;
    }

    // Calculate column count from first row.
    $col_count = ! empty( $element['rows'][0]['cells'] )
        ? count( $element['rows'][0]['cells'] )
        : 1;

    // Total content width: 6.5 inches (US Letter - 1" margins each side).
    $total_width_twip = Converter::inchToTwip( 6.5 );
    $cell_width       = (int) ( $total_width_twip / $col_count );

    $table_style = array(
        'borderSize'  => 1,
        'borderColor' => $this->colors['border'],
        'cellMargin'  => Converter::cmToTwip( 0.1 ),
        'unit'        => \PhpOffice\PhpWord\SimpleType\TblWidth::TWIP,
        'width'       => $total_width_twip,
    );

    $table = $section->addTable( $table_style );

    foreach ( $element['rows'] as $row ) {
        $table->addRow();
        foreach ( $row['cells'] as $cell ) {
            $cell_style = array();
            $font_style = array(
                'name'  => $this->font_name,
                'size'  => 10,
                'color' => $this->colors['body'],
            );

            if ( ! empty( $cell['is_header'] ) ) {
                $cell_style['bgColor'] = $this->colors['light_bg'];
                $font_style['bold']    = true;
                $font_style['color']   = $this->colors['heading'];
            }

            // Pass explicit width — fixes Google Docs and LibreOffice rendering.
            $table->addCell( $cell_width, $cell_style )->addText(
                $this->safe_text( $cell['content'] ),
                $font_style
            );
        }
    }

    $section->addTextBreak( 1 );
}
```

---

### ISSUE #4 — 🟠 HIGH: Missing `return` After `wp_send_json_error()` Capability Checks

**File:** `includes/class-sscribe-batch-processor.php`  
**Problem:** `wp_send_json_error()` calls `die()` in standard WordPress installations, but this is NOT guaranteed in all environments — some test frameworks, REST proxy setups, or custom SAPI configurations may suppress or mock `die()`. Without an explicit `return`, execution continues past the capability check, processing the export with no authentication.

**Fix — add `return` after every `wp_send_json_error()` capability check:**

```php
// In ajax_start_export():
if ( ! current_user_can( $this->get_required_capability() ) ) {
    wp_send_json_error(
        array( 'message' => __( 'You do not have permission to export pages.', 'sscribe-export-site-pages' ) )
    );
    return; // ← ADD THIS
}

// In ajax_process_batch():
if ( ! current_user_can( $this->get_required_capability() ) ) {
    wp_send_json_error(
        array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) )
    );
    return; // ← ADD THIS
}

// Also after the session check:
if ( ! $session ) {
    wp_send_json_error(
        array( 'message' => __( 'Export session expired. Please start again.', 'sscribe-export-site-pages' ) )
    );
    return; // ← ADD THIS
}

// Also after the no-pages check in ajax_start_export():
if ( 0 === $total ) {
    wp_send_json_error(
        array( 'message' => __( 'No published pages found for this language.', 'sscribe-export-site-pages' ) )
    );
    return; // ← ADD THIS
}
```

---

### ISSUE #5 — 🟠 HIGH: Language Parameter Not Validated Against Known WPML Codes

**File:** `includes/class-sscribe-batch-processor.php`, `ajax_start_export()`  
**Problem:** The `$language` POST parameter is sanitized with `sanitize_text_field()` but not validated against the list of actual WPML language codes. Any string (including empty, `null`, special chars) gets passed directly to `wpml_switch_language`. This could trigger unexpected WPML behavior.

**Fix — validate against known languages when WPML is active:**

```php
$language = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';

// Validate language code against active WPML languages when WPML is present.
if ( ! empty( $language ) && $this->collector->is_wpml_active() ) {
    $valid_languages = wp_list_pluck( $this->collector->get_wpml_languages(), 'code' );
    if ( ! in_array( $language, $valid_languages, true ) ) {
        wp_send_json_error(
            array( 'message' => __( 'Invalid language code specified.', 'sscribe-export-site-pages' ) )
        );
        return;
    }
}
```

---

### ISSUE #6 — 🟡 MEDIUM: `$is_header = true` Unused Variable in `parse_table()`

**File:** `includes/class-sscribe-content-parser.php`, line ~350  
**Problem:** `$is_header = true;` is declared at the top of `parse_table()` but is never used — the logic was refactored to use `$section['is_header']` from the `$sections` array instead. This causes a PHPStan warning and could confuse future developers.

**Fix — remove the line entirely:**
```php
private function parse_table( $node ) {
    $rows     = array();
    // Remove: $is_header = true;   ← DELETE THIS LINE

    // Get thead/tbody/direct tr children.
    $sections = array();
    // ... rest of method unchanged
```

---

### ISSUE #7 — 🟡 MEDIUM: Shortcode Regex Strips Non-Shortcode Brackets

**File:** `includes/class-sscribe-content-parser.php`, `strip_shortcodes()`  
**Problem:** The regex `/\[(\/?[a-zA-Z0-9_-]+)[^\]]*\]/` strips anything that looks like `[word...]`. This will incorrectly remove:
- Price ranges written as `[5-10 items]` → stripped
- Math/notation: `[n+1]` → stripped  
- Legal/academic references: `[1]`, `[see note 3]` → stripped
- Custom bracket conventions in content

**Better approach — use WordPress's own `strip_shortcodes()` function first, then apply the regex only to what remains:**

```php
private function strip_shortcodes( $html ) {
    // Use WordPress's own shortcode stripper first (safe — only strips registered shortcodes).
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core function.
    $html = strip_shortcodes( $html );

    // Strip any remaining unregistered shortcode-like patterns only if they look like
    // actual shortcodes (must have a valid tag name at the start, with attributes).
    // Be conservative — do NOT strip simple [word] patterns that could be content.
    $html = preg_replace( '/\[(\/?[a-zA-Z0-9_-]+)(\s+[^\]]+)?\]/', '', $html );

    return $html;
}
```

> Note: The change is adding `(\s+[^\]]+)?` instead of `[^\]]*` — this means bare `[1]` and `[note]` are NOT stripped (no attributes/spaces inside), while `[gallery ids="1,2"]` and `[/gallery]` still are. This is a much safer heuristic.

---

### ISSUE #8 — 🟡 MEDIUM: `Tested up to: 6.9` — Verify Against Actual WP Version

**File:** `readme.txt`  
**Problem:** `Tested up to: 6.9` must match the **actual latest released** WordPress version at the time of submission. Listing a version that hasn't been released yet causes WP.org reviewers to flag the submission.

**Action:** Before submitting, update this line to the actual latest released WP version:
```
Tested up to: X.X  ← replace with actual current WP version
```

Check the current version at https://wordpress.org/download/ immediately before submission.

---

### ISSUE #9 — 🟡 MEDIUM: `is_rtl` Not Passed to Info Table, SEO, Breadcrumb Methods

**This is the companion to Issue #2.** The `$is_rtl` class property approach (from Issue #2) naturally solves this — all methods automatically access `$this->is_rtl` without needing it passed as a parameter. Confirm after implementing Issue #2 that ALL of these methods use `$this->get_para_style()` for their paragraph style arguments:

- `add_page_info_table()` — table cell text alignment
- `add_seo_section()` — table cell text alignment  
- `add_breadcrumbs()` — paragraph alignment
- `add_cover_page()` — center-aligned elements need `Jc::END` equivalent for RTL

---

### ISSUE #10 — 🟡 MEDIUM: Leading Space Before `$this->loader->run()`

**File:** `includes/class-sscribe.php`, line 89  
**Problem:** `     $this->loader->run();` has a leading space before the `$`. PHPCS WordPress standard requires tabs for indentation, not spaces.

**Fix:**
```php
// WRONG (leading space):
     $this->loader->run();

// CORRECT (tab only):
	$this->loader->run();
```

Also fix the alignment issue on line 40: `$this->loader   = new SScribe_Loader();` — the extra spaces before `=` are inconsistent with the rest of the file.

---

### ISSUE #11 — ℹ️ LOW: `libxml_use_internal_errors()` State Not Restored

**File:** `includes/class-sscribe-content-parser.php`, `parse_dom()`  
**Problem:** `libxml_use_internal_errors( true )` is called but the previous state is never restored. If another plugin was relying on libxml error reporting being off (the default), it will now silently swallow XML errors from then on.

**Fix:**
```php
// Replace:
libxml_use_internal_errors( true );
$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
libxml_clear_errors();

// With:
$prev_use_errors = libxml_use_internal_errors( true );
$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
libxml_clear_errors();
libxml_use_internal_errors( $prev_use_errors ); // ← Restore previous state
```

---

## Final Submission Checklist

### 🔴 BLOCKERS (Must fix before ANY submission attempt)
- [ ] `screenshots/` folder created with 6 real `screenshot-N.png` files
- [ ] RTL paragraph-level `bidi` implemented via `$this->is_rtl` property + `get_para_style()` helper

### 🟠 HIGH (Fix before submission)
- [ ] `render_table()` — explicit cell width calculated from column count
- [ ] `return` added after every `wp_send_json_error()` call
- [ ] Language parameter validated against WPML language list

### 🟡 MEDIUM (Fix before submission — reviewers check these)
- [ ] `$is_header = true` dead variable removed from `parse_table()`
- [ ] Shortcode regex updated to use WordPress `strip_shortcodes()` + conservative pattern
- [ ] `Tested up to:` updated to actual current WordPress version at submission time
- [ ] All rendering methods use `$this->get_para_style()` for RTL paragraph alignment
- [ ] Indentation fixed in `class-sscribe.php` (`run()` method + loader alignment)
- [ ] `libxml_use_internal_errors()` state restored after DOM parsing

### 🟢 Final Build Process (In Order)
1. Apply all fixes above
2. Run `composer install --no-dev --optimize-autoloader`
3. Run `phpcbf --standard=WordPress` on all PHP files  
4. Run `phpstan analyse --memory-limit=512M` — must be 0 errors
5. Run `phpunit` — all tests must pass
6. Build: `wp dist-archive . --plugin-dirname=sscribe-export-site-pages`
7. Inspect the ZIP — verify `screenshots/` is included, `PCLZip/` is absent, `tests/` is absent
8. Test ZIP on a clean WordPress install with `WP_DEBUG=true` — zero PHP notices
9. Run WordPress Plugin Check tool on the local install — must be 0 errors
10. Submit to WordPress.org

---

## Summary for GLM-5

**11 issues found.** Work in this exact order:

1. **Create `screenshots/` folder with 6 PNG files** — WP.org will reject without them
2. **Implement `$this->is_rtl` class property + `get_para_style()` helper** — solves both Issue #2 and #9 at once; apply to every paragraph-generating call in the class
3. **Fix `render_table()` cell widths** — calculate `$cell_width` from column count
4. **Add `return` after all `wp_send_json_error()` calls**
5. **Validate `$language` against WPML language list**
6. **Remove `$is_header = true` dead variable**
7. **Update shortcode regex to use `strip_shortcodes()` first**
8. **Update `Tested up to:` in readme.txt**
9. **Fix indentation in `class-sscribe.php`**
10. **Restore `libxml_use_internal_errors()` state**

After all 10 items are done, run the full build process and the plugin will be **genuinely ready for WordPress.org submission**.
