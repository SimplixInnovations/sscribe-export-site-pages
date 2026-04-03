#!/bin/bash
#
# Build production artifact for WordPress.org submission.
# Excludes dev dependencies, tests, and unnecessary files.
#
# Usage: ./scripts/build-release.sh [version]
#

set -e

PLUGIN_SLUG="sscribe-export-site-pages"
VERSION=${1:-$(grep -oP 'Version:\s*\K[0-9.]+' sscribe-export-site-pages.php)}
BUILD_DIR="build/${PLUGIN_SLUG}"

echo "🔨 Building ${PLUGIN_SLUG} v${VERSION}..."

# Clean previous builds
rm -rf build/
mkdir -p "${BUILD_DIR}"

# Copy plugin files (excluding dev files)
rsync -a --exclude='.git/' \
         --exclude='.github/' \
         --exclude='build/' \
         --exclude='node_modules/' \
         --exclude='vendor/' \
         --exclude='tests/' \
         --exclude='.phpunit.cache/' \
         --exclude='.sisyphus/' \
         --exclude='.env' \
         --exclude='.env.*' \
         --exclude='phpcs.xml' \
         --exclude='phpstan.neon' \
         --exclude='phpunit.xml' \
         --exclude='composer.json' \
         --exclude='composer.lock' \
         --exclude='.gitignore' \
         --exclude='.distignore' \
         --exclude='opencode.json' \
         --exclude='*.log' \
         --exclude='screenshots/' \
         ./ "${BUILD_DIR}/"

# Install production dependencies only
echo "📦 Installing production dependencies..."
cd "${BUILD_DIR}"
composer install --no-dev --optimize-autoloader --no-interaction
cd - > /dev/null

# Minify CSS
echo "🎨 Minifying CSS..."
if command -v node &> /dev/null; then
    node scripts/minify-css.js
fi

# Create ZIP archive
echo "📦 Creating ZIP archive..."
cd build
zip -r "${PLUGIN_SLUG}.${VERSION}.zip" "${PLUGIN_SLUG}/"
cd - > /dev/null

echo "✅ Build complete: build/${PLUGIN_SLUG}.${VERSION}.zip"
echo "📊 Size: $(du -sh "build/${PLUGIN_SLUG}.${VERSION}.zip" | cut -f1)"
