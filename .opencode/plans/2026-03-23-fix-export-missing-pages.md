# Fix Export Missing Pages - Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix critical bug where only 10-15 out of 110+ pages are exported, and add export settings UI for language/status filtering.

**Architecture:** Replace unreliable transient-based session storage with file-based JSON storage (immune to caching plugins). Add settings UI for export filtering. Validate session integrity at each batch step.

**Tech Stack:** PHP 7.4+, WordPress 5.8+, JSON file storage

---

## Root Cause Analysis

The current implementation stores export session data (including all page IDs) in WordPress transients:
```php
set_transient('sscribe_export_' . $session_id, $data, 4 * HOUR_IN_SECONDS);
```

**Problem:** On sites with aggressive caching (Redis/Memcached + WP Rocket + LightSpeed + Cloudflare):
1. Transients may exceed Redis 1MB key limit (500+ page IDs + metadata)
2. Cache plugins purge transients during their optimization cycles
3. Object cache backends may silently fail on large serialized data
4. No validation when transient is retrieved - corrupted data is used silently

**Result:** Page IDs are lost mid-export, causing only partial exports with no error messages.

---

## File Structure

| File | Action | Purpose |
|------|--------|---------|
| `includes/class-sscribe-session.php` | Create | File-based session storage |
| `includes/class-sscribe-batch-processor.php` | Modify | Use new session handler |
| `includes/class-sscribe-page-collector.php` | Modify | Post status support |
| `admin/partials/sscribe-admin-display.php` | Modify | New settings UI |
| `admin/class-sscribe-admin.php` | Modify | Handle new settings |
| `admin/js/sscribe-admin.js` | Modify | Pass new parameters |
| `admin/css/sscribe-admin.css` | Modify | Style new controls |
| `tests/Unit/SScribe_Session_Test.php` | Create | Test file-based sessions |
| `tests/Unit/SScribe_Page_Collector_Test.php` | Create | Test post status filtering |

---

## Task 1: Create File-Based Session Handler

**Files:**
- Create: `includes/class-sscribe-session.php`
- Create: `tests/Unit/SScribe_Session_Test.php`

### Step 1.1: Write the failing test for session storage

- [ ] **Create test file `tests/Unit/SScribe_Session_Test.php`**

```php
<?php
/**
 * Unit tests for SScribe_Session class.
 *
 * @package SScribe
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test SScribe_Session file-based storage.
 */
class SScribe_Session_Test extends TestCase {

    private $test_dir;

    protected function setUp(): void {
        parent::setUp();
        $this->test_dir = sys_get_temp_dir() . '/sscribe-test-' . uniqid();
        mkdir($this->test_dir, 0755, true);
    }

    protected function tearDown(): void {
        $this->recursiveDelete($this->test_dir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) return;
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->recursiveDelete($file) : unlink($file);
        }
        rmdir($dir);
    }

    public function testCreateSession(): void {
        $session = new SScribe_Session($this->test_dir);
        $id = $session->create(array('page_ids' => array(1, 2, 3), 'total' => 3));
        
        $this->assertNotEmpty($id);
        $this->assertEquals(16, strlen($id));
    }

    public function testGetSession(): void {
        $session = new SScribe_Session($this->test_dir);
        $id = $session->create(array('page_ids' => array(1, 2, 3), 'total' => 3));
        
        $data = $session->get($id);
        
        $this->assertIsArray($data);
        $this->assertEquals(array(1, 2, 3), $data['page_ids']);
        $this->assertEquals(3, $data['total']);
    }

    public function testUpdateSession(): void {
        $session = new SScribe_Session($this->test_dir);
        $id = $session->create(array('page_ids' => array(1, 2, 3), 'processed' => 0));
        
        $session->update($id, array('processed' => 2));
        
        $data = $session->get($id);
        $this->assertEquals(2, $data['processed']);
    }

    public function testDeleteSession(): void {
        $session = new SScribe_Session($this->test_dir);
        $id = $session->create(array('page_ids' => array(1, 2, 3)));
        
        $result = $session->delete($id);
        
        $this->assertTrue($result);
        $this->assertNull($session->get($id));
    }

    public function testLargeSessionData(): void {
        $large_page_ids = range(1, 1000);
        
        $session = new SScribe_Session($this->test_dir);
        $id = $session->create(array('page_ids' => $large_page_ids, 'total' => 1000));
        
        $data = $session->get($id);
        
        $this->assertCount(1000, $data['page_ids']);
    }

    public function testValidateIntegrity(): void {
        $session = new SScribe_Session($this->test_dir);
        $id = $session->create(array('page_ids' => array(1, 2, 3), 'total' => 3));
        
        $this->assertTrue($session->validate($id));
    }

    public function testInvalidSessionReturnsNull(): void {
        $session = new SScribe_Session($this->test_dir);
        
        $data = $session->get('nonexistent-id');
        
        $this->assertNull($data);
    }
}
```

