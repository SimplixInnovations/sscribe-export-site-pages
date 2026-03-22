# SScribe Export Site Pages — Full Plugin Audit & GLM-5 Agent Fix Instructions

**Plugin:** `sscribe-export-site-pages` v1.1.7  
**Auditor:** Deep automated + manual review of every file  
**Target:** WordPress.org repository submission approval  
**Date:** March 2026  

---

## Executive Summary

The plugin is architecturally solid — it uses a proper Loader pattern, has good separation of concerns, AJAX batch processing, nonce verification on all AJAX actions, and solid PHPDoc coverage. However, it has **3 confirmed WordPress.org rejection reasons** still present plus **19 additional issues** that must be resolved before re-submission, ranging from critical security vulnerabilities to PHP bugs that would cause notices on any site.

---

## Issue Index (Priority Order)

| # | Severity | File | Issue |
|---|----------|------|-------|
| 1 | 🔴 CRITICAL | `class-sscribe-activator.php` | .htaccess allows PHP execution in uploads dir (security hole) |
| 2 | 🔴 CRITICAL | `class-sscribe-exporter.php` | `render_button()` uses wrong array key — `label` vs `content` (PHP notice + broken output) |
| 3 | 🔴 CRITICAL | `class-sscribe-exporter.php` | `add_child_pages()` accesses `$child['author']` and `$child['date']` — keys don't exist in collector |
| 4 | 🔴 CRITICAL | `vendor/` | PCLZip bundled — conflicts with WordPress core (WP.org rejection reason #2) |
| 5 | 🔴 CRITICAL | `admin/partials/sscribe-admin-display.php` | Recent Exports downloads use direct file URL — bypasses secure AJAX handler |
| 6 | 🟠 HIGH | `sscribe-export-site-pages.php` | `sscribe_init()` called directly with no hook — must be on `plugins_loaded` |
| 7 | 🟠 HIGH | `admin/class-sscribe-admin.php` | `add_plugin_action_links()` — missing `esc_url()` and `esc_html()` on output |
| 8 | 🟠 HIGH | `admin/class-sscribe-admin.php` | Asset versioning uses `time()` — cache-busts on every page load in production |
| 9 | 🟠 HIGH | `admin/partials/sscribe-admin-display.php` | References `$sscribe_lang['translated_name']` — key doesn't exist (should be `name`) |
| 10 | 🟠 HIGH | `class-sscribe-exporter.php` | 8 hardcoded strings not wrapped in `__()` / `esc_html__()` (i18n violations) |
| 11 | 🟡 MEDIUM | `class-sscribe-exporter.php` | `safe_text()` runs `htmlspecialchars()` but PHPWord does its own XML-escaping — double-encode risk |
| 12 | 🟡 MEDIUM | `class-sscribe-zip-handler.php` | Stores one `sscribe_last_export_time_*` option per export — DB pollution |
| 13 | 🟡 MEDIUM | `uninstall.php` | Uses `@` error-suppression operator (`@rmdir`, `@$sscribe_todo`) — WPCS violation |
| 14 | 🟡 MEDIUM | `.distignore` | Needs verification that `wp dist-archive` is used to build the distribution ZIP |
| 15 | 🟡 MEDIUM | `class-sscribe.php` | Indentation inconsistency — mixed leading spaces (not tabs) in constructor |
| 16 | 🟡 MEDIUM | `class-sscribe-loader.php` | Same indentation issue in constructor |
| 17 | 🟡 MEDIUM | Multiple files | Missing `index.php` silence files in `admin/`, `admin/css/`, `admin/js/`, `admin/partials/`, `includes/` |
| 18 | 🟡 MEDIUM | `class-sscribe-admin.php` | Google Fonts loaded from external CDN — GDPR concern, no user consent check |
| 19 | 🟡 MEDIUM | `sscribe-export-site-pages.php` | Plugin header missing `Plugin URI` field |
| 20 | 🟡 MEDIUM | `class-sscribe-exporter.php` | `current_time()` used for document timestamp — should use `wp_date()` |
| 21 | 🟡 MEDIUM | `class-sscribe-page-collector.php` | `get_page_ids()` doesn't reset WPML if exception occurs mid-query |
| 22 | ℹ️ LOW | `readme.txt` | `load_plugin_textdomain()` issue from rejection email is already resolved (file removed) — confirm |

---

## Detailed Fix Instructions for GLM-5

---

### ISSUE #1 — 🔴 CRITICAL: .htaccess Allows PHP Execution in Uploads Directory

**File:** `includes/class-sscribe-activator.php`  
**Why critical:** Allowing PHP files to execute inside `wp-content/uploads/sscribe-exports/` is a known attack vector. If any PHP file were to land there (through any means), it would execute. This contradicts WordPress hardening standards.

**What to fix:**  
Remove the entire second `<Files "*.php">` block from the `.htaccess` generator. The `ajax_download()` AJAX handler in `SScribe_Batch_Processor` already serves files via PHP through `admin-ajax.php` — there is NO need for direct PHP execution in the uploads folder.

**Replace this entire method:**
```php
private static function create_export_directory() {
    $upload_dir  = wp_upload_dir();
    $export_path = $upload_dir['basedir'] . '/sscribe-exports';

    if ( ! file_exists( $export_path ) ) {
        wp_mkdir_p( $export_path );
    }

    // .htaccess to prevent direct access.
    $htaccess_path = $export_path . '/.htaccess';
    if ( ! file_exists( $htaccess_path ) ) {
        $htaccess_content  = "Options -Indexes\n";
        $htaccess_content .= "<Files \"*\">\n";
        $htaccess_content .= "  <IfModule mod_authz_core.c>\n";
        $htaccess_content .= "    Require all denied\n";
        $htaccess_content .= "  </IfModule>\n";
        $htaccess_content .= "  <IfModule !mod_authz_core.c>\n";
        $htaccess_content .= "    Order Allow,Deny\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "  </IfModule>\n";
        $htaccess_content .= "</Files>\n";

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $htaccess_path, $htaccess_content );
    }

    // index.php to prevent directory listing.
    $index_path = $export_path . '/index.php';
    if ( ! file_exists( $index_path ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $index_path, "<?php\n// Silence is golden.\n" );
    }
}
```

> Remove the entire `<Files "*.php"> ... </Files>` block. The new version above already blocks everything. Files are served via AJAX, not direct HTTP.

---

### ISSUE #2 — 🔴 CRITICAL: render_button() Uses Wrong Array Key

**File:** `includes/class-sscribe-exporter.php`, method `render_button()`  
**Bug:** Line uses `$element['label'] ?? 'Click Here'` but `SScribe_Content_Parser::detect_button()` creates the element with key `'content'`, not `'label'`. This causes a PHP 7.x notice and button text is always "Click Here".

**Fix — replace this line in `render_button()`:**
```php
// WRONG:
$cell->addText(
    '[ACTION BUTTON] ' . $this->safe_text( ( $element['label'] ?? 'Click Here' ) ),
```

```php
// CORRECT:
$cell->addText(
    '[ACTION BUTTON] ' . $this->safe_text( ! empty( $element['content'] ) ? $element['content'] : __( 'Click Here', 'sscribe-export-site-pages' ) ),
```

---

### ISSUE #3 — 🔴 CRITICAL: add_child_pages() Accesses Non-Existent Array Keys

**File:** `includes/class-sscribe-exporter.php`, method `add_child_pages()`  
**Bug:** Line accesses `$child['author']` and `$child['date']` but `SScribe_Page_Collector::get_child_pages()` only returns `['id', 'title', 'url']`. This generates PHP notices on every page with child pages.

**Fix — replace the full text run builder in `add_child_pages()`:**
```php
// WRONG (current code):
$text_run->addText(
    ' - ' . $this->safe_text( ( $child['author'] . ' / ' . $child['date'] ) ),
    ...
);

// CORRECT — remove the line entirely, or if you want to keep the dash,
// just show the URL:
$text_run->addText(
    ' — ' . $this->safe_text( $child['url'] ),
    array(
        'name'  => $this->font_name,
        'size'  => 8,
        'color' => $this->colors['body'],
    )
);
```

**Also update `SScribe_Page_Collector::get_child_pages()`** if you want to display author/date. Add these fields to the return array:
```php
$children[] = array(
    'id'    => $child->ID,
    'title' => $child->post_title,
    'url'   => get_permalink( $child->ID ),
    'author' => get_the_author_meta( 'display_name', $child->post_author ),
    'date'   => get_the_date( 'F j, Y', $child->ID ),
);
```

---

### ISSUE #4 — 🔴 CRITICAL: PCLZip Bundled (WP.org Rejection Reason #2)

**File:** `vendor/phpoffice/phpword/src/PhpWord/Shared/PCLZip/pclzip.lib.php`  
**Why:** WordPress core already includes PCLZip. WordPress.org forbids plugins from bundling it.

**Two-part fix:**

**Part A — Add explicit ZipArchive preference at the top of `class-sscribe-exporter.php`** (before the `generate_docx()` method is called), to ensure PHPWord never tries to fall back to PCLZip:
```php
// Force ZipArchive — prevents PHPWord from ever loading bundled PCLZip.
if ( class_exists( '\PhpOffice\PhpWord\Settings' ) ) {
    \PhpOffice\PhpWord\Settings::setZipClass( \PhpOffice\PhpWord\Settings::PCLZIP );
    // Override to use native ZipArchive exclusively.
    if ( class_exists( 'ZipArchive' ) ) {
        \PhpOffice\PhpWord\Settings::setZipClass( \PhpOffice\PhpWord\Settings::ZIPARCHIVE );
    }
}
```

Place this inside `generate_docx()` before `new PhpWord()`, or better in a private `configure_phpword()` method called once from the constructor.

**Part B — `.distignore`** (already present but must be verified):
```
vendor/phpoffice/phpword/src/PhpWord/Shared/PCLZip/
```
Confirm this line is present and the distribution archive is built using:
```bash
wp dist-archive . --plugin-dirname=sscribe-export-site-pages
```

---

### ISSUE #5 — 🔴 CRITICAL: Recent Exports Uses Direct File URL (Bypasses Security)

**File:** `admin/class-sscribe-admin.php` (render_admin_page), `admin/partials/sscribe-admin-display.php`  
**Bug:** The `$recent_exports` array is built using `$export_url . $filename` (a direct HTTP URL to the file). The `.htaccess` blocks direct access on Apache, but on Nginx this won't work at all. More importantly, it completely bypasses your nonce-protected `ajax_download` handler.

**Fix — in `render_admin_page()`, change the URL construction:**
```php
// WRONG (current):
'url' => $export_url . $filename,

// CORRECT — use the secure AJAX download URL:
'url' => add_query_arg(
    array(
        'action' => 'sscribe_download',
        'file'   => sanitize_file_name( $filename ),
        'nonce'  => wp_create_nonce( 'sscribe_download' ),
    ),
    admin_url( 'admin-ajax.php' )
),
```

This ensures every download goes through the capability check + nonce verification in `ajax_download()`.

---

### ISSUE #6 — 🟠 HIGH: sscribe_init() Called Without a Hook

**File:** `sscribe-export-site-pages.php`  
**Why:** Calling `sscribe_init()` directly at file include time (outside of any WordPress hook) means the plugin bootstraps before other plugins have loaded. This creates race conditions when checking for WPML, SEO plugins, etc., which are all checked during class construction.

**Fix — replace at bottom of `sscribe-export-site-pages.php`:**
```php
// WRONG (current):
sscribe_init();

// CORRECT:
add_action( 'plugins_loaded', 'sscribe_init' );
```

---

### ISSUE #7 — 🟠 HIGH: Unescaped Output in add_plugin_action_links()

**File:** `admin/class-sscribe-admin.php`, method `add_plugin_action_links()`  
**WP.org rule:** All output must be escaped at time of output.

**Fix:**
```php
// WRONG (current):
$plugin_links = array(
    '<a href="' . admin_url( 'tools.php?page=sscribe-export' ) . '">' . __( 'Export Pages', 'sscribe-export-site-pages' ) . '</a>',
);

// CORRECT:
$plugin_links = array(
    '<a href="' . esc_url( admin_url( 'tools.php?page=sscribe-export' ) ) . '">' . esc_html__( 'Export Pages', 'sscribe-export-site-pages' ) . '</a>',
);
```

---

### ISSUE #8 — 🟠 HIGH: Asset Versioning Uses time()

**File:** `admin/class-sscribe-admin.php`, method `enqueue_admin_assets()`  
**Bug:** `SSCRIBE_VERSION . '.' . time()` as the version for CSS and JS means the browser cache is busted on EVERY admin page load. This causes unnecessary asset reloads and is explicitly bad practice.

**Fix:**
```php
// WRONG (current):
SSCRIBE_VERSION . '.' . time()

// CORRECT — for both CSS and JS enqueue calls:
SSCRIBE_VERSION
```

---

### ISSUE #9 — 🟠 HIGH: Wrong Array Key $sscribe_lang['translated_name']

**File:** `admin/partials/sscribe-admin-display.php`  
**Bug:** The language card template uses `$sscribe_lang['translated_name']` but `SScribe_Page_Collector::get_wpml_languages()` returns the array with key `'name'`, not `'translated_name'`. This throws a PHP notice on every admin page load when WPML is active.

**Find in `sscribe-admin-display.php`:**
```php
<span class="sscribe-lang-name"><?php echo esc_html($sscribe_lang['translated_name']); ?></span>
```

**Fix:**
```php
<span class="sscribe-lang-name"><?php echo esc_html( $sscribe_lang['name'] ); ?></span>
```

---

### ISSUE #10 — 🟠 HIGH: 8 Hardcoded Strings Not Wrapped in Translation Functions

**File:** `includes/class-sscribe-exporter.php`  
**WP.org rule:** All user-facing strings must be translatable.

**Find and replace each of the following:**

| Location | Current (wrong) | Fix |
|----------|-----------------|-----|
| `add_cover_page()` | `'DOCUMENT BLUEPRINT OVERVIEW'` | `esc_html__( 'DOCUMENT BLUEPRINT OVERVIEW', 'sscribe-export-site-pages' )` |
| `add_cover_page()` | `'Site Path: ' . $this->safe_text(...)` | `$this->safe_text( sprintf( __( 'Site Path: %s', 'sscribe-export-site-pages' ), $breadcrumb_text ) )` |
| `add_cover_page()` | `'TABLE OF CONTENTS'` | `esc_html__( 'TABLE OF CONTENTS', 'sscribe-export-site-pages' )` |
| `render_button()` | `'DESTINATION URL:'` | `esc_html__( 'DESTINATION URL:', 'sscribe-export-site-pages' )` |
| `render_inline_image()` | `'IMAGE ASSET SOURCE URL:'` | `esc_html__( 'IMAGE ASSET SOURCE URL:', 'sscribe-export-site-pages' )` |
| `render_inline_image()` | `'[MISSING IMAGE] '` | `__( '[MISSING IMAGE] ', 'sscribe-export-site-pages' )` |
| `render_inline_image()` | `'No Alt Text Provided'` | `__( 'No Alt Text Provided', 'sscribe-export-site-pages' )` |
| `render_inline_image()` | `'Unknown URL'` | `__( 'Unknown URL', 'sscribe-export-site-pages' )` |

> Note: In `class-sscribe-exporter.php`, strings going into PHPWord (not echoed to HTML) do NOT need `esc_html()` — just `__()`. The `safe_text()` method handles XML escaping for PHPWord.

---

### ISSUE #11 — 🟡 MEDIUM: Double-Encoding Risk in safe_text() + PHPWord

**File:** `includes/class-sscribe-exporter.php`, method `safe_text()`  
**Problem:** `safe_text()` runs `htmlspecialchars()` which produces `&amp;`, `&lt;`, etc. PHPWord's writer then XML-escapes the string AGAIN, turning `&amp;` into `&amp;amp;`. This results in visible `&amp;` characters appearing in Word documents.

**Fix — remove the `htmlspecialchars()` call from `safe_text()`. PHPWord handles XML encoding internally:**
```php
private function safe_text( $text ) {
    $text = (string) $text;

    // Remove astral-plane Unicode (emoji, symbols above U+FFFF).
    $text = preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', $text );

    // Remove XML-illegal control characters (keep tab, newline, carriage return).
    $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

    // DO NOT call htmlspecialchars() here — PHPWord handles XML escaping internally.
    // Calling it here causes double-encoding (&amp; → &amp;amp; in output).

    return $text;
}
```

---

### ISSUE #12 — 🟡 MEDIUM: Database Pollution from Per-Export Options

**File:** `includes/class-sscribe-zip-handler.php`  
**Problem:** `update_option( 'sscribe_last_export_time_' . md5( $zip_path ), time() )` creates a new `wp_options` row for every single export. These are never properly cleaned up when the option name changes (path changes). On an active site, this pollutes the options table.

**Fix — replace with a single option containing an array:**

In `create_zip()`, replace:
```php
update_option( 'sscribe_last_export_time_' . md5( $zip_path ), time() );
```
With:
```php
$exports = get_option( 'sscribe_export_index', array() );
$exports[ basename( $zip_path ) ] = time();
update_option( 'sscribe_export_index', $exports, false ); // false = not autoloaded
```

In `cleanup_expired()`, replace:
```php
delete_option( 'sscribe_last_export_time_' . md5( $file ) );
```
With:
```php
$exports = get_option( 'sscribe_export_index', array() );
unset( $exports[ basename( $file ) ] );
update_option( 'sscribe_export_index', $exports, false );
```

In `uninstall.php`, add:
```php
delete_option( 'sscribe_export_index' );
```

---

### ISSUE #13 — 🟡 MEDIUM: @ Error-Suppression Operator in uninstall.php

**File:** `uninstall.php`  
**WP.org rule:** WordPress Coding Standards forbids the `@` operator.

**Fix — replace the variable-function-call pattern:**
```php
// WRONG (current):
$sscribe_todo = ( $sscribe_fileinfo->isDir() ? 'rmdir' : 'unlink' );
@$sscribe_todo( $sscribe_fileinfo->getRealPath() );
...
@rmdir( $sscribe_export_path );

// CORRECT:
if ( $sscribe_fileinfo->isDir() ) {
    if ( is_dir( $sscribe_fileinfo->getRealPath() ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        rmdir( $sscribe_fileinfo->getRealPath() );
    }
} else {
    wp_delete_file( $sscribe_fileinfo->getRealPath() );
}
...
if ( is_dir( $sscribe_export_path ) ) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    rmdir( $sscribe_export_path );
}
```

> `wp_delete_file()` is already used in `SScribe_Zip_Handler::delete_directory()` — apply the same pattern here for consistency.

---

### ISSUE #14 — 🟡 MEDIUM: Distribution Archive Must Use wp dist-archive

**Files:** `.distignore`, all vendor files  
**WP.org requirement:** The submitted ZIP must NOT contain dev files, GitHub metadata, COPYING.LESSER, etc. The `.distignore` is well-written but must actually be used.

**Required build command:**
```bash
# Install WP-CLI if not already available
# Then from the plugin root:
wp dist-archive . --plugin-dirname=sscribe-export-site-pages
```

**Verify the following are excluded from the generated ZIP:**
- `vendor/phpoffice/phpword/.github_changelog_generator` ✓ (in .distignore)
- `vendor/phpoffice/phpword/COPYING.LESSER` ✓ (in .distignore)
- `vendor/phpoffice/phpword/src/PhpWord/Shared/PCLZip/` ✓ (in .distignore)
- `tests/`, `phpunit.xml`, `phpstan.neon`, `phpcs.xml` ✓ (in .distignore)
- `composer.json`, `composer.lock` — **add these to .distignore** (not needed by end users)

**Add to `.distignore`:**
```
composer.json
composer.lock
```

---

### ISSUE #15 + #16 — 🟡 MEDIUM: Indentation Inconsistencies

**Files:** `includes/class-sscribe.php`, `includes/class-sscribe-loader.php`  
**WP.org standard:** WordPress uses tabs, not spaces, for indentation.

In `class-sscribe.php` constructor:
```php
// WRONG (extra leading space):
 $this->version = defined( 'SSCRIBE_VERSION' ) ? SSCRIBE_VERSION : '1.0.0';
 $admin = new SScribe_Admin();

// CORRECT (tab only):
$this->version = defined( 'SSCRIBE_VERSION' ) ? SSCRIBE_VERSION : '1.0.0';
$admin = new SScribe_Admin();
```

Same fix in `class-sscribe-loader.php` constructor:
```php
// WRONG:
 $this->actions = array();
$this->filters  = array();

// CORRECT:
$this->actions = array();
$this->filters = array();
```

Run `phpcbf --standard=WordPress` on all files after fixes to catch any remaining alignment issues.

---

### ISSUE #17 — 🟡 MEDIUM: Missing index.php Silence Files

**WordPress best practice:** Every directory in a plugin should have an `index.php` with `<?php // Silence is golden.` to prevent directory listing on servers without Options -Indexes.

**Create the following files** (each with identical content):

Files to create:
- `admin/index.php`
- `admin/css/index.php`
- `admin/js/index.php`
- `admin/partials/index.php`
- `includes/index.php`
- `languages/index.php` (already exists ✓)

Content for each:
```php
<?php
// Silence is golden.
```

---

### ISSUE #18 — 🟡 MEDIUM: Google Fonts Loaded From External CDN (GDPR)

**File:** `admin/class-sscribe-admin.php`, method `enqueue_admin_assets()`  
**Problem:** Loading `https://fonts.googleapis.com/...` sends the user's IP to Google servers. This requires user consent in GDPR-regulated jurisdictions (EU, UK, etc.). WordPress admin pages shouldn't require cookie consent notices.

**Fix option A (recommended) — remove the Google Fonts import and use system font stack:**
```php
// Remove this entirely:
wp_enqueue_style(
    'sscribe-manrope-font',
    'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap',
    array(),
    SSCRIBE_VERSION
);

// And in the admin CSS, replace font-family with:
// font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
```

**Fix option B — self-host the font:**
Download Manrope woff2 files and serve them from `admin/fonts/`. Update `sscribe-admin.css` to use `@font-face` with local paths.

---

### ISSUE #19 — 🟡 MEDIUM: Missing Plugin URI in Header

**File:** `sscribe-export-site-pages.php`  
**WP.org recommendation:** The plugin header should include a `Plugin URI` for the plugin's homepage or documentation.

**Add after `Plugin Name`:**
```php
/**
 * Plugin Name:       SScribe Export Site Pages
 * Plugin URI:        https://simplixi.com/sscribe
 * Description:       ...
```

---

### ISSUE #20 — 🟡 MEDIUM: current_time() Instead of wp_date()

**File:** `includes/class-sscribe-exporter.php`, method `add_cover_page()`  
**Problem:** `current_time( 'F j, Y, g:i a' )` returns local time but isn't i18n-aware for date formats. WordPress 5.3+ provides `wp_date()` which respects timezone settings AND is translatable.

**Fix:**
```php
// WRONG:
current_time( 'F j, Y, g:i a' )

// CORRECT:
wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
```

---

### ISSUE #21 — 🟡 MEDIUM: WPML Language Not Reset on Exception

**File:** `includes/class-sscribe-page-collector.php`, method `get_page_ids()`  
**Problem:** If `WP_Query` throws an exception, the `do_action( 'wpml_switch_language', null )` reset never fires, leaving WPML stuck in the wrong language for the rest of the request.

**Fix — wrap in try/finally:**
```php
public function get_page_ids( $language = '' ) {
    $args = array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    );

    $switched = false;
    if ( ! empty( $language ) && $this->is_wpml_active() ) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
        do_action( 'wpml_switch_language', $language );
        $args['suppress_filters'] = false;
        $switched = true;
    }

    try {
        $query    = new WP_Query( $args );
        $page_ids = $query->posts;
    } finally {
        if ( $switched ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
            do_action( 'wpml_switch_language', null );
        }
    }

    return $page_ids;
}
```

---

### ISSUE #22 — ℹ️ LOW: load_plugin_textdomain() Rejection (Already Resolved)

**Status:** The `class-sscribe-i18n.php` file mentioned in the rejection email no longer exists in the repository. WordPress 4.6+ auto-loads translations, so no `load_plugin_textdomain()` is needed. **No action required** — just confirm the file is absent from the submitted ZIP.

---

## Additional Best Practices (Not Blocking, But Recommended)

### A. Add `batch_size` Filter for Developer Extensibility
In `class-sscribe-batch-processor.php`:
```php
// Replace hardcoded:
private $batch_size = 3;

// With filterable value in constructor:
$this->batch_size = (int) apply_filters( 'sscribe_batch_size', 3 );
```

### B. Add Capability Filter for Multisite
The `manage_options` capability is used throughout. Consider a filter:
```php
$capability = apply_filters( 'sscribe_export_capability', 'manage_options' );
if ( ! current_user_can( $capability ) ) { ... }
```

### C. Add `sscribe_export_index` to uninstall.php
```php
delete_option( 'sscribe_export_index' ); // from Issue #12 fix
```

### D. readme.txt — Verify Tested Up To Version
`Tested up to: 6.9` — Verify this matches the latest WordPress release available at submission time. Do not list a version that hasn't been released yet.

### E. readme.txt — Add Screenshots Section
WordPress.org requires at least 1 screenshot for the plugin directory listing. Add a `screenshots/` folder with `screenshot-1.png` and document in `readme.txt`:
```
== Screenshots ==

1. The SScribe admin panel showing language selection and export options.
2. Sample exported DOCX document showing cover page and content formatting.
```

---

## WordPress.org Submission Checklist

Before re-submitting, verify all of the following:

- [ ] `sscribe_init()` wrapped in `add_action( 'plugins_loaded', 'sscribe_init' )`
- [ ] `.htaccess` no longer allows PHP execution in exports dir
- [ ] `render_button()` uses `$element['content']` not `$element['label']`
- [ ] `add_child_pages()` no longer accesses non-existent keys
- [ ] PCLZip excluded from dist archive AND ZipArchive forced in PHPWord config
- [ ] Recent Exports download uses AJAX URL with nonce
- [ ] `add_plugin_action_links()` uses `esc_url()` and `esc_html__()`
- [ ] Asset versioning uses `SSCRIBE_VERSION` only (no `time()`)
- [ ] `$sscribe_lang['translated_name']` replaced with `$sscribe_lang['name']`
- [ ] All 8 hardcoded strings wrapped in `__()`
- [ ] `safe_text()` no longer double-encodes (remove `htmlspecialchars()`)
- [ ] Options pollution fixed (single `sscribe_export_index` option)
- [ ] `@` error suppression removed from `uninstall.php`
- [ ] `composer.json` + `composer.lock` added to `.distignore`
- [ ] Distribution archive built with `wp dist-archive`
- [ ] All indentation is tabs (run `phpcbf --standard=WordPress`)
- [ ] `index.php` silence files in all subdirectories
- [ ] Google Fonts removed or self-hosted
- [ ] Plugin URI added to header
- [ ] `current_time()` replaced with `wp_date()`
- [ ] WPML language reset wrapped in `try/finally`
- [ ] `screenshots/` folder added with at least 1 screenshot
- [ ] `readme.txt` `Tested up to:` matches latest WP release
- [ ] Final test on clean WordPress install with WP_DEBUG=true — zero PHP notices

---

## Summary for GLM-5 Agent

**Total issues: 22** (4 critical blocking WP.org approval, 5 high, 13 medium/low)

Work through issues in order #1 → #22. Each issue above contains the exact file, the exact wrong code, and the exact replacement code. After all fixes are applied:

1. Run `composer install --no-dev --optimize-autoloader` to regenerate the production vendor directory
2. Run `phpcbf --standard=WordPress` on all PHP files
3. Run `wp dist-archive . --plugin-dirname=sscribe-export-site-pages` to build the submission ZIP
4. Verify the ZIP contents — ensure PCLZip directory and dev files are absent
5. Test on a clean WordPress 6.x install with `WP_DEBUG=true` and `WP_DEBUG_LOG=true` — zero notices/warnings is the target
