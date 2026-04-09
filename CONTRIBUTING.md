# Contributing

## Prerequisites
- PHP 8.2+
- Composer
- Optional: Node.js for `npm`-based local WordPress environment commands

## Setup
1. Run `composer install`
2. Run `composer vendor:prefix` to generate `vendor-prefixed/`
3. Run `composer build` to refresh minified assets

## Verification
- `composer test`
- `composer stan`
- `composer cs`

## Local WordPress Environment
- `npm install`
- `npm run wp-env:start`
- `npm run wp-env:stop`

## Release Packaging
- `composer vendor:prefix`
- `composer build`
- `bash scripts/build-release.sh`

## Notes
- Runtime code should reference prefixed vendor classes under `SScribeVendor\\...`
- The raw `vendor/` tree is for local development only and is excluded from release packages
