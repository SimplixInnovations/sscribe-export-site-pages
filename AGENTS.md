# AGENTS.md - SScribe Export Site Pages

> **For AI Agents:** This document provides complete context about the SScribe Export Site Pages WordPress plugin. Read this file first to understand the project architecture, coding standards, and how to contribute.

---

## Project Overview

**SScribe Export Site Pages** is a WordPress plugin that exports all published pages into professionally formatted Microsoft Word DOCX files, bundled into a secure ZIP package.

### Key Features
- Export pages to DOCX format with PHPWord library
- WPML multilingual support with RTL (Arabic) support
- SEO metadata extraction (Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework)
- Batch processing to avoid PHP timeouts
- Secure ZIP packaging with auto-deletion after 1 hour

### Technical Stack
| Component | Technology |
|-----------|------------|
| Language | PHP 7.4+ |
| Framework | WordPress 5.8+ |
| PDF/DOCX | PHPWord 1.3+ (phpoffice/phpword) |
| Dependency Management | Composer |
| Version Control | Git (GitFlow) |
| Repository | GitHub (Private) |

---

## Repository Information

| Item | Value |
|------|-------|
| Repository | `https://github.com/SimplixInnovations/sscribe-export-site-pages` |
| Default Branch | `main` |
| Development Branch | `develop` |
| License | GPL v2 or later |
| WordPress.org Slug | `sscribe-export-site-pages` |

---

## File Structure

```
sscribe-export-site-pages/
├── sscribe-export-site-pages.php    # Main plugin file (entry point)
├── readme.txt                        # WordPress.org readme
├── uninstall.php                     # Cleanup on plugin uninstall
├── LICENSE                          # GPL v2 license
├── composer.json                    # PHP dependencies
├── composer.lock                    # Locked dependencies
├── .gitignore                       # Git ignore rules
│
├── includes/                        # Core plugin classes
│   ├── class-sscribe.php           # Main orchestrator class
│   ├── class-sscribe-loader.php    # Hook registration system
│   ├── class-sscribe-activator.php # Activation tasks
│   ├── class-sscribe-deactivator.php # Deactivation tasks
│   ├── class-sscribe-i18n.php      # Internationalization (TO BE REMOVED)
│   ├── class-sscribe-page-collector.php # Gather page data
│   ├── class-sscribe-seo-reader.php # Read SEO metadata
│   ├── class-sscribe-content-parser.php # Parse HTML to structured data
│   ├── class-sscribe-exporter.php  # Generate DOCX files
│   ├── class-sscribe-zip-handler.php # ZIP creation and cleanup
│   └── class-sscribe-batch-processor.php # AJAX batch processing
│
├── admin/                           # Admin interface
│   ├── class-sscribe-admin.php     # Admin page registration
│   ├── css/sscribe-admin.css       # Admin styles
│   ├── js/sscribe-admin.js         # Admin JavaScript (AJAX)
│   └── partials/
│       └── sscribe-admin-display.php # Admin page template
│
├── languages/                       # Translation files
│   └── index.php                   # Empty index for security
│
└── vendor/                          # Composer dependencies (git-ignored)
    ├── autoload.php                # Composer autoloader
    ├── composer/                   # Composer internals
    └── phpoffice/                  # PHPWord library
```

---

## Architecture

### Class Responsibilities

| Class | Responsibility |
|-------|----------------|
| `SScribe` | Main orchestrator - registers all hooks and bootstraps plugin |
| `SScribe_Loader` | Stores and registers WordPress actions/filters |
| `SScribe_Activator` | Creates export directory, schedules cron, sets version |
| `SScribe_Deactivator` | Clears scheduled cron events |
| `SScribe_Page_Collector` | Queries pages, gathers metadata, handles WPML |
| `SScribe_SEO_Reader` | Reads SEO metadata from 6 SEO plugins |
| `SScribe_Content_Parser` | Converts HTML to structured elements for DOCX |
| `SScribe_Exporter` | Generates DOCX files using PHPWord |
| `SScribe_Zip_Handler` | Creates ZIP archives, handles cleanup |
| `SScribe_Batch_Processor` | AJAX handlers for batch export |
| `SScribe_Admin` | Admin menu, assets, page rendering |

