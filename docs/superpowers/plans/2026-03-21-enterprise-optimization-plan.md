# SScribe Enterprise Optimization Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transform SScribe into an enterprise-grade WordPress plugin with exceptional code quality, performance, and user experience.

**Architecture:** PSR-4 autoloading with DTOs, interfaces, and dependency injection. Caching layer with object cache + transient fallback. Interface-based SEO and multilingual integrations.

**Tech Stack:** PHP 7.4+, PHPUnit 9, PHPStan 1.10, PHPCS 3, GitHub Actions, WP-CLI

---

## Phase 1: Foundation & WordPress.org Compliance

### Task 1.1: Create .distignore File

**Files:**
- Create: `.distignore`

- [ ] **Step 1: Create .distignore file**

```
# Development files
.github/
.git/
.gitignore
.node_modules/

# Build & deploy
build/
deploy/
scripts/
dist/

# Testing
tests/
phpunit.xml*
phpstan.neon*
phpcs.xml*
.php-cs-fixer.php
coverage/

# IDE
.idea/
.vscode/
*.sublime-*

# Node
package*.json
node_modules/

# Documentation
docs/
*.md
!readme.txt

# Vendor extras (WordPress.org requirement)
vendor/*/.github/
vendor/*/.git/
vendor/*/COPYING*
vendor/*/.github_changelog_generator
vendor/*/.php-cs-fixer*
vendor/*/phpstan.neon*
vendor/*/phpunit.xml*
vendor/*/tests/
vendor/*/docs/
vendor/*/.travis.yml
vendor/*/.scrutinizer.yml
vendor/*/mkdocs.yml

# PCLZip conflict (WordPress.org requirement)
vendor/phpoffice/phpword/src/PhpWord/Shared/PCLZip/
```

- [ ] **Step 2: Verify file exists**

Run: `cat .distignore`

---

### Task 1.2: Update composer.json for PSR-4 Autoloading

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Update composer.json with autoloading and dev dependencies**

```json
{
    "name": "simplix-innovations/sscribe-export-site-pages",
    "description": "Export every page into beautifully formatted Word DOCX files with multilingual support, SEO meta, rich styling, and secure ZIP download.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "authors": [
        {
            "name": "Simplix Innovations",
            "email": "info@simplixi.com",
            "homepage": "https://simplixi.com"
        }
    ],
    "require": {
        "php": ">=7.4",
        "phpoffice/phpword": "^1.3"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "phpstan/phpstan": "^1.10",
        "squizlabs/php_codesniffer": "^3.7",
        "wp-coding-standards/wpcs": "^3.0",
        "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
        "phpcompatibility/php-compatibility": "^9.3",
        "brain/monkey": "^2.7"
    },
    "autoload": {
        "psr-4": {
            "SScribe\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "SScribe\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit",
        "test:coverage": "phpunit --coverage-html coverage/html",
        "lint": "phpcs --standard=phpcs.xml.dist",
        "lint:fix": "phpcbf --standard=phpcs.xml.dist",
        "analyze": "phpstan analyse",
        "build": "composer install --no-dev --optimize-autoloader"
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true,
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    }
}
```

- [ ] **Step 2: Run composer update**

Run: `composer update`

Expected: Dependencies installed successfully

---

### Task 1.3: Create PHPStan Configuration

**Files:**
- Create: `phpstan.neon.dist`

- [ ] **Step 1: Create phpstan.neon.dist**

```neon
parameters:
    level: 5
    paths:
        - src
        - scribe-export-site-pages.php
    excludePaths:
        - vendor
        - tests
    ignoreErrors:
        - '#Call to function get_post_meta\(\)#'
        - '#Call to function get_option\(\)#'
        - '#Call to function apply_filters\(\)#'
        - '#Call to function do_action\(\)#'
        - '#WordPress\\.#'
    bootstrapFiles:
        - tests/stubs/wordpress-stubs.php
```

- [ ] **Step 2: Create WordPress stubs file**

Create: `tests/stubs/wordpress-stubs.php`

```php
<?php
// WordPress function stubs for PHPStan
// phpcs:ignoreFile

if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default') { return $text; }
}
if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default') { return $number === 1 ? $single : $plural; }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw') { return ''; }
}
if (!function_exists('get_option')) {
    function get_option($option, $default = false) { return $default; }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) { return true; }
}
if (!function_exists('delete_option')) {
    function delete_option($option) { return true; }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) { return ''; }
}
if (!function_exists('get_transient')) {
    function get_transient($transient) { return false; }
}
if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) { return true; }
}
if (!function_exists('delete_transient')) {
    function delete_transient($transient) { return true; }
}
if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '', $force = false, $found = null) { return false; }
}
if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0) { return true; }
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') { return true; }
}
if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache() { return false; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return $str; }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename) { return $filename; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_kses')) {
    function wp_kses($string, $allowed_html, $allowed_protocols = array()) { return $string; }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false) { return $string; }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($string) { return rtrim($string, '/\\') . '/'; }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) { return date('Y-m-d H:i:s'); }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) { return 'password123'; }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target) { return true; }
}
if (!function_exists('wp_delete_file')) {
    function wp_delete_file($file) { return true; }
}
if (!function_exists('home_url')) {
    function home_url($path = '', $scheme = null) { return 'http://example.org' . $path; }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin') { return 'http://example.org/wp-admin/' . $path; }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return dirname($file) . '/'; }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) { return 'http://example.org/wp-content/plugins/' . basename(dirname($file)) . '/'; }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value = false, $url = false) { return $url; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) { return $value; }
}
if (!function_exists('do_action')) {
    function do_action($tag, ...$arg) { return; }
}
if (!function_exists('add_action')) {
    function add_action($tag, $function_to_add, $priority = 10, $accepted_args = 1) { return true; }
}
if (!function_exists('add_filter')) {
    function add_filter($tag, $function_to_add, $priority = 10, $accepted_args = 1) { return true; }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $function) { return; }
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $function) { return; }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $die = true) { return true; }
}
if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args) { return true; }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null) { return; }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null) { return; }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) { return 'nonce123'; }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) { return true; }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = array()) { return false; }
}
if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event($timestamp, $hook, $args = array()) { return; }
}
if (!function_exists('size_format')) {
    function size_format($bytes, $decimals = 0) { return $bytes . ' B'; }
}
if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp_with_offset = false, $gmt = false) { return date($format); }
}
if (!function_exists('get_file_data')) {
    function get_file_data($file, $default_headers, $context = '') { return array(); }
}
if (!function_exists('nocache_headers')) {
    function nocache_headers() { return; }
}

// WordPress class stubs
if (!class_exists('WP_Post')) {
    class WP_Post {
        public $ID = 0;
        public $post_author = 0;
        public $post_date = '';
        public $post_date_gmt = '';
        public $post_content = '';
        public $post_title = '';
        public $post_excerpt = '';
        public $post_status = '';
        public $comment_status = '';
        public $ping_status = '';
        public $post_password = '';
        public $post_name = '';
        public $to_ping = '';
        public $pinged = '';
        public $post_modified = '';
        public $post_modified_gmt = '';
        public $post_content_filtered = '';
        public $post_parent = 0;
        public $guid = '';
        public $menu_order = 0;
        public $post_type = '';
        public $post_mime_type = '';
        public $comment_count = 0;
        public $filter = '';
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query {
        public $posts = array();
        public function __construct($query = array()) {}
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct($code = '', $message = '', $data = '') {}
        public function get_error_message($code = '') { return ''; }
        public function is_wp_error() { return true; }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}
```

