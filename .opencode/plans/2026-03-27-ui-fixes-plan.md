# SScribe UI/UX Comprehensive Fix Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all SVG icon, CSS class naming, JavaScript logic, and session management issues to restore full plugin functionality.

**Architecture:** WordPress plugin with PHP backend, CSS frontend styling, JavaScript AJAX interactions, and SVG icons loaded via `<img>` tags. Fixes involve coordinated changes across all layers.

**Tech Stack:** PHP 7.4+, WordPress 5.8+, CSS3 with Custom Properties, jQuery, SVG

---

## File Structure

### Files to Modify:
1. `admin/partials/sscribe-admin-display.php` - Fix HTML class names (7 locations)
2. `admin/css/sscribe-admin.css` - Fix CSS selectors (51+ instances), remove duplicates, add missing styles
3. `admin/js/sscribe-admin.js` - Fix status count logic, time estimate, retry function
4. `includes/class-sscribe-helpers.php` - Fix icon class prefix
5. `includes/class-sscribe-session.php` - Fix session blocking logic
6. `includes/class-sscribe-batch-processor.php` - Add force clear option

### Files to Create/Update:
- `assets/icons/*.svg` - Add fill="currentColor" to all 44 SVG files

---

## Task 1: Fix SVG Icons Fill Attribute

**Files:**
- Modify: All files in `assets/icons/*.svg` (44 files)

- [ ] **Step 1: Create script to batch update SVG files**

Create a PowerShell script to add `fill="currentColor"` to all SVG path elements:

```powershell
# Run from project root
Get-ChildItem -Path "assets\icons\*.svg" | ForEach-Object {
    $content = Get-Content $_.FullName -Raw
    if ($content -notmatch 'fill=') {
        $content = $content -replace '<path d=', '<path fill="currentColor" d='
        Set-Content -Path $_.FullName -Value $content -NoNewline
    }
}
```

- [ ] **Step 2: Execute the script**

Run: `powershell -ExecutionPolicy Bypass -File fix-svg.ps1`
Expected: All 44 SVG files updated with `fill="currentColor"`

- [ ] **Step 3: Verify a sample SVG**

Run: `cat assets/icons/check.svg`
Expected: `<svg ...><path fill="currentColor" d="..."/></svg>`

- [ ] **Step 4: Commit**

```bash
git add assets/icons/*.svg
git commit -m "fix: add fill='currentColor' to all SVG icons for proper color inheritance"
```

---

## Task 2: Fix CSS Class Name Mismatches

**Files:**
- Modify: `admin/css/sscribe-admin.css`

- [ ] **Step 1: Replace all `.scribe-` with `.sscribe-` in CSS**

Use global find-and-replace. The following selectors need fixing:

**Line 70:** `.scribe-hero-content` → `.sscribe-hero-content`
**Line 130:** `.scribe-hero-content` → `.sscribe-hero-content`
**Line 297:** `.scribe-status-card-label` → `.sscribe-status-card-label`
**Lines 321-363:** All `.scribe-format-option` → `.sscribe-format-option`
**Line 399:** `.scribe-time-remaining` → `.sscribe-time-remaining`
**Line 1044:** `.scribe-hero-right` → `.sscribe-hero-right`
**Line 1063:** `.scribe-error-actions` → `.sscribe-error-actions`
**Lines 1111-1177:** All `.scribe-format-*` → `.sscribe-format-*`
**Lines 1222-1226:** `.scribe-delete-icon`, `.scribe-delete-btn` → `.sscribe-delete-icon`, `.sscribe-delete-btn`
**Lines 1245-1405:** All `.scribe-modal-*`, `.scribe-log-*`, `.scribe-spinner-img` → `.sscribe-*`
**Lines 1426-1449:** `.scribe-icon-img`, `.scribe-logo-img`, `.scribe-legend-icon` → `.sscribe-*`

- [ ] **Step 2: Remove duplicate CSS definitions**

Delete lines 1456-1500 (duplicate `.sscribe-badge` and duplicate responsive rules)

- [ ] **Step 3: Add missing CSS definitions**

Add after line 148:

```css
.sscribe-workspace {
    max-width: 1280px;
}

.sscribe-config-panel {
    margin-bottom: 32px;
}
```

- [ ] **Step 4: Commit**

```bash
git add admin/css/sscribe-admin.css
git commit -m "fix: correct all CSS class name prefixes from scribe- to sscribe-"
```

---

## Task 3: Fix HTML Class Names in PHP Template

**Files:**
- Modify: `admin/partials/sscribe-admin-display.php`

- [ ] **Step 1: Fix line 140 - status disabled class**