### Data Flow

```
User Click → AJAX Request → BatchProcessor
    ↓
PageCollector → Get Page IDs (WPML filtered)
    ↓
For each batch:
    PageCollector::get_page_data()
        → SEO_Reader::get_seo_data()
        → Build PageData array
    ↓
    Exporter::generate_docx()
        → ContentParser::parse()
        → PHPWord document creation
    ↓
ZipHandler::create_zip()
    ↓
Return download URL
```

---

## Coding Standards

### WordPress Coding Standards (WPCS)
This plugin follows WordPress Coding Standards. Key rules:

- **Yoda conditions**: `if ( true === $value )` not `if ( $value === true )`
- **Braces**: Always use braces, even for single-line statements
- **Naming**: snake_case for functions/variables, PascalCase for classes
- **Prefixing**: All functions/classes prefixed with `SScribe` or `sscribe_`
- **Sanitization**: Always sanitize input (`sanitize_text_field()`, etc.)
- **Escaping**: Always escape output (`esc_html()`, `esc_attr()`, `esc_url()`)
- **Nonces**: Verify nonces for all forms and AJAX requests

### PHP Version
- Minimum: PHP 7.4
- Uses typed properties where applicable
- No PHP 8.0+ features (named args, match, enums)

### Documentation
- All classes and methods must have PHPDoc blocks
- Use `@package SScribe` for all files
- Document parameters and return types

---

## Development Setup

### Prerequisites
| Tool | Version | Install Check |
|------|---------|---------------|
| PHP | 7.4+ | `php -v` |
| Composer | 2.x | `composer -V` |
| Node.js | 18+ | `node -v` |
| npm | 9+ | `npm -v` |
| Git | 2.x | `git --version` |
| WP-CLI | 2.x | `wp --version` |

### Installation

```bash
# Clone repository
git clone https://github.com/SimplixInnovations/sscribe-export-site-pages.git
cd sscribe-export-site-pages

# Switch to develop branch
git checkout develop

# Install dependencies
composer install

# Verify setup
php -l sscribe-export-site-pages.php
```

### Local Development with LocalWP

1. Create a new WordPress site in LocalWP
2. Navigate to `app/public/wp-content/plugins/`
3. Symlink or copy the plugin folder
4. Activate in WordPress Admin > Plugins
5. Access at Tools > SScribe Export

---

## Testing

### Test Structure (To Be Implemented)

```
tests/
├── Unit/                    # Unit tests (no WP dependency)
│   ├── DTO/
│   ├── Utils/
│   └── Integration/
├── Integration/             # Integration tests (WP loaded)
│   └── Export/
├── fixtures/                # Test data
│   └── sample-page.html
└── bootstrap.php            # Test bootstrap
```

### Running Tests (After Setup)

```bash
# Run all tests
composer test

# Run with coverage
composer test:coverage

# Run specific test
vendor/bin/phpunit tests/Unit/Utils/CacheTest.php
```

---

## Deployment

### WordPress.org SVN Deployment

```bash
# Build production version
composer install --no-dev --optimize-autoloader

# Create distribution archive
wp dist-archive . --format=zip

# Or use SVN (after approval)
svn co https://plugins.svn.wordpress.org/sscribe-export-site-pages
cd scribe-export-site-pages
cp -r ../dist/* trunk/
svn ci -m "Release 1.1.0"
```

### Version Bump Checklist

1. Update `SSCRIBE_VERSION` in `sscribe-export-site-pages.php`
2. Update `Version:` in plugin header
3. Update `Stable tag:` in `readme.txt`
4. Add changelog entry in `readme.txt`
5. Tag release: `git tag v1.1.0`
6. Push tags: `git push origin v1.1.0`