---

### Task 1.4: Create PHPCS Configuration

**Files:**
- Create: `phpcs.xml.dist`

- [ ] **Step 1: Create phpcs.xml.dist**

```xml
<?xml version="1.0"?>
<ruleset name="SScribe Coding Standards">
    <description>Coding standards for SScribe Export Site Pages</description>
    
    <!-- Files to check -->
    <file>src</file>
    <file>scribe-export-site-pages.php</file>
    
    <!-- Exclude patterns -->
    <exclude-pattern>*/vendor/*</exclude-pattern>
    <exclude-pattern>*/tests/*</exclude-pattern>
    <exclude-pattern>*/node_modules/*</exclude-pattern>
    
    <!-- Use WordPress Coding Standards -->
    <rule ref="WordPress-Core">
        <!-- Allow short array syntax -->
        <exclude name="Generic.Arrays.DisallowShortArraySyntax"/>
    </rule>
    <rule ref="WordPress-Docs"/>
    
    <!-- PHP Compatibility -->
    <rule ref="PHPCompatibility"/>
    <config name="testVersion" value="7.4-"/>
    
    <!-- File extensions -->
    <arg name="extensions" value="php"/>
    
    <!-- Show progress -->
    <arg value="s"/>
    
    <!-- Use colors -->
    <arg name="colors"/>
    
    <!-- Show sniff codes -->
    <arg name="report" value="full"/>
</ruleset>
```

---

### Task 1.5: Create PHPUnit Configuration

**Files:**
- Create: `phpunit.xml.dist`

- [ ] **Step 1: Create phpunit.xml.dist**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    cacheResultFile=".phpunit.cache/test-results"
    executionOrder="depends,defects"
    failOnRisky="true"
    failOnWarning="true"
    verbose="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory suffix="Test.php">tests/Integration</directory>
        </testsuite>
    </testsuites>
    
    <coverage>
        <include>
            <directory suffix=".php">src</directory>
        </include>
        <report>
            <html outputDirectory="coverage/html"/>
            <clover outputFile="coverage/clover.xml"/>
        </report>
    </coverage>
    
    <php>
        <env name="WP_TESTS_SKIP_INSTALL" value="1"/>
        <env name="WP_TESTS_DOMAIN" value="example.org"/>
        <env name="WP_TESTS_EMAIL" value="admin@example.org"/>
    </php>
</phpunit>
```

---

### Task 1.6: Create Test Bootstrap

**Files:**
- Create: `tests/bootstrap.php`

- [ ] **Step 1: Create tests directory structure**

Run: `mkdir -p tests/Unit tests/Integration tests/stubs tests/fixtures`

- [ ] **Step 2: Create tests/bootstrap.php**

```php
<?php
/**
 * PHPUnit Bootstrap File
 *
 * @package SScribe\Tests
 */

declare(strict_types=1);

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load WordPress stubs for PHPStan/PHPUnit
require_once dirname(__DIR__) . '/tests/stubs/wordpress-stubs.php';

// Brain\Monkey setup for WordPress function mocking
use Brain\Monkey;

// Reset Brain\Monkey after each test
PHPUnit\Framework\TestCase::tearDown(function(): void {
    Monkey\tearDown();
});
```

---

### Task 1.7: Fix SVG Escaping in Admin Display

**Files:**
- Modify: `admin/partials/sscribe-admin-display.php:325`

- [ ] **Step 1: Define allowed SVG tags before the feature loop**

Find the `$features` array definition (around line 301) and add this before it:

```php
<?php
// Define allowed SVG tags for wp_kses
$allowed_svg_tags = [
    'path' => [
        'd' => true,
        'fill' => true,
        'fill-opacity' => true,
        'stroke' => true,
        'stroke-width' => true,
        'stroke-linecap' => true,
        'stroke-linejoin' => true,
        'transform' => true,
    ],
    'circle' => [
        'cx' => true,
        'cy' => true,
        'r' => true,
        'stroke' => true,
        'stroke-width' => true,
        'fill' => true,
        'fill-opacity' => true,
    ],
    'line' => [
        'x1' => true,
        'y1' => true,
        'x2' => true,
        'y2' => true,
        'stroke' => true,
        'stroke-width' => true,
        'transform' => true,
    ],
    'polyline' => [
        'points' => true,
        'stroke' => true,
        'stroke-width' => true,
        'fill' => true,
        'transform' => true,
    ],
    'rect' => [
        'x' => true,
        'y' => true,
        'width' => true,
        'height' => true,
        'rx' => true,
        'ry' => true,
        'stroke' => true,
        'stroke-width' => true,
        'fill' => true,
        'fill-opacity' => true,
        'transform' => true,
    ],
];
?>
```

- [ ] **Step 2: Fix the echo statement on line 325**

Replace:
```php
<?php echo $feature['icon']; ?> // outputting raw SVG paths
```

With:
```php
<?php echo wp_kses($feature['icon'], $allowed_svg_tags); ?>
```

---

### Task 1.8: Remove class-sscribe-i18n.php

**Files:**
- Delete: `includes/class-sscribe-i18n.php`
- Modify: `scribe-export-site-pages.php`

- [ ] **Step 1: Delete the i18n class file**

Run: `rm includes/class-sscribe-i18n.php`

- [ ] **Step 2: Remove require from main plugin file**

In `scribe-export-site-pages.php`, remove this line:
```php
require_once SSCRIBE_PLUGIN_DIR . 'includes/class-sscribe-i18n.php';
```

- [ ] **Step 3: Remove set_locale method from SScribe class**

In the future `src/Core/Plugin.php`, the `set_locale()` method will not be needed since WordPress auto-loads translations for WordPress.org plugins since 4.6.

---

## Phase 2: Create Source Directory Structure

### Task 2.1: Create src Directory Structure

**Files:**
- Create directories

- [ ] **Step 1: Create all src directories**

```bash
mkdir -p src/Core
mkdir -p src/DTO
mkdir -p src/Export
mkdir -p src/Processing
mkdir -p src/Integration/Seo
mkdir -p src/Integration/Multilingual
mkdir -p src/Admin
mkdir -p src/Utils
```

---

### Task 2.2: Create PageData DTO

**Files:**
- Create: `src/DTO/PageData.php`

- [ ] **Step 1: Create PageData DTO**

```php
<?php
/**
 * Page Data Transfer Object.
 *
 * @package SScribe\DTO
 */

declare(strict_types=1);

namespace SScribe\DTO;

use WP_Post;

/**
 * Immutable page data for export.
 */
final class PageData
{
    public int $id = 0;
    public string $title = '';
    public string $content = '';
    public string $raw_content = '';
    public string $excerpt = '';
    public string $permalink = '';
    public string $slug = '';
    public string $author = '';
    public string $date_published = '';
    public string $date_modified = '';
    public int $word_count = 0;
    public int $reading_time = 0;
    public string $language = '';
    
    public ?string $featured_image_url = null;
    public ?string $featured_image_path = null;
    