Change:
```php
<label class="sscribe-status-card-label<?php echo $sscribe_is_zero ? ' scribe-status-disabled' : ''; ?>">
```
To:
```php
<label class="sscribe-status-card-label<?php echo $sscribe_is_zero ? ' sscribe-status-disabled' : ''; ?>">
```

- [ ] **Step 2: Fix line 165 - format all class**

Change:
```php
<label class="sscribe-format-card-label scribe-format-all">
```
To:
```php
<label class="sscribe-format-card-label sscribe-format-all">
```

- [ ] **Step 3: Fix lines 333, 337, 341 - button outline classes**

Change all instances of:
```php
class="sscribe-button scribe-button-outline scribe-button-sm"
```
To:
```php
class="sscribe-button sscribe-button-outline sscribe-button-sm"
```

- [ ] **Step 4: Fix line 342 - delete icon class**

Change:
```php
class="sscribe-delete-icon"
```
To:
```php
class="sscribe-delete-icon"
```
(Already correct, verify no changes needed)

- [ ] **Step 5: Commit**

```bash
git add admin/partials/sscribe-admin-display.php
git commit -m "fix: correct HTML class names to match CSS selectors"
```

---

## Task 4: Fix Helper Class Icon Prefix

**Files:**
- Modify: `includes/class-sscribe-helpers.php`

- [ ] **Step 1: Fix line 28 icon class**

Change:
```php
$class = 'sscribe-icon scribe-icon-' . sanitize_html_class( $name );
```
To:
```php
$class = 'sscribe-icon sscribe-icon-' . sanitize_html_class( $name );
```

- [ ] **Step 2: Commit**

```bash
git add includes/class-sscribe-helpers.php
git commit -m "fix: correct icon class prefix in helper function"
```

---

## Task 5: Fix JavaScript Status Count Logic

**Files:**
- Modify: `admin/js/sscribe-admin.js`

- [ ] **Step 1: Fix updateTimeEstimate to get selected status count**

Replace the `updateTimeEstimate` function (lines 93-130) with:

```javascript
updateTimeEstimate: function () {
    var $selectedStatus = $('input[name="sscribe_post_status"]:checked');
    var count = 0;
    
    if ($selectedStatus.length && !$selectedStatus.prop('disabled')) {
        count = parseInt($selectedStatus.closest('.sscribe-status-card-label').find('.sscribe-status-count').text()) || 0;
    }
    
    this.selectedPageCount = count;

    var format = $('input[name="sscribe_format"]:checked').val() || 'all';
    var estimate = '';

    if (count === 0) {
        estimate = 'Select options to see estimated time';
    } else if (format === 'all') {
        var totalSeconds = (1.2 + 8 + 1 + 0.5) * count;
        var minutes = Math.ceil(totalSeconds / 60);
        if (minutes < 60) {
            estimate = 'Estimated time: ~' + minutes + ' ' + (minutes === 1 ? 'minute' : 'minutes');
        } else {
            var hours = Math.floor(minutes / 60);
            var mins = minutes % 60;
            estimate = 'Estimated time: ~' + hours + 'h ' + mins + 'm';
        }
    } else {
        var times = {
            'docx': 1.2,
            'pdf': 8,
            'html': 1,
            'markdown': 0.5
        };
        var seconds = (times[format] || 2) * count;
        if (seconds < 60) {
            estimate = 'Estimated time: ~' + Math.ceil(seconds) + ' seconds';
        } else {
            var mins = Math.ceil(seconds / 60);
            estimate = 'Estimated time: ~' + mins + ' ' + (mins === 1 ? 'minute' : 'minutes');
        }
    }

    $('#sscribe-time-estimate-text').text(estimate);
    this.updateExportButton();
},
```

- [ ] **Step 2: Fix onLanguageChange to call updateTimeEstimate after DOM updates**

Update the `onLanguageChange` success callback to ensure proper status selection:

```javascript
onLanguageChange: function () {
    if (this.isProcessing) {
        return;
    }

    var language = $('input[name="sscribe_language"]:checked').val() || '';
    var self = this;

    $.ajax({
        url: sscribe_data.ajaxurl,
        type: 'POST',
        data: {
            action: 'sscribe_get_status_counts',
            nonce: sscribe_data.nonce,
            language: language
        },
        success: function (response) {
            if (response.success && response.data.counts) {
                var counts = response.data.counts;
                var hasSelection = false;

                $('.sscribe-status-card-label').each(function () {
                    var $label = $(this);
                    var $input = $label.find('input[type="radio"]');
                    var status = $input.val();
                    var count = counts[status] || 0;

                    $label.find('.sscribe-status-count').text(count);

                    if (count === 0) {
                        $input.prop('disabled', true).prop('checked', false);
                        $label.addClass('sscribe-status-disabled');
                    } else {
                        $input.prop('disabled', false);
                        $label.removeClass('sscribe-status-disabled');
                        if (!hasSelection) {
                            $input.prop('checked', true);
                            hasSelection = true;
                        }
                    }
                });

                self.updateTimeEstimate();
            }
        }
    });
},
```