---

## WordPress.org Review Issues (FIX BEFORE RESUBMISSION)

### Issue 1: Not Permitted Files
**Status:** NEEDS FIX
**Solution:** Create `.distignore` file (WILL BE CREATED)

### Issue 2: PCLZip Library Conflict
**Status:** NEEDS FIX
**Solution:** Add to `.distignore` (WILL BE CREATED)

### Issue 3: load_plugin_textdomain()
**Status:** NEEDS FIX
**File:** `includes/class-sscribe-i18n.php`
**Solution:** Delete the file and remove require from main plugin file
**Reason:** WordPress.org auto-loads translations since 4.6

### Issue 4: Unescaped Output
**Status:** NEEDS FIX
**File:** `admin/partials/sscribe-admin-display.php:325`
**Solution:** Use `wp_kses()` for SVG output
```php
// Before
echo $feature['icon'];

// After
echo wp_kses($feature['icon'], $allowed_svg_tags);
```

---

## Roadmap

### Version 1.1.0 (Current Target)
- [ ] Fix WordPress.org review issues
- [ ] Add .distignore file
- [ ] Add PSR-4 autoloading
- [ ] Add unit tests
- [ ] Add PHPStan static analysis
- [ ] Add GitHub Actions CI/CD
- [ ] Remove class-sscribe-i18n.php

### Version 2.0.0 (Future)
- [ ] PDF export format
- [ ] Custom post types support
- [ ] WooCommerce products export
- [ ] Custom branding/templates
- [ ] Polylang, TranslatePress, Weglot support
- [ ] Combined "full site" document

---

## Git Workflow (GitFlow)

```
main        → Production releases only
develop     → Integration branch (default for development)
feature/*   → New features
fix/*       → Bug fixes
release/*   → Release preparation
hotfix/*    → Emergency production fixes
```

### Creating a Feature Branch

```bash
git checkout develop
git checkout -b feature/add-pdf-export
# ... make changes ...
git add -A
git commit -m "feat: add PDF export capability"
git push origin feature/add-pdf-export
# Create PR to develop
```

### Commit Message Convention

```
feat:     New feature
fix:      Bug fix
docs:     Documentation changes
style:    Formatting (no code change)
refactor: Code refactoring
test:     Adding tests
chore:    Maintenance tasks
```

---

## Important Notes for Agents

### Before Making Changes

1. **Always read the relevant file first** - Use the Read tool
2. **Check existing patterns** - Follow the code style in surrounding code
3. **Test after changes** - Verify the plugin still works
4. **Run lint checks** - Use `php -l` for syntax checking

### Security Considerations

- Always verify nonces in AJAX handlers
- Always check user capabilities (`current_user_can('manage_options')`)
- Sanitize all input with WordPress functions
- Escape all output with WordPress functions
- Never trust `$_POST` or `$_GET` directly

### Performance Considerations

- The plugin uses batch processing (3 pages per batch by default)
- ZIP files auto-delete after 1 hour
- No front-end impact - runs only in admin

### Common Tasks

| Task | File(s) to Modify |
|------|-------------------|
| Add new SEO plugin support | `includes/class-sscribe-seo-reader.php` |
| Modify DOCX output | `includes/class-sscribe-exporter.php` |
| Add new AJAX endpoint | `includes/class-sscribe-batch-processor.php` |
| Change admin UI | `admin/partials/sscribe-admin-display.php` |
| Modify document template | `includes/class-sscribe-exporter.php` |
| Add new content element | `includes/class-sscribe-content-parser.php` |

---

## Contact

- **Author:** Simplix Innovations
- **Email:** info@simplixi.com
- **Website:** https://simplixi.com
- **GitHub:** https://github.com/SimplixInnovations/sscribe-export-site-pages

---

*Last Updated: 2026-03-21*
*Plugin Version: 1.0.0*