- [ ] **Run test to verify it fails**

Run: `composer test tests/Unit/SScribe_Session_Test.php`
Expected: FAIL with "Class SScribe_Session not found"

---

### Step 1.2: Implement SScribe_Session class

- [ ] **Create `includes/class-sscribe-session.php`**

```php
<?php
/**
 * File-based session storage for export operations.
 *
 * Replaces transient-based storage to avoid issues with caching plugins
 * (Redis, Memcached, WP Rocket, LightSpeed) that may clear or corrupt
 * large transients during export operations.
 *
 * @package SScribe
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class SScribe_Session
 *
 * Provides reliable file-based storage for export session data.
 */
class SScribe_Session {

    private $storage_dir;
    private $session_prefix = 'sscribe-session-';

    public function __construct(string $storage_dir = null) {
        if ($storage_dir === null) {
            $upload_dir = wp_upload_dir();
            $storage_dir = trailingslashit($upload_dir['basedir']) . 'sscribe-sessions/';
        }
        $this->storage_dir = trailingslashit($storage_dir);
        $this->ensure_directory_exists();
    }

    private function ensure_directory_exists(): void {
        if (!is_dir($this->storage_dir)) {
            wp_mkdir_p($this->storage_dir);
            file_put_contents($this->storage_dir . '.htaccess', 'Deny from all');
            file_put_contents($this->storage_dir . 'index.html', '');
        }
    }

    public function create(array $data): string {
        $session_id = wp_generate_password(16, false);
        $session_id = strtolower($session_id);
        
        $data['created_at'] = time();
        $data['session_id'] = $session_id;
        
        $file_path = $this->get_file_path($session_id);
        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        
        if ($json === false) {
            return '';
        }
        
        $result = file_put_contents($file_path, $json, LOCK_EX);
        
        if ($result === false) {
            return '';
        }
        
        return $session_id;
    }

    public function get(string $session_id): ?array {
        $session_id = sanitize_key($session_id);
        
        if (empty($session_id) || strlen($session_id) !== 16) {
            return null;
        }
        
        $file_path = $this->get_file_path($session_id);
        
        if (!file_exists($file_path)) {
            return null;
        }
        
        $content = file_get_contents($file_path);
        
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        
        return $data;
    }

    public function update(string $session_id, array $data): bool {
        $existing = $this->get($session_id);
        
        if ($existing === null) {
            return false;
        }
        
        $merged = array_merge($existing, $data);
        $merged['updated_at'] = time();
        
        $file_path = $this->get_file_path($session_id);
        $json = wp_json_encode($merged, JSON_UNESCAPED_UNICODE);
        
        if ($json === false) {
            return false;
        }
        
        $result = file_put_contents($file_path, $json, LOCK_EX);
        
        return $result !== false;
    }

    public function delete(string $session_id): bool {
        $session_id = sanitize_key($session_id);
        $file_path = $this->get_file_path($session_id);
        
        if (!file_exists($file_path)) {
            return false;
        }
        
        return unlink($file_path);
    }

    public function validate(string $session_id): bool {
        $data = $this->get($session_id);
        
        if ($data === null) {
            return false;
        }
        
        $required_keys = array('page_ids', 'total', 'processed');
        
        foreach ($required_keys as $key) {
            if (!isset($data[$key])) {
                return false;
            }
        }
        
        if (!is_array($data['page_ids'])) {
            return false;
        }
        
        if (count($data['page_ids']) !== (int) $data['total']) {
            return false;
        }
        
        return true;
    }

    public function cleanup_expired(int $max_age_seconds = 14400): int {
        $files = glob($this->storage_dir . $this->session_prefix . '*.json');
        $deleted = 0;
        $now = time();
        
        if ($files === false) {
            return 0;
        }
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            
            if (isset($data['created_at']) && ($now - $data['created_at']) > $max_age_seconds) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }

    private function get_file_path(string $session_id): string {
        return $this->storage_dir . $this->session_prefix . $session_id . '.json';
    }

    public function get_storage_dir(): string {
        return $this->storage_dir;
    }
}
```

- [ ] **Add require statement in main plugin file `sscribe-export-site-pages.php` after line 48**

```php
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-session.php';
```

- [ ] **Run test to verify it passes**

Run: `composer test tests/Unit/SScribe_Session_Test.php`
Expected: PASS (7 tests)

- [ ] **Commit**

```bash
git add includes/class-sscribe-session.php tests/Unit/SScribe_Session_Test.php sscribe-export-site-pages.php
git commit -m "feat: add file-based session storage to replace transients"
```