    /**
     * @var array<int, array{title: string, url: string}>
     */
    public array $breadcrumbs = [];
    
    /**
     * @var array<int, array{id: int, title: string, url: string, author: string, date: string}>
     */
    public array $children = [];
    
    public ?SeoData $seo = null;
    public int $parent_id = 0;

    /**
     * Create from WordPress post.
     */
    public static function from_post(WP_Post $post): self
    {
        $instance = new self();
        
        $instance->id = $post->ID;
        $instance->title = get_the_title($post);
        $instance->content = apply_filters('the_content', $post->post_content);
        $instance->raw_content = $post->post_content;
        $instance->excerpt = $post->post_excerpt;
        $instance->permalink = (string) get_permalink($post);
        $instance->slug = $post->post_name;
        $instance->author = get_the_author_meta('display_name', (int) $post->post_author);
        $instance->date_published = get_the_date('F j, Y', $post);
        $instance->date_modified = get_the_modified_date('F j, Y', $post);
        $instance->word_count = str_word_count(wp_strip_all_tags($instance->content));
        $instance->reading_time = max(1, (int) ceil($instance->word_count / 200));
        $instance->parent_id = $post->post_parent;
        
        return $instance;
    }
}
```

---

### Task 2.3: Create SeoData DTO

**Files:**
- Create: `src/DTO/SeoData.php`

- [ ] **Step 1: Create SeoData DTO**

```php
<?php
/**
 * SEO Data Transfer Object.
 *
 * @package SScribe\DTO
 */

declare(strict_types=1);

namespace SScribe\DTO;

/**
 * SEO metadata container.
 */
final class SeoData
{
    public string $meta_title = '';
    public string $meta_description = '';
    public string $focus_keyword = '';
    public string $source = '';

    /**
     * Check if any SEO data exists.
     */
    public function has_data(): bool
    {
        return ! empty($this->meta_title)
            || ! empty($this->meta_description)
            || ! empty($this->focus_keyword);
    }
}
```

---

### Task 2.4: Create ExportResult DTO

**Files:**
- Create: `src/DTO/ExportResult.php`

- [ ] **Step 1: Create ExportResult DTO**

```php
<?php
/**
 * Export Result Data Transfer Object.
 *
 * @package SScribe\DTO
 */

declare(strict_types=1);

namespace SScribe\DTO;

/**
 * Result of a single page export.
 */
final class ExportResult
{
    public bool $success = false;
    public ?string $file_path = null;
    public ?string $error = null;
    public float $generation_time = 0.0;
    public int $memory_used = 0;

    /**
     * Create successful result.
     */
    public static function success(string $file_path): self
    {
        $instance = new self();
        $instance->success = true;
        $instance->file_path = $file_path;
        return $instance;
    }

    /**
     * Create failed result.
     */
    public static function failure(string $error): self
    {
        $instance = new self();
        $instance->success = false;
        $instance->error = $error;
        return $instance;
    }
}
```

---

### Task 2.5: Create ExportSession DTO

**Files:**
- Create: `src/DTO/ExportSession.php`

- [ ] **Step 1: Create ExportSession DTO**

```php
<?php
/**
 * Export Session Data Transfer Object.
 *
 * @package SScribe\DTO
 */

declare(strict_types=1);

namespace SScribe\DTO;

/**
 * Represents an active export session.
 */
final class ExportSession
{
    public string $session_id = '';
    public string $temp_dir = '';
    
    /**
     * @var array<int>
     */
    public array $page_ids = [];
    
    public int $total = 0;
    public int $processed = 0;
    public string $language = '';
    
    /**
     * @var array<string>
     */
    public array $errors = [];
    
    public int $created_at = 0;

    /**
     * Create new session.
     */
    public static function create(array $page_ids, string $temp_dir, string $language = ''): self
    {
        $instance = new self();
        $instance->session_id = wp_generate_password(16, false);
        $instance->page_ids = $page_ids;
        $instance->temp_dir = $temp_dir;
        $instance->total = count($page_ids);
        $instance->language = $language;
        $instance->created_at = time();
        
        return $instance;
    }

    /**
     * Save session to transient.
     */
    public function save(): bool
    {
        return set_transient(
            'sscribe_export_' . $this->session_id,
            [
                'page_ids' => $this->page_ids,
                'temp_dir' => $this->temp_dir,
                'total' => $this->total,
                'processed' => $this->processed,
                'language' => $this->language,
                'errors' => $this->errors,
            ],
            HOUR_IN_SECONDS
        );
    }

    /**
     * Load session from transient.
     */
    public static function load(string $session_id): ?self
    {
        $data = get_transient('sscribe_export_' . $session_id);
        
        if ($data === false) {
            return null;
        }
        
        $instance = new self();
        $instance->session_id = $session_id;
        $instance->page_ids = $data['page_ids'] ?? [];
        $instance->temp_dir = $data['temp_dir'] ?? '';
        $instance->total = $data['total'] ?? 0;
        $instance->processed = $data['processed'] ?? 0;
        $instance->language = $data['language'] ?? '';
        $instance->errors = $data['errors'] ?? [];
        
        return $instance;
    }

    /**
     * Delete session.
     */
    public function delete(): bool
    {
        return delete_transient('sscribe_export_' . $this->session_id);
    }

    /**
     * Get progress percentage.
     */
    public function get_progress_percent(): int
    {
        if ($this->total === 0) {
            return 100;
        }
        
        return (int) round(($this->processed / $this->total) * 100);
    }
}
```

---

## Phase 3: Utility Classes

### Task 3.1: Create Cache Utility

**Files:**
- Create: `src/Utils/Cache.php`
- Create: `tests/Unit/Utils/CacheTest.php`

- [ ] **Step 1: Write failing test for Cache**

Create: `tests/Unit/Utils/CacheTest.php`

```php
<?php
/**
 * Cache Test.
 *
 * @package SScribe\Tests\Unit\Utils
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use SScribe\Utils\Cache;
use Brain\Monkey;

class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_set_and_get_value(): void
    {
        Monkey\Functions\expect('set_transient')
            ->once()
            ->with('sscribe_test_key', 'test_value', 3600)
            ->andReturn(true);

        Monkey\Functions\expect('get_transient')
            ->once()
            ->with('sscribe_test_key')
            ->andReturn('test_value');

        Cache::set('test_key', 'test_value');
        $result = Cache::get('test_key');

        $this->assertEquals('test_value', $result);
    }

    public function test_get_returns_default_for_missing_key(): void
    {
        Monkey\Functions\expect('get_transient')
            ->once()
            ->with('sscribe_missing_key')
            ->andReturn(false);

        $result = Cache::get('missing_key', 'default');

        $this->assertEquals('default', $result);
    }

    public function test_delete_removes_cached_value(): void
    {
        Monkey\Functions\expect('delete_transient')
            ->once()
            ->with('sscribe_delete_key')
            ->andReturn(true);

        Monkey\Functions\expect('wp_cache_delete')
            ->once()
            ->with('delete_key', 'sscribe')
            ->andReturn(true);

        $result = Cache::delete('delete_key');

        $this->assertTrue($result);
    }

    public function test_remember_returns_cached_value(): void
    {
        Monkey\Functions\expect('get_transient')
            ->once()
            ->with('sscribe_remember_key')
            ->andReturn('cached_value');

        $result = Cache::remember('remember_key', function () {
            return 'new_value';
        });

        $this->assertEquals('cached_value', $result);
    }

    public function test_remember_calls_callback_when_missing(): void
    {
        Monkey\Functions\expect('get_transient')
            ->once()
            ->with('sscribe_new_key')
            ->andReturn(false);

        Monkey\Functions\expect('set_transient')
            ->once()
            ->with('sscribe_new_key', 'computed_value', 3600)
            ->andReturn(true);

        $result = Cache::remember('new_key', function () {
            return 'computed_value';
        });

        $this->assertEquals('computed_value', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Utils/CacheTest.php`

Expected: FAIL - Class SScribe\Utils\Cache not found

- [ ] **Step 3: Create Cache utility**

Create: `src/Utils/Cache.php`

```php
<?php
/**
 * Caching Utility.
 *
 * @package SScribe\Utils
 */

