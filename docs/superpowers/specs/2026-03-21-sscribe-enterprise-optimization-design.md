# SScribe Export Site Pages - Enterprise Optimization Design

**Version:** 1.1.0  
**Date:** 2026-03-21  
**Status:** Draft for Approval

---

## Executive Summary

Comprehensive optimization of SScribe Export Site Pages plugin to achieve enterprise-grade code quality, performance, and user experience for WordPress.org approval and #1 plugin positioning.

### Goals

1. **Code Quality**: PHP 7.4+ types, PSR-4, 80%+ test coverage, PHPStan level 5
2. **Performance**: Caching, memory management, async processing, third-party compatibility
3. **User Experience**: WCAG 2.1 AA accessibility, award-winning UI, robust error handling
4. **WordPress.org Compliance**: Fix all reviewer issues and follow the best practices for WordPress plugins development.

---

## Phase 1: WordPress.org Compliance Fixes

### Issues to Fix

| Issue | Solution |
|-------|----------|
| Not permitted files | Create `.distignore` to exclude vendor extras |
| PCLZip conflict | Exclude from distribution |
| load_plugin_textdomain | Remove `class-sscribe-i18n.php` entirely |
| Unescaped SVG output | Use `wp_kses()` with allowed SVG tags |

### .distignore

```
# Development
.github/
.git/
tests/
*.md
!readme.txt

# Vendor extras
vendor/*/.github/
vendor/*/COPYING*
vendor/*/.github_changelog_generator
vendor/*/tests/
vendor/*/docs/

# PCLZip conflict
vendor/phpoffice/phpword/src/PhpWord/Shared/PCLZip/
```

---

## Phase 2: Architecture

### Directory Structure

```
sscribe-export-site-pages/
├── scribe-export-site-pages.php
├── composer.json
├── phpunit.xml.dist
├── phpstan.neon.dist
├── phpcs.xml.dist
├── .distignore
├── .github/workflows/
├── src/
│   ├── Core/
│   ├── DTO/
│   ├── Export/
│   ├── Processing/
│   ├── Integration/
│   ├── Admin/
│   └── Utils/
├── tests/
├── assets/
├── views/
└── vendor/
```

### Key Classes

- `Plugin.php` - Main container
- `PageData` - Page DTO
- `SeoData` - SEO DTO
- `Cache` - Unified caching layer
- `MemoryManager` - Memory monitoring
- `Compatibility` - Third-party plugin support
- `SeoReaderInterface` - SEO integration interface

---

## Phase 3: Performance

### Caching

- Object cache (Redis/Memcached) with transient fallback
- Page data cached for 1 hour
- SEO data cached for 1 hour

### Memory Management

- Monitor usage percentage
- Warning at 75%, Critical at 85%
- Automatic garbage collection
- Pause processing when critical

### Compatibility

- Cloudflare bypass headers
- Wordfence compatibility
- WP Rocket, W3TC, WP Super Cache
- `DONOTCACHEPAGE` constants

---

## Phase 4: UI/UX

### Accessibility (WCAG 2.1 AA)

- Keyboard navigation
- Screen reader announcements
- Focus visible indicators
- Reduced motion support
- High contrast support

### CSS Design Tokens

```css
:root {
    --ss-color-primary: #4A8263;
    --ss-focus-ring: 0 0 0 3px rgba(74, 130, 99, 0.4);
}

/* Focus visible */
:focus-visible {
    box-shadow: var(--ss-focus-ring);
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    * { animation-duration: 0.01ms !important; }
}
```

---

## Phase 5: Testing

### Coverage Requirements

| Component | Target |
|-----------|--------|
| DTOs | 100% |
| Cache | 90% |
| MemoryManager | 90% |
| SeoReaders | 85% |
| Exporter | 80% |

---

## Build & Deployment

### GitHub Actions

- **Quality**: PHPCS, PHPStan on PHP 7.4-8.3
- **Test**: PHPUnit with WordPress test suite
- **Build**: Create distribution ZIP
- **Deploy**: WordPress.org SVN on release

### Version Strategy

- Semantic versioning (MAJOR.MINOR.PATCH)
- Git branches: main, develop, feature/*

---

## Implementation Roadmap

| Week | Focus |
|------|-------|
| 1 | Foundation: Directory restructure, PSR-4, WordPress.org fixes, CI/CD |
| 2 | Code Quality: DTOs, interfaces, type hints, PHPStan, unit tests |
| 3 | Performance: Cache, memory, async, compatibility |
| 4 | UI/UX: Accessibility, error handling, integration tests |
| 5 | Release: Documentation, testing, WordPress.org resubmission |

---

## Success Criteria

- [ ] PHPStan level 5: 0 errors
- [ ] PHPCS: 0 warnings
- [ ] Test coverage: 80%+
- [ ] WCAG 2.1 AA compliant
- [ ] WordPress.org approved

---

## File Checklist

### Create
- `.distignore`, `phpstan.neon.dist`, `phpcs.xml.dist`, `phpunit.xml.dist`
- `.github/workflows/ci.yml`, `.github/workflows/deploy.yml`
- `src/Core/*`, `src/DTO/*`, `src/Export/*`, `src/Processing/*`
- `src/Integration/Seo/*`, `src/Integration/Multilingual/*`
- `src/Admin/*`, `src/Utils/*`
- `tests/bootstrap.php`, `tests/Unit/*`, `tests/Integration/*`

### Modify
- `scribe-export-site-pages.php` (bootstrap only)
- `composer.json` (PSR-4 autoload)
- `admin/partials/sscribe-admin-display.php` (escaping)

### Delete
- `includes/class-sscribe-i18n.php`
- `includes/class-sscribe-loader.php`
- All old `includes/class-sscribe-*.php` (after migration)

---

**Status:** Ready for user review  
**Next:** User approval → writing-plans skill for implementation plan
