# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Changed
- Refactored SScribe_Batch_Processor: extracted 7 private methods into 6 single-responsibility classes (SScribe_Export_Rate_Limiter, SScribe_Export_Auditor, SScribe_Export_Resource_Monitor, SScribe_Export_Lock_Manager, SScribe_Export_Error_Handler, SScribe_Export_Query_Controller). Removed 4 orphaned methods and 2 unused constants. Net -722 lines.
- All 340 PHPUnit tests passing, PHPStan Level 6 clean, PHPCS clean, full includes/ directory at PHPStan Level 6 — 0 errors.