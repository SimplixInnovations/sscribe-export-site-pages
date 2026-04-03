#!/bin/bash
#
# Build production artifact for WordPress.org submission.
# Excludes dev dependencies, tests, and unnecessary files.
# Auto-detects version from plugin header.
#
# Usage: ./scripts/build-release.sh [version]
#

set -e

PLUGIN_SLUG="sscribe-export-site-pages"
PLUGIN_FILE="sscribe-export-site-pages.php"

# Auto-detect version from plugin header if not provided
if [ -z "$1" ]; then
	VERSION=$(grep -oP 'Version:\s*\K[0-9.]+' "$PLUGIN_FILE" 2>/dev/null || grep -m1 'Version:' "$PLUGIN_FILE" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
	if [ -z "$VERSION" ]; then
		echo "❌ Error: Could not detect version from plugin header."
		exit 1
	fi
	echo "🔍 Auto-detected version: ${VERSION}"
else
	VERSION="$1"
fi

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
