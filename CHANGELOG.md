# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Changed

## [3.30.7] - 2026-04-11

### Fixed
- DOCX export now produces separate per-page DOCX files for all page counts (removed broken streaming mode).
- Export Log viewer now loads correctly (fixed filename matching for non-sscribe-export- prefixed ZIPs).

### Changed

## [3.30.6] - 2026-04-10

### Fixed
- PHPCS: Array alignment in batch-processor, XSS escaping in exporter factory exception context.

### Changed
- Migrated remaining runtime Dompdf and PHPWord references to the `SScribeVendor\\` namespace.
- Updated CI and release workflows to enable `fileinfo` and keep Strauss available during prefixed vendor generation.
- Aligned the legacy release build script with the prefixed-vendor packaging flow.
- Improved admin discoverability with a top-level dashboard menu and first-activation redirect.
- Modernized GitHub workflows for Node 24-safe actions, rerun-safe release uploads, and main-head tag enforcement.
- Added regression coverage for successful post-activation admin redirects.
- Surfaced production bootstrap failures to administrators and registered admin entry hooks eagerly to prevent silent menu loss.

### Added
- Added repository hygiene files: `.editorconfig`, `CONTRIBUTING.md`, `package.json`, and `.wp-env.json`.

## [3.30.5] - 2026-04-10

### Existing baseline
- Current plugin release version as declared in the main plugin file and readme metadata.