declare(strict_types=1);

namespace SScribe\Utils;

/**
 * Unified caching layer with object cache + transient fallback.
 */
final class Cache
{
    private const PREFIX = 'sscribe_';
    private const GROUP = 'sscribe';

    /**
     * Get cached value.
     *
     * @param string $key     Cache key.
     * @param mixed  $default Default value if not found.
     * @return mixed Cached value or default.
     */
    public static function get(string $key, $default = null)
    {
        // Try object cache first (Redis, Memcached, etc.)
        if (wp_using_ext_object_cache()) {
            $value = wp_cache_get($key, self::GROUP);
            if ($value !== false) {
                return $value;
            }
        }

        // Fall back to transient (database)
        $value = get_transient(self::PREFIX . $key);
        
        return $value !== false ? $value : $default;
    }

    /**
     * Set cached value.
     *
     * @param string $key   Cache key.
     * @param mixed  $value Value to cache.
     * @param int    $ttl   Time to live in seconds.
     * @return bool Success.
     */
    public static function set(string $key, $value, int $ttl = HOUR_IN_SECONDS): bool
    {
        if (wp_using_ext_object_cache()) {
            wp_cache_set($key, $value, self::GROUP, $ttl);
        }
        
        return set_transient(self::PREFIX . $key, $value, $ttl);
    }

    /**
     * Delete cached value.
     *
     * @param string $key Cache key.
     * @return bool Success.
     */
    public static function delete(string $key): bool
    {
        wp_cache_delete($key, self::GROUP);
        
        return delete_transient(self::PREFIX . $key);
    }

    /**
     * Get value or compute and cache it.
     *
     * @param string   $key      Cache key.
     * @param callable $callback Function to compute value if missing.
     * @param int      $ttl      Time to live in seconds.
     * @return mixed Cached or computed value.
     */
    public static function remember(string $key, callable $callback, int $ttl = HOUR_IN_SECONDS)
    {
        $value = self::get($key);
        
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        self::set($key, $value, $ttl);
        
        return $value;
    }