- [ ] **Step 3: Commit**

```bash
git add admin/js/sscribe-admin.js
git commit -m "fix: correct status count retrieval from selected card, not first non-disabled"
```

---

## Task 6: Fix Export Format Timing Display

**Files:**
- Modify: `admin/partials/sscribe-admin-display.php`

- [ ] **Step 1: Update format time display to show total time**

The format cards currently show per-page time. The time estimate is already calculated dynamically in JS, so we can simplify the PHP to show format description only.

Change the format data array (lines 181-202):

```php
$sscribe_formats = array(
    'docx' => array(
        'label' => __('Word Document (DOCX)', 'sscribe-export-site-pages'),
        'icon'  => 'file-doc',
        'desc'  => __('~1.2 seconds per page', 'sscribe-export-site-pages'),
    ),
    'pdf' => array(
        'label' => __('PDF Document', 'sscribe-export-site-pages'),
        'icon'  => 'file-pdf',
        'desc'  => __('~8 seconds per page', 'sscribe-export-site-pages'),
    ),
    'html' => array(
        'label' => __('HTML Page', 'sscribe-export-site-pages'),
        'icon'  => 'file-html',
        'desc'  => __('~1 second per page', 'sscribe-export-site-pages'),
    ),
    'markdown' => array(
        'label' => __('Markdown', 'sscribe-export-site-pages'),
        'icon'  => 'file-md',
        'desc'  => __('~0.5 seconds per page', 'sscribe-export-site-pages'),
    ),
);
```

- [ ] **Step 2: Update HTML to use sscribe-format-desc class**

Change line 213:
```php
<span class="sscribe-format-time"><?php echo esc_html($sscribe_format_data['time']); ?></span>
```
To:
```php
<span class="sscribe-format-desc"><?php echo esc_html($sscribe_format_data['desc']); ?></span>
```

- [ ] **Step 3: Commit**

```bash
git add admin/partials/sscribe-admin-display.php
git commit -m "fix: show per-page time as description in format cards"
```

---

## Task 7: Fix Session Management for Cancel/Restart

**Files:**
- Modify: `includes/class-sscribe-session.php`
- Modify: `includes/class-sscribe-batch-processor.php`

- [ ] **Step 1: Reduce has_active_session threshold from 300 to 60 seconds**

In `class-sscribe-session.php`, line 360, change:

```php
$max_age = 300;
```
To:
```php
$max_age = 60;
```

- [ ] **Step 2: Add force_clear_user_sessions method to SScribe_Session**

Add after the `cleanup_expired` method:

```php
public function clear_user_sessions(int $user_id): int
{
    $files = glob($this->storage_dir . $this->session_prefix . '*.json');
    $deleted = 0;

    if ($files === false || empty($files)) {
        return 0;
    }

    foreach ($files as $file) {
        $content = @file_get_contents($file);
        if ($content === false) {
            continue;
        }
        
        $data = json_decode($content, true);
        
        if (!is_array($data)) {
            continue;
        }

        if (isset($data['user_id']) && (int) $data['user_id'] === $user_id) {
            if (function_exists('wp_delete_file')) {
                wp_delete_file($file);
            } else {
                @unlink($file);
            }
            if (!file_exists($file)) {
                $deleted++;
            }
            
            $lock_file = $file . $this->lock_suffix;
            if (file_exists($lock_file)) {
                @unlink($lock_file);
            }
        }
    }

    return $deleted;
}
```

- [ ] **Step 3: Update ajax_clear_session to force clear all user sessions**

In `class-sscribe-batch-processor.php`, replace `ajax_clear_session` method (lines 1203-1217):

```php
public function ajax_clear_session() {
    check_ajax_referer( 'sscribe_export_nonce', 'nonce' );

    if ( ! current_user_can( $this->get_required_capability() ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sscribe-export-site-pages' ) ) );
        return;
    }

    $user_id = get_current_user_id();
    $force = isset( $_POST['force'] ) && filter_var( $_POST['force'], FILTER_VALIDATE_BOOLEAN );
    
    if ( $force ) {
        $deleted = $this->session->clear_user_sessions( $user_id );
        $this->logger->debug( 'Force cleared all sessions for user', array( 
            'user_id' => $user_id,
            'deleted_count' => $deleted,
        ) );
    } else {
        $this->session->cleanup_expired( 60 );
        $this->logger->debug( 'Cleared expired sessions for user', array( 'user_id' => $user_id ) );
    }

    wp_send_json_success( array( 'message' => __( 'Session cleared.', 'sscribe-export-site-pages' ) ) );
}
```