---

## Task 2: Add Post Status Support to Page Collector

**Files:**
- Modify: `includes/class-sscribe-page-collector.php`
- Create: `tests/Unit/SScribe_Page_Collector_Test.php`

### Step 2.1: Write tests for post status filtering

- [ ] **Create `tests/Unit/SScribe_Page_Collector_Test.php`**

```php
<?php
/**
 * Unit tests for SScribe_Page_Collector class.
 *
 * @package SScribe
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SScribe_Page_Collector_Test extends TestCase {

    public function testGetPageIdsWithDefaultStatus(): void {
        $collector = new SScribe_Page_Collector();
        
        $this->assertTrue(method_exists($collector, 'get_page_ids'));
    }

    public function testGetPageIdsAcceptsPostStatusParameter(): void {
        $collector = new SScribe_Page_Collector();
        
        $reflection = new ReflectionMethod($collector, 'get_page_ids');
        $params = $reflection->getParameters();
        
        $status_param_exists = false;
        foreach ($params as $param) {
            if ($param->getName() === 'post_status') {
                $status_param_exists = true;
                break;
            }
        }
        
        $this->assertTrue($status_param_exists, 'get_page_ids should accept post_status parameter');
    }

    public function testGetValidPostStatuses(): void {
        $collector = new SScribe_Page_Collector();
        
        $valid_statuses = $collector->get_valid_post_statuses();
        
        $this->assertIsArray($valid_statuses);
        $this->assertContains('publish', $valid_statuses);
        $this->assertContains('draft', $valid_statuses);
        $this->assertContains('private', $valid_statuses);
        $this->assertContains('future', $valid_statuses);
    }

    public function testValidatePostStatusReturnsValidStatus(): void {
        $collector = new SScribe_Page_Collector();
        
        $result = $collector->validate_post_status('publish');
        $this->assertEquals('publish', $result);
    }

    public function testValidatePostStatusReturnsDefaultForInvalid(): void {
        $collector = new SScribe_Page_Collector();
        
        $result = $collector->validate_post_status('invalid_status');
        $this->assertEquals('publish', $result);
    }

    public function testValidatePostStatusHandlesAll(): void {
        $collector = new SScribe_Page_Collector();
        
        $result = $collector->validate_post_status('all');
        $this->assertEquals('any', $result);
    }
}
```

- [ ] **Run test to verify it fails**

Run: `composer test tests/Unit/SScribe_Page_Collector_Test.php`
Expected: FAIL with method not found errors

---

### Step 2.2: Implement post status support in Page Collector

- [ ] **Modify `includes/class-sscribe-page-collector.php` - Update `get_page_ids` method (lines 28-61)**

```php
public function get_page_ids($language = '', $post_status = 'publish') {
    $post_status = $this->validate_post_status($post_status);
    
    $args = array(
        'post_type'      => 'page',
        'post_status'    => $post_status,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    );

    $switched = false;
    if (!empty($language) && $this->is_wpml_active()) {
        do_action('wpml_switch_language', $language);
        $args['suppress_filters'] = false;
        $switched = true;
    }

    try {
        $query = new WP_Query($args);
        $page_ids = $query->posts;
    } finally {
        if ($switched) {
            do_action('wpml_switch_language', null);
        }
    }

    return $page_ids;
}
```

- [ ] **Add new methods to `SScribe_Page_Collector` class**

```php
public function get_valid_post_statuses(): array {
    return array(
        'publish' => __('Published', 'sscribe-export-site-pages'),
        'draft'   => __('Draft', 'sscribe-export-site-pages'),
        'private' => __('Private', 'sscribe-export-site-pages'),
        'future'  => __('Scheduled', 'sscribe-export-site-pages'),
        'pending' => __('Pending Review', 'sscribe-export-site-pages'),
    );
}

public function validate_post_status(string $status): string {
    $valid = array_keys($this->get_valid_post_statuses());
    
    if ($status === 'all') {
        return 'any';
    }
    
    if (in_array($status, $valid, true)) {
        return $status;
    }
    
    return 'publish';
}
```

- [ ] **Update `get_page_count_only` method (lines 71-99) to accept post_status**

```php
public function get_page_count_only($language = '', $post_status = 'publish') {
    $post_status = $this->validate_post_status($post_status);
    
    $args = array(
        'post_type'      => 'page',
        'post_status'    => $post_status,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => false,
    );

    $switched = false;
    if (!empty($language) && $this->is_wpml_active()) {
        do_action('wpml_switch_language', $language);
        $args['suppress_filters'] = false;
        $switched = true;
    }

    try {
        $query = new WP_Query($args);
        $count = (int) $query->found_posts;
    } finally {
        if ($switched) {
            do_action('wpml_switch_language', null);
        }
    }

    return $count;
}
```