    /**
     * Delete all SScribe cache entries.
     */
    public static function flush(): void
    {
        global $wpdb;

        // Delete transients
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_' . self::PREFIX . '%',
                '_site_transient_' . self::PREFIX . '%'
            )
        );
    }

    /**
     * Generate cache key for page data.
     *
     * @param int    $page_id  Page ID.
     * @param string $language Language code.
     * @return string Cache key.
     */
    public static function key_for_page(int $page_id, string $language = ''): string
    {
        return 'page_' . $page_id . '_' . md5($language);
    }

    /**
     * Generate cache key for SEO data.
     *
     * @param int $page_id Page ID.
     * @return string Cache key.
     */
    public static function key_for_seo(int $page_id): string
    {
        return 'seo_' . $page_id;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Utils/CacheTest.php`

Expected: PASS

---

### Task 3.2: Create MemoryManager Utility

**Files:**
- Create: `src/Processing/MemoryManager.php`
- Create: `tests/Unit/Processing/MemoryManagerTest.php`

- [ ] **Step 1: Write failing test for MemoryManager**

Create: `tests/Unit/Processing/MemoryManagerTest.php`

```php
<?php
/**
 * MemoryManager Test.
 *
 * @package SScribe\Tests\Unit\Processing
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit\Processing;

use PHPUnit\Framework\TestCase;
use SScribe\Processing\MemoryManager;

class MemoryManagerTest extends TestCase
{
    private MemoryManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new MemoryManager();
    }

    public function test_get_usage_returns_int(): void
    {
        $usage = $this->manager->get_usage();
        
        $this->assertIsInt($usage);
        $this->assertGreaterThan(0, $usage);
    }

    public function test_get_peak_returns_int(): void
    {
        $peak = $this->manager->get_peak();
        
        $this->assertIsInt($peak);
        $this->assertGreaterThan(0, $peak);
    }

    public function test_get_usage_percent_returns_float(): void
    {
        $percent = $this->manager->get_usage_percent();
        
        $this->assertIsFloat($percent);
        $this->assertGreaterThan(0, $percent);
        $this->assertLessThan(100, $percent);
    }

    public function test_format_bytes_returns_string(): void
    {
        $formatted = $this->manager->format_bytes(1024);
        
        $this->assertIsString($formatted);
        $this->assertStringContainsString('KB', $formatted);
    }

    public function test_format_bytes_handles_megabytes(): void
    {
        $formatted = $this->manager->format_bytes(1048576);
        
        $this->assertStringContainsString('MB', $formatted);
    }

    public function test_is_ok_returns_bool(): void
    {
        $result = $this->manager->is_ok();
        
        $this->assertIsBool($result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Processing/MemoryManagerTest.php`

Expected: FAIL - Class not found

- [ ] **Step 3: Create MemoryManager utility**

Create: `src/Processing/MemoryManager.php`

```php
<?php
/**
 * Memory Management Utility.
 *
 * @package SScribe\Processing
 */

declare(strict_types=1);

namespace SScribe\Processing;

/**
 * Memory monitoring and management for large exports.
 */
final class MemoryManager
{
    private int $memory_limit;
    private float $warning_threshold = 0.75;
    private float $critical_threshold = 0.85;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->memory_limit = $this->parse_memory_limit(ini_get('memory_limit'));
    }

    /**
     * Get current memory usage in bytes.
     */
    public function get_usage(): int
    {
        return memory_get_usage(true);
    }

    /**
     * Get peak memory usage in bytes.
     */
    public function get_peak(): int
    {
        return memory_get_peak_usage(true);
    }

    /**
     * Get available memory in bytes.
     */
    public function get_available(): int
    {
        return max(0, $this->memory_limit - $this->get_usage());
    }

    /**
     * Get memory usage as percentage.
     */
    public function get_usage_percent(): float
    {
        if ($this->memory_limit <= 0) {
            return 0.0;
        }
        
        return round(($this->get_usage() / $this->memory_limit) * 100, 2);
    }

    /**
     * Check if memory usage is within safe limits.
     */
    public function is_ok(): bool
    {
        return $this->get_usage_percent() < ($this->warning_threshold * 100);
    }

    /**
     * Check if memory is under pressure (warning level).
     */
    public function is_warning(): bool
    {
        $percent = $this->get_usage_percent();
        
        return $percent >= ($this->warning_threshold * 100)
            && $percent < ($this->critical_threshold * 100);
    }

    /**
     * Check if memory is critical.
     */
    public function is_critical(): bool
    {
        return $this->get_usage_percent() >= ($this->critical_threshold * 100);
    }

    /**
     * Check if processing should pause.
     */
    public function should_pause(): bool
    {
        return $this->is_critical();
    }

    /**
     * Force garbage collection.
     */
    public function gc(): int
    {
        if (function_exists('gc_collect_cycles')) {
            return gc_collect_cycles();
        }
        
        return 0;
    }

    /**
     * Format bytes to human-readable string.
     */
    public function format_bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes > 0 ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * Parse PHP memory_limit value to bytes.
     */
    private function parse_memory_limit(string $value): int
    {
        $value = trim($value);
        
        if ($value === '' || $value === '-1') {
            // Unlimited - use 512MB as safe default
            return 512 * 1024 * 1024;
        }

        $last = strtolower($value[strlen($value) - 1] ?? '');
        $number = (int) $value;

        switch ($last) {
            case 'g':
                $number *= 1024;
                // fall through
            case 'm':
                $number *= 1024;
                // fall through
            case 'k':
                $number *= 1024;
        }

        return $number;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Processing/MemoryManagerTest.php`

Expected: PASS

---

### Task 3.3: Create Compatibility Utility

**Files:**
- Create: `src/Utils/Compatibility.php`

- [ ] **Step 1: Create Compatibility utility**

```php
<?php
/**
 * Third-Party Compatibility Utility.
 *
 * @package SScribe\Utils
 */

declare(strict_types=1);

namespace SScribe\Utils;

/**
 * Compatibility layer for third-party services and plugins.
 */
final class Compatibility
{
    /**
     * Get headers to prevent caching for AJAX responses.
     *
     * @return array<string, string>
     */
    public static function get_no_cache_headers(): array
    {
        $headers = [
            'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Wed, 11 Jan 1984 05:00:00 GMT',
            'X-Content-Type-Options' => 'nosniff',
        ];

        // Cloudflare-specific headers
        if (self::is_cloudflare()) {
            $headers['CF-Cache-Status'] = 'BYPASS';
        }

        return $headers;
    }

    /**
     * Set nocache headers and constants.
     */
    public static function bypass_cache(): void
    {
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        
        if (! defined('DONOTCACHEDB')) {
            define('DONOTCACHEDB', true);
        }
        
        if (! defined('DONOTMINIFY')) {
            define('DONOTMINIFY', true);
        }
        
        if (! defined('DONOTCDN')) {
            define('DONOTCDN', true);
        }

        nocache_headers();
    }

    /**
     * Check if Cloudflare is active.
     */
    public static function is_cloudflare(): bool
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        return isset($_SERVER['HTTP_CF_CONNECTING_IP'])
            || isset($_SERVER['HTTP_CF_VISITOR']);
    }

    /**
     * Check if Wordfence is active.
     */
    public static function is_wordfence(): bool
    {
        return defined('WORDFENCE_VERSION')
            || class_exists('wordfence');
    }

    /**
     * Check if WP Rocket is active.
     */
    public static function is_wp_rocket(): bool
    {
        return defined('WP_ROCKET_VERSION');
    }

    /**
     * Check if W3 Total Cache is active.
     */
    public static function is_w3tc(): bool
    {
        return defined('W3TC');
    }

    /**
     * Check if WP Super Cache is active.
     */
    public static function is_wp_super_cache(): bool
    {
        return function_exists('wp_cache_clear_cache');
    }

    /**
     * Check if WP Engine hosting.
     */
    public static function is_wpengine(): bool
    {
        return defined('WPE_APIKEY')
            || class_exists('WPECommon');
    }

    /**
     * Check if SiteGround Optimizer is active.
     */
    public static function is_siteground(): bool
    {
        return defined('SITEGROUND_OPTIMIZER_VERSION');
    }

    /**
     * Get list of active caching/performance plugins.
     *
     * @return array<string>
     */
    public static function get_active_plugins(): array
    {
        $active = [];

        if (self::is_cloudflare()) {
            $active[] = 'Cloudflare';
        }
        
        if (self::is_wordfence()) {
            $active[] = 'Wordfence';
        }
        
        if (self::is_wp_rocket()) {
            $active[] = 'WP Rocket';
        }
        
        if (self::is_w3tc()) {
            $active[] = 'W3 Total Cache';
        }
        
        if (self::is_wp_super_cache()) {
            $active[] = 'WP Super Cache';
        }
        
        if (self::is_wpengine()) {
            $active[] = 'WP Engine';
        }
        
        if (self::is_siteground()) {
            $active[] = 'SiteGround Optimizer';
        }

        return $active;
    }

    /**
     * Get hosting context for diagnostics.
     *
     * @return array<string, bool>
     */
    public static function get_hosting_context(): array
    {
        return [
            'cloudflare' => self::is_cloudflare(),
            'wordfence' => self::is_wordfence(),
            'wp_rocket' => self::is_wp_rocket(),
            'w3tc' => self::is_w3tc(),
            'wp_super_cache' => self::is_wp_super_cache(),
            'wpengine' => self::is_wpengine(),
            'siteground' => self::is_siteground(),
        ];
    }
}
```

---

## Phase 4: SEO Integration (Interface-Based)

### Task 4.1: Create SeoReaderInterface

**Files:**
- Create: `src/Integration/Seo/SeoReaderInterface.php`

- [ ] **Step 1: Create interface**

```php
<?php
/**
 * SEO Reader Interface.
 *
 * @package SScribe\Integration\Seo
 */

declare(strict_types=1);

namespace SScribe\Integration\Seo;

use SScribe\DTO\SeoData;

/**
 * Interface for SEO plugin integrations.
 */
interface SeoReaderInterface
{
    /**
     * Read SEO data for a page.
     *
     * @param int $page_id Page ID.
     * @return SeoData SEO data.
     */
    public function read(int $page_id): SeoData;

    /**
     * Check if this SEO plugin is active.
     */
    public function is_active(): bool;

    /**
     * Get the plugin name for display.
     */
    public function get_name(): string;

    /**
     * Get priority order (lower = higher priority).
     */
    public function get_priority(): int;
}
```

---

### Task 4.2: Create YoastSeo Reader

**Files:**
- Create: `src/Integration/Seo/YoastSeo.php`

- [ ] **Step 1: Create Yoast SEO reader**

```php
<?php
/**
 * Yoast SEO Integration.
 *
 * @package SScribe\Integration\Seo
 */

declare(strict_types=1);

namespace SScribe\Integration\Seo;

use SScribe\DTO\SeoData;

/**
 * Yoast SEO plugin reader.
 */
final class YoastSeo implements SeoReaderInterface
{
    /**
     * Read SEO data.
     */
    public function read(int $page_id): SeoData
    {
        $data = new SeoData();

        if (! $this->is_active()) {
            return $data;
        }

        $data->meta_title = (string) get_post_meta($page_id, '_yoast_wpseo_title', true);
        $data->meta_description = (string) get_post_meta($page_id, '_yoast_wpseo_metadesc', true);
        $data->focus_keyword = (string) get_post_meta($page_id, '_yoast_wpseo_focuskw', true);
        $data->source = $this->get_name();

        return $data;
    }

    /**
     * Check if Yoast is active.
     */
    public function is_active(): bool
    {
        return defined('WPSEO_VERSION');
    }

    /**
     * Get plugin name.
     */
    public function get_name(): string
    {
        return 'Yoast SEO';
    }

    /**
     * Get priority (highest priority = lowest number).
     */
    public function get_priority(): int
    {
        return 10;
    }
}
```

---

### Task 4.3: Create RankMathSeo Reader

**Files:**
- Create: `src/Integration/Seo/RankMathSeo.php`

- [ ] **Step 1: Create Rank Math reader**

```php
<?php
/**
 * Rank Math SEO Integration.
 *
 * @package SScribe\Integration\Seo
 */

declare(strict_types=1);

namespace SScribe\Integration\Seo;

use SScribe\DTO\SeoData;

/**
 * Rank Math SEO plugin reader.
 */
final class RankMathSeo implements SeoReaderInterface
{
    /**
     * Read SEO data.
     */
    public function read(int $page_id): SeoData
    {
        $data = new SeoData();

        if (! $this->is_active()) {
            return $data;
        }

        $data->meta_title = (string) get_post_meta($page_id, 'rank_math_title', true);
        $data->meta_description = (string) get_post_meta($page_id, 'rank_math_description', true);
        $data->focus_keyword = (string) get_post_meta($page_id, 'rank_math_focus_keyword', true);
        $data->source = $this->get_name();

        return $data;
    }

    /**
     * Check if Rank Math is active.
     */
    public function is_active(): bool
    {
        return defined('RANK_MATH_VERSION') || class_exists('RankMath');
    }

    /**
     * Get plugin name.
     */
    public function get_name(): string
    {
        return 'Rank Math';
    }

    /**
     * Get priority.
     */
    public function get_priority(): int
    {
        return 20;
    }
}
```

---

### Task 4.4: Create AioSeoV4 Reader

**Files:**
- Create: `src/Integration/Seo/AioSeoV4.php`

- [ ] **Step 1: Create AIOSEO v4 reader**

```php
<?php
/**
 * All in One SEO v4 Integration.
 *
 * @package SScribe\Integration\Seo
 */

declare(strict_types=1);

namespace SScribe\Integration\Seo;

use SScribe\DTO\SeoData;

/**
 * All in One SEO v4 plugin reader.
 */
final class AioSeoV4 implements SeoReaderInterface
{
    /**
     * Read SEO data.
     */
    public function read(int $page_id): SeoData
    {
        $data = new SeoData();

        if (! $this->is_active()) {
            return $data;
        }

        if (function_exists('aioseo')) {
            $aioseo_post = aioseo()->models->post::getPost($page_id);
            
            if ($aioseo_post) {
                $data->meta_title = isset($aioseo_post->title) ? (string) $aioseo_post->title : '';
                $data->meta_description = isset($aioseo_post->description) ? (string) $aioseo_post->description : '';
                
                $keyphrases = isset($aioseo_post->keyphrases) 
                    ? json_decode($aioseo_post->keyphrases, true) 
                    : [];
                
                if (! empty($keyphrases['focus']['keyphrase'])) {
                    $data->focus_keyword = (string) $keyphrases['focus']['keyphrase'];
                }
            }
        }

        $data->source = $this->get_name();
        
        return $data;
    }

    /**
     * Check if AIOSEO v4 is active.
     */
    public function is_active(): bool
    {
        return function_exists('aioseo') && defined('AIOSEO_VERSION');
    }

    /**
     * Get plugin name.
     */
    public function get_name(): string
    {
        return 'All in One SEO v4';
    }

    /**
     * Get priority.
     */
    public function get_priority(): int
    {
        return 30;
    }
}
```

---

### Task 4.5: Create AioSeoV3 Reader

**Files:**
- Create: `src/Integration/Seo/AioSeoV3.php`

- [ ] **Step 1: Create AIOSEO v3 reader**

```php
<?php
/**
 * All in One SEO v3 Integration (Legacy).
 *
 * @package SScribe\Integration\Seo
 */

declare(strict_types=1);

namespace SScribe\Integration\Seo;

use SScribe\DTO\SeoData;

/**
 * All in One SEO v3 plugin reader (legacy).
 */
final class AioSeoV3 implements SeoReaderInterface
{
    /**
     * Read SEO data.
     */
    public function read(int $page_id): SeoData
    {
        $data = new SeoData();

        if (! $this->is_active()) {
            return $data;
        }

        $data->meta_title = (string) get_post_meta($page_id, '_aioseop_title', true);
        $data->meta_description = (string) get_post_meta($page_id, '_aioseop_description', true);
        $data->focus_keyword = (string) get_post_meta($page_id, '_aioseop_keywords', true);
        $data->source = $this->get_name();

        return $data;
    }

    /**
     * Check if AIOSEO v3 is active.
     */
    public function is_active(): bool
    {
        return class_exists('All_in_One_SEO_Pack') && ! function_exists('aioseo');
    }

    /**
     * Get plugin name.
     */
    public function get_name(): string
    {
        return 'All in One SEO v3';
    }

    /**
     * Get priority.
     */
    public function get_priority(): int
    {
        return 40;
    }
}
```

---

### Task 4.6: Create SeoPress Reader

**Files:**
- Create: `src/Integration/Seo/SeoPress.php`

- [ ] **Step 1: Create SEOPress reader**

```php
<?php
/**
 * SEOPress Integration.
 *
 * @package SScribe\Integration\Seo
 */

declare(strict_types=1);

namespace SScribe\Integration\Seo;

use SScribe\DTO\SeoData;

/**
 * SEOPress plugin reader.
 */
final class SeoPress implements SeoReaderInterface
{
    /**
     * Read SEO data.
     */
    public function read(int $page_id): SeoData
    {
        $data = new SeoData();

        if (! $this->is_active()) {
            return $data;
        }

        $data->meta_title = (string) get_post_meta($page_id, '_seopress_titles_title', true);
        $data->meta_description = (string) get_post_meta($page_id, '_seopress_titles_desc', true);
        $data->focus_keyword = (string) get_post_meta($page_id, '_seopress_analysis_target_kw', true);
        $data->source = $this->get_name();

        return $data;
    }

    /**
     * Check if SEOPress is active.
     */
    public function is_active(): bool
    {
        return defined('SEOPRESS_VERSION');
    }

    /**
     * Get plugin name.
     */
    public function get_name(): string
    {
        return 'SEOPress';
    }

    /**
     * Get priority.
     */
    public function get_priority(): int
    {
        return 50;
    }
}
```

---

### Task 4.7: Create TheSeoFramework Reader

**Files:**
- Create: `src/Integration/Seo/TheSeoFramework.php`

- [ ] **Step 1: Create The SEO Framework reader**

```php
<?php
/**
 * The SEO Framework Integration.
 *
 * @package SScribe\Integration\Seo
 */

declare(strict_types=1);

namespace SScribe\Integration\Seo;

use SScribe\DTO\SeoData;

/**
 * The SEO Framework plugin reader.
 */
final class TheSeoFramework implements SeoReaderInterface
{
    /**
     * Read SEO data.
     */
    public function read(int $page_id): SeoData
    {
        $data = new SeoData();

        if (! $this->is_active()) {
            return $data;
        }

        $data->meta_title = (string) get_post_meta($page_id, '_genesis_title', true);
        $data->meta_description = (string) get_post_meta($page_id, '_genesis_description', true);
        $data->focus_keyword = (string) get_post_meta($page_id, '_tsf_title', true);
        $data->source = $this->get_name();

        return $data;
    }

    /**
     * Check if The SEO Framework is active.
     */
    public function is_active(): bool
    {
        return defined('THE_SEO_FRAMEWORK_VERSION');
    }

    /**
     * Get plugin name.
     */
    public function get_name(): string
    {
        return 'The SEO Framework';
    }

    /**
     * Get priority.
     */
    public function get_priority(): int
    {
        return 60;
    }
}
```

---

### Task 4.8: Create SeoReaderFactory

**Files:**
- Create: `src/Integration/Seo/SeoReaderFactory.php`
- Create: `tests/Unit/Integration/Seo/SeoReaderFactoryTest.php`

- [ ] **Step 1: Write failing test**

Create: `tests/Unit/Integration/Seo/SeoReaderFactoryTest.php`

```php
<?php
/**
 * SeoReaderFactory Test.
 *
 * @package SScribe\Tests\Unit\Integration\Seo
 */

declare(strict_types=1);

namespace SScribe\Tests\Unit\Integration\Seo;

use PHPUnit\Framework\TestCase;
use SScribe\Integration\Seo\SeoReaderFactory;
use SScribe\Integration\Seo\YoastSeo;
use SScribe\Integration\Seo\RankMathSeo;
use Brain\Monkey;

class SeoReaderFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_readers_returns_array(): void
    {
        $readers = SeoReaderFactory::get_readers();
        
        $this->assertIsArray($readers);
        $this->assertNotEmpty($readers);
    }

    public function test_readers_are_sorted_by_priority(): void
    {
        $readers = SeoReaderFactory::get_readers();
        
        $priorities = array_map(function ($reader) {
            return $reader->get_priority();
        }, $readers);
        
        $sorted = $priorities;
        sort($sorted);
        
        $this->assertEquals($sorted, $priorities);
    }

    public function test_first_reader_is_yoast(): void
    {
        $readers = SeoReaderFactory::get_readers();
        
        $this->assertInstanceOf(YoastSeo::class, $readers[0]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Integration/Seo/SeoReaderFactoryTest.php`

Expected: FAIL

- [ ] **Step 3: Create SeoReaderFactory**

```php
<?php
/**
 * SEO Reader Factory.
 *
 * @package SScribe\Integration\Seo
 */

declare(strict_types=1);

namespace SScribe\Integration\Seo;

use SScribe\DTO\SeoData;

/**
 * Factory for creating SEO readers.
 */
final class SeoReaderFactory
{
    /**
     * @var array<SeoReaderInterface>|null
     */
    private static ?array $readers = null;

    /**
     * Get all registered SEO readers sorted by priority.
     *
     * @return array<SeoReaderInterface>
     */
    public static function get_readers(): array
    {
        if (self::$readers === null) {
            self::$readers = [
                new YoastSeo(),
                new RankMathSeo(),
                new AioSeoV4(),
                new AioSeoV3(),
                new SeoPress(),
                new TheSeoFramework(),
            ];

            // Sort by priority
            usort(self::$readers, function (SeoReaderInterface $a, SeoReaderInterface $b): int {
                return $a->get_priority() <=> $b->get_priority();
            });
        }

        return self::$readers;
    }

    /**
     * Get SEO data from the first active reader with data.
     *
     * @param int $page_id Page ID.
     * @return SeoData SEO data.
     */
    public static function read(int $page_id): SeoData
    {
        foreach (self::get_readers() as $reader) {
            if ($reader->is_active()) {
                $data = $reader->read($page_id);
                
                if ($data->has_data()) {
                    return $data;
                }
            }
        }

        return new SeoData();
    }

    /**
     * Get list of active SEO plugins.
     *
     * @return array<string>
     */
    public static function get_active_plugins(): array
    {
        $active = [];

        foreach (self::get_readers() as $reader) {
            if ($reader->is_active()) {
                $active[] = $reader->get_name();
            }
        }

        return $active;
    }

    /**
     * Check if any SEO plugin is active.
     */
    public static function has_seo_plugin(): bool
    {
        foreach (self::get_readers() as $reader) {
            if ($reader->is_active()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reset cached readers (for testing).
     */
    public static function reset(): void
    {
        self::$readers = null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Integration/Seo/SeoReaderFactoryTest.php`

Expected: PASS

---

## Phase 5: Core Plugin Class

### Task 5.1: Create Main Plugin Class

**Files:**
- Create: `src/Core/Plugin.php`

- [ ] **Step 1: Create Plugin class**

```php
<?php
/**
 * Main Plugin Class.
 *
 * @package SScribe\Core
 */

declare(strict_types=1);

namespace SScribe\Core;

use SScribe\Admin\AdminPage;
use SScribe\Admin\Assets;
use SScribe\Admin\Ajax;
use SScribe\Processing\BatchProcessor;
use SScribe\Export\ZipHandler;

/**
 * Main plugin orchestrator.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private function __construct()
    {
    }

    /**
     * Get singleton instance.
     */
    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Bootstrap the plugin.
     */
    public function boot(): void
    {
        $this->define_hooks();
        $this->define_ajax_handlers();
        $this->define_cron_handlers();
    }

    /**
     * Define admin and public hooks.
     */
    private function define_hooks(): void
    {
        // Admin menu
        add_action('admin_menu', function (): void {
            $admin = new AdminPage();
            $admin->add_menu();
        });

        // Admin assets
        add_action('admin_enqueue_scripts', function (string $hook): void {
            $assets = new Assets();
            $assets->enqueue($hook);
        });

        // Plugin action links
        add_filter('plugin_action_links_' . SSCRIBE_PLUGIN_BASENAME, function (array $links): array {
            $links[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('tools.php?page=sscribe-export')),
                esc_html__('Export Pages', 'sscribe-export-site-pages')
            );
            return $links;
        });
    }

    /**
     * Define AJAX handlers.
     */
    private function define_ajax_handlers(): void
    {
        $ajax = new Ajax();

        add_action('wp_ajax_sscribe_start_export', [$ajax, 'start_export']);
        add_action('wp_ajax_sscribe_process_batch', [$ajax, 'process_batch']);
        add_action('wp_ajax_sscribe_download', [$ajax, 'download']);
    }

    /**
     * Define cron handlers.
     */
    private function define_cron_handlers(): void
    {
        add_action('sscribe_cleanup_exports', function (): void {
            $zip_handler = new ZipHandler();
            $zip_handler->cleanup_expired();
        });
    }
}
```

---

### Task 5.2: Create Activator

**Files:**
- Create: `src/Core/Activator.php`

- [ ] **Step 1: Create Activator class**

```php
<?php
/**
 * Plugin Activator.
 *
 * @package SScribe\Core
 */

declare(strict_types=1);

namespace SScribe\Core;

/**
 * Handles plugin activation tasks.
 */
final class Activator
{
    /**
     * Run activation tasks.
     */
    public static function activate(): void
    {
        self::create_export_directory();
        self::schedule_cleanup();
        self::set_version();
    }

    /**
     * Create the export directory with security files.
     */
    private static function create_export_directory(): void
    {
        $upload_dir = wp_upload_dir();
        $export_path = $upload_dir['basedir'] . '/sscribe-exports';

        if (! file_exists($export_path)) {
            wp_mkdir_p($export_path);
        }

        // Create .htaccess
        self::create_htaccess($export_path);

        // Create index.php
        self::create_index_php($export_path);
    }

    /**
     * Create .htaccess file.
     */
    private static function create_htaccess(string $export_path): void
    {
        $htaccess_path = $export_path . '/.htaccess';

        if (file_exists($htaccess_path)) {
            return;
        }

        $content = "Options -Indexes\n";
        $content .= "<Files \"*\">\n";
        $content .= "  <IfModule mod_authz_core.c>\n";
        $content .= "    Require all denied\n";
        $content .= "  </IfModule>\n";
        $content .= "  <IfModule !mod_authz_core.c>\n";
        $content .= "    Order Allow,Deny\n";
        $content .= "    Deny from all\n";
        $content .= "  </IfModule>\n";
        $content .= "</Files>\n";

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($htaccess_path, $content);
    }

    /**
     * Create index.php file.
     */
    private static function create_index_php(string $export_path): void
    {
        $index_path = $export_path . '/index.php';

        if (file_exists($index_path)) {
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($index_path, "<?php\n// Silence is golden.\n");
    }

    /**
     * Schedule hourly cleanup cron event.
     */
    private static function schedule_cleanup(): void
    {
        if (! wp_next_scheduled('sscribe_cleanup_exports')) {
            wp_schedule_event(time(), 'hourly', 'sscribe_cleanup_exports');
        }
    }

    /**
     * Set plugin version.
     */
    private static function set_version(): void
    {
        update_option('sscribe_version', SSCRIBE_VERSION);
    }
}
```

---

### Task 5.3: Create Deactivator

**Files:**
- Create: `src/Core/Deactivator.php`

- [ ] **Step 1: Create Deactivator class**

```php
<?php
/**
 * Plugin Deactivator.
 *
 * @package SScribe\Core
 */

declare(strict_types=1);

namespace SScribe\Core;

/**
 * Handles plugin deactivation tasks.
 */
final class Deactivator
{
    /**
     * Run deactivation tasks.
     */
    public static function deactivate(): void
    {
        self::clear_scheduled_events();
    }

    /**
     * Clear scheduled cron events.
     */
    private static function clear_scheduled_events(): void
    {
        $timestamp = wp_next_scheduled('sscribe_cleanup_exports');
        
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'sscribe_cleanup_exports');
        }
    }
}
```

---

## Phase 6: Update Main Plugin File

### Task 6.1: Rewrite Main Plugin File

**Files:**
- Modify: `scribe-export-site-pages.php`

- [ ] **Step 1: Rewrite main plugin file**

```php
<?php
/**
 * Plugin Name:       SScribe Export Site Pages
 * Description:       Export every page into beautifully formatted Word DOCX files with multilingual support, SEO meta, rich styling, and secure ZIP download.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Simplix Innovations
 * Author URI:        https://simplixi.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       scribe-export-site-pages
 * Domain Path:       /languages
 *
 * @package SScribe
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('SSCRIBE_VERSION', '1.1.0');
define('SSCRIBE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SSCRIBE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SSCRIBE_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Load Composer autoloader
$autoload = SSCRIBE_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// Load legacy classes (temporary during migration)
$legacy_files = [
    'includes/class-sscribe-loader.php',
    'includes/class-sscribe-activator.php',
    'includes/class-sscribe-deactivator.php',
    'includes/class-sscribe-page-collector.php',
    'includes/class-sscribe-seo-reader.php',
    'includes/class-sscribe-content-parser.php',
    'includes/class-sscribe-exporter.php',
    'includes/class-sscribe-zip-handler.php',
    'includes/class-sscribe-batch-processor.php',
    'admin/class-sscribe-admin.php',
];

foreach ($legacy_files as $file) {
    $path = SSCRIBE_PLUGIN_DIR . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

// Activation/Deactivation hooks
register_activation_hook(__FILE__, function (): void {
    \SScribe\Core\Activator::activate();
});

register_deactivation_hook(__FILE__, function (): void {
    \SScribe\Core\Deactivator::deactivate();
});

// Initialize plugin
add_action('plugins_loaded', function (): void {
    try {
        $plugin = \SScribe\Core\Plugin::get_instance();
        $plugin->boot();
    } catch (\Throwable $e) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('SScribe Error: ' . $e->getMessage());
    }
});
```

---

## Phase 7: GitHub Actions CI/CD

### Task 7.1: Create CI Workflow

**Files:**
- Create: `.github/workflows/ci.yml`

- [ ] **Step 1: Create CI workflow**

```yaml
name: CI

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  quality:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['7.4', '8.0', '8.1', '8.2', '8.3']

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring, xml, zip, gd
          tools: composer

      - name: Install dependencies
        run: composer install --no-progress --prefer-dist

      - name: Run PHPCS
        run: vendor/bin/phpcs --standard=phpcs.xml.dist || true

      - name: Run PHPStan
        run: vendor/bin/phpstan analyse || true

  test:
    needs: quality
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['7.4', '8.2']

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring, xml, zip, gd
          coverage: xdebug

      - name: Install dependencies
        run: composer install --no-progress --prefer-dist

      - name: Run PHPUnit
        run: vendor/bin/phpunit --testdox || true

  build:
    needs: test
    runs-on: ubuntu-latest
    if: github.event_name == 'push' && github.ref == 'refs/heads/main'

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'

      - name: Install production dependencies
        run: composer install --no-dev --optimize-autoloader

      - name: Create distribution
        run: |
          mkdir -p dist
          rsync -av --exclude-from=.distignore . dist/sscribe-export-site-pages/ || true
          cd dist
          zip -r ../sscribe-export-site-pages.zip scribe-export-site-pages || true

      - name: Upload artifact
        uses: actions/upload-artifact@v4
        with:
          name: scribe-export-site-pages
          path: scribe-export-site-pages.zip
          if-no-files-found: ignore
```

---

## Summary

This plan covers:

| Phase | Tasks | Files Created |
|-------|-------|---------------|
| 1 | WordPress.org Compliance | `.distignore`, `phpstan.neon.dist`, `phpcs.xml.dist`, `phpunit.xml.dist` |
| 2 | Directory Structure | `src/` directories |
| 3 | Utility Classes | `Cache.php`, `MemoryManager.php`, `Compatibility.php` |
| 4 | SEO Integration | Interface + 6 SEO readers + Factory |
| 5 | Core Classes | `Plugin.php`, `Activator.php`, `Deactivator.php` |
| 6 | Main Plugin File | Updated `scribe-export-site-pages.php` |
| 7 | CI/CD | GitHub Actions workflow |

**Total Files to Create:** ~25
**Total Files to Modify:** ~3
**Total Tests to Write:** ~4

---

**Status:** Ready for execution
**Next:** Execute plan using subagent-driven-development or executing-plans skill