- [ ] **Step 4: Fix retry function in JavaScript to use force clear**

In `sscribe-admin.js`, replace `retry` method (lines 327-346):

```javascript
retry: function (e) {
    e.preventDefault();
    var self = this;

    $.ajax({
        url: sscribe_data.ajaxurl,
        type: 'POST',
        data: {
            action: 'sscribe_clear_session',
            nonce: sscribe_data.nonce,
            force: true
        },
        success: function () {
            self.sessionId = null;
            self.isProcessing = false;
            self.resetUI();
            $('.sscribe-action-row').slideDown(200);
        },
        error: function () {
            self.sessionId = null;
            self.isProcessing = false;
            self.resetUI();
            $('.sscribe-action-row').slideDown(200);
        }
    });
},
```

- [ ] **Step 5: Commit**

```bash
git add includes/class-sscribe-session.php includes/class-sscribe-batch-processor.php admin/js/sscribe-admin.js
git commit -m "fix: improve session management with force clear option and reduced timeout"
```

---

## Task 8: Add Accessibility Improvements

**Files:**
- Modify: `admin/partials/sscribe-admin-display.php`
- Modify: `admin/css/sscribe-admin.css`

- [ ] **Step 1: Add ARIA labels to modal close button**

Change line 498:
```php
<button type="button" class="sscribe-modal-close" id="sscribe-modal-close">&times;</button>
```
To:
```php
<button type="button" class="sscribe-modal-close" id="sscribe-modal-close" aria-label="<?php esc_attr_e('Close modal', 'sscribe-export-site-pages'); ?>">&times;</button>
```

- [ ] **Step 2: Add focus styles for keyboard navigation**

Add to CSS after the `.sscribe-modal-close:hover` rule:

```css
.sscribe-modal-close:focus {
    outline: 2px solid var(--sscribe-primary);
    outline-offset: 2px;
    opacity: 1;
}

.sscribe-button:focus-visible {
    outline: 2px solid var(--sscribe-primary);
    outline-offset: 2px;
}
```

- [ ] **Step 3: Commit**

```bash
git add admin/partials/sscribe-admin-display.php admin/css/sscribe-admin.css
git commit -m "fix: add accessibility improvements for WCAG compliance"
```

---

## Task 9: Final Verification and Testing

**Files:**
- All modified files

- [ ] **Step 1: Run PHP syntax check**

```bash
php -l admin/partials/sscribe-admin-display.php
php -l includes/class-sscribe-helpers.php
php -l includes/class-sscribe-session.php
php -l includes/class-sscribe-batch-processor.php
```
Expected: No syntax errors

- [ ] **Step 2: Run existing tests**

```bash
cd tests && phpunit
```
Expected: All tests pass

- [ ] **Step 3: Manual testing checklist**

1. Load admin page - verify all icons display correctly
2. Select different languages - verify status counts update
3. Select different status - verify time estimate updates
4. Select different formats - verify time estimate updates
5. Start export, then cancel - verify can restart
6. Complete an export - verify download works
7. Test keyboard navigation - verify focus indicators work

- [ ] **Step 4: Final commit with version bump**

```bash
git add -A
git commit -m "release: v3.1.4 - comprehensive UI/UX fixes

- Fix SVG icons with fill='currentColor' for proper display
- Correct all CSS class name mismatches (51+ instances)
- Fix page status count retrieval from selected card
- Improve session management with force clear option
- Add accessibility improvements for WCAG compliance
- Remove duplicate CSS definitions
- Add missing CSS classes for workspace and config panel"
```

---

## Summary

| Task | Description | Files Changed |
|------|-------------|---------------|
| 1 | SVG Icons Fill | 44 SVG files |
| 2 | CSS Class Names | sscribe-admin.css |
| 3 | HTML Class Names | sscribe-admin-display.php |
| 4 | Helper Icon Prefix | class-sscribe-helpers.php |
| 5 | JS Status Logic | sscribe-admin.js |
| 6 | Format Timing | sscribe-admin-display.php |
| 7 | Session Management | class-sscribe-session.php, class-sscribe-batch-processor.php, sscribe-admin.js |
| 8 | Accessibility | sscribe-admin-display.php, sscribe-admin.css |
| 9 | Verification | All files |

**Total estimated time:** 30-45 minutes