- [ ] **Run test to verify it passes**

Run: `composer test tests/Unit/SScribe_Page_Collector_Test.php`
Expected: PASS (6 tests)

- [ ] **Commit**

```bash
git add includes/class-sscribe-page-collector.php tests/Unit/SScribe_Page_Collector_Test.php
git commit -m "feat: add post status filtering to page collector"
```

---

## Task 3: Update Batch Processor to Use File-Based Sessions

**Files:**
- Modify: `includes/class-sscribe-batch-processor.php`

### Step 3.1: Replace transient calls with session file storage

- [ ] **Add session property and initialization in `__construct`**

Add after line 47:
```php
private $session;

public function __construct() {
    $this->batch_size = (int) apply_filters('sscribe_batch_size', 1);
    $this->batch_size = max(1, min(20, $this->batch_size));

    $this->collector   = new SScribe_Page_Collector();
    $this->exporter    = new SScribe_Exporter();
    $this->zip_handler = new SScribe_Zip_Handler();
    $this->session     = new SScribe_Session();
}
```

- [ ] **Replace `ajax_start_export` method**

Key changes:
- Add `post_status` parameter handling
- Use `$this->session->create()` instead of `set_transient()`
- Add error handling for session creation failure

- [ ] **Replace `ajax_process_batch` method**

Key changes:
- Use `$this->session->get()` instead of `get_transient()`
- Add `$this->session->validate()` check
- Use `$this->session->update()` instead of `set_transient()`
- Add better error messages for session issues

- [ ] **Update `finalize_export` method**

Key changes:
- Use `$this->session->delete()` instead of `delete_transient()`
- Report error count in success message

- [ ] **Run all tests**

Run: `composer test`
Expected: All tests PASS

- [ ] **Commit**

```bash
git add includes/class-sscribe-batch-processor.php
git commit -m "fix: replace transient storage with file-based session storage"
```

---

## Task 4: Add Export Settings UI

**Files:**
- Modify: `admin/partials/sscribe-admin-display.php`
- Modify: `admin/css/sscribe-admin.css`
- Modify: `admin/js/sscribe-admin.js`

### Step 4.1: Add post status selector to UI

- [ ] **Add post status filter after language cards in `sscribe-admin-display.php`**

- [ ] **Add CSS styles for status options in `sscribe-admin.css`**

- [ ] **Update `sscribe-admin.js` to pass post_status parameter**

- [ ] **Commit**

```bash
git add admin/partials/sscribe-admin-display.php admin/css/sscribe-admin.css admin/js/sscribe-admin.js
git commit -m "feat: add post status filter to export settings UI"
```

---

## Task 5: Add "All Languages" Option

**Files:**
- Modify: `admin/partials/sscribe-admin-display.php`

### Step 5.1: Add "All Languages" card

- [ ] **Add "All Languages" option before individual language cards**

- [ ] **Commit**

```bash
git add admin/partials/sscribe-admin-display.php
git commit -m "feat: add 'All Languages' option to language selector"
```

---

## Task 6: Add Session Cleanup Cron

**Files:**
- Modify: `includes/class-sscribe.php`
- Modify: `includes/class-sscribe-activator.php`
- Modify: `includes/class-sscribe-deactivator.php`

### Step 6.1: Register cron job

- [ ] **Add cron hook for session cleanup**

- [ ] **Schedule cron on activation**

- [ ] **Clear cron on deactivation**

- [ ] **Commit**

```bash
git add includes/class-sscribe.php includes/class-sscribe-activator.php includes/class-sscribe-deactivator.php
git commit -m "feat: add scheduled cleanup for expired session files"
```

---

## Task 7: Update Version and Final Testing

**Files:**
- Modify: `sscribe-export-site-pages.php`
- Modify: `readme.txt`

### Step 7.1: Version bump

- [ ] **Update version to 1.7.0**

- [ ] **Run full CI**

Run: `composer ci`
Expected: All tests pass

- [ ] **Final commit**

```bash
git add -A
git commit -m "release: v1.7.0 - Fix missing pages export, add settings UI"
```

---

## Testing Checklist

After implementation, verify:

- [ ] Export 500+ pages completes successfully
- [ ] Export shows correct page count at start
- [ ] Progress percentage matches actual progress
- [ ] "All Languages" exports pages from all WPML languages
- [ ] Post status filter works (published, draft, private, all)
- [ ] Session files are cleaned up after export
- [ ] Session files are cleaned up by cron after 4 hours
- [ ] Error messages display when session is corrupted
- [ ] Works with WP Rocket, LightSpeed, Redis/Memcached active
